"""Signed calls from this backend to the WordPress site for live data.

Two endpoints on the site (paths in .env, host = PLUGIN_BASE_URL - never taken from a request):

  POST {PLUGIN_BASE_URL}{TOOLS_PRODUCTS_PATH}   live data of products   -> {"ok", "message"}
  POST {PLUGIN_BASE_URL}{TOOLS_DYNAMIC_PATH}    text of a dynamic tool  -> {"ok", "text"}

Signing is the same as for the completion callbacks (PLUGIN_CALLBACK_SECRET, default = the
shared API secret):  X-PAIA-Timestamp / X-PAIA-Signature = "sha256=" + hex HMAC_SHA256(key,
"{timestamp}.{raw body}"), plus X-PAIA-Request (unique id, for the site's logs).
Redirects are never followed. Every failure becomes a `ToolCallError` with a SAFE message (the
model reads it as the tool result, so it never contains a URL or a secret).
"""

from __future__ import annotations

import ipaddress
import json
import logging
import secrets
import time
from urllib.parse import urlparse

import httpx

from app.core.config import Settings
from app.core.monitoring import emit
from app.services.ingestion.delivery import H_SIGNATURE, H_TIMESTAMP, sign

logger = logging.getLogger(__name__)

H_REQUEST = "X-PAIA-Request"
_MAX_RESPONSE_BYTES = 256 * 1024


class ToolCallError(Exception):
    """The live-data call failed; `str(exc)` is safe to show to the model."""


class SiteToolClient:
    def __init__(self, settings: Settings, *, http: httpx.AsyncClient | None = None):
        self.s = settings
        self._http = http  # tests inject a client with a mock transport

    # ------------------------------------------------------------------ public
    @property
    def configured(self) -> bool:
        return bool(self.s.PLUGIN_BASE_URL)

    async def product_info(self, *, fields: list[str], products: list[dict]) -> str:
        """Live data of products -> the site's ready-made, structured message."""
        data = await self._post(
            self.s.TOOLS_PRODUCTS_PATH, {"fields": fields, "products": products}
        )
        text = data.get("message")
        if not isinstance(text, str) or not text.strip():
            items = data.get("items")
            if items is None:
                raise ToolCallError("the site returned no product data")
            text = json.dumps(items, ensure_ascii=False)
        return text.strip()

    async def dynamic_tool(self, tool_id: int | str) -> str:
        """Live text of a site-defined tool."""
        data = await self._post(self.s.TOOLS_DYNAMIC_PATH, {"tool_id": tool_id})
        text = data.get("text")
        if not isinstance(text, str) or not text.strip():
            raise ToolCallError("the site returned an empty text for this tool")
        return text.strip()

    # ------------------------------------------------------------------ transport
    def _url(self, path: str) -> str:
        base = self.s.PLUGIN_BASE_URL.rstrip("/")
        if not base:
            raise ToolCallError("live data is not configured on the server")
        u = urlparse(base)
        if u.scheme == "http" and self.s.is_production:
            raise ToolCallError("live data needs an https site address")
        if self.s.is_production and u.hostname:
            try:
                if not ipaddress.ip_address(u.hostname).is_global:
                    raise ToolCallError("live data site address is not public")
            except ValueError:
                pass  # a domain name
        return base + path

    async def _post(self, path: str, payload: dict) -> dict:
        url = self._url(path)
        kind = "products" if path == self.s.TOOLS_PRODUCTS_PATH else "dynamic"
        request_id = secrets.token_hex(8)
        body = json.dumps(
            {"request_id": request_id, **payload}, ensure_ascii=False, separators=(",", ":")
        ).encode("utf-8")
        stamp = str(int(time.time()))
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            H_TIMESTAMP: stamp,
            H_SIGNATURE: sign(self.s.callback_secret, stamp, body),
            H_REQUEST: request_id,
        }
        t0 = time.monotonic()
        client = self._http or httpx.AsyncClient(
            timeout=httpx.Timeout(self.s.TOOLS_TIMEOUT, connect=min(5.0, self.s.TOOLS_TIMEOUT)),
            follow_redirects=False,
        )
        try:
            resp = await client.post(url, content=body, headers=headers)
        except httpx.HTTPError as exc:
            logger.warning(
                "tools.site_unreachable path=%s error=%s ms=%d",
                path, type(exc).__name__, (time.monotonic() - t0) * 1000,
            )  # fmt: skip
            emit(
                "tool.site_request", kind=kind, outcome="unreachable",
                error_type=type(exc).__name__, ms=(time.monotonic() - t0) * 1000,
            )  # fmt: skip
            raise ToolCallError("the website did not answer in time") from exc
        finally:
            if self._http is None:
                await client.aclose()

        ms = int((time.monotonic() - t0) * 1000)

        def seen(outcome: str) -> None:
            emit(
                "tool.site_request", kind=kind, outcome=outcome, http_status=resp.status_code,
                ms=ms, bytes=len(resp.content),
            )  # fmt: skip

        if resp.status_code != 200:
            logger.warning("tools.site_status path=%s status=%d ms=%d", path, resp.status_code, ms)
            seen("http_error")
            raise ToolCallError(f"the website answered with an error (HTTP {resp.status_code})")
        if len(resp.content) > _MAX_RESPONSE_BYTES:
            seen("too_large")
            raise ToolCallError("the website's answer was too large")
        try:
            data = resp.json()
        except ValueError as exc:
            seen("bad_json")
            raise ToolCallError("the website's answer was not valid JSON") from exc
        if not isinstance(data, dict):
            seen("bad_shape")
            raise ToolCallError("the website's answer has an unexpected shape")
        if data.get("ok") is False:
            seen("site_error")
            reason = data.get("error")
            reason = reason.strip()[:200] if isinstance(reason, str) and reason.strip() else None
            raise ToolCallError(reason or "the website could not provide this information")
        seen("ok")
        logger.info("tools.site_ok path=%s ms=%d", path, ms)
        return data
