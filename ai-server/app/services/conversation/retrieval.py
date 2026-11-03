"""RAG retrieval for one visitor question.

    question -> normalize_text -> embedding (same model as the records) -> Qdrant cosine top-K
             -> chunk ids -> text DB (chunk text + record title/url) -> `RetrievedHit`s

The caller puts the hits into the system prompt (see prompt.py).
"""

from __future__ import annotations

import logging
import re
import time
from dataclasses import dataclass

from app.db.repository import KnowledgeRepository, RetrievedChunk
from app.services.chunking.normalize import normalize_text
from app.services.conversation.history import PreparedHistory
from app.services.embedding.openai_embedder import Embedder
from app.services.vectorstore.qdrant import VectorStore

logger = logging.getLogger(__name__)

_SHORT_QUERY_CHARS = 25  # shorter questions ("قیمتش؟") are searched together with the one before
_MAX_QUERY_CHARS = 2000
_PLACEHOLDERS = re.compile(r"\[(?:voice message|image)\]")


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
    ):
        self.embedder = embedder
        self.vectorstore = vectorstore
        self.repo = repo
        self.top_k = top_k
        self.min_score = min_score

    async def search(self, query: str) -> list[RetrievedHit]:
        text = normalize_text(query)[:_MAX_QUERY_CHARS]  # same normalization as the records
        if not text:
            return []
        t0 = time.perf_counter()
        embedded = await self.embedder.embed([text])
        found = await self.vectorstore.search(
            embedded.vectors[0], limit=self.top_k, min_score=self.min_score
        )
        chunks = await self.repo.get_chunks([cid for cid, _ in found])
        by_id = {c.chunk_id: c for c in chunks}
        hits = [RetrievedHit(by_id[cid], score) for cid, score in found if cid in by_id]
        logger.info(
            "rag.retrieved found=%d loaded=%d scores=%s ids=%s ms=%d",
            len(found), len(hits), [round(h.score, 3) for h in hits],
            [h.chunk.chunk_id for h in hits], (time.perf_counter() - t0) * 1000,
        )  # fmt: skip
        if len(hits) < len(found):
            logger.warning("rag.out_of_sync missing_in_db=%d", len(found) - len(hits))
        return hits
