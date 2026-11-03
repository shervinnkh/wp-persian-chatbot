"""Knowledge ingestion: record JSON -> chunks -> embeddings -> Qdrant + text DB (and deletion).

Order of writes for one record (so a failure never leaves the text DB ahead of Qdrant):
    1. build chunks (CPU, in a thread)        nothing written yet
    2. embed the new/changed chunks           nothing written yet
    3. upsert points into Qdrant              idempotent (deterministic point ids)
    4. drop Qdrant points of removed chunks   idempotent
    5. commit record + chunks to the text DB  one transaction
A failure before step 5 simply leaves the record "not confirmed"; sending it again is safe.
"""

from __future__ import annotations

import asyncio
import logging
import time
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from typing import Any

from app.core.config import Settings
from app.core.errors import AppError, UnprocessableRecordError
from app.db.base import Database
from app.db.repository import ChunkWrite, KnowledgeRepository, RecordWrite
from app.schemas.knowledge import (
    DeleteItemResult,
    UpsertItemResult,
)
from app.services.chunking.pipeline import (
    CHUNKER_VERSION,
    ChunkPlan,
    build_plan,
    data_hash,
    make_chunk_id,
)
from app.services.chunking.splitter import ChunkConfig
from app.services.chunking.tokens import TokenCounter
from app.services.embedding import Embedder, OpenAIEmbedder
from app.services.vectorstore import VectorPoint, VectorStore

logger = logging.getLogger(__name__)

# A failure with one of these codes may succeed later (OpenAI/Qdrant hiccup, bug-free retry);
# anything else (e.g. a record without usable text) will fail the same way again.
RETRYABLE_CODES = frozenset({"upstream_error", "service_unavailable", "internal_error"})


class _KeyedLocks:
    """One asyncio lock per record id, so two requests never write the same record at once."""

    def __init__(self) -> None:
        self._locks: dict[str, tuple[asyncio.Lock, int]] = {}

    @asynccontextmanager
    async def hold(self, key: str) -> AsyncIterator[None]:
        lock, users = self._locks.get(key, (asyncio.Lock(), 0))
        self._locks[key] = (lock, users + 1)
        try:
            async with lock:
                yield
        finally:
            lock, users = self._locks[key]
            if users <= 1:
                del self._locks[key]
            else:
                self._locks[key] = (lock, users - 1)


