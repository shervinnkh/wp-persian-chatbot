"""Summarizer worker:  python -m app.workers.summarizer

Polls the `summary_requests` table (filled by the API when a conversation got too long), asks the
AI to merge the old summary with the newer messages, and saves the result on the conversation.
Run it next to the API (run.bat starts it); it can be restarted at any time - an interrupted
request is retried (SUMMARY_STALE_SECONDS / SUMMARY_MAX_ATTEMPTS).
"""

from __future__ import annotations

import asyncio
import logging
import signal

from app.core.config import Settings, get_settings
from app.core.logging import setup_logging
from app.core.monitoring import shutdown_monitoring, start_snapshots, stop_snapshots
from app.db.base import Database
from app.db.messaging_repository import MessagingRepository
from app.services.budget import get_budget
from app.services.conversation.summarizer import OpenAISummarizer, Summarizer
from app.services.conversation.summary_service import SummaryService

logger = logging.getLogger("app.workers.summarizer")


async def run(
    settings: Settings,
    *,
    summarizer: Summarizer | None = None,
    stop: asyncio.Event | None = None,
    once: bool = False,
) -> None:
    """Main loop. `once=True` drains the queue and returns (used by tests/manual runs)."""
    stop = stop or asyncio.Event()
    db = Database.from_settings(settings)
    await db.init()
    summarizer = summarizer or OpenAISummarizer(settings)
    service = SummaryService(settings=settings, repo=MessagingRepository(db), summarizer=summarizer)
    logger.info(
        "summarizer worker started model=%s poll=%ss", settings.SUMMARY_MODEL,
        settings.SUMMARY_WORKER_POLL_SECONDS,
    )  # fmt: skip
    try:
        while not stop.is_set():
            exceeded = await get_budget(settings).check()  # S-2: summaries are blocked too
            if exceeded is not None:
                logger.warning("summarizer paused: %s budget is used up", exceeded.period)
                try:
                    await asyncio.wait_for(stop.wait(), timeout=60)
                except asyncio.TimeoutError:
                    pass
                continue
            try:
                worked = await service.process_next()
            except Exception:  # noqa: BLE001 - DB hiccup: wait and continue
                logger.exception("summarizer worker iteration failed")
                worked = False
            if worked:
                continue  # more requests may be waiting
            if once:
                return
            try:
                await asyncio.wait_for(stop.wait(), timeout=settings.SUMMARY_WORKER_POLL_SECONDS)
            except asyncio.TimeoutError:
                pass
    finally:
        await summarizer.close()
        await get_budget(settings).close()
        await db.dispose()
        logger.info("summarizer worker stopped")


def main() -> None:
    settings = get_settings()
    setup_logging(settings, service="summarizer")

    async def _runner() -> None:
        stop = asyncio.Event()
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGINT, signal.SIGTERM):
            try:
                loop.add_signal_handler(sig, stop.set)
            except NotImplementedError:  # Windows: Ctrl+C raises KeyboardInterrupt instead
                pass
        snap = start_snapshots(settings, stop=stop)
        try:
            await run(settings, stop=stop)
        finally:
            await stop_snapshots(snap, stop)

    try:
        asyncio.run(_runner())
    except KeyboardInterrupt:
        pass
    finally:
        shutdown_monitoring()


if __name__ == "__main__":
    main()
