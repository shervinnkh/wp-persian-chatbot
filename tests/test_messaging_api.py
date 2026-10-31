"""End-to-end tests of /v1/chat/messages and /v1/conversations/... (temp SQLite, mocked downloads)."""

from __future__ import annotations

import json
import sqlite3

import httpx
import pytest
from fastapi.testclient import TestClient

from app.db.base import Database
from app.main import create_app
from app.services.messaging import MessagingService, storage
from tests.conftest import make_settings, signed_headers

PLUGIN = "http://plugin.test"
PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 64
JPEG = b"\xff\xd8\xff\xe0" + b"\x00" * 64
OGG = b"OggS" + b"\x00" * 64


class _NullKnowledge:
    """Keeps the app lifespan from building the real knowledge service (OpenAI/Qdrant)."""

    async def ensure_ready(self):
        pass

    async def close(self):
        pass


class Env:
    def __init__(self, client, settings):
        self.client, self.settings = client, settings
        self.db_path = settings.data_dir / "app.db"

    def send(self, **fields):
        body = json.dumps(fields, ensure_ascii=False).encode()
        return self.client.post("/v1/chat/messages", content=body, headers=signed_headers(body))

    def delete(self, conversation_id, message_id=None):
        url = f"/v1/conversations/{conversation_id}/messages"
        if message_id is not None:
            url += f"/{message_id}"
        return self.client.delete(url, headers=signed_headers())

    def sql(self, query, *params):
        with sqlite3.connect(self.db_path) as conn:
            return conn.execute(query, params).fetchall()

    def file(self, relative):
        return self.settings.uploads_dir / relative


def make_env(tmp_path, **overrides):
    settings = make_settings(tmp_path, PLUGIN_BASE_URL=PLUGIN, **overrides)
    service = MessagingService(settings=settings, db=Database.from_settings(settings))
    return settings, create_app(settings, knowledge_service=_NullKnowledge(), messaging_service=service)


@pytest.fixture
def env(tmp_path):
    settings, app = make_env(tmp_path)
    # `with` keeps ONE event loop alive for all requests (needed by the async DB engine)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        yield Env(client, settings)


class Downloads(dict):
    """downloads[url] = (status, bytes) is served by the fake plugin site; `.calls` = fetched urls."""

    def __init__(self):
        super().__init__()
        self.calls: list[str] = []


