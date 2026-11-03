"""Application errors and uniform JSON error responses.

Error body: {"error": {"code", "message", "request_id", "details"?}}
"""

from __future__ import annotations

import logging
from typing import Any

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.core.logging import request_id_var

logger = logging.getLogger(__name__)


class AppError(Exception):
    status_code = 400
    code = "bad_request"

    def __init__(self, message: str | None = None, *, details: Any = None):
        self.message = message or self.__class__.__name__
        self.details = details
        super().__init__(self.message)


class AuthError(AppError):
    status_code = 401
    code = "unauthorized"

    def __init__(self, message: str, *, reason: str):
        super().__init__(message)
        self.reason = reason  # for server logs only


class ForbiddenError(AppError):
    status_code = 403
    code = "forbidden"


class NotFoundError(AppError):
    status_code = 404
    code = "not_found"


class PayloadTooLargeError(AppError):
    status_code = 413
    code = "payload_too_large"


class UnprocessableRecordError(AppError):
    """A record's data cannot be turned into usable text."""

    status_code = 422
    code = "unprocessable_record"


class FileProcessingError(AppError):
    """A knowledge file cannot be downloaded / read / indexed. `code` says why; `retryable` marks
    a temporary failure (website 5xx, network) - everything else would fail the same way again."""

    status_code = 422
    code = "file_error"

    def __init__(self, code: str, message: str, *, retryable: bool = False):
        super().__init__(message)
        self.code = code
        self.retryable = retryable


class StorageQuotaError(FileProcessingError):
    status_code = 507

    def __init__(self, message: str):
        super().__init__("storage_quota_exceeded", message)


class InvalidCallbackError(AppError):
    """The `callback` object of a request is unusable or points to a forbidden host."""

    status_code = 422
    code = "invalid_callback"


class UpstreamError(AppError):
    """An external service (embedding API) failed or returned something unusable."""

    status_code = 502
    code = "upstream_error"


class ServiceUnavailableError(AppError):
    """A required backing service (Qdrant, database) or its configuration is unavailable."""

    status_code = 503
    code = "service_unavailable"


_HTTP_CODES = {
    400: "bad_request",
    401: "unauthorized",
    403: "forbidden",
    404: "not_found",
    405: "method_not_allowed",
    413: "payload_too_large",
    415: "unsupported_media_type",
    429: "too_many_requests",
}


def error_response(
    status: int, code: str, message: str, details: Any = None, headers: dict | None = None
) -> JSONResponse:
    body: dict[str, Any] = {
        "error": {"code": code, "message": message, "request_id": request_id_var.get()}
    }
    if details is not None:
        body["error"]["details"] = details
    return JSONResponse(status_code=status, content=body, headers=headers)


def register_exception_handlers(app: FastAPI) -> None:
    @app.exception_handler(AppError)
    async def _app_error(_: Request, exc: AppError) -> JSONResponse:
        return error_response(exc.status_code, exc.code, exc.message, exc.details)

    @app.exception_handler(StarletteHTTPException)
    async def _http_error(_: Request, exc: StarletteHTTPException) -> JSONResponse:
        code = _HTTP_CODES.get(exc.status_code, "http_error")
        return error_response(exc.status_code, code, str(exc.detail), headers=exc.headers)

    @app.exception_handler(RequestValidationError)
    async def _validation_error(_: Request, exc: RequestValidationError) -> JSONResponse:
        # Never echo the submitted input back (may be large or sensitive).
        details = [
            {"loc": list(e.get("loc", ())), "msg": e.get("msg"), "type": e.get("type")}
            for e in exc.errors()
        ]
        return error_response(422, "validation_error", "Request validation failed", details)

    @app.exception_handler(Exception)
    async def _unhandled(_: Request, exc: Exception) -> JSONResponse:
        logger.exception("Unhandled error: %s", exc)
        return error_response(500, "internal_error", "Internal server error")
