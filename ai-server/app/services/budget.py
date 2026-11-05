"""S-2: spending cap in USD (daily + monthly), counted locally from the providers' `usage`.

* `cost_micro_usd` turns a provider `usage` into integer micro-USD with the prices of
  MODEL_PRICES_JSON (no float money).
* Every paid call is recorded (answer rounds, summaries, question embeddings, STT, and the
  ingestion embeddings - the last one is counted but never blocked).
* `check()` says whether answering is still allowed; the effective cap of a period is the smaller
  non-zero of [the plugin's value, the .env hard cap]; both 0 = no cap.
* The first time a period passes 80 % / 100 % (flags warned80 / limited100, once per period) a
  `budget` event goes through the normal callback outbox; a new period sends level "ok".
Passing the cap by the cost of the requests already running is accepted on purpose.
"""

from __future__ import annotations

import json
import logging
import secrets
import time
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from urllib.parse import urlsplit, urlunsplit
from zoneinfo import ZoneInfo

from sqlalchemy import select, update
from sqlalchemy.dialects import postgresql, sqlite

from app.core.config import Settings
from app.core.monitoring import emit
from app.core.pricing import MICRO, ModelPrice
from app.db.base import Database
from app.db.ingest_models import CallbackDelivery, CallbackSite
from app.db.messaging_models import utcnow
from app.db.security_models import BudgetCounter, BudgetLimit

logger = logging.getLogger(__name__)

KINDS = ("day", "month")
LIMITS_TTL = 5.0  # seconds a loaded plugin limit is trusted inside one process


# ===================================================================== cost
@dataclass(slots=True)
class Usage:
    prompt_tokens: int = 0
    cached_tokens: int = 0
    completion_tokens: int = 0
    total_tokens: int = 0
    audio_seconds: float = 0.0

    @classmethod
    def from_response(cls, usage) -> Usage:
        """Provider `usage` object (or dict) -> Usage. Missing fields count as 0."""
        if usage is None:
            return cls()

        def get(obj, name):
            if obj is None:
                return None
            return obj.get(name) if isinstance(obj, dict) else getattr(obj, name, None)

        details = get(usage, "prompt_tokens_details")
        return cls(
            prompt_tokens=int(get(usage, "prompt_tokens") or 0),
            cached_tokens=int(get(details, "cached_tokens") or 0),
            completion_tokens=int(get(usage, "completion_tokens") or 0),
            total_tokens=int(get(usage, "total_tokens") or 0),
        )


def cost_micro_usd(prices: dict[str, ModelPrice], model: str, usage: Usage, *, kind: str) -> int:
    """kind: chat | embedding | stt. A model without a price costs 0 (warned once)."""
    price = prices.get(model)
    if price is None:
        _warn_unpriced(model)
        return 0
    if kind == "embedding":
        return price.embedding_micro(usage.total_tokens or usage.prompt_tokens)
    if kind == "stt":
        return price.audio_micro(usage.audio_seconds)
    return price.chat_micro(usage.prompt_tokens, usage.cached_tokens, usage.completion_tokens)


def cost_usd(prices: dict[str, ModelPrice], model: str, usage: Usage, *, kind: str = "chat") -> float:
    return cost_micro_usd(prices, model, usage, kind=kind) / MICRO


_warned: set[str] = set()


def _warn_unpriced(model: str) -> None:
    if model not in _warned:
        _warned.add(model)
        logger.warning("budget.unpriced_model model=%s (MODEL_PRICES_JSON) - counted as 0", model)


# ===================================================================== periods
@dataclass(slots=True)
class Period:
    kind: str
    key: str
    end: int  # unix time the period ends


def periods(settings: Settings, now: datetime | None = None) -> dict[str, Period]:
    tz = timezone.utc if settings.BUDGET_TIMEZONE == "UTC" else ZoneInfo(settings.BUDGET_TIMEZONE)
    local = (now or datetime.now(timezone.utc)).astimezone(tz)
    day0 = local.replace(hour=0, minute=0, second=0, microsecond=0)
    day_end = day0 + timedelta(days=1)
    m0 = day0.replace(day=1)
    m_end = (m0.replace(year=m0.year + 1, month=1) if m0.month == 12 else m0.replace(month=m0.month + 1))
    return {
        "day": Period("day", local.strftime("%Y-%m-%d"), int(day_end.timestamp())),
        "month": Period("month", local.strftime("%Y-%m"), int(m_end.timestamp())),
    }


def budget_url(callback_url: str) -> str:
    """The plugin's budget endpoint next to its results endpoint (.../ai/results -> .../ai/budget)."""
    parts = urlsplit(callback_url)
    path = parts.path.rstrip("/")
    path = path[: -len("/results")] + "/budget" if path.endswith("/results") else path + "/budget"
    return urlunsplit((parts.scheme, parts.netloc, path, "", ""))