@pytest.fixture
def downloads(monkeypatch):
    files = Downloads()

    def handler(request: httpx.Request) -> httpx.Response:
        files.calls.append(str(request.url))
        status, content = files.get(str(request.url), (404, b""))
        return httpx.Response(status, content=content)

    real = httpx.AsyncClient

    def factory(*args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(handler)
        return real(*args, **kwargs)

    monkeypatch.setattr(storage.httpx, "AsyncClient", factory)
    return files


# ------------------------------------------------------------------------------ basics
def test_requires_signature(env):
    assert env.client.post("/v1/chat/messages", json={"role": "user"}).status_code == 401
    assert env.client.delete("/v1/conversations/1/messages").status_code == 401
    assert env.client.delete("/v1/conversations/1/messages/1").status_code == 401


def test_first_message_creates_the_single_conversation(env):
    r = env.send(visitor_uid="v1", role="user", content="سلام")
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["created_conversation"] is True and body["attachment_status"] == "none"
    msg = body["message"]
    assert msg["conversation_id"] == body["conversation_id"]
    assert (msg["role"], msg["content"], msg["visitor_uid"]) == ("user", "سلام", "v1")
    assert msg["wp_user_id"] is None and msg["attachment_path"] is None
    assert msg["created_at"].endswith("+00:00") or msg["created_at"].endswith("Z")

    r2 = env.send(visitor_uid="v1", role="assistant", content="سلام! چطور کمک کنم؟")
    assert r2.json()["conversation_id"] == body["conversation_id"]
    assert r2.json()["created_conversation"] is False
    assert env.sql("select count(*) from conversations") == [(1,)]
    assert env.sql("select role, content from messages order by id") == [
        ("user", "سلام"),
        ("assistant", "سلام! چطور کمک کنم؟"),
    ]


def test_logged_in_user_has_one_conversation_across_devices_and_guest_history(env):
    guest = env.send(visitor_uid="browser-A", role="user", content="من مهمانم").json()
    # the same browser logs in -> the guest conversation moves to the account
    logged = env.send(visitor_uid="browser-A", wp_user_id=12, role="user", content="حالا واردم")
    assert logged.json()["conversation_id"] == guest["conversation_id"]
    # another device of the same account -> still the same conversation
    other = env.send(visitor_uid="browser-B", wp_user_id=12, role="user", content="دستگاه دوم")
    assert other.json()["conversation_id"] == guest["conversation_id"]
    assert other.json()["message"]["visitor_uid"] == "browser-B"
    assert env.sql("select count(*) from conversations") == [(1,)]
    assert env.sql("select owner_key, wp_user_id from conversations") == [("u:12", 12)]
    assert env.sql("select count(*) from messages") == [(3,)]


def test_wp_user_id_zero_is_a_guest(env):
    r = env.send(visitor_uid="v9", wp_user_id=0, role="user", content="x")
    assert r.status_code == 200
    assert r.json()["message"]["wp_user_id"] is None
    assert env.sql("select owner_key from conversations") == [("v:v9",)]


def test_different_users_get_different_conversations(env):
    a = env.send(visitor_uid="a", role="user", content="x").json()["conversation_id"]
    b = env.send(visitor_uid="b", role="user", content="x").json()["conversation_id"]
    c = env.send(visitor_uid="c", wp_user_id=5, role="user", content="x").json()["conversation_id"]
    assert len({a, b, c}) == 3


def test_send_with_conversation_id(env):
    cid = env.send(visitor_uid="v1", role="user", content="x").json()["conversation_id"]
    r = env.send(conversation_id=cid, role="assistant", content="پاسخ")
    assert r.status_code == 200
    assert r.json()["conversation_id"] == cid
    assert r.json()["message"]["visitor_uid"] == "v1"  # taken from the conversation
    missing = env.send(conversation_id=999, role="assistant", content="پاسخ")
    assert missing.status_code == 404 and missing.json()["error"]["code"] == "not_found"


def test_admin_and_assistant_fields(env):
    r = env.send(
        visitor_uid="v1", role="admin", content="پشتیبان هستم", admin_user_id=3, admin_name="علی"
    )
    assert r.status_code == 200, r.text
    m = r.json()["message"]
    assert (m["role"], m["admin_user_id"], m["admin_name"]) == ("admin", 3, "علی")

    r = env.send(
        visitor_uid="v1", role="assistant", content="چند پیشنهاد", suggestions=["الف", " ب ", ""]
    )
    assert r.json()["message"]["suggestions"] == ["الف", "ب"]

    # admin fields are dropped for non-admin roles
    r = env.send(visitor_uid="v1", role="user", content="x", admin_user_id=3, admin_name="علی")
    m = r.json()["message"]
    assert m["admin_user_id"] is None and m["admin_name"] is None


@pytest.mark.parametrize(
    "fields",
    [
        {"role": "user", "content": "x"},  # no conversation_id / visitor_uid
        {"visitor_uid": "v", "role": "robot", "content": "x"},  # unknown role
        {"visitor_uid": "v", "role": "user"},  # nothing to save
        {"visitor_uid": "v", "role": "user", "content": "   "},
        {"visitor_uid": "v", "role": "user", "attachment_type": "image"},  # type without url
        {"visitor_uid": "v", "role": "user", "attachment_url": f"{PLUGIN}/a.png"},
        {"visitor_uid": "v", "role": "user", "attachment_type": "video", "attachment_url": PLUGIN},
        {"visitor_uid": "v", "role": "user", "attachment_type": "image", "attachment_url": "ftp://x"},
        {"visitor_uid": "v", "role": "admin", "content": "x"},  # admin without admin_user_id
        {"visitor_uid": "bad uid!", "role": "user", "content": "x"},
        {"visitor_uid": "v", "role": "assistant", "content": "x", "suggestions": list("abcde")},
    ],
)
def test_validation_errors(env, fields):
    r = env.send(**fields)
    assert r.status_code == 422, r.text
    assert r.json()["error"]["code"] == "validation_error"
    assert env.sql("select count(*) from messages") == [(0,)]


# ------------------------------------------------------------------------------ attachments
def test_image_is_downloaded_and_path_stored(env, downloads):
    url = f"{PLUGIN}/wp-content/uploads/2026/10/photo.png"
    downloads[url] = (200, PNG)
    r = env.send(
        visitor_uid="v1", role="user", content="این عکس", attachment_type="image", attachment_url=url
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["attachment_status"] == "stored"
    rel = body["message"]["attachment_path"]
    assert rel.startswith("image/") and rel.endswith(".png") and not rel.startswith("/")
    assert env.file(rel).read_bytes() == PNG
    assert body["message"]["attachment_url"] == url
    assert env.sql("select attachment_path from messages") == [(rel,)]
    assert not list(env.settings.uploads_dir.rglob("*.part"))


def test_voice_is_downloaded(env, downloads):
    url = f"{PLUGIN}/wp-content/uploads/voice.bin"  # extension in the URL is irrelevant
    downloads[url] = (200, OGG)
    r = env.send(visitor_uid="v1", role="user", attachment_type="voice", attachment_url=url)
    assert r.status_code == 200, r.text  # a voice message needs no text
    rel = r.json()["message"]["attachment_path"]
    assert rel.startswith("voice/") and rel.endswith(".ogg")
    assert env.file(rel).read_bytes() == OGG


def test_message_is_saved_even_when_download_fails(env, downloads):
    cases = {
        f"{PLUGIN}/missing.png": (404, b""),  # HTTP error
        f"{PLUGIN}/fake.png": (200, b"<html>not an image</html>" + b"x" * 64),  # wrong type
        f"{PLUGIN}/empty.png": (200, b""),
    }
    downloads.update(cases)
    for url in cases:
        r = env.send(
            visitor_uid="v1", role="user", content="عکس", attachment_type="image", attachment_url=url
        )
        assert r.status_code == 200, r.text
        assert r.json()["attachment_status"] == "failed"
        assert r.json()["message"]["attachment_path"] is None
    assert env.sql("select count(*) from messages where attachment_path is null") == [(3,)]
    assert not [p for p in env.settings.uploads_dir.rglob("*") if p.is_file() and p.name != ".gitkeep"]


def test_image_bytes_are_not_accepted_as_voice(env, downloads):
    url = f"{PLUGIN}/a.ogg"
    downloads[url] = (200, PNG)
    r = env.send(visitor_uid="v1", role="user", attachment_type="voice", attachment_url=url)
    assert r.json()["attachment_status"] == "failed"


def test_foreign_host_is_never_fetched(env, downloads):
    for url in (
        "http://evil.test/a.png",
        "http://169.254.169.254/latest/meta-data",
        "http://plugin.test:8080/a.png",  # same host, other port
    ):
        r = env.send(visitor_uid="v1", role="user", attachment_type="image", attachment_url=url)
        assert r.json()["attachment_status"] == "failed"
    assert downloads.calls == []


def test_too_large_file_is_rejected(tmp_path, monkeypatch):
    settings, app = make_env(tmp_path, MESSAGE_ATTACHMENT_MAX_MB=1)
    big = {f"{PLUGIN}/big.png": (200, PNG + b"\x00" * (1024 * 1024 + 10))}
    real = httpx.AsyncClient

    def factory(*args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(
            lambda req: httpx.Response(big[str(req.url)][0], content=big[str(req.url)][1])
        )
        return real(*args, **kwargs)

    monkeypatch.setattr(storage.httpx, "AsyncClient", factory)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        e = Env(client, settings)
        r = e.send(
            visitor_uid="v1", role="user", attachment_type="image", attachment_url=f"{PLUGIN}/big.png"
        )
    assert r.status_code == 200 and r.json()["attachment_status"] == "failed"
    assert not [p for p in settings.uploads_dir.rglob("*") if p.is_file() and p.name != ".gitkeep"]


def test_downloads_disabled_without_plugin_base_url(tmp_path):
    settings = make_settings(tmp_path)  # PLUGIN_BASE_URL empty
    service = MessagingService(settings=settings, db=Database.from_settings(settings))
    app = create_app(settings, knowledge_service=_NullKnowledge(), messaging_service=service)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        r = Env(client, settings).send(
            visitor_uid="v1", role="user", attachment_type="image", attachment_url=f"{PLUGIN}/a.png"
        )
    assert r.status_code == 200 and r.json()["attachment_status"] == "failed"


def test_production_requires_https_for_downloads(tmp_path):
    settings = make_settings(
        tmp_path, APP_ENV="production", REQUIRE_HTTPS=True, PLUGIN_BASE_URL="https://plugin.test"
    )
    storage._check_url("https://plugin.test/a.png", settings)
    with pytest.raises(storage.AttachmentError):
        storage._check_url("http://plugin.test/a.png", settings)


# ------------------------------------------------------------------------------ delete
def test_delete_one_message_removes_row_and_file(env, downloads):
    url = f"{PLUGIN}/a.png"
    downloads[url] = (200, PNG)
    sent = env.send(
        visitor_uid="v1", role="user", content="عکس", attachment_type="image", attachment_url=url
    ).json()
    other = env.send(visitor_uid="v1", role="user", content="می‌ماند").json()
    cid, mid = sent["conversation_id"], sent["message"]["id"]
    path = env.file(sent["message"]["attachment_path"])
    assert path.exists()

    r = env.delete(cid, mid)
    assert r.status_code == 200
    assert r.json() == {"conversation_id": cid, "message_id": mid, "deleted": True}
    assert not path.exists()
    assert env.sql("select id from messages") == [(other["message"]["id"],)]

    again = env.delete(cid, mid)
    assert again.status_code == 404 and again.json()["error"]["code"] == "not_found"


def test_delete_message_of_another_conversation_is_404(env):
    a = env.send(visitor_uid="a", role="user", content="x").json()
    b = env.send(visitor_uid="b", role="user", content="y").json()
    r = env.delete(b["conversation_id"], a["message"]["id"])
    assert r.status_code == 404
    assert env.sql("select count(*) from messages") == [(2,)]


def test_clear_conversation_messages_keeps_the_conversation(env, downloads):
    url = f"{PLUGIN}/v.ogg"
    downloads[url] = (200, OGG)
    first = env.send(visitor_uid="v1", role="user", attachment_type="voice", attachment_url=url)
    cid = first.json()["conversation_id"]
    path = env.file(first.json()["message"]["attachment_path"])
    env.send(visitor_uid="v1", role="assistant", content="پاسخ")
    other = env.send(visitor_uid="zzz", role="user", content="کاربر دیگر").json()

    r = env.delete(cid)
    assert r.status_code == 200 and r.json() == {"conversation_id": cid, "deleted_count": 2}
    assert not path.exists()
    assert env.sql("select conversation_id from messages") == [(other["conversation_id"],)]
    assert env.sql("select count(*) from conversations") == [(2,)]

    # the user's single conversation is reused afterwards
    again = env.send(visitor_uid="v1", role="user", content="دوباره")
    assert again.json()["conversation_id"] == cid and again.json()["created_conversation"] is False

    assert env.delete(12345).status_code == 404


# ------------------------------------------------------------------------------ unit
def test_sniff_extension():
    assert storage.sniff_extension("image", JPEG) == "jpg"
    assert storage.sniff_extension("image", PNG) == "png"
    assert storage.sniff_extension("image", b"GIF89a" + b"\x00" * 20) == "gif"
    assert storage.sniff_extension("image", b"RIFF\x00\x00\x00\x00WEBPVP8 ") == "webp"
    assert storage.sniff_extension("image", b"MZ\x90\x00" + b"\x00" * 20) is None
    assert storage.sniff_extension("voice", OGG) == "ogg"
    assert storage.sniff_extension("voice", b"RIFF\x00\x00\x00\x00WAVEfmt ") == "wav"
    assert storage.sniff_extension("voice", b"\x1a\x45\xdf\xa3" + b"\x00" * 20) == "webm"
    assert storage.sniff_extension("voice", b"\x00\x00\x00\x20ftypM4A " + b"\x00" * 8) == "m4a"
    assert storage.sniff_extension("voice", b"ID3\x04" + b"\x00" * 20) == "mp3"
    assert storage.sniff_extension("voice", JPEG) is None
    assert storage.sniff_extension("document", PNG) is None


def test_resolve_attachment_path_stays_inside_uploads(tmp_path):
    settings = make_settings(tmp_path)
    inside = storage.resolve_attachment_path(settings, "image/2026/10/a.png")
    assert inside is not None and settings.uploads_dir.resolve() in inside.parents
    assert storage.resolve_attachment_path(settings, "../data/app.db") is None
    assert storage.resolve_attachment_path(settings, "/etc/passwd") is None
    assert storage.resolve_attachment_path(settings, None) is None
