"""Callback delivery worker: batching, signing, retries, backoff, circuit breaker, slow sites."""

from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

from app.db.base import Database
from app.db.ingest_models import CallbackDelivery, CallbackSite
from app.db.ingest_repository import IngestRepository, aware
from app.db.messaging_models import utcnow
from app.services.ingestion.callback import CallbackBlockedError, resolve_public_ip
from app.services.ingestion.delivery import (
    DeliveryService,
    HttpResult,
    PinnedHttpTransport,
    backoff_seconds,
    classify,
)
from tests.conftest import SECRET, make_settings

HOST = "shop.example.com"
URL = f"https://{HOST}/wp-json/paia/v1/ai/results"
PING = f"https://{HOST}/ping"  # the ping_url seeded by setup()


class FakeTransport:
    """Scripted answers: each call pops the next item (an HttpResult or a callable)."""

    def __init__(self, *answers):
        self.answers = list(answers)
        self.calls: list[dict] = []

    async def request(self, method, url, body, headers):
        self.calls.append({"method": method, "url": url, "body": body, "headers": headers})
        answer = self.answers.pop(0) if len(self.answers) > 1 else self.answers[0]
        if callable(answer):
            return await answer()
        return answer

    def posted(self):
        return [c for c in self.calls if c["method"] == "POST"]


def ok(ms=50, body=b'{"ok":true}'):
    return HttpResult(200, body, {}, elapsed_ms=ms)


def http(status, code="", headers=None, extra=None):
    body = json.dumps({"code": code, **(extra or {})}).encode()
    return HttpResult(status, body, headers or {}, elapsed_ms=30)


_DBS: list[Database] = []


def run(coro):
    async def wrapper():
        try:
            return await coro
        finally:  # close the SQLite connections before the loop goes away
            while _DBS:
                await _DBS.pop().dispose()

    return asyncio.run(wrapper())


async def setup(tmp_path, transport, *, rows=3, hosts=(HOST,), **overrides):
    values = dict(CALLBACK_BATCH_WINDOW_SECONDS=0, CALLBACK_MIN_INTERVAL_SECONDS=0)
    values.update(overrides)
    settings = make_settings(tmp_path, **values)
    db = Database.from_settings(settings)
    await db.init()
    _DBS.append(db)
    repo = IngestRepository(db)
    async with db.session() as s:
        for host in hosts:
            s.add(
                CallbackSite(
                    host=host,
                    callback_url=f"https://{host}/r",
                    ping_url=f"https://{host}/ping",
                    batch_limit=settings.CALLBACK_INITIAL_BATCH_SIZE,
                )
            )
            for i in range(rows):
                s.add(
                    CallbackDelivery(
                        site_key=host,
                        callback_url=f"https://{host}/r",
                        job_id=f"j_{host[:3]}{i}",
                        record_id=f"product:{i}",
                        result_status="trained",
                        rev=f"rev{i}",
                        available_at=utcnow(),
                    )
                )
        await s.commit()
    service = DeliveryService(settings=settings, repo=repo, transport=transport, rand=lambda: 0.5)
    return settings, repo, service


async def deliveries(repo, host=HOST):
    async with repo.db.session() as s:
        from sqlalchemy import select

        return list(
            (
                await s.execute(
                    select(CallbackDelivery).where(CallbackDelivery.site_key == host).order_by(
                        CallbackDelivery.id
                    )
                )
            ).scalars()
        )


async def make_due(repo, host=HOST):
    """Pretend the backoff / ping interval has passed."""
    past = utcnow().replace(year=2000)
    await repo.update_site(host, next_attempt_at=past, next_ping_at=past)


# ------------------------------------------------------------------ batching + signature
def test_results_are_sent_in_one_signed_batch(tmp_path):
    async def go():
        t = FakeTransport(ok())
        settings, repo, svc = await setup(tmp_path, t, rows=3)
        assert await svc.run_pass(wait=True) == 1
        assert len(t.posted()) == 1  # 3 results, ONE request
        call = t.posted()[0]
        payload = json.loads(call["body"])
        assert [r["id"] for r in payload["results"]] == ["product:0", "product:1", "product:2"]
        assert payload["results"][0] == {
            "id": "product:0", "status": "trained", "job_id": "j_sho0", "rev": "rev0",
        }  # fmt: skip
        assert payload["delivery_id"] == call["headers"]["X-PAIA-Delivery"]
        assert payload["job_id"].startswith("b_")  # several jobs in one request

        # apis-README §3: sha256= + HMAC_SHA256(api_key, "{timestamp}.{raw body}")
        ts = call["headers"]["X-PAIA-Timestamp"]
        expected = hmac.new(SECRET.encode(), ts.encode() + b"." + call["body"], hashlib.sha256)
        assert call["headers"]["X-PAIA-Signature"] == "sha256=" + expected.hexdigest()
        assert abs(int(ts) - time.time()) < 5
        assert call["url"] == f"https://{HOST}/r"
        assert all(d.state == "done" for d in await deliveries(repo))

    run(go())


