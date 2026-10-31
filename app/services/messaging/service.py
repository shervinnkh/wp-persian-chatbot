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
from app.db.base import Database
from app.db.messaging_models import Message
from app.db.messaging_repository import MessagingRepository
from app.schemas.messaging import SendMessageRequest
from app.services.messaging import storage
from app.services.messaging.errors import ConversationNotFoundError, MessageNotFoundError

logger = logging.getLogger(__name__)

AttachmentStatus = Literal["none", "stored", "failed"]


@dataclass
class SendResult:
    conversation_id: int
    created_conversation: bool
    attachment_status: AttachmentStatus
    message: Message


class MessagingService:
    def __init__(self, *, settings: Settings, db: Database):
        self.settings = settings
        self.db = db
        self.repo = MessagingRepository(db)
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
        await self.db.dispose()

    # ------------------------------------------------------------------ send
    async def send(self, req: SendMessageRequest) -> SendResult:
        await self.ensure_ready()
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
        return SendResult(conv.id, created, status, msg)

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
        logger.info(
            "messaging.messages_cleared conversation_id=%s count=%d", conversation_id, count
        )
        return count
