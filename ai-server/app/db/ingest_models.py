"""Tables of the ingestion queue and of the callback (webhook) outbox.

`ingest_jobs`          one row per accepted request (upsert / replace / delete of ONE record).
                       The API inserts it (status pending) and answers 200 at once; the ingestion
                       worker takes it, does chunk -> embed -> Qdrant + text DB.
`callback_sites`       one row per plugin site (key = callback host[:port]): where to call, health
                       state (circuit breaker), adaptive batch size. The first host ever seen (or
                       PLUGIN_BASE_URL) is the only one accepted (the key is locked to a site).
`callback_deliveries`  outbox: one row per finished job that must be reported to the plugin. The
                       delivery worker sends them in batches (many results per HTTP request).
"""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import (
    JSON,
    Boolean,
    CheckConstraint,
    DateTime,
    Index,
    Integer,
    String,
    Text,
)
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base
from app.db.messaging_models import utcnow


class IngestJob(Base):
    __tablename__ = "ingest_jobs"
    __table_args__ = (
        CheckConstraint(
            "status IN ('pending','processing','done','failed','superseded')",
            name="ck_ingest_jobs_status",
        ),
        CheckConstraint("action IN ('upsert','replace','delete')", name="ck_ingest_jobs_action"),
        Index("ix_ingest_jobs_status_available", "status", "available_at"),
        Index("ix_ingest_jobs_record_status", "record_id", "status"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    job_id: Mapped[str] = mapped_column(String(32), unique=True, nullable=False)  # "j_8f3a1c2d"
    record_id: Mapped[str] = mapped_column(String(128), nullable=False)
    action: Mapped[str] = mapped_column(String(16), nullable=False)
    event: Mapped[str | None] = mapped_column(String(16), nullable=True)  # as sent by the plugin
    rev: Mapped[str | None] = mapped_column(String(128), nullable=True)  # echoed in the callback
    force: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    # record data (null once processed)
    payload: Mapped[dict | None] = mapped_column(JSON(none_as_null=True), nullable=True)
    # callback descriptor from the plugin
    callback: Mapped[dict | None] = mapped_column(JSON(none_as_null=True), nullable=True)
    site_key: Mapped[str | None] = mapped_column(String(255), nullable=True)
    status: Mapped[str] = mapped_column(String(16), nullable=False, default="pending")
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    outcome: Mapped[str | None] = mapped_column(String(16), nullable=True)  # trained | untrained
    # per-record stats
    result: Mapped[dict | None] = mapped_column(JSON(none_as_null=True), nullable=True)
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


class CallbackSite(Base):
    __tablename__ = "callback_sites"

    host: Mapped[str] = mapped_column(String(255), primary_key=True)  # "shop.example.com[:8443]"
    callback_url: Mapped[str] = mapped_column(Text, nullable=False)  # latest descriptor values
    ping_url: Mapped[str | None] = mapped_column(Text, nullable=True)
    site_url: Mapped[str | None] = mapped_column(Text, nullable=True)
    max_results: Mapped[int] = mapped_column(Integer, nullable=False, default=500)
    # healthy | unhealthy (circuit open) | suspended (plugin off / not configured) | auth_failed
    state: Mapped[str] = mapped_column(String(16), nullable=False, default="healthy")
    consecutive_failures: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    next_attempt_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    next_ping_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    last_ping_ms: Mapped[int | None] = mapped_column(Integer, nullable=True)
    last_latency_ms: Mapped[int | None] = mapped_column(Integer, nullable=True)
    last_ok_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)
    batch_limit: Mapped[int] = mapped_column(Integer, nullable=False, default=50)
    clock_offset: Mapped[int] = mapped_column(Integer, nullable=False, default=0)  # site - us (s)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, onupdate=utcnow, nullable=False
    )


class CallbackDelivery(Base):
    __tablename__ = "callback_deliveries"
    __table_args__ = (
        CheckConstraint("state IN ('pending','done','dead')", name="ck_callback_deliveries_state"),
        CheckConstraint(
            "result_status IN ('trained','untrained','failed')",
            name="ck_callback_deliveries_result",
        ),
        Index("ix_callback_deliveries_site_state", "site_key", "state", "available_at"),
        Index("ix_callback_deliveries_batch", "batch_id"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    site_key: Mapped[str] = mapped_column(String(255), nullable=False)
    callback_url: Mapped[str] = mapped_column(Text, nullable=False)
    job_id: Mapped[str] = mapped_column(String(32), nullable=False)
    record_id: Mapped[str] = mapped_column(String(128), nullable=False)
    result_status: Mapped[str] = mapped_column(String(16), nullable=False)
    # The job's action (upsert|replace|delete). Sent as `operation` only for a FAILED delete, so
    # the plugin knows to put the record back to "trained" instead of releasing it.
    action: Mapped[str | None] = mapped_column(String(16), nullable=True)
    # NULL/"record" = a knowledge-record result; "answer" = a finished chat answer, whose whole
    # callback item is in `payload` (JSON text). For "answer" rows result_status is only
    # trained (= answered) | failed (the table's check constraint predates chat answers).
    kind: Mapped[str | None] = mapped_column(String(16), nullable=True)
    payload: Mapped[str | None] = mapped_column(Text, nullable=True)
    rev: Mapped[str | None] = mapped_column(String(128), nullable=True)
    error: Mapped[str | None] = mapped_column(Text, nullable=True)  # reported to the plugin
    state: Mapped[str] = mapped_column(String(16), nullable=False, default="pending")
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    # Rows sent together share a batch id = the X-PWC-Delivery value; a retry reuses it.
    batch_id: Mapped[str | None] = mapped_column(String(36), nullable=True)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)  # why a send failed
    available_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )
    finished_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, onupdate=utcnow, nullable=False
    )
