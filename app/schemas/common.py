from __future__ import annotations

from typing import Any

from pydantic import BaseModel


class ErrorBody(BaseModel):
    code: str
    message: str
    request_id: str
    details: Any | None = None


class ErrorResponse(BaseModel):
    error: ErrorBody


class HealthResponse(BaseModel):
    status: str = "ok"


class PingResponse(BaseModel):
    status: str = "ok"
    authenticated: bool = True
    app: str
    version: str
    environment: str
    server_time: int  # unix seconds; the plugin can use it to detect clock skew
    received_bytes: int = 0
