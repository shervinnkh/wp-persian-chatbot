"""Worker side of the ingestion queue: take a job, do the heavy work, queue the callback.

Used by `python -m app.workers.ingestion` (own process) and, when embedded Qdrant is in use, by the
API process itself (INGEST_RUN_IN_API) - the code is the same.
"""

from __future__ import annotations

import asyncio
import logging
import time

from app.core.config import Settings
from app.core.monitoring import emit
from app.db.ingest_models import IngestJob
from app.db.ingest_repository import DeliverySpec, IngestRepository, aware
from app.db.messaging_models import utcnow
from app.services.ingestion.content import ContentService
from app.services.ingestion.service import KnowledgeService

logger = logging.getLogger(__name__)

_MAX_ERROR_CHARS = 300  # what is stored / reported to the plugin


class _JobFailure(Exception):
    def __init__(self, message: str, *, retryable: bool, code: str | None = None):
        super().__init__(message)
        self.message = message
        self.retryable = retryable
        self.code = code


class IngestProcessor:
    def __init__(
        self, *, settings: Settings, repo: IngestRepository, knowledge: KnowledgeService
    ):
        self.settings = settings
        self.repo = repo
        self.knowledge = knowledge
        # text:* / file:* have their own build steps; every other record goes to KnowledgeService
        self.content = ContentService(knowledge, settings, locked_site=repo.locked_site)

    # ------------------------------------------------------------------ one job
    async def process_next(self) -> bool:
        """Handle one due job. -> True when a job was taken (even if it failed)."""
        job = await self.repo.claim_next_job(stale_after_seconds=self.settings.INGEST_STALE_SECONDS)
        if job is None:
            return False
        started = time.monotonic()
        created = aware(job.created_at)
        job_wait_ms = (
            (utcnow() - created).total_seconds() * 1000 if created and job.attempts <= 1 else None
        )
        try:
            if job.attempts > self.settings.INGEST_MAX_ATTEMPTS:
                # Claimed again and again but never finished: the worker kept dying on it.
                raise _JobFailure("Processing was interrupted too many times", retryable=False)
            outcome, result = await self._execute(job)
        except _JobFailure as exc:
            await self._failed(job, exc.message, exc.retryable, exc.code)
            return True
        except Exception as exc:  # noqa: BLE001 - one bad job must not stop the worker
            logger.exception("ingest.job_crashed job=%s id=%s", job.job_id, job.record_id)
            await self._failed(job, f"Internal error: {type(exc).__name__}", True, "internal_error")
            return True
        await self._done(job, outcome, result)
        emit(
            "ingest.job", phase="done", job_id=job.job_id, record_id=job.record_id,
            kind=job.record_id.split(":", 1)[0], action=job.action, outcome=outcome,
            status=result.get("status"), attempt=job.attempts, queue_wait_ms=job_wait_ms,
            ms=(time.monotonic() - started) * 1000, chunks=result.get("chunks"),
            embedded_chunks=result.get("embedded_chunks"),
            reused_chunks=result.get("reused_chunks"), tokens=result.get("tokens"),
            embedding_tokens=result.get("embedding_tokens"),
        )  # fmt: skip
        logger.info(
            "ingest.job_done job=%s id=%s action=%s outcome=%s attempts=%d duration_ms=%d",
            job.job_id, job.record_id, job.action, outcome, job.attempts,
            int((time.monotonic() - started) * 1000),
        )  # fmt: skip
        return True

    async def _execute(self, job: IngestJob) -> tuple[str, dict]:
        """-> ("trained" | "untrained", per-record stats). Raises _JobFailure."""
        if job.action in ("delete", "replace"):
            deleted = await self.content.delete(job.record_id)
            if deleted.status == "failed":
                raise _JobFailure(
                    f"delete step: {deleted.error}",
                    retryable=deleted.retryable,
                    code=deleted.error_code,
                )
            if job.action == "delete":
                return "untrained", deleted.model_dump()
        if not isinstance(job.payload, dict):
            raise _JobFailure("The job has no record data", retryable=False)
        added = await self.content.upsert(job.record_id, job.payload, force=job.force)
        if added.status == "failed":
            raise _JobFailure(
                added.error or "Processing failed", retryable=added.retryable, code=added.error_code
            )
        return "trained", added.model_dump()

    # ------------------------------------------------------------------ outcomes
    def _delivery(self, job: IngestJob, status: str, error: str | None = None):
        if not job.callback or not job.site_key:
            return None
        return DeliverySpec(
            site_key=job.site_key,
            callback_url=job.callback["url"],
            result_status=status,
            rev=job.rev if status == "trained" else None,
            error=error,
            action=job.action,
        )

    async def _done(self, job: IngestJob, outcome: str, result: dict) -> None:
        await self.repo.finish_job(
            job.id,
            ok=True,
            outcome=outcome,
            result=result,
            error=None,
            delivery=self._delivery(job, outcome),
            job_id=job.job_id,
            record_id=job.record_id,
            batch_window_seconds=self.settings.CALLBACK_BATCH_WINDOW_SECONDS,
        )

    async def _failed(
        self, job: IngestJob, message: str, retryable: bool, code: str | None
    ) -> None:
        message = message[:_MAX_ERROR_CHARS]
        if retryable and job.attempts < self.settings.INGEST_MAX_ATTEMPTS:
            delay = self.settings.INGEST_RETRY_DELAY_SECONDS * job.attempts
            await self.repo.retry_job(job.id, error=message, delay_seconds=delay)
            emit(
                "ingest.job", phase="retry", job_id=job.job_id, record_id=job.record_id,
                kind=job.record_id.split(":", 1)[0], action=job.action, attempt=job.attempts,
                delay_s=delay, code=code, error=message[:200],
            )  # fmt: skip
            logger.warning(
                "ingest.job_retry job=%s id=%s attempt=%d delay_s=%d code=%s error=%s",
                job.job_id, job.record_id, job.attempts, delay, code, message,
            )  # fmt: skip
            return
        await self.repo.finish_job(
            job.id,
            ok=False,
            outcome=None,
            result={"status": "failed", "error_code": code},
            error=message,
            delivery=self._delivery(job, "failed", message),
            job_id=job.job_id,
            record_id=job.record_id,
            batch_window_seconds=self.settings.CALLBACK_BATCH_WINDOW_SECONDS,
        )
        emit(
            "ingest.job", phase="failed", job_id=job.job_id, record_id=job.record_id,
            kind=job.record_id.split(":", 1)[0], action=job.action, attempt=job.attempts,
            code=code, error=message[:200],
        )  # fmt: skip
        logger.error(
            "ingest.job_failed job=%s id=%s attempts=%d code=%s error=%s",
            job.job_id, job.record_id, job.attempts, code, message,
        )  # fmt: skip

    # ------------------------------------------------------------------ loop
    async def run_loop(self, stop: asyncio.Event) -> None:
        """`INGEST_WORKER_CONCURRENCY` tasks, each taking one job at a time until `stop`."""
        concurrency = self.settings.INGEST_WORKER_CONCURRENCY
        logger.info(
            "ingestion loop started concurrency=%d poll=%ss", concurrency,
            self.settings.INGEST_WORKER_POLL_SECONDS,
        )  # fmt: skip

        async def _one() -> None:
            while not stop.is_set():
                try:
                    worked = await self.process_next()
                except Exception:  # noqa: BLE001 - DB hiccup: wait and continue
                    logger.exception("ingestion loop iteration failed")
                    worked = False
                if worked:
                    continue
                try:
                    await asyncio.wait_for(
                        stop.wait(), timeout=self.settings.INGEST_WORKER_POLL_SECONDS
                    )
                except asyncio.TimeoutError:
                    pass

        async def _janitor() -> None:  # purge old finished rows about once an hour
            while not stop.is_set():
                try:
                    jobs, deliveries = await self.repo.purge_finished(
                        self.settings.INGEST_RETENTION_DAYS
                    )
                    if jobs or deliveries:
                        logger.info("ingest.purged jobs=%d deliveries=%d", jobs, deliveries)
                except Exception:  # noqa: BLE001
                    logger.exception("ingest purge failed")
                try:  # S-1/S-2: old rate-limit rows, IP hashes (IP_HASH_RETENTION_DAYS), periods
                    from app.services.ratelimit import purge as purge_security

                    await purge_security(self.repo.db, self.settings)
                except Exception:  # noqa: BLE001
                    logger.exception("security purge failed")
                try:
                    await asyncio.wait_for(stop.wait(), timeout=3600)
                except asyncio.TimeoutError:
                    pass

        await asyncio.gather(*[_one() for _ in range(concurrency)], _janitor())
        logger.info("ingestion loop stopped")

    async def drain(self) -> int:
        """Process every currently due job and return how many were taken (tests, manual runs)."""
        n = 0
        while await self.process_next():
            n += 1
        return n
