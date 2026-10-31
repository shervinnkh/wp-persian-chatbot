"""Callback address checks (SSRF protection), done in two places:

* when a request is accepted (`validate_callback`): scheme, port, forbidden/literal IPs and
  "the host must be the site this key is locked to". No DNS here - the API must stay fast.
* right before every send (`resolve_public_ip`): DNS is resolved, private/loopback/link-local
  addresses are refused, and the HTTP connection goes to the checked IP (DNS-rebinding safe).
"""

from __future__ import annotations

import asyncio
import ipaddress
import socket
from dataclasses import dataclass
from urllib.parse import urlsplit

from app.core.config import Settings
from app.core.errors import InvalidCallbackError
from app.schemas.ingest import CallbackIn

ALLOWED_PORTS = (443, 8443)


class CallbackBlockedError(Exception):
    """The address resolves to a forbidden (non-public) IP or cannot be resolved."""


@dataclass(slots=True)
class ValidatedCallback:
    site_key: str  # host[:port], lower case
    url: str
    ping_url: str | None
    site_url: str | None
    max_results: int

    def as_dict(self) -> dict:
        return {
            "url": self.url,
            "ping_url": self.ping_url,
            "site_url": self.site_url,
            "max_results": self.max_results,
        }


def _is_public(ip: ipaddress.IPv4Address | ipaddress.IPv6Address) -> bool:
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    return ip.is_global and not ip.is_multicast


def _parse(url: str, *, dev: bool, what: str):
    try:
        parts = urlsplit(url.strip())
        host = (parts.hostname or "").lower()
        port = parts.port  # may raise ValueError
    except ValueError as exc:
        raise InvalidCallbackError(f"callback.{what} is not a valid URL") from exc
    if parts.scheme not in ("https", "http") or not host:
        raise InvalidCallbackError(f"callback.{what} must be an absolute http(s) URL")
    if parts.scheme == "http" and not dev:
        raise InvalidCallbackError(f"callback.{what} must use https")
    if parts.username or parts.password:
        raise InvalidCallbackError(f"callback.{what} must not contain credentials")
    if not dev and port is not None and port not in ALLOWED_PORTS:
        raise InvalidCallbackError(f"callback.{what}: only ports 443 and 8443 are allowed")
    default = 443 if parts.scheme == "https" else 80
    return parts, host, port, default


def site_key_for_url(url: str, *, dev: bool = True) -> str:
    """`host` or `host:port` (port only when it is not the scheme's default)."""
    parts, host, port, default = _parse(url, dev=dev, what="url")
    return host if port in (None, default) else f"{host}:{port}"


def validate_callback(
    settings: Settings, cb: CallbackIn, *, locked_site: str | None
) -> ValidatedCallback:
    dev = settings.CALLBACK_ALLOW_PRIVATE_NETWORKS  # false in production (config validator)
    parts, host, port, default = _parse(cb.url, dev=dev, what="url")
    key = host if port in (None, default) else f"{host}:{port}"

    if not dev:
        try:
            literal = ipaddress.ip_address(host.strip("[]"))
        except ValueError:
            literal = None
        if literal is not None and not _is_public(literal):
            raise InvalidCallbackError("callback.url points to a non-public address")
        if host == "localhost" or host.endswith((".localhost", ".local", ".internal")):
            raise InvalidCallbackError("callback.url points to a non-public address")

    # The key is locked to one site: PLUGIN_BASE_URL if configured, else the first one seen.
    expected = None
    if settings.PLUGIN_BASE_URL:
        expected = site_key_for_url(settings.PLUGIN_BASE_URL, dev=True)
    elif locked_site:
        expected = locked_site
    if expected is not None and key != expected:
        raise InvalidCallbackError("callback.url host does not match the site of this key")

    ping = cb.ping_url
    if ping:
        _, ping_host, ping_port, ping_default = _parse(ping, dev=dev, what="ping_url")
        ping_key = ping_host if ping_port in (None, ping_default) else f"{ping_host}:{ping_port}"
        if ping_key != key:
            raise InvalidCallbackError("callback.ping_url must be on the same host as callback.url")

    return ValidatedCallback(
        site_key=key,
        url=cb.url.strip(),
        ping_url=ping.strip() if ping else None,
        site_url=cb.site_url.strip() if cb.site_url else None,
        max_results=cb.max_results,
    )


async def resolve_public_ip(
    host: str, port: int, *, allow_private: bool, timeout: float = 3.0
) -> str:
    """Resolve `host` and return an IP that is safe to connect to (all answers must be public)."""
    host = host.strip("[]")
    try:
        literal = ipaddress.ip_address(host)
        addresses = [literal]
    except ValueError:
        try:
            loop = asyncio.get_running_loop()
            infos = await asyncio.wait_for(
                loop.getaddrinfo(host, port, type=socket.SOCK_STREAM), timeout=timeout
            )
        except (OSError, asyncio.TimeoutError) as exc:
            raise CallbackBlockedError(f"cannot resolve {host}") from exc
        addresses = []
        for info in infos:
            ip = ipaddress.ip_address(info[4][0])
            if ip not in addresses:
                addresses.append(ip)
    if not addresses:
        raise CallbackBlockedError(f"cannot resolve {host}")
    if not allow_private:
        for ip in addresses:
            if not _is_public(ip):
                raise CallbackBlockedError(f"{host} resolves to a non-public address")
    return str(addresses[0])
