"""Request/response models for messaging."""

from __future__ import annotations

import re
from datetime import datetime, timezone
from typing import Literal

from pydantic import (
    AliasChoices,
    BaseModel,
    ConfigDict,
    Field,
    PrivateAttr,
    ValidationError,
    field_validator,
    model_validator,
)

from app.db.messaging_models import AttachmentType, MessageRole
from app.schemas.ingest import CallbackIn

_UID_RE = re.compile(r"^[A-Za-z0-9_.:\-]{1,64}$")
MAX_SUGGESTIONS = 4
MAX_NOTES_CHARS = 1500
MAX_BATCH_MESSAGES = 100  # one batch request; the plugin sends bigger backlogs in several requests
MAX_REQUEST_TOOLS = 100  # hard schema limit; TOOLS_MAX_DYNAMIC (.env) is the working limit
LIVE_PRODUCT_FIELDS = ("price", "stock")  # live product data the site may allow
REFERENCE_TYPES = ("product", "blog", "page")  # site content an answer can point to
MAX_REFERENCES = 8
MARK_CHAT_REPLY = "Mark-Chat"  # the whole answer text when the AI hands the chat over to humans
_TOOL_ID_RE = re.compile(r"^[A-Za-z0-9_.:\-]{1,40}$")


class ChatSite(BaseModel):
    """Short facts about the site (plugin settings); only used when an AI answer is generated."""

    title: str | None = Field(None, max_length=200)
    description: str | None = Field(None, max_length=400)


class ChatPage(BaseModel):
    """The page the visitor is chatting on (tiny, static info that exists on every page)."""

    url: str | None = Field(None, max_length=2048)
    slug: str | None = Field(None, max_length=200)  # "" / "home" for the front page
    title: str | None = Field(None, max_length=300)
    type: str | None = Field(None, max_length=40)  # home | product | post | page | archive | other
    # Optional: the WordPress post id of the page (e.g. the product id on a product page). Lets the
    # AI ask the live-product tool by id when the visitor says "this product".
    id: int | str | None = None

    @field_validator("id")
    @classmethod
    def _page_id(cls, v: int | str | None) -> int | str | None:
        if isinstance(v, str):
            v = v.strip()[:40]
            return int(v) if v.isdigit() else (v or None)
        return v


class ChatDynamicTool(BaseModel):
    """A live-information tool defined by the site manager (text fetched live by `id`)."""

    model_config = ConfigDict(populate_by_name=True)

    id: int | str
    title: str = Field(..., max_length=200)
    # "when to call it". The plugin currently sends the key as "disciprtion": all spellings work.
    description: str = Field(
        "",
        max_length=1000,
        validation_alias=AliasChoices("description", "disciprtion", "discription", "desc"),
    )

    @field_validator("id")
    @classmethod
    def _id(cls, v: int | str) -> int | str:
        if isinstance(v, bool):
            raise ValueError("tool id must be an int or a short string")
        if isinstance(v, int):
            if v < 0:
                raise ValueError("tool id must not be negative")
            return v
        v = v.strip()
        if not _TOOL_ID_RE.match(v):
            raise ValueError("tool id has invalid characters")
        return int(v) if v.isdigit() else v

    @field_validator("title", "description", mode="before")
    @classmethod
    def _strip(cls, v):
        return v.strip() if isinstance(v, str) else v


def _loose_bool(v):
    """true/false, 1/0 or "true"/"false" -> bool; missing/null/unknown -> False (never an error)."""
    if isinstance(v, str):
        return v.strip().lower() in ("1", "true", "yes", "on")
    return bool(v) if v is not None else False


