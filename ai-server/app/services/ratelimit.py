"""S-1: atomic fixed-window rate limits + keyed IP hashing + the security janitor.

Counting is ONE statement (INSERT ... ON CONFLICT DO UPDATE ... RETURNING n), so two parallel
requests can never both slip under the cap. Works the same on SQLite (>= 3.35) and PostgreSQL.
"""

from __future__ import annotations

import hashlib
import hmac
import ipaddress
import logging
import time
from datetime import timedelta

from sqlalchemy import case, delete, update
from sqlalchemy.dialects import postgresql, sqlite

from app.core.config import Settings
from app.core.errors import RateLimitedError
from app.db.base import Database
from app.db.messaging_models import Message, utcnow
from app.db.security_models import BudgetCounter, RateLimit

logger = logging.getLogger(__name__)

WINDOW_SECONDS = 60


def valid_ip(value: str | None) -> str | None:
    """A literal IPv4/IPv6 address (normalized) or None."""
    if not value:
        return None
    try:
        return str(ipaddress.ip_address(value.strip()))
    except ValueError:
        return None


def hash_ip(settings: Settings, ip: str | None) -> str | None:
    """HMAC-SHA256 keyed with HMAC(API_SHARED_SECRET, "ip-hash"), first 32 hex chars.
    A plain hash of an IPv4 address could be brute-forced; the keyed one cannot."""
    ip = valid_ip(ip)
    if ip is None:
        return None
    secret = settings.API_SHARED_SECRET.get_secret_value().encode()
    key = hmac.new(secret, b"ip-hash", hashlib.sha256).digest()
    return hmac.new(key, ip.encode(), hashlib.sha256).hexdigest()[:32]


def _insert(db: Database):
    return (postgresql if db.engine.dialect.name == "postgresql" else sqlite).insert


class RateLimiter:
    def __init__(self, settings: Settings, db: Database, *, clock=time.time):
        self.settings = settings
        self.db = db
        self._clock = clock

    async def hit(self, key: str) -> tuple[int, int]:
        """Count one event in the current window -> (count in this window, seconds until it ends)."""
        now = self._clock()
        window = int(now // WINDOW_SECONDS)
        ins = _insert(self.db)(RateLimit).values(key=key, window=window, n=1)
        stmt = ins.on_conflict_do_update(
            index_elements=[RateLimit.key],
            set_={
                "n": case((RateLimit.window == ins.excluded.window, RateLimit.n + 1), else_=1),
                "window": ins.excluded.window,
            },
        ).returning(RateLimit.n)
        async with self.db.session() as s:
            n = (await s.execute(stmt)).scalar_one()
            await s.commit()
        return int(n), int((window + 1) * WINDOW_SECONDS - now) + 1

    async def check_message(self, *, ip_hash: str | None, visitor_uid: str | None) -> None:
        """IP counter first, then the visitor counter. Over a cap -> RateLimitedError (429)."""
        s = self.settings
        if ip_hash:
            n, wait = await self.hit(f"ip:{ip_hash}")
            if n > s.RATE_PER_IP_PER_MIN:
                raise RateLimitedError("Too many messages from this address", retry_after=wait)
        if visitor_uid:
            n, wait = await self.hit(f"visitor:{visitor_uid}")
            if n > s.RATE_PER_VISITOR_PER_MIN:
                raise RateLimitedError("Too many messages from this visitor", retry_after=wait)


async def purge(db: Database, settings: Settings) -> dict[str, int]:
    """Janitor: old rate-limit rows, old IP hashes, old budget periods."""
    now = utcnow()
    day_window = int(time.time() // WINDOW_SECONDS) - 24 * 60  # window older than 1 day
    cutoff = now - timedelta(days=settings.IP_HASH_RETENTION_DAYS)
    async with db.session() as s:
        rl = await s.execute(delete(RateLimit).where(RateLimit.window < day_window))
        ips = await s.execute(
            update(Message)
            .where(Message.ip_hash.is_not(None), Message.created_at < cutoff)
            .values(ip_hash=None)
        )
        old_days = (now - timedelta(days=60)).strftime("%Y-%m-%d")
        old_months = (now - timedelta(days=800)).strftime("%Y-%m")
        bc = await s.execute(
            delete(BudgetCounter).where(
                ((BudgetCounter.kind == "day") & (BudgetCounter.period_key < old_days))
                | ((BudgetCounter.kind == "month") & (BudgetCounter.period_key < old_months))
            )
        )
        await s.commit()
    out = {
        "rate_limits": int(rl.rowcount or 0),
        "ip_hashes": int(ips.rowcount or 0),
        "budget_periods": int(bc.rowcount or 0),
    }
    if any(out.values()):
        logger.info("security.purged %s", out)
    return out
