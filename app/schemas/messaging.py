"""Request/response models for messaging."""
from __future__ import annotations

import re
from datetime import datetime, timezone
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.db.messaging_models import AttachmentType, MessageRole

_UID_RE = re.compile(r"^[A-Za-z0-9_.:\-]{1,64}$")
MAX_SUGGESTIONS = 4


class SendMessageRequest(BaseModel):
    # Conversation lookup: either conversation_id, or visitor_uid (+ wp_user_id when logged in)
    conversation_id: int | None = Field(None, ge=1)
    visitor_uid: str | None = Field(None, max_length=64)
    wp_user_id: int | None = Field(None, ge=0)  # 0 (WordPress guest) -> None

    role: MessageRole
    content: str = Field("", max_length=20000)
    attachment_type: AttachmentType | None = None
    attachment_url: str | None = Field(None, max_length=2048)
    suggestions: list[str] | None = None

    admin_user_id: int | None = Field(None, ge=1)
    admin_name: str | None = Field(None, max_length=120)

    @field_validator("wp_user_id")
    @classmethod
    def _guest_zero(cls, v: int | None) -> int | None:
        return v or None

    @field_validator("visitor_uid")
    @classmethod
    def _uid(cls, v: str | None) -> str | None:
        if v is None:
            return v
        if not _UID_RE.match(v):
            raise ValueError("visitor_uid has invalid characters")
        return v

    @field_validator("attachment_url")
    @classmethod
    def _url(cls, v: str | None) -> str | None:
        if v is not None and not re.match(r"^https?://", v, re.I):
            raise ValueError("attachment_url must be http(s)")
        return v

    @field_validator("suggestions")
    @classmethod
    def _suggestions(cls, v: list[str] | None) -> list[str] | None:
        if v is None:
            return v
        if len(v) > MAX_SUGGESTIONS:
            raise ValueError(f"at most {MAX_SUGGESTIONS} suggestions are allowed")
        cleaned = [s.strip() for s in v if isinstance(s, str) and s.strip()]
        if any(len(s) > 200 for s in cleaned):
            raise ValueError("each suggestion must be at most 200 characters")
        return cleaned or None

    @model_validator(mode="after")
    def _check(self) -> SendMessageRequest:
        if self.conversation_id is None and not self.visitor_uid:
            raise ValueError("conversation_id or visitor_uid is required")
        if (self.attachment_type is None) != (self.attachment_url is None):
            raise ValueError("attachment_type and attachment_url must be sent together")
        self.content = self.content.strip()
        if not self.content and self.attachment_url is None:
            raise ValueError("content or attachment is required")
        if self.role == MessageRole.admin:
            if self.admin_user_id is None:
                raise ValueError("admin_user_id is required for role=admin")
        else:
            self.admin_user_id = None
            self.admin_name = None
        return self


class MessageOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    conversation_id: int
    visitor_uid: str
    wp_user_id: int | None
    role: MessageRole
    content: str
    attachment_type: AttachmentType | None
    attachment_url: str | None
    attachment_path: str | None
    suggestions: list[str] | None
    created_at: datetime
    admin_user_id: int | None
    admin_name: str | None

    @field_validator("created_at")
    @classmethod
    def _utc(cls, v: datetime) -> datetime:  # SQLite returns naive datetimes
        return v.replace(tzinfo=timezone.utc) if v.tzinfo is None else v


class SendMessageResponse(BaseModel):
    conversation_id: int
    created_conversation: bool
    # none = no attachment | stored = downloaded | failed = message saved, download failed
    attachment_status: Literal["none", "stored", "failed"]
    message: MessageOut


class DeleteMessageResponse(BaseModel):
    conversation_id: int
    message_id: int
    deleted: bool = True


class ClearMessagesResponse(BaseModel):
    conversation_id: int
    deleted_count: int
