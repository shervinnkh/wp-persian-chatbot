"""Shared FastAPI dependencies. `require_signed_request` guards everything under /v1."""

from __future__ import annotations

import ipaddress
import logging
from dataclasses import dataclass

from fastapi import Request

from app.core.config import Settings
from app.core.errors import AuthError, ForbiddenError
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
        raise ForbiddenError("IP address is not allowed")


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
        raise
    return SignedRequest(body=body, timestamp=ts, client_ip=ip)
