"""Qdrant access: one collection; each chunk is a point; deletion is by `record_id` filter."""

from __future__ import annotations

import logging
import uuid
from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from typing import Any, TypeVar

from qdrant_client import AsyncQdrantClient, models
from qdrant_client.http.exceptions import ApiException, ResponseHandlingException

from app.core.config import Settings
from app.core.errors import ServiceUnavailableError

logger = logging.getLogger(__name__)
T = TypeVar("T")

_POINT_NAMESPACE = uuid.UUID("6f1c5b9e-3a1d-4c8e-9a52-7d0e2b4a1f10")
_UPSERT_BATCH = 64


def point_id(chunk_id: str) -> str:
    """Deterministic Qdrant point id for a chunk (re-upserting the same chunk overwrites it)."""
    return str(uuid.uuid5(_POINT_NAMESPACE, chunk_id))


@dataclass(frozen=True)
class VectorPoint:
    chunk_id: str
    vector: list[float]
    payload: dict[str, Any]


class VectorStore:
    def __init__(self, settings: Settings):
        self.collection = settings.QDRANT_COLLECTION
        self.dimensions = settings.EMBEDDING_DIMENSIONS
        self._quantize = settings.QDRANT_SCALAR_QUANTIZATION
        url = settings.QDRANT_URL
        path = settings.qdrant_path
        if path is not None:
            path.mkdir(parents=True, exist_ok=True)
            self._client = AsyncQdrantClient(path=str(path))
            url = f"local:{path}"
            logger.info("Qdrant embedded local mode: path=%s (single process only)", path)
        elif url == ":memory:":
            self._client = AsyncQdrantClient(location=":memory:")
        else:
            key = settings.QDRANT_API_KEY.get_secret_value() or None
            self._client = AsyncQdrantClient(url=url, api_key=key, timeout=settings.QDRANT_TIMEOUT)
        self._url = url
        self._ready = False

    async def _call(self, what: str, fn: Callable[[], Awaitable[T]]) -> T:
        try:
            return await fn()
        except (ApiException, ResponseHandlingException, OSError) as exc:
            logger.error("qdrant.%s failed: %s: %s", what, type(exc).__name__, exc)
            raise ServiceUnavailableError(f"Vector database error during {what}") from exc

    async def ensure_collection(self) -> None:
        if self._ready:
            return

        async def _ensure() -> None:
            if not await self._client.collection_exists(self.collection):
                await self._client.create_collection(
                    self.collection,
                    vectors_config=models.VectorParams(
                        size=self.dimensions, distance=models.Distance.COSINE
                    ),
                    # QDRANT_SCALAR_QUANTIZATION (default off): only for NEW collections.
                    quantization_config=(
                        models.ScalarQuantization(
                            scalar=models.ScalarQuantizationConfig(
                                type=models.ScalarType.INT8, quantile=0.99, always_ram=True
                            )
                        )
                        if self._quantize
                        else None
                    ),
                )
                logger.info(
                    "qdrant.collection_created name=%s dims=%d distance=cosine quantization=%s",
                    self.collection,
                    self.dimensions,
                    "int8" if self._quantize else "off",
                )
            else:
                info = await self._client.get_collection(self.collection)
                vectors = info.config.params.vectors
                size = getattr(vectors, "size", None)
                if size is not None and size != self.dimensions:
                    raise ServiceUnavailableError(
                        f"Qdrant collection {self.collection!r} has vector size {size} but "
                        f"EMBEDDING_DIMENSIONS={self.dimensions}. Use another QDRANT_COLLECTION "
                        "or recreate the collection."
                    )
            for field, schema in (
                ("record_id", models.PayloadSchemaType.KEYWORD),
                ("type", models.PayloadSchemaType.KEYWORD),
                ("chunk_index", models.PayloadSchemaType.INTEGER),
            ):
                await self._client.create_payload_index(self.collection, field, schema)

        await self._call("ensure_collection", _ensure)
        self._ready = True

    async def upsert(self, points: list[VectorPoint]) -> None:
        for start in range(0, len(points), _UPSERT_BATCH):
            batch = points[start : start + _UPSERT_BATCH]
            structs = [
                models.PointStruct(id=point_id(p.chunk_id), vector=p.vector, payload=p.payload)
                for p in batch
            ]
            await self._call(
                "upsert",
                lambda structs=structs: self._client.upsert(
                    self.collection, points=structs, wait=True
                ),
            )

    async def search(
        self, vector: list[float], *, limit: int, min_score: float | None = None
    ) -> list[tuple[str, float]]:
        """Nearest chunks (cosine) as `(chunk_id, score)`, best first."""
        await self.ensure_collection()
        response = await self._call(
            "search",
            lambda: self._client.query_points(
                self.collection,
                query=vector,
                limit=limit,
                score_threshold=min_score or None,
                with_payload=["chunk_id"],
                with_vectors=False,
            ),
        )
        out: list[tuple[str, float]] = []
        for point in response.points:
            chunk_id = (point.payload or {}).get("chunk_id")
            if chunk_id:
                out.append((str(chunk_id), float(point.score)))
        return out

    async def delete_stale(self, record_id: str, keep_count: int) -> None:
        """Remove points of `record_id` whose chunk_index >= keep_count (record got shorter)."""
        flt = models.Filter(
            must=[
                models.FieldCondition(key="record_id", match=models.MatchValue(value=record_id)),
                models.FieldCondition(key="chunk_index", range=models.Range(gte=keep_count)),
            ]
        )
        await self._call(
            "delete_stale",
            lambda: self._client.delete(
                self.collection, points_selector=models.FilterSelector(filter=flt), wait=True
            ),
        )

    async def delete_record(self, record_id: str) -> None:
        flt = models.Filter(
            must=[models.FieldCondition(key="record_id", match=models.MatchValue(value=record_id))]
        )
        await self._call(
            "delete_record",
            lambda: self._client.delete(
                self.collection, points_selector=models.FilterSelector(filter=flt), wait=True
            ),
        )

    async def list_record_points(self, record_id: str) -> list[dict[str, Any]]:
        """Payloads of all points of a record (vectors omitted) - for debugging/inspection."""
        flt = models.Filter(
            must=[models.FieldCondition(key="record_id", match=models.MatchValue(value=record_id))]
        )
        points, _ = await self._call(
            "scroll",
            lambda: self._client.scroll(
                self.collection,
                scroll_filter=flt,
                limit=1000,
                with_payload=True,
                with_vectors=False,
            ),
        )
        out = [{"point_id": str(p.id), **(p.payload or {})} for p in points]
        return sorted(out, key=lambda d: d.get("chunk_index", 0))

    async def count_record(self, record_id: str) -> int:
        flt = models.Filter(
            must=[models.FieldCondition(key="record_id", match=models.MatchValue(value=record_id))]
        )
        result = await self._call(
            "count",
            lambda: self._client.count(self.collection, count_filter=flt, exact=True),
        )
        return result.count

    async def close(self) -> None:
        await self._client.close()
