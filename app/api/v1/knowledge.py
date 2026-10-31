"""Knowledge base endpoints.

POST / PUT / DELETE only VALIDATE and QUEUE the work and answer 200 at once ("got it"); the
ingestion worker does chunking/embedding/Qdrant later and the plugin is called back
(`callback` in the request) when a record is finished. GET endpoints are read-only inspection.
"""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, Path, Query, Request

from app.core.errors import NotFoundError
from app.schemas.common import ErrorResponse
from app.schemas.ingest import EnqueueResponse
from app.schemas.knowledge import (
    ChunkOut,
    DeleteRequest,
    RecordDetailResponse,
    RecordListResponse,
    RecordSummary,
    UpsertRequest,
)
from app.services.ingestion import IngestQueueService, KnowledgeService

router = APIRouter(prefix="/knowledge", tags=["knowledge"])

_RESPONSES = {
    401: {"model": ErrorResponse},
    403: {"model": ErrorResponse},
    503: {"model": ErrorResponse},
}


def get_knowledge_service(request: Request) -> KnowledgeService:
    """Built lazily on first use (tests inject their own via create_app)."""
    service = getattr(request.app.state, "knowledge_service", None)
    if service is None:
        service = KnowledgeService.from_settings(request.app.state.settings)
        request.app.state.knowledge_service = service
    return service


def get_ingest_queue(request: Request) -> IngestQueueService:
    """Built lazily on first use (tests inject their own via create_app)."""
    queue = getattr(request.app.state, "ingest_queue", None)
    if queue is None:
        queue = IngestQueueService.from_settings(request.app.state.settings)
        request.app.state.ingest_queue = queue
    return queue


Queue = Annotated[IngestQueueService, Depends(get_ingest_queue)]

_QUEUE_RESPONSES = {**_RESPONSES, 422: {"model": ErrorResponse}}


@router.post(
    "/records",
    response_model=EnqueueResponse,
    responses=_QUEUE_RESPONSES,
    summary="Queue records for processing (chunk -> embed -> Qdrant + text DB); 200 = accepted",
)
async def upsert_records(body: UpsertRequest, queue: Queue) -> EnqueueResponse:
    return await queue.enqueue_records(
        body.records, replace=False, force=body.force, callback=body.callback
    )


@router.put(
    "/records",
    response_model=EnqueueResponse,
    responses=_QUEUE_RESPONSES,
    summary="Queue records for replacement: the worker deletes each one completely, then adds it",
)
async def update_records(body: UpsertRequest, queue: Queue) -> EnqueueResponse:
    return await queue.enqueue_records(
        body.records, replace=True, force=body.force, callback=body.callback
    )


@router.delete(
    "/records",
    response_model=EnqueueResponse,
    responses=_QUEUE_RESPONSES,
    summary="Queue complete deletion (text DB rows + Qdrant vectors); body: {ids: [...]}",
)
async def delete_records(body: DeleteRequest, queue: Queue) -> EnqueueResponse:
    return await queue.enqueue_deletes(body.ids, callback=body.callback)


def _summary(rec) -> RecordSummary:
    return RecordSummary(
        id=rec.record_id,
        kind=rec.kind,
        type=rec.type,
        title=rec.title,
        url=rec.url,
        chunk_count=rec.chunk_count,
        token_count=rec.token_count,
        updated_at=rec.updated_at.isoformat(),
    )


@router.get(
    "/records",
    response_model=RecordListResponse,
    responses=_RESPONSES,
    summary="List stored records (debug/inspection)",
)
async def list_records(
    service: Annotated[KnowledgeService, Depends(get_knowledge_service)],
    limit: Annotated[int, Query(ge=1, le=500)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
    kind: Annotated[str | None, Query(max_length=40)] = None,
) -> RecordListResponse:
    await service.ensure_ready()
    total, rows = await service.repo.list_records(limit=limit, offset=offset, kind=kind)
    return RecordListResponse(
        total=total, limit=limit, offset=offset, items=[_summary(r) for r in rows]
    )


@router.get(
    "/records/{record_id}",
    response_model=RecordDetailResponse,
    responses={**_RESPONSES, 404: {"model": ErrorResponse}},
    summary="One record: raw data, chunks (text DB) and its Qdrant points (debug/inspection)",
)
async def get_record(
    record_id: Annotated[str, Path(pattern=r"^[A-Za-z0-9_]{1,40}:[A-Za-z0-9_.\-]{1,64}$")],
    service: Annotated[KnowledgeService, Depends(get_knowledge_service)],
) -> RecordDetailResponse:
    await service.ensure_ready()
    found = await service.repo.get_record(record_id)
    if found is None:
        raise NotFoundError(f"Record {record_id!r} not found")
    rec, chunks = found
    points = await service.vectorstore.list_record_points(record_id)
    in_sync = {c.chunk_id for c in chunks} == {p.get("chunk_id") for p in points}
    return RecordDetailResponse(
        record=_summary(rec),
        data=rec.data,
        chunks=[
            ChunkOut(chunk_id=c.chunk_id, index=c.index, tokens=c.tokens, hash=c.hash, text=c.text)
            for c in chunks
        ],
        qdrant_points=points,
        in_sync=in_sync,
    )
