"""Replay protection (S-6): a signed mutating request is accepted only once.

The HMAC signature covers ``timestamp + "\\n" + body`` and the timestamp is accepted for
+-AUTH_MAX_SKEW_SECONDS, so without this guard a captured request could be sent again (and
again) for that whole window. Every accepted POST/PUT/PATCH/DELETE therefore stores its
signature in `seen_signatures`; the same signature a second time -> 401 (reason="replayed").
GET (no side effects, e.g. /v1/ping) is not tracked.

Key
  * request WITH a body: the signature itself.
  * request WITHOUT a body (e.g. DELETE /v1/.../{id}): the signature does not depend on the
    path (it is HMAC of "timestamp\\n" only), so two different deletes in the same second
    would share one signature. Their key is a digest of signature + method + path + query.

Rows live until ``timestamp + 2 * AUTH_MAX_SKEW_SECONDS`` (after that the timestamp check
rejects the request anyway) and are purged by `run_janitor` every few minutes.
"""

from __future__ import annotations

import asyncio
import hashlib
import logging
import time

from sqlalchemy import delete
from sqlalchemy.exc import IntegrityError

from app.db.base import Database
from app.db.replay_models import SeenSignature

logger = logging.getLogger(__name__)

MUTATING_METHODS = frozenset({"POST", "PUT", "PATCH", "DELETE"})
PURGE_INTERVAL_SECONDS = 300


def replay_key(signature: str, method: str, target: str, body: bytes) -> str:
    """The value stored in `seen_signatures.sig` (<= 66 chars)."""
    sig = (signature or "").strip().lower()
    if body:
        return sig
    digest = hashlib.sha256(f"{sig}|{method.upper()}|{target}".encode("utf-8")).hexdigest()
    return "e:" + digest


class ReplayGuard:
    def __init__(self, db: Database):
        self.db = db
        self._ready = False
        self._lock = asyncio.Lock()

    async def _ensure_table(self) -> None:
        if self._ready:
            return
        async with self._lock:
            if self._ready:
                return
            async with self.db.engine.begin() as conn:
                await conn.run_sync(SeenSignature.__table__.create, checkfirst=True)
            self._ready = True

    async def claim(self, key: str, expires_at: int, *, now: int | None = None) -> bool:
        """Remember `key`. True = first time, False = already seen (a replay)."""
        await self._ensure_table()
        current = int(time.time() if now is None else now)
        async with self.db.session() as s:
            # an expired leftover with the same key is not a replay (the janitor was not there yet)
            await s.execute(
                delete(SeenSignature).where(
                    SeenSignature.sig == key, SeenSignature.expires_at < current
                )
            )
            s.add(SeenSignature(sig=key, expires_at=int(expires_at)))
            try:
                await s.commit()
            except IntegrityError:
                await s.rollback()
                return False
        return True

    async def purge_expired(self, *, now: int | None = None) -> int:
        await self._ensure_table()
        current = int(time.time() if now is None else now)
        async with self.db.session() as s:
            res = await s.execute(delete(SeenSignature).where(SeenSignature.expires_at < current))
            await s.commit()
            return int(res.rowcount or 0)

    async def run_janitor(self, stop: asyncio.Event) -> None:
        """Delete expired rows every PURGE_INTERVAL_SECONDS until `stop` is set."""
        while not stop.is_set():
            try:
                purged = await self.purge_expired()
                if purged:
                    logger.info("replay.purged rows=%d", purged)
            except Exception:  # noqa: BLE001 - DB hiccup: try again next round
                logger.exception("replay purge failed")
            try:
                await asyncio.wait_for(stop.wait(), timeout=PURGE_INTERVAL_SECONDS)
            except asyncio.TimeoutError:
                pass
