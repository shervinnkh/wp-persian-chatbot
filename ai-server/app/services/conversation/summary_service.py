"""Summarization queue: enqueue (API side) and process (worker side).

Flow
  1. The API answers a user message, returns it to the client, and THEN (background task) calls
     `enqueue(conversation_id, upto_message_id)` when the history was too long.
  2. The worker (`python -m app.workers.summarizer`) calls `process_next()` in a loop: it takes
     the stored summary + the messages after it (up to `upto_message_id`), asks the AI to merge
     them, and saves the new summary on the conversation record.
"""

from __future__ import annotations

import logging
import time

from app.core.config import Settings
from app.core.monitoring import emit
from app.db.ingest_repository import aware
from app.db.messaging_models import utcnow
from app.db.messaging_repository import MessagingRepository
from app.services.conversation.history import HistoryMessage
from app.services.conversation.summarizer import Summarizer

logger = logging.getLogger(__name__)


class SummaryService:
    def __init__(
        self, *, settings: Settings, repo: MessagingRepository, summarizer: Summarizer | None = None
    ):
        self.settings = settings
        self.repo = repo
        self.summarizer = summarizer

    # ------------------------------------------------------------------ API side
    async def enqueue(self, conversation_id: int, upto_message_id: int) -> bool:
        """Never raises: it runs after the response was sent."""
        try:
            req = await self.repo.enqueue_summary_request(conversation_id, upto_message_id)
        except Exception:  # noqa: BLE001
            logger.exception("summary.enqueue_failed conversation_id=%s", conversation_id)
            return False
        if req is None:
            logger.info(
                "summary.enqueue_skipped conversation_id=%s (already queued or covered)",
                conversation_id,
            )
            emit("summary.job", phase="skipped", conversation_id=conversation_id)
            return False
        logger.info(
            "summary.enqueued request_id=%s conversation_id=%s upto_message_id=%s",
            req.id, conversation_id, upto_message_id,
        )  # fmt: skip
        emit(
            "summary.job", phase="enqueued", request_id=req.id, conversation_id=conversation_id,
            upto_message_id=upto_message_id,
        )  # fmt: skip
        return True

    # ------------------------------------------------------------------ worker side
    async def process_next(self) -> bool:
        """Handle one due request. -> True when a request was taken (even if it failed)."""
        req = await self.repo.claim_next_summary_request(
            stale_after_seconds=self.settings.SUMMARY_STALE_SECONDS
        )
        if req is None:
            return False
        started = time.monotonic()
        created = aware(getattr(req, "created_at", None))
        wait_ms = (utcnow() - created).total_seconds() * 1000 if created else None
        if req.attempts > 1:
            wait_ms = None  # the first attempt's wait is what shows queue pressure
        try:
            outcome = await self._summarize(req.conversation_id, req.upto_message_id)
        except Exception as exc:  # noqa: BLE001 - one bad request must not stop the worker
            await self._fail(req.id, req.attempts, exc, req.conversation_id)
            return True
        await self.repo.finish_summary_request(req.id, ok=True)
        emit(
            "summary.job", phase="done", request_id=req.id, conversation_id=req.conversation_id,
            outcome=outcome, attempt=req.attempts, queue_wait_ms=wait_ms,
            ms=(time.monotonic() - started) * 1000,
        )  # fmt: skip
        logger.info(
            "summary.done request_id=%s conversation_id=%s outcome=%s duration_ms=%d",
            req.id, req.conversation_id, outcome, int((time.monotonic() - started) * 1000),
        )  # fmt: skip
        return True

    async def _summarize(self, conversation_id: int, upto_id: int) -> str:
        if self.summarizer is None:
            raise RuntimeError("No summarizer configured")
        conv = await self.repo.get_conversation(conversation_id)
        if conv is None:
            return "conversation_deleted"
        cutoff = conv.summarized_upto_message_id if conv.summary else None
        if cutoff is not None and cutoff >= upto_id:
            return "already_covered"

        rows = await self.repo.list_messages_after(
            conversation_id, after_id=cutoff, upto_id=upto_id
        )
        messages = [HistoryMessage.from_row(m) for m in rows]
        if not any(m.text for m in messages):
            return "nothing_to_summarize"

        text = await self.summarizer.summarize(
            conv.summary if cutoff is not None else None, messages
        )

        # The chat may have been cleared while the AI was working: then drop the result.
        if not await self.repo.message_exists(conversation_id, rows[-1].id):
            return "discarded_messages_deleted"
        saved = await self.repo.set_summary(conversation_id, text, rows[-1].id)
        return "saved" if saved else "discarded_newer_summary_exists"

    async def _fail(
        self, request_id: int, attempts: int, exc: Exception, conversation_id: int | None = None
    ) -> None:
        error = f"{type(exc).__name__}: {exc}"[:500]
        final = attempts >= self.settings.SUMMARY_MAX_ATTEMPTS
        emit(
            "summary.job", phase="failed" if final else "retry", request_id=request_id,
            conversation_id=conversation_id, attempt=attempts, error_type=type(exc).__name__,
            error=error[:200],
        )  # fmt: skip
        if final:
            await self.repo.finish_summary_request(request_id, ok=False, error=error)
            logger.error(
                "summary.failed request_id=%s attempts=%s error=%s", request_id, attempts, error
            )
        else:
            delay = self.settings.SUMMARY_RETRY_DELAY_SECONDS * attempts
            await self.repo.retry_summary_request(request_id, error=error, delay_seconds=delay)
            logger.warning(
                "summary.retry request_id=%s attempts=%s delay_s=%s error=%s",
                request_id, attempts, delay, error,
            )  # fmt: skip
