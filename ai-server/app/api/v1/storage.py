"""GET /v1/storage - how much of the knowledge-file quota (UPLOADS_MAX_TOTAL_MB) is used."""

from __future__ import annotations

import asyncio

from fastapi import APIRouter, Depends, Request
from pydantic import BaseModel

from app.schemas.common import ErrorResponse
from app.services.files.download import DownloadManager

router = APIRouter(prefix="/storage", tags=["storage"])


def get_download_manager(request: Request) -> DownloadManager:
    """ONE shared manager per app (built on first use): its constructor does `mkdir` and
    `cleanup_partials`, which must not run on every request. No `await` between the check and the
    assignment, so two requests cannot both build one."""
    state = request.app.state
    manager = getattr(state, "download_manager", None)
    if manager is None:
        manager = DownloadManager(state.settings)
        state.download_manager = manager
    return manager


class StorageResponse(BaseModel):
    used_bytes: int
    max_bytes: int
    free_bytes: int
    files: int
    max_file_bytes: int
    note: str = "Counts downloaded knowledge files only (chat attachments are not included)."


@router.get("", response_model=StorageResponse, responses={401: {"model": ErrorResponse}})
async def storage(manager: DownloadManager = Depends(get_download_manager)) -> StorageResponse:
    return StorageResponse(**await asyncio.to_thread(manager.usage))
