"""Text database tables for conversations and chat messages.

Each user has exactly ONE conversation. Its unique key `owner_key` is
  - ``u:{wp_user_id}``   for a logged-in WordPress user
  - ``v:{visitor_uid}``  for a guest
"""
from __future__ import annotations

import enum
from datetime import datetime, timezone

from sqlalchemy import (
    JSON,
    CheckConstraint,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
)
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class MessageRole(str, enum.Enum):
    user = "user"
    admin = "admin"
    assistant = "assistant"


class AttachmentType(str, enum.Enum):
    image = "image"
    voice = "voice"


class Conversation(Base):
    __tablename__ = "conversations"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    owner_key: Mapped[str] = mapped_column(String(80), unique=True, nullable=False)
    visitor_uid: Mapped[str] = mapped_column(String(64), nullable=False, index=True)
    wp_user_id: Mapped[int | None] = mapped_column(Integer, nullable=True, index=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, onupdate=utcnow, nullable=False
    )
    # Rolling summary: covers every message with id <= summarized_upto_message_id.
    summary: Mapped[str | None] = mapped_column(Text, nullable=True)
    summarized_upto_message_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    summary_updated_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )


class Message(Base):
    __tablename__ = "messages"
    __table_args__ = (
        CheckConstraint("role IN ('user','admin','assistant')", name="ck_messages_role"),
        Index("ix_messages_conversation_id_id", "conversation_id", "id"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    conversation_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("conversations.id", ondelete="CASCADE"), nullable=False
    )
    visitor_uid: Mapped[str] = mapped_column(String(64), nullable=False)
    wp_user_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    role: Mapped[str] = mapped_column(String(16), nullable=False)
    content: Mapped[str] = mapped_column(Text, nullable=False, default="")
    attachment_type: Mapped[str | None] = mapped_column(String(16), nullable=True)
    attachment_url: Mapped[str | None] = mapped_column(Text, nullable=True)
    # RELATIVE to UPLOADS_DIR, e.g. image/2026/10/<uuid>.jpg
    attachment_path: Mapped[str | None] = mapped_column(String(500), nullable=True)
    suggestions: Mapped[list | None] = mapped_column(JSON, nullable=True)
    admin_user_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    admin_name: Mapped[str | None] = mapped_column(String(120), nullable=True)
    # Speech-to-text of a voice message (filled when the AI answer is generated).
    transcript: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )


class SummaryStatus(str, enum.Enum):
    pending = "pending"
    processing = "processing"
    done = "done"
    failed = "failed"


class SummaryRequest(Base):
    """A queued request to summarize a conversation (picked up by the summarizer worker)."""

    __tablename__ = "summary_requests"
    __table_args__ = (
        CheckConstraint(
            "status IN ('pending','processing','done','failed')", name="ck_summary_requests_status"
        ),
        Index("ix_summary_requests_status_available", "status", "available_at"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    conversation_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("conversations.id", ondelete="CASCADE"), nullable=False, index=True
    )
    # Summarize every message up to (and including) this id.
    upto_message_id: Mapped[int] = mapped_column(Integer, nullable=False)
    status: Mapped[str] = mapped_column(String(16), nullable=False, default="pending")
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    error: Mapped[str | None] = mapped_column(Text, nullable=True)
    available_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )
    started_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, onupdate=utcnow, nullable=False
    )