class SendMessageRequest(BaseModel):
    # `mark-the-conversation` has dashes, so it is read through an alias (the field name works too).
    model_config = ConfigDict(populate_by_name=True)

    # Conversation lookup: either conversation_id, or visitor_uid (+ wp_user_id when logged in)
    conversation_id: int | None = Field(None, ge=1)
    visitor_uid: str | None = Field(None, max_length=64)
    wp_user_id: int | None = Field(None, ge=0)  # 0 (WordPress guest) -> None

    role: MessageRole
    content: str = Field("", max_length=20000)
    attachment_type: AttachmentType | None = None
    attachment_url: str | None = Field(None, max_length=2048)
    suggestions: list[str] | None = None

    admin_user_id: int | None = Field(None, ge=1)
    admin_name: str | None = Field(None, max_length=120)

    # Only meaningful for role=user: true -> an answer is generated and saved as `assistant`.
    # Missing / false -> the message is only saved. Ignored for admin/assistant messages.
    generate_ai_answer: bool = False

    # The fields below are used ONLY when generate_ai_answer is true (they go into the prompt).
    model: str | None = Field(None, max_length=64)  # key from AI_ALLOWED_MODELS; else the default
    tone: Literal["formal", "friendly"] | None = None
    site: ChatSite | None = None
    page: ChatPage | None = None
    # Important instructions of the site owner (plugin setting `ai.notes`); goes into the prompt.
    # Optional; longer text is cut (never an error).
    notes: str | None = None
    # Live product data the site can give (tool "product live info"): any of "price", "stock".
    # Missing/empty = the site has NO live product data: the product tool is never offered.
    # (A {"price": true, "stock": false} object is accepted too.)
    live_product_datas: list[str] | None = None
    # Dynamic tools of the site manager: [{id, title, description}, ...]. Each one the AI may
    # call; its text is fetched live from the site by id and put into the prompt.
    tools: list[ChatDynamicTool] | None = Field(None, max_length=MAX_REQUEST_TOOLS)
    # With generate_ai_answer=true AND a callback: the request returns at once
    # (ai_answer.status="queued") and the answer is POSTed to the callback URL when it is ready
    # (kind "chat_answer"). Without a callback the answer is generated inside the request.
    callback: CallbackIn | None = None
    # `mark-the-conversation`: the site allows the AI to MARK the chat instead of answering.
    # true  -> when the AI cannot answer the visitor it returns exactly "Mark-Chat" (the site then
    #          marks the conversation for the support team; nothing is shown to the visitor)
    # false / missing (default) -> the AI behaves as before. Only used with generate_ai_answer.
    mark_the_conversation: bool = Field(False, alias="mark-the-conversation")

    @field_validator("mark_the_conversation", mode="before")
    @classmethod
    def _mark_bool(cls, v):
        return _loose_bool(v)

    @field_validator("notes")
    @classmethod
    def _notes(cls, v: str | None) -> str | None:
        v = (v or "").strip()
        return v[:MAX_NOTES_CHARS] or None

    @field_validator("live_product_datas", mode="before")
    @classmethod
    def _live_fields(cls, v):
        if v is None:
            return None
        if isinstance(v, dict):
            v = [k for k, on in v.items() if on]
        elif isinstance(v, str):
            v = [v]
        if not isinstance(v, (list, tuple)):
            return None
        out: list[str] = []
        for item in v:
            key = str(item).strip().lower()
            if key in LIVE_PRODUCT_FIELDS and key not in out:
                out.append(key)  # unknown names are ignored, never an error
        return out or None

    @field_validator("tools", mode="after")
    @classmethod
    def _unique_tools(cls, v: list[ChatDynamicTool] | None) -> list[ChatDynamicTool] | None:
        if not v:
            return None
        seen: set[str] = set()
        out: list[ChatDynamicTool] = []
        for t in v:
            if str(t.id) in seen:
                continue
            seen.add(str(t.id))
            out.append(t)
        return out

    @field_validator("wp_user_id")
    @classmethod
    def _guest_zero(cls, v: int | None) -> int | None:
        return v or None

    @field_validator("visitor_uid")
    @classmethod
    def _uid(cls, v: str | None) -> str | None:
        if v is None:
            return v
        if not _UID_RE.match(v):
            raise ValueError("visitor_uid has invalid characters")
        return v

    @field_validator("attachment_url")
    @classmethod
    def _url(cls, v: str | None) -> str | None:
        if v is not None and not re.match(r"^https?://", v, re.I):
            raise ValueError("attachment_url must be http(s)")
        return v

    @field_validator("suggestions")
    @classmethod
    def _suggestions(cls, v: list[str] | None) -> list[str] | None:
        if v is None:
            return v
        if len(v) > MAX_SUGGESTIONS:
            raise ValueError(f"at most {MAX_SUGGESTIONS} suggestions are allowed")
        cleaned = [s.strip() for s in v if isinstance(s, str) and s.strip()]
        if any(len(s) > 200 for s in cleaned):
            raise ValueError("each suggestion must be at most 200 characters")
        return cleaned or None

    @model_validator(mode="after")
    def _check(self) -> SendMessageRequest:
        if self.conversation_id is None and not self.visitor_uid:
            raise ValueError("conversation_id or visitor_uid is required")
        if (self.attachment_type is None) != (self.attachment_url is None):
            raise ValueError("attachment_type and attachment_url must be sent together")
        self.content = self.content.strip()
        if not self.content and self.attachment_url is None:
            raise ValueError("content or attachment is required")
        if self.role == MessageRole.admin:
            if self.admin_user_id is None:
                raise ValueError("admin_user_id is required for role=admin")
        else:
            self.admin_user_id = None
            self.admin_name = None
        if self.role != MessageRole.user:
            self.generate_ai_answer = False
        if not self.generate_ai_answer:
            self.callback = None
            self.live_product_datas = None
            self.tools = None
            self.mark_the_conversation = False
        return self


