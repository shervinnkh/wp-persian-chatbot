"""Shared FastAPI dependencies. `require_signed_request` guards everything under /v1."""

from __future__ import annotations

import ipaddress
import logging
from dataclasses import dataclass

from fastapi import Request

from app.core.config import Settings
from app.core.errors import AuthError, ForbiddenError, ServiceUnavailableError
from app.core.monitoring import emit
from app.core.replay import MUTATING_METHODS, ReplayGuard, replay_key
from app.core.security import HEADER_SIGNATURE, HEADER_TIMESTAMP, verify_signature

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class SignedRequest:
    body: bytes
    timestamp: int
    client_ip: str | None


def get_settings(request: Request) -> Settings:
    return request.app.state.settings


def _check_ip_allowed(settings: Settings, ip: str | None) -> None:
    networks = settings.allowed_networks
    if not networks:
        return
    try:
        addr = ipaddress.ip_address(ip) if ip else None
    except ValueError:
        addr = None
    if addr is None or not any(addr in net for net in networks):
        logger.warning("IP not allowed: ip=%s", ip)
        emit("auth.rejected", kind="ip", reason="ip_not_allowed", ip=ip)
        raise ForbiddenError("IP address is not allowed")


async def _replay_guard(request: Request, settings: Settings) -> ReplayGuard:
    """The guard started with the app; built on first use when the app was assembled by hand."""
    guard = getattr(request.app.state, "replay_guard", None)
    if guard is None:
        db = getattr(getattr(request.app.state, "messaging_service", None), "db", None)
        if db is None:
            from app.db.base import Database

            db = Database.from_settings(settings)
        guard = ReplayGuard(db)
        request.app.state.replay_guard = guard
    return guard


async def _reject_replay(
    request: Request, settings: Settings, ip: str | None, ts: int, body: bytes
) -> None:
    """Accept a signed mutating request only once (S-6); raises AuthError(reason=replayed)."""
    target = request.url.path + (f"?{request.url.query}" if request.url.query else "")
    key = replay_key(request.headers.get(HEADER_SIGNATURE, ""), request.method, target, body)
    guard = await _replay_guard(request, settings)
    try:
        first_time = await guard.claim(key, ts + 2 * settings.AUTH_MAX_SKEW_SECONDS)
    except Exception as exc:  # noqa: BLE001 - never let a storage problem pass as "authorized"
        logger.exception("Replay check failed: %s %s", request.method, request.url.path)
        raise ServiceUnavailableError("Replay check is temporarily unavailable") from exc
    if first_time:
        return
    logger.warning(
        "Auth failed: reason=replayed ip=%s %s %s", ip, request.method, request.url.path
    )
    route = getattr(request.scope.get("route"), "path", None)
    emit(
        "auth.rejected", kind="signature", reason="replayed", ip=ip,
        method=request.method, route=route,
    )  # fmt: skip
    raise AuthError("Request was already processed", reason="replayed")


async def require_signed_request(request: Request) -> SignedRequest:
    settings = get_settings(request)
    ip = request.client.host if request.client else None
    _check_ip_allowed(settings, ip)

    body = await request.body()  # cached by Starlette; the route can still parse it
    try:
        ts = verify_signature(
            secret=settings.API_SHARED_SECRET.get_secret_value(),
            timestamp=request.headers.get(HEADER_TIMESTAMP),
            signature=request.headers.get(HEADER_SIGNATURE),
            body=body,
            max_skew=settings.AUTH_MAX_SKEW_SECONDS,
        )
    except AuthError as exc:
        logger.warning(
            "Auth failed: reason=%s ip=%s %s %s", exc.reason, ip, request.method, request.url.path
        )
        route = getattr(request.scope.get("route"), "path", None)
        emit(
            "auth.rejected", kind="signature", reason=str(exc.reason), ip=ip,
            method=request.method, route=route,
        )  # fmt: skip
        raise
    if settings.REPLAY_PROTECTION and request.method.upper() in MUTATING_METHODS:
        await _reject_replay(request, settings, ip, ts, body)
    return SignedRequest(body=body, timestamp=ts, client_ip=ip)