def test_single_job_uses_its_job_id_and_failed_carries_error_without_rev(tmp_path):
    async def go():
        t = FakeTransport(ok())
        settings, repo, svc = await setup(tmp_path, t, rows=0)
        async with repo.db.session() as s:
            s.add(CallbackDelivery(site_key=HOST, callback_url=URL, job_id="j_one", record_id="blog:5",
                                   result_status="failed", rev="ignored", error="متن خالی است",
                                   available_at=utcnow()))  # fmt: skip
            await s.commit()
        await repo.update_site(HOST, callback_url=URL)
        await svc.run_pass(wait=True)
        payload = json.loads(t.posted()[0]["body"])
        assert payload["job_id"] == "j_one"
        assert payload["results"] == [
            {"id": "blog:5", "status": "failed", "job_id": "j_one", "error": "متن خالی است"}
        ]

    run(go())


def test_callback_secret_can_be_separate(tmp_path):
    async def go():
        t = FakeTransport(ok())
        other = "b" * 64
        settings, repo, svc = await setup(tmp_path, t, rows=1, PLUGIN_CALLBACK_SECRET=other)
        await svc.run_pass(wait=True)
        c = t.posted()[0]
        ts = c["headers"]["X-PAIA-Timestamp"]
        good = "sha256=" + hmac.new(other.encode(), ts.encode() + b"." + c["body"], hashlib.sha256).hexdigest()
        assert c["headers"]["X-PAIA-Signature"] == good

    run(go())


def test_batch_window_delays_the_first_send(tmp_path):
    async def go():
        t = FakeTransport(ok())
        settings, repo, svc = await setup(tmp_path, t, rows=0, CALLBACK_BATCH_WINDOW_SECONDS=10)
        async with repo.db.session() as s:
            s.add(CallbackDelivery(site_key=HOST, callback_url=URL, job_id="j1", record_id="a:1",
                                   result_status="trained",
                                   available_at=utcnow().replace(year=2099)))  # fmt: skip
            await s.commit()
        assert await svc.run_pass(wait=True) == 0 and t.calls == []

    run(go())


def test_batch_size_limit_and_adaptive_growth(tmp_path):
    async def go():
        t = FakeTransport(ok(ms=100))
        settings, repo, svc = await setup(tmp_path, t, rows=7, CALLBACK_INITIAL_BATCH_SIZE=3)
        await svc.run_pass(wait=True)
        assert len(json.loads(t.posted()[0]["body"])["results"]) == 3
        site = await repo.get_site(HOST)
        assert site.batch_limit == 6  # fast site and a full batch -> doubled
        await svc.run_pass(wait=True)
        assert len(json.loads(t.posted()[1]["body"])["results"]) == 4  # the rest
        assert all(d.state == "done" for d in await deliveries(repo))

    run(go())


def test_slow_site_gets_smaller_batches(tmp_path):
    async def go():
        t = FakeTransport(ok(ms=6000))
        settings, repo, svc = await setup(tmp_path, t, rows=2, CALLBACK_INITIAL_BATCH_SIZE=40)
        await svc.run_pass(wait=True)
        assert (await repo.get_site(HOST)).batch_limit == 20

    run(go())


