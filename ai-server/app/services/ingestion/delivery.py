"""Callback delivery: tell the plugin "this record is finished" (apis-README: POST /ai/results).

Runs in its OWN cheap worker (never inside the AI/ingestion worker), so a slow WordPress site can
only ever hold up this loop. Rules from apis-README §5:
  * hard timeouts (connect <= 3 s, whole request <= 10 s), the response body is read up to a few KB
  * one request at a time per site, a small gap between sends, a global cap on parallel sites
  * results are batched: many records in ONE request (job_id/results as in the contract)
  * retry with exponential backoff + jitter (30 s, 2 m, 10 m, 1 h, 6 h; honours Retry-After)
  * circuit breaker per site: after N failures in a row only a periodic ping goes out until the
    site answers again; 401/404/503-not-configured stop sending at once (ping-only as well)
  * batch size adapts to the site's speed (413 halves it)
The signing key / timestamp / delivery-id headers follow apis-README §3.
"""

from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import logging
import random
import time
import uuid
from dataclasses import dataclass, field
from datetime import timedelta
from email.utils import parsedate_to_datetime
from typing import Protocol
from urllib.parse import urlsplit

import httpx

from app.core.config import Settings
from app.core.monitoring import emit
from app.db.ingest_models import CallbackDelivery, CallbackSite
from app.db.ingest_repository import IngestRepository, aware
from app.db.messaging_models import utcnow
from app.services.ingestion.callback import CallbackBlockedError, resolve_public_ip

logger = logging.getLogger(__name__)

BACKOFF_SECONDS = (30, 120, 600, 3600, 21600)  # 30 s, 2 min, 10 min, 1 h, 6 h
MAX_BODY_BYTES = 900_000  # the plugin accepts 1 MB per request
MAX_RESPONSE_BYTES = 8192
MAX_RETRY_AFTER = 21600
SLOW_MS = 4000  # slower than this -> smaller batches
FAST_MS = 1500  # faster than this (and the batch was full) -> bigger batches
MIN_BATCH = 10

H_TIMESTAMP = "X-PAIA-Timestamp"
H_SIGNATURE = "X-PAIA-Signature"
H_DELIVERY = "X-PAIA-Delivery"


def sign(secret: str, timestamp: str, body: bytes) -> str:
    """`sha256=` + hex HMAC_SHA256(key, "{timestamp}.{raw body}")."""
    mac = hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256)
    return "sha256=" + mac.hexdigest()


# ===================================================================== transport
@dataclass(slots=True)
class HttpResult:
    status: int | None  # None = no HTTP answer (timeout, refused, blocked address…)
    body: bytes = b""
    headers: dict[str, str] = field(default_factory=dict)
    error: str | None = None
    elapsed_ms: int = 0


class Transport(Protocol):
    async def request(
        self, method: str, url: str, body: bytes, headers: dict[str, str]
    ) -> HttpResult: ...


