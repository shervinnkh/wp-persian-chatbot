"""In-memory connection table + the socket session loop. Single process by design."""

from __future__ import annotations

import asyncio
import json
import logging
import time
from urllib.parse import urlsplit

from starlette.websockets import WebSocket, WebSocketDisconnect, WebSocketState

from app.core.config import Settings
from app.core.monitoring import emit
from app.services.realtime.tokens import JtiCache, TokenError, verify_token

logger = logging.getLogger(__name__)

SEND_TIMEOUT = 2.0


class ConnectionManager:
    def __init__(self) -> None:
        self._conns: dict[str, set[WebSocket]] = {}
        self._total = 0
        self.unauth = 0
        self.jti = JtiCache()

    @property
    def total(self) -> int:
        return self._total

    def count(self, visitor: str) -> int:
        return len(self._conns.get(visitor, ()))

    def add(self, visitor: str, ws: WebSocket) -> None:
        self._conns.setdefault(visitor, set()).add(ws)
        self._total += 1

    def remove(self, visitor: str, ws: WebSocket) -> None:
        s = self._conns.get(visitor)
        if s is not None and ws in s:
            s.discard(ws)
            self._total -= 1
            if not s:
                self._conns.pop(visitor, None)

    async def notify_visitor(self, visitor_id: str, event: dict) -> int:
        """Send `event` to every socket of the visitor; a failing socket is dropped. -> sent count."""
        sockets = list(self._conns.get(visitor_id, ()))
        if not sockets:
            return 0
        text = json.dumps(event, separators=(",", ":"))

        async def _one(ws: WebSocket) -> bool:
            try:
                await asyncio.wait_for(ws.send_text(text), SEND_TIMEOUT)
                return True
            except Exception:  # noqa: BLE001
                self.remove(visitor_id, ws)
                try:
                    await ws.close(code=1011)
                except Exception:  # noqa: BLE001
                    pass
                return False

        return sum(await asyncio.gather(*(_one(w) for w in sockets)))


_MANAGER = ConnectionManager()


def get_manager() -> ConnectionManager:
    return _MANAGER


def _origin_ok(settings: Settings, origin: str | None, aud: str | None) -> bool:
    if not origin:
        return False
    origin = origin.rstrip("/").lower()
    allowed = settings.ws_origins
    if allowed:
        return origin in allowed
    if aud:  # no configuration: the Origin must be the site the token was issued for
        parts = urlsplit(origin)
        host = parts.hostname or ""
        default = 443 if parts.scheme == "https" else 80
        key = host if parts.port in (None, default) else f"{host}:{parts.port}"
        return key == aud
    return False


async def _close(ws: WebSocket, code: int) -> None:
    try:
        if ws.application_state != WebSocketState.DISCONNECTED:
            await ws.close(code=code)
    except Exception:  # noqa: BLE001
        pass


async def serve(ws: WebSocket, settings: Settings, manager: ConnectionManager | None = None) -> None:
    """One socket: accept -> origin -> auth message (5 s) -> ready -> heartbeat loop. Never logs tokens."""
    manager = manager or _MANAGER
    await ws.accept()
    visitor: str | None = None
    code = 1000
    try:
        if not settings.ws_active:
            code = 4503
            return await _close(ws, code)
        origin = ws.headers.get("origin")
        allowed = settings.ws_origins
        if allowed and not _origin_ok(settings, origin, None):
            code = 4403
            return await _close(ws, code)
        if manager.unauth >= settings.WS_MAX_UNAUTH or manager.total >= settings.WS_MAX_TOTAL:
            code = 4429
            return await _close(ws, code)

        manager.unauth += 1
        try:
            raw = await asyncio.wait_for(ws.receive_text(), settings.WS_AUTH_TIMEOUT_SEC)
            if len(raw.encode()) > settings.WS_MAX_MESSAGE_BYTES:
                raise TokenError("too_big")
            msg = json.loads(raw)
            if not isinstance(msg, dict) or msg.get("type") != "auth":
                raise TokenError("not_auth")
            tok = verify_token(
                str(msg.get("token", "")), settings.API_SHARED_SECRET.get_secret_value(),
                max_ttl=settings.WS_TOKEN_MAX_TTL_SEC,
            )  # fmt: skip
            if settings.PLUGIN_BASE_URL:
                from app.services.ingestion.callback import site_key_for_url

                if tok.aud != site_key_for_url(settings.PLUGIN_BASE_URL, dev=True):
                    raise TokenError("wrong_site")
            if not _origin_ok(settings, origin, tok.aud):
                code = 4403
                return await _close(ws, code)
            if not manager.jti.use(tok.jti, tok.exp):
                raise TokenError("replay")
        except (asyncio.TimeoutError, TokenError, ValueError, WebSocketDisconnect, RuntimeError):
            code = 4401
            return await _close(ws, code)
        finally:
            manager.unauth -= 1

        if manager.count(tok.visitor) >= settings.WS_MAX_PER_VISITOR or manager.total >= settings.WS_MAX_TOTAL:
            code = 4429
            return await _close(ws, code)

        visitor = tok.visitor
        manager.add(visitor, ws)
        emit("ws.open", conns=manager.total, visitor=visitor[:8])
        await ws.send_text('{"type":"ready"}')
        code = await _session(ws, settings)
        await _close(ws, code)
    except WebSocketDisconnect:
        code = 1006
    except Exception:  # noqa: BLE001
        logger.exception("ws session crashed")
        code = 1011
        await _close(ws, code)
    finally:
        if visitor is not None:
            manager.remove(visitor, ws)
        emit("ws.close", code=code, conns=manager.total)


async def _session(ws: WebSocket, settings: Settings) -> int:
    start = time.monotonic()
    state = {"rx": start}
    window: list[float] = []

    async def reader() -> int:
        while True:
            raw = await ws.receive_text()
            now = time.monotonic()
            state["rx"] = now
            window[:] = [t for t in window if now - t < 1.0] + [now]
            if len(window) > settings.WS_MAX_MSG_PER_SEC or len(raw.encode()) > settings.WS_MAX_MESSAGE_BYTES:
                return 1008
            try:
                m = json.loads(raw)
            except ValueError:
                return 1008
            if not isinstance(m, dict) or m.get("type") not in ("pong", "ping"):
                return 1008  # the socket accepts nothing else: no chat logic over WS
            if m.get("type") == "ping":
                await ws.send_text('{"type":"pong"}')

    async def pinger() -> int:
        while True:
            await asyncio.sleep(settings.WS_PING_INTERVAL_SEC)
            now = time.monotonic()
            if now - start >= settings.WS_MAX_LIFETIME_SEC:
                return 1000
            if now - state["rx"] > settings.WS_IDLE_TIMEOUT_SEC:
                return 4408
            await ws.send_text('{"type":"ping"}')

    tasks = [asyncio.create_task(reader()), asyncio.create_task(pinger())]
    try:
        done, _ = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        try:
            return next(iter(done)).result()
        except (WebSocketDisconnect, RuntimeError):
            return 1006
    finally:
        for t in tasks:
            t.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
