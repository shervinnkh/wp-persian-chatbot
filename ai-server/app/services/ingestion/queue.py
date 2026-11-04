"""API side of the ingestion queue: validate, store the job, answer. Nothing heavy happens here.

plugin ──POST {records, callback}──▶ enqueue (one DB transaction) ──▶ 200 {job_id…}
                                                     │
                      ingestion worker (separate process): chunk → embed → Qdrant → DB
                                                     │
                      delivery worker (separate process): POST {results} to callback.url
"""

from __future__ import annotations

import asyncio
import logging

from sqlalchemy.exc import SQLAlchemyError

from app.core.config import Settings
from app.core.errors import FileProcessingError, ServiceUnavailableError, UnprocessableRecordError
from app.core.monitoring import emit
from app.db.base import Database
from app.db.ingest_repository import IngestRepository, NewJob, SiteUpsert
from app.schemas.ingest import (
    CallbackIn,
    EnqueueResponse,
    JobStatusItem,
    JobStatusResponse,
    QueuedJob,
)
from app.schemas.knowledge import RecordIn
from app.services.files.download import check_download_url, pick_extension
from app.services.ingestion.callback import ValidatedCallback, validate_callback

logger = logging.getLogger(__name__)


class IngestQueueService:
    def __init__(self, *, settings: Settings, db: Database):
        self.settings = settings
        self.db = db
        self.repo = IngestRepository(db)
        self._ready = False
        self._ready_lock = asyncio.Lock()

    @classmethod
    def from_settings(cls, settings: Settings) -> IngestQueueService:
        return cls(settings=settings, db=Database.from_settings(settings))

    async def ensure_ready(self) -> None:
        if self._ready:
            return
        async with self._ready_lock:
            if not self._ready:
                await self.db.init()
                self._ready = True

    async def close(self) -> None:
        await self.db.dispose()

    # ------------------------------------------------------------------ accept
    async def _validated(self, callback: CallbackIn | None) -> ValidatedCallback | None:
        if callback is None:
            return None
        return validate_callback(self.settings, callback, locked_site=await self.repo.locked_site())

    async def _enqueue(self, jobs: list[NewJob], cb: ValidatedCallback | None) -> EnqueueResponse:
        site = (
            SiteUpsert(cb.site_key, cb.url, cb.ping_url, cb.site_url, cb.max_results)
            if cb
            else None
        )
        try:
            rows, superseded = await self.repo.enqueue(
                jobs, site, initial_batch_size=self.settings.CALLBACK_INITIAL_BATCH_SIZE
            )
        except (SQLAlchemyError, OSError) as exc:
            logger.error("ingest.enqueue_failed error=%s", type(exc).__name__)
            raise ServiceUnavailableError("The ingestion queue is unavailable") from exc
        logger.info(
            "ingest.enqueued jobs=%d superseded=%d callback=%s first_job=%s",
            len(rows), superseded, "yes" if cb else "no", rows[0].job_id,
        )  # fmt: skip
        kinds: dict[str, int] = {}
        for r in rows:
            k = r.record_id.split(":", 1)[0]
            kinds[k] = kinds.get(k, 0) + 1
        emit(
            "ingest.enqueued", jobs=len(rows), superseded=superseded, callback=bool(cb),
            kinds=kinds, action=jobs[0].action if jobs else None,
        )  # fmt: skip
        return EnqueueResponse(
            job_id=rows[0].job_id,
            queued_ids=[r.record_id for r in rows],
            jobs=[QueuedJob(id=r.record_id, job_id=r.job_id) for r in rows],
            superseded=superseded,
            callback="registered" if cb else "none",
        )

    async def enqueue_records(
        self,
        records: list[RecordIn],
        *,
        replace: bool,
        force: bool,
        callback: CallbackIn | None,
    ) -> EnqueueResponse:
        """`replace=True` (PUT): the worker deletes the record completely, then adds it again."""
        await self.ensure_ready()
        cb = await self._validated(callback)
        await self._check_content_records(records)
        jobs = [
            NewJob(
                record_id=r.id,
                action="replace" if replace else "upsert",
                event=r.event,
                rev=r.rev,
                force=force,
                payload=r.data,
                callback=cb.as_dict() if cb else None,
                site_key=cb.site_key if cb else None,
            )
            for r in records
        ]
        return await self._enqueue(jobs, cb)

    async def _check_content_records(self, records: list[RecordIn]) -> None:
        """Cheap checks for text:* / file:* so a hopeless request is refused at once (422) instead
        of failing later in the worker. No network, no file access."""
        locked = None
        for r in records:
            kind = r.id.split(":", 1)[0]
            if kind == "text":
                from app.services.chunking.render import text_pieces

                if not text_pieces(r.data):
                    raise UnprocessableRecordError(f"{r.id}: text has no chunks or text")
            elif kind == "file":
                dl = r.data.get("download")
                if not isinstance(dl, dict) or not dl.get("url"):
                    raise UnprocessableRecordError(
                        f"{r.id}: file record needs data.download.url (signed link)"
                    )
                try:
                    pick_extension(
                        str(r.data.get("original_name") or ""), str(r.data.get("ext") or "")
                    )
                    if locked is None:
                        locked = await self.repo.locked_site() or ""
                    check_download_url(self.settings, str(dl["url"]), locked_site=locked or None)
                except FileProcessingError as exc:
                    raise UnprocessableRecordError(f"{r.id}: {exc.message}") from exc

    async def enqueue_deletes(
        self, ids: list[str], *, callback: CallbackIn | None
    ) -> EnqueueResponse:
        await self.ensure_ready()
        cb = await self._validated(callback)
        jobs = [
            NewJob(
                record_id=i,
                action="delete",
                event="delete",
                callback=cb.as_dict() if cb else None,
                site_key=cb.site_key if cb else None,
            )
            for i in ids
        ]
        return await self._enqueue(jobs, cb)

    # ------------------------------------------------------------------ status (reconciliation)
    async def job_status(self, ids: list[str]) -> JobStatusResponse:
        """Latest known state per record, in the words of the callback. The plugin can feed
        this to the same function that applies callback results (lost-webhook safety net)."""
        await self.ensure_ready()
        try:
            latest = await self.repo.latest_jobs(ids)
        except (SQLAlchemyError, OSError) as exc:
            raise ServiceUnavailableError("The ingestion queue is unavailable") from exc
        items: list[JobStatusItem] = []
        for record_id in ids:
            job = latest.get(record_id)
            if job is None:
                items.append(JobStatusItem(id=record_id, status="unknown"))
            elif job.status in ("pending", "processing"):
                items.append(
                    JobStatusItem(id=record_id, status="processing", job_id=job.job_id, rev=job.rev)
                )
            elif job.status == "done":
                items.append(
                    JobStatusItem(
                        id=record_id,
                        status="untrained" if job.outcome == "untrained" else "trained",
                        job_id=job.job_id,
                        rev=job.rev if job.outcome != "untrained" else None,
                    )
                )
            else:
                items.append(
                    JobStatusItem(
                        id=record_id,
                        status="failed",
                        job_id=job.job_id,
                        error=job.error,
                        operation="delete" if job.action == "delete" else None,
                    )
                )
        return JobStatusResponse(results=items)
