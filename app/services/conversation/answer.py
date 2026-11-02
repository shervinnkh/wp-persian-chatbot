"""Answer generation (placeholder).

Only the interface and a FIXED answer exist for now. The real generator (system prompt + RAG +
LLM) replaces `FixedAnswerGenerator` without touching callers: it must implement `generate`
(the whole answer).
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Literal, Protocol

from app.db.messaging_models import Message
from app.services.conversation.history import PreparedHistory
from app.services.conversation.prompt import PromptContext

FIXED_ANSWER = (
    "سلام! پیام شما دریافت شد. این یک پاسخ آزمایشی است و به‌زودی دستیار هوشمند پاسخ می‌دهد."
)


class AnswerGenerator(Protocol):
    async def generate(
        self, history: PreparedHistory, ctx: PromptContext | None = None
    ) -> str: ...


class FixedAnswerGenerator:
    """Returns the same text every time - no AI is called."""

    async def generate(
        self, history: PreparedHistory, ctx: PromptContext | None = None  # noqa: ARG002
    ) -> str:
        return FIXED_ANSWER


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
