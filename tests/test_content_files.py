"""Texts (text:*) and files (file:*) through the ingestion queue.

Files come from a tiny local HTTP server that plays the WordPress site (the plugin's signed
short-lived link). Development mode (CALLBACK_ALLOW_PRIVATE_NETWORKS) lets the downloader use it.
"""

from __future__ import annotations

import hashlib
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest
from fastapi.testclient import TestClient

from tests.test_ingest_queue import make_q, status_of

TXT = (
    "بخش اول\n\nگارانتی همهٔ محصولات یک سال است. " * 6
    + "\n\nبخش دوم\n\nارسال سه روز کاری طول می‌کشد."
).encode()


class Site:
    """Fake WordPress site: path -> (status, bytes)."""

    def __init__(self):
        self.files: dict[str, tuple[int, bytes]] = {}
        self.hits: list[str] = []
        site = self

        class H(BaseHTTPRequestHandler):
            def do_GET(self):  # noqa: N802
                site.hits.append(self.path)
                code, body = site.files.get(self.path.split("?")[0], (404, b"nope"))
                self.send_response(code)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *a):  # silence
                pass

        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def url(self, path="/f/1.txt", exp=None):
        exp = int(time.time()) + 600 if exp is None else exp
        return f"http://127.0.0.1:{self.port}{path}?id=file:1&exp={exp}&sig=abc"

    def close(self):
        self.httpd.shutdown()


@pytest.fixture
def site():
    s = Site()
    s.files["/f/1.txt"] = (200, TXT)
    yield s
    s.close()


def make(tmp_path, **kw):
    kw.setdefault("CALLBACK_ALLOW_PRIVATE_NETWORKS", True)
    kw.setdefault("FILES_DOWNLOAD_RETRIES", 0)
    app, cls, (settings, service, embedder, processor, queue) = make_q(tmp_path, **kw)
    client = TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5))
    client.__enter__()
    return cls(client, service, embedder, processor, queue, settings.data_dir / "app.db"), settings


def file_rec(site, **over):
    data = {
        "type": "file",
        "title": "راهنمای گارانتی",
        "original_name": "guide.txt",
        "ext": "txt",
        "size": len(TXT),
        "sha256": hashlib.sha256(TXT).hexdigest(),
        "download": {"url": site.url(), "expires": int(time.time()) + 600},
    }
    data.update(over)
    return {"id": "file:1", "data": data, "rev": "r1"}


# ------------------------------------------------------------------ texts
def test_text_keeps_plugin_chunks_and_resplits_only_big_ones(tmp_path):
    q, _ = make(tmp_path)
    try:
        big = " ".join(f"جملهٔ شمارهٔ {i} دربارهٔ گلدان و آبیاری." for i in range(400))
        data = {"type": "text", "chunks": ["چانک اول کوتاه است.", "چانک دوم هم کوتاه است.", big]}
        assert (
            q.records({"records": [{"id": "text:1", "data": data, "rev": "t1"}]}).status_code == 200
        )
        assert q.drain() == 1
        assert status_of(q, "text:1")["text:1"]["status"] == "trained"
        texts = [
            r[0] for r in q.sql("select text from chunks where record_id='text:1' order by [index]")
        ]
        assert texts[0] == "چانک اول کوتاه است." and texts[1] == "چانک دوم هم کوتاه است."
        assert len(texts) > 3  # the big chunk was split
        assert q.sql("select type, kind, title from records where record_id='text:1'") == [
            ("text", "text", "چانک اول کوتاه است.")
        ]
        # same data again -> nothing embedded
        n = len(q.embedder.calls)
        q.records({"records": [{"id": "text:1", "data": data, "rev": "t1"}]})
        q.drain()
        assert len(q.embedder.calls) == n
    finally:
        q.client.__exit__(None, None, None)


def test_text_without_chunks_is_refused_at_once(tmp_path):
    q, _ = make(tmp_path)
    try:
        r = q.records({"records": [{"id": "text:2", "data": {"type": "text", "chunks": ["  "]}}]})
        assert r.status_code == 422 and r.json()["error"]["code"] == "unprocessable_record"
    finally:
        q.client.__exit__(None, None, None)


