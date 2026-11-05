"""The real answer generator: RAG + system prompt + history -> LLM loop -> {text, suggestions}.

Steps of `generate` (one visitor message):
  1. query      = newest user message (+ the previous one when it is very short)
  2. retrieval  = embed the query, Qdrant top-RAG_TOP_K chunks, chunk text from the text DB
  3. tools      = the live-data tools this request may use (ToolKit: product price/stock only when
                  the site sent `live_product_datas`, plus the site's dynamic tools)
  4. prompt     = STATIC system prompt (same bytes every time) + the per-request session-context
                  block (site, tone, page, notes, tool rules, RAG chunks) - see prompt.py
  5. messages   = [static system] (+ [summary system]) + recent messages + the question
                  (+ its image, resized to <= 1 MB) + the session-context block LAST, so the
                  provider's prompt cache (identical-prefix) hits: system -> tools -> history grow
                  by appending only; everything variable is at the very end
  6. LOOP, at most ANSWER_MAX_LLM_ROUNDS (K, default 3) AI calls:
       round < K : the model may call tools; the calls run (site API), the results are appended
                   to the conversation and the next round starts
       round = K : tools are switched off (tool_choice=none) and a notice tells the model to
                   answer NOW from what it has - a final answer is always produced
       (a round without tool calls ends the loop at once: the common case is ONE call)
  7. parse      -> GeneratedAnswer: text + suggestions + references (the site's products / posts /
                   pages the text talks about); text "Mark-Chat" = hand the chat over to humans
                   (only when the request had mark-the-conversation: true).
                   Broken JSON raises -> the answer queue retries
Retrieval/LLM errors are raised on purpose: answering "I don't know" only because Qdrant or
OpenAI was briefly down would be wrong, and the queue retries (ANSWER_MAX_ATTEMPTS).
A failing TOOL never raises: the model just receives an "unavailable" text.
"""

from __future__ import annotations

import logging
import time
from urllib.parse import urlparse

from openai import AsyncOpenAI

from app.core.config import Settings
from app.core.errors import ServiceUnavailableError, UpstreamError
from app.core.monitoring import emit, note_answer_error
from app.services.conversation.history import PreparedHistory
from app.services.conversation.history import HistoryMessage
from app.services.conversation.prompt import (
    FINAL_ROUND_NOTICE,
    FINAL_ROUND_NOTICE_MARK,
    KnowledgeSnippet,
    PromptContext,
    strip_context_tags,
)
from app.services.conversation.reply_format import (
    JSON_SCHEMA_FORMAT,
    GeneratedAnswer,
    ReplyFormatError,
    parse_reply,
)
from app.services.conversation.retrieval import KnowledgeRetriever, build_search_query
from app.services.messaging.storage import resolve_attachment_path
from app.services.tools import SiteToolClient, ToolKit
from app.services.vision import prepare_image

logger = logging.getLogger(__name__)


def _cached_tokens(usage) -> int | None:
    """`cached_tokens` of a chat usage object (SDK object or plain dict from a gateway)."""
    details = getattr(usage, "prompt_tokens_details", None)
    if details is None and isinstance(usage, dict):
        details = usage.get("prompt_tokens_details")
    if isinstance(details, dict):
        value = details.get("cached_tokens")
    else:
        value = getattr(details, "cached_tokens", None)
    return value if isinstance(value, int) else None


