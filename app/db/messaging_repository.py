"""Data access for conversations/messages (the only module that talks SQL for messaging).

Every method opens its own session/transaction via `Database`, like KnowledgeRepository.
"""

from __future__ import annotations

from sqlalchemy import delete, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession

from app.db.base import Database
from app.db.messaging_models import Conversation, Message, utcnow


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
            await s.commit()
            return result.rowcount or 0, paths
