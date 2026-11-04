"""Monitoring events as JSON Lines (JSONL): one event = one line, bounded memory and disk.

    from app.core.monitoring import emit
    emit("llm.chat", model="gpt-5-4", status="ok", ms=812, prompt_tokens=1200, ...)

`emit` never blocks and never raises: the event goes into a bounded in-memory queue
(MONITOR_QUEUE_SIZE; when it is full the event is dropped and counted) and ONE background thread
per process appends batches to
    <MONITOR_DIR>/<YYYY>/<MM>/<day>.<process>.jsonl          (+ ".2", ".3"... parts)
The file name has the process role (api / ingestion / delivery / summarizer), so the four
processes never write into the same file. Fields of every event are documented in MONITORING.md.

Bounded storage = the SAME knobs as the text logs:
  * a file part is at most LOG_MAX_MB (MONITOR_MAX_MB) and a process writes at most
    LOG_BACKUP_COUNT (MONITOR_MAX_PARTS) parts per day; beyond that events are dropped + counted
  * the API process runs a maintenance pass every hour: it summarizes finished days into a tiny
    roll-up (rollups/<YYYY>/<day>.json - kept, this is what monthly/yearly reports use when the raw
    lines are gone), gzips raw files of days older than yesterday, deletes raw files older than
    MONITOR_RETENTION_DAYS and, if the folder exceeds MONITOR_MAX_TOTAL_MB, the oldest days.
Nothing here stores message text, file contents, secrets or URLs with query strings.
"""

from __future__ import annotations

import asyncio
import atexit
import contextvars
import gzip
import itertools
import json
import logging
import math
import os
import queue
import re
import shutil
import sys
import threading
import time
from collections.abc import Callable
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import TYPE_CHECKING, Any

from app.core import monitor_report as report

if TYPE_CHECKING:
    from app.core.config import Settings

SCHEMA = 1
RESERVED = frozenset({"v", "ts", "day", "event", "svc", "pid", "rid"})
_MAX_STR = 300
_logger = logging.getLogger("app.monitoring")  # excluded from the log -> event mirror

# ============================================================================ cleaning
_URL = re.compile(r"(https?://[^\s/?#\"']+)[^\s\"']*")
_SECRET = re.compile(r"(sk-[A-Za-z0-9_\-]{6,}|Bearer\s+[A-Za-z0-9._\-]{8,}|[A-Fa-f0-9]{40,})")


def redact(text: str) -> str:
    """Error texts of SDKs can contain keys or signed URLs: keep host only, hide key-like parts."""
    if len(text) < 8:
        return text
    return _SECRET.sub("[redacted]", _URL.sub(r"\1/…", text))


def _clean(v: Any, depth: int = 0) -> Any:
    if isinstance(v, bool) or isinstance(v, int):
        return v
    if isinstance(v, float):
        return round(v, 3) if math.isfinite(v) else None
    if isinstance(v, str):
        return redact(v[:_MAX_STR])
    if depth < 2 and isinstance(v, (list, tuple, set)):
        return [_clean(x, depth + 1) for x in itertools.islice(v, 50)]
    if depth < 2 and isinstance(v, dict):
        return {
            str(k)[:60]: _clean(x, depth + 1)
            for k, x in itertools.islice(v.items(), 50)
            if x is not None
        }
    return redact(str(v)[:_MAX_STR])


def _iso(epoch: float) -> str:
    ms = int((epoch % 1) * 1000)
    return time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(epoch)) + f".{ms:03d}Z"


# ============================================================================ context helpers
_request_id: Callable[[], str] | None = None
_answer_stats: contextvars.ContextVar[dict | None] = contextvars.ContextVar(
    "paia_answer_stats", default=None
)
op_var: contextvars.ContextVar[str] = contextvars.ContextVar("paia_embed_op", default="ingest")


def set_request_id_getter(fn: Callable[[], str]) -> None:
    global _request_id
    _request_id = fn


class answer_stats:  # noqa: N801 - used like a context manager function
    """`with answer_stats() as st:` - every llm.chat / tool.call / embedding.request / rag.retrieval
    event emitted inside (also by tasks started inside) adds to `st`, so ONE chat.answer event
    can carry the totals of the whole answer."""

    def __enter__(self) -> dict:
        self.d: dict = {
            "llm_calls": 0, "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0,
            "tool_calls": 0, "tool_errors": 0, "embedding_tokens": 0, "rag_hits": None,
            "rag_top_score": None, "stt_ms": 0, "error_type": None,
        }  # fmt: skip
        self._tok = _answer_stats.set(self.d)
        return self.d

    def __exit__(self, *exc: object) -> None:
        _answer_stats.reset(self._tok)


