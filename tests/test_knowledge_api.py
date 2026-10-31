"""End-to-end tests of /v1/knowledge with a fake embedder, in-memory Qdrant and a temp SQLite."""

from __future__ import annotations

import asyncio
import copy
import hashlib
import json
import sqlite3

import pytest
from fastapi.testclient import TestClient

from app.core.errors import UpstreamError
from app.db.base import Database
from app.main import create_app
from app.services.chunking.tokens import TokenCounter
from app.services.embedding import EmbeddingResult
from app.services.ingestion import IngestQueueService, KnowledgeService
from app.services.ingestion.processor import IngestProcessor
from app.services.vectorstore import VectorStore, point_id
from tests.conftest import make_settings, signed_headers
from tests.samples import BLOG, PAGE, PRODUCT, SITE, TAXONOMIES

DIMS = 8


class FakeEmbedder:
    model = "fake-embedding"
    dimensions = DIMS

    def __init__(self):
        self.calls: list[list[str]] = []
        self.fail = False

    async def embed(self, texts):
        if self.fail:
            raise UpstreamError("Embedding request failed (FakeError)")
        self.calls.append(list(texts))
        vectors = []
        for t in texts:
            digest = hashlib.sha256(t.encode()).digest()
            vectors.append([b / 255 + 0.01 for b in digest[:DIMS]])
        tokens = sum(len(t) // 2 for t in texts)
        return EmbeddingResult(
            vectors=vectors, prompt_tokens=tokens, total_tokens=tokens, requests=1
        )

    async def close(self):
        pass


class _Done:
    """Looks like the old synchronous response: status 200 + the finished results as JSON."""

    def __init__(self, body, queued):
        self._body, self.status_code, self.text = body, queued.status_code, queued.text

    def json(self):
        return self._body


class Env:
    """Test harness. The API only queues; `upsert()/delete()` post, then let the ingestion worker
    drain the queue and rebuild the old synchronous response shape from the finished jobs."""

    def __init__(self, client, service, embedder, db_path, processor, queue):
        self.client, self.service, self.embedder, self.db_path = client, service, embedder, db_path
        self.processor, self.queue = processor, queue

    # --- helpers -----------------------------------------------------------------------
    def _drain(self):
        # same event loop as the app (the async engine is bound to it)
        return self.client.portal.call(self.processor.drain)

    def _results(self, accepted):
        assert accepted["accepted"] is True and accepted["callback"] == "none"
        self._drain()
        ids = [j["id"] for j in accepted["jobs"]]
        status = self.client.post(
            "/v1/jobs/status", content=json.dumps({"ids": ids}).encode(),
            headers=signed_headers(json.dumps({"ids": ids}).encode()),
        ).json()["results"]  # fmt: skip
        rows = {
            r[0]: r[1:]
            for r in self.sql("select job_id, result, error, status from ingest_jobs")
        }
        results = []
        for job in accepted["jobs"]:
            raw, error, st = rows[job["job_id"]]
            data = json.loads(raw) if raw else {}
            if st == "failed":
                data = {"id": job["id"], "status": "failed", "error": error, **(data or {})}
                data["error"] = error
            results.append(data)
        confirmed = [r["id"] for r in results if r["status"] != "failed"]
        failed = [r["id"] for r in results if r["status"] == "failed"]
        assert len(status) == len(ids)
        return {"confirmed_ids": confirmed, "failed_ids": failed, "results": results,
                "summary": {k: sum(1 for r in results if r["status"] == k)
                            for k in ("created", "updated", "unchanged", "failed")}
                | {"total": len(results)}}  # fmt: skip

    def queue_upsert(self, *records, force=False, **extra):
        body = json.dumps({"records": list(records), "force": force, **extra}, ensure_ascii=False)
        return self.client.post(
            "/v1/knowledge/records", content=body.encode(), headers=signed_headers(body.encode())
        )

    def upsert(self, *records, force=False):
        r = self.queue_upsert(*records, force=force)
        if r.status_code != 200:  # validation errors etc.: hand the raw response back
            return r
        return _Done(self._results(r.json()), r)

    def put(self, *records):
        body = json.dumps({"records": list(records)}, ensure_ascii=False).encode()
        r = self.client.put("/v1/knowledge/records", content=body, headers=signed_headers(body))
        if r.status_code != 200:
            return r
        return _Done(self._results(r.json()), r)

    def delete(self, *ids):
        body = json.dumps({"ids": list(ids)}).encode()
        r = self.client.request(
            "DELETE", "/v1/knowledge/records", content=body, headers=signed_headers(body)
        )
        if r.status_code != 200:
            return r
        accepted = r.json()
        self._drain()
        rows = {
            x[0]: x[1:] for x in self.sql("select job_id, result, status, error from ingest_jobs")
        }
        results = []
        for job in accepted["jobs"]:
            raw, st, error = rows[job["job_id"]]
            data = json.loads(raw) if raw else {}
            if st == "failed":
                data = {"id": job["id"], "status": "failed", "error": error}
            results.append(data)
        return _Done(
            {
                "confirmed_ids": [x["id"] for x in results if x["status"] != "failed"],
                "failed_ids": [x["id"] for x in results if x["status"] == "failed"],
                "results": results,
            },
            r,
        )

    def sql(self, query, *params):
        with sqlite3.connect(self.db_path) as conn:
            return conn.execute(query, params).fetchall()

    def points(self, record_id=None):
        async def _scroll():
            pts, _ = await self.service.vectorstore._client.scroll(
                self.service.vectorstore.collection, limit=1000, with_payload=True
            )
            return pts

        pts = asyncio.run(_scroll())
        return [p for p in pts if record_id is None or p.payload["record_id"] == record_id]


@pytest.fixture
def env(tmp_path):
    settings = make_settings(
        tmp_path,
        QDRANT_URL=":memory:",
        EMBEDDING_DIMENSIONS=DIMS,
        LOG_LEVEL="INFO",
        INGEST_MAX_ATTEMPTS=1,  # a failed job fails at once (retries are tested separately)
    )
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
    # `with` keeps ONE event loop alive for all requests (needed by the async DB engine)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        yield Env(client, service, embedder, settings.data_dir / "app.db", processor, queue)


def rec(rid, data):
    return {"id": rid, "data": copy.deepcopy(data)}


def long_page(paragraphs: int) -> dict:
    body = "\n\n".join(
        " ".join(
            f"جمله {j} از پاراگراف {i} درباره آبیاری و نور گیاهان خانگی است." for j in range(4)
        )
        for i in range(paragraphs)
    )
    return {"type": "page", "title": "راهنمای بلند", "url": "http://x/long/", "content": body}


# ------------------------------------------------------------------------------------------
def test_requires_signature(env):
    r = env.client.post("/v1/knowledge/records", json={"records": []})
    assert r.status_code == 401
    r = env.client.request("DELETE", "/v1/knowledge/records", json={"ids": ["a:1"]})
    assert r.status_code == 401


def test_create_stores_text_db_and_vectors(env):
    r = env.upsert(rec("product:13199", PRODUCT), rec("blog:15650", BLOG), rec("page:15434", PAGE))
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["confirmed_ids"] == ["product:13199", "blog:15650", "page:15434"]
    assert body["failed_ids"] == [] and body["summary"]["created"] == 3
    product = body["results"][0]
    assert product["status"] == "created" and product["chunks"] == 2
    assert product["embedded_chunks"] == 2 and product["embedding_tokens"] > 0

    # records table: whole raw record stored once, incl. images / sku / currency / url
    rows = env.sql(
        "select record_id, kind, type, title, url, data, chunk_count from records order by record_id"
    )
    assert [r[0] for r in rows] == ["blog:15650", "page:15434", "product:13199"]
    prod = next(r for r in rows if r[0] == "product:13199")
    assert prod[1:5] == ("product", "product", "آلوئه آریستاتا", PRODUCT["url"]) and prod[6] == 2
    stored = json.loads(prod[5])
    assert stored["product"]["sku"] == "SB-101" and len(stored["images"]) == 3
    assert "آلوئه" in prod[5]  # Persian stored readable, not \u-escaped

    # chunks table: only id/record/index/text/tokens/hash
    cols = [c[1] for c in env.sql("pragma table_info(chunks)")]
    assert cols == ["chunk_id", "record_id", "index", "text", "tokens", "hash"]
    chunks = env.sql(
        'select chunk_id, record_id, "index", tokens, hash from chunks where record_id=? order by "index"',
        "product:13199",
    )
    assert [c[0] for c in chunks] == ["product:13199#0", "product:13199#1"]
    assert all(c[3] > 0 and len(c[4]) == 64 for c in chunks)

    # Qdrant: ids + only small filter fields in the payload (no text, no images)
    pts = env.points("product:13199")
    assert len(pts) == 2
    for p in pts:
        assert set(p.payload) == {
            "record_id",
            "chunk_id",
            "chunk_index",
            "kind",
            "type",
            "url",
            "title",
        }
        assert p.payload["record_id"] == "product:13199" and p.payload["type"] == "product"
        assert p.payload["url"] == PRODUCT["url"] and p.payload["title"] == "آلوئه آریستاتا"
        assert str(p.id) == point_id(p.payload["chunk_id"])
    assert sorted(p.payload["kind"] for p in pts) == ["main", "reviews"]


def test_retrieval_flow_chunk_ids_to_text_db(env):
    env.upsert(rec("product:13199", PRODUCT))
    ids = [p.payload["chunk_id"] for p in env.points("product:13199")]
    got = asyncio.run(env.service.repo.get_chunks(sorted(ids)))
    assert len(got) == 2
    assert "کد محصول (SKU): SB-101" in got[0].text
    assert got[0].url == PRODUCT["url"] and got[0].data["images"][0]["role"] == "featured"


def test_resend_unchanged_does_not_call_embedding(env):
    env.upsert(rec("product:13199", PRODUCT))
    calls = len(env.embedder.calls)
    r = env.upsert(rec("product:13199", PRODUCT)).json()
    assert r["results"][0]["status"] == "unchanged" and r["confirmed_ids"] == ["product:13199"]
    assert len(env.embedder.calls) == calls and len(env.points()) == 2

    forced = env.upsert(rec("product:13199", PRODUCT), force=True).json()["results"][0]
    assert forced["status"] == "updated" and forced["embedded_chunks"] == 2


def test_update_reembeds_only_changed_chunks(env):
    env.upsert(rec("product:13199", PRODUCT))
    changed = copy.deepcopy(PRODUCT)
    changed["reviews"].append({"rating": 3, "content": "معمولی بود.", "date": "2026-01-01"})
    before = len(env.embedder.calls)
    res = env.upsert(rec("product:13199", changed)).json()["results"][0]
    assert res["status"] == "updated"
    assert res["embedded_chunks"] == 1 and res["reused_chunks"] == 1  # only the reviews chunk
    assert len(env.embedder.calls) == before + 1 and len(env.embedder.calls[-1]) == 1
    assert (
        "معمولی بود" in env.sql("select text from chunks where chunk_id=?", "product:13199#1")[0][0]
    )


def test_update_to_shorter_text_removes_stale_vectors_and_chunks(env):
    r = env.upsert(rec("page:9", long_page(30))).json()["results"][0]
    n = r["chunks"]
    assert n > 5 and len(env.points("page:9")) == n

    r2 = env.upsert(rec("page:9", long_page(2))).json()["results"][0]
    assert r2["status"] == "updated" and r2["chunks"] == 1
    assert len(env.points("page:9")) == 1
    assert env.sql("select count(*) from chunks where record_id=?", "page:9") == [(1,)]
    assert env.sql("select chunk_count from records where record_id=?", "page:9") == [(1,)]


def test_embedding_failure_leaves_record_unconfirmed_and_others_proceed(env):
    env.embedder.fail = True
    r = env.upsert(rec("blog:15650", BLOG)).json()
    assert r["confirmed_ids"] == [] and r["failed_ids"] == ["blog:15650"]
    assert "Embedding request failed" in r["results"][0]["error"]
    assert env.sql("select count(*) from records") == [(0,)] and env.points() == []

    env.embedder.fail = False
    ok = env.upsert(
        rec("blog:15650", BLOG), rec("blog:bad", {"type": "post", "title": "", "content": ""})
    )
    body = ok.json()
    assert body["confirmed_ids"] == ["blog:15650"] and body["failed_ids"] == ["blog:bad"]
    assert (
        body["results"][1]["status"] == "failed" and "no usable text" in body["results"][1]["error"]
    )


def test_other_record_kinds(env):
    r = env.upsert(
        rec("product_taxonomies:all", TAXONOMIES),
        rec("site:1", SITE),
        rec("blog_taxonomies:all", {"category": "blog", "taxonomies": []}),
    ).json()
    assert r["summary"]["failed"] == 0, r
    assert env.sql("select type from records where record_id='site:1'") == [("site",)]


def test_delete_removes_text_db_and_vectors_completely(env):
    env.upsert(rec("product:13199", PRODUCT), rec("page:9", long_page(30)), rec("blog:15650", BLOG))
    assert len(env.points("page:9")) > 5

    r = env.delete("page:9", "product:13199", "product:404")
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["confirmed_ids"] == ["page:9", "product:13199", "product:404"]
    statuses = {x["id"]: x["status"] for x in body["results"]}
    assert statuses == {"page:9": "deleted", "product:13199": "deleted", "product:404": "not_found"}
    assert next(x for x in body["results"] if x["id"] == "product:13199")["chunks_removed"] == 2

    assert env.points("page:9") == [] and env.points("product:13199") == []
    assert env.sql("select record_id from records") == [("blog:15650",)]
    assert {c[0] for c in env.sql("select distinct record_id from chunks")} == {"blog:15650"}
    assert len(env.points("blog:15650")) == 1  # untouched

    # re-creating after a delete works like a fresh record
    again = env.upsert(rec("product:13199", PRODUCT)).json()["results"][0]
    assert again["status"] == "created" and again["embedded_chunks"] == 2


def test_validation(env):
    assert env.upsert({"id": "no-colon", "data": {}}).status_code == 422
    assert env.upsert({"id": "product:1", "data": "not json"}).status_code == 422
    assert (
        env.client.post(
            "/v1/knowledge/records",
            content=b'{"records":[]}',
            headers=signed_headers(b'{"records":[]}'),
        ).status_code
        == 422
    )
    assert env.delete().status_code == 422


def test_data_may_be_a_json_string(env):
    r = env.upsert({"id": "blog:15650", "data": json.dumps(BLOG, ensure_ascii=False)}).json()
    assert r["results"][0]["status"] == "created"


def test_pipeline_change_triggers_reembedding(env):
    env.upsert(rec("blog:15650", BLOG))
    env.service.pipeline_id += "|changed"
    res = env.upsert(rec("blog:15650", BLOG)).json()["results"][0]
    assert res["status"] == "updated" and res["embedded_chunks"] == res["chunks"]


def test_embedded_local_qdrant_path_mode(tmp_path):
    settings = make_settings(tmp_path, QDRANT_PATH=str(tmp_path / "q"), EMBEDDING_DIMENSIONS=DIMS)
    store = VectorStore(settings)

    async def run():
        await store.ensure_collection()
        from app.services.vectorstore import VectorPoint

        await store.upsert([VectorPoint("a:1#0", [0.1] * DIMS, {"record_id": "a:1", "chunk_index": 0})])
        before = await store.count_record("a:1")
        await store.delete_record("a:1")
        after = await store.count_record("a:1")
        await store.close()
        return before, after

    assert asyncio.run(run()) == (1, 0)
    assert (tmp_path / "q").is_dir()


def test_list_and_detail_endpoints(env):
    env.upsert(rec("product:13199", PRODUCT), rec("blog:15650", BLOG))

    def get(path):
        return env.client.get(path, headers=signed_headers())

    listing = get("/v1/knowledge/records").json()
    assert listing["total"] == 2 and {i["id"] for i in listing["items"]} == {"product:13199", "blog:15650"}
    only = get("/v1/knowledge/records?kind=blog").json()
    assert only["total"] == 1 and only["items"][0]["id"] == "blog:15650"

    detail = get("/v1/knowledge/records/product:13199").json()
    assert detail["in_sync"] is True and len(detail["chunks"]) == 2
    assert len(detail["qdrant_points"]) == 2 and detail["data"]["product"]["sku"] == "SB-101"
    assert get("/v1/knowledge/records/product:999").status_code == 404


def test_put_updates_by_delete_then_add(env):
    env.upsert(rec("page:9", long_page(30)), rec("blog:15650", BLOG))

    put = env.put

    r = put(rec("page:9", long_page(2)), rec("product:13199", PRODUCT))  # 2nd one is new
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["confirmed_ids"] == ["page:9", "product:13199"] and body["failed_ids"] == []
    assert [x["status"] for x in body["results"]] == ["created", "created"]  # re-added from scratch
    assert len(env.points("page:9")) == 1
    assert env.sql("select count(*) from chunks where record_id=?", "page:9") == [(1,)]
    assert len(env.points("blog:15650")) == 1  # untouched

    env.embedder.fail = True
    bad = put(rec("blog:15650", BLOG)).json()
    assert bad["failed_ids"] == ["blog:15650"]
