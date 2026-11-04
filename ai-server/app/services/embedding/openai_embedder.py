"""text-embedding-3-large via the OpenAI SDK, batched, with usage logging."""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from typing import Protocol

import openai
from openai import AsyncOpenAI

from app.core.config import Settings
from app.core.errors import ServiceUnavailableError, UpstreamError
from app.core.monitoring import emit, op_var

logger = logging.getLogger(__name__)


@dataclass
class EmbeddingResult:
    vectors: list[list[float]]
    prompt_tokens: int = 0
    total_tokens: int = 0
    requests: int = 0
    extra: dict = field(default_factory=dict)


class Embedder(Protocol):
    model: str
    dimensions: int

    async def embed(self, texts: list[str]) -> EmbeddingResult: ...

    async def close(self) -> None: ...


class OpenAIEmbedder:
    def __init__(self, settings: Settings):
        self.model = settings.EMBEDDING_MODEL
        self.dimensions = settings.EMBEDDING_DIMENSIONS
        self._batch = settings.EMBEDDING_BATCH_SIZE
        self._key = settings.OPENAI_API_KEY.get_secret_value()
        self._base_url = settings.OPENAI_BASE_URL or None
        self._timeout = settings.EMBEDDING_TIMEOUT
        self._retries = settings.EMBEDDING_MAX_RETRIES
        self._client: AsyncOpenAI | None = None

    def _get_client(self) -> AsyncOpenAI:
        if not self._key:
            raise ServiceUnavailableError("OPENAI_API_KEY is not configured on the server")
        if self._client is None:
            self._client = AsyncOpenAI(
                api_key=self._key,
                base_url=self._base_url,
                timeout=self._timeout,
                max_retries=self._retries,
            )
        return self._client

    async def embed(self, texts: list[str]) -> EmbeddingResult:
        result = EmbeddingResult(vectors=[])
        if not texts:
            return result
        client = self._get_client()
        for start in range(0, len(texts), self._batch):
            batch = texts[start : start + self._batch]
            t0 = time.perf_counter()
            try:
                response = await client.embeddings.create(
                    model=self.model, input=batch, dimensions=self.dimensions
                )
            except openai.OpenAIError as exc:
                emit(
                    "embedding.request", model=self.model, op=op_var.get(), status="error",
                    error_type=type(exc).__name__, inputs=len(batch),
                    http_status=getattr(exc, "status_code", None),
                    ms=(time.perf_counter() - t0) * 1000,
                )  # fmt: skip
                logger.error(
                    "embedding.request_failed batch=%d inputs=%d error=%s: %s",
                    start // self._batch + 1,
                    len(batch),
                    type(exc).__name__,
                    exc,
                )
                raise UpstreamError(f"Embedding request failed ({type(exc).__name__})") from exc
            ordered = sorted(response.data, key=lambda d: d.index)
            vectors = [list(d.embedding) for d in ordered]
            if len(vectors) != len(batch) or any(len(v) != self.dimensions for v in vectors):
                raise UpstreamError(
                    "Embedding API returned an unexpected number/size of vectors "
                    f"(expected {len(batch)}x{self.dimensions})"
                )
            usage = response.usage
            prompt = getattr(usage, "prompt_tokens", 0) or 0
            total = getattr(usage, "total_tokens", 0) or 0
            result.vectors.extend(vectors)
            result.prompt_tokens += prompt
            result.total_tokens += total
            result.requests += 1
            emit(
                "embedding.request", model=self.model, op=op_var.get(), status="ok",
                inputs=len(batch), prompt_tokens=prompt, total_tokens=total,
                ms=(time.perf_counter() - t0) * 1000,
            )  # fmt: skip
            logger.info(
                "embedding.request model=%s inputs=%d prompt_tokens=%d total_tokens=%d ms=%d",
                self.model,
                len(batch),
                prompt,
                total,
                (time.perf_counter() - t0) * 1000,
            )
        return result

    async def close(self) -> None:
        if self._client is not None:
            await self._client.close()
            self._client = None
