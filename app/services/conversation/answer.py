"""Answer generation (placeholder).

Only the interface and a FIXED answer exist for now. Version 0.5.0 replaces
`FixedAnswerGenerator` with the real one (system prompt + RAG + LLM) without touching callers.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Literal, Protocol

from app.db.messaging_models import Message
from app.services.conversation.history import PreparedHistory

FIXED_ANSWER = (
    "سلام! پیام شما دریافت شد. این یک پاسخ آزمایشی است و به‌زودی دستیار هوشمند پاسخ می‌دهد."
)


class AnswerGenerator(Protocol):
    async def generate(self, history: PreparedHistory) -> str: ...


class FixedAnswerGenerator:
    """Returns the same text every time - no AI is called."""

    async def generate(self, history: PreparedHistory) -> str:  # noqa: ARG002
        return FIXED_ANSWER


@dataclass
class AiAnswerResult:
    # skipped = generate_ai_answer was not true (or not a user message) | generated | failed
    status: Literal["skipped", "generated", "failed"] = "skipped"
    message: Message | None = None  # the saved assistant message
    full_history_tokens: int | None = None
    context_tokens: int | None = None
    summarization_scheduled: bool = False
    # filled when a summarization must be queued AFTER the response reached the client
    summarize_upto_message_id: int | None = None
