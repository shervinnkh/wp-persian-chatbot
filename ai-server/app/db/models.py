"""Text database tables for the knowledge base.

`records`  - one row per extracted WordPress record, holding the full raw JSON once.
`chunks`   - one row per embedded chunk (text only). Vectors live in Qdrant; at retrieval time
             the chunk ids returned by Qdrant are looked up here and joined to `records`.
"""

from __future__ import annotations

from datetime import datetime, timezone

from sqlalchemy import JSON, DateTime, ForeignKey, Integer, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base


def _now() -> datetime:
    return datetime.now(timezone.utc)


class Record(Base):
    __tablename__ = "records"

    record_id: Mapped[str] = mapped_column(String(128), primary_key=True)  # "product:14542"
    kind: Mapped[str] = mapped_column(String(64), index=True)  # id prefix: product, blog, page...
    type: Mapped[str | None] = mapped_column(String(64), nullable=True)  # product/post/page/...
    title: Mapped[str | None] = mapped_column(Text, nullable=True)
    url: Mapped[str | None] = mapped_column(Text, nullable=True)
    data: Mapped[dict] = mapped_column(JSON)  # full raw record (images, sku, currency, links...)
    data_hash: Mapped[str] = mapped_column(String(64))
    pipeline: Mapped[str] = mapped_column(String(255))  # chunker/embedding settings used
    chunk_count: Mapped[int] = mapped_column(Integer, default=0)
    token_count: Mapped[int] = mapped_column(Integer, default=0)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_now)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_now)

    chunks: Mapped[list[Chunk]] = relationship(
        back_populates="record", cascade="all, delete-orphan", order_by="Chunk.index"
    )


class Chunk(Base):
    __tablename__ = "chunks"
    __table_args__ = (UniqueConstraint("record_id", "index", name="uq_chunks_record_index"),)

    chunk_id: Mapped[str] = mapped_column(String(160), primary_key=True)  # "product:14542#0"
    record_id: Mapped[str] = mapped_column(
        ForeignKey("records.record_id", ondelete="CASCADE"), index=True
    )
    index: Mapped[int] = mapped_column(Integer)
    text: Mapped[str] = mapped_column(Text)
    tokens: Mapped[int] = mapped_column(Integer)
    hash: Mapped[str] = mapped_column(String(64))

    record: Mapped[Record] = relationship(back_populates="chunks")
