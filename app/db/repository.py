"""Data access for records/chunks (the only module that talks SQL for the knowledge base)."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone

from sqlalchemy import delete, func, select

from app.db.base import Database
from app.db.models import Chunk, Record


@dataclass(frozen=True)
class RecordState:
    record_id: str
    data_hash: str
    pipeline: str
    chunk_count: int
    chunk_hashes: dict[int, str]  # chunk index -> text hash


@dataclass(frozen=True)
class RecordWrite:
    record_id: str
    kind: str
    type: str | None
    title: str | None
    url: str | None
    data: dict
    data_hash: str
    pipeline: str


@dataclass(frozen=True)
class ChunkWrite:
    chunk_id: str
    index: int
    text: str
    tokens: int
    hash: str


@dataclass(frozen=True)
class RetrievedChunk:
    """A chunk joined to its record - what the chat step needs after a vector search."""

    chunk_id: str
    record_id: str
    index: int
    text: str
    tokens: int
    record_type: str | None
    title: str | None
    url: str | None
    data: dict


class KnowledgeRepository:
    def __init__(self, db: Database):
        self.db = db

    async def get_state(self, record_id: str) -> RecordState | None:
        async with self.db.session() as s:
            rec = await s.get(Record, record_id)
            if rec is None:
                return None
            rows = await s.execute(
                select(Chunk.index, Chunk.hash).where(Chunk.record_id == record_id)
            )
            return RecordState(
                record_id=rec.record_id,
                data_hash=rec.data_hash,
                pipeline=rec.pipeline,
                chunk_count=rec.chunk_count,
                chunk_hashes={i: h for i, h in rows.all()},
            )

    async def save(self, record: RecordWrite, chunks: list[ChunkWrite]) -> bool:
        """Insert or replace a record and ALL its chunks in one transaction.

        Returns True when the record was newly created.
        """
        now = datetime.now(timezone.utc)
        async with self.db.session() as s, s.begin():
            existing = await s.get(Record, record.record_id)
            created = existing is None
            row = existing or Record(record_id=record.record_id, created_at=now)
            row.kind, row.type, row.title, row.url = (
                record.kind,
                record.type,
                record.title,
                record.url,
            )
            row.data, row.data_hash, row.pipeline = record.data, record.data_hash, record.pipeline
            row.chunk_count = len(chunks)
            row.token_count = sum(c.tokens for c in chunks)
            row.updated_at = now
            if created:
                s.add(row)
                await s.flush()
            else:
                await s.execute(delete(Chunk).where(Chunk.record_id == record.record_id))
            s.add_all(
                Chunk(
                    chunk_id=c.chunk_id,
                    record_id=record.record_id,
                    index=c.index,
                    text=c.text,
                    tokens=c.tokens,
                    hash=c.hash,
                )
                for c in chunks
            )
        return created

    async def delete(self, record_id: str) -> int | None:
        """Delete a record and its chunks. Returns the number of chunks removed (None = absent)."""
        async with self.db.session() as s, s.begin():
            rec = await s.get(Record, record_id)
            if rec is None:
                return None
            removed = rec.chunk_count
            await s.execute(delete(Chunk).where(Chunk.record_id == record_id))
            await s.execute(delete(Record).where(Record.record_id == record_id))
            return removed

    async def list_records(
        self, *, limit: int, offset: int, kind: str | None = None
    ) -> tuple[int, list[Record]]:
        async with self.db.session() as s:
            q = select(Record).order_by(Record.updated_at.desc(), Record.record_id)
            cq = select(func.count()).select_from(Record)
            if kind:
                q, cq = q.where(Record.kind == kind), cq.where(Record.kind == kind)
            total = (await s.execute(cq)).scalar_one()
            rows = (await s.execute(q.limit(limit).offset(offset))).scalars().all()
            return total, list(rows)

    async def get_record(self, record_id: str) -> tuple[Record, list[Chunk]] | None:
        async with self.db.session() as s:
            rec = await s.get(Record, record_id)
            if rec is None:
                return None
            rows = await s.execute(
                select(Chunk).where(Chunk.record_id == record_id).order_by(Chunk.index)
            )
            return rec, list(rows.scalars().all())

    async def get_chunks(self, chunk_ids: list[str]) -> list[RetrievedChunk]:
        """Look up chunks (with their record) by the ids found in Qdrant payloads."""
        if not chunk_ids:
            return []
        async with self.db.session() as s:
            rows = await s.execute(
                select(Chunk, Record)
                .join(Record, Record.record_id == Chunk.record_id)
                .where(Chunk.chunk_id.in_(chunk_ids))
            )
            found = {
                c.chunk_id: RetrievedChunk(
                    c.chunk_id,
                    c.record_id,
                    c.index,
                    c.text,
                    c.tokens,
                    r.type,
                    r.title,
                    r.url,
                    r.data,
                )  # fmt: skip
                for c, r in rows.all()
            }
        return [found[i] for i in chunk_ids if i in found]  # keep the vector-search order
