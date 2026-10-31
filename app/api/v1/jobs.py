"""Job status endpoint: the plugin's safety net when a completion callback got lost."""

from __future__ import annotations

from fastapi import APIRouter

from app.api.v1.knowledge import Queue
from app.schemas.common import ErrorResponse
from app.schemas.ingest import JobStatusRequest, JobStatusResponse

router = APIRouter(prefix="/jobs", tags=["jobs"])


@router.post(
    "/status",
    response_model=JobStatusResponse,
    responses={401: {"model": ErrorResponse}, 503: {"model": ErrorResponse}},
    summary="Latest state of records (trained|untrained|failed|processing|unknown); body: {ids}",
)
async def job_status(body: JobStatusRequest, queue: Queue) -> JobStatusResponse:
    return await queue.job_status(body.ids)
