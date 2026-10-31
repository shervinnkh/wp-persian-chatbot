"""Texts (`text:*`) and files (`file:*`) -> chunks -> embeddings -> Qdrant + text DB.

Built on `KnowledgeService` (same locks, same write order, same stores):
  texts : plugin chunks are kept; only chunks > CHUNK_MAX_TOKENS are re-split (doc_chunker).
  files : download (FILES_BASE_URL + path) -> extractor (pdf/txt/docx) -> balanced chunker.
Update = delete completely, then add again (like PUT /records). Delete reuses `delete_many`
and also removes the downloaded file.
"""

from __future__ import annotations

import asyncio
import logging
import time
from collections.abc import Awaitable, Callable
from typing import Any

from app.core.config import Settings
from app.core.errors import AppError, FileProcessingError
from app.schemas.knowledge import (
    DeleteItemResult,
    FileItemIn,
    TextRecordIn,
    UpsertItemResult,
)
from app.services.chunking.doc_chunker import (
    CHUNKER_VERSION,
    BuiltChunk,
    chunk_document,
    rechunk_text_chunks,
)
from app.services.chunking.pipeline import data_hash, text_hash
from app.services.extractors.loader import extract_document_sync
from app.services.files.download import DownloadManager
from app.services.ingestion.service import KnowledgeService

logger = logging.getLogger(__name__)


def _as_tuples(chunks: list[BuiltChunk]) -> list[tuple[str, int, str]]:
    return [(c.text, c.tokens, c.hash) for c in chunks]


