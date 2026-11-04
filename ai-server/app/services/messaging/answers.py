"""Async AI answers: queue -> generate (inside the API process) -> callback to the site.

Why: generating an answer is slow (seconds) but costs no CPU here - it only waits for the AI
provider. Holding a WordPress PHP worker for that time is what does not scale, so the plugin sends
the message with a `callback`, gets "queued" at once, and the answer is POSTed back to
`callback.url` as a result item of kind "chat_answer" (the plugin saves it as a chat message and
the widget picks it up with its normal polling).

Pieces:
  * `answer_jobs` (this file)            - what to answer; survives restarts (stale: retried)
  * `AnswerQueue.run_loop`               - up to ANSWER_WORKER_CONCURRENCY answers at once
  * `callback_deliveries` (kind=answer)  - outbox row written in the SAME transaction that marks
    the job done; the existing delivery worker signs/sends it with backoff, a small attempt limit
    (ANSWER_CALLBACK_MAX_ATTEMPTS) and a time limit (ANSWER_CALLBACK_TTL_SECONDS).
Single API process assumed (the claim is not safe for several processes polling at once).
"""

from __future__ import annotations

import asyncio
import dataclasses
import json
import logging
import secrets
from datetime import timedelta
from typing import TYPE_CHECKING

from sqlalchemy import delete, select, update

from app.db.answer_models import AnswerJob
from app.db.ingest_models import CallbackDelivery
from app.db.ingest_repository import IngestRepository, SiteUpsert
from app.db.messaging_models import Conversation, Message, utcnow
from app.services.conversation.answer import AiAnswerResult
from app.services.conversation.prompt import PromptContext
from app.services.ingestion.callback import ValidatedCallback

if TYPE_CHECKING:
    from app.services.messaging.service import MessagingService

logger = logging.getLogger(__name__)

_MAX_ERROR_CHARS = 500
_HOUSEKEEPING_EVERY = 60.0


def _message_dict(m: Message) -> dict:
    return {
        "id": m.id,
        "content": m.content,
        "suggestions": list(m.suggestions or []),
        "created_at": m.created_at.isoformat() if m.created_at else None,
    }


