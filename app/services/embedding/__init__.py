"""Embeddings (OpenAI-compatible API)."""

from app.services.embedding.openai_embedder import Embedder, EmbeddingResult, OpenAIEmbedder

__all__ = ["Embedder", "EmbeddingResult", "OpenAIEmbedder"]
