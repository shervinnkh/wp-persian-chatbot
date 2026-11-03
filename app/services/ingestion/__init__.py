"""Knowledge ingestion: records, texts, files -> chunks -> embeddings -> Qdrant + DB (queued)."""

from app.services.ingestion.content import ContentService
from app.services.ingestion.queue import IngestQueueService
from app.services.ingestion.service import KnowledgeService

__all__ = ["ContentService", "IngestQueueService", "KnowledgeService"]