def note_answer_error(exc: BaseException) -> None:
    st = _answer_stats.get()
    if st is not None and not st.get("error_type"):
        st["error_type"] = type(exc).__name__


def _accumulate(st: dict, event: str, f: dict) -> None:
    try:
        if event == "llm.chat":
            st["llm_calls"] += 1
            for k in ("prompt_tokens", "completion_tokens", "total_tokens"):
                st[k] += int(f.get(k) or 0)
        elif event == "tool.call":
            st["tool_calls"] += 1
            if f.get("status") != "ok":
                st["tool_errors"] += 1
        elif event == "embedding.request":
            st["embedding_tokens"] += int(f.get("total_tokens") or f.get("prompt_tokens") or 0)
        elif event == "rag.retrieval":
            st["rag_hits"] = f.get("loaded")
            st["rag_top_score"] = f.get("top_score")
        elif event == "stt.transcribe":
            st["stt_ms"] += int(f.get("ms") or 0)
    except (TypeError, ValueError):
        pass


# ============================================================================ configuration
@dataclass(frozen=True)
class _Cfg:
    base: Path
    offset_min: int
    part_bytes: int
    max_parts: int
    queue_size: int
    flush: float
    log_ip: bool
    compress: bool
    retention_days: int
    max_total_bytes: int
    snapshot_s: int
    storage_s: int

    @classmethod
    def from_settings(cls, s: Settings) -> _Cfg:
        return cls(
            base=s.monitor_dir,
            offset_min=s.monitor_utc_offset_minutes,
            part_bytes=s.monitor_part_bytes,
            max_parts=s.monitor_max_parts,
            queue_size=s.MONITOR_QUEUE_SIZE,
            flush=s.MONITOR_FLUSH_SECONDS,
            log_ip=s.MONITOR_LOG_IP,
            compress=s.MONITOR_COMPRESS,
            retention_days=s.MONITOR_RETENTION_DAYS,
            max_total_bytes=s.MONITOR_MAX_TOTAL_MB * 1024 * 1024,
            snapshot_s=s.MONITOR_SNAPSHOT_SECONDS,
            storage_s=s.MONITOR_STORAGE_SNAPSHOT_SECONDS,
        )


# ============================================================================ writer
class _DayState:
    __slots__ = ("part", "size", "total", "pending", "cap_notified")

    def __init__(self, part: int = 1, size: int = 0, total: int = 0):
        self.part = part
        self.size = size  # bytes in the current part
        self.total = total  # bytes of all parts of this day (this process)
        self.pending: list[tuple[int, bytes]] = []
        self.cap_notified = False