class KnowledgeService:
    def __init__(
        self,
        *,
        settings: Settings,
        db: Database,
        vectorstore: VectorStore,
        embedder: Embedder,
        counter: TokenCounter,
    ):
        self.settings = settings
        self.db = db
        self.repo = KnowledgeRepository(db)
        self.vectorstore = vectorstore
        self.embedder = embedder
        self.counter = counter
        self.chunk_cfg = ChunkConfig(
            target_tokens=settings.CHUNK_TARGET_TOKENS,
            max_tokens=settings.CHUNK_MAX_TOKENS,
            overlap_tokens=settings.CHUNK_OVERLAP_TOKENS,
            min_tokens=settings.CHUNK_MIN_TOKENS,
        )
        # Changing any of these re-chunks and re-embeds a record the next time it is sent.
        self.pipeline_id = (
            f"v{CHUNKER_VERSION}|{counter.name}|{self.chunk_cfg.target_tokens}/"
            f"{self.chunk_cfg.max_tokens}/{self.chunk_cfg.overlap_tokens}/"
            f"{self.chunk_cfg.min_tokens}|{embedder.model}|{embedder.dimensions}"
        )
        self._locks = _KeyedLocks()
        self._ready = False
        self._ready_lock = asyncio.Lock()

    @classmethod
    def from_settings(cls, settings: Settings) -> KnowledgeService:
        return cls(
            settings=settings,
            db=Database.from_settings(settings),
            vectorstore=VectorStore(settings),
            embedder=OpenAIEmbedder(settings),
            counter=TokenCounter.from_settings(settings),
        )

    # ------------------------------------------------------------------ lifecycle
    async def ensure_ready(self) -> None:
        if self._ready:
            return
        async with self._ready_lock:
            if self._ready:
                return
            await self.db.init()
            await self.vectorstore.ensure_collection()
            self._ready = True
            logger.info(
                "knowledge.ready collection=%s pipeline=%s", self.vectorstore.collection,
                self.pipeline_id,
            )  # fmt: skip

    async def close(self) -> None:
        await self.embedder.close()
        await self.vectorstore.close()
        await self.db.dispose()

    # ------------------------------------------------------------------ upsert
    async def upsert_many(
        self, items: list[tuple[str, dict[str, Any]]], *, force: bool = False
    ) -> list[UpsertItemResult]:
        await self.ensure_ready()
        logger.info("knowledge.upsert_batch records=%d force=%s", len(items), force)
        results: list[UpsertItemResult] = []
        for record_id, data in items:  # one by one, in the order received
            t0 = time.perf_counter()
            try:
                async with self._locks.hold(record_id):
                    result = await self._upsert_one(record_id, data, force)
            except AppError as exc:
                logger.warning("knowledge.upsert_failed id=%s error=%s", record_id, exc.message)
                result = UpsertItemResult(
                    id=record_id,
                    status="failed",
                    error=exc.message,
                    error_code=exc.code,
                    retryable=exc.code in RETRYABLE_CODES,
                )
            except Exception:
                logger.exception("knowledge.upsert_crashed id=%s", record_id)
                result = UpsertItemResult(
                    id=record_id,
                    status="failed",
                    error="Internal error (see server log)",
                    error_code="internal_error",
                    retryable=True,
                )
            result.duration_ms = int((time.perf_counter() - t0) * 1000)
            logger.info(
                "knowledge.upsert_done id=%s status=%s chunks=%d embedded=%d reused=%d "
                "tokens=%d embedding_tokens=%d ms=%d",
                result.id, result.status, result.chunks, result.embedded_chunks,
                result.reused_chunks, result.tokens, result.embedding_tokens, result.duration_ms,
            )  # fmt: skip
            results.append(result)
        return results

    async def _upsert_one(self, record_id: str, data: dict, force: bool) -> UpsertItemResult:
        digest = data_hash(data)
        state = await self.repo.get_state(record_id)
        logger.info(
            "knowledge.upsert_start id=%s exists=%s force=%s", record_id, state is not None, force
        )

        if (
            state is not None
            and not force
            and state.data_hash == digest
            and state.pipeline == self.pipeline_id
            and state.chunk_count > 0
        ):
            logger.info(
                "knowledge.unchanged id=%s (same data and pipeline; nothing to do)", record_id
            )
            return UpsertItemResult(id=record_id, status="unchanged", chunks=state.chunk_count)

        # 1. chunking (CPU-bound -> thread, keeps the event loop free for big pages)
        t0 = time.perf_counter()
        plan: ChunkPlan = await asyncio.to_thread(
            build_plan, record_id, data, self.counter, self.chunk_cfg
        )
        logger.info(
            "knowledge.chunked id=%s type=%s chunks=%d kinds=%s tokens=%s total_tokens=%d "
            "text_chars=%d exact_tokenizer=%s ms=%d",
            record_id, plan.record_type, len(plan.chunks), plan.kinds,
            [c.tokens for c in plan.chunks], plan.total_tokens, plan.text_chars,
            self.counter.exact, (time.perf_counter() - t0) * 1000,
        )  # fmt: skip

        return await self.store_prebuilt(
            record_id,
            record_type=plan.record_type,
            title=plan.title,
            url=plan.url,
            data=data,
            digest=digest,
            chunks=[(c.text, c.tokens, c.hash) for c in plan.chunks],
            kinds=[c.kind for c in plan.chunks],
            pipeline=self.pipeline_id,
            force=force,
            state=state,
        )

    async def store_prebuilt(
        self,
        record_id: str,
        *,
        record_type: str,
        title: str,
        url: str | None,
        data: dict,
        digest: str,
        chunks: list[tuple[str, int, str]],
        pipeline: str,
        force: bool,
        state,
        kinds: list[str] | None = None,
    ) -> UpsertItemResult:
        """Steps 2-5 for chunks that are already built: (text, tokens, hash) per chunk.

        Used by `_upsert_one` (records) and by ContentService (texts and files)."""
        if not chunks:
            raise UnprocessableRecordError("Record produced no text chunks")
        kinds = kinds or ["main"] * len(chunks)
        total_tokens = sum(t for _, t, _ in chunks)

        # 2. embed only the chunks whose text changed (or all, when pipeline/force says so)
        reuse = state is not None and not force and state.pipeline == pipeline
        old_hashes = state.chunk_hashes if (state and reuse) else {}
        to_embed = [(i, c) for i, c in enumerate(chunks) if old_hashes.get(i) != c[2]]
        embedding_tokens = 0
        if to_embed:
            embedded = await self.embedder.embed([c[0] for _, c in to_embed])
            embedding_tokens = embedded.prompt_tokens
            expected = sum(c[1] for _, c in to_embed)
            if (
                self.counter.exact
                and expected
                and abs(embedding_tokens - expected) > 0.15 * expected
            ):
                logger.warning(
                    "knowledge.token_mismatch id=%s tiktoken=%d api_prompt_tokens=%d "
                    "(check TIKTOKEN_ENCODING vs EMBEDDING_MODEL)",
                    record_id, expected, embedding_tokens,
                )  # fmt: skip

            # 3. vectors -> Qdrant (payload holds ids + small filter fields only)
            points = [
                VectorPoint(
                    chunk_id=make_chunk_id(record_id, i),
                    vector=vec,
                    payload={
                        "record_id": record_id,
                        "chunk_id": make_chunk_id(record_id, i),
                        "chunk_index": i,
                        "kind": kinds[i],
                        "type": record_type,
                        "url": url,
                        "title": title,
                    },
                )
                for (i, _), vec in zip(to_embed, embedded.vectors, strict=True)
            ]
            await self.vectorstore.upsert(points)
            logger.info("knowledge.qdrant_upserted id=%s points=%d", record_id, len(points))
        else:
            logger.info("knowledge.no_new_chunks id=%s (all chunk texts unchanged)", record_id)

        # 4. the record may have become shorter: drop points of chunks that no longer exist
        if state is not None and state.chunk_count > len(chunks):
            await self.vectorstore.delete_stale(record_id, len(chunks))
            logger.info(
                "knowledge.qdrant_stale_removed id=%s from_index=%d", record_id, len(chunks)
            )

        # 5. text DB (one transaction)
        created = await self.repo.save(
            RecordWrite(
                record_id=record_id,
                kind=record_id.split(":", 1)[0],
                type=record_type,
                title=title,
                url=url,
                data=data,
                data_hash=digest,
                pipeline=pipeline,
            ),
            [
                ChunkWrite(make_chunk_id(record_id, i), i, c[0], c[1], c[2])
                for i, c in enumerate(chunks)
            ],
        )
        logger.info(
            "knowledge.db_saved id=%s created=%s chunks=%d", record_id, created, len(chunks)
        )
        return UpsertItemResult(
            id=record_id,
            status="created" if created else "updated",
            chunks=len(chunks),
            embedded_chunks=len(to_embed),
            reused_chunks=len(chunks) - len(to_embed),
            tokens=total_tokens,
            embedding_tokens=embedding_tokens,
        )

    # ------------------------------------------------------------------ helpers for ContentService
    def pipeline_for(self, extra: str) -> str:
        """Pipeline id for records built outside `build_plan` (texts/files): the base pipeline
        (tokenizer, chunk sizes, embedding model) plus the version of their own chunker."""
        return f"{self.pipeline_id}|{extra}"

    async def get_state(self, record_id: str):
        return await self.repo.get_state(record_id)

    def lock(self, record_id: str):
        return self._locks.hold(record_id)

    # ------------------------------------------------------------------ delete
    async def delete_many(self, ids: list[str]) -> list[DeleteItemResult]:
        await self.ensure_ready()
        logger.info("knowledge.delete_batch ids=%d", len(ids))
        results: list[DeleteItemResult] = []
        for record_id in ids:
            try:
                async with self._locks.hold(record_id):
                    # Qdrant first: if it fails the text DB is untouched and a retry is safe.
                    await self.vectorstore.delete_record(record_id)
                    removed = await self.repo.delete(record_id)
                result = DeleteItemResult(
                    id=record_id,
                    status="not_found" if removed is None else "deleted",
                    chunks_removed=removed or 0,
                )
            except AppError as exc:
                result = DeleteItemResult(
                    id=record_id,
                    status="failed",
                    error=exc.message,
                    error_code=exc.code,
                    retryable=exc.code in RETRYABLE_CODES,
                )
            except Exception:
                logger.exception("knowledge.delete_crashed id=%s", record_id)
                result = DeleteItemResult(
                    id=record_id,
                    status="failed",
                    error="Internal error (see server log)",
                    error_code="internal_error",
                    retryable=True,
                )
            logger.info(
                "knowledge.delete_done id=%s status=%s chunks_removed=%d error=%s",
                result.id, result.status, result.chunks_removed, result.error,
            )  # fmt: skip
            results.append(result)
        return results
