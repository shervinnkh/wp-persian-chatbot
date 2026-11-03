"""Data access for conversations/messages (the only module that talks SQL for messaging).

Every method opens its own session/transaction via `Database`, like KnowledgeRepository.
"""

from __future__ import annotations

from datetime import timedelta

from sqlalchemy import delete, func, select, update
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession

from app.db.base import Database
from app.db.messaging_models import (
    Conversation,
    Message,
    SummaryRequest,
    SummaryStatus,
    utcnow,
)


def owner_key_for(visitor_uid: str, wp_user_id: int | None) -> str:
    return f"u:{wp_user_id}" if wp_user_id is not None else f"v:{visitor_uid}"


async def _by_key(s: AsyncSession, key: str) -> Conversation | None:
    res = await s.execute(select(Conversation).where(Conversation.owner_key == key))
    return res.scalar_one_or_none()


class MessagingRepository:
    def __init__(self, db: Database):
        self.db = db

    # ------------------------------------------------------------------ conversations
    async def get_conversation(self, conversation_id: int) -> Conversation | None:
        async with self.db.session() as s:
            return await s.get(Conversation, conversation_id)

    async def get_or_create_conversation(
        self, *, visitor_uid: str, wp_user_id: int | None
    ) -> tuple[Conversation, bool]:
        """The single conversation of this user -> (conversation, created)."""
        key = owner_key_for(visitor_uid, wp_user_id)
        async with self.db.session() as s:
            conv = await _by_key(s, key)
            if conv is not None:
                if conv.visitor_uid != visitor_uid:  # logged-in user on another device/browser
                    conv.visitor_uid = visitor_uid
                    await s.commit()
                return conv, False

            # A guest who just logged in: move their guest conversation to the account.
            if wp_user_id is not None:
                guest = await _by_key(s, f"v:{visitor_uid}")
                if guest is not None:
                    guest.owner_key, guest.wp_user_id = key, wp_user_id
                    try:
                        await s.commit()
                        return guest, False
                    except IntegrityError:  # a concurrent request created u:<id> first
                        await s.rollback()
                        existing = await _by_key(s, key)
                        if existing is None:
                            raise
                        return existing, False

            conv = Conversation(owner_key=key, visitor_uid=visitor_uid, wp_user_id=wp_user_id)
            s.add(conv)
            try:
                await s.commit()
            except IntegrityError:  # concurrent request of the same user
                await s.rollback()
                existing = await _by_key(s, key)
                if existing is None:
                    raise
                return existing, False
            return conv, True

    # ------------------------------------------------------------------ messages
    async def add_message(
        self,
        conversation_id: int,
        *,
        visitor_uid: str,
        wp_user_id: int | None,
        role: str,
        content: str,
        attachment_type: str | None = None,
        attachment_url: str | None = None,
        suggestions: list[str] | None = None,
        admin_user_id: int | None = None,
        admin_name: str | None = None,
    ) -> Message:
        """Insert + commit immediately; returns the stored row."""
        async with self.db.session() as s:
            msg = Message(
                conversation_id=conversation_id,
                visitor_uid=visitor_uid,
                wp_user_id=wp_user_id,
                role=role,
                content=content,
                attachment_type=attachment_type,
                attachment_url=attachment_url,
                suggestions=suggestions,
                admin_user_id=admin_user_id,
                admin_name=admin_name,
            )
            s.add(msg)
            conv = await s.get(Conversation, conversation_id)
            if conv is not None:
                conv.updated_at = utcnow()
            await s.commit()
            return msg

    async def set_attachment_path(self, message_id: int, path: str) -> Message | None:
        async with self.db.session() as s:
            msg = await s.get(Message, message_id)
            if msg is None:  # deleted while the file was downloading
                return None
            msg.attachment_path = path
            await s.commit()
            return msg

    async def set_transcript(self, message_id: int, transcript: str) -> Message | None:
        async with self.db.session() as s:
            msg = await s.get(Message, message_id)
            if msg is None:  # deleted meanwhile
                return None
            msg.transcript = transcript
            await s.commit()
            return msg

    async def get_message(self, conversation_id: int, message_id: int) -> Message | None:
        async with self.db.session() as s:
            res = await s.execute(
                select(Message).where(
                    Message.id == message_id, Message.conversation_id == conversation_id
                )
            )
            return res.scalar_one_or_none()

    async def list_messages(
        self, conversation_id: int, *, limit: int = 50, before_id: int | None = None
    ) -> list[Message]:
        """Newest first; used to build the LLM history in a later step."""
        stmt = select(Message).where(Message.conversation_id == conversation_id)
        if before_id is not None:
            stmt = stmt.where(Message.id < before_id)
        stmt = stmt.order_by(Message.id.desc()).limit(limit)
        async with self.db.session() as s:
            return list((await s.execute(stmt)).scalars().all())

    async def delete_message(
        self, conversation_id: int, message_id: int
    ) -> tuple[bool, str | None]:
        """-> (found, relative attachment path to remove from disk)."""
        async with self.db.session() as s:
            res = await s.execute(
                select(Message).where(
                    Message.id == message_id, Message.conversation_id == conversation_id
                )
            )
            msg = res.scalar_one_or_none()
            if msg is None:
                return False, None
            path = msg.attachment_path
            await s.delete(msg)
            await s.commit()
            return True, path

    async def delete_all_messages(self, conversation_id: int) -> tuple[int, list[str]]:
        """Delete every message of a conversation (the conversation stays)."""
        async with self.db.session() as s:
            res = await s.execute(
                select(Message.attachment_path).where(
                    Message.conversation_id == conversation_id,
                    Message.attachment_path.is_not(None),
                )
            )
            paths = [p for (p,) in res.all() if p]
            result = await s.execute(
                delete(Message).where(Message.conversation_id == conversation_id)
            )
            # The summary describes messages that no longer exist.
            await s.execute(
                update(Conversation)
                .where(Conversation.id == conversation_id)
                .values(summary=None, summarized_upto_message_id=None, summary_updated_at=None)
            )
            await s.execute(
                delete(SummaryRequest).where(SummaryRequest.conversation_id == conversation_id)
            )
            await s.commit()
            return result.rowcount or 0, paths

    # ------------------------------------------------------------------ history
    async def list_messages_after(
        self, conversation_id: int, *, after_id: int | None = None, upto_id: int | None = None
    ) -> list[Message]:
        """Oldest first: messages with after_id < id <= upto_id."""
        stmt = select(Message).where(Message.conversation_id == conversation_id)
        if after_id is not None:
            stmt = stmt.where(Message.id > after_id)
        if upto_id is not None:
            stmt = stmt.where(Message.id <= upto_id)
        async with self.db.session() as s:
            return list((await s.execute(stmt.order_by(Message.id.asc()))).scalars().all())

    async def message_exists(self, conversation_id: int, message_id: int) -> bool:
        async with self.db.session() as s:
            res = await s.execute(
                select(func.count())
                .select_from(Message)
                .where(Message.id == message_id, Message.conversation_id == conversation_id)
            )
            return bool(res.scalar_one())

    # ------------------------------------------------------------------ summaries
    async def set_summary(self, conversation_id: int, summary: str, upto_id: int) -> bool:
        """Store a finished summary. False if the conversation vanished or an equal/newer
        summary is already stored (never move the cutoff backwards)."""
        async with self.db.session() as s:
            conv = await s.get(Conversation, conversation_id)
            if conv is None:
                return False
            if (conv.summarized_upto_message_id or 0) >= upto_id:
                return False
            conv.summary = summary
            conv.summarized_upto_message_id = upto_id
            conv.summary_updated_at = utcnow()
            await s.commit()
            return True

    async def enqueue_summary_request(
        self, conversation_id: int, upto_message_id: int
    ) -> SummaryRequest | None:
        """Queue a summarization. None when one is already waiting/running for this
        conversation or the range is already covered by the stored summary."""
        async with self.db.session() as s:
            conv = await s.get(Conversation, conversation_id)
            if conv is None or (conv.summarized_upto_message_id or 0) >= upto_message_id:
                return None
            open_count = (
                await s.execute(
                    select(func.count())
                    .select_from(SummaryRequest)
                    .where(
                        SummaryRequest.conversation_id == conversation_id,
                        SummaryRequest.status.in_(
                            [SummaryStatus.pending.value, SummaryStatus.processing.value]
                        ),
                    )
                )
            ).scalar_one()
            if open_count:
                return None
            req = SummaryRequest(
                conversation_id=conversation_id,
                upto_message_id=upto_message_id,
                status=SummaryStatus.pending.value,
            )
            s.add(req)
            await s.commit()
            return req

    async def claim_next_summary_request(
        self, *, stale_after_seconds: int
    ) -> SummaryRequest | None:
        """Atomically take the oldest due request (status -> processing, attempts + 1).

        A request stuck in `processing` longer than `stale_after_seconds` (crashed worker) is
        made pending again first.
        """
        now = utcnow()
        async with self.db.session() as s:
            await s.execute(
                update(SummaryRequest)
                .where(
                    SummaryRequest.status == SummaryStatus.processing.value,
                    SummaryRequest.started_at < now - timedelta(seconds=stale_after_seconds),
                )
                .values(status=SummaryStatus.pending.value)
            )
            row_id = (
                await s.execute(
                    select(SummaryRequest.id)
                    .where(
                        SummaryRequest.status == SummaryStatus.pending.value,
                        SummaryRequest.available_at <= now,
                    )
                    .order_by(SummaryRequest.id.asc())
                    .limit(1)
                )
            ).scalar_one_or_none()
            if row_id is None:
                await s.commit()
                return None
            claimed = await s.execute(
                update(SummaryRequest)
                .where(
                    SummaryRequest.id == row_id,
                    SummaryRequest.status == SummaryStatus.pending.value,
                )
                .values(
                    status=SummaryStatus.processing.value,
                    started_at=now,
                    attempts=SummaryRequest.attempts + 1,
                    updated_at=now,
                )
            )
            await s.commit()
            if claimed.rowcount != 1:  # another worker took it first
                return None
            return await s.get(SummaryRequest, row_id, populate_existing=True)

    async def finish_summary_request(
        self, request_id: int, *, ok: bool, error: str | None = None
    ) -> None:
        async with self.db.session() as s:
            await s.execute(
                update(SummaryRequest)
                .where(SummaryRequest.id == request_id)
                .values(
                    status=(SummaryStatus.done if ok else SummaryStatus.failed).value,
                    error=error,
                    finished_at=utcnow(),
                    updated_at=utcnow(),
                )
            )
            await s.commit()

    async def retry_summary_request(
        self, request_id: int, *, error: str, delay_seconds: int
    ) -> None:
        async with self.db.session() as s:
            await s.execute(
                update(SummaryRequest)
                .where(SummaryRequest.id == request_id)
                .values(
                    status=SummaryStatus.pending.value,
                    error=error,
                    available_at=utcnow() + timedelta(seconds=delay_seconds),
                    updated_at=utcnow(),
                )
            )
            await s.commit()

    async def get_summary_request(self, request_id: int) -> SummaryRequest | None:
        async with self.db.session() as s:
            return await s.get(SummaryRequest, request_id)
