"""GET /v1/storage - how much of the knowledge-file quota (UPLOADS_MAX_TOTAL_MB) is used."""

from __future__ import annotations

import asyncio

from fastapi import APIRouter, Request
from pydantic import BaseModel

from app.schemas.common import ErrorResponse
from app.services.files.download import DownloadManager

router = APIRouter(prefix="/storage", tags=["storage"])


class StorageResponse(BaseModel):
    used_bytes: int
    max_bytes: int
    free_bytes: int
    files: int
    max_file_bytes: int
    note: str = "Counts downloaded knowledge files only (chat attachments are not included)."


@router.get("", response_model=StorageResponse, responses={401: {"model": ErrorResponse}})
async def storage(request: Request) -> StorageResponse:
    manager = DownloadManager(request.app.state.settings)
    return StorageResponse(**await asyncio.to_thread(manager.usage))
