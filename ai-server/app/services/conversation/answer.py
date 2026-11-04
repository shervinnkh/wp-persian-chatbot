"""Answer generation interface.

`AnswerGenerator.generate` returns a `GeneratedAnswer` (text + quick-reply suggestions; a plain
`str` is still accepted). `LLMAnswerGenerator` (llm_answer.py) is the real one; the fixed one is
only used when no OPENAI_API_KEY is configured (and in tests).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Literal, Protocol

from app.db.messaging_models import Message
from app.services.conversation.history import PreparedHistory
from app.services.conversation.prompt import PromptContext
from app.services.conversation.reply_format import GeneratedAnswer

FIXED_ANSWER = (
    "سلام! پیام شما دریافت شد. این یک پاسخ آزمایشی است و به‌زودی دستیار هوشمند پاسخ می‌دهد."
)


class AnswerGenerator(Protocol):
    async def generate(
        self, history: PreparedHistory, ctx: PromptContext | None = None
    ) -> GeneratedAnswer | str: ...


class FixedAnswerGenerator:
    """Returns the same text every time - no AI is called."""

    async def generate(
        self, history: PreparedHistory, ctx: PromptContext | None = None  # noqa: ARG002
    ) -> GeneratedAnswer:
        return GeneratedAnswer(text=FIXED_ANSWER)


__all__ = ["AiAnswerResult", "AnswerGenerator", "FixedAnswerGenerator", "GeneratedAnswer"]


@dataclass
class AiAnswerResult:
    # skipped = generate_ai_answer was not true (or not a user message) | generated | failed
    status: Literal["skipped", "generated", "failed", "queued"] = "skipped"
    job_id: str | None = None
    message: Message | None = None  # the saved assistant message
    full_history_tokens: int | None = None
    context_tokens: int | None = None
    summarization_scheduled: bool = False
    model: str | None = None
    # filled when a summarization must be queued AFTER the response reached the client
    summarize_upto_message_id: int | None = None
    # the site's products / blog posts / pages the answer talks about: [{type, id, url}]
    references: list[dict] = field(default_factory=list)
    # true = the answer is exactly "Mark-Chat": `message` is NOT saved (id 0, not in the history)
    marked: bool = False
