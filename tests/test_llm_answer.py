"""0.5.0: base prompt, RAG retrieval, LLM answer generator (fake embedder/Qdrant/OpenAI)."""

from __future__ import annotations

import asyncio
import json
from types import SimpleNamespace

import pytest

from app.db.base import Database
from app.db.repository import RetrievedChunk
from app.schemas.messaging import SendMessageRequest
from app.services.conversation.history import HistoryMessage, PreparedHistory
from app.services.conversation.llm_answer import LLMAnswerGenerator
from app.services.conversation.prompt import KnowledgeSnippet, PromptContext
from app.services.conversation.reply_format import (
    GeneratedAnswer,
    ReplyFormatError,
    parse_reply,
)
from app.services.conversation.retrieval import KnowledgeRetriever, build_search_query
from app.services.messaging import MessagingService
from tests.conftest import make_settings


def _hm(i, role, text):
    return HistoryMessage(id=i, role=role, sender=role, text=text, speaker=role)


def _history(question, recent=(), summary=None):
    return PreparedHistory(
        conversation_id=1, summary=summary, summarized_upto_message_id=None,
        recent=list(recent), question=_hm(99, "user", question),
    )  # fmt: skip


def _chunk(cid, text, url="https://e.com/p/1"):
    return RetrievedChunk(cid, cid.split("#")[0], 0, text, 10, "product", "گلدان سرامیکی", url, {})


# ---------------------------------------------------------------- prompt
def test_prompt_dynamic_parts_and_rules(tmp_path):
    s = make_settings(tmp_path, TONE_FRIENDLY="FRIENDLY-TEXT", TONE_FORMAL="FORMAL-TEXT")
    req = SendMessageRequest(
        visitor_uid="v", role="user", content="x", generate_ai_answer=True, tone="friendly",
        site={"title": "گل‌فروشی", "description": "گیاه و گلدان"}, notes="همیشه سلام کن",
        page={"url": "https://e.com/p/1", "title": "گلدان", "type": "product"},
    )  # fmt: skip
    ctx = PromptContext.from_request(req, s)
    text = ctx.system_prompt(
        tone_text=s.tone_text(ctx.tone),
        knowledge=[KnowledgeSnippet("متن چانک", title="گلدان", type="product", url="https://e.com/p/1")],
        summary="کاربر گلدان می‌خواهد",
    )
    assert "FRIENDLY-TEXT" in text and "FORMAL-TEXT" not in text
    assert "گل‌فروشی" in text and "همیشه سلام کن" in text and "https://e.com/p/1" in text
    assert "متن چانک" in text and "کاربر گلدان می‌خواهد" in text
    assert "NEVER state, estimate" in text and '"suggestions"' in text


def test_prompt_with_nothing_optional_does_not_fail(tmp_path):
    s = make_settings(tmp_path)
    ctx = PromptContext.from_request(
        SendMessageRequest(visitor_uid="v", role="user", content="x", generate_ai_answer=True), s
    )
    text = ctx.system_prompt(tone_text=s.tone_text(ctx.tone))
    assert "## Knowledge" not in text and "<owner_notes>" not in text
    assert s.tone_text(None) == s.TONE_FORMAL == s.tone_text("weird")  # default tone = formal
    assert "no passage matched" in ctx.system_prompt(knowledge=[])


def test_notes_are_cut_not_rejected():
    req = SendMessageRequest(
        visitor_uid="v", role="user", content="x", generate_ai_answer=True, notes="a" * 5000
    )
    assert len(req.notes) == 1500


# ---------------------------------------------------------------- reply format
def test_parse_reply():
    a = parse_reply('```json\n{"text":"سلام","suggestions":["الف","الف","ب","ج","د","ه"]}\n```')
    assert a.text == "سلام" and a.suggestions == ["الف", "ب", "ج", "د"]
    assert parse_reply("متن ساده").suggestions == []
    for bad in ("", '{"text": "نیمه', '{"text": ""}', "[1]"):
        with pytest.raises(ReplyFormatError):
            parse_reply(bad)


# ---------------------------------------------------------------- retrieval
def test_search_query_short_followup_uses_previous_user_message():
    recent = [_hm(1, "user", "گلدان سرامیکی می‌خوام"), _hm(2, "assistant", "بله")]
    assert build_search_query(_history("سایزش؟", recent)) == "گلدان سرامیکی می‌خوام\nسایزش؟"
    long_q = "این گلدان برای فضای داخلی آپارتمان مناسب است یا نه؟"
    assert build_search_query(_history(long_q, recent)) == long_q
    assert build_search_query(_history("[voice message]", recent)) == ""


class FakeEmbedder:
    model, dimensions = "m", 3

    def __init__(self):
        self.texts = []

    async def embed(self, texts):
        self.texts += texts
        return SimpleNamespace(vectors=[[0.1, 0.2, 0.3]] * len(texts))


