"""0.3.7: model allow-list, prompt context, get_log, failed-delete `operation`."""

from __future__ import annotations

import json

from fastapi.testclient import TestClient

from app.schemas.messaging import SendMessageRequest
from app.services.conversation.prompt import PromptContext
from tests.conftest import make_settings, signed_headers
from tests.test_messaging_api import Env, _NullKnowledge, make_env  # noqa: F401
from tests.test_messaging_api import env as env  # noqa: F401  (fixture)


def test_model_allow_list(tmp_path):
    s = make_settings(tmp_path, AI_ALLOWED_MODELS="gpt-a, gpt-b")
    assert s.default_model == "gpt-a"
    assert s.resolve_model("gpt-b") == "gpt-b"
    assert s.resolve_model("nope") == "gpt-a"
    assert s.resolve_model(None) == "gpt-a"
    assert s.resolve_model("") == "gpt-a"


def test_prompt_context_only_what_was_sent(tmp_path):
    s = make_settings(tmp_path, AI_ALLOWED_MODELS="m1,m2")
    req = SendMessageRequest(
        visitor_uid="v", role="user", content="x", generate_ai_answer=True,
        model="m2", tone="friendly",
        site={"title": "فروشگاه", "description": "لوازم"},
        page={"url": "https://e.com/p/a", "slug": "a", "title": "محصول الف", "type": "product"},
    )  # fmt: skip
    ctx = PromptContext.from_request(req, s)
    text = ctx.system_prompt()
    assert ctx.model == "m2"
    assert "فروشگاه" in text and "محصول الف" in text and "type: product" in text
    bare = PromptContext.from_request(
        SendMessageRequest(visitor_uid="v", role="user", content="x", generate_ai_answer=True), s
    )
    assert bare.model == "m1" and not bare.has_page and "currently on this page" not in bare.system_prompt()


def test_model_reported_and_fallback(env):
    r = env.send(visitor_uid="v1", role="user", content="سلام", generate_ai_answer=True, model="zzz")
    assert r.json()["ai_answer"]["model"] == "gpt-5-4"


def test_no_generate_ignores_extras(env):
    r = env.send(visitor_uid="v1", role="user", content="سلام", model="x", tone="friendly")
    body = r.json()
    assert r.status_code == 200 and body["ai_answer"]["status"] == "skipped"


def test_get_log_after_ids(tmp_path):
    settings = make_settings(tmp_path, LOG_TO_FILE=False)
    settings.log_dir.mkdir(parents=True, exist_ok=True)
    lines = [
        f"2026-11-01 10:00:{i:02d},000 | INFO     | rid{i} | app.t | line {i}" for i in range(10)
    ]
    lines.insert(5, "Traceback (most recent call last):")
    (settings.log_dir / "app.log").write_text("\n".join(lines) + "\n", encoding="utf-8")
    from app.main import create_app

    app = create_app(settings, knowledge_service=_NullKnowledge())
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as c:

        def call(**body):
            raw = json.dumps(body).encode()
            r = c.post("/v1/get_log", content=raw, headers=signed_headers(raw))
            assert r.status_code == 200, r.text
            return r.json()

        first = call(limit=4)
        assert [e["message"].split("\n")[0] for e in first["entries"]][-1] == "line 9"
        assert first["count"] == 4 and not first["reset"]
        full = call()
        assert full["count"] == 10  # the traceback line is glued to its entry
        ids = [e["id"] for e in full["entries"]]
        after = call(after=ids[5:8])
        assert [e["id"] for e in after["entries"]] == ids[8:]
        assert call(after=ids[-3:])["entries"] == []
        lost = call(after=["deadbeef0000"], limit=3)
        assert lost["reset"] and lost["count"] == 3


def test_failed_delete_payload_has_operation():
    from types import SimpleNamespace

    from app.services.ingestion.delivery import DeliveryService

    def row(action):
        return SimpleNamespace(record_id="product:1", result_status="failed", job_id="j_1",
                               rev=None, error="x", action=action, kind=None, payload=None)  # fmt: skip

    svc = DeliveryService.__new__(DeliveryService)
    body = json.loads(svc._payload([row("delete"), row("upsert")], "d1"))
    assert body["results"][0]["operation"] == "delete"
    assert "operation" not in body["results"][1]