class PinnedHttpTransport:
    """httpx transport that connects to an IP we resolved and checked ourselves."""

    def __init__(self, settings: Settings):
        self.settings = settings

    async def request(
        self, method: str, url: str, body: bytes, headers: dict[str, str]
    ) -> HttpResult:
        started = time.monotonic()

        def elapsed() -> int:
            return int((time.monotonic() - started) * 1000)

        parts = urlsplit(url)
        host = parts.hostname or ""
        default = 443 if parts.scheme == "https" else 80
        port = parts.port or default
        try:
            ip = await resolve_public_ip(
                host, port, allow_private=self.settings.CALLBACK_ALLOW_PRIVATE_NETWORKS
            )
        except CallbackBlockedError as exc:
            return HttpResult(None, error=str(exc), elapsed_ms=elapsed())

        netloc = f"[{ip}]:{port}" if ":" in ip else f"{ip}:{port}"
        target = parts._replace(netloc=netloc).geturl()
        host_header = host if port == default else f"{host}:{port}"
        timeout = httpx.Timeout(
            self.settings.CALLBACK_TOTAL_TIMEOUT, connect=self.settings.CALLBACK_CONNECT_TIMEOUT
        )
        try:
            async with httpx.AsyncClient(
                timeout=timeout, follow_redirects=False, trust_env=False
            ) as client:
                async with client.stream(
                    method,
                    target,
                    content=body or None,
                    headers={**headers, "Host": host_header},
                    extensions={"sni_hostname": host},  # TLS name check against the real host
                ) as response:
                    chunks: list[bytes] = []
                    size = 0
                    async for chunk in response.aiter_bytes():
                        chunks.append(chunk)
                        size += len(chunk)
                        if size >= MAX_RESPONSE_BYTES:
                            break
                    return HttpResult(
                        response.status_code,
                        b"".join(chunks)[:MAX_RESPONSE_BYTES],
                        {k.lower(): v for k, v in response.headers.items()},
                        elapsed_ms=elapsed(),
                    )
        except httpx.TimeoutException:
            return HttpResult(None, error="timeout", elapsed_ms=elapsed())
        except httpx.HTTPError as exc:
            return HttpResult(None, error=f"{type(exc).__name__}", elapsed_ms=elapsed())


# ===================================================================== response handling
def _json(body: bytes) -> dict:
    try:
        data = json.loads(body.decode("utf-8", "replace"))
    except ValueError:
        return {}
    return data if isinstance(data, dict) else {}


def _error_code(body: bytes) -> str:
    return str(_json(body).get("code") or "")


def _server_time(body: bytes) -> int | None:
    data = _json(body)
    value = data.get("server_time")
    if value is None and isinstance(data.get("data"), dict):
        value = data["data"].get("server_time")
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _retry_after(headers: dict[str, str]) -> float | None:
    raw = headers.get("retry-after")
    if not raw:
        return None
    try:
        return min(max(float(raw), 0.0), MAX_RETRY_AFTER)
    except ValueError:
        pass
    try:
        when = parsedate_to_datetime(raw)
        seconds = (aware(when) - utcnow()).total_seconds()
        return min(max(seconds, 0.0), MAX_RETRY_AFTER)
    except (TypeError, ValueError):
        return None


@dataclass(slots=True)
class Verdict:
    kind: str  # ok | stale_ts | bad_request | auth | suspended | too_large | transient
    retry_after: float | None = None
    server_time: int | None = None
    detail: str = ""


def classify(result: HttpResult) -> Verdict:
    status = result.status
    if status is None:
        return Verdict("transient", detail=result.error or "no response")
    if 200 <= status < 300:
        return Verdict("ok")
    code = _error_code(result.body)
    detail = f"HTTP {status}" + (f" {code}" if code else "")
    if status == 401:
        if code == "paia_stale_timestamp":
            return Verdict("stale_ts", server_time=_server_time(result.body), detail=detail)
        return Verdict("auth", detail=detail)
    if status == 400:
        return Verdict("bad_request", detail=detail)
    if status == 404 or (status == 503 and code == "paia_not_configured"):
        return Verdict("suspended", detail=detail)
    if status == 413:
        return Verdict("too_large", detail=detail)
    return Verdict("transient", retry_after=_retry_after(result.headers), detail=detail)


def backoff_seconds(failures: int, rand: float) -> float:
    """Exponential backoff with +-20 % jitter (`rand` in [0, 1))."""
    base = BACKOFF_SECONDS[min(max(failures, 1), len(BACKOFF_SECONDS)) - 1]
    return base * (0.8 + 0.4 * rand)


