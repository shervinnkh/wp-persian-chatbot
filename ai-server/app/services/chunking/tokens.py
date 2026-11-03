"""Token counting with tiktoken (cl100k_base = the encoding of text-embedding-3-large)."""

from __future__ import annotations

import logging
import math
import os
from typing import Any

from app.core.config import Settings

logger = logging.getLogger(__name__)

# Used only when tiktoken cannot load its vocabulary (e.g. first run without internet).
# Persian text averages roughly 2.2-2.6 characters per cl100k token; we over-estimate a bit so
# chunks stay on the safe (smaller) side.
_APPROX_CHARS_PER_TOKEN = 2.2


class TokenCounter:
    def __init__(self, encoding: Any | None = None, name: str = "approx"):
        self._enc = encoding
        self.name = name

    @property
    def exact(self) -> bool:
        return self._enc is not None

    @classmethod
    def approximate(cls) -> TokenCounter:
        return cls(None, "approx")

    @classmethod
    def from_settings(cls, settings: Settings) -> TokenCounter:
        cache_dir = settings.tiktoken_cache_dir
        try:
            cache_dir.mkdir(parents=True, exist_ok=True)
            os.environ.setdefault("TIKTOKEN_CACHE_DIR", str(cache_dir))
            import tiktoken

            enc = tiktoken.get_encoding(settings.TIKTOKEN_ENCODING)
        except Exception as exc:  # noqa: BLE001 - any failure must degrade, not crash
            logger.warning(
                "tiktoken encoding %r could not be loaded (%s: %s). Falling back to an "
                "APPROXIMATE token counter (chars/%.1f); chunk sizes will be less exact. "
                "Fix: allow the server to reach openaipublic.blob.core.windows.net once, or "
                "place the cached vocabulary file in %s",
                settings.TIKTOKEN_ENCODING,
                type(exc).__name__,
                exc,
                _APPROX_CHARS_PER_TOKEN,
                cache_dir,
            )
            return cls.approximate()
        logger.info("Token counter ready: tiktoken encoding=%s", settings.TIKTOKEN_ENCODING)
        return cls(enc, settings.TIKTOKEN_ENCODING)

    def count(self, text: str) -> int:
        if not text:
            return 0
        if self._enc is not None:
            # encode_ordinary: special-token strings in user text are treated as plain text
            return len(self._enc.encode_ordinary(text))
        return math.ceil(len(text) / _APPROX_CHARS_PER_TOKEN)

    def hard_split(self, text: str, limit: int) -> list[str]:
        """Last resort for text with no usable separators: cut by characters until it fits."""
        pieces: list[str] = []
        rest = text
        while rest:
            piece = rest[: max(1, int(limit * 2))]
            while len(piece) > 1 and self.count(piece) > limit:
                piece = piece[: max(1, int(len(piece) * 0.8))]
            pieces.append(piece)
            rest = rest[len(piece) :]
        return pieces