class Monitor:
    def __init__(self, cfg: _Cfg, service: str, proc: str):
        self.cfg = cfg
        self.service = service
        self.proc = proc
        self.pid = os.getpid()
        self.started = time.time()
        self.q: queue.Queue[tuple[str, str]] = queue.Queue(maxsize=cfg.queue_size)
        self.written = 0
        self.dropped = 0
        self._dropped_reported = 0
        self._lock = threading.Lock()
        self._states: dict[str, _DayState] = {}
        self._stopping = threading.Event()
        self._err_printed = False
        self._thread = threading.Thread(target=self._run, name="monitor-writer", daemon=True)
        self._maint: threading.Thread | None = None

    # ------------------------------------------------------------------ producer side
    def emit(self, event: str, fields: dict) -> None:
        now = time.time()
        rec: dict[str, Any] = {
            "v": SCHEMA,
            "ts": _iso(now),
            "day": report.day_of(now, self.cfg.offset_min),
            "event": event,
            "svc": self.service,
            "pid": self.pid,
        }
        if _request_id is not None:
            try:
                rid = _request_id()
            except Exception:  # noqa: BLE001
                rid = "-"
            if rid and rid != "-":
                rec["rid"] = rid
        for k, v in fields.items():
            if k in RESERVED or v is None or (k == "ip" and not self.cfg.log_ip):
                continue
            rec[k] = _clean(v)
        try:
            line = json.dumps(rec, ensure_ascii=False, separators=(",", ":"))
        except (TypeError, ValueError):
            return
        try:
            self.q.put_nowait((rec["day"], line))
        except queue.Full:
            with self._lock:
                self.dropped += 1

    def take_dropped(self) -> int:
        """Events dropped since the last call (the system snapshot reports this)."""
        with self._lock:
            delta = self.dropped - self._dropped_reported
            self._dropped_reported = self.dropped
        return delta

    # ------------------------------------------------------------------ lifecycle
    def start(self) -> None:
        self._thread.start()
        if self.service == "api":
            self._maint = threading.Thread(
                target=self._maintenance_loop, name="monitor-maintenance", daemon=True
            )
            self._maint.start()

    def stop(self, timeout: float = 5.0) -> None:
        self._stopping.set()
        self._thread.join(timeout)

    # ------------------------------------------------------------------ writer thread
    def _run(self) -> None:
        while True:
            try:
                first = self.q.get(timeout=0.5)
            except queue.Empty:
                if self._stopping.is_set():
                    return
                continue
            self._stopping.wait(self.cfg.flush)  # let a few more events arrive: fewer writes
            batch = [first]
            while len(batch) < 5000:
                try:
                    batch.append(self.q.get_nowait())
                except queue.Empty:
                    break
            try:
                self._write(batch)
            except Exception as exc:  # noqa: BLE001 - monitoring must never take the app down
                self._complain(exc)

    def _complain(self, exc: BaseException) -> None:
        if not self._err_printed:  # once, to stderr (the logging system is not used on purpose)
            self._err_printed = True
            print(f"[monitoring] cannot write events: {exc!r}", file=sys.stderr)

    def _path(self, day: str, part: int) -> Path:
        name = f"{day}.{self.proc}.jsonl" if part == 1 else f"{day}.{self.proc}.{part}.jsonl"
        return self.cfg.base / day[:4] / day[5:7] / name

    def _state(self, day: str) -> _DayState:
        st = self._states.get(day)
        if st is not None:
            return st
        # resume after a restart: continue the newest part, count what is already there
        part, size, total = 1, 0, 0
        folder = self.cfg.base / day[:4] / day[5:7]
        if folder.is_dir():
            prefix = f"{day}.{self.proc}."
            for p in folder.iterdir():
                n = p.name
                if not (n.startswith(prefix) and n.endswith(".jsonl")):
                    continue
                mid = n[len(prefix) : -len(".jsonl")]
                num = 1 if mid == "" else int(mid) if mid.isdigit() else 0
                if num == 0:
                    continue
                try:
                    sz = p.stat().st_size
                except OSError:
                    continue
                total += sz
                if num >= part:
                    part, size = num, sz
        if len(self._states) > 3:  # only today (+ a day around midnight) is ever written
            for old in sorted(self._states)[:-2]:
                self._states.pop(old, None)
        st = self._states[day] = _DayState(part, size, total)
        return st

    def _write(self, batch: list[tuple[str, str]]) -> None:
        cfg = self.cfg
        touched: dict[str, _DayState] = {}
        for day, line in batch:
            st = self._state(day)
            data = (line + "\n").encode("utf-8")
            if st.size and st.size + len(data) > cfg.part_bytes:
                if st.part >= cfg.max_parts:  # the day is full: drop (counted), tell once
                    with self._lock:
                        self.dropped += 1
                    if not st.cap_notified:
                        st.cap_notified = True
                        self._notify_cap(day, st)
                    continue
                st.part += 1
                st.size = 0
            st.pending.append((st.part, data))
            st.size += len(data)
            st.total += len(data)
            touched[day] = st
        for day, st in touched.items():
            self._flush(day, st)

    def _notify_cap(self, day: str, st: _DayState) -> None:
        """One ordinary event (bypasses the cap) saying the day's budget is used up."""
        rec = {
            "v": SCHEMA, "ts": _iso(time.time()), "day": day, "event": "monitor.cap_reached",
            "svc": self.service, "pid": self.pid, "max_parts": self.cfg.max_parts,
            "part_mb": round(self.cfg.part_bytes / 1048576, 1), "day_bytes": st.total,
        }  # fmt: skip
        st.pending.append((st.part, (json.dumps(rec, separators=(",", ":")) + "\n").encode()))

    def _flush(self, day: str, st: _DayState) -> None:
        pending, st.pending = st.pending, []
        for part, group in itertools.groupby(pending, key=lambda x: x[0]):
            blob = b"".join(d for _, d in group)
            path = self._path(day, part)
            path.parent.mkdir(parents=True, exist_ok=True)
            with open(path, "ab", buffering=0) as fh:  # one write call = lines never interleave
                view = memoryview(blob)
                while view:
                    view = view[fh.write(view) :]
            with self._lock:
                self.written += blob.count(b"\n")

    # ------------------------------------------------------------------ maintenance thread
    def _maintenance_loop(self) -> None:
        if self._stopping.wait(30):  # let the server start first
            return
        while True:
            try:
                result = run_maintenance(self.cfg)
                if any(result.get(k) for k in ("rolled_up", "compressed", "deleted_files")):
                    emit("monitor.maintenance", **result)
            except Exception as exc:  # noqa: BLE001
                self._complain(exc)
            if self._stopping.wait(3600):
                return


