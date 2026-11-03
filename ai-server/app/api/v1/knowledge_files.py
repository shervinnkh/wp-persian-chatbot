"""Texts and files in the knowledge base.

  POST | PUT | DELETE  /v1/knowledge/texts
      body: [{id:"text:..", data:{..}}] | {"records":[..]} | DELETE: {"ids":[..]}
  POST | PUT | DELETE  /v1/knowledge/files
      body: [{id:"file:..", title, file_orginal_name, path}] | {"files":[..]} | DELETE: {"ids":[..]}

PUT = for each item: delete completely, then add again (same as PUT /records).
"""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, Request

from app.api.v1.knowledge import _RESPONSES, _upsert_response, get_knowledge_service
from app.schemas.knowledge import (
    DeleteItemResult,
    DeleteResponse,
    FileIdsRequest,
    FilesRequest,
    TextIdsRequest,
    TextsRequest,
    UpsertResponse,
)
from app.services.ingestion import ContentService, KnowledgeService

router = APIRouter(prefix="/knowledge", tags=["knowledge-texts-files"])


def get_content_service(
    request: Request, knowledge: Annotated[KnowledgeService, Depends(get_knowledge_service)]
) -> ContentService:
    """Built lazily from the shared KnowledgeService (tests may inject their own)."""
    service = getattr(request.app.state, "content_service", None)
    if service is None:
        service = ContentService(knowledge, request.app.state.settings)
        request.app.state.content_service = service
    return service


Content = Annotated[ContentService, Depends(get_content_service)]


def _delete_response(results: list[DeleteItemResult]) -> DeleteResponse:
    return DeleteResponse(
        confirmed_ids=[r.id for r in results if r.status != "failed"],
        failed_ids=[r.id for r in results if r.status == "failed"],
        results=results,
    )


# ------------------------------------------------------------------ texts
@router.post("/texts", response_model=UpsertResponse, responses=_RESPONSES,
             summary="Add texts (plugin chunks kept; oversized chunks re-split)")  # fmt: skip
async def add_texts(body: TextsRequest, content: Content) -> UpsertResponse:
    return _upsert_response(await content.add_texts(body.records, force=body.force))


@router.put("/texts", response_model=UpsertResponse, responses=_RESPONSES,
            summary="Update texts: delete completely, then add again")  # fmt: skip
async def update_texts(body: TextsRequest, content: Content) -> UpsertResponse:
    return _upsert_response(await content.replace_texts(body.records))


@router.delete("/texts", response_model=DeleteResponse, responses=_RESPONSES,
               summary="Delete texts (text DB + Qdrant)")  # fmt: skip
async def delete_texts(body: TextIdsRequest, content: Content) -> DeleteResponse:
    return _delete_response(await content.delete(body.ids, files=False))


# ------------------------------------------------------------------ files
@router.post("/files", response_model=UpsertResponse, responses=_RESPONSES,
             summary="Add files: download, extract, chunk, embed, store")  # fmt: skip
async def add_files(body: FilesRequest, content: Content) -> UpsertResponse:
    return _upsert_response(await content.add_files(body.files, force=body.force))


@router.put("/files", response_model=UpsertResponse, responses=_RESPONSES,
            summary="Update files: delete completely, then add again")  # fmt: skip
async def update_files(body: FilesRequest, content: Content) -> UpsertResponse:
    return _upsert_response(await content.replace_files(body.files))


@router.delete("/files", response_model=DeleteResponse, responses=_RESPONSES,
               summary="Delete files (text DB + Qdrant + downloaded file)")  # fmt: skip
async def delete_files(body: FileIdsRequest, content: Content) -> DeleteResponse:
    return _delete_response(await content.delete(body.ids, files=True))
