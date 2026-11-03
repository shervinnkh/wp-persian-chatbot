"""Texts (`text:*`) and files (`file:*`) for the ingestion worker.

Called by `IngestProcessor` for every job; other record kinds go straight to `KnowledgeService`.

  text : the plugin's chunks (`data.chunks`) are kept as they are; only a chunk larger than
         CHUNK_MAX_TOKENS is split again (doc_chunker.rechunk_text_chunks).
  file : `data.download.url` (short-lived signed link made by the plugin) -> download (SSRF + quota
         guarded, files/download.py) -> extractor (pdf/docx/txt) -> balanced document chunker.

Both finish through `KnowledgeService.store_prebuilt` (same lock, same write order, same stores as
every other record). The signed link is never stored: it is removed from `records.data` and from
the change hash. Delete = `KnowledgeService.delete_many` + removal of the downloaded file.
"""

from __future__ import annotations

import asyncio
import logging
import time
from collections.abc import Awaitable, Callable
from typing import Any

from app.core.config import Settings
from app.core.errors import AppError, FileProcessingError
from app.schemas.knowledge import DeleteItemResult, UpsertItemResult
from app.services.chunking.doc_chunker import (
    CHUNKER_VERSION,
    BuiltChunk,
    chunk_document,
    rechunk_text_chunks,
)
from app.services.chunking.pipeline import data_hash
from app.services.chunking.render import text_pieces, text_title
from app.services.extractors.loader import extract_document_sync
from app.services.files.download import DownloadManager, pick_extension
from app.services.ingestion.service import RETRYABLE_CODES, KnowledgeService

logger = logging.getLogger(__name__)

LockedSite = Callable[[], Awaitable[str | None]]


def _tuples(chunks: list[BuiltChunk]) -> list[tuple[str, int, str]]:
    return [(c.text, c.tokens, c.hash) for c in chunks]