# ------------------------------------------------------------------ failures
def test_server_error_retries_with_backoff_and_same_delivery_id(tmp_path):
    async def go():
        t = FakeTransport(http(503), ok())
        settings, repo, svc = await setup(tmp_path, t, rows=2)
        await svc.run_pass(wait=True)
        rows = await deliveries(repo)
        assert [d.state for d in rows] == ["pending", "pending"]
        assert [d.attempts for d in rows] == [1, 1]
        site = await repo.get_site(HOST)
        assert site.consecutive_failures == 1 and site.state == "healthy"
        wait = (aware(site.next_attempt_at) - utcnow()).total_seconds()
        assert 20 < wait <= 31  # 30 s * jitter(0.8 + 0.4*0.5 = 1.0)

        assert await svc.run_pass(wait=True) == 0  # not due: nothing is sent early
        await make_due(repo)
        await svc.run_pass(wait=True)
        first, second = t.posted()
        # the retry is the SAME delivery: same id, same results
        assert first["headers"]["X-PAIA-Delivery"] == second["headers"]["X-PAIA-Delivery"]
        assert json.loads(first["body"])["results"] == json.loads(second["body"])["results"]
        assert all(d.state == "done" for d in await deliveries(repo))
        assert (await repo.get_site(HOST)).consecutive_failures == 0

    run(go())


def test_retry_after_header_is_respected(tmp_path):
    async def go():
        t = FakeTransport(http(429, headers={"retry-after": "120"}))
        settings, repo, svc = await setup(tmp_path, t, rows=1)
        await svc.run_pass(wait=True)
        site = await repo.get_site(HOST)
        wait = (aware(site.next_attempt_at) - utcnow()).total_seconds()
        assert 110 < wait <= 121

    run(go())


def test_timeout_counts_as_a_temporary_failure(tmp_path):
    async def go():
        t = FakeTransport(HttpResult(None, error="timeout"))
        settings, repo, svc = await setup(tmp_path, t, rows=1)
        await svc.run_pass(wait=True)
        d = (await deliveries(repo))[0]
        assert d.state == "pending" and d.attempts == 1 and d.last_error == "timeout"

    run(go())


def test_hard_timeout_even_if_the_transport_hangs(tmp_path):
    async def hang():
        await asyncio.sleep(60)

    async def go():
        t = FakeTransport(hang)
        settings, repo, svc = await setup(tmp_path, t, rows=1, CALLBACK_TOTAL_TIMEOUT=1)
        t0 = time.monotonic()
        await svc.run_pass(wait=True)
        assert time.monotonic() - t0 < 6  # CALLBACK_TOTAL_TIMEOUT + 2, never 60
        assert (await deliveries(repo))[0].attempts == 1

    run(go())


def test_result_becomes_dead_after_max_attempts(tmp_path):
    async def go():
        t = FakeTransport(http(500))
        settings, repo, svc = await setup(
            tmp_path, t, rows=1, CALLBACK_MAX_ATTEMPTS=2, CALLBACK_BREAKER_FAILURES=99
        )
        for _ in range(2):
            await make_due(repo)
            await svc.run_pass(wait=True)
        d = (await deliveries(repo))[0]
        assert d.state == "dead" and d.attempts == 2
        await make_due(repo)
        assert await svc.run_pass(wait=True) == 0  # dead results are not sent again

    run(go())


def test_circuit_breaker_stops_sending_and_only_pings_until_recovery(tmp_path):
    async def go():
        t = FakeTransport(http(502), http(502), http(502), HttpResult(None, error="timeout"), ok())
        settings, repo, svc = await setup(
            tmp_path, t, rows=1, CALLBACK_BREAKER_FAILURES=3, CALLBACK_MAX_ATTEMPTS=50
        )
        for _ in range(3):
            await make_due(repo)
            await svc.run_pass(wait=True)
        site = await repo.get_site(HOST)
        assert site.state == "unhealthy" and site.consecutive_failures == 3
        posts_before = len(t.posted())

        # open circuit: no result is posted, only a ping goes out (and fails: site still down)
        await make_due(repo)
        await svc.run_pass(wait=True)
        assert len(t.posted()) == posts_before
        assert t.calls[-1]["method"] == "GET" and t.calls[-1]["url"] == PING
        assert (await repo.get_site(HOST)).state == "unhealthy"

        # the site answers the ping -> healthy again -> the waiting result is delivered
        await make_due(repo)
        await svc.run_pass(wait=True)
        assert (await repo.get_site(HOST)).state == "healthy"
        await svc.run_pass(wait=True)
        assert (await deliveries(repo))[0].state == "done"

    run(go())


def test_bad_signature_stops_the_site_but_keeps_results(tmp_path):
    async def go():
        t = FakeTransport(http(401, "paia_bad_signature"))
        settings, repo, svc = await setup(tmp_path, t, rows=2)
        await svc.run_pass(wait=True)
        site = await repo.get_site(HOST)
        assert site.state == "auth_failed"
        assert [d.state for d in await deliveries(repo)] == ["pending", "pending"]
        assert len(t.posted()) == 1
        await svc.run_pass(wait=True)  # not due for a ping yet
        assert len(t.calls) == 1

    run(go())