class FakeStore:
    def __init__(self, found):
        self.found, self.calls = found, []

    async def search(self, vector, *, limit, min_score=None):
        self.calls.append((vector, limit, min_score))
        return self.found[:limit]


class FakeRepo:
    def __init__(self, chunks):
        self.chunks = {c.chunk_id: c for c in chunks}

    async def get_chunks(self, ids):
        return [self.chunks[i] for i in ids if i in self.chunks]


def _retriever(found, chunks, embedder=None):
    return KnowledgeRetriever(
        embedder=embedder or FakeEmbedder(), vectorstore=FakeStore(found),
        repo=FakeRepo(chunks), top_k=3, min_score=0.0,
    )  # fmt: skip


def test_retriever_top3_in_vector_order_and_skips_missing():
    chunks = [_chunk("product:1#0", "الف"), _chunk("product:2#0", "ب")]
    found = [("product:2#0", 0.9), ("product:9#0", 0.8), ("product:1#0", 0.7), ("x:1#0", 0.6)]
    emb = FakeEmbedder()
    r = _retriever(found, chunks, emb)
    hits = asyncio.run(r.search("گلدان ٣"))
    assert [h.chunk.chunk_id for h in hits] == ["product:2#0", "product:1#0"]
    assert emb.texts == ["گلدان 3"]  # normalized like the records (digits -> ASCII)
    assert r.vectorstore.calls[0][1] == 3
    assert asyncio.run(r.search("   ")) == []


# ---------------------------------------------------------------- generator
class FakeOpenAI:
    def __init__(self, content):
        self.content, self.kwargs = content, None
        self.chat = SimpleNamespace(completions=SimpleNamespace(create=self._create))

    async def _create(self, **kwargs):
        self.kwargs = kwargs
        msg = SimpleNamespace(content=self.content)
        return SimpleNamespace(
            choices=[SimpleNamespace(message=msg, finish_reason="stop")],
            usage=SimpleNamespace(prompt_tokens=10, completion_tokens=5),
        )


def test_generator_builds_prompt_and_parses_json(tmp_path):
    s = make_settings(tmp_path)
    reply = json.dumps({"text": "داخل یا بیرون؟", "suggestions": ["داخل آپارتمان", "فضای باز"]})
    client = FakeOpenAI(reply)
    retriever = _retriever([("product:1#0", 0.8)], [_chunk("product:1#0", "گلدان سرامیکی ۲۰ سانتی")])
    gen = LLMAnswerGenerator(s, retriever, client=client)
    ctx = PromptContext(model="gpt-5-4", tone="friendly", site_title="گل‌فروشی")
    hist = _history("گلدان می‌خوام", [_hm(1, "user", "سلام"), _hm(2, "assistant", "سلام!")], "خلاصه")
    out = asyncio.run(gen.generate(hist, ctx))
    assert isinstance(out, GeneratedAnswer) and out.suggestions == ["داخل آپارتمان", "فضای باز"]
    sent = client.kwargs
    assert sent["model"] == "gpt-5-4" and sent["response_format"]["type"] == "json_schema"
    assert [m["role"] for m in sent["messages"]] == ["system", "user", "assistant", "user"]
    system = sent["messages"][0]["content"]
    assert "گلدان سرامیکی ۲۰ سانتی" in system and "خلاصه" in system and "گل‌فروشی" in system
    assert sent["messages"][-1]["content"] == "گلدان می‌خوام"


def test_generator_voice_only_skips_search_and_bad_json_raises(tmp_path):
    s = make_settings(tmp_path)
    emb = FakeEmbedder()
    gen = LLMAnswerGenerator(
        s, _retriever([], [], emb), client=FakeOpenAI('{"text": "نتوانستم باز کنم", "suggestions": []}')
    )
    out = asyncio.run(gen.generate(_history("[voice message]")))
    assert out.text and emb.texts == []
    bad = LLMAnswerGenerator(s, _retriever([], []), client=FakeOpenAI('{"text": "cut'))
    with pytest.raises(Exception, match="unusable"):
        asyncio.run(bad.generate(_history("سلام")))


# ---------------------------------------------------------------- service stores suggestions
def test_service_saves_suggestions_on_assistant_message(tmp_path):
    class Gen:
        async def generate(self, history, ctx=None):
            return GeneratedAnswer("جواب", ["الف", "ب"])

    async def main():
        s = make_settings(tmp_path)
        svc = MessagingService(settings=s, db=Database.from_settings(s), answer_generator=Gen())
        try:
            res = await svc.send(
                SendMessageRequest(
                    visitor_uid="v1", role="user", content="سلام", generate_ai_answer=True
                )
            )
            assert res.ai.status == "generated"
            assert res.ai.message.content == "جواب" and res.ai.message.suggestions == ["الف", "ب"]
        finally:
            await svc.close()

    asyncio.run(main())
