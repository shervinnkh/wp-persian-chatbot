"""Ingestion queue: the API only accepts (200 at once), the worker does the work later,
the plugin is reported to via the callback outbox."""

from __future__ import annotations

import json
import sqlite3

import pytest
from fastapi.testclient import TestClient
from sqlalchemy.exc import OperationalError

from app.db.base import Database
from app.main import create_app
from app.services.chunking.tokens import TokenCounter
from app.services.ingestion import IngestQueueService, KnowledgeService
from app.services.ingestion.processor import IngestProcessor
from app.services.vectorstore import VectorStore
from tests.conftest import make_settings, signed_headers
from tests.samples import BLOG, PRODUCT
from tests.test_knowledge_api import DIMS, FakeEmbedder

CALLBACK = {
    "api_version": 1,
    "url": "https://shop.example.com/wp-json/paia/v1/ai/results",
    "ping_url": "https://shop.example.com/wp-json/paia/v1/ai/ping",
    "site_url": "https://shop.example.com",
    "signature": "hmac-sha256",
    "max_results": 500,
}


class Q:
    def __init__(self, client, service, embedder, processor, queue, db_path):
        self.client, self.service, self.embedder = client, service, embedder
        self.processor, self.queue, self.db_path = processor, queue, db_path

    def post(self, path, payload, method="POST"):
        body = json.dumps(payload, ensure_ascii=False).encode()
        return self.client.request(method, path, content=body, headers=signed_headers(body))

    def records(self, payload, method="POST"):
        return self.post("/v1/knowledge/records", payload, method)

    def drain(self):
        return self.client.portal.call(self.processor.drain)

    def claim(self):
        async def _claim():
            return await self.queue.repo.claim_next_job(stale_after_seconds=900)

        return self.client.portal.call(_claim)

    def sql(self, query, *params):
        with sqlite3.connect(self.db_path) as conn:
            return conn.execute(query, params).fetchall()


def make_q(tmp_path, **overrides):
    values = dict(
        QDRANT_URL=":memory:",
        EMBEDDING_DIMENSIONS=DIMS,
        CALLBACK_BATCH_WINDOW_SECONDS=0,
        INGEST_RETRY_DELAY_SECONDS=1,
    )
    values.update(overrides)
    settings = make_settings(tmp_path, **values)
    embedder = FakeEmbedder()
    service = KnowledgeService(
        settings=settings,
        db=Database.from_settings(settings),
        vectorstore=VectorStore(settings),
        embedder=embedder,
        counter=TokenCounter.approximate(),
    )
    queue = IngestQueueService.from_settings(settings)
    app = create_app(settings, knowledge_service=service, ingest_queue=queue)
    processor = IngestProcessor(settings=settings, repo=queue.repo, knowledge=service)
    return app, Q, (settings, service, embedder, processor, queue)


@pytest.fixture
def q(tmp_path):
    app, cls, (settings, service, embedder, processor, queue) = make_q(tmp_path)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        yield cls(client, service, embedder, processor, queue, settings.data_dir / "app.db")


def make_fixture(**overrides):
    @pytest.fixture
    def fixture(tmp_path):
        app, cls, (settings, service, embedder, processor, queue) = make_q(tmp_path, **overrides)
        with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
            yield cls(client, service, embedder, processor, queue, settings.data_dir / "app.db")

    return fixture


q_retry = make_fixture(INGEST_MAX_ATTEMPTS=2)
q_locked = make_fixture(PLUGIN_BASE_URL="https://shop.example.com")
q_prod_like = make_fixture(APP_ENV="production", REQUIRE_HTTPS=True)


def status_of(q, *ids):
    r = q.post("/v1/jobs/status", {"ids": list(ids)})
    assert r.status_code == 200, r.text
    return {x["id"]: x for x in r.json()["results"]}


# ------------------------------------------------------------------ accept fast, work later
def test_post_answers_200_immediately_and_does_no_work(q):
    r = q.records({"records": [{"id": "product:13199", "data": PRODUCT, "rev": "r1"}]})
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["accepted"] is True and body["queued_ids"] == ["product:13199"]
    assert body["job_id"].startswith("j_") and body["callback"] == "none"

    # nothing was chunked/embedded/stored by the request itself
    assert q.embedder.calls == []
    assert q.sql("select count(*) from records") == [(0,)]
    assert status_of(q, "product:13199")["product:13199"]["status"] == "processing"

    assert q.drain() == 1  # the worker does it
    assert len(q.embedder.calls) == 1
    assert q.sql("select count(*) from records") == [(1,)]
    done = status_of(q, "product:13199")["product:13199"]
    assert done["status"] == "trained" and done["rev"] == "r1"


