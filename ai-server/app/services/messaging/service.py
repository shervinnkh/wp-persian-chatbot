"""Saving and deleting chat messages.

Order for one incoming message (the message is never lost because of its attachment):
    1. find/create the user's single conversation
    2. insert the message and COMMIT immediately
    3. if it has an image/voice: download it, then store its relative path on the same row
A failed download leaves the message saved with `attachment_path = NULL`.
"""

from __future__ import annotations

import asyncio
import logging
import time
from dataclasses import dataclass
from typing import Literal

from app.core.config import Settings
from app.core.monitoring import answer_stats, emit, note_answer_error
from app.db.base import Database
from app.db.messaging_models import Message
from app.db.messaging_repository import MessagingRepository
from app.schemas.messaging import SendMessageRequest, SendMessagesBatchRequest
from app.services.chunking.tokens import TokenCounter
from app.services.conversation.answer import (
    AiAnswerResult,
    AnswerGenerator,
    FixedAnswerGenerator,
    GeneratedAnswer,
)
from app.services.conversation.history import HistoryBuilder
from app.services.conversation.prompt import PromptContext
from app.services.conversation.summary_service import SummaryService
from app.services.ingestion.callback import validate_callback
from app.services.messaging import storage
from app.services.messaging.answers import AnswerQueue
from app.services.messaging.errors import ConversationNotFoundError, MessageNotFoundError
from app.services.speech import Transcriber, UnusableAudioError

logger = logging.getLogger(__name__)

AttachmentStatus = Literal["none", "stored", "failed"]


@dataclass
class SendResult:
    conversation_id: int
    created_conversation: bool
    attachment_status: AttachmentStatus
    message: Message
    ai: AiAnswerResult


@dataclass
class BatchResult:
    conversation_id: int
    created_conversation: bool
    total_count: int
    items: list[SendResult]  # the messages that were saved, in request order
    ai: AiAnswerResult
    error: str | None = None
    saved_callback_queued: bool = False  # the "messages saved" callback item was queued

    @property
    def received_count(self) -> int:
        return len(self.items)


