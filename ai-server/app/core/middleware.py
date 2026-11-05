"""Pure-ASGI middlewares: request context + access log, HTTPS-only, body size limit."""

from __future__ import annotations

import logging
import re
import time
import uuid

from fastapi import HTTPException
from starlette.datastructures import Headers
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app.core.errors import error_response
from app.core.logging import request_id_var
from app.core.monitoring import emit

access_logger = logging.getLogger("app.access")
_RID_RE = re.compile(r"^[A-Za-z0-9._-]{1,64}$")
_NUM_SEGMENT = re.compile(r"/\d+")


def _route_name(scope: Scope, status: int) -> str:
    """Route template (`/v1/conversations/{conversation_id}/messages`), never the raw path: ids
    and scanner garbage must not create endless distinct values in the monitoring data."""
    route = scope.get("route")
    path = getattr(route, "path", None)
    if path:
        return path
    if status == 404:
        return "(unmatched)"
    return _NUM_SEGMENT.sub("/{id}", scope.get("path", ""))[:120]


class RequestContextMiddleware:
    """Assigns a request id (X-Request-ID), logs one access line per request."""

    def __init__(self, app: ASGIApp, quiet_paths: tuple[str, ...] = ("/health",)):
        self.app = app
        self.quiet_paths = quiet_paths

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        incoming = Headers(scope=scope).get("x-request-id", "")
        rid = incoming if _RID_RE.match(incoming) else uuid.uuid4().hex[:16]
        token = request_id_var.set(rid)
        started = time.perf_counter()
        status = 500
        bytes_out = 0

        async def send_wrapper(message: Message) -> None:
            nonlocal status, bytes_out
            if message["type"] == "http.response.start":
                status = message["status"]
                headers = list(message.get("headers", []))
                headers.append((b"x-request-id", rid.encode("ascii")))
                message["headers"] = headers
            elif message["type"] == "http.response.body":
                bytes_out += len(message.get("body", b""))
            await send(message)

        try:
            await self.app(scope, receive, send_wrapper)
        finally:
            duration_ms = (time.perf_counter() - started) * 1000
            client = scope.get("client")
            ip = client[0] if client else "-"
            ip_hash = (scope.get("state") or {}).get("ip_hash")  # set by the chat handlers (S-1)
            path = scope.get("path", "")
            level = logging.DEBUG if path in self.quiet_paths else logging.INFO
            if status >= 500:
                level = logging.ERROR
            access_logger.log(
                level,
                "%s %s -> %s (%.1f ms) ip=%s",
                scope.get("method", "-"),
                path,
                status,
                duration_ms,
                ip,
                extra={"http_status": status, "duration_ms": round(duration_ms, 1), "ip": ip},
            )
            if path not in self.quiet_paths:  # /health is polled constantly: not worth a line
                declared = Headers(scope=scope).get("content-length", "")
                emit(
                    "http.request",
                    method=scope.get("method", "-"),
                    route=_route_name(scope, status),
                    status=status,
                    ms=round(duration_ms, 1),
                    bytes_in=int(declared) if declared.isdigit() else None,
                    bytes_out=bytes_out,
                    # chat requests: the visitor's IP hash; the TCP peer is only the WordPress host
                    ip=None if ip_hash else (ip if ip != "-" else None),
                    ip_hash=ip_hash,
                )
            request_id_var.reset(token)


class HTTPSOnlyMiddleware:
    """Rejects plain-HTTP requests (scheme comes from uvicorn --proxy-headers behind nginx)."""

    def __init__(self, app: ASGIApp, enabled: bool, exempt_paths: tuple[str, ...] = ()):
        self.app = app
        self.enabled = enabled
        self.exempt_paths = exempt_paths

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if (
            self.enabled
            and scope["type"] == "http"
            and scope.get("scheme") != "https"
            and scope.get("path") not in self.exempt_paths
        ):
            response = error_response(400, "https_required", "HTTPS is required")
            await response(scope, receive, send)
            return
        await self.app(scope, receive, send)


class BodySizeLimitMiddleware:
    """Rejects bodies larger than max_bytes (Content-Length fast path + streaming count)."""

    def __init__(self, app: ASGIApp, max_bytes: int):
        self.app = app
        self.max_bytes = max_bytes

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        declared = Headers(scope=scope).get("content-length")
        if declared and declared.isdigit() and int(declared) > self.max_bytes:
            response = error_response(413, "payload_too_large", "Request body too large")
            await response(scope, receive, send)
            return

        received = 0

        async def limited_receive() -> Message:
            nonlocal received
            message = await receive()
            if message["type"] == "http.request":
                received += len(message.get("body", b""))
                if received > self.max_bytes:
                    raise HTTPException(status_code=413, detail="Request body too large")
            return message

        await self.app(scope, limited_receive, send)