@pytest.mark.parametrize(
    "answer", [http(404, "rest_no_route"), http(503, "paia_not_configured")]
)
def test_plugin_off_or_not_configured_suspends_the_site(tmp_path, answer):
    async def go():
        t = FakeTransport(answer)
        settings, repo, svc = await setup(tmp_path, t, rows=1)
        await svc.run_pass(wait=True)
        assert (await repo.get_site(HOST)).state == "suspended"
        assert (await deliveries(repo))[0].state == "pending"

    run(go())


def test_bad_request_is_not_retried(tmp_path):
    async def go():
        t = FakeTransport(http(400, "paia_bad_json"))
        settings, repo, svc = await setup(tmp_path, t, rows=2)
        await svc.run_pass(wait=True)
        assert [d.state for d in await deliveries(repo)] == ["dead", "dead"]
        assert (await repo.get_site(HOST)).consecutive_failures == 0

    run(go())


def test_413_halves_the_batch(tmp_path):
    async def go():
        t = FakeTransport(http(413), ok())
        settings, repo, svc = await setup(tmp_path, t, rows=6)
        await svc.run_pass(wait=True)
        assert (await repo.get_site(HOST)).batch_limit == 3
        assert all(d.state == "pending" and d.batch_id is None for d in await deliveries(repo))
        await svc.run_pass(wait=True)
        assert len(json.loads(t.posted()[1]["body"])["results"]) == 3

    run(go())


def test_stale_timestamp_is_fixed_with_the_sites_clock_and_retried_once(tmp_path):
    async def go():
        site_time = int(time.time()) - 900  # the site's clock is 15 minutes behind
        t = FakeTransport(http(401, "paia_stale_timestamp", extra={"server_time": site_time}), ok())
        settings, repo, svc = await setup(tmp_path, t, rows=1)
        await svc.run_pass(wait=True)
        first, second = t.posted()
        assert abs(int(second["headers"]["X-PAIA-Timestamp"]) - site_time) < 5
        assert (await deliveries(repo))[0].state == "done"
        assert (await repo.get_site(HOST)).clock_offset < -800

    run(go())


# ------------------------------------------------------------------ many sites, slow sites
def test_a_slow_site_does_not_hold_up_other_sites_and_gets_one_request_at_a_time(tmp_path):
    release = asyncio.Event
    state = {}

    async def go():
        state["gate"] = release()

        async def slow():
            await state["gate"].wait()
            return ok()

        class Router(FakeTransport):
            async def request(self, method, url, body, headers):
                self.calls.append({"method": method, "url": url, "body": body, "headers": headers})
                if "slow.example.com" in url:
                    return await slow()
                return ok()

        t = Router()
        settings, repo, svc = await setup(
            tmp_path, t, rows=2, hosts=("slow.example.com", "fast.example.com")
        )
        started = await svc.run_pass()  # both sites started, nothing awaited
        assert started == 2
        await asyncio.sleep(0.3)
        fast = await deliveries(repo, "fast.example.com")
        slow_rows = await deliveries(repo, "slow.example.com")
        assert all(d.state == "done" for d in fast)  # the fast site was not blocked
        assert all(d.state == "pending" for d in slow_rows)
        assert await svc.run_pass() == 0  # slow site is busy: never a second request at once
        state["gate"].set()
        await asyncio.gather(*svc._tasks)
        assert all(d.state == "done" for d in await deliveries(repo, "slow.example.com"))

    run(go())


def test_global_concurrency_is_capped(tmp_path):
    active = {"now": 0, "max": 0}

    async def go():
        class Counting(FakeTransport):
            async def request(self, method, url, body, headers):
                active["now"] += 1
                active["max"] = max(active["max"], active["now"])
                await asyncio.sleep(0.1)
                active["now"] -= 1
                return ok()

        hosts = tuple(f"s{i}.example.com" for i in range(6))
        settings, repo, svc = await setup(
            tmp_path, Counting(), rows=1, hosts=hosts, CALLBACK_MAX_CONCURRENCY=2
        )
        await svc.run_pass(wait=True)
        assert active["max"] == 2

    run(go())