class LLMAnswerGenerator:
    def __init__(
        self,
        settings: Settings,
        retriever: KnowledgeRetriever,
        *,
        client=None,
        tool_client: SiteToolClient | None = None,
    ):
        self.settings = settings
        self.retriever = retriever
        self._client = client  # tests inject a fake with `.chat.completions.create`
        self._key = settings.OPENAI_API_KEY.get_secret_value()
        self._tools = tool_client or SiteToolClient(settings)

    def _get_client(self):
        if self._client is None:
            if not self._key:
                raise ServiceUnavailableError("OPENAI_API_KEY is not configured on the server")
            self._client = AsyncOpenAI(
                api_key=self._key,
                base_url=self.settings.OPENAI_BASE_URL or None,
                timeout=self.settings.ANSWER_LLM_TIMEOUT,
                max_retries=self.settings.ANSWER_LLM_MAX_RETRIES,
            )
        return self._client

    def _toolkit(self, ctx: PromptContext) -> ToolKit:
        return ToolKit.build(
            self.settings,
            self._tools,
            live_product_datas=ctx.live_product_datas,
            tools=ctx.tools,
        )

    async def _question_content(self, q: HistoryMessage, text: str, context: str):
        """Content of the newest user message: the text, then (for an image) the resized image,
        and the session-context block LAST (everything variable goes at the end)."""
        if q.attachment_type != "image":
            return f"{text}\n\n{context}"
        path = resolve_attachment_path(self.settings, q.attachment_path)
        image = (
            await prepare_image(path, self.settings.IMAGE_MAX_KB)
            if path is not None and path.is_file()
            else None
        )
        if image is None:
            logger.warning("answer.image_unavailable message_id=%s", q.id)
            return f"{text.replace('[image]', '[image - could not be opened]', 1)}\n\n{context}"
        return [
            {"type": "text", "text": text},
            {
                "type": "image_url",
                "image_url": {"url": image.data_url, "detail": self.settings.IMAGE_DETAIL},
            },
            {"type": "text", "text": context},
        ]

    def _cache_key(self, conversation_id: int) -> str | None:
        """`prompt_cache_key` (OpenAI): a constant per site (or per conversation) so requests that
        share the static prefix are routed to the same cache. None = do not send it."""
        scope = self.settings.ANSWER_PROMPT_CACHE_KEY
        if scope == "off":
            return None
        host = urlparse(self.settings.PLUGIN_BASE_URL or "").netloc or "default"
        return f"paia:{host}" if scope == "site" else f"paia:{host}:c{conversation_id}"

    async def _complete(self, kwargs: dict, meta: dict):
        """One chat-completion call; every call (ok or not) is one `llm.chat` monitoring event."""
        client = self._get_client()
        t0 = time.perf_counter()
        try:
            response = await client.chat.completions.create(**kwargs)
        except Exception as exc:  # noqa: BLE001 - SDK errors differ per version
            emit(
                "llm.chat", **meta, status="error", error_type=type(exc).__name__,
                http_status=getattr(exc, "status_code", None),
                ms=(time.perf_counter() - t0) * 1000, error=str(exc)[:200],
            )  # fmt: skip
            note_answer_error(exc)
            raise UpstreamError(f"Answer request failed ({type(exc).__name__}: {exc})") from exc
        choice = response.choices[0] if response.choices else None
        usage = getattr(response, "usage", None)
        cd = getattr(usage, "completion_tokens_details", None)
        emit(
            "llm.chat", **meta, status="ok", ms=(time.perf_counter() - t0) * 1000,
            prompt_tokens=getattr(usage, "prompt_tokens", None),
            completion_tokens=getattr(usage, "completion_tokens", None),
            total_tokens=getattr(usage, "total_tokens", None),
            cached_tokens=_cached_tokens(usage),
            reasoning_tokens=getattr(cd, "reasoning_tokens", None),
            finish=getattr(choice, "finish_reason", None),
            tool_calls=len(getattr(getattr(choice, "message", None), "tool_calls", None) or []),
        )  # fmt: skip
        return response

    async def generate(
        self, history: PreparedHistory, ctx: PromptContext | None = None
    ) -> GeneratedAnswer:
        s = self.settings
        ctx = ctx or PromptContext(model=s.default_model, tone=s.DEFAULT_TONE)
        if history.question is None:
            raise UpstreamError("No question to answer")

        query = build_search_query(history)
        hits = await self.retriever.search(query) if query else []
        snippets = [
            KnowledgeSnippet(
                text=h.chunk.text,
                title=h.chunk.title or "",
                type=h.chunk.record_type or "",
                url=h.chunk.url or "",
                record_id=h.chunk.record_id or "",
            )
            for h in hits
        ]

        rounds = s.ANSWER_MAX_LLM_ROUNDS
        kit = self._toolkit(ctx) if rounds >= 2 else None
        use_tools = kit is not None and kit.enabled
        context = ctx.context_block(
            tone_text=s.tone_text(ctx.tone),
            knowledge=snippets if query else None,  # None: nothing was searched (voice/image only)
            product_fields=kit.product_fields if use_tools else (),
            site_tools=bool(kit.dynamic) if use_tools else False,
        )
        # Fixed prefix first (static system -> [summary] -> history, append-only); the variable
        # session context is added to the END of the newest user message.
        messages: list[dict] = [{"role": "system", "content": ctx.system_prompt()}]
        if (summary_text := ctx.summary_message(history.summary)) is not None:
            messages.append({"role": "system", "content": summary_text})
        messages += [
            {**m, "content": strip_context_tags(m["content"])} for m in history.llm_messages()
        ]
        messages[-1]["content"] = await self._question_content(
            history.question, messages[-1]["content"], context
        )

        base: dict = {"model": ctx.model, "max_completion_tokens": s.ANSWER_MAX_OUTPUT_TOKENS}
        if s.ANSWER_RESPONSE_FORMAT == "json_schema":
            base["response_format"] = JSON_SCHEMA_FORMAT
        elif s.ANSWER_RESPONSE_FORMAT == "json_object":
            base["response_format"] = {"type": "json_object"}
        if s.ANSWER_REASONING_EFFORT.strip():
            base["reasoning_effort"] = s.ANSWER_REASONING_EFFORT.strip()
        if use_tools:
            base["tools"] = kit.definitions  # canonical order + sorted keys (catalog.py)
        if (cache_key := self._cache_key(history.conversation_id)) is not None:
            base["extra_body"] = {"prompt_cache_key": cache_key}  # works on any SDK version

        used: list[str] = []
        for round_no in range(1, rounds + 1):
            final = round_no == rounds or not use_tools
            kwargs = {**base, "messages": messages}
            if use_tools:
                kwargs["tool_choice"] = "none" if final else "auto"
            if final and round_no > 1:  # tools were used: force the answer now
                notice = FINAL_ROUND_NOTICE + (
                    FINAL_ROUND_NOTICE_MARK if ctx.mark_the_conversation else ""
                )
                kwargs["messages"] = [*messages, {"role": "system", "content": notice}]

            response = await self._complete(
                kwargs,
                {
                    "purpose": "answer", "model": ctx.model, "round": round_no, "rounds": rounds,
                    "has_image": history.question.attachment_type == "image",
                },
            )  # fmt: skip
            choice = response.choices[0] if response.choices else None
            message = choice.message if choice is not None else None
            usage = getattr(response, "usage", None)
            calls = list(getattr(message, "tool_calls", None) or [])
            logger.info(
                "answer.llm model=%s round=%d/%d prompt_tokens=%s cached_tokens=%s "
                "completion_tokens=%s finish=%s chunks=%d tool_calls=%d",
                ctx.model, round_no, rounds, getattr(usage, "prompt_tokens", None),
                _cached_tokens(usage), getattr(usage, "completion_tokens", None),
                getattr(choice, "finish_reason", None), len(hits), len(calls),
            )  # fmt: skip

            if calls and not final and kit is not None:
                messages.append(
                    {
                        "role": "assistant",
                        "content": message.content or None,
                        "tool_calls": [
                            {
                                "id": c.id,
                                "type": "function",
                                "function": {
                                    "name": c.function.name,
                                    "arguments": c.function.arguments or "{}",
                                },
                            }
                            for c in calls
                        ],
                    }
                )
                results = await kit.run_all(
                    [(c.id, c.function.name, c.function.arguments or "{}") for c in calls]
                )
                for c, result in zip(calls, results):
                    messages.append({"role": "tool", "tool_call_id": c.id, "content": result})
                    used.append(c.function.name)
                continue

            raw = message.content if message is not None else None
            try:
                answer = parse_reply(raw)
            except ReplyFormatError as exc:
                raise UpstreamError(f"Model returned an unusable answer ({exc})") from exc
            if used:
                logger.info("answer.tools_used names=%s rounds=%d", used, round_no)
            return answer
        raise UpstreamError("Model produced no answer")  # unreachable: the last round is final

    async def close(self) -> None:
        if self._client is not None and hasattr(self._client, "close"):
            await self._client.close()
        self._client = None