def test_plugin_single_record_form_with_callback(q):
    r = q.records(
        {"event": "create", "id": "blog:15650", "rev": "abc123", "data": BLOG, "callback": CALLBACK}
    )
    assert r.status_code == 200, r.text
    assert r.json()["callback"] == "registered"
    job = q.sql("select event, rev, site_key, status from ingest_jobs")[0]
    assert job == ("create", "abc123", "shop.example.com", "pending")

    q.drain()
    rows = q.sql(
        "select site_key, callback_url, record_id, result_status, rev, state from callback_deliveries"
    )
    assert rows == [
        ("shop.example.com", CALLBACK["url"], "blog:15650", "trained", "abc123", "pending")
    ]
    assert q.sql("select state, consecutive_failures, batch_limit from callback_sites") == [
        ("healthy", 0, 50)
    ]


def test_callback_is_optional(q):
    q.records({"records": [{"id": "blog:15650", "data": BLOG}]})
    q.drain()
    assert q.sql("select count(*) from callback_deliveries") == [(0,)]
    assert q.sql("select count(*) from callback_sites") == [(0,)]


def test_newer_request_supersedes_a_not_yet_started_one(q):
    q.records({"records": [{"id": "blog:15650", "data": BLOG, "rev": "old"}], "callback": CALLBACK})
    changed = dict(BLOG, title="عنوان جدید")
    r = q.records({"records": [{"id": "blog:15650", "data": changed, "rev": "new"}], "callback": CALLBACK})
    assert r.json()["superseded"] == 1
    assert q.drain() == 1  # only the newest version is processed
    assert q.sql("select status from ingest_jobs order by id") == [("superseded",), ("done",)]
    assert q.sql("select rev from callback_deliveries") == [("new",)]
    # superseded job lost its data (not kept around)
    assert q.sql("select payload from ingest_jobs where status='superseded'") == [(None,)]


def test_delete_is_queued_and_reported_as_untrained(q):
    q.records({"records": [{"id": "blog:15650", "data": BLOG}]})
    q.drain()
    r = q.post("/v1/knowledge/records", {"ids": ["blog:15650"], "callback": CALLBACK}, "DELETE")
    assert r.status_code == 200 and r.json()["queued_ids"] == ["blog:15650"]
    assert q.sql("select count(*) from records") == [(1,)]  # not yet
    q.drain()
    assert q.sql("select count(*) from records") == [(0,)]
    assert q.sql("select result_status, rev from callback_deliveries") == [("untrained", None)]
    assert status_of(q, "blog:15650")["blog:15650"]["status"] == "untrained"


def test_put_replace_runs_in_the_worker(q):
    q.records({"records": [{"id": "blog:15650", "data": BLOG}]})
    q.drain()
    r = q.records({"records": [{"id": "blog:15650", "data": BLOG, "rev": "r2"}]}, method="PUT")
    assert r.status_code == 200
    q.drain()
    res = json.loads(q.sql("select result from ingest_jobs order by id desc limit 1")[0][0])
    assert res["status"] == "created"  # deleted completely, then added again


# ------------------------------------------------------------------ failures and retries
def test_temporary_failure_is_retried_later_then_succeeds(q_retry):
    q = q_retry
    q.embedder.fail = True
    q.records({"records": [{"id": "blog:15650", "data": BLOG, "rev": "r"}], "callback": CALLBACK})
    assert q.drain() == 1
    job = q.sql("select status, attempts, error from ingest_jobs")[0]
    assert job[0] == "pending" and job[1] == 1 and "Embedding request failed" in job[2]
    assert q.drain() == 0  # not due yet (delay)
    assert q.sql("select count(*) from callback_deliveries") == [(0,)]  # nothing reported yet

    q.embedder.fail = False
    with sqlite3.connect(q.db_path) as c:
        c.execute("update ingest_jobs set available_at='2000-01-01 00:00:00'")
    assert q.drain() == 1
    assert q.sql("select status, attempts from ingest_jobs") == [("done", 2)]
    assert q.sql("select result_status from callback_deliveries") == [("trained",)]


def test_failure_after_last_attempt_is_reported_as_failed(q_retry):
    q = q_retry
    q.embedder.fail = True
    q.records({"records": [{"id": "blog:15650", "data": BLOG, "rev": "r"}], "callback": CALLBACK})
    q.drain()
    with sqlite3.connect(q.db_path) as c:
        c.execute("update ingest_jobs set available_at='2000-01-01 00:00:00'")
    q.drain()
    assert q.sql("select status, attempts from ingest_jobs") == [("failed", 2)]
    rows = q.sql("select result_status, rev, error from callback_deliveries")
    assert rows[0][0] == "failed" and rows[0][1] is None and "Embedding" in rows[0][2]
    assert status_of(q, "blog:15650")["blog:15650"]["status"] == "failed"


def test_permanent_failure_is_not_retried(q_retry):
    q = q_retry
    q.records(
        {"records": [{"id": "blog:bad", "data": {"type": "post", "title": "", "content": ""}}],
         "callback": CALLBACK}
    )  # fmt: skip
    q.drain()
    assert q.sql("select status, attempts from ingest_jobs") == [("failed", 1)]  # no 2nd attempt
    assert "no usable text" in q.sql("select error from callback_deliveries")[0][0]