# ============================================================================ maintenance
def _gzip_file(src: Path) -> None:
    dst = src.with_name(src.name + ".gz")
    tmp = dst.with_name(dst.name + ".tmp")
    with open(src, "rb") as fi, gzip.open(tmp, "wb", compresslevel=6) as fo:
        shutil.copyfileobj(fi, fo, 1 << 20)
    st = src.stat()
    os.replace(tmp, dst)
    os.utime(dst, (st.st_atime, st.st_mtime))  # keep the mtime: the roll-up freshness test uses it
    src.unlink()


def _newest_mtime(files: list[Path]) -> float:
    best = 0.0
    for p in files:
        try:
            best = max(best, p.stat().st_mtime)
        except OSError:
            pass
    return best


def run_maintenance(cfg: _Cfg, now: float | None = None) -> dict:
    """Roll-ups -> compress -> retention -> total size cap. Safe to run anytime / from a script."""
    now = now or time.time()
    base = cfg.base
    today = report.local_dt(now, cfg.offset_min).date()
    settled = today - timedelta(days=2)  # nothing is written to these days any more
    out = {"rolled_up": 0, "compressed": 0, "deleted_files": 0, "freed_mb": 0.0, "total_mb": 0.0}
    if not base.is_dir():
        return out

    def day_date(day: str) -> date:
        return date.fromisoformat(day)

    for day in report.list_days(base):
        if day_date(day) > settled:
            continue
        files = report.day_files(base, day)
        rp = report.rollup_path(base, day)
        stale = not rp.exists() or rp.stat().st_mtime < _newest_mtime(files)
        if stale:
            roll = report.build_day_rollup(base, day, cfg.offset_min)
            if roll is not None:
                report.write_rollup(base, roll)
                out["rolled_up"] += 1
        if cfg.compress:
            for p in files:
                if p.name.endswith(".jsonl"):
                    try:
                        _gzip_file(p)
                        out["compressed"] += 1
                    except OSError:
                        pass  # e.g. still open on Windows: next pass

    def sizes() -> dict[str, int]:
        per_day: dict[str, int] = {}
        for day in report.list_days(base):
            per_day[day] = sum(p.stat().st_size for p in report.day_files(base, day) if p.exists())
        return per_day

    def delete_day(day: str) -> None:
        for p in report.day_files(base, day):
            try:
                size = p.stat().st_size
                p.unlink()
            except OSError:
                continue
            out["deleted_files"] += 1
            out["freed_mb"] += size / 1048576

    per_day = sizes()
    cutoff = today - timedelta(days=cfg.retention_days)
    for day in sorted(per_day):
        if day_date(day) < cutoff and day_date(day) <= settled:
            delete_day(day)
            per_day.pop(day)
    total = sum(per_day.values())
    for day in sorted(per_day):  # still too big: oldest settled days go first (roll-ups stay)
        if total <= cfg.max_total_bytes:
            break
        if day_date(day) > settled:
            break
        total -= per_day[day]
        delete_day(day)
    out["freed_mb"] = round(out["freed_mb"], 2)
    out["total_mb"] = round(max(total, 0) / 1048576, 2)
    return out


# ============================================================================ module API
_MON: Monitor | None = None
_atexit_done = False


def enabled() -> bool:
    return _MON is not None


