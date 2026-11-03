"""POST /v1/get_log - the plugin's "server log" tab asks for the lines after the ones it has."""

from __future__ import annotations

import asyncio
from typing import Annotated

from fastapi import APIRouter, Request
from pydantic import BaseModel, Field

from app.schemas.common import ErrorResponse
from app.services import logs as log_reader

router = APIRouter(tags=["logs"])


class GetLogRequest(BaseModel):
    # Ids of the last few entries the plugin already has (it sends ~3). Empty = newest `limit`.
    after: Annotated[list[str], Field(max_length=log_reader.MAX_AFTER_IDS)] = []
    limit: int = Field(200, ge=1, le=log_reader.MAX_LIMIT)


class LogEntryOut(BaseModel):
    id: str
    time: str
    level: str
    request_id: str
    logger: str
    message: str


class GetLogResponse(BaseModel):
    entries: list[LogEntryOut]  # oldest -> newest
    count: int
    reset: bool = False  # `after` was not found (rotated/too old): these are the newest lines


@router.post(
    "/get_log",
    response_model=GetLogResponse,
    responses={401: {"model": ErrorResponse}},
    summary="Log lines after the given entry ids (light: one bounded read of the end of app.log)",
)
async def get_log(body: GetLogRequest, request: Request) -> GetLogResponse:
    path = request.app.state.settings.log_dir / "app.log"
    entries, reset = await asyncio.to_thread(log_reader.logs_after, path, body.after, body.limit)
    return GetLogResponse(
        entries=[LogEntryOut(**e) for e in entries], count=len(entries), reset=reset
    )
