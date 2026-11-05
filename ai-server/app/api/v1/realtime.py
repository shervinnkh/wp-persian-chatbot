"""Realtime endpoints: WebSocket /v1/ws (token auth inside the socket) and signed POST /v1/push."""

from __future__ import annotations

import re
import time
from typing import Literal

from fastapi import APIRouter, Depends, Request, WebSocket
from pydantic import BaseModel, field_validator

from app.api.deps import SignedRequest, get_settings, require_signed_request
from app.core.errors import AuthError
from app.core.monitoring import emit
from app.services.realtime.manager import get_manager, serve

ws_router = APIRouter()  # no HMAC dependency: a socket authenticates with the plugin's token
push_router = APIRouter()  # included under the signed /v1 router

_UID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")


@ws_router.websocket("/v1/ws")
async def ws_endpoint(websocket: WebSocket) -> None:
    await serve(websocket, websocket.app.state.settings)


class PushIn(BaseModel):
    visitor_id: str
    event: Literal["new_message", "typing"]
    on: bool | None = None

    @field_validator("visitor_id")
    @classmethod
    def _uid(cls, v: str) -> str:
        v = v.strip().lower()
        if not _UID.match(v):
            raise ValueError("invalid visitor_id")
        return v


@push_router.post("/push")
async def push(body: PushIn, request: Request, signed: SignedRequest = Depends(require_signed_request)) -> dict:
    settings = get_settings(request)
    if abs(time.time() - signed.timestamp) > settings.WS_PUSH_MAX_SKEW_SECONDS:
        raise AuthError("Request timestamp is outside the push window", reason="expired")
    if settings.ws_active:
        event: dict = {"type": body.event}
        if body.event == "typing":
            event["on"] = bool(body.on)
        sent = await get_manager().notify_visitor(body.visitor_id, event)
        emit("ws.push", event=body.event, sent=sent)
    return {"ok": True}  # same answer whether or not the visitor is connected