def emit(event: str, **fields: Any) -> None:
    """Record one event. Never blocks, never raises, free when monitoring is off."""
    mon = _MON
    if mon is None:
        return
    try:
        st = _answer_stats.get()
        if st is not None:
            _accumulate(st, event, fields)
        mon.emit(event, fields)
    except Exception:  # noqa: BLE001
        pass


class MonitorLogHandler(logging.Handler):
    """WARNING+ log lines become `log.problem` events (message only, secrets/URLs redacted)."""

    def __init__(self) -> None:
        super().__init__(level=logging.WARNING)

    def emit(self, record: logging.LogRecord) -> None:  # noqa: D102
        if record.name.startswith(("app.access", "app.monitoring")):
            return  # 5xx are already in http.request
        try:
            fields: dict[str, Any] = {
                "level": record.levelname.lower(),
                "logger": record.name,
                "message": record.getMessage(),
            }
            if record.exc_info and record.exc_info[0] is not None:
                fields["exc_type"] = record.exc_info[0].__name__
            emit("log.problem", **fields)  # the module-level function, not this method
        except Exception:  # noqa: BLE001
            pass


def init_monitoring(settings: Settings, service: str = "api") -> bool:
    """Start monitoring for this process (called by `setup_logging`). Idempotent."""
    global _MON, _atexit_done
    if not settings.MONITOR_ENABLED:
        shutdown_monitoring()
        return False
    cfg = _Cfg.from_settings(settings)
    if _MON is not None:
        if _MON.cfg == cfg and _MON.service == service:
            _attach_log_handler()  # dictConfig (setup_logging) may have replaced the root handlers
            return True
        shutdown_monitoring()
    proc = service
    if service == "api" and settings.WORKERS > 1:
        proc = f"api-{os.getpid()}"  # several API processes: one file each
    mon = Monitor(cfg, service, proc)
    mon.start()
    _MON = mon
    if not _atexit_done:
        atexit.register(shutdown_monitoring)
        _atexit_done = True
    _attach_log_handler()
    _emit_start(settings, cfg)
    return True


def _attach_log_handler() -> None:
    root = logging.getLogger()
    if not any(isinstance(h, MonitorLogHandler) for h in root.handlers):
        root.addHandler(MonitorLogHandler())


def _emit_start(settings: Settings, cfg: _Cfg) -> None:
    try:
        from urllib.parse import urlsplit

        from app import __version__

        db = settings.database_url
        emit(
            "app.start",
            version=__version__,
            env=settings.APP_ENV,
            host=settings.HOST,
            port=settings.PORT,
            workers=settings.WORKERS,
            log_level=settings.LOG_LEVEL,
            https_required=settings.REQUIRE_HTTPS,
            models=settings.allowed_models,
            embedding_model=settings.EMBEDDING_MODEL,
            embedding_dimensions=settings.EMBEDDING_DIMENSIONS,
            summary_model=settings.SUMMARY_MODEL,
            stt_model=settings.STT_MODEL,
            answer_max_rounds=settings.ANSWER_MAX_LLM_ROUNDS,
            db_backend=db.split(":", 1)[0].split("+", 1)[0],
            qdrant_mode="embedded" if settings.qdrant_path is not None else "server",
            ingest_in_api=settings.ingest_in_api,
            plugin_host=urlsplit(settings.PLUGIN_BASE_URL).netloc or None,
            python=sys.version.split()[0],
            monitor_part_mb=round(cfg.part_bytes / 1048576, 1),
            monitor_max_parts=cfg.max_parts,
            monitor_retention_days=cfg.retention_days,
            monitor_max_total_mb=round(cfg.max_total_bytes / 1048576),
            monitor_utc_offset_min=cfg.offset_min,
        )
    except Exception:  # noqa: BLE001
        pass


def shutdown_monitoring() -> None:
    """Write `app.stop`, flush the queue and stop the writer (also registered with atexit)."""
    global _MON
    mon = _MON
    if mon is None:
        return
    emit("app.stop", uptime_s=round(time.time() - mon.started), written=mon.written,
         dropped=mon.dropped)  # fmt: skip
    _MON = None
    mon.stop()
    root = logging.getLogger()
    for h in [h for h in root.handlers if isinstance(h, MonitorLogHandler)]:
        root.removeHandler(h)


# ============================================================================ snapshots
_psutil: Any = False