class ContentService:
    def __init__(
        self,
        knowledge: KnowledgeService,
        settings: Settings | None = None,
        *,
        locked_site: LockedSite | None = None,
    ) -> None:
        self.kb = knowledge
        self.settings = settings or knowledge.settings
        self._locked_site = locked_site
        self._downloads: DownloadManager | None = None
        self.pipeline = knowledge.pipeline_for(CHUNKER_VERSION)

    @property
    def downloads(self) -> DownloadManager:
        if self._downloads is None:  # created on first use (makes the uploads folder)
            self._downloads = DownloadManager(self.settings)
        return self._downloads

    # ------------------------------------------------------------------ public
    async def upsert(
        self, record_id: str, data: dict[str, Any], *, force: bool
    ) -> UpsertItemResult:
        kind = record_id.split(":", 1)[0]
        if kind == "text":
            return await self._guard(record_id, lambda: self._upsert_text(record_id, data, force))
        if kind == "file":
            return await self._guard(record_id, lambda: self._upsert_file(record_id, data, force))
        return (await self.kb.upsert_many([(record_id, data)], force=force))[0]

    async def delete(self, record_id: str) -> DeleteItemResult:
        result = (await self.kb.delete_many([record_id]))[0]
        if record_id.startswith("file:") and result.status != "failed":
            try:
                self.downloads.remove(record_id)
            except Exception:  # noqa: BLE001 - the index is already clean; the file is just space
                logger.exception("content.remove_local_failed id=%s", record_id)
        return result

    def storage_usage(self) -> dict:
        return self.downloads.usage()

    # ------------------------------------------------------------------ helpers
    async def _guard(
        self, record_id: str, fn: Callable[[], Awaitable[UpsertItemResult]]
    ) -> UpsertItemResult:
        t0 = time.perf_counter()
        try:
            await self.kb.ensure_ready()
            async with self.kb.lock(record_id):
                result = await fn()
        except FileProcessingError as exc:
            logger.warning("content.failed id=%s code=%s msg=%s", record_id, exc.code, exc.message)
            result = UpsertItemResult(
                id=record_id,
                status="failed",
                error=exc.message,
                error_code=exc.code,
                retryable=exc.retryable,
            )
        except AppError as exc:
            logger.warning("content.failed id=%s code=%s msg=%s", record_id, exc.code, exc.message)
            result = UpsertItemResult(
                id=record_id,
                status="failed",
                error=exc.message,
                error_code=exc.code,
                retryable=exc.code in RETRYABLE_CODES,
            )
        except Exception:
            logger.exception("content.crashed id=%s", record_id)
            result = UpsertItemResult(
                id=record_id,
                status="failed",
                error="Internal error (see server log)",
                error_code="internal_error",
                retryable=True,
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
    async def _upsert_text(self, record_id: str, data: dict, force: bool) -> UpsertItemResult:
        pieces = text_pieces(data)
        if not pieces:
            raise FileProcessingError("unprocessable_record", "Text record has no chunks or text.")
        digest = data_hash(data)
        state, same = await self._unchanged(record_id, digest, force)
        if same is not None:
            return same
        chunks = await asyncio.to_thread(
            rechunk_text_chunks, pieces, counter=self.kb.counter, cfg=self.kb.chunk_cfg
        )
        if not chunks:
            raise FileProcessingError("unprocessable_record", "Text record has no usable text.")
        return await self.kb.store_prebuilt(
            record_id,
            record_type="text",
            title=text_title(pieces),
            url=None,
            data=data,
            digest=digest,
            chunks=_tuples(chunks),
            pipeline=self.pipeline,
            force=force,
            state=state,
        )

    # ------------------------------------------------------------------ files
    async def _upsert_file(self, record_id: str, data: dict, force: bool) -> UpsertItemResult:
        download = data.get("download") if isinstance(data.get("download"), dict) else {}
        meta = {k: v for k, v in data.items() if k != "download"}  # never store the signed link
        original = str(meta.get("original_name") or "")
        ext = pick_extension(original, str(meta.get("ext") or ""))
        title = (str(meta.get("title") or "").strip() or original.strip() or record_id)[:200]
        declared_sha = str(meta.get("sha256") or "").lower()
        declared_size = int(meta["size"]) if str(meta.get("size") or "").isdigit() else None

        def digest_of(sha: str, size: int | None) -> str:
            return data_hash(
                {"title": title, "original_name": original, "ext": ext, "size": size, "sha256": sha}
            )

        # Same file, same title as already indexed: nothing to download.
        if declared_sha:
            state, same = await self._unchanged(
                record_id, digest_of(declared_sha, declared_size), force
            )
            if same is not None:
                return same

        url = str(download.get("url") or "")
        if not url:
            raise FileProcessingError(
                "missing_download_link", "The file record has no download.url (signed link)."
            )
        try:
            locked = await self._locked_site() if self._locked_site else None
            dl = await self.downloads.download(
                record_id,
                url,
                ext=ext,
                declared_size=declared_size,
                locked_site=locked,
                expires=int(download["expires"])
                if str(download.get("expires") or "").isdigit()
                else None,
            )
            if declared_sha and dl.sha256 != declared_sha:
                raise FileProcessingError(
                    "checksum_mismatch",
                    "Downloaded file differs from the file the plugin described.",
                )
            digest = digest_of(dl.sha256, dl.size)
            state, same = await self._unchanged(record_id, digest, force)
            if same is not None:
                if not self.settings.FILES_KEEP_ORIGINAL:
                    self.downloads.remove(record_id)
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

            keep = self.settings.FILES_KEEP_ORIGINAL
            raw: dict[str, Any] = {
                "type": "file",
                "title": title,
                "original_name": original,
                "ext": dl.ext.lstrip("."),
                "size_bytes": dl.size,
                "sha256": dl.sha256,
                "stored_path": self.downloads.relative(dl.local_path) if keep else None,
                "extraction": doc.meta,
                "warnings": doc.warnings,
                "chars": doc.chars,
            }
            result = await self.kb.store_prebuilt(
                record_id,
                record_type="file",
                title=title,
                url=None,
                data=raw,
                digest=digest,
                chunks=_tuples(chunks),
                pipeline=self.pipeline,
                force=force,
                state=state,
            )
        except BaseException:
            self.downloads.remove(record_id)  # never leave an orphan file behind
            raise
        if not keep:
            self.downloads.remove(record_id)
        return result
