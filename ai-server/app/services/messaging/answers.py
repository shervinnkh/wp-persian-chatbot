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
import time
from datetime import timedelta
from typing import TYPE_CHECKING

from sqlalchemy import delete, func, select, update

from app.core.monitoring import emit
from app.db.answer_models import AnswerJob
from app.db.ingest_models import CallbackDelivery
from app.db.ingest_repository import IngestRepository, SiteUpsert, aware
from app.db.messaging_models import Conversation, Message, utcnow
from app.services.conversation.answer import AiAnswerResult
from app.services.conversation.prompt import PromptContext
from app.services.ingestion.callback import ValidatedCallback

if TYPE_CHECKING:
    from app.services.messaging.service import MessagingService

logger = logging.getLogger(__name__)

_MAX_ERROR_CHARS = 500
_HOUSEKEEPING_EVERY = 60.0


def _message_dict(m: Message, references: list[dict] | None = None, marked: bool = False) -> dict:
    """The message of a `chat_answer` item. `references` = the site's products / posts / pages the
    answer talks about ([{type, id, url}]). A marked answer ("Mark-Chat") was not stored: id null,
    no suggestions, no references."""
    return {
        "id": None if marked else m.id,
        "content": m.content,
        "suggestions": [] if marked else list(m.suggestions or []),
        "references": [] if marked else list(references or []),
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
        self._last_retention: float | None = None  # loop time of the last daily purge
        self._retention_more = False  # the last purge batch was full: run again soon

    def notify(self) -> None:
        self._wake.set()

    async def backlog(self) -> int:
        """Answers waiting or being generated (ANSWER_QUEUE_MAX guards this number)."""
        async with self.db.session() as s:
            return int(
                (
                    await s.execute(
                        select(func.count())
                        .select_from(AnswerJob)
                        .where(AnswerJob.status.in_(("pending", "processing")))
                    )
                ).scalar_one()
            )

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
        emit(
            "answer.job", phase="queued", job_id=job_id, conversation_id=conversation_id,
            message_id=question_id, model=ctx.model,
        )  # fmt: skip
        self.notify()
        return job_id


    def _age_ms(self, job: AnswerJob) -> float | None:
        created = aware(job.created_at)
        return (utcnow() - created).total_seconds() * 1000 if created else None

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
        # time spent waiting in the queue (first attempt only: later ones include the back-off)
        wait_ms = self._age_ms(job) if job.attempts <= 1 else None
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
            exceeded = await self.service.budget.check()  # S-2: queued before the cap was hit
            if exceeded is not None:
                logger.warning("answer.budget_exceeded job=%s period=%s", job.job_id, exceeded.period)
                await self._close(
                    job, "failed", "budget_exceeded", conv=conv,
                    extra={
                        "code": "budget_exceeded", "period": exceeded.period,
                        "period_end": int(time.time()) + exceeded.retry_after,
                    },
                )  # fmt: skip
                return
            ctx = PromptContext(**job.ctx)
            ai = await self.service._generate_answer(  # noqa: SLF001
                conv.id, question, ctx, mode="async", job_id=job.job_id, attempt=job.attempts
            )
        except Exception as exc:  # noqa: BLE001
            logger.exception("answer.crashed job=%s", job.job_id)
            conv, ai = None, AiAnswerResult(status="failed")
            ctx = PromptContext(model=self.settings.default_model)
            error = f"{type(exc).__name__}"
        else:
            error = "answer generation failed"

        if ai.status == "generated" and ai.message is not None and conv is not None:
            await self._finish_ok(job, conv, ai, ctx)
            emit(
                "answer.job", phase="done", job_id=job.job_id, conversation_id=conv.id,
                model=ctx.model, attempt=job.attempts, queue_wait_ms=wait_ms,
                total_ms=self._age_ms(job),
            )  # fmt: skip
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
            emit(
                "answer.job", phase="retry", job_id=job.job_id, attempt=job.attempts,
                delay_s=delay, queue_wait_ms=wait_ms, error=error,
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
            "mark_the_conversation": ai.marked,
            "message": _message_dict(msg, ai.references, ai.marked),
        }
        now = utcnow()
        async with self.db.session() as s:  # outbox: job done + delivery row, one transaction
            await s.execute(
                update(AnswerJob)
                .where(AnswerJob.id == job.id)
                .values(
                    status="done", answer_message_id=None if ai.marked else msg.id, error=None,
                    finished_at=now, updated_at=now,
                )  # fmt: skip
            )
            s.add(self._delivery(job, item, "trained", None))
            await s.commit()
        logger.info(
            "answer.done job=%s conversation_id=%s answer_id=%s marked=%s",
            job.job_id, conv.id, None if ai.marked else msg.id, ai.marked,
        )

    async def _close(
        self, job: AnswerJob, status: str, error: str, *, conv: Conversation | None = None,
        extra: dict | None = None,
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
                if extra:
                    item.update(extra)  # budget_exceeded: code/period/period_end
                s.add(self._delivery(job, item, "failed", error))
            await s.commit()
        logger.error("answer.failed job=%s error=%s", job.job_id, error)
        emit(
            "answer.job", phase="failed", job_id=job.job_id, attempt=job.attempts,
            conversation_id=job.conversation_id, total_ms=self._age_ms(job), error=error,
        )  # fmt: skip

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
                    CallbackDelivery.kind.in_(("answer", "budget")),
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
            emit("answer.job", phase="expired", count=expired.rowcount)
        await self._retention(loop_time)

    async def _retention(self, loop_time: float) -> None:
        """P-2 janitor: once a day (and again at once while a batch came back full) delete the
        conversations idle for CONVERSATION_RETENTION_DAYS, with their messages/summaries/files.
        Same pattern as INGEST_RETENTION_DAYS; 0 = off."""
        days = self.settings.CONVERSATION_RETENTION_DAYS
        if days <= 0:
            return
        due = self._last_retention is None or loop_time - self._last_retention >= 86400
        if not (due or self._retention_more):
            return
        self._last_retention = loop_time
        try:
            convs, msgs, paths = await self.service.repo.purge_idle_conversations(days)
            self._retention_more = convs >= 200
            if paths:
                from app.services.messaging import storage

                await asyncio.to_thread(storage.delete_files, self.settings, paths)
            if convs:
                logger.info(
                    "retention.purged conversations=%d messages=%d files=%d", convs, msgs, len(paths)
                )
                emit("retention.purge", conversations=convs, messages=msgs, files=len(paths))
        except Exception:  # noqa: BLE001 - housekeeping must never break the answer loop
            self._retention_more = False
            logger.exception("retention purge failed")