def _level(percent: float, limited: bool = False) -> str:
    if limited or percent >= 100:
        return "limit100"
    return "warn80" if percent >= 80 else "ok"


@dataclass(slots=True)
class Exceeded:
    period: str  # day | month
    retry_after: int  # seconds until the period ends


# ===================================================================== service
class BudgetService:
    def __init__(self, settings: Settings, db: Database, *, owns_db: bool = False):
        self.settings = settings
        self.db = db
        self._owns_db = owns_db
        self._plugin: dict[str, int] = {"day": 0, "month": 0}
        self._loaded_at = 0.0
        self._known: set[tuple[str, str]] = set()  # period rows we know exist (this process)
        self._ready = False

    async def close(self) -> None:
        if self._owns_db:
            await self.db.dispose()

    async def _ensure_ready(self) -> None:
        if not self._ready:
            await self.db.init()  # creates missing tables (idempotent)
            self._ready = True

    @property
    def prices(self) -> dict[str, ModelPrice]:
        return self.settings.model_prices

    # ------------------------------------------------------------------ limits
    def _env_limit(self, kind: str) -> int:
        usd = self.settings.BUDGET_DAILY_USD_MAX if kind == "day" else self.settings.BUDGET_MONTHLY_USD_MAX
        return int(round(usd * MICRO))

    async def _load_limits(self, force: bool = False) -> None:
        if not force and time.monotonic() - self._loaded_at < LIMITS_TTL:
            return
        async with self.db.session() as s:
            rows = (await s.execute(select(BudgetLimit))).scalars().all()
        for r in rows:
            self._plugin[r.kind] = int(r.limit_micro_usd or 0)
        self._loaded_at = time.monotonic()

    async def effective_limit(self, kind: str) -> int:
        """Micro-USD cap of a period (0 = no cap): min of the non-zero [plugin, env] values."""
        await self._load_limits()
        vals = [v for v in (self._plugin.get(kind, 0), self._env_limit(kind)) if v > 0]
        return min(vals) if vals else 0

    async def set_plugin_limits(self, daily_usd: float | None, monthly_usd: float | None) -> None:
        """The limits the site owner set (sent with every chat request). None = leave as is."""
        await self._ensure_ready()
        await self._load_limits()
        changed: list[str] = []
        for kind, usd in (("day", daily_usd), ("month", monthly_usd)):
            if usd is None:
                continue
            micro = int(round(max(usd, 0.0) * MICRO))
            if micro == self._plugin.get(kind):
                continue
            self._plugin[kind] = micro
            changed.append(kind)
            ins = _insert(self.db)(BudgetLimit).values(kind=kind, limit_micro_usd=micro, updated_at=utcnow())
            stmt = ins.on_conflict_do_update(
                index_elements=[BudgetLimit.kind],
                set_={"limit_micro_usd": ins.excluded.limit_micro_usd, "updated_at": ins.excluded.updated_at},
            )
            async with self.db.session() as s:
                await s.execute(stmt)
                await s.commit()
        for kind in changed:  # a raised/lowered cap can change the level of the running period
            await self._reconcile(kind)

    # ------------------------------------------------------------------ counters
    async def _spent(self) -> dict[str, tuple[int, bool, bool]]:
        per = periods(self.settings)
        out: dict[str, tuple[int, bool, bool]] = {k: (0, False, False) for k in KINDS}
        async with self.db.session() as s:
            for kind in KINDS:
                row = (
                    await s.execute(
                        select(BudgetCounter).where(
                            BudgetCounter.kind == kind, BudgetCounter.period_key == per[kind].key
                        )
                    )
                ).scalar_one_or_none()
                if row is not None:
                    out[kind] = (int(row.spent_micro_usd), bool(row.warned80), bool(row.limited100))
        return out

    async def check(self) -> Exceeded | None:
        """Is answering still allowed? -> the exceeded period, or None."""
        try:
            await self._ensure_ready()
            per = periods(self.settings)
            spent = await self._spent()
            for kind in KINDS:
                limit = await self.effective_limit(kind)
                if limit > 0 and spent[kind][0] >= limit:
                    return Exceeded(kind, max(1, per[kind].end - int(time.time())))
        except Exception:  # noqa: BLE001 - a broken counter must not take the chat down
            logger.exception("budget.check_failed")
        return None

    async def _ensure_period(self, per: Period) -> bool:
        """Create the counter row of a period. -> True when THIS call created it (new period)."""
        if (per.kind, per.key) in self._known:
            return False
        ins = _insert(self.db)(BudgetCounter).values(
            kind=per.kind, period_key=per.key, spent_micro_usd=0, warned80=False,
            limited100=False, created_at=utcnow(),
        ).on_conflict_do_nothing(index_elements=[BudgetCounter.kind, BudgetCounter.period_key])
        async with self.db.session() as s:
            res = await s.execute(ins)
            await s.commit()
        self._known = {k for k in self._known if k[0] != per.kind}
        self._known.add((per.kind, per.key))
        return bool(res.rowcount)

    async def record(
        self, model: str, usage: Usage, *, kind: str = "chat", purpose: str = "answer"
    ) -> int:
        """Add the cost of one paid call. Never raises. -> micro-USD added."""
        try:
            cost = cost_micro_usd(self.prices, model, usage, kind=kind)
            await self._ensure_ready()
            per = periods(self.settings)
            for k in KINDS:
                created = await self._ensure_period(per[k])
                if created and await self.effective_limit(k) > 0:
                    await self._send(per[k], "ok", 0, await self.effective_limit(k))
            if cost > 0:
                for k in KINDS:
                    await self._add(per[k], cost)
                for k in KINDS:
                    await self._reconcile(k)
            emit("budget.cost", purpose=purpose, model=model, micro_usd=cost)
            return cost
        except Exception:  # noqa: BLE001
            logger.exception("budget.record_failed model=%s", model)
            return 0

    async def _add(self, per: Period, cost: int) -> None:
        stmt = (
            update(BudgetCounter)
            .where(BudgetCounter.kind == per.kind, BudgetCounter.period_key == per.key)
            .values(spent_micro_usd=BudgetCounter.spent_micro_usd + cost)  # atomic, integer
        )
        async with self.db.session() as s:
            await s.execute(stmt)
            await s.commit()

    async def _reconcile(self, kind: str) -> None:
        """Compare spent/limit with the stored flags; a level change flips the flags ONCE (the
        UPDATE only matches when they still hold the old value) and sends one event."""
        per = periods(self.settings)[kind]
        limit = await self.effective_limit(kind)
        await self._ensure_period(per)
        async with self.db.session() as s:
            row = (
                await s.execute(
                    select(BudgetCounter).where(
                        BudgetCounter.kind == kind, BudgetCounter.period_key == per.key
                    )
                )
            ).scalar_one_or_none()
        if row is None:
            return
        spent = int(row.spent_micro_usd)
        percent = (spent * 100.0 / limit) if limit > 0 else 0.0
        level = _level(percent) if limit > 0 else "ok"
        want80 = level in ("warn80", "limit100")
        want100 = level == "limit100"
        if want80 == bool(row.warned80) and want100 == bool(row.limited100):
            return
        async with self.db.session() as s:
            res = await s.execute(
                update(BudgetCounter)
                .where(
                    BudgetCounter.kind == kind,
                    BudgetCounter.period_key == per.key,
                    BudgetCounter.warned80 == bool(row.warned80),
                    BudgetCounter.limited100 == bool(row.limited100),
                )
                .values(warned80=want80, limited100=want100)
            )
            await s.commit()
        if res.rowcount:  # we won the flip: nobody else sends this event
            await self._send(per, level, spent, limit)

    # ------------------------------------------------------------------ push to the site
    async def _send(self, per: Period, level: str, spent: int, limit: int) -> None:
        """Queue a `budget` callback for every registered site (same outbox/HMAC as answers)."""
        percent = int(spent * 100 // limit) if limit > 0 else 0
        item = {
            "period": per.kind,
            "level": level,
            "used_usd": round(spent / MICRO, 4),
            "limit_usd": round(limit / MICRO, 4),
            "percent": percent,
            "period_end": per.end,
        }
        body = json.dumps(item, separators=(",", ":"))
        async with self.db.session() as s:
            sites = (await s.execute(select(CallbackSite))).scalars().all()
            for site in sites:
                s.add(
                    CallbackDelivery(
                        site_key=site.host,
                        callback_url=budget_url(site.callback_url),
                        job_id="g_" + secrets.token_hex(6),
                        record_id=f"budget:{per.kind}:{per.key}:{level}",
                        result_status="trained",  # table check constraint: trained = ok
                        kind="budget",
                        payload=body,
                        available_at=utcnow(),
                    )
                )
            await s.commit()
        logger.warning(
            "budget.level period=%s level=%s used_usd=%s limit_usd=%s sites=%d",
            per.kind, level, item["used_usd"], item["limit_usd"], len(sites),
        )
        emit("budget.level", **item)


def _insert(db: Database):
    return (postgresql if db.engine.dialect.name == "postgresql" else sqlite).insert


# ===================================================================== process-wide access
_DEFAULT: BudgetService | None = None


def set_default(service: BudgetService | None) -> None:
    global _DEFAULT
    _DEFAULT = service


def get_budget(settings: Settings) -> BudgetService:
    """The budget service of this process (the API shares the messaging database; a worker
    process builds its own on first use)."""
    global _DEFAULT
    if _DEFAULT is None:
        _DEFAULT = BudgetService(settings, Database.from_settings(settings), owns_db=True)
    return _DEFAULT
