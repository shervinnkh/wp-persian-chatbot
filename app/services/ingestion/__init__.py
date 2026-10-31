"""Knowledge ingestion (records -> chunks -> embeddings -> Qdrant + text DB)."""

from app.services.ingestion.content import ContentService
from app.services.ingestion.service import KnowledgeService

__all__ = ["ContentService", "KnowledgeService"]
