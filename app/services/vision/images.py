"""Prepares a chat image for the AI: at most IMAGE_MAX_KB, sent inline as a data URL.

A file that is already small enough (and not a GIF) goes unchanged. Anything else is re-encoded as
JPEG: EXIF rotation applied, transparency flattened on white, quality lowered step by step and the
longest side reduced until the size fits. Pillow does the work (in a thread: CPU-bound).
If Pillow is missing a small image still works; a too-large one is skipped (warning in the log).
"""

from __future__ import annotations

import asyncio
import base64
import io
import logging
from dataclasses import dataclass
from pathlib import Path

logger = logging.getLogger(__name__)

_MIME = {"jpg": "image/jpeg", "jpeg": "image/jpeg", "png": "image/png", "webp": "image/webp",
         "gif": "image/gif"}  # fmt: skip
_QUALITIES = (85, 75, 65, 55, 45)
_START_SIDE = 2048
_MIN_SIDE = 320


@dataclass(frozen=True)
class PreparedImage:
    mime: str
    data: bytes

    @property
    def data_url(self) -> str:
        return f"data:{self.mime};base64,{base64.b64encode(self.data).decode('ascii')}"


def _shrink(raw: bytes, limit: int) -> bytes | None:
    try:
        from PIL import Image, ImageOps
    except ImportError:
        logger.warning("vision.pillow_missing (pip install Pillow) - large image skipped")
        return None
    try:
        with Image.open(io.BytesIO(raw)) as src:
            img = ImageOps.exif_transpose(src)
            img.load()
    except Exception:  # noqa: BLE001 - corrupt/unsupported image
        logger.warning("vision.image_unreadable")
        return None
    if img.mode in ("RGBA", "LA", "P"):
        rgba = img.convert("RGBA")
        base = Image.new("RGB", rgba.size, (255, 255, 255))
        base.paste(rgba, mask=rgba.split()[-1])
        img = base
    elif img.mode != "RGB":
        img = img.convert("RGB")

    side = min(max(img.size), _START_SIDE)
    best: bytes | None = None
    while True:
        work = img.copy()
        work.thumbnail((side, side), Image.LANCZOS)
        for quality in _QUALITIES:
            buf = io.BytesIO()
            work.save(buf, "JPEG", quality=quality, optimize=True)
            best = buf.getvalue()
            if len(best) <= limit:
                return best
        if side <= _MIN_SIDE:
            break
        side = max(_MIN_SIDE, int(side * 0.8))
    logger.warning("vision.image_still_large bytes=%d limit=%d", len(best or b""), limit)
    return None


def _prepare(path: Path, limit: int) -> PreparedImage | None:
    raw = path.read_bytes()
    ext = path.suffix.lower().lstrip(".")
    mime = _MIME.get(ext)
    if mime and ext != "gif" and len(raw) <= limit:
        return PreparedImage(mime, raw)
    shrunk = _shrink(raw, limit)
    if shrunk is not None:
        logger.info("vision.image_reduced from=%d to=%d", len(raw), len(shrunk))
        return PreparedImage("image/jpeg", shrunk)
    return None  # never send more than the limit


async def prepare_image(path: Path, max_kb: int) -> PreparedImage | None:
    """Image file -> PreparedImage (<= max_kb when possible), None when it cannot be used."""
    try:
        return await asyncio.to_thread(_prepare, path, max_kb * 1024)
    except OSError:
        logger.warning("vision.image_read_failed")
        return None