# ------------------------------------------------------------------ files
def test_file_signed_link_download_extract_index(tmp_path, site):
    q, settings = make(tmp_path)
    try:
        r = q.records({"records": [file_rec(site)]})
        assert r.status_code == 200, r.text
        assert q.drain() == 1
        assert status_of(q, "file:1")["file:1"]["status"] == "trained"
        assert len(site.hits) == 1 and "sig=abc" in site.hits[0]
        chunks = q.sql("select text from chunks where record_id='file:1'")
        assert chunks and any("گارانتی" in c[0] for c in chunks)
        assert all(c[0].startswith("فایل: راهنمای گارانتی") for c in chunks)
        (data,) = q.sql("select data from records where record_id='file:1'")[0]
        assert "sig=abc" not in data and "download" not in data  # the link is never stored
        assert (settings.files_dir / "file_1" / "source.txt").is_file()
        usage = q.client.get("/v1/storage", headers=_h("")).json()
        assert usage["files"] == 1 and usage["used_bytes"] == len(TXT)
        assert usage["max_bytes"] == settings.uploads_max_total_bytes

        # same file + same title again: nothing downloaded, nothing embedded
        q.records({"records": [file_rec(site)]})
        q.drain()
        assert len(site.hits) == 1

        # DELETE removes the index and the stored file
        assert q.post("/v1/knowledge/records", {"ids": ["file:1"]}, "DELETE").status_code == 200
        q.drain()
        assert status_of(q, "file:1")["file:1"]["status"] == "untrained"
        assert not (settings.files_dir / "file_1").exists()
        assert q.sql("select count(*) from records") == [(0,)]
    finally:
        q.client.__exit__(None, None, None)


def _h(body):
    from tests.conftest import signed_headers

    return signed_headers(body.encode() if isinstance(body, str) else body)


@pytest.mark.parametrize(
    "over, code",
    [
        ({"ext": "doc", "original_name": "a.doc"}, "unprocessable_record"),
        ({"download": {"url": "https://evil.example.com/x.txt?exp=9999999999"}}, None),
    ],
)
def test_bad_file_requests_are_refused_at_once(tmp_path, site, over, code):
    q, _ = make(tmp_path, PLUGIN_BASE_URL="https://shop.example.com")
    try:
        r = q.records({"records": [file_rec(site, **over)]})
        assert r.status_code == 422
        assert q.sql("select count(*) from ingest_jobs") == [(0,)]
    finally:
        q.client.__exit__(None, None, None)


def test_expired_link_fails_without_retry_or_download(tmp_path, site):
    q, _ = make(tmp_path)
    try:
        old = int(time.time()) - 600
        r = q.records({"records": [file_rec(site, download={"url": site.url(exp=old)})]})
        assert r.status_code == 422 and "expired" in r.text
        assert site.hits == []
    finally:
        q.client.__exit__(None, None, None)


def test_site_refusing_link_fails_permanently(tmp_path, site):
    q, _ = make(tmp_path)
    try:
        site.files["/f/1.txt"] = (403, b"no")
        q.records({"records": [file_rec(site)]})
        q.drain()
        st = status_of(q, "file:1")["file:1"]
        assert st["status"] == "failed" and "refused" in st["error"]
        assert len(site.hits) == 1  # no retries for a permanent error
        assert q.embedder.calls == []
    finally:
        q.client.__exit__(None, None, None)


def test_checksum_mismatch_fails(tmp_path, site):
    q, settings = make(tmp_path)
    try:
        q.records({"records": [file_rec(site, sha256="0" * 64)]})
        q.drain()
        st = status_of(q, "file:1")["file:1"]
        assert st["status"] == "failed" and "differs" in st["error"]
        assert not (settings.files_dir / "file_1").exists()  # no orphan file
    finally:
        q.client.__exit__(None, None, None)


def test_storage_quota_blocks_download_and_indexing(tmp_path, site):
    q, settings = make(tmp_path, UPLOADS_MAX_TOTAL_MB=1)
    try:
        big = b"x" * (1024 * 1024 + 10)
        site.files["/f/1.txt"] = (200, big)
        rec = file_rec(site, size=len(big), sha256=hashlib.sha256(big).hexdigest())
        q.records({"records": [rec]})
        q.drain()
        st = status_of(q, "file:1")["file:1"]
        assert st["status"] == "failed" and "Storage limit" in st["error"]
        assert q.embedder.calls == []
        assert q.sql("select count(*) from records") == [(0,)]
        assert not (settings.files_dir / "file_1").exists()
    finally:
        q.client.__exit__(None, None, None)


def test_pdf_with_wrong_content_is_rejected(tmp_path, site):
    q, _ = make(tmp_path)
    try:
        site.files["/f/1.pdf"] = (200, b"<html>login</html>")
        q.records(
            {
                "records": [
                    file_rec(
                        site,
                        ext="pdf",
                        original_name="a.pdf",
                        sha256="",
                        download={"url": site.url("/f/1.pdf")},
                    )
                ]
            }
        )
        q.drain()
        st = status_of(q, "file:1")["file:1"]
        assert st["status"] == "failed" and "web page" in st["error"]
    finally:
        q.client.__exit__(None, None, None)
