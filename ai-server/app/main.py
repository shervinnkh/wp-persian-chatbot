"""FastAPI application factory.

Run via:  python -m app     (uvicorn factory mode; see app/__main__.py)
"""

from __future__ import annotations

import asyncio
import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from app import __version__
from app.api.router import api_router
from app.core.config import Settings, get_settings
from app.core.errors import register_exception_handlers
from app.core.logging import setup_logging
from app.core.monitoring import shutdown_monitoring, start_snapshots, stop_snapshots
from app.core.middleware import (
    BodySizeLimitMiddleware,
    HTTPSOnlyMiddleware,
    RequestContextMiddleware,
)

logger = logging.getLogger(__name__)


def _attach_llm_generator(settings: Settings, messaging, knowledge) -> None:
    """Real answers (RAG + LLM) replace the fixed placeholder, sharing the knowledge service's
    embedder / Qdrant client (embedded Qdrant can be opened by one process only). A generator
    injected by the caller (tests) is left alone."""
    from app.services.conversation.answer import FixedAnswerGenerator

    if not isinstance(messaging.answer_generator, FixedAnswerGenerator):
        return
    parts = [getattr(knowledge, n, None) for n in ("vectorstore", "embedder", "repo")]
    if not settings.OPENAI_API_KEY.get_secret_value() or any(p is None for p in parts):
        logger.warning(
            "AI answers use the FIXED placeholder text: OPENAI_API_KEY is empty or the "
            "knowledge service is unavailable"
        )
        return
    from app.services.conversation.llm_answer import LLMAnswerGenerator
    from app.services.conversation.retrieval import KnowledgeRetriever

    vectorstore, embedder, repo = parts
    retriever = KnowledgeRetriever(
        embedder=embedder, vectorstore=vectorstore, repo=repo,
        top_k=settings.RAG_TOP_K, min_score=settings.RAG_MIN_SCORE,
        cache_size=settings.EMBEDDING_CACHE_SIZE,
    )  # fmt: skip
    messaging.answer_generator = LLMAnswerGenerator(settings, retriever)
    logger.info("AI answers: RAG + LLM generator active (top_k=%d)", settings.RAG_TOP_K)


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
    messaging = getattr(app.state, "messaging_service", None)
    if messaging is None:
        try:
            from app.services.messaging import MessagingService

            messaging = MessagingService.from_settings(settings)
            app.state.messaging_service = messaging
        except Exception:
            logger.exception("Messaging service could not be created at startup")
    if messaging is not None:
        try:
            await messaging.ensure_ready()
        except Exception as exc:  # noqa: BLE001
            logger.error("Messaging service not ready at startup (will retry on use): %s", exc)
    if messaging is not None:
        _attach_llm_generator(settings, messaging, service)
    queue = getattr(app.state, "ingest_queue", None)
    if queue is None:
        try:
            from app.services.ingestion import IngestQueueService

            queue = IngestQueueService.from_settings(settings)
            app.state.ingest_queue = queue
        except Exception:
            logger.exception("Ingestion queue could not be created at startup")
    if queue is not None:
        try:
            await queue.ensure_ready()
        except Exception as exc:  # noqa: BLE001
            logger.error("Ingestion queue not ready at startup (will retry on use): %s", exc)

    # Embedded Qdrant (QDRANT_PATH) can be opened by ONE process: then the ingestion loop runs
    # here, in the background, instead of in `python -m app.workers.ingestion`. Requests still
    # only queue and answer at once; the work happens in this background task.
    ingest_stop = asyncio.Event()
    ingest_task: asyncio.Task | None = None
    if settings.ingest_in_api and service is not None and queue is not None:
        from app.services.ingestion.processor import IngestProcessor

        processor = IngestProcessor(settings=settings, repo=queue.repo, knowledge=service)
        ingest_task = asyncio.create_task(processor.run_loop(ingest_stop), name="ingest-loop")
        logger.info("Ingestion loop runs inside the API process (INGEST_RUN_IN_API)")
    # AI answers (generate_ai_answer + callback) are produced by a loop in this process: it only
    # waits on the AI provider, so a high concurrency is cheap (ANSWER_WORKER_CONCURRENCY).
    answer_stop = asyncio.Event()
    answer_task: asyncio.Task | None = None
    if messaging is not None:
        answer_task = asyncio.create_task(
            messaging.answers.run_loop(answer_stop), name="answer-loop"
        )
    snap_stop = asyncio.Event()
    snap_task = start_snapshots(
        settings, db=messaging.db if messaging is not None else None, stop=snap_stop
    )
    yield
    await stop_snapshots(snap_task, snap_stop)
    if answer_task is not None:
        answer_stop.set()
        messaging.answers.notify()
        try:
            await asyncio.wait_for(answer_task, timeout=30)
        except Exception:  # noqa: BLE001
            answer_task.cancel()
    if ingest_task is not None:
        ingest_stop.set()
        try:
            await asyncio.wait_for(ingest_task, timeout=30)
        except Exception:  # noqa: BLE001
            ingest_task.cancel()
    if messaging is not None:
        closer = getattr(messaging.answer_generator, "close", None)
        if closer is not None:
            try:
                await closer()
            except Exception:  # noqa: BLE001
                logger.exception("Answer generator could not be closed")
    if service is not None:
        await service.close()
    if messaging is not None:
        await messaging.close()
    if queue is not None:
        await queue.close()
    logger.info("Shutting down")
    shutdown_monitoring()


def create_app(
    settings: Settings | None = None,
    knowledge_service=None,
    messaging_service=None,
    ingest_queue=None,
) -> FastAPI:
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
    app.state.messaging_service = messaging_service  # None = built lazily
    app.state.ingest_queue = ingest_queue  # None = built lazily
    register_exception_handlers(app)

    # add_middleware: the last one added is the outermost.
    app.add_middleware(BodySizeLimitMiddleware, max_bytes=settings.MAX_BODY_BYTES)
    app.add_middleware(
        HTTPSOnlyMiddleware, enabled=settings.REQUIRE_HTTPS, exempt_paths=("/health",)
    )
    app.add_middleware(RequestContextMiddleware)

    app.include_router(api_router)
    return app