class MessagingService:
    def __init__(
        self,
        *,
        settings: Settings,
        db: Database,
        answer_generator: AnswerGenerator | None = None,
        token_counter: TokenCounter | None = None,
        transcriber: Transcriber | None = None,
    ):
        self.settings = settings
        self.db = db
        self.repo = MessagingRepository(db)
        self.answer_generator: AnswerGenerator = answer_generator or FixedAnswerGenerator()
        self._counter = token_counter
        self.transcriber = transcriber or Transcriber(settings)
        self.summaries = SummaryService(settings=settings, repo=self.repo)  # enqueue only
        self.answers = AnswerQueue(self)
        self._ready = False
        self._ready_lock = asyncio.Lock()

    @classmethod
    def from_settings(cls, settings: Settings) -> MessagingService:
        return cls(settings=settings, db=Database.from_settings(settings))

    # ------------------------------------------------------------------ lifecycle
    async def ensure_ready(self) -> None:
        if self._ready:
            return
        async with self._ready_lock:
            if self._ready:
                return
            await self.db.init()
            self._ready = True
            logger.info("messaging.ready")

    async def close(self) -> None:
        await self.transcriber.close()
        await self.db.dispose()

    # ------------------------------------------------------------------ send
    async def _validated_cb(self, req: SendMessageRequest):
        """The validated callback of a request that wants an async answer (else None)."""
        if req.generate_ai_answer and req.role.value == "user" and req.callback is not None:
            # Validated BEFORE anything is saved: a bad callback must not leave a message behind.
            return validate_callback(
                self.settings, req.callback, locked_site=await self.answers.ingest.locked_site()
            )
        return None

    async def _save(self, req: SendMessageRequest):
        """Find/create the conversation, save the message (+ its attachment).
        -> (conversation, created, message, attachment_status)"""
        if req.conversation_id is not None:
            conv = await self.repo.get_conversation(req.conversation_id)
            if conv is None:
                raise ConversationNotFoundError("Conversation not found")
            created = False
        else:
            conv, created = await self.repo.get_or_create_conversation(
                visitor_uid=req.visitor_uid or "", wp_user_id=req.wp_user_id
            )

        msg = await self.repo.add_message(
            conv.id,
            visitor_uid=req.visitor_uid or conv.visitor_uid,
            wp_user_id=req.wp_user_id if req.wp_user_id is not None else conv.wp_user_id,
            role=req.role.value,
            content=req.content,
            attachment_type=req.attachment_type.value if req.attachment_type else None,
            attachment_url=req.attachment_url,
            suggestions=req.suggestions,
            admin_user_id=req.admin_user_id,
            admin_name=req.admin_name,
        )
        logger.info(
            "messaging.message_saved conversation_id=%s message_id=%s role=%s has_attachment=%s",
            conv.id, msg.id, req.role.value, req.attachment_url is not None,
        )  # fmt: skip

        status: AttachmentStatus = "none"
        if req.attachment_url and req.attachment_type:
            msg, status = await self._fetch_attachment(
                msg, req.attachment_url, req.attachment_type.value
            )
        return conv, created, msg, status

    async def _answer(self, req: SendMessageRequest, conv_id: int, msg: Message, cb) -> AiAnswerResult:
        """Answer the saved message when the request asks for it (async with `cb`, else inline)."""
        if not (req.generate_ai_answer and req.role.value == "user"):
            return AiAnswerResult()
        ctx = PromptContext.from_request(req, self.settings)
        if cb is not None:  # async: queued now, POSTed to the callback when ready
            job_id = await self.answers.enqueue(
                conversation_id=conv_id, question_id=msg.id, cb=cb, ctx=ctx
            )
            return AiAnswerResult(status="queued", job_id=job_id, model=ctx.model)
        return await self._generate_answer(conv_id, msg, ctx)

    async def send(self, req: SendMessageRequest) -> SendResult:
        await self.ensure_ready()
        cb = await self._validated_cb(req)
        conv, created, msg, status = await self._save(req)
        ai = await self._answer(req, conv.id, msg, cb)
        return SendResult(conv.id, created, status, msg, ai)

    async def send_batch(self, batch: SendMessagesBatchRequest) -> BatchResult:
        """Save a list of messages in order (oldest first); the last one may also get an AI answer.

        Each message is committed on its own (so the order of ids == the order of the list). The
        list stops at the first failure: the result says how many messages from the start were
        saved (`received_count`) so the client can retry only the rest. If the very first message
        fails the error is raised as usual (nothing was saved).

        With a `callback` the site is told FIRST (item kind "chat_messages_saved", queued as soon as
        the messages are in the database and BEFORE any answer is generated) which messages were
        saved; the answer follows later as kind "chat_answer". So even when the HTTP response of
        this request is lost or the answer fails, the site knows what the server already has.
        """
        await self.ensure_ready()
        reqs = batch.requests
        cb = None
        if batch.callback is not None:
            # Validated BEFORE anything is saved: a bad callback must not leave messages behind.
            cb = validate_callback(
                self.settings, batch.callback, locked_site=await self.answers.ingest.locked_site()
            )
        saved: list[tuple] = []  # (conversation, created, message, attachment_status)
        error: str | None = None
        for i, req in enumerate(reqs):
            try:
                one = await self._save(req)
            except Exception as exc:  # noqa: BLE001
                if not saved:
                    raise
                logger.exception(
                    "messaging.batch_stopped index=%d saved=%d total=%d", i, len(saved), len(reqs)
                )
                error = f"message {i} could not be saved ({type(exc).__name__})"
                break
            # Conversation lookup by uid only once: later messages go to the same conversation.
            if i == 0:
                for later in reqs[1:]:
                    later.conversation_id = one[0].id
            saved.append(one)

        conv = saved[0][0]
        saved_queued = False
        if cb is not None:
            saved_queued = await self._notify_saved(batch, cb, conv, saved, error)

        mode = ("async" if cb is not None else "sync") if wants_answer else None
        emit(
            "chat.message", role=req.role.value, conversation_id=conv.id, message_id=msg.id,
            created_conversation=created, logged_in=bool(conv.wp_user_id),
            attachment_type=req.attachment_type.value if req.attachment_type else None,
            attachment_status=status if req.attachment_url else None,
            chars=len(req.content or ""), wants_answer=wants_answer, mode=mode,
        )  # fmt: skip
        ai = AiAnswerResult()
        if len(saved) == len(reqs):
            last = saved[-1]
            ai = await self._answer(reqs[-1], conv.id, last[2], cb if batch.generate_ai_answer else None)
        logger.info(
            "messaging.batch_saved conversation_id=%s received=%d total=%d ai_status=%s",
            conv.id, len(saved), len(reqs), ai.status,
        )  # fmt: skip
        items = [
            SendResult(conv.id, created, status, msg, ai if k == len(saved) - 1 else AiAnswerResult())
            for k, (_c, created, msg, status) in enumerate(saved)
        ]
        return BatchResult(
            conversation_id=conv.id,
            created_conversation=saved[0][1],
            total_count=len(reqs),
            items=items,
            ai=ai,
            error=error,
            saved_callback_queued=saved_queued,
        )

    async def _notify_saved(self, batch, cb, conv, saved: list[tuple], error: str | None) -> bool:
        """Queue the "messages saved" callback item. Never raises: a failure here must not hide
        the fact that the messages are saved (the HTTP response still says it)."""
        try:
            await self.answers.enqueue_saved(
                cb=cb,
                conversation=conv,
                request_id=batch.request_id,
                total_count=len(batch.messages),
                messages=[
                    {
                        "index": i,
                        "message_id": msg.id,
                        "external_id": batch.messages[i].external_id,
                    }
                    for i, (_c, _created, msg, _status) in enumerate(saved)
                ],
                error=error,
            )
            return True
        except Exception:  # noqa: BLE001
            logger.exception("messaging.saved_callback_failed conversation_id=%s", conv.id)
            return False

    # ------------------------------------------------------------------ AI answer
    @property
    def counter(self) -> TokenCounter:
        if self._counter is None:  # loads the tiktoken vocabulary once
            self._counter = TokenCounter.from_settings(self.settings)
        return self._counter

    async def enqueue_summary(self, conversation_id: int, upto_message_id: int) -> bool:
        """Background task: runs after the response was sent to the client."""
        return await self.summaries.enqueue(conversation_id, upto_message_id)

    async def _generate_answer(
        self,
        conversation_id: int,
        question: Message,
        ctx: PromptContext,
        *,
        mode: str = "sync",
        job_id: str | None = None,
        attempt: int | None = None,
    ) -> AiAnswerResult:
        """One `chat.answer` monitoring event per call: outcome, time and the totals of every AI /
        tool / search call made for it (see `answer_stats`)."""
        t0 = time.monotonic()
        with answer_stats() as st:
            result = await self._generate_answer_inner(conversation_id, question, ctx)
        msg = result.message
        emit(
            "chat.answer", status=result.status, mode=mode, job_id=job_id, attempt=attempt,
            model=ctx.model, tone=ctx.tone, ms=(time.monotonic() - t0) * 1000,
            conversation_id=conversation_id, question_message_id=question.id,
            answer_message_id=msg.id if msg is not None else None,
            question_type=question.attachment_type or "text",
            answer_chars=len(msg.content or "") if msg is not None else None,
            suggestions=len(msg.suggestions or []) if msg is not None else None,
            history_tokens=result.full_history_tokens, context_tokens=result.context_tokens,
            summary_scheduled=result.summarization_scheduled, page_type=ctx.page_type or None,
            **st,
        )  # fmt: skip
        return result

    async def _generate_answer_inner(
        self, conversation_id: int, question: Message, ctx: PromptContext
    ) -> AiAnswerResult:
        """History -> answer (text + suggestions) -> saved as `assistant`. Never raises: the user
        message is already stored, so a failure here only marks the answer as failed."""
        try:
            conv = await self.repo.get_conversation(conversation_id)
            if conv is None:
                raise ConversationNotFoundError("Conversation not found")
            question = await self._ensure_transcript(question)
            builder = HistoryBuilder(
                self.repo,
                self.counter,
                recent_pairs=self.settings.HISTORY_RECENT_PAIRS,
                trigger_tokens=self.settings.SUMMARY_TRIGGER_TOKENS,
            )
            history = await builder.build(conv, question)
            generated = await self.answer_generator.generate(history, ctx)
            answer = (
                generated
                if isinstance(generated, GeneratedAnswer)
                else GeneratedAnswer(text=str(generated))
            )
            reply = await self.repo.add_message(
                conversation_id,
                visitor_uid=conv.visitor_uid,
                wp_user_id=conv.wp_user_id,
                role="assistant",
                content=answer.text,
                suggestions=answer.suggestions or None,
            )
        except Exception as exc:  # noqa: BLE001
            note_answer_error(exc)
            logger.exception(
                "messaging.answer_failed conversation_id=%s message_id=%s",
                conversation_id, question.id,
            )  # fmt: skip
            return AiAnswerResult(status="failed")

        logger.info(
            "messaging.answer_generated conversation_id=%s question_id=%s answer_id=%s "
            "full_history_tokens=%d context_tokens=%d needs_summary=%s",
            conversation_id, question.id, reply.id, history.full_history_tokens,
            history.context_tokens, history.needs_summary,
        )  # fmt: skip
        return AiAnswerResult(
            status="generated",
            message=reply,
            full_history_tokens=history.full_history_tokens,
            context_tokens=history.context_tokens,
            summarization_scheduled=history.needs_summary,
            # The summary also covers this answer; the request is queued after the response.
            summarize_upto_message_id=reply.id if history.needs_summary else None,
            model=ctx.model,
        )

    async def _ensure_transcript(self, msg: Message) -> Message:
        """Voice question -> speech-to-text, saved on the message (`transcript`) so the history,
        the knowledge search and later summaries see the words. A file the API rejects is skipped
        (the AI then sees a bare "[voice message]" and asks for text); a temporary API failure
        raises, so the answer job is retried and the voice is transcribed again."""
        if (
            msg.attachment_type != "voice"
            or not msg.attachment_path
            or (msg.transcript or "").strip()
            or not self.settings.OPENAI_API_KEY.get_secret_value()
        ):
            return msg
        path = storage.resolve_attachment_path(self.settings, msg.attachment_path)
        if path is None or not path.is_file():
            logger.warning("messaging.voice_file_missing message_id=%s", msg.id)
            return msg
        try:
            text = await self.transcriber.transcribe(path)
        except UnusableAudioError as exc:
            logger.warning("messaging.voice_unusable message_id=%s reason=%s", msg.id, exc)
            return msg
        if not text:
            logger.info("messaging.voice_empty message_id=%s", msg.id)
            return msg
        return await self.repo.set_transcript(msg.id, text) or msg

    async def _fetch_attachment(
        self, msg: Message, url: str, kind: str
    ) -> tuple[Message, Literal["stored", "failed"]]:
        started = time.monotonic()
        try:
            relative, size = await storage.download_attachment(
                url=url, kind=kind, settings=self.settings
            )
        except storage.AttachmentError as exc:
            logger.warning("messaging.attachment_failed message_id=%s reason=%s", msg.id, exc)
            return msg, "failed"
        except Exception:  # noqa: BLE001 - the message is already saved; never lose it
            logger.exception("messaging.attachment_error message_id=%s", msg.id)
            return msg, "failed"

        try:
            updated = await self.repo.set_attachment_path(msg.id, relative)
        except Exception:  # noqa: BLE001
            await asyncio.to_thread(storage.delete_files, self.settings, [relative])
            logger.exception("messaging.attachment_db_failed message_id=%s", msg.id)
            return msg, "failed"
        if updated is None:  # the message was deleted while its file was downloading
            await asyncio.to_thread(storage.delete_files, self.settings, [relative])
            return msg, "failed"

        logger.info(
            "messaging.attachment_stored message_id=%s kind=%s bytes=%s duration_ms=%d",
            msg.id, kind, size, int((time.monotonic() - started) * 1000),
        )  # fmt: skip
        return updated, "stored"

    # ------------------------------------------------------------------ delete
    async def delete_message(self, conversation_id: int, message_id: int) -> None:
        await self.ensure_ready()
        found, path = await self.repo.delete_message(conversation_id, message_id)
        if not found:
            raise MessageNotFoundError("Message not found")
        await asyncio.to_thread(storage.delete_files, self.settings, [path])
        emit("chat.delete", scope="message", count=1, conversation_id=conversation_id)
        logger.info(
            "messaging.message_deleted conversation_id=%s message_id=%s",
            conversation_id, message_id,
        )  # fmt: skip

    async def clear_messages(self, conversation_id: int) -> int:
        await self.ensure_ready()
        if await self.repo.get_conversation(conversation_id) is None:
            raise ConversationNotFoundError("Conversation not found")
        count, paths = await self.repo.delete_all_messages(conversation_id)
        await asyncio.to_thread(storage.delete_files, self.settings, list(paths))
        emit("chat.delete", scope="conversation", count=count, conversation_id=conversation_id)
        logger.info(
            "messaging.messages_cleared conversation_id=%s count=%d", conversation_id, count
        )
        return count
