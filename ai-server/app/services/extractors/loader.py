"""فراخوانی استخراج‌گرهای pdf / txt / docx و تبدیل خروجی JSON آن‌ها به `DocBlock`.

خروجی `common.build_result`: `{schema_version, source, extractor, language, text_order, stats,
warnings, paragraphs[{index,type,level?,page?,text,line_range}], lines, full_text, markdown, ...}`.
چون استخراج‌گرها `from common import ...` دارند، همین پوشه موقتاً به sys.path اضافه می‌شود
(فایل‌های استخراج‌گر بدون تغییر هم‌چنان به‌صورت CLI مستقل کار می‌کنند).
"""

from __future__ import annotations

import logging
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from app.core.errors import FileProcessingError
from app.services.chunking.doc_chunker import DocBlock

logger = logging.getLogger(__name__)

_DIR = Path(__file__).resolve().parent
_KINDS = {"heading", "paragraph", "list_item", "table", "footnote"}
_META_KEYS = ("encoding", "extractor", "language", "stats", "text_order")


@dataclass
class ExtractedDocument:
    blocks: list[DocBlock]
    meta: dict[str, Any] = field(default_factory=dict)
    warnings: list[Any] = field(default_factory=list)
    chars: int = 0


def _ensure_path() -> None:
    p = str(_DIR)
    if p not in sys.path:
        sys.path.insert(0, p)


def _blocks_from_result(result: dict[str, Any]) -> list[DocBlock]:
    paragraphs = result.get("paragraphs")
    if not isinstance(paragraphs, list):
        raise FileProcessingError(
            "unexpected_extractor_output",
            f"Extractor output has no 'paragraphs' (keys: {sorted(result)[:12]}).",
        )
    blocks: list[DocBlock] = []
    for p in paragraphs:
        if not isinstance(p, dict):
            continue
        text = p.get("text")
        if not isinstance(text, str) or not text.strip():
            continue
        kind = str(p.get("type") or "paragraph")
        if kind not in _KINDS:
            kind = "paragraph"
        level = p.get("level")
        blocks.append(DocBlock(kind, text, level if isinstance(level, int) and level > 0 else 1))
    return blocks


def extract_document_sync(path: Path, ext: str) -> ExtractedDocument:
    """بلاک‌کننده و CPU-سنگین؛ باید داخل thread صدا زده شود (`asyncio.to_thread`)."""
    _ensure_path()
    try:
        if ext == ".pdf":
            from pdf_extractor import extract_pdf as fn  # type: ignore[import-not-found]
        elif ext == ".docx":
            from docs_extractor import extract_docx as fn  # type: ignore[import-not-found]
        elif ext == ".txt":
            from txt_extractor import extract_txt as fn  # type: ignore[import-not-found]
        else:
            raise FileProcessingError("unsupported_extension", f"Unsupported extension {ext}.")
    except ImportError as exc:
        raise FileProcessingError(
            "extractor_unavailable",
            f"Extractor for {ext} cannot be imported ({exc}). Run: run.bat install",
        ) from exc

    try:
        result = fn(path)
    except FileProcessingError:
        raise
    except Exception as exc:  # noqa: BLE001 - ExtractionError (code/message) and anything else
        code = getattr(exc, "code", None) or "extraction_failed"
        message = getattr(exc, "message", None) or str(exc) or "Extraction failed."
        raise FileProcessingError(str(code), str(message)) from exc

    blocks = _blocks_from_result(result)
    chars = sum(len(b.text) for b in blocks)
    if chars == 0:
        codes = [w.get("code") for w in result.get("warnings", []) if isinstance(w, dict)]
        raise FileProcessingError(
            "no_text_extracted",
            "No text could be extracted from the file"
            + (" (scanned PDF? OCR is not supported)." if "no_text_layer" in codes else "."),
        )
    warnings = result.get("warnings") if isinstance(result.get("warnings"), list) else []
    meta = {k: result[k] for k in _META_KEYS if k in result}
    logger.info(
        "files.extracted ext=%s blocks=%d chars=%d warnings=%d",
        ext,
        len(blocks),
        chars,
        len(warnings),
    )
    return ExtractedDocument(blocks=blocks, meta=meta, warnings=warnings, chars=chars)