def test_no_ping_url_means_retry_sending_after_the_interval(tmp_path):
    async def go():
        t = FakeTransport(ok())
        settings, repo, svc = await setup(tmp_path, t, rows=1)
        await repo.update_site(HOST, state="unhealthy", ping_url=None, next_ping_at=None)
        await svc.run_pass(wait=True)
        site = await repo.get_site(HOST)
        assert site.state == "healthy" and t.calls == []

    run(go())


# ------------------------------------------------------------------ pure helpers
def test_backoff_schedule_and_jitter():
    assert [backoff_seconds(n, 0.5) for n in (1, 2, 3, 4, 5, 9)] == [30, 120, 600, 3600, 21600, 21600]
    assert backoff_seconds(1, 0.0) == pytest.approx(24) and backoff_seconds(1, 1.0) == pytest.approx(36)


def test_classify():
    assert classify(HttpResult(204)).kind == "ok"
    assert classify(http(401, "paia_bad_signature")).kind == "auth"
    assert classify(http(401, "paia_stale_timestamp")).kind == "stale_ts"
    assert classify(http(403)).kind == "transient"  # WAF challenge page etc.
    assert classify(http(408)).kind == "transient" and classify(http(429)).kind == "transient"
    assert classify(HttpResult(502, b"<html>bad gateway</html>")).kind == "transient"
    assert classify(HttpResult(None, error="timeout")).kind == "transient"


# ------------------------------------------------------------------ the real transport
class _Handler(BaseHTTPRequestHandler):
    seen: list = []
    reply_size = 0

    def log_message(self, *args):
        pass

    def _reply(self):
        body = (b"x" * self.reply_size) if self.reply_size else b'{"ok":true}'
        self.send_response(200)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        n = int(self.headers.get("Content-Length", 0))
        _Handler.seen.append((self.command, self.path, dict(self.headers), self.rfile.read(n)))
        self._reply()

    do_GET = do_POST


@pytest.fixture
def local_site():
    _Handler.seen = []
    server = ThreadingHTTPServer(("127.0.0.1", 0), _Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    yield server.server_address[1]
    server.shutdown()
    server.server_close()


def test_real_transport_connects_to_the_checked_ip_and_limits_the_response(tmp_path, local_site):
    async def go():
        settings = make_settings(tmp_path, CALLBACK_ALLOW_PRIVATE_NETWORKS=True)
        transport = PinnedHttpTransport(settings)
        _Handler.reply_size = 100_000
        result = await transport.request(
            "POST", f"http://localhost:{local_site}/wp-json/x?rest_route=/a", b'{"a":1}',
            {"X-PAIA-Delivery": "d1"},
        )  # fmt: skip
        assert result.status == 200 and len(result.body) <= 8192  # never reads a big answer
        method, path, headers, body = _Handler.seen[0]
        assert (method, path, body) == ("POST", "/wp-json/x?rest_route=/a", b'{"a":1}')
        assert headers["Host"] == f"localhost:{local_site}"  # original host, IP only for TCP
        assert headers["X-PAIA-Delivery"] == "d1"
        _Handler.reply_size = 0

    run(go())


def test_real_transport_refuses_private_addresses_by_default(tmp_path, local_site):
    async def go():
        settings = make_settings(tmp_path)  # CALLBACK_ALLOW_PRIVATE_NETWORKS=false
        transport = PinnedHttpTransport(settings)
        for url in (
            f"http://127.0.0.1:{local_site}/x",
            f"http://localhost:{local_site}/x",
            "http://169.254.169.254/latest/meta-data",
            "http://10.0.0.5/x",
        ):
            result = await transport.request("GET", url, b"", {})
            assert result.status is None and "non-public" in result.error or "resolve" in result.error
        assert _Handler.seen == []  # nothing ever reached the local server

    run(go())


def test_resolve_public_ip_rules():
    async def go():
        assert await resolve_public_ip("93.184.216.34", 443, allow_private=False) == "93.184.216.34"
        for bad in ("127.0.0.1", "::1", "10.1.2.3", "172.16.0.9", "192.168.0.1", "169.254.1.1",
                    "100.64.0.1", "::ffff:127.0.0.1"):  # fmt: skip
            with pytest.raises(CallbackBlockedError):
                await resolve_public_ip(bad, 443, allow_private=False)
        assert await resolve_public_ip("127.0.0.1", 80, allow_private=True) == "127.0.0.1"

    run(go())
