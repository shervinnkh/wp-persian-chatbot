"""History building, fixed answer, summarization queue + worker (temp SQLite, fake summarizer)."""

from __future__ import annotations

import sqlite3

import pytest

from app.services.chunking.tokens import TokenCounter
from app.services.conversation.answer import FIXED_ANSWER
from app.services.conversation.summarizer import (
    SUMMARIZATION_SYSTEM_PROMPT,
    build_user_prompt,
)
from app.workers import summarizer as worker
from tests.test_messaging_api import Env, _NullKnowledge, make_env  # noqa: F401
from tests.test_messaging_api import env as env  # noqa: F401  (fixture)


class FakeSummarizer:
    def __init__(self, text="خلاصهٔ آزمایشی", fail_times=0):
        self.text, self.fail_times, self.calls = text, fail_times, []

    async def summarize(self, previous_summary, messages):
        self.calls.append((previous_summary, [(m.speaker, m.text) for m in messages]))
        if self.fail_times:
            self.fail_times -= 1
            raise RuntimeError("boom")
        return self.text

    async def close(self):
        pass


def _uid(n=1):
    return f"visitor{n}"


def _ask(env, content="سلام", generate=True, uid="v1", **extra):
    fields = dict(visitor_uid=uid, role="user", content=content, **extra)
    if generate is not None:
        fields["generate_ai_answer"] = generate
    return env.send(**fields)


def _roles(env):
    return [r for (r,) in env.sql("select role from messages order by id")]


# ------------------------------------------------------------------ answer switch
def test_generate_true_saves_user_message_and_fixed_answer(env):
    r = _ask(env)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["message"]["role"] == "user"
    ai = body["ai_answer"]
    assert ai["status"] == "generated"
    assert ai["message"]["role"] == "assistant" and ai["message"]["content"] == FIXED_ANSWER
    assert ai["message"]["conversation_id"] == body["conversation_id"]
    assert ai["full_history_tokens"] > 0 and ai["context_tokens"] > 0
    assert _roles(env) == ["user", "assistant"]


@pytest.mark.parametrize("flag", [None, False])
def test_missing_or_false_only_saves(env, flag):
    body = _ask(env, generate=flag).json()
    assert body["ai_answer"] == {
        "status": "skipped", "message": None, "full_history_tokens": None,
        "context_tokens": None, "summarization_scheduled": False,
    }  # fmt: skip
    assert _roles(env) == ["user"]


def test_admin_and_assistant_messages_are_only_saved(env):
    for role in ("assistant", "admin"):
        extra = {"admin_user_id": 3} if role == "admin" else {}
        r = env.send(visitor_uid="v1", role=role, content="x", generate_ai_answer=True, **extra)
        assert r.status_code == 200 and r.json()["ai_answer"]["status"] == "skipped"
    assert _roles(env) == ["assistant", "admin"]


def test_generate_flag_must_be_boolean(env):
    r = env.send(visitor_uid="v1", role="user", content="x", generate_ai_answer="maybe")
    assert r.status_code == 422


# ------------------------------------------------------------------ history shape
def test_history_is_summary_plus_last_three_pairs_plus_question(tmp_path):
    from app.db.base import Database
    from app.services.messaging import MessagingService
    from tests.conftest import make_settings

    settings = make_settings(tmp_path)
    service = MessagingService(
        settings=settings, db=Database.from_settings(settings),
        token_counter=TokenCounter.approximate(),
    )  # fmt: skip

    import asyncio

    from app.schemas.messaging import SendMessageRequest
    from app.services.conversation.history import HistoryBuilder

    async def scenario():
        await service.ensure_ready()
        for i in range(1, 6):  # 5 full pairs
            await service.send(SendMessageRequest(visitor_uid="v", role="user", content=f"q{i}"))
            await service.send(
                SendMessageRequest(visitor_uid="v", role="assistant", content=f"a{i}")
            )
            if i == 3:  # a human agent joins pair 3
                await service.send(
                    SendMessageRequest(
                        visitor_uid="v", role="admin", content="agent3", admin_user_id=1,
                        admin_name="علی",
                    )
                )  # fmt: skip
        last = await service.send(SendMessageRequest(visitor_uid="v", role="user", content="q6"))
        conv = await service.repo.get_conversation(last.conversation_id)
        await service.repo.set_summary(conv.id, "SUMMARY-TEXT", 9)
        conv = await service.repo.get_conversation(conv.id)
        builder = HistoryBuilder(
            service.repo, service.counter, recent_pairs=3, trigger_tokens=10**6
        )
        history = await builder.build(conv, last.message)
        await service.close()
        return history

    h = asyncio.run(scenario())
    assert h.summary == "SUMMARY-TEXT" and h.summarized_upto_message_id == 9
    texts = [m.text for m in h.recent]
    assert texts == ["q3", "a3", "agent3", "q4", "a4", "q5", "a5"]  # last 3 USER turns in full
    assert h.question.text == "q6" and h.question.role == "user"
    msgs = h.llm_messages()
    assert [m["role"] for m in msgs] == ["user", "assistant", "assistant", "user", "assistant",
                                         "user", "assistant", "user"]  # fmt: skip
    assert msgs[2]["content"].startswith("[Message from a human support agent]")
    assert h.needs_summary is False


