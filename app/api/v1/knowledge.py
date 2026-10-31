"""Knowledge base endpoints: batch upsert and batch delete of extracted WordPress records."""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, Path, Query, Request

from app.core.errors import NotFoundError
from app.schemas.common import ErrorResponse
from app.schemas.knowledge import (
    ChunkOut,
    DeleteRequest,
    DeleteResponse,
    RecordDetailResponse,
    RecordListResponse,
    RecordSummary,
    UpsertItemResult,
    UpsertRequest,
    UpsertResponse,
    UpsertSummary,
)
from app.services.ingestion import KnowledgeService

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


def _upsert_response(results: list[UpsertItemResult]) -> UpsertResponse:
    def count(status: str) -> int:
        return sum(1 for r in results if r.status == status)

    return UpsertResponse(
        confirmed_ids=[r.id for r in results if r.status != "failed"],
        failed_ids=[r.id for r in results if r.status == "failed"],
        summary=UpsertSummary(
            total=len(results),
            created=count("created"),
            updated=count("updated"),
            unchanged=count("unchanged"),
            failed=count("failed"),
        ),
        results=results,
    )


@router.post(
    "/records",
    response_model=UpsertResponse,
    responses=_RESPONSES,
    summary="Create/update records (chunk -> embed -> Qdrant + text DB), one by one",
)
async def upsert_records(
    body: UpsertRequest, service: Annotated[KnowledgeService, Depends(get_knowledge_service)]
) -> UpsertResponse:
    results = await service.upsert_many([(r.id, r.data) for r in body.records], force=body.force)
    return _upsert_response(results)


@router.put(
    "/records",
    response_model=UpsertResponse,
    responses=_RESPONSES,
    summary="Update records: for each one, delete it completely then add it again",
)
async def update_records(
    body: UpsertRequest, service: Annotated[KnowledgeService, Depends(get_knowledge_service)]
) -> UpsertResponse:
    results = []
    for r in body.records:
        deleted = (await service.delete_many([r.id]))[0]
        if deleted.status == "failed":
            results.append(
                UpsertItemResult(id=r.id, status="failed", error=f"delete step: {deleted.error}")
            )
            continue
        results.extend(await service.upsert_many([(r.id, r.data)]))
    return _upsert_response(results)


@router.delete(
    "/records",
    response_model=DeleteResponse,
    responses=_RESPONSES,
    summary="Delete records completely (text DB rows + Qdrant vectors); body: {ids: [...]}",
)
async def delete_records(
    body: DeleteRequest, service: Annotated[KnowledgeService, Depends(get_knowledge_service)]
) -> DeleteResponse:
    results = await service.delete_many(body.ids)
    return DeleteResponse(
        confirmed_ids=[r.id for r in results if r.status != "failed"],
        failed_ids=[r.id for r in results if r.status == "failed"],
        results=results,
    )


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
