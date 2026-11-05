"""RAG retrieval for one visitor question.

    question -> normalize_text -> embedding (same model as the records) -> Qdrant cosine top-K
             -> chunk ids -> text DB (chunk text + record title/url) -> `RetrievedHit`s

The caller puts the hits into the system prompt (see prompt.py).
"""

from __future__ import annotations

import hashlib
import logging
import re
import time
from array import array
from collections import OrderedDict
from dataclasses import dataclass

from app.core.monitoring import emit, op_var
from app.db.repository import KnowledgeRepository, RetrievedChunk
from app.services.chunking.normalize import normalize_text
from app.services.conversation.history import PreparedHistory
from app.services.embedding.openai_embedder import Embedder
from app.services.vectorstore.qdrant import VectorStore

logger = logging.getLogger(__name__)

_SHORT_QUERY_CHARS = 25  # shorter questions ("قیمتش؟") are searched together with the one before
_MAX_QUERY_CHARS = 2000
_PLACEHOLDERS = re.compile(r"\[(?:voice message|image)\]")


class QueryEmbeddingCache:
    """Tiny LRU of question embeddings: sha256(normalized question) -> vector.

    Vectors are kept as `array('d')` (8 bytes per number, ~24 KB for 3072 dims) instead of a list
    of Python floats (~100 KB), so 500 entries stay around 12 MB. Single event loop, so no lock.
    """

    def __init__(self, max_items: int, *, namespace: str = ""):
        self.max_items = max(0, int(max_items))
        self._ns = namespace  # model + dimensions: a different model never shares a vector
        self._items: OrderedDict[str, array] = OrderedDict()
        self.hits = 0
        self.misses = 0

    def key(self, text: str) -> str:
        return hashlib.sha256(f"{self._ns}\n{text}".encode("utf-8")).hexdigest()

    def get(self, key: str) -> list[float] | None:
        vec = self._items.get(key)
        if vec is None:
            self.misses += 1
            return None
        self._items.move_to_end(key)
        self.hits += 1
        return vec.tolist()

    def put(self, key: str, vector: list[float]) -> None:
        if self.max_items <= 0:
            return
        self._items[key] = array("d", vector)
        self._items.move_to_end(key)
        while len(self._items) > self.max_items:
            self._items.popitem(last=False)

    def __len__(self) -> int:
        return len(self._items)


@dataclass(frozen=True)
class RetrievedHit:
    chunk: RetrievedChunk
    score: float


def _plain(text: str) -> str:
    return " ".join(_PLACEHOLDERS.sub(" ", text or "").split())


def build_search_query(history: PreparedHistory) -> str:
    """Text to embed: the newest user message; if it is very short (a follow-up such as "and
    its size?"), the previous user message is prepended so the search still knows the topic.
    A message that is only a voice/image tag gives "" (nothing to search)."""
    if history.question is None:
        return ""
    current = _plain(history.question.text)
    if not current:
        return ""
    if len(current) < _SHORT_QUERY_CHARS:
        for m in reversed(history.recent):
            if m.role == "user" and (previous := _plain(m.text)):
                return f"{previous}\n{current}"
    return current


class KnowledgeRetriever:
    def __init__(
        self,
        *,
        embedder: Embedder,
        vectorstore: VectorStore,
        repo: KnowledgeRepository,
        top_k: int,
        min_score: float = 0.0,
        cache_size: int = 0,
    ):
        self.embedder = embedder
        self.vectorstore = vectorstore
        self.repo = repo
        self.top_k = top_k
        self.min_score = min_score
        self.cache = QueryEmbeddingCache(
            cache_size,
            namespace=f"{getattr(embedder, 'model', '')}:{getattr(embedder, 'dimensions', '')}",
        )

    async def _embed_query(self, text: str) -> tuple[list[float], bool]:
        """Vector of a visitor question -> (vector, served_from_cache)."""
        key = self.cache.key(text)
        cached = self.cache.get(key) if self.cache.max_items > 0 else None
        if cached is not None:
            return cached, True
        token = op_var.set("query")  # the embedding event says it was a visitor question
        try:
            embedded = await self.embedder.embed([text])
        finally:
            op_var.reset(token)
        vector = embedded.vectors[0]
        self.cache.put(key, vector)
        return vector, False

    async def search(self, query: str) -> list[RetrievedHit]:
        text = normalize_text(query)[:_MAX_QUERY_CHARS]  # same normalization as the records
        if not text:
            return []
        t0 = time.perf_counter()
        vector, from_cache = await self._embed_query(text)
        found = await self.vectorstore.search(vector, limit=self.top_k, min_score=self.min_score)
        chunks = await self.repo.get_chunks([cid for cid, _ in found])
        by_id = {c.chunk_id: c for c in chunks}
        hits = [RetrievedHit(by_id[cid], score) for cid, score in found if cid in by_id]
        logger.info(
            "rag.retrieved found=%d loaded=%d scores=%s ids=%s embed_cached=%s ms=%d",
            len(found), len(hits), [round(h.score, 3) for h in hits],
            [h.chunk.chunk_id for h in hits], from_cache, (time.perf_counter() - t0) * 1000,
        )  # fmt: skip
        emit(
            "rag.retrieval", query_chars=len(text), top_k=self.top_k, found=len(found),
            loaded=len(hits), missing=len(found) - len(hits),
            top_score=max((h.score for h in hits), default=None),
            scores=[round(h.score, 3) for h in hits],
            record_ids=[h.chunk.record_id for h in hits if h.chunk.record_id],
            embed_cached=from_cache,
            ms=(time.perf_counter() - t0) * 1000,
        )  # fmt: skip
        if len(hits) < len(found):
            logger.warning("rag.out_of_sync missing_in_db=%d", len(found) - len(hits))
        return hits
