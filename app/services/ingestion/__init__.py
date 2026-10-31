"""Knowledge ingestion (records -> chunks -> embeddings -> Qdrant + text DB), queued + callbacks."""

from app.services.ingestion.queue import IngestQueueService
from app.services.ingestion.service import KnowledgeService

__all__ = ["IngestQueueService", "KnowledgeService"]