# ===================================================================== service
class DeliveryService:
    def __init__(
        self,
        *,
        settings: Settings,
        repo: IngestRepository,
        transport: Transport | None = None,
        rand=random.random,
    ):
        self.settings = settings
        self.repo = repo
        self.transport = transport or PinnedHttpTransport(settings)
        self._rand = rand
        self._busy: set[str] = set()  # sites with a request in flight (1 per site)
        self._tasks: set[asyncio.Task] = set()
        self._slots = asyncio.Semaphore(settings.CALLBACK_MAX_CONCURRENCY)

    # ------------------------------------------------------------------ scheduling
    async def run_pass(self, *, wait: bool = False) -> int:
        """Start work for every site that is due (sends, or pings for stopped sites).
        -> number of site tasks started. `wait=True` also waits for them (tests, `--once`)."""
        started: list[asyncio.Task] = []
        for site in await self.repo.sites_ready_to_send():
            if site.host not in self._busy:
                started.append(self._spawn(self.send_for_site(site.host), site.host))
        for site in await self.repo.sites_ready_to_ping():
            if site.host not in self._busy:
                started.append(self._spawn(self.ping_site(site.host), site.host))
        if wait and started:
            await asyncio.gather(*started)
        return len(started)

    def _spawn(self, coro, host: str) -> asyncio.Task:
        self._busy.add(host)  # claimed before the task runs: never two tasks for one site

        async def _guard() -> None:
            try:
                async with self._slots:
                    await coro
            except Exception:  # noqa: BLE001
                logger.exception("callback.site_task_crashed site=%s", host)
            finally:
                self._busy.discard(host)

        task = asyncio.create_task(_guard())
        self._tasks.add(task)
        task.add_done_callback(self._tasks.discard)
        return task

    async def run_loop(self, stop: asyncio.Event) -> None:
        logger.info(
            "delivery loop started max_concurrency=%d window=%ss",
            self.settings.CALLBACK_MAX_CONCURRENCY, self.settings.CALLBACK_BATCH_WINDOW_SECONDS,
        )  # fmt: skip
        while not stop.is_set():
            try:
                await self.run_pass()
            except Exception:  # noqa: BLE001 - DB hiccup: wait and continue
                logger.exception("delivery loop iteration failed")
            try:
                await asyncio.wait_for(
                    stop.wait(), timeout=self.settings.CALLBACK_WORKER_POLL_SECONDS
                )
            except asyncio.TimeoutError:
                pass
        if self._tasks:
            await asyncio.gather(*self._tasks, return_exceptions=True)
        logger.info("delivery loop stopped")

    # ------------------------------------------------------------------ signed request
    async def _call(
        self, site: CallbackSite, method: str, url: str, body: bytes, delivery_id: str
    ) -> HttpResult:
        stamp = str(int(time.time()) + int(site.clock_offset or 0))
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            H_TIMESTAMP: stamp,
            H_SIGNATURE: sign(self.settings.callback_secret, stamp, body),
            H_DELIVERY: delivery_id,
        }
        try:  # the transport has its own timeouts; this is the hard ceiling for everything
            return await asyncio.wait_for(
                self.transport.request(method, url, body, headers),
                timeout=self.settings.CALLBACK_TOTAL_TIMEOUT + 2,
            )
        except asyncio.TimeoutError:
            return HttpResult(None, error="timeout")

    # ------------------------------------------------------------------ results
    def _payload(self, rows: list[CallbackDelivery], delivery_id: str) -> bytes:
        results = []
        for r in rows:
            if r.kind == "answer" and r.payload:  # a finished chat answer: ready-made item
                try:
                    results.append(json.loads(r.payload))
                    continue
                except ValueError:
                    pass
            item: dict = {"id": r.record_id, "status": r.result_status, "job_id": r.job_id}
            if r.rev and r.result_status == "trained":
                item["rev"] = r.rev
            if r.error and r.result_status == "failed":
                item["error"] = r.error
            if r.result_status == "failed" and r.action == "delete":
                item["operation"] = "delete"  # the record is still trained: plugin restores 2
            results.append(item)
        payload = {
            # one contract-level job_id per request; each result also names its own job
            "job_id": (
                rows[0].job_id if len({r.job_id for r in rows}) == 1 else f"b_{delivery_id[:8]}"
            ),
            "delivery_id": delivery_id,
            "results": results,
        }
        return json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")

    async def send_for_site(self, host: str) -> None:
        site = await self.repo.get_site(host)
        if site is None or site.state != "healthy":
            return
        limit = max(1, min(site.batch_limit, site.max_results, 500))
        rows = await self.repo.claim_delivery_batch(host, limit)
        if not rows:
            return
        ids = [r.id for r in rows]
        delivery_id = rows[0].batch_id or str(uuid.uuid4())
        body = self._payload(rows, delivery_id)
        if len(body) > MAX_BODY_BYTES and len(rows) > 1:
            await self._split(site, rows)
            return

        result = await self._call(site, "POST", rows[0].callback_url, body, delivery_id)
        verdict = classify(result)

        if verdict.kind == "stale_ts" and verdict.server_time is not None:
            # Our clock and the site's differ: sign with the site's time and try once more.
            offset = verdict.server_time - int(time.time())
            await self.repo.update_site(host, clock_offset=offset)
            site.clock_offset = offset
            logger.warning("callback.clock_offset site=%s offset_s=%d", host, offset)
            result = await self._call(site, "POST", rows[0].callback_url, body, delivery_id)
            verdict = classify(result)

        answers = sum(1 for r in rows if r.kind == "answer")
        emit(
            "callback.send", kind="results", site=site.host,
            outcome=verdict.kind if verdict.kind != "transient" else "transient",
            http_status=result.status, ms=result.elapsed_ms, results=len(rows),
            answers=answers, records=len(rows) - answers, batch_limit=site.batch_limit,
            error=None if verdict.kind == "ok" else (verdict.detail or result.error),
        )  # fmt: skip
        await self._apply(site, rows, ids, verdict, result)

    async def _split(self, site: CallbackSite, rows: list[CallbackDelivery]) -> None:
        half = max(1, len(rows) // 2)
        await self.repo.release_batch([r.id for r in rows])
        await self.repo.update_site(site.host, batch_limit=max(1, half))

    async def _apply(
        self,
        site: CallbackSite,
        rows: list[CallbackDelivery],
        ids: list[int],
        verdict: Verdict,
        result: HttpResult,
    ) -> None:
        host = site.host
        s = self.settings
        now = utcnow()
        gap = timedelta(seconds=s.CALLBACK_MIN_INTERVAL_SECONDS)

        if verdict.kind == "ok":
            await self.repo.mark_deliveries_done(ids)
            latency = result.elapsed_ms
            limit = site.batch_limit
            if latency > SLOW_MS:
                limit = max(MIN_BATCH, limit // 2)
            elif latency < FAST_MS and len(rows) >= limit:
                limit = min(site.max_results, 500, limit * 2)
            await self.repo.update_site(
                host,
                state="healthy",
                consecutive_failures=0,
                next_attempt_at=now + gap,
                last_ok_at=now,
                last_latency_ms=latency,
                last_error=None,
                batch_limit=limit,
            )
            logger.info(
                "callback.delivered site=%s results=%d latency_ms=%d batch_limit=%d",
                host, len(rows), latency, limit,
            )  # fmt: skip
            return

        error = verdict.detail or "failed"

        if verdict.kind == "bad_request":
            # Our payload is wrong: retrying the same bytes cannot help. A bug to fix, not a retry.
            await self.repo.kill_deliveries(ids, error=error)
            logger.error("callback.bad_request site=%s results=%d error=%s", host, len(rows), error)
            return

        if verdict.kind in ("auth", "suspended"):
            state = "auth_failed" if verdict.kind == "auth" else "suspended"
            await self.repo.update_site(
                host,
                state=state,
                last_error=error,
                next_ping_at=now + timedelta(seconds=s.CALLBACK_PING_INTERVAL_SECONDS),
            )
            emit("callback.site_state", site=host, state=state, error=error)
            logger.error(
                "callback.site_stopped site=%s state=%s error=%s (only pings until it answers)",
                host, state, error,
            )  # fmt: skip
            return  # results stay pending; nothing is lost

        if verdict.kind == "too_large":
            if len(rows) == 1:
                await self.repo.kill_deliveries(ids, error="single result rejected as too large")
                logger.error("callback.too_large site=%s (a single result)", host)
                return
            await self._split(site, rows)
            logger.warning("callback.batch_split site=%s was=%d", host, len(rows))
            return

        # transient (timeout, 408, 429, 5xx, WAF page) or a stale timestamp that did not fix itself
        failures = site.consecutive_failures + 1
        retrying, dead = await self.repo.fail_deliveries(
            ids,
            error=error,
            max_attempts=s.CALLBACK_MAX_ATTEMPTS,
            answer_max_attempts=s.ANSWER_CALLBACK_MAX_ATTEMPTS,
        )
        delay = (
            verdict.retry_after
            if verdict.retry_after is not None
            else backoff_seconds(failures, self._rand())
        )
        values: dict = {
            "consecutive_failures": failures,
            "last_error": error,
            "next_attempt_at": now + timedelta(seconds=delay),
        }
        if failures >= s.CALLBACK_BREAKER_FAILURES:
            values["state"] = "unhealthy"
            emit("callback.site_state", site=host, state="unhealthy", error=error)
            values["next_ping_at"] = now + timedelta(seconds=s.CALLBACK_PING_INTERVAL_SECONDS)
        await self.repo.update_site(host, **values)
        emit(
            "callback.send", kind="retry", site=host, outcome="retry_scheduled",
            failures=failures, retrying=retrying, dead=dead, retry_in_s=int(delay),
            circuit_open="state" in values,
        )  # fmt: skip
        logger.warning(
            "callback.failed site=%s error=%s failures=%d retry_in_s=%d retrying=%d dead=%d%s",
            host, error, failures, int(delay), retrying, dead,
            " circuit=open" if "state" in values else "",
        )  # fmt: skip
        if dead:
            logger.error(
                "callback.delivery_dead site=%s results=%d (the plugin must reconcile via "
                "POST /v1/jobs/status)", host, dead,
            )  # fmt: skip

    # ------------------------------------------------------------------ ping (health + recovery)
    async def ping_site(self, host: str) -> None:
        site = await self.repo.get_site(host)
        if site is None:
            return
        now = utcnow()
        interval = timedelta(seconds=self.settings.CALLBACK_PING_INTERVAL_SECONDS)
        if not site.ping_url:  # nothing to ping: simply try sending again after the interval
            await self.repo.update_site(
                host, state="healthy", consecutive_failures=0, next_attempt_at=now + interval
            )
            return
        result = await self._call(site, "GET", site.ping_url, b"", str(uuid.uuid4()))
        verdict = classify(result)
        if verdict.kind == "ok":
            await self.repo.update_site(
                host,
                state="healthy",
                consecutive_failures=0,
                next_attempt_at=None,
                next_ping_at=None,
                last_ping_ms=result.elapsed_ms,
                last_error=None,
            )
            emit("callback.site_state", site=host, state="recovered")
            emit("callback.send", kind="ping", site=host, outcome="ok", ms=result.elapsed_ms)
            logger.info("callback.site_recovered site=%s ping_ms=%d", host, result.elapsed_ms)
            return
        await self.repo.update_site(
            host,
            next_ping_at=now + interval,
            last_ping_ms=result.elapsed_ms or None,
            last_error=verdict.detail,
        )
        emit(
            "callback.send", kind="ping", site=host, outcome="failed", ms=result.elapsed_ms,
            http_status=result.status, error=verdict.detail,
        )  # fmt: skip
        logger.info("callback.ping_failed site=%s error=%s", host, verdict.detail)
