"""Reading, summarizing and exporting the monitoring JSONL files (stdlib only, no app imports).

Used by
  * `app.core.monitoring` (daily roll-ups before raw files are compressed / deleted), and
  * `scripts/monitor_export.py` (daily / monthly / yearly / custom reports, raw exports).

Layout on disk (see MONITORING.md):
    <base>/<YYYY>/<MM>/<day>.<proc>.jsonl[.gz]     raw events, one JSON object per line
    <base>/rollups/<YYYY>/<day>.json               one small summary per finished day (kept forever)

Summaries are plain nested dicts that can be MERGED (counters add, `max`/`*_max` take the maximum,
`*_min` the minimum, `*_last` the newest value, histograms add bucket by bucket), so a month or a
year is the merge of its days and gives the same numbers as reading every raw line.
"""

from __future__ import annotations

import csv
import gzip
import json
import math
import os
import re
from collections.abc import Iterator
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any

SCHEMA = 1

# latency / duration buckets (upper bounds, ms); one more bucket for "larger than all"
BUCKETS = (5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000, 120000, 600000)
NB = len(BUCKETS) + 1
_MAX_KEYS = 200  # dimensions with unbounded values (ips, sites, loggers...) keep this many keys

_DAY_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


# ============================================================================ time / calendars
def parse_offset(text: str) -> int:
    """'+03:30' -> 210 (minutes)."""
    m = re.fullmatch(r"([+-])(\d{1,2}):?(\d{2})", (text or "+00:00").strip())
    if not m:
        raise ValueError(f"bad UTC offset {text!r} (use e.g. +03:30)")
    return (-1 if m.group(1) == "-" else 1) * (int(m.group(2)) * 60 + int(m.group(3)))


def local_dt(epoch: float, offset_min: int) -> datetime:
    return datetime.fromtimestamp(epoch + offset_min * 60, tz=timezone.utc)


def day_of(epoch: float, offset_min: int) -> str:
    return local_dt(epoch, offset_min).strftime("%Y-%m-%d")


_G_DAYS = (0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334)


