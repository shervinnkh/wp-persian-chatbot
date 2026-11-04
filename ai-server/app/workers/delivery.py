"""Callback delivery worker:  python -m app.workers.delivery

Sends "record X is finished" results to the WordPress plugin (POST callback.url, signed). It only
needs the database and HTTP - no AI, no Qdrant - and is deliberately separate from the ingestion
worker: a slow or dead WordPress site can only ever hold up this process.
Run exactly ONE instance (a second one would only cause harmless duplicate deliveries).
"""

from __future__ import annotations

import asyncio
import logging
import signal

from app.core.config import Settings, get_settings
from app.core.logging import setup_logging
from app.core.monitoring import shutdown_monitoring, start_snapshots, stop_snapshots
from app.db.base import Database
from app.db.ingest_repository import IngestRepository
from app.services.ingestion.delivery import DeliveryService, Transport

logger = logging.getLogger("app.workers.delivery")


async def run(
    settings: Settings,
    *,
    transport: Transport | None = None,
    stop: asyncio.Event | None = None,
    once: bool = False,
) -> None:
    """Main loop. `once=True` runs a single pass (waits for it) and returns."""
    stop = stop or asyncio.Event()
    db = Database.from_settings(settings)
    await db.init()
    service = DeliveryService(settings=settings, repo=IngestRepository(db), transport=transport)
    try:
        if once:
            await service.run_pass(wait=True)
        else:
            await service.run_loop(stop)
    finally:
        await db.dispose()


def main() -> None:
    settings = get_settings()
    setup_logging(settings, service="delivery")

    async def _runner() -> None:
        stop = asyncio.Event()
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGINT, signal.SIGTERM):
            try:
                loop.add_signal_handler(sig, stop.set)
            except NotImplementedError:  # Windows: Ctrl+C raises KeyboardInterrupt instead
                pass
        logger.info("delivery worker started")
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
