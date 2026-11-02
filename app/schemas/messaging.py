"""Request/response models for messaging."""

from __future__ import annotations

import re
from datetime import datetime, timezone
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.db.messaging_models import AttachmentType, MessageRole
from app.schemas.ingest import CallbackIn

_UID_RE = re.compile(r"^[A-Za-z0-9_.:\-]{1,64}$")
MAX_SUGGESTIONS = 4


class ChatSite(BaseModel):
    """Short facts about the site (plugin settings); only used when an AI answer is generated."""

    title: str | None = Field(None, max_length=200)
    description: str | None = Field(None, max_length=400)


class ChatPage(BaseModel):
    """The page the visitor is chatting on (tiny, static info that exists on every page)."""

    url: str | None = Field(None, max_length=2048)
    slug: str | None = Field(None, max_length=200)  # "" / "home" for the front page
    title: str | None = Field(None, max_length=300)
    type: str | None = Field(None, max_length=40)  # home | product | post | page | archive | other


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

    # Only meaningful for role=user: true -> an answer is generated and saved as `assistant`.
    # Missing / false -> the message is only saved. Ignored for admin/assistant messages.
    generate_ai_answer: bool = False

    # The fields below are used ONLY when generate_ai_answer is true (they go into the prompt).
    model: str | None = Field(None, max_length=64)  # key from AI_ALLOWED_MODELS; else the default
    tone: Literal["formal", "friendly"] | None = None
    site: ChatSite | None = None
    page: ChatPage | None = None
    # With generate_ai_answer=true AND a callback: the request returns at once
    # (ai_answer.status="queued") and the answer is POSTed to the callback URL when it is ready
    # (kind "chat_answer"). Without a callback the answer is generated inside the request.
    callback: CallbackIn | None = None

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
        if self.role != MessageRole.user:
            self.generate_ai_answer = False
        if not self.generate_ai_answer:
            self.callback = None
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


class AiAnswerOut(BaseModel):
    # skipped = no answer requested | generated = `message` is the saved reply | failed = the
    # user message is saved but the answer could not be produced (reason in the server log)
    # queued = (request had a callback) the answer is generated in the background and POSTed to
    # the callback when ready; `job_id` names it
    status: Literal["skipped", "generated", "failed", "queued"]
    job_id: str | None = None
    message: MessageOut | None = None
    full_history_tokens: int | None = None  # summary + all unsummarized messages
    context_tokens: int | None = None  # what the AI would receive (summary + last pairs + question)
    summarization_scheduled: bool = False  # queued right after this response was sent
    model: str | None = None  # the model that answered (after the allow-list was applied)


class SendMessageResponse(BaseModel):
    conversation_id: int
    created_conversation: bool
    # none = no attachment | stored = downloaded | failed = message saved, download failed
    attachment_status: Literal["none", "stored", "failed"]
    message: MessageOut
    ai_answer: AiAnswerOut


class DeleteMessageResponse(BaseModel):
    conversation_id: int
    message_id: int
    deleted: bool = True


class ClearMessagesResponse(BaseModel):
    conversation_id: int
    deleted_count: int