def _rss_mb() -> float | None:
    global _psutil
    if _psutil is False:
        try:
            import psutil

            _psutil = psutil
        except Exception:  # noqa: BLE001
            _psutil = None
    try:
        if _psutil is not None:
            return round(_psutil.Process().memory_info().rss / 1048576, 1)
        with open("/proc/self/statm", encoding="ascii") as fh:  # Linux
            return round(int(fh.read().split()[1]) * os.sysconf("SC_PAGE_SIZE") / 1048576, 1)
    except Exception:  # noqa: BLE001
        pass
    try:
        import resource  # Unix: peak RSS (KB on Linux, bytes on macOS)

        peak = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        return round(peak / (1048576 if sys.platform == "darwin" else 1024), 1)
    except Exception:  # noqa: BLE001
        return None


def _open_fds() -> int | None:
    try:
        return len(os.listdir("/proc/self/fd"))
    except OSError:
        return None


def _dir_stats(path: Path | None) -> tuple[float, int]:
    """(megabytes, files) of a folder tree; (0, 0) when it does not exist."""
    if path is None or not path.exists():
        return 0.0, 0
    total = files = 0
    stack = [path]
    while stack:
        try:
            with os.scandir(stack.pop()) as it:
                for e in it:
                    try:
                        if e.is_dir(follow_symlinks=False):
                            stack.append(Path(e.path))
                        elif e.is_file(follow_symlinks=False):
                            total += e.stat().st_size
                            files += 1
                    except OSError:
                        continue
        except OSError:
            continue
    return round(total / 1048576, 2), files


def _storage_sizes(settings: Settings, cfg: _Cfg) -> dict:
    out: dict[str, Any] = {}
    try:
        usage = shutil.disk_usage(settings.data_dir if settings.data_dir.exists() else Path("."))
        out["disk_free_mb"] = round(usage.free / 1048576)
        out["disk_total_mb"] = round(usage.total / 1048576)
    except OSError:
        pass
    out["data_mb"], _ = _dir_stats(settings.data_dir)
    out["uploads_mb"], out["uploads_files"] = _dir_stats(settings.uploads_dir)
    out["knowledge_files_mb"], out["knowledge_files"] = _dir_stats(settings.files_dir)
    out["qdrant_mb"], _ = _dir_stats(settings.qdrant_path)
    out["logs_mb"], _ = _dir_stats(settings.log_dir)
    out["monitor_mb"], out["monitor_files"] = _dir_stats(cfg.base)
    url = settings.database_url
    if url.startswith("sqlite"):
        db_file = Path(url.split(":///", 1)[1])
        mb = 0.0
        for suffix in ("", "-wal", "-shm"):
            try:
                mb += os.path.getsize(f"{db_file}{suffix}") / 1048576
            except OSError:
                pass
        out["db_mb"] = round(mb, 2)
    return out


async def _queue_snapshot(db: Any) -> dict:
    """Backlog of every queue + age of the oldest waiting item (the 'is it stuck?' gauge)."""
    from sqlalchemy import func, select

    from app.db.answer_models import AnswerJob
    from app.db.ingest_models import CallbackDelivery, CallbackSite, IngestJob
    from app.db.ingest_repository import aware
    from app.db.messaging_models import SummaryRequest

    now = datetime.now(timezone.utc)
    out: dict[str, Any] = {}

    def age(ts: datetime | None) -> int | None:
        ts = aware(ts)
        return max(int((now - ts).total_seconds()), 0) if ts else None

    async with db.session() as s:
        for prefix, model, wait_col in (
            ("ingest", IngestJob, IngestJob.created_at),
            ("answers", AnswerJob, AnswerJob.created_at),
            ("summaries", SummaryRequest, SummaryRequest.created_at),
        ):
            counts = dict(
                (await s.execute(select(model.status, func.count()).group_by(model.status))).all()
            )
            for status in ("pending", "processing", "failed"):
                out[f"{prefix}_{status}"] = int(counts.get(status, 0))
            oldest = (
                await s.execute(select(func.min(wait_col)).where(model.status == "pending"))
            ).scalar()
            out[f"{prefix}_oldest_pending_s"] = age(oldest) or 0

        rows = (
            await s.execute(
                select(CallbackDelivery.state, CallbackDelivery.kind, func.count()).group_by(
                    CallbackDelivery.state, CallbackDelivery.kind
                )
            )
        ).all()
        out["deliveries_pending"] = sum(n for st, _k, n in rows if st == "pending")
        out["deliveries_pending_answers"] = sum(
            n for st, k, n in rows if st == "pending" and k == "answer"
        )
        out["deliveries_dead"] = sum(n for st, _k, n in rows if st == "dead")
        oldest = (
            await s.execute(
                select(func.min(CallbackDelivery.created_at)).where(
                    CallbackDelivery.state == "pending"
                )
            )
        ).scalar()
        out["deliveries_oldest_pending_s"] = age(oldest) or 0
        sites = (
            await s.execute(select(CallbackSite.state, func.count()).group_by(CallbackSite.state))
        ).all()
        out["sites_total"] = sum(n for _st, n in sites)
        out["sites_unhealthy"] = sum(n for st, n in sites if st != "healthy")
    return out