def test_job_of_a_record_in_progress_is_not_taken_twice(q):
    q.records({"records": [{"id": "blog:1", "data": BLOG}]})
    first = q.claim()
    assert first is not None
    q.records({"records": [{"id": "blog:1", "data": BLOG}]})  # a newer job for the same record
    again = q.claim()
    assert again is None  # waits until the first one is finished
    other = q.records({"records": [{"id": "blog:2", "data": BLOG}]})
    assert other.status_code == 200
    third = q.claim()
    assert third is not None and third.record_id == "blog:2"


def test_stuck_processing_job_is_recovered(q):
    q.records({"records": [{"id": "blog:15650", "data": BLOG}]})
    q.claim()  # "worker dies"
    with sqlite3.connect(q.db_path) as c:
        c.execute("update ingest_jobs set started_at='2000-01-01 00:00:00'")
    assert q.drain() == 1
    assert q.sql("select status, attempts from ingest_jobs") == [("done", 2)]


def test_queue_unavailable_gives_503(q, monkeypatch):
    async def boom(*a, **k):
        raise OperationalError("x", {}, Exception("db down"))

    monkeypatch.setattr(q.queue.repo, "enqueue", boom)
    r = q.records({"records": [{"id": "blog:15650", "data": BLOG}]})
    assert r.status_code == 503 and r.json()["error"]["code"] == "service_unavailable"


# ------------------------------------------------------------------ callback validation
@pytest.mark.parametrize(
    "mutation",
    [
        {"url": "http://shop.example.com/wp-json/paia/v1/ai/results"},  # not https
        {"url": "https://127.0.0.1/wp-json/paia/v1/ai/results"},  # loopback literal
        {"url": "https://192.168.1.5/x", "ping_url": None},  # private literal
        {"url": "https://169.254.169.254/latest", "ping_url": None},  # link-local literal
        {"url": "https://localhost/x", "ping_url": None},
        {"url": "https://shop.example.com:8080/x"},  # port not allowed
        {"url": "https://user:pw@shop.example.com/x"},  # credentials
        {"ping_url": "https://other.example.com/ping"},  # ping on another host
        {"signature": "md5"},
    ],
)
def test_bad_callbacks_are_rejected_without_queueing(q, mutation):
    r = q.records({"records": [{"id": "blog:15650", "data": BLOG}], "callback": {**CALLBACK, **mutation}})
    assert r.status_code == 422, r.text
    assert q.sql("select count(*) from ingest_jobs") == [(0,)]


def test_callback_host_is_locked_to_the_first_site(q):
    ok = q.records({"records": [{"id": "blog:1", "data": BLOG}], "callback": CALLBACK})
    assert ok.status_code == 200
    evil = {
        **CALLBACK,
        "url": "https://evil.example.org/hook",
        "ping_url": "https://evil.example.org/ping",
    }
    r = q.records({"records": [{"id": "blog:2", "data": BLOG}], "callback": evil})
    assert r.status_code == 422 and r.json()["error"]["code"] == "invalid_callback"
    # same site again (port 443 spelled out) is fine
    same = {**CALLBACK, "url": "https://SHOP.example.com:443/wp-json/paia/v1/ai/results"}
    assert q.records({"records": [{"id": "blog:3", "data": BLOG}], "callback": same}).status_code == 200


def test_callback_must_match_plugin_base_url_when_configured(q_locked):
    other = {**CALLBACK, "url": "https://other.example.com/r", "ping_url": None}
    r = q_locked.records({"records": [{"id": "blog:1", "data": BLOG}], "callback": other})
    assert r.status_code == 422
    assert q_locked.records({"records": [{"id": "blog:1", "data": BLOG}], "callback": CALLBACK}).status_code == 200


def test_auth_still_required(q):
    assert q.client.post("/v1/jobs/status", json={"ids": ["a:1"]}).status_code == 401
    assert q.client.put("/v1/knowledge/records", json={"records": []}).status_code == 401


def test_status_unknown_for_never_sent_records(q):
    assert status_of(q, "product:1")["product:1"] == {
        "id": "product:1", "status": "unknown", "job_id": None, "rev": None, "error": None,
    }  # fmt: skip


# ------------------------------------------------------------------ loop inside the API (embedded Qdrant)
def test_ingest_loop_inside_the_api_process(tmp_path):
    import time

    app, cls, (settings, service, embedder, processor, queue) = make_q(
        tmp_path, INGEST_RUN_IN_API=True, INGEST_WORKER_POLL_SECONDS=0.1
    )
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        q = cls(client, service, embedder, processor, queue, settings.data_dir / "app.db")
        r = q.records({"records": [{"id": "blog:15650", "data": BLOG, "rev": "r"}]})
        assert r.status_code == 200
        deadline = time.time() + 10
        while time.time() < deadline:
            if status_of(q, "blog:15650")["blog:15650"]["status"] == "trained":
                break
            time.sleep(0.2)
        assert status_of(q, "blog:15650")["blog:15650"]["status"] == "trained"
