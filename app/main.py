"""FastAPI application factory.

Run via:  python -m app     (uvicorn factory mode; see app/__main__.py)
"""

from __future__ import annotations

import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from app import __version__
from app.api.router import api_router
from app.core.config import Settings, get_settings
from app.core.errors import register_exception_handlers
from app.core.logging import setup_logging
from app.core.middleware import (
    BodySizeLimitMiddleware,
    HTTPSOnlyMiddleware,
    RequestContextMiddleware,
)

logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    settings: Settings = app.state.settings
    for directory in (settings.data_dir, settings.uploads_dir, settings.log_dir):
        directory.mkdir(parents=True, exist_ok=True)
    logger.info(
        "Starting %s v%s env=%s https_required=%s ip_allowlist=%d docs=%s",
        settings.APP_NAME,
        __version__,
        settings.APP_ENV,
        settings.REQUIRE_HTTPS,
        len(settings.allowed_networks),
        settings.docs_enabled,
    )
    service = getattr(app.state, "knowledge_service", None)
    if service is None:
        # Build now so tokenizer/DB/Qdrant problems show up in the log at startup, not on the
        # first request. Failures here are logged only; requests retry initialization.
        try:
            from app.services.ingestion import KnowledgeService

            service = KnowledgeService.from_settings(settings)
            app.state.knowledge_service = service
        except Exception:
            logger.exception("Knowledge service could not be created at startup")
    if service is not None:
        try:
            await service.ensure_ready()
        except Exception as exc:  # noqa: BLE001
            logger.error("Knowledge service not ready at startup (will retry on use): %s", exc)
    yield
    if service is not None:
        await service.close()
    logger.info("Shutting down")


def create_app(settings: Settings | None = None, knowledge_service=None) -> FastAPI:
    settings = settings or get_settings()
    setup_logging(settings)

    docs = settings.docs_enabled
    app = FastAPI(
        title=settings.APP_NAME,
        version=__version__,
        lifespan=lifespan,
        docs_url="/docs" if docs else None,
        redoc_url=None,
        openapi_url="/openapi.json" if docs else None,
    )
    app.state.settings = settings
    app.state.knowledge_service = knowledge_service  # None = built lazily
    register_exception_handlers(app)

    # add_middleware: the last one added is the outermost.
    app.add_middleware(BodySizeLimitMiddleware, max_bytes=settings.MAX_BODY_BYTES)
    app.add_middleware(
        HTTPSOnlyMiddleware, enabled=settings.REQUIRE_HTTPS, exempt_paths=("/health",)
    )
    app.add_middleware(RequestContextMiddleware)

    app.include_router(api_router)
    return app
