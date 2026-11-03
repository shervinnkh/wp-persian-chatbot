from __future__ import annotations

import time
from typing import Annotated

from fastapi import APIRouter, Depends

from app import __version__
from app.api.deps import SignedRequest, get_settings, require_signed_request
from app.core.config import Settings
from app.schemas.common import ErrorResponse, PingResponse

router = APIRouter(tags=["system"])

_RESPONSES = {401: {"model": ErrorResponse}, 403: {"model": ErrorResponse}}


def _build(settings: Settings, received: int) -> PingResponse:
    return PingResponse(
        app=settings.APP_NAME,
        version=__version__,
        environment=settings.APP_ENV,
        server_time=int(time.time()),
        received_bytes=received,
    )


@router.get(
    "/ping", response_model=PingResponse, responses=_RESPONSES, summary="Signed connectivity test"
)
async def ping_get(
    signed: Annotated[SignedRequest, Depends(require_signed_request)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> PingResponse:
    return _build(settings, len(signed.body))


@router.post(
    "/ping",
    response_model=PingResponse,
    responses=_RESPONSES,
    summary="Signed connectivity test (verifies body signing)",
)
async def ping_post(
    signed: Annotated[SignedRequest, Depends(require_signed_request)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> PingResponse:
    return _build(settings, len(signed.body))