class BatchMessageIn(BaseModel):
    """One message of a batch (same fields/rules as the single-message body, role user|admin)."""

    role: MessageRole
    content: str = Field("", max_length=20000)
    attachment_type: AttachmentType | None = None
    attachment_url: str | None = Field(None, max_length=2048)
    suggestions: list[str] | None = None
    admin_user_id: int | None = Field(None, ge=1)
    admin_name: str | None = Field(None, max_length=120)
    # The client's own id for this message (e.g. its row id). Not stored: it is only echoed back
    # in the response and in the "chat_messages_saved" callback so the client can match them.
    external_id: str | None = Field(None, max_length=64)


class SendMessagesBatchRequest(BaseModel):
    """Several messages saved IN THE GIVEN ORDER (oldest first), optionally ending in an AI answer.

    Used by the plugin: messages exchanged while the AI was off (or while a human answered) are not
    sent one by one; they go together with the next user message that needs an AI answer.
    Server time vs. site time does not matter: only the order of the list is kept (messages get
    increasing ids as they are saved one after another).
    """

    conversation_id: int | None = Field(None, ge=1)
    visitor_uid: str | None = Field(None, max_length=64)
    wp_user_id: int | None = Field(None, ge=0)

    model_config = ConfigDict(populate_by_name=True)

    messages: list[BatchMessageIn] = Field(..., min_length=1, max_length=MAX_BATCH_MESSAGES)
    request_id: str | None = Field(None, max_length=64)  # echoed in the "messages saved" callback

    # true -> the LAST message (must be role=user) also gets an AI answer; every field below is
    # then used exactly like in POST /v1/chat/messages. false (default) -> everything is only saved.
    generate_ai_answer: bool = False
    model: str | None = Field(None, max_length=64)
    tone: Literal["formal", "friendly"] | None = None
    site: ChatSite | None = None
    page: ChatPage | None = None
    notes: str | None = None
    live_product_datas: list[str] | None = None
    tools: list[ChatDynamicTool] | None = Field(None, max_length=MAX_REQUEST_TOOLS)
    # Same meaning as in POST /v1/chat/messages (alias "mark-the-conversation").
    mark_the_conversation: bool = Field(False, alias="mark-the-conversation")
    # With a callback the site is first told which messages were saved (item kind
    # "chat_messages_saved", before any answer is generated), then - if generate_ai_answer -
    # gets the answer (kind "chat_answer"). Without it the answer (if any) is generated inline.
    callback: CallbackIn | None = None

    _requests: list[SendMessageRequest] = PrivateAttr(default_factory=list)

    @field_validator("mark_the_conversation", mode="before")
    @classmethod
    def _mark_bool(cls, v):
        return _loose_bool(v)

    @model_validator(mode="after")
    def _build(self) -> SendMessagesBatchRequest:
        if self.generate_ai_answer and self.messages[-1].role != MessageRole.user:
            raise ValueError("the last message must have role=user when generate_ai_answer is true")
        last = len(self.messages) - 1
        requests: list[SendMessageRequest] = []
        for i, m in enumerate(self.messages):
            data: dict = {
                "conversation_id": self.conversation_id,
                "visitor_uid": self.visitor_uid,
                "wp_user_id": self.wp_user_id,
                **m.model_dump(exclude={"external_id"}),
            }
            if self.generate_ai_answer and i == last:
                data.update(
                    generate_ai_answer=True,
                    model=self.model,
                    tone=self.tone,
                    site=self.site,
                    page=self.page,
                    notes=self.notes,
                    live_product_datas=self.live_product_datas,
                    tools=self.tools,
                    mark_the_conversation=self.mark_the_conversation,
                    callback=self.callback,
                )
            try:
                requests.append(SendMessageRequest(**data))
            except ValidationError as exc:
                detail = "; ".join(str(e.get("msg", "")) for e in exc.errors())
                raise ValueError(f"messages[{i}]: {detail}") from None
        self._requests = requests
        return self

    @property
    def requests(self) -> list[SendMessageRequest]:
        """The validated single-message requests, in order (the last one may ask for an answer)."""
        return self._requests


