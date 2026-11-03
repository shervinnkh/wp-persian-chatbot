"""Queue of AI answers to generate (one row per user message that asked for an answer)."""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import JSON, CheckConstraint, DateTime, Index, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base
from app.db.messaging_models import utcnow


class AnswerJob(Base):
    __tablename__ = "answer_jobs"
    __table_args__ = (
        CheckConstraint(
            "status IN ('pending','processing','done','failed')", name="ck_answer_jobs_status"
        ),
        Index("ix_answer_jobs_status_available", "status", "available_at"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    job_id: Mapped[str] = mapped_column(String(32), unique=True, nullable=False)
    conversation_id: Mapped[int] = mapped_column(Integer, nullable=False)
    question_message_id: Mapped[int] = mapped_column(Integer, nullable=False)
    site_key: Mapped[str] = mapped_column(String(255), nullable=False)
    callback: Mapped[dict] = mapped_column(JSON, nullable=False)  # validated callback descriptor
    ctx: Mapped[dict] = mapped_column(JSON, nullable=False)  # PromptContext fields
    status: Mapped[str] = mapped_column(String(16), nullable=False, default="pending")
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    error: Mapped[str | None] = mapped_column(Text, nullable=True)
    answer_message_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
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
