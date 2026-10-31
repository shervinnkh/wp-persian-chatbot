"""Builds the conversation history that will be sent to the AI.

Shape (decided by the project owner):

    last summary  +  all messages after it, in full (at least the last N pairs)  +  newest message

  * no summary yet      -> the WHOLE conversation, in full (+ the newest user message)
  * with a summary      -> summary + every message after the summary cutoff; if fewer than N
                           pairs lie after the cutoff, the last N pairs are still included
                           (they overlap the summary a little, on purpose)

Nothing is sent to an AI here: the result (`PreparedHistory`) is only prepared so the next step
(0.5.0: system prompt + LLM call) can use it directly.

Definitions
- *pair*: one user message plus everything that answered it (assistant and/or human `admin`
  messages) until the next user message. The last N pairs are found by counting USER messages
  backwards, so extra admin replies never push a user message out of the window.
- *full history tokens*: tokens of the stored summary + every message that is not covered by the
  summary yet (including the newest user message). This number decides if a summarization is
  requested (`needs_summary`).
- For the AI only two roles exist: `admin` becomes `assistant` (the label is kept in
  `sender` and in `llm_text`, so the model knows a human wrote it).
"""

from __future__ import annotations

from dataclasses import dataclass, field

from app.db.messaging_models import Conversation, Message, MessageRole
from app.db.messaging_repository import MessagingRepository
from app.services.chunking.tokens import TokenCounter

_PAGE = 40  # rows per backward page while looking for the last N user messages


def attachment_placeholder(msg: Message) -> str:
    if msg.attachment_type == "voice":
        return "[voice message]"
    if msg.attachment_type == "image":
        return "[image]"
    return ""


def message_text(msg: Message) -> str:
    """Text of a message as the AI should see it (attachments without a caption get a tag)."""
    content = (msg.content or "").strip()
    if msg.attachment_type and msg.content:
        return f"{attachment_placeholder(msg)} {content}"
    return content or attachment_placeholder(msg)


def speaker_label(msg: Message) -> str:
    if msg.role == MessageRole.admin.value:
        return (
            f"human support agent ({msg.admin_name})" if msg.admin_name else "human support agent"
        )
    return "user" if msg.role == MessageRole.user.value else "AI assistant"


@dataclass(frozen=True)
class HistoryMessage:
    id: int
    role: str  # "user" | "assistant"  (the only roles an LLM knows)
    sender: str  # "user" | "admin" | "assistant"  (who really wrote it)
    text: str
    speaker: str  # readable label, used in summarization transcripts
    attachment_type: str | None = None
    attachment_path: str | None = None  # relative to UPLOADS_DIR (for the voice/vision steps)

    @property
    def llm_text(self) -> str:
        """Content for the model; a human agent's message is marked so the AI can tell."""
        if self.sender == MessageRole.admin.value:
            return f"[Message from a human support agent] {self.text}"
        return self.text

    @classmethod
    def from_row(cls, msg: Message) -> HistoryMessage:
        sender = msg.role
        return cls(
            id=msg.id,
            role="user" if sender == MessageRole.user.value else "assistant",
            sender=sender,
            text=message_text(msg),
            speaker=speaker_label(msg),
            attachment_type=msg.attachment_type,
            attachment_path=msg.attachment_path,
        )


@dataclass
class PreparedHistory:
    conversation_id: int
    summary: str | None  # goes to the AI together with the system prompt
    summarized_upto_message_id: int | None
    recent: list[HistoryMessage] = field(default_factory=list)  # oldest first, full text
    question: HistoryMessage | None = None  # the newest user message
    full_history_tokens: int = 0  # summary + all unsummarized messages (decides summarizing)
    context_tokens: int = 0  # summary + recent + question (what would really be sent)
    needs_summary: bool = False

    def llm_messages(self) -> list[dict[str, str]]:
        """recent + question as `{role, content}` dicts (the summary and the system prompt are
        added by the caller, which decides where the summary goes)."""
        items = [*self.recent, *([self.question] if self.question else [])]
        return [{"role": m.role, "content": m.llm_text} for m in items]


class HistoryBuilder:
    def __init__(
        self,
        repo: MessagingRepository,
        counter: TokenCounter,
        *,
        recent_pairs: int,
        trigger_tokens: int,
    ):
        self.repo = repo
        self.counter = counter
        self.recent_pairs = recent_pairs
        self.trigger_tokens = trigger_tokens

    async def build(self, conv: Conversation, question: Message) -> PreparedHistory:
        cutoff = conv.summarized_upto_message_id
        summary = (conv.summary or "").strip() or None
        if summary is None:
            cutoff = None  # a cutoff without a summary text means nothing is summarized

        # 1) everything not covered by the summary, up to the newest user message -> tokens
        tail = await self.repo.list_messages_after(conv.id, after_id=cutoff, upto_id=question.id)
        full_tokens = self.counter.count(summary or "") + sum(
            self.counter.count(HistoryMessage.from_row(m).llm_text) for m in tail
        )

        # 2) raw messages = EVERYTHING after the summary cutoff (no summary -> the whole
        #    conversation), extended backwards so that at least the last N pairs are present
        #    even when they lie before the cutoff.
        window = await self._last_pairs(conv.id, before_id=question.id)
        tail_before_question = [m for m in tail if m.id != question.id]
        older = [m for m in window if cutoff is not None and m.id <= cutoff]
        recent_rows = older + tail_before_question
        recent = [HistoryMessage.from_row(m) for m in recent_rows]
        q = HistoryMessage.from_row(question)

        context_tokens = (
            self.counter.count(summary or "")
            + sum(self.counter.count(m.llm_text) for m in recent)
            + self.counter.count(q.llm_text)
        )
        return PreparedHistory(
            conversation_id=conv.id,
            summary=summary,
            summarized_upto_message_id=cutoff,
            recent=recent,
            question=q,
            full_history_tokens=full_tokens,
            context_tokens=context_tokens,
            needs_summary=full_tokens > self.trigger_tokens,
        )

    async def _last_pairs(self, conversation_id: int, *, before_id: int) -> list[Message]:
        """Walk backwards from the question until `recent_pairs` user messages are collected."""
        collected: list[Message] = []  # newest first
        users = 0
        cursor = before_id
        while users < self.recent_pairs:
            page = await self.repo.list_messages(conversation_id, limit=_PAGE, before_id=cursor)
            if not page:
                break
            for row in page:
                collected.append(row)
                if row.role == MessageRole.user.value:
                    users += 1
                    if users >= self.recent_pairs:
                        break
            cursor = page[-1].id
            if len(page) < _PAGE:
                break
        collected.reverse()
        return collected
