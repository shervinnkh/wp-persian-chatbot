"""Router assembly. Everything under /v1 requires a valid HMAC signature (secure by default)."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.api.deps import require_signed_request
from app.api.v1 import health, jobs, knowledge, logs, messaging, system

api_router = APIRouter()

# Public
api_router.include_router(health.router)

# Authenticated (add new v1 routers here)
v1_router = APIRouter(prefix="/v1", dependencies=[Depends(require_signed_request)])
v1_router.include_router(system.router)
v1_router.include_router(knowledge.router)
v1_router.include_router(jobs.router)
v1_router.include_router(messaging.router)
v1_router.include_router(logs.router)
api_router.include_router(v1_router)