class ContentService:
    def __init__(self, knowledge: KnowledgeService, settings: Settings | None = None) -> None:
        self.kb = knowledge
        self.settings = settings or knowledge.settings
        self.downloads = DownloadManager(self.settings)
        self.pipeline = knowledge.pipeline_for(CHUNKER_VERSION)

    # ------------------------------------------------------------------ helpers
    async def _guard(
        self, record_id: str, fn: Callable[[], Awaitable[UpsertItemResult]]
    ) -> UpsertItemResult:
        t0 = time.perf_counter()
        try:
            result = await fn()
        except FileProcessingError as exc:
            logger.warning("content.failed id=%s code=%s msg=%s", record_id, exc.code, exc.message)
            result = UpsertItemResult(
                id=record_id, status="failed", error=exc.message, error_code=exc.code
            )
        except AppError as exc:
            logger.warning("content.failed id=%s code=%s msg=%s", record_id, exc.code, exc.message)
            result = UpsertItemResult(
                id=record_id, status="failed", error=exc.message, error_code=exc.code
            )
        except Exception:
            logger.exception("content.crashed id=%s", record_id)
            result = UpsertItemResult(
                id=record_id,
                status="failed",
                error="Internal error (see server log)",
                error_code="internal_error",
            )
        result.duration_ms = int((time.perf_counter() - t0) * 1000)
        logger.info(
            "content.done id=%s status=%s chunks=%d embedded=%d reused=%d tokens=%d "
            "embedding_tokens=%d ms=%d",
            result.id, result.status, result.chunks, result.embedded_chunks,
            result.reused_chunks, result.tokens, result.embedding_tokens, result.duration_ms,
        )  # fmt: skip
        return result

    async def _unchanged(self, record_id: str, digest: str, force: bool):
        state = await self.kb.get_state(record_id)
        if (
            state is not None
            and not force
            and state.data_hash == digest
            and state.pipeline == self.pipeline
            and state.chunk_count > 0
        ):
            return state, UpsertItemResult(
                id=record_id, status="unchanged", chunks=state.chunk_count
            )
        return state, None

    # ------------------------------------------------------------------ texts
    async def _add_text(self, rec: TextRecordIn, force: bool) -> UpsertItemResult:
        data: dict[str, Any] = rec.data
        pieces = data.get("chunks")
        pieces = [p for p in pieces if isinstance(p, str)] if isinstance(pieces, list) else []
        full = data.get("text") if isinstance(data.get("text"), str) else ""
        if not any(p.strip() for p in pieces):
            pieces = [full] if full.strip() else []
        if not pieces:
            raise FileProcessingError("unprocessable_record", "Text record has no chunks or text.")

        digest = data_hash(data)
        state, same = await self._unchanged(rec.id, digest, force)
        if same is not None:
            return same

        chunks = await asyncio.to_thread(
            rechunk_text_chunks, pieces, counter=self.kb.counter, cfg=self.kb.chunk_cfg
        )
        if not chunks:
            raise FileProcessingError("unprocessable_record", "Text record has no usable text.")
        source = full or "\n".join(pieces)
        title = next((ln.strip() for ln in source.splitlines() if ln.strip()), "")[:80]
        return await self.kb.store_prebuilt(
            rec.id,
            record_type="text",
            title=title,
            url=None,
            data=data,
            digest=digest,
            chunks=_as_tuples(chunks),
            pipeline=self.pipeline,
            force=force,
            state=state,
        )

    async def add_texts(self, records: list[TextRecordIn], *, force: bool = False):
        out: list[UpsertItemResult] = []
        for rec in records:
            async with self.kb.lock(rec.id):
                out.append(await self._guard(rec.id, lambda r=rec: self._add_text(r, force)))
        return out

    async def replace_texts(self, records: list[TextRecordIn]):
        out: list[UpsertItemResult] = []
        for rec in records:
            async with self.kb.lock(rec.id):
                failed = await self._delete_locked(rec.id, files=False)
                if failed is not None:
                    out.append(failed)
                    continue
                out.append(await self._guard(rec.id, lambda r=rec: self._add_text(r, True)))
        return out

    # ------------------------------------------------------------------ files
    async def _add_file(self, item: FileItemIn, force: bool) -> UpsertItemResult:
        dl = None
        try:
            dl = await self.downloads.download(item.id, item.path, item.file_original_name)
            title = item.title.strip() or item.file_original_name.strip() or dl.local_path.name
            digest = text_hash(f"{dl.sha256}|{title}|{item.file_original_name}|{item.path}")
            state, same = await self._unchanged(item.id, digest, force)
            if same is not None:
                same.url, same.size_bytes = dl.url, dl.size
                if not self.settings.FILES_KEEP_ORIGINAL:
                    self.downloads.remove(item.id)
                return same

            doc = await asyncio.to_thread(extract_document_sync, dl.local_path, dl.ext)
            if doc.chars > self.settings.FILES_MAX_EXTRACTED_CHARS:
                raise FileProcessingError(
                    "extracted_text_too_large", "Extracted text is too large to index."
                )
            chunks = await asyncio.to_thread(
                chunk_document,
                doc.blocks,
                title=title,
                counter=self.kb.counter,
                cfg=self.kb.chunk_cfg,
            )
            if not chunks:
                raise FileProcessingError("no_text_extracted", "File produced no usable text.")

            raw: dict[str, Any] = {
                "title": title,
                "original_name": item.file_original_name,
                "path": item.path,
                "download_url": dl.url,
                "extension": dl.ext,
                "size_bytes": dl.size,
                "sha256": dl.sha256,
                "stored_path": (
                    self.downloads.relative(dl.local_path)
                    if self.settings.FILES_KEEP_ORIGINAL
                    else None
                ),
                "extraction": doc.meta,
                "warnings": doc.warnings,
                "chars": doc.chars,
            }
            result = await self.kb.store_prebuilt(
                item.id,
                record_type="file",
                title=title,
                url=dl.url,
                data=raw,
                digest=digest,
                chunks=_as_tuples(chunks),
                pipeline=self.pipeline,
                force=force,
                state=state,
            )
        except BaseException:
            if dl is not None:  # never leave an orphan file behind
                self.downloads.remove(item.id)
            raise
        result.url, result.size_bytes = dl.url, dl.size
        if not self.settings.FILES_KEEP_ORIGINAL:
            self.downloads.remove(item.id)
        return result

    async def add_files(self, items: list[FileItemIn], *, force: bool = False):
        out: list[UpsertItemResult] = []
        for it in items:
            async with self.kb.lock(it.id):
                out.append(await self._guard(it.id, lambda i=it: self._add_file(i, force)))
        return out

    async def replace_files(self, items: list[FileItemIn]):
        out: list[UpsertItemResult] = []
        for it in items:
            async with self.kb.lock(it.id):
                failed = await self._delete_locked(it.id, files=True)
                if failed is not None:
                    out.append(failed)
                    continue
                out.append(await self._guard(it.id, lambda i=it: self._add_file(i, True)))
        return out

    # ------------------------------------------------------------------ delete
    async def _delete_locked(self, record_id: str, *, files: bool) -> UpsertItemResult | None:
        """Delete step of an update. Returns a failed result, or None when it is gone."""
        d = await self._delete_one(record_id, files=files, lock=False)
        if d.status == "failed":
            return UpsertItemResult(
                id=record_id,
                status="failed",
                error=f"delete step: {d.error}",
                error_code="delete_failed",
            )
        return None

    async def _delete_one(
        self, record_id: str, *, files: bool, lock: bool = True
    ) -> DeleteItemResult:
        # `delete_many` takes the record lock itself, so callers already holding it (PUT)
        # must not use it: delete through the same primitives under the held lock instead.
        kb = self.kb
        try:
            await kb.ensure_ready()
            if lock:
                async with kb.lock(record_id):
                    result = await self._delete_primitives(record_id)
            else:
                result = await self._delete_primitives(record_id)
        except AppError as exc:
            return DeleteItemResult(id=record_id, status="failed", error=exc.message)
        except Exception:
            logger.exception("content.delete_crashed id=%s", record_id)
            return DeleteItemResult(
                id=record_id, status="failed", error="Internal error (see server log)"
            )
        if files:
            try:
                self.downloads.remove(record_id)
            except Exception:  # noqa: BLE001
                logger.exception("content.remove_local_failed id=%s", record_id)
        return result

    async def _delete_primitives(self, record_id: str) -> DeleteItemResult:
        # Qdrant first: if it fails the text DB is untouched and a retry is safe.
        await self.kb.vectorstore.delete_record(record_id)
        removed = await self.kb.repo.delete(record_id)
        return DeleteItemResult(
            id=record_id,
            status="not_found" if removed is None else "deleted",
            chunks_removed=removed or 0,
        )

    async def delete(self, ids: list[str], *, files: bool) -> list[DeleteItemResult]:
        out: list[DeleteItemResult] = []
        for rid in ids:
            out.append(await self._delete_one(rid, files=files))
            logger.info("content.delete_done id=%s status=%s", rid, out[-1].status)
        return out