async def _db_totals(db: Any) -> dict:
    from sqlalchemy import func, select

    from app.db.messaging_models import Conversation, Message
    from app.db.models import Chunk, Record

    out: dict[str, int] = {}
    async with db.session() as s:
        for name, model in (
            ("conversations", Conversation), ("messages", Message),
            ("records", Record), ("chunks", Chunk),
        ):  # fmt: skip
            count = (await s.execute(select(func.count()).select_from(model))).scalar()
            out[f"total_{name}"] = int(count or 0)
    return out


async def _snapshot_loop(settings: Settings, mon: Monitor, db: Any, stop: asyncio.Event) -> None:
    cfg = mon.cfg
    sys_every = float(cfg.snapshot_s)
    sto_every = float(cfg.storage_s)
    now = time.monotonic()
    next_sys = now + 5 if sys_every > 0 else math.inf
    next_sto = now + 15 if sto_every > 0 else math.inf
    last_cpu, last_wall = time.process_time(), time.monotonic()
    lag_max = 0.0
    while not stop.is_set():
        t0 = time.monotonic()
        await asyncio.sleep(1.0)
        lag_max = max(lag_max, (time.monotonic() - t0 - 1.0) * 1000)  # event-loop lag (ms)
        now = time.monotonic()
        if now >= next_sys:
            next_sys = now + sys_every
            cpu, wall = time.process_time(), now
            cpu_pct = (cpu - last_cpu) / max(wall - last_wall, 1e-6) * 100
            last_cpu, last_wall = cpu, wall
            emit(
                "system.snapshot",
                rss_mb=_rss_mb(),
                cpu_pct=round(cpu_pct, 1),
                threads=threading.active_count(),
                fds=_open_fds(),
                loop_lag_ms_max=round(max(lag_max, 0.0), 1),
                mon_queue=mon.q.qsize(),
                mon_written=mon.written,
                mon_dropped=mon.take_dropped(),
            )
            lag_max = 0.0
            if db is not None:
                try:
                    emit("queue.snapshot", **await _queue_snapshot(db))
                except Exception as exc:  # noqa: BLE001 - DB hiccup: skip this sample
                    _logger.debug("queue snapshot failed: %s", exc)
        if now >= next_sto:
            next_sto = now + sto_every
            try:
                fields = await asyncio.to_thread(_storage_sizes, settings, cfg)
                if db is not None:
                    fields.update(await _db_totals(db))
                emit("storage.snapshot", **fields)
            except Exception as exc:  # noqa: BLE001
                _logger.debug("storage snapshot failed: %s", exc)


def start_snapshots(
    settings: Settings, *, db: Any = None, stop: asyncio.Event | None = None
) -> asyncio.Task | None:
    """Background task (needs a running loop) that emits system/queue/storage gauges.
    Pass `db` (a `Database`) in the API process to include queue backlogs and table sizes."""
    mon = _MON
    if mon is None or (mon.cfg.snapshot_s <= 0 and mon.cfg.storage_s <= 0):
        return None
    return asyncio.create_task(
        _snapshot_loop(settings, mon, db, stop or asyncio.Event()), name="monitor-snapshots"
    )


async def stop_snapshots(task: asyncio.Task | None, stop: asyncio.Event | None = None) -> None:
    if task is None:
        return
    if stop is not None:
        stop.set()
    task.cancel()
    try:
        await task
    except (asyncio.CancelledError, Exception):  # noqa: BLE001
        pass