# ------------------------------------------------------------------ threshold -> queue
def _long(n=200):
    return "این یک متن طولانی برای شمارش توکن است. " * n


def test_over_threshold_queues_summary_after_the_answer(tmp_path):
    from fastapi.testclient import TestClient

    settings, app = make_env(tmp_path, SUMMARY_TRIGGER_TOKENS=500)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        e = Env(client, settings)
        short = _ask(e, "سلام").json()
        assert short["ai_answer"]["summarization_scheduled"] is False
        assert e.sql("select count(*) from summary_requests") == [(0,)]

        big = _ask(e, _long()).json()
        ai = big["ai_answer"]
        assert ai["status"] == "generated" and ai["summarization_scheduled"] is True
        assert ai["full_history_tokens"] > 500
        rows = e.sql("select conversation_id, upto_message_id, status from summary_requests")
        assert rows == [(big["conversation_id"], ai["message"]["id"], "pending")]

        # a second long message while one request is still waiting does not queue another
        _ask(e, _long())
        assert e.sql("select count(*) from summary_requests") == [(1,)]


# ------------------------------------------------------------------ worker
def _db_scalar(path, q, *p):
    with sqlite3.connect(path) as conn:
        return conn.execute(q, p).fetchall()


def _prepare_queued(tmp_path, **overrides):
    from fastapi.testclient import TestClient

    settings, app = make_env(tmp_path, SUMMARY_TRIGGER_TOKENS=500, **overrides)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        e = Env(client, settings)
        _ask(e, "سلام")
        _ask(e, _long())
        _ask(e, "یک پیام کوتاه بعدی")  # still unsummarized
    return settings, e


def test_worker_summarizes_and_stores_on_conversation(tmp_path):
    import asyncio

    settings, e = _prepare_queued(tmp_path)
    fake = FakeSummarizer("SUMMARY-1")
    asyncio.run(worker.run(settings, summarizer=fake, once=True))

    # asked with no previous summary and every message up to the queued id (the answer of #2)
    prev, msgs = fake.calls[0]
    assert prev is None
    assert [s for s, _ in msgs] == ["user", "AI assistant", "user", "AI assistant"]
    summary, upto = e.sql("select summary, summarized_upto_message_id from conversations")[0]
    assert summary == "SUMMARY-1" and upto == 4
    assert e.sql("select status, attempts from summary_requests") == [("done", 1)]


def test_second_summary_merges_with_the_previous_one(tmp_path):
    import asyncio

    from fastapi.testclient import TestClient

    settings, e = _prepare_queued(tmp_path)
    asyncio.run(worker.run(settings, summarizer=FakeSummarizer("SUMMARY-1"), once=True))

    _, app = make_env(tmp_path, SUMMARY_TRIGGER_TOKENS=500)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        e2 = Env(client, settings)
        body = _ask(e2, _long()).json()
        assert body["ai_answer"]["summarization_scheduled"] is True
    fake = FakeSummarizer("SUMMARY-2")
    asyncio.run(worker.run(settings, summarizer=fake, once=True))
    prev, msgs = fake.calls[0]
    assert prev == "SUMMARY-1"
    assert [t for _, t in msgs][0] == "یک پیام کوتاه بعدی"  # continues after the old cutoff
    assert e2.sql("select summary from conversations") == [("SUMMARY-2",)]