def gregorian_to_jalali(gy: int, gm: int, gd: int) -> tuple[int, int, int]:
    """Same algorithm as app/services/chunking/jalali.py (copied: this module has no imports)."""
    gy2 = gy + 1 if gm > 2 else gy
    days = (
        355666 + 365 * gy + (gy2 + 3) // 4 - (gy2 + 99) // 100 + (gy2 + 399) // 400
        + gd + _G_DAYS[gm - 1]
    )  # fmt: skip
    jy = -1595 + 33 * (days // 12053)
    days %= 12053
    jy += 4 * (days // 1461)
    days %= 1461
    if days > 365:
        jy += (days - 1) // 365
        days = (days - 1) % 365
    if days < 186:
        jm, jd = 1 + days // 31, 1 + days % 31
    else:
        jm, jd = 7 + (days - 186) // 30, 1 + (days - 186) % 30
    return jy, jm, jd


def jalali_to_gregorian(jy: int, jm: int, jd: int) -> date:
    """Inverse of `gregorian_to_jalali` (starts near the answer and walks day by day)."""
    off = (jm - 1) * 31 if jm <= 7 else 186 + (jm - 7) * 30
    d = date(jy + 621, 3, 21) + timedelta(days=off + jd - 1)
    target = (jy, jm, jd)
    for _ in range(12):
        cur = gregorian_to_jalali(d.year, d.month, d.day)
        if cur == target:
            return d
        d += timedelta(days=1 if cur < target else -1)
    raise ValueError(f"invalid Jalali date {jy}-{jm:02d}-{jd:02d}")


def month_label(day: str, calendar: str) -> str:
    y, m, d = (int(x) for x in day.split("-"))
    if calendar == "jalali":
        jy, jm, _ = gregorian_to_jalali(y, m, d)
        return f"{jy}-{jm:02d}"
    return f"{y}-{m:02d}"


def year_label(day: str, calendar: str) -> str:
    return month_label(day, calendar)[:4]


def day_label(day: str, calendar: str) -> str:
    if calendar != "jalali":
        return day
    y, m, d = (int(x) for x in day.split("-"))
    jy, jm, jd = gregorian_to_jalali(y, m, d)
    return f"{jy}-{jm:02d}-{jd:02d}"


def resolve_period(
    period: str, text: str | None, calendar: str, offset_min: int, now: float
) -> tuple[date, date, str]:
    """-> (first day, last day INCLUSIVE, label); days are local days in `calendar`'s labels but
    returned as Gregorian dates. `text`: 2026-11-03 | 2026-11 | 2026 (Jalali digits when
    calendar == "jalali"); None = the current day/month/year."""
    today = local_dt(now, offset_min).date()
    jalali = calendar == "jalali"

    def parse(t: str) -> list[int]:
        try:
            return [int(x) for x in t.strip().split("-")]
        except ValueError as exc:
            raise ValueError(f"bad date {t!r}") from exc

    if period == "day":
        if text:
            y, m, d = parse(text)
            first = jalali_to_gregorian(y, m, d) if jalali else date(y, m, d)
        else:
            first = today
        return first, first, day_label(first.isoformat(), calendar)
    if period == "month":
        if text:
            y, m = parse(text)[:2]
        elif jalali:
            y, m, _ = gregorian_to_jalali(today.year, today.month, today.day)
        else:
            y, m = today.year, today.month
        ny, nm = (y + 1, 1) if m == 12 else (y, m + 1)
        if jalali:
            first, nxt = jalali_to_gregorian(y, m, 1), jalali_to_gregorian(ny, nm, 1)
        else:
            first, nxt = date(y, m, 1), date(ny, nm, 1)
        return first, nxt - timedelta(days=1), f"{y}-{m:02d}"
    if period == "year":
        if text:
            y = parse(text)[0]
        elif jalali:
            y = gregorian_to_jalali(today.year, today.month, today.day)[0]
        else:
            y = today.year
        if jalali:
            first, nxt = jalali_to_gregorian(y, 1, 1), jalali_to_gregorian(y + 1, 1, 1)
        else:
            first, nxt = date(y, 1, 1), date(y + 1, 1, 1)
        return first, nxt - timedelta(days=1), f"{y}"
    raise ValueError(f"unknown period {period!r}")


# ============================================================================ files
def _day_dir(base: Path, day: str) -> Path:
    return base / day[:4] / day[5:7]


def day_files(base: Path, day: str) -> list[Path]:
    d = _day_dir(base, day)
    if not d.is_dir():
        return []
    return sorted(
        p for p in d.glob(f"{day}.*") if p.name.endswith((".jsonl", ".jsonl.gz"))
    )


def list_days(base: Path) -> list[str]:
    """Every day that has raw files, ascending."""
    days: set[str] = set()
    if not base.is_dir():
        return []
    for year in base.iterdir():
        if not (year.is_dir() and year.name.isdigit()):
            continue
        for month in year.iterdir():
            if not (month.is_dir() and month.name.isdigit()):
                continue
            for p in month.iterdir():
                name = p.name
                if name.endswith((".jsonl", ".jsonl.gz")) and _DAY_RE.match(name[:10]):
                    days.add(name[:10])
    return sorted(days)


def rollup_path(base: Path, day: str) -> Path:
    return base / "rollups" / day[:4] / f"{day}.json"


def load_rollup(base: Path, day: str) -> dict | None:
    try:
        data = json.loads(rollup_path(base, day).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None
    return data if isinstance(data, dict) and data.get("schema") == SCHEMA else None


def iter_records(files: list[Path], stats: dict | None = None) -> Iterator[dict]:
    """Every JSON object of the files; broken lines (a crash mid-write) are counted and skipped."""
    for path in files:
        opener = gzip.open if path.name.endswith(".gz") else open
        try:
            with opener(path, "rt", encoding="utf-8", errors="replace") as fh:
                for line in fh:
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        rec = json.loads(line)
                    except ValueError:
                        if stats is not None:
                            stats["bad_lines"] = stats.get("bad_lines", 0) + 1
                        continue
                    if isinstance(rec, dict):
                        yield rec
        except (OSError, EOFError):  # unreadable / truncated gzip: use what was read
            if stats is not None:
                stats["bad_files"] = stats.get("bad_files", 0) + 1


# ============================================================================ aggregation
def _num(v: Any) -> float | None:
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)) and math.isfinite(v):
        return float(v)
    return None


def _node(d: dict, *path: str) -> dict:
    for p in path:
        d = d.setdefault(p, {})
    return d


def _add(d: dict, key: str, v: float = 1) -> None:
    d[key] = d.get(key, 0) + v


def _mx(d: dict, key: str, v: float | None) -> None:
    if v is not None and v > d.get(key, -math.inf):
        d[key] = v


def _mn(d: dict, key: str, v: float | None) -> None:
    if v is not None and v < d.get(key, math.inf):
        d[key] = v


def _ckey(d: dict, key: Any, cap: int = _MAX_KEYS) -> str:
    key = str(key)[:80] if key not in (None, "") else "(none)"
    return key if key in d or len(d) < cap else "(other)"


def _lat(d: dict, key: str, v: Any) -> None:
    x = _num(v)
    if x is None:
        return
    h = d.setdefault(key, {"n": 0, "sum": 0.0, "max": 0.0, "h": [0] * NB})
    h["n"] += 1
    h["sum"] += x
    h["max"] = max(h["max"], x)
    i = 0
    while i < len(BUCKETS) and x > BUCKETS[i]:
        i += 1
    h["h"][i] += 1


def _is_hist(d: Any) -> bool:
    return isinstance(d, dict) and {"n", "sum", "max", "h"} <= d.keys() and isinstance(d["h"], list)


def merge(a: dict, b: dict) -> dict:
    """Merge summary `b` into `a` (in place) and return `a`. `b` is the NEWER one."""
    for k, v in b.items():
        if isinstance(v, dict):
            tgt = a.get(k)
            if not isinstance(tgt, dict):
                a[k] = tgt = {}
            merge(tgt, v)
        elif k == "h" and isinstance(v, list):
            cur = a.get("h")
            a["h"] = [x + y for x, y in zip(cur, v)] if isinstance(cur, list) else list(v)
        elif k == "max" or k.endswith("_max"):
            a[k] = max(a.get(k, v), v)
        elif k.endswith("_min"):
            a[k] = min(a.get(k, v), v)
        elif k.endswith("_last"):
            a[k] = v
        elif isinstance(v, (int, float)):
            a[k] = a.get(k, 0) + v
        else:
            a[k] = v
    return a


class Aggregator:
    """Turns event records into a mergeable summary (`self.s`)."""

    def __init__(self) -> None:
        self.s: dict = {}

    # ------------------------------------------------------------------ entry point
    def add(self, rec: dict) -> None:
        ev = rec.get("event")
        if not isinstance(ev, str):
            return
        s = self.s
        _add(s, "records")
        _add(_node(s, "events"), _ckey(s.get("events", {}), ev, 400))
        fn = _HANDLERS.get(ev)
        if fn is not None:
            fn(self, rec)

    # ------------------------------------------------------------------ handlers
    def _http(self, r: dict) -> None:
        h = _node(self.s, "http")
        _add(h, "requests")
        status = int(_num(r.get("status")) or 0)
        _add(_node(h, "status"), f"{status // 100}xx" if status else "other")
        _lat(h, "lat", r.get("ms"))
        _add(h, "bytes_in", _num(r.get("bytes_in")) or 0)
        _add(h, "bytes_out", _num(r.get("bytes_out")) or 0)
        routes = _node(h, "routes")
        rn = routes.setdefault(
            _ckey(routes, f"{r.get('method', '-')} {r.get('route', '-')}"), {}
        )
        _add(rn, "n")
        if status >= 500:
            _add(rn, "err5xx")
        elif status >= 400:
            _add(rn, "err4xx")
        _lat(rn, "lat", r.get("ms"))

    def _auth(self, r: dict) -> None:
        a = _node(self.s, "security")
        _add(a, "auth_rejected")
        by = _node(a, "by_reason")
        _add(by, _ckey(by, r.get("reason")))
        ips = _node(a, "by_ip")
        if r.get("ip"):
            _add(ips, _ckey(ips, r.get("ip"), 50))

    def _problem(self, r: dict) -> None:
        p = _node(self.s, "problems")
        level = str(r.get("level", "")).lower()
        _add(p, "error" if level in ("error", "critical") else "warning")
        lg = _node(p, "by_logger")
        _add(lg, _ckey(lg, r.get("logger"), 60))
        if r.get("exc_type"):
            ex = _node(p, "by_exception")
            _add(ex, _ckey(ex, r.get("exc_type"), 60))

    def _llm(self, r: dict) -> None:
        g = _node(self.s, "llm")
        ok = r.get("status") == "ok"
        _add(g, "calls")
        if not ok:
            _add(g, "errors")
        for f in ("prompt_tokens", "completion_tokens", "total_tokens", "cached_tokens",
                  "reasoning_tokens"):  # fmt: skip
            _add(g, f, _num(r.get(f)) or 0)
        _lat(g, "lat", r.get("ms"))
        m = _node(g, "by_model")
        mn = m.setdefault(_ckey(m, r.get("model"), 50), {})
        _add(mn, "calls")
        if not ok:
            _add(mn, "errors")
        for f in ("prompt_tokens", "completion_tokens", "total_tokens", "cached_tokens",
                  "reasoning_tokens", "tool_calls"):  # fmt: skip
            _add(mn, f, _num(r.get(f)) or 0)
        _lat(mn, "lat", r.get("ms"))
        _add(_node(mn, "by_purpose"), str(r.get("purpose") or "answer"))
        if r.get("finish"):
            _add(_node(g, "finish"), str(r["finish"])[:24])
        if not ok and r.get("error_type"):
            ex = _node(g, "error_types")
            _add(ex, _ckey(ex, r["error_type"], 40))

    def _embedding(self, r: dict) -> None:
        e = _node(self.s, "embedding")
        ok = r.get("status") == "ok"
        _add(e, "requests")
        if not ok:
            _add(e, "errors")
        _add(e, "inputs", _num(r.get("inputs")) or 0)
        _add(e, "tokens", _num(r.get("total_tokens")) or _num(r.get("prompt_tokens")) or 0)
        _lat(e, "lat", r.get("ms"))
        m = _node(e, "by_model")
        mn = m.setdefault(_ckey(m, r.get("model"), 20), {})
        _add(mn, "requests")
        _add(mn, "tokens", _num(r.get("total_tokens")) or _num(r.get("prompt_tokens")) or 0)
        op = _node(e, "by_op")
        on = op.setdefault(_ckey(op, r.get("op"), 10), {})
        _add(on, "requests")
        _add(on, "tokens", _num(r.get("total_tokens")) or _num(r.get("prompt_tokens")) or 0)
        _lat(on, "lat", r.get("ms"))

    def _stt(self, r: dict) -> None:
        t = _node(self.s, "stt")
        _add(t, "calls")
        _add(_node(t, "status"), str(r.get("status") or "?"))
        _add(t, "bytes", _num(r.get("bytes")) or 0)
        _add(t, "chars", _num(r.get("chars")) or 0)
        _lat(t, "lat", r.get("ms"))

    def _tool(self, r: dict) -> None:
        t = _node(self.s, "tools")
        _add(t, "calls")
        if r.get("status") != "ok":
            _add(t, "errors")
        _lat(t, "lat", r.get("ms"))
        k = _node(t, "by_kind")
        kn = k.setdefault(_ckey(k, r.get("kind"), 10), {})
        _add(kn, "calls")
        if r.get("status") != "ok":
            _add(kn, "errors")
        _lat(kn, "lat", r.get("ms"))
        names = _node(t, "by_tool")
        nn = names.setdefault(_ckey(names, r.get("tool"), 100), {})
        _add(nn, "calls")
        if r.get("status") != "ok":
            _add(nn, "errors")

    def _site_request(self, r: dict) -> None:
        t = _node(self.s, "site_api")
        k = _node(t, "by_kind")
        kn = k.setdefault(_ckey(k, r.get("kind"), 10), {})
        _add(kn, "calls")
        _add(_node(kn, "outcomes"), str(r.get("outcome") or "?")[:24])
        _lat(kn, "lat", r.get("ms"))

    def _rag(self, r: dict) -> None:
        g = _node(self.s, "rag")
        _add(g, "queries")
        if not (_num(r.get("loaded")) or 0):
            _add(g, "empty")
        _add(g, "out_of_sync", _num(r.get("missing")) or 0)
        _lat(g, "lat", r.get("ms"))
        top = _num(r.get("top_score"))
        if top is not None:
            _add(g, "top_score_sum", top)
            _add(g, "top_score_n")
            _add(_node(g, "top_score_bucket"), f"{min(int(top * 10), 9) / 10:.1f}")

    def _chat_message(self, r: dict) -> None:
        c = _node(self.s, "chat")
        _add(_node(c, "messages"), str(r.get("role") or "?"))
        if r.get("created_conversation"):
            _add(c, "conversations_created")
        _add(_node(c, "visitors"), "logged_in" if r.get("logged_in") else "guest")
        if r.get("wants_answer"):
            _add(c, "answers_requested")
        _add(c, "chars", _num(r.get("chars")) or 0)
        if r.get("attachment_type"):
            a = _node(c, "attachments", str(r["attachment_type"]))
            _add(a, str(r.get("attachment_status") or "?"))

    def _chat_delete(self, r: dict) -> None:
        c = _node(self.s, "chat", "deletes")
        _add(c, str(r.get("scope") or "?"))
        _add(c, "messages", _num(r.get("count")) or 0)

    def _answer(self, r: dict) -> None:
        a = _node(self.s, "answers")
        _add(a, "total")
        _add(_node(a, "status"), str(r.get("status") or "?"))
        _add(_node(a, "mode"), str(r.get("mode") or "?"))
        _add(_node(a, "question_type"), str(r.get("question_type") or "text"))
        _lat(a, "lat", r.get("ms"))
        _lat(a, "history_tokens", r.get("history_tokens"))
        _lat(a, "answer_chars", r.get("answer_chars"))
        if r.get("summary_scheduled"):
            _add(a, "summaries_scheduled")
        calls = int(_num(r.get("llm_calls")) or 0)
        _add(_node(a, "llm_calls"), str(min(calls, 9)))
        if (_num(r.get("tool_calls")) or 0) > 0:
            _add(a, "with_tools")
        for f in ("prompt_tokens", "completion_tokens", "total_tokens", "tool_calls",
                  "tool_errors", "embedding_tokens"):  # fmt: skip
            _add(a, f, _num(r.get(f)) or 0)
        m = _node(a, "by_model")
        mn = m.setdefault(_ckey(m, r.get("model"), 50), {})
        _add(mn, "total")
        if r.get("status") != "generated":
            _add(mn, "failed")
        _lat(mn, "lat", r.get("ms"))
        _add(mn, "total_tokens", _num(r.get("total_tokens")) or 0)
        if r.get("status") != "generated" and r.get("error_type"):
            ex = _node(a, "error_types")
            _add(ex, _ckey(ex, r["error_type"], 40))

    def _answer_job(self, r: dict) -> None:
        j = _node(self.s, "answer_jobs")
        _add(_node(j, "phase"), str(r.get("phase") or "?"))
        _lat(j, "queue_wait", r.get("queue_wait_ms"))
        if r.get("phase") in ("done", "failed"):
            _lat(j, "total", r.get("total_ms"))

    def _summary_job(self, r: dict) -> None:
        j = _node(self.s, "summary_jobs")
        _add(_node(j, "phase"), str(r.get("phase") or "?"))
        if r.get("outcome"):
            _add(_node(j, "outcome"), str(r["outcome"])[:40])
        _lat(j, "lat", r.get("ms"))
        _lat(j, "queue_wait", r.get("queue_wait_ms"))

    def _ingest_enqueued(self, r: dict) -> None:
        i = _node(self.s, "ingest")
        _add(i, "requests")
        _add(i, "enqueued_jobs", _num(r.get("jobs")) or 0)
        _add(i, "superseded", _num(r.get("superseded")) or 0)
        kinds = r.get("kinds")
        if isinstance(kinds, dict):
            k = _node(i, "enqueued_by_kind")
            for name, n in kinds.items():
                _add(k, _ckey(k, name, 30), _num(n) or 0)

    def _ingest_job(self, r: dict) -> None:
        i = _node(self.s, "ingest")
        phase = str(r.get("phase") or "?")
        _add(_node(i, "phase"), phase)
        kind = str(r.get("kind") or "?")
        k = _node(i, "by_kind")
        kn = k.setdefault(_ckey(k, kind, 30), {})
        _add(kn, phase)
        if phase == "retry":
            return
        _lat(i, "lat", r.get("ms"))
        _lat(i, "queue_wait", r.get("queue_wait_ms"))
        _lat(kn, "lat", r.get("ms"))
        if phase == "done":
            _add(_node(i, "status"), str(r.get("status") or r.get("outcome") or "?"))
            for f in ("chunks", "embedded_chunks", "reused_chunks", "tokens", "embedding_tokens"):
                _add(i, f, _num(r.get(f)) or 0)
        if phase == "failed" and r.get("code"):
            ex = _node(i, "error_codes")
            _add(ex, _ckey(ex, r["code"], 40))

    def _callback_send(self, r: dict) -> None:
        c = _node(self.s, "callbacks")
        kind = str(r.get("kind") or "results")
        if kind == "ping":
            _add(_node(c, "pings"), "ok" if r.get("outcome") == "ok" else "failed")
            return
        if kind == "retry":  # follow-up of a failed send: only the "dead" count is new
            _add(c, "dead", _num(r.get("dead")) or 0)
            return
        _add(c, "sends")
        outcome = str(r.get("outcome") or "?")
        _add(_node(c, "by_outcome"), outcome)
        _lat(c, "lat", r.get("ms"))
        if outcome == "ok":
            _add(c, "results_delivered", _num(r.get("results")) or 0)
            _add(c, "answers_delivered", _num(r.get("answers")) or 0)
        sites = _node(c, "by_site")
        sn = sites.setdefault(_ckey(sites, r.get("site"), 50), {})
        _add(sn, "sends")
        if outcome != "ok":
            _add(sn, "failed")
        _lat(sn, "lat", r.get("ms"))

    def _site_state(self, r: dict) -> None:
        _add(_node(self.s, "callbacks", "site_state_changes"), str(r.get("state") or "?"))

    def _queue(self, r: dict) -> None:
        q = _node(self.s, "queues")
        _add(q, "samples")
        for k, v in r.items():
            x = _num(v)
            if x is None or k in ("v", "pid"):
                continue
            _mx(q, f"{k}_max", x)
            q[f"{k}_last"] = x

    def _storage(self, r: dict) -> None:
        q = _node(self.s, "storage")
        for k, v in r.items():
            x = _num(v)
            if x is None or k in ("v", "pid"):
                continue
            _mx(q, f"{k}_max", x)
            _mn(q, f"{k}_min", x)
            q[f"{k}_last"] = x

    def _system(self, r: dict) -> None:
        sy = _node(self.s, "system")
        svc = str(r.get("svc") or "?")
        sv = _node(sy, "by_svc").setdefault(svc, {})
        _add(sv, "samples")
        for src, dst in (("rss_mb", "rss_mb"), ("cpu_pct", "cpu_pct")):
            x = _num(r.get(src))
            if x is not None:
                _add(sv, f"{dst}_sum", x)
                _add(sv, f"{dst}_n")
                _mx(sv, f"{dst}_max", x)
                _mx(sy, f"{dst}_max", x)
        for f in ("loop_lag_ms", "threads", "fds"):
            x = _num(r.get(f if f != "loop_lag_ms" else "loop_lag_ms_max"))
            _mx(sv, f"{f}_max", x)
            _mx(sy, f"{f}_max", x)
        dropped = _num(r.get("mon_dropped")) or 0
        if dropped:
            _add(_node(self.s, "monitor"), "dropped_events", dropped)

    def _start(self, r: dict) -> None:
        _add(_node(self.s, "app", "starts"), str(r.get("svc") or "?"))

    def _stop(self, r: dict) -> None:
        _add(_node(self.s, "app", "stops"), str(r.get("svc") or "?"))

    def _cap(self, r: dict) -> None:
        _add(_node(self.s, "monitor"), "cap_reached")

    # ------------------------------------------------------------------ finalize
    def finalize(self, prices: dict[str, tuple[float, float]] | None = None) -> dict:
        return finalize(self.s, prices)


_HANDLERS = {
    "http.request": Aggregator._http,
    "auth.rejected": Aggregator._auth,
    "log.problem": Aggregator._problem,
    "llm.chat": Aggregator._llm,
    "embedding.request": Aggregator._embedding,
    "stt.transcribe": Aggregator._stt,
    "tool.call": Aggregator._tool,
    "tool.site_request": Aggregator._site_request,
    "rag.retrieval": Aggregator._rag,
    "chat.message": Aggregator._chat_message,
    "chat.delete": Aggregator._chat_delete,
    "chat.answer": Aggregator._answer,
    "answer.job": Aggregator._answer_job,
    "summary.job": Aggregator._summary_job,
    "ingest.enqueued": Aggregator._ingest_enqueued,
    "ingest.job": Aggregator._ingest_job,
    "callback.send": Aggregator._callback_send,
    "callback.site_state": Aggregator._site_state,
    "queue.snapshot": Aggregator._queue,
    "storage.snapshot": Aggregator._storage,
    "system.snapshot": Aggregator._system,
    "app.start": Aggregator._start,
    "app.stop": Aggregator._stop,
    "monitor.cap_reached": Aggregator._cap,
}


def quantile(h: dict, q: float) -> float:
    """Approximate quantile from a histogram (upper bound of the bucket, never above the max)."""
    n = h.get("n", 0)
    if not n:
        return 0.0
    target = q * n
    seen = 0
    for i, c in enumerate(h["h"]):
        seen += c
        if seen >= target:
            bound = BUCKETS[i] if i < len(BUCKETS) else h["max"]
            return float(min(bound, h["max"]))
    return float(h["max"])


def _round(x: float) -> float | int:
    if float(x).is_integer():
        return int(x)
    return round(x, 2) if abs(x) < 1000 else round(x)


def _fin(node: Any) -> Any:
    if _is_hist(node):
        n = node["n"]
        return {
            "n": n,
            "avg": _round(node["sum"] / n) if n else 0,
            "p50": _round(quantile(node, 0.50)),
            "p90": _round(quantile(node, 0.90)),
            "p95": _round(quantile(node, 0.95)),
            "p99": _round(quantile(node, 0.99)),
            "max": _round(node["max"]),
        }
    if isinstance(node, dict):
        out = {k: _fin(v) for k, v in node.items()}
        # averages from *_sum / *_n pairs, e.g. rss_mb_sum + rss_mb_n -> rss_mb_avg
        for k in [k for k in out if k.endswith("_sum") and k[:-4] + "_n" in out]:
            n = out[k[:-4] + "_n"]
            if n:
                out[k[:-4] + "_avg"] = _round(out[k] / n)
            del out[k]
            del out[k[:-4] + "_n"]
        return out
    if isinstance(node, float):
        return _round(node)
    return node


def _rate(num: float, den: float) -> float | None:
    return round(num / den, 4) if den else None


def finalize(state: dict, prices: dict[str, tuple[float, float]] | None = None) -> dict:
    """Mergeable state -> the readable report (latency summaries, averages, rates, cost)."""
    out = _fin(state)
    rates: dict[str, float | None] = {}
    http = state.get("http", {})
    rates["http_5xx"] = _rate(http.get("status", {}).get("5xx", 0), http.get("requests", 0))
    llm = state.get("llm", {})
    rates["llm_error"] = _rate(llm.get("errors", 0), llm.get("calls", 0))
    ans = state.get("answers", {})
    rates["answer_failed"] = _rate(ans.get("status", {}).get("failed", 0), ans.get("total", 0))
    tools = state.get("tools", {})
    rates["tool_error"] = _rate(tools.get("errors", 0), tools.get("calls", 0))
    cb = state.get("callbacks", {})
    rates["callback_failed"] = _rate(
        cb.get("sends", 0) - cb.get("by_outcome", {}).get("ok", 0), cb.get("sends", 0)
    )
    ing = state.get("ingest", {}).get("phase", {})
    rates["ingest_failed"] = _rate(ing.get("failed", 0), ing.get("done", 0) + ing.get("failed", 0))
    rag = state.get("rag", {})
    rates["rag_empty"] = _rate(rag.get("empty", 0), rag.get("queries", 0))
    out["rates"] = {k: v for k, v in rates.items() if v is not None}

    if prices:
        cost: dict[str, Any] = {"by_model": {}, "total": 0.0, "unit": "price units per 1M tokens"}
        for model, mn in llm.get("by_model", {}).items():
            if model in prices:
                pin, pout = prices[model]
                billed_in = max(mn.get("prompt_tokens", 0), 0)
                c_in = billed_in / 1e6 * pin
                c_out = mn.get("completion_tokens", 0) / 1e6 * pout
                cost["by_model"][model] = {
                    "input": round(c_in, 4), "output": round(c_out, 4),
                    "total": round(c_in + c_out, 4),
                }  # fmt: skip
                cost["total"] += c_in + c_out
        for model, mn in state.get("embedding", {}).get("by_model", {}).items():
            if model in prices:
                c = mn.get("tokens", 0) / 1e6 * prices[model][0]
                cost["by_model"][model] = {"input": round(c, 4), "output": 0, "total": round(c, 4)}
                cost["total"] += c
        cost["total"] = round(cost["total"], 4)
        out["cost"] = cost
    return out


# ============================================================================ roll-ups
def build_day_rollup(base: Path, day: str, offset_min: int) -> dict | None:
    """Summarize every raw file of `day`. None when there are none."""
    files = day_files(base, day)
    if not files:
        return None
    agg = Aggregator()
    info: dict = {}
    for rec in iter_records(files, info):
        agg.add(rec)
    return {
        "schema": SCHEMA,
        "day": day,
        "utc_offset_minutes": offset_min,
        "built_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "raw_files": [p.name for p in files],
        "bad_lines": info.get("bad_lines", 0),
        "state": agg.s,
    }


def write_rollup(base: Path, rollup: dict) -> Path:
    path = rollup_path(base, rollup["day"])
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + f".{os.getpid()}.tmp")
    tmp.write_text(json.dumps(rollup, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    os.replace(tmp, path)  # atomic: a reader never sees half a file
    return path


# ============================================================================ reports
def _bucket_key(unit: str, day: str, ts: str | None, offset_min: int, calendar: str) -> str:
    if unit == "hour":
        try:
            dt = datetime.fromisoformat((ts or "").replace("Z", "+00:00"))
            return local_dt(dt.timestamp(), offset_min).strftime("%Y-%m-%d %H:00")
        except ValueError:
            return f"{day} ??"
    if unit == "month":
        return month_label(day, calendar)
    return day_label(day, calendar)


def build_report(
    base: Path,
    first: date,
    last: date,
    *,
    unit: str,
    calendar: str = "gregorian",
    offset_min: int = 0,
    prices: dict[str, tuple[float, float]] | None = None,
    use_rollups: bool = True,
) -> dict:
    """Summary of the whole period + a series (`unit` = hour | day | month)."""
    buckets: dict[str, dict] = {}
    total: dict = {}
    cov = {"days_raw": 0, "days_rollup": 0, "days_missing": [], "bad_lines": 0}
    d = first
    while d <= last:
        day = d.isoformat()
        d += timedelta(days=1)
        files = day_files(base, day)
        roll = load_rollup(base, day) if use_rollups else None
        roll_ok = roll is not None and roll.get("utc_offset_minutes") == offset_min
        if roll_ok and unit != "hour" and files:
            # a finished day with a roll-up: no need to read the raw lines again
            files = []
        if files:
            cov["days_raw"] += 1
            info: dict = {}
            if unit == "hour":
                aggs: dict[str, Aggregator] = {}
                for rec in iter_records(files, info):
                    key = _bucket_key("hour", day, rec.get("ts"), offset_min, calendar)
                    aggs.setdefault(key, Aggregator()).add(rec)
                for key, agg in sorted(aggs.items()):
                    merge(buckets.setdefault(key, {}), agg.s)
                    merge(total, agg.s)
            else:
                agg = Aggregator()
                for rec in iter_records(files, info):
                    agg.add(rec)
                key = _bucket_key(unit, day, None, offset_min, calendar)
                merge(buckets.setdefault(key, {}), agg.s)
                merge(total, agg.s)
            cov["bad_lines"] += info.get("bad_lines", 0)
        elif roll_ok and roll is not None:
            cov["days_rollup"] += 1
            key = _bucket_key("day" if unit == "hour" else unit, day, None, offset_min, calendar)
            merge(buckets.setdefault(key, {}), roll["state"])
            merge(total, roll["state"])
        else:
            cov["days_missing"].append(day)
    return {
        "schema": SCHEMA,
        "period": {
            "from": first.isoformat(),
            "to": last.isoformat(),
            "calendar": calendar,
            "utc_offset_minutes": offset_min,
            "series_unit": unit,
        },
        "coverage": cov,
        "summary": finalize(total, prices),
        "series": [{"bucket": k, **finalize(v, prices)} for k, v in buckets.items()],
    }


def iter_raw(
    base: Path, first: date, last: date, *, events: set[str] | None = None,
    services: set[str] | None = None,
) -> Iterator[dict]:  # fmt: skip
    d = first
    while d <= last:
        for rec in iter_records(day_files(base, d.isoformat())):
            if events and rec.get("event") not in events:
                continue
            if services and rec.get("svc") not in services:
                continue
            yield rec
        d += timedelta(days=1)


# ----------------------------------------------------------------------------- flat / csv
def flatten(node: Any, prefix: str = "") -> dict[str, Any]:
    out: dict[str, Any] = {}
    if isinstance(node, dict):
        for k, v in node.items():
            out.update(flatten(v, f"{prefix}.{k}" if prefix else str(k)))
    else:
        out[prefix] = node
    return out


HEADLINE = (
    ("http_requests", "http.requests"),
    ("http_4xx", "http.status.4xx"),
    ("http_5xx", "http.status.5xx"),
    ("http_p95_ms", "http.lat.p95"),
    ("auth_rejected", "security.auth_rejected"),
    ("user_messages", "chat.messages.user"),
    ("new_conversations", "chat.conversations_created"),
    ("answers", "answers.total"),
    ("answers_failed", "answers.status.failed"),
    ("answer_p50_ms", "answers.lat.p50"),
    ("answer_p95_ms", "answers.lat.p95"),
    ("llm_calls", "llm.calls"),
    ("llm_errors", "llm.errors"),
    ("prompt_tokens", "llm.prompt_tokens"),
    ("completion_tokens", "llm.completion_tokens"),
    ("embedding_tokens", "embedding.tokens"),
    ("tool_calls", "tools.calls"),
    ("tool_errors", "tools.errors"),
    ("rag_queries", "rag.queries"),
    ("rag_empty", "rag.empty"),
    ("ingest_done", "ingest.phase.done"),
    ("ingest_failed", "ingest.phase.failed"),
    ("callbacks_sent", "callbacks.sends"),
    ("callbacks_dead", "callbacks.dead"),
    ("log_errors", "problems.error"),
    ("log_warnings", "problems.warning"),
    ("rss_mb_max", "system.rss_mb_max"),
    ("loop_lag_ms_max", "system.loop_lag_ms_max"),
    ("queue_ingest_pending_max", "queues.ingest_pending_max"),
    ("queue_answers_pending_max", "queues.answers_pending_max"),
    ("cost_estimate", "cost.total"),
)


def _get(d: dict, path: str) -> Any:
    for p in path.split("."):
        if not isinstance(d, dict) or p not in d:
            return None
        d = d[p]
    return d


def write_csv_files(report: dict, outdir: Path, stem: str, *, wide: bool = False) -> list[Path]:
    outdir.mkdir(parents=True, exist_ok=True)
    paths: list[Path] = []
    p1 = outdir / f"{stem}.summary.csv"
    with p1.open("w", encoding="utf-8-sig", newline="") as fh:  # BOM: Excel reads Persian text
        w = csv.writer(fh)
        w.writerow(["metric", "value"])
        for k, v in sorted(flatten(report["summary"]).items()):
            w.writerow([k, v])
    paths.append(p1)
    p2 = outdir / f"{stem}.series.csv"
    series = report["series"]
    with p2.open("w", encoding="utf-8-sig", newline="") as fh:
        w = csv.writer(fh)
        if wide:
            rows = [flatten({k: v for k, v in s.items() if k != "bucket"}) for s in series]
            cols = sorted({c for r in rows for c in r})
            w.writerow(["bucket", *cols])
            for s, r in zip(series, rows):
                w.writerow([s["bucket"], *[r.get(c, "") for c in cols]])
        else:
            w.writerow(["bucket", *[name for name, _ in HEADLINE]])
            for s in series:
                w.writerow([s["bucket"], *[_get(s, path) if _get(s, path) is not None else ""
                                           for _, path in HEADLINE]])  # fmt: skip
    paths.append(p2)
    return paths
