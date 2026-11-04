"""Report for the plugin's monitoring page (POST /v1/get_monitoring).

Pure functions (no FastAPI): `build_view` wraps `monitor_report.build_report` with what a dashboard
needs on top of the plain report: the full time axis (empty buckets included, future buckets
removed), previous/next period for navigation, which days have data, and a small in-memory cache
(so opening the page twice in a row, or two admins, do not read the files twice).
"""

from __future__ import annotations

import json
import threading
import time
from datetime import date, timedelta
from pathlib import Path

from app.core import monitor_report as rep

PERIODS = ("day", "month", "year")
CALENDARS = ("gregorian", "jalali")
# period -> allowed series units (first = default)
UNITS = {"day": ("hour",), "month": ("day",), "year": ("month", "day")}

_CACHE_MAX = 48
_TTL_OPEN = 15.0  # period that still contains "today": new events keep arriving
_TTL_CLOSED = 600.0  # period that is completely in the past
_cache: dict[str, tuple[float, dict]] = {}
_lock = threading.Lock()


def _label(period: str, d: date, calendar: str) -> str:
    iso = d.isoformat()
    if period == "day":
        return rep.day_label(iso, calendar)
    if period == "month":
        return rep.month_label(iso, calendar)
    return rep.year_label(iso, calendar)


def _axis(unit: str, first: date, last: date, calendar: str, offset_min: int, now: float) -> list[str]:
    """Every bucket key of the period, in order, up to the current moment."""
    local_now = rep.local_dt(now, offset_min)
    today = local_now.date()
    keys: list[str] = []
    d = first
    while d <= min(last, today):
        iso = d.isoformat()
        if unit == "hour":
            for h in range(24):
                if d == today and h > local_now.hour:
                    break
                keys.append(f"{iso} {h:02d}:00")
        else:
            key = rep.month_label(iso, calendar) if unit == "month" else rep.day_label(iso, calendar)
            if not keys or keys[-1] != key:
                keys.append(key)
        d += timedelta(days=1)
    return keys


# Per-bucket detail the dashboard never charts (it only needs counters, status maps and latency
# summaries per bucket); dropping it keeps the response small (day: 24 buckets, year: 12-365).
_HEAVY = {"events", "routes", "by_model", "by_tool", "by_site", "by_ip", "by_logger", "by_exception",
          "by_reason", "by_kind", "by_svc", "by_op", "by_purpose", "by_outcome", "by_ip"}  # fmt: skip


def _slim(node):
    if isinstance(node, dict):
        return {k: _slim(v) for k, v in node.items() if k not in _HEAVY}
    return node


def _earliest(base: Path) -> date | None:
    """Oldest day that has raw files or a roll-up."""
    found: list[str] = []
    days = rep.list_days(base)
    if days:
        found.append(days[0])
    roll = base / "rollups"
    if roll.is_dir():
        names = sorted(p.stem for y in roll.iterdir() if y.is_dir() for p in y.glob("*.json"))
        names = [n for n in names if len(n) == 10 and n[4] == "-"]
        if names:
            found.append(names[0])
    if not found:
        return None
    try:
        return date.fromisoformat(min(found))
    except ValueError:
        return None


def build_view(
    base: Path,
    *,
    period: str,
    text: str | None,
    calendar: str,
    unit: str | None,
    offset_min: int,
    prices: dict[str, tuple[float, float]] | None = None,
    now: float | None = None,
    use_cache: bool = True,
) -> dict:
    """Raises ValueError for a bad period/date/unit."""
    now = time.time() if now is None else now
    if period not in PERIODS:
        raise ValueError(f"unknown period {period!r}")
    if calendar not in CALENDARS:
        raise ValueError(f"unknown calendar {calendar!r}")
    allowed = UNITS[period]
    if unit is not None and unit not in allowed:
        raise ValueError(f"unit {unit!r} is not available for period {period!r}")
    unit = unit or allowed[0]

    first, last, label = rep.resolve_period(period, text, calendar, offset_min, now)
    today = rep.local_dt(now, offset_min).date()
    if first > today:
        raise ValueError("this period is in the future")

    key = json.dumps(
        [str(base), period, first.isoformat(), last.isoformat(), calendar, unit, offset_min,
         sorted((prices or {}).items())],
        sort_keys=True,
    )  # fmt: skip
    if use_cache:
        with _lock:
            hit = _cache.get(key)
        if hit and hit[0] > now:
            return {**hit[1], "cached": True}

    report = rep.build_report(
        base, first, last, unit=unit, calendar=calendar, offset_min=offset_min, prices=prices
    )
    earliest = _earliest(base)
    prev_day = first - timedelta(days=1)
    next_day = last + timedelta(days=1)
    nav = {
        "prev": _label(period, prev_day, calendar) if earliest and prev_day >= earliest else None,
        "next": _label(period, next_day, calendar) if next_day <= today else None,
    }
    view = {
        **report,
        "series": [_slim(b) for b in report["series"]],
        "kind": period,
        "label": label,
        "unit": unit,
        "axis": _axis(unit, first, last, calendar, offset_min, now),
        "nav": nav,
        "today": rep.day_label(today.isoformat(), calendar),
        "earliest_day": rep.day_label(earliest.isoformat(), calendar) if earliest else None,
        "is_current": first <= today <= last,
        "generated_at": int(now),
        "cached": False,
    }
    ttl = _TTL_OPEN if view["is_current"] else _TTL_CLOSED
    with _lock:
        if len(_cache) >= _CACHE_MAX:
            for k in sorted(_cache, key=lambda k: _cache[k][0])[: _CACHE_MAX // 2]:
                _cache.pop(k, None)
        _cache[key] = (now + ttl, view)
    return view