class ReferenceOut(BaseModel):
    """A product / blog post / page of the site that the answer talks about or recommends.

    The AI lists them OUTSIDE the text; the site looks each one up (by id, else by url) and shows
    it as a card under the message. `id` is the site's own id (for WordPress the post id)."""

    type: Literal["product", "blog", "page"]
    id: str | None = None
    url: str | None = None


class MessageOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    conversation_id: int
    visitor_uid: str
    wp_user_id: int | None
    role: MessageRole
    content: str
    attachment_type: AttachmentType | None
    attachment_url: str | None
    attachment_path: str | None
    suggestions: list[str] | None
    created_at: datetime
    admin_user_id: int | None
    admin_name: str | None
    transcript: str | None = None  # speech-to-text of a voice message (after the AI answered)
    # AI answers only: site content the answer refers to (not stored; also sent in the webhook)
    references: list[ReferenceOut] = Field(default_factory=list)

    @field_validator("created_at")
    @classmethod
    def _utc(cls, v: datetime) -> datetime:  # SQLite returns naive datetimes
        return v.replace(tzinfo=timezone.utc) if v.tzinfo is None else v


class AiAnswerOut(BaseModel):
    # skipped = no answer requested | generated = `message` is the saved reply | failed = the
    # user message is saved but the answer could not be produced (reason in the server log)
    # queued = (request had a callback) the answer is generated in the background and POSTed to
    # the callback when ready; `job_id` names it
    status: Literal["skipped", "generated", "failed", "queued"]
    job_id: str | None = None
    message: MessageOut | None = None
    full_history_tokens: int | None = None  # summary + all unsummarized messages
    context_tokens: int | None = None  # what the AI would receive (summary + last pairs + question)
    summarization_scheduled: bool = False  # queued right after this response was sent
    model: str | None = None  # the model that answered (after the allow-list was applied)
    # true = the AI answered exactly "Mark-Chat": `message.content` is that text, it is NOT saved in
    # the conversation history (message.id = 0), and the site should only mark the chat.
    marked: bool = False


class SendMessageResponse(BaseModel):
    conversation_id: int
    created_conversation: bool
    # none = no attachment | stored = downloaded | failed = message saved, download failed
    attachment_status: Literal["none", "stored", "failed"]
    message: MessageOut
    ai_answer: AiAnswerOut


class BatchItemOut(BaseModel):
    index: int  # position in the request's `messages`
    external_id: str | None = None  # echo of the request item's external_id
    attachment_status: Literal["none", "stored", "failed"]
    message: MessageOut


class SendMessagesBatchResponse(BaseModel):
    conversation_id: int
    created_conversation: bool
    total_count: int  # messages in the request
    # Messages saved from the START of the list (the list is processed in order and stops at the
    # first failure). received_count == total_count means everything was received.
    received_count: int
    error: str | None = None  # set when received_count < total_count (details in the server log)
    # true = a "chat_messages_saved" callback item was queued (only when the request had a callback)
    saved_callback_queued: bool = False
    messages: list[BatchItemOut]
    ai_answer: AiAnswerOut


class DeleteMessageResponse(BaseModel):
    conversation_id: int
    message_id: int
    deleted: bool = True


class ClearMessagesResponse(BaseModel):
    conversation_id: int
    deleted_count: int