class AnswerQueue:
    def __init__(self, service: MessagingService):
        self.service = service
        self.settings = service.settings
        self.db = service.db
        self.ingest = IngestRepository(service.db)
        self._wake = asyncio.Event()
        self._tasks: set[asyncio.Task] = set()
        self._last_housekeeping = 0.0

    def notify(self) -> None:
        self._wake.set()

    # ------------------------------------------------------------------ API side
    async def enqueue(
        self, *, conversation_id: int, question_id: int, cb: ValidatedCallback, ctx: PromptContext
    ) -> str:
        """Queue one answer. The callback site is registered/updated like for knowledge jobs."""
        await self.ingest.ensure_site(
            SiteUpsert(
                host=cb.site_key,
                callback_url=cb.url,
                ping_url=cb.ping_url,
                site_url=cb.site_url,
                max_results=cb.max_results,
            ),
            initial_batch_size=self.settings.CALLBACK_INITIAL_BATCH_SIZE,
        )
        job_id = "a_" + secrets.token_hex(6)
        async with self.db.session() as s:
            s.add(
                AnswerJob(
                    job_id=job_id,
                    conversation_id=conversation_id,
                    question_message_id=question_id,
                    site_key=cb.site_key,
                    callback=cb.as_dict(),
                    ctx=dataclasses.asdict(ctx),
                    status="pending",
                    available_at=utcnow(),
                )
            )
            await s.commit()
        logger.info(
            "answer.queued job=%s conversation_id=%s message_id=%s model=%s",
            job_id, conversation_id, question_id, ctx.model,
        )  # fmt: skip
        self.notify()
        return job_id

    async def enqueue_saved(
        self,
        *,
        cb: ValidatedCallback,
        conversation: Conversation,
        request_id: str | None,
        total_count: int,
        messages: list[dict],
        error: str | None,
    ) -> None:
        """Outbox row "these messages are saved" (callback item kind "chat_messages_saved").

        Queued right after the messages are committed and before an answer is generated, and sent
        by the same delivery worker/backoff as answers. `messages` = [{index, message_id,
        external_id}] for the messages saved from the START of the request's list.
        """
        await self.ingest.ensure_site(
            SiteUpsert(
                host=cb.site_key,
                callback_url=cb.url,
                ping_url=cb.ping_url,
                site_url=cb.site_url,
                max_results=cb.max_results,
            ),
            initial_batch_size=self.settings.CALLBACK_INITIAL_BATCH_SIZE,
        )
        job_id = "s_" + secrets.token_hex(6)
        item = {
            "kind": "chat_messages_saved",
            "id": f"saved:{job_id}",
            "status": "saved",
            "job_id": job_id,
            "request_id": request_id,
            "conversation_id": conversation.id,
            "visitor_uid": conversation.visitor_uid,
            "wp_user_id": conversation.wp_user_id or 0,
            "received_count": len(messages),
            "total_count": total_count,
            "messages": messages,
        }
        if error:
            item["error"] = error
        async with self.db.session() as s:
            s.add(
                CallbackDelivery(
                    site_key=cb.site_key,
                    callback_url=cb.url,
                    job_id=job_id,
                    record_id=item["id"],
                    result_status="trained",  # table check constraint: trained = ok
                    kind="answer",  # same outbox rules as answers (ready-made payload, short TTL)
                    payload=json.dumps(item, ensure_ascii=False, separators=(",", ":")),
                    available_at=utcnow(),  # sent right away
                )
            )
            await s.commit()
        logger.info(
            "messaging.saved_queued job=%s conversation_id=%s received=%d/%d",
            job_id, conversation.id, len(messages), total_count,
        )  # fmt: skip
        # delivery worker polls on its own; nothing to notify here

    # ------------------------------------------------------------------ loop
    async def run_loop(self, stop: asyncio.Event) -> None:
        concurrency = self.settings.ANSWER_WORKER_CONCURRENCY
        logger.info(
            "answer loop started concurrency=%d poll=%ss", concurrency,
            self.settings.ANSWER_WORKER_POLL_SECONDS,
        )  # fmt: skip
        await self.service.ensure_ready()
        while not stop.is_set():
            started = 0
            try:
                free = concurrency - len(self._tasks)
                if free > 0:
                    for job in await self._claim(free):
                        self._spawn(job)
                        started += 1
                await self._housekeeping()
            except Exception:  # noqa: BLE001 - DB hiccup: wait and continue
                logger.exception("answer loop iteration failed")
            if started and len(self._tasks) < concurrency:
                continue  # there may be more waiting
            self._wake.clear()
            try:
                await asyncio.wait_for(
                    self._wake.wait(), timeout=self.settings.ANSWER_WORKER_POLL_SECONDS
                )
            except asyncio.TimeoutError:
                pass
            if stop.is_set():
                break
        if self._tasks:
            await asyncio.gather(*self._tasks, return_exceptions=True)
        logger.info("answer loop stopped")

    def _spawn(self, job: AnswerJob) -> None:
        task = asyncio.create_task(self._run(job), name=f"answer-{job.job_id}")
        self._tasks.add(task)

        def _done(t: asyncio.Task) -> None:
            self._tasks.discard(t)
            self.notify()  # a slot is free

        task.add_done_callback(_done)

    async def _claim(self, limit: int) -> list[AnswerJob]:
        now = utcnow()
        stale = now - timedelta(seconds=self.settings.ANSWER_STALE_SECONDS)
        async with self.db.session() as s:
            await s.execute(  # an API crash left these in "processing": try them again
                update(AnswerJob)
                .where(AnswerJob.status == "processing", AnswerJob.started_at < stale)
                .values(status="pending", available_at=now, updated_at=now)
            )
            rows = list(
                (
                    await s.execute(
                        select(AnswerJob)
                        .where(AnswerJob.status == "pending", AnswerJob.available_at <= now)
                        .order_by(AnswerJob.id)
                        .limit(limit)
                    )
                )
                .scalars()
                .all()
            )
            if rows:
                await s.execute(
                    update(AnswerJob)
                    .where(AnswerJob.id.in_([r.id for r in rows]))
                    .values(
                        status="processing",
                        attempts=AnswerJob.attempts + 1,
                        started_at=now,
                        updated_at=now,
                    )
                )
            await s.commit()
        return rows  # (the UPDATE above already refreshed `attempts` on these objects)

    # ------------------------------------------------------------------ one answer
    async def _run(self, job: AnswerJob) -> None:
        repo = self.service.repo
        try:
            conv = await repo.get_conversation(job.conversation_id)
            question = (
                await repo.get_message(job.conversation_id, job.question_message_id)
                if conv is not None
                else None
            )
            if conv is None or question is None:  # deleted meanwhile: nothing to answer
                await self._close(job, "failed", "conversation or message no longer exists")
                return
            ctx = PromptContext(**job.ctx)
            ai = await self.service._generate_answer(conv.id, question, ctx)  # noqa: SLF001
        except Exception as exc:  # noqa: BLE001
            logger.exception("answer.crashed job=%s", job.job_id)
            conv, ai = None, AiAnswerResult(status="failed")
            ctx = PromptContext(model=self.settings.default_model)
            error = f"{type(exc).__name__}"
        else:
            error = "answer generation failed"

        if ai.status == "generated" and ai.message is not None and conv is not None:
            await self._finish_ok(job, conv, ai, ctx)
            if ai.summarize_upto_message_id is not None:
                await self.service.enqueue_summary(conv.id, ai.summarize_upto_message_id)
            return

        if job.attempts < self.settings.ANSWER_MAX_ATTEMPTS:
            delay = self.settings.ANSWER_RETRY_DELAY_SECONDS * job.attempts
            await self._update(
                job, status="pending", error=error, available_at=utcnow() + timedelta(seconds=delay)
            )
            logger.warning(
                "answer.retry job=%s attempt=%d delay_s=%d error=%s",
                job.job_id, job.attempts, delay, error,
            )  # fmt: skip
            return
        await self._close(job, "failed", error, conv=conv)

    async def _update(self, job: AnswerJob, **values) -> None:
        async with self.db.session() as s:
            await s.execute(
                update(AnswerJob)
                .where(AnswerJob.id == job.id)
                .values(updated_at=utcnow(), **values)
            )
            await s.commit()

    def _delivery(self, job: AnswerJob, item: dict, status: str, error: str | None):
        return CallbackDelivery(
            site_key=job.site_key,
            callback_url=job.callback["url"],
            job_id=job.job_id,
            record_id=f"answer:{job.job_id}",
            result_status=status,  # trained = answered | failed
            kind="answer",
            payload=json.dumps(item, ensure_ascii=False, separators=(",", ":")),
            error=error,
            available_at=utcnow(),  # no batching window: a chat answer is sent right away
        )

    async def _finish_ok(
        self, job: AnswerJob, conv: Conversation, ai: AiAnswerResult, ctx: PromptContext
    ) -> None:
        msg = ai.message
        assert msg is not None
        item = {
            "kind": "chat_answer",
            "id": f"answer:{job.job_id}",
            "status": "answered",
            "job_id": job.job_id,
            "conversation_id": conv.id,
            "visitor_uid": conv.visitor_uid,
            "wp_user_id": conv.wp_user_id or 0,
            "question_message_id": job.question_message_id,
            "model": ctx.model,
            "message": _message_dict(msg),
        }
        now = utcnow()
        async with self.db.session() as s:  # outbox: job done + delivery row, one transaction
            await s.execute(
                update(AnswerJob)
                .where(AnswerJob.id == job.id)
                .values(
                    status="done", answer_message_id=msg.id, error=None,
                    finished_at=now, updated_at=now,
                )  # fmt: skip
            )
            s.add(self._delivery(job, item, "trained", None))
            await s.commit()
        logger.info(
            "answer.done job=%s conversation_id=%s answer_id=%s", job.job_id, conv.id, msg.id
        )

    async def _close(
        self, job: AnswerJob, status: str, error: str, *, conv: Conversation | None = None
    ) -> None:
        """Final failure. When the conversation still exists the site is told (status failed)."""
        error = error[:_MAX_ERROR_CHARS]
        now = utcnow()
        async with self.db.session() as s:
            await s.execute(
                update(AnswerJob)
                .where(AnswerJob.id == job.id)
                .values(status=status, error=error, finished_at=now, updated_at=now)
            )
            if conv is not None:
                item = {
                    "kind": "chat_answer",
                    "id": f"answer:{job.job_id}",
                    "status": "failed",
                    "job_id": job.job_id,
                    "conversation_id": conv.id,
                    "visitor_uid": conv.visitor_uid,
                    "wp_user_id": conv.wp_user_id or 0,
                    "question_message_id": job.question_message_id,
                    "error": error,
                }
                s.add(self._delivery(job, item, "failed", error))
            await s.commit()
        logger.error("answer.failed job=%s error=%s", job.job_id, error)

    # ------------------------------------------------------------------ housekeeping
    async def _housekeeping(self) -> None:
        loop_time = asyncio.get_running_loop().time()
        if loop_time - self._last_housekeeping < _HOUSEKEEPING_EVERY:
            return
        self._last_housekeeping = loop_time
        now = utcnow()
        ttl = now - timedelta(seconds=self.settings.ANSWER_CALLBACK_TTL_SECONDS)
        old = now - timedelta(days=self.settings.INGEST_RETENTION_DAYS)
        async with self.db.session() as s:
            expired = await s.execute(  # an answer the site could not take for too long
                update(CallbackDelivery)
                .where(
                    CallbackDelivery.kind == "answer",
                    CallbackDelivery.state == "pending",
                    CallbackDelivery.created_at < ttl,
                )
                .values(
                    state="dead", last_error="expired (ANSWER_CALLBACK_TTL_SECONDS)",
                    finished_at=now, updated_at=now,
                )  # fmt: skip
            )
            await s.execute(
                delete(AnswerJob).where(
                    AnswerJob.status.in_(("done", "failed")), AnswerJob.finished_at < old
                )
            )
            await s.commit()
        if expired.rowcount:
            logger.warning("answer.delivery_expired count=%d", expired.rowcount)