def test_worker_retries_then_fails_for_good(tmp_path):
    import asyncio

    settings, e = _prepare_queued(tmp_path, SUMMARY_MAX_ATTEMPTS=2, SUMMARY_RETRY_DELAY_SECONDS=1)
    fake = FakeSummarizer(fail_times=99)
    asyncio.run(worker.run(settings, summarizer=fake, once=True))
    assert e.sql("select status, attempts from summary_requests") == [("pending", 1)]  # waits
    import time

    time.sleep(1.2)
    asyncio.run(worker.run(settings, summarizer=fake, once=True))
    status, attempts, error = e.sql("select status, attempts, error from summary_requests")[0]
    assert (status, attempts) == ("failed", 2) and "boom" in error
    assert e.sql("select summary from conversations") == [(None,)]


def test_clearing_the_chat_drops_summary_and_requests(tmp_path):
    import asyncio

    from fastapi.testclient import TestClient

    settings, e = _prepare_queued(tmp_path)
    asyncio.run(worker.run(settings, summarizer=FakeSummarizer("S"), once=True))
    _, app = make_env(tmp_path)
    with TestClient(app, base_url="http://testserver", client=("203.0.113.10", 5)) as client:
        e2 = Env(client, settings)
        conv_id = e2.sql("select id from conversations")[0][0]
        assert e2.delete(conv_id).status_code == 200
        assert e2.sql("select summary, summarized_upto_message_id from conversations") == [
            (None, None)
        ]
        assert e2.sql("select count(*) from summary_requests") == [(0,)]


# ------------------------------------------------------------------ prompt + migration
def test_summarization_prompt_is_english_and_transcript_is_labelled():
    assert SUMMARIZATION_SYSTEM_PROMPT.isascii()
    from app.services.conversation.history import HistoryMessage

    msg = HistoryMessage(
        id=1, role="assistant", sender="admin", text="سلام", speaker="human support agent (علی)"
    )
    prompt = build_user_prompt(None, [msg])
    assert "(none)" in prompt and "[human support agent (علی)]: سلام" in prompt


def test_old_database_gets_the_new_columns(tmp_path):
    import asyncio

    from app.db.base import Database
    from tests.conftest import make_settings

    settings = make_settings(tmp_path)
    settings.data_dir.mkdir(parents=True, exist_ok=True)
    path = settings.data_dir / "app.db"
    with sqlite3.connect(path) as conn:  # the 0.3.4 shape of the table
        conn.execute(
            "create table conversations (id integer primary key autoincrement, "
            "owner_key varchar(80) not null unique, visitor_uid varchar(64) not null, "
            "wp_user_id integer, created_at datetime not null, updated_at datetime not null)"
        )
    db = Database.from_settings(settings)

    async def go():
        await db.init()
        await db.dispose()

    asyncio.run(go())
    with sqlite3.connect(path) as conn:
        cols = {r[1] for r in conn.execute("pragma table_info(conversations)")}
    assert {"summary", "summarized_upto_message_id", "summary_updated_at"} <= cols


def test_without_summary_the_whole_conversation_is_sent(tmp_path):
    import asyncio

    from app.db.base import Database
    from app.schemas.messaging import SendMessageRequest
    from app.services.conversation.history import HistoryBuilder
    from app.services.messaging import MessagingService
    from tests.conftest import make_settings

    settings = make_settings(tmp_path)
    service = MessagingService(
        settings=settings, db=Database.from_settings(settings),
        token_counter=TokenCounter.approximate(),
    )  # fmt: skip

    async def scenario():
        await service.ensure_ready()
        for i in range(1, 8):  # 7 pairs, no summary
            await service.send(SendMessageRequest(visitor_uid="v", role="user", content=f"q{i}"))
            await service.send(
                SendMessageRequest(visitor_uid="v", role="assistant", content=f"a{i}")
            )
        last = await service.send(SendMessageRequest(visitor_uid="v", role="user", content="q8"))
        builder = HistoryBuilder(
            service.repo, service.counter, recent_pairs=3, trigger_tokens=10**6
        )
        conv = await service.repo.get_conversation(last.conversation_id)
        no_summary = await builder.build(conv, last.message)
        # summary covers up to a2 (id 4): everything after it stays in full, not only 3 pairs
        await service.repo.set_summary(conv.id, "S", 4)
        conv = await service.repo.get_conversation(conv.id)
        with_summary = await builder.build(conv, last.message)
        await service.close()
        return no_summary, with_summary

    h1, h2 = asyncio.run(scenario())
    assert h1.summary is None
    assert [m.text for m in h1.recent] == [f"{r}{i}" for i in range(1, 8) for r in "qa"]
    assert h1.question.text == "q8"
    assert h2.summary == "S"
    assert [m.text for m in h2.recent] == [f"{r}{i}" for i in range(3, 8) for r in "qa"]
