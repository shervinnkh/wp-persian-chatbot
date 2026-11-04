"""Ingestion worker:  python -m app.workers.ingestion

Takes the jobs the API queued (`ingest_jobs`), does chunking -> embedding -> Qdrant + text DB, and
queues a completion callback for the plugin (the delivery worker sends it). The API process never
does this work, so a slow embedding API or a huge page cannot make the server or the client wait.

Embedded Qdrant (QDRANT_PATH) can be opened by ONE process only, so with it the same loop runs
inside the API process instead (INGEST_RUN_IN_API, automatic) and this worker is not needed.
Safe to restart at any time: an interrupted job is picked up again (INGEST_STALE_SECONDS).
"""

from __future__ import annotations

import asyncio
import logging
import signal
import sys

from app.core.config import Settings, get_settings
from app.core.logging import setup_logging
from app.core.monitoring import shutdown_monitoring, start_snapshots, stop_snapshots
from app.db.base import Database
from app.db.ingest_repository import IngestRepository
from app.services.ingestion import KnowledgeService
from app.services.ingestion.processor import IngestProcessor

logger = logging.getLogger("app.workers.ingestion")


async def run(
    settings: Settings,
    *,
    knowledge: KnowledgeService | None = None,
    stop: asyncio.Event | None = None,
    once: bool = False,
) -> None:
    """Main loop. `once=True` drains the queue and returns (tests / manual runs)."""
    stop = stop or asyncio.Event()
    db = Database.from_settings(settings)
    await db.init()
    owns_knowledge = knowledge is None
    knowledge = knowledge or KnowledgeService.from_settings(settings)
    await knowledge.ensure_ready()
    processor = IngestProcessor(settings=settings, repo=IngestRepository(db), knowledge=knowledge)
    try:
        if once:
            await processor.drain()
        else:
            await processor.run_loop(stop)
    finally:
        if owns_knowledge:
            await knowledge.close()
        await db.dispose()
        logger.info("ingestion worker stopped")


def main() -> None:
    settings = get_settings()
    setup_logging(settings, service="ingestion")

    if settings.qdrant_path is not None:
        if settings.ingest_in_api:
            logger.warning(
                "QDRANT_PATH is set (embedded Qdrant, one process only): ingestion runs inside "
                "the API process, this worker is not needed. Exiting."
            )
            sys.exit(0)
        logger.error(
            "QDRANT_PATH is set but INGEST_RUN_IN_API=false: the API and this worker would both "
            "open the embedded Qdrant folder. Use a Qdrant server (empty QDRANT_PATH) or remove "
            "INGEST_RUN_IN_API=false."
        )
        sys.exit(2)

    async def _runner() -> None:
        stop = asyncio.Event()
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGINT, signal.SIGTERM):
            try:
                loop.add_signal_handler(sig, stop.set)
            except NotImplementedError:  # Windows: Ctrl+C raises KeyboardInterrupt instead
                pass
        logger.info("ingestion worker started (embedding model=%s)", settings.EMBEDDING_MODEL)
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
