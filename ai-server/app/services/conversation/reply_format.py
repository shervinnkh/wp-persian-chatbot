"""The JSON the model must return, and a tolerant parser for it.

    {"text": "<message for the visitor>", "suggestions": ["<short visitor reply>", ...],
     "references": [{"type": "product|blog|page", "id": "<id>", "url": "<url>"}, ...]}

`references` = products / blog posts / pages OF THE SITE that the message talks about. They are
kept OUT of the text; the site turns each one into a card under the message.
When the site allowed it (`mark-the-conversation`) and the AI cannot answer, `text` is exactly
"Mark-Chat" (suggestions and references empty): the site then only marks the conversation.

No third-party imports on purpose (easy to test).
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field

MAX_SUGGESTIONS = 4
MAX_SUGGESTION_CHARS = 60  # a longer "suggestion" is an explanation, not a quick reply
MAX_REFERENCES = 8
REFERENCE_TYPES = ("product", "blog", "page")
MARK_CHAT = "Mark-Chat"  # the whole answer when the AI hands the chat over to the support team
_REF_ALIASES = {
    "products": "product",
    "post": "blog",
    "posts": "blog",
    "article": "blog",
    "blogpost": "blog",
    "blog_post": "blog",
    "blog-post": "blog",
    "pages": "page",
}

RESPONSE_SCHEMA = {
    "type": "object",
    "properties": {
        "text": {"type": "string"},
        "suggestions": {"type": "array", "items": {"type": "string"}},
        "references": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "type": {"type": "string", "enum": list(REFERENCE_TYPES)},
                    "id": {"type": "string"},
                    "url": {"type": "string"},
                },
                "required": ["type", "id", "url"],
                "additionalProperties": False,
            },
        },
    },
    "required": ["text", "suggestions", "references"],
    "additionalProperties": False,
}
JSON_SCHEMA_FORMAT = {
    "type": "json_schema",
    "json_schema": {"name": "chat_reply", "strict": True, "schema": RESPONSE_SCHEMA},
}


class ReplyFormatError(ValueError):
    """The model output could not be turned into an answer (the caller retries)."""


@dataclass
class GeneratedAnswer:
    text: str
    suggestions: list[str] = field(default_factory=list)
    references: list[dict] = field(default_factory=list)  # [{type, id, url}] of the site's content

    @property
    def is_mark(self) -> bool:
        """The AI could not answer and hands the chat over (the text is exactly `Mark-Chat`)."""
        return is_mark_text(self.text)


def is_mark_text(text: object) -> bool:
    """True when the WHOLE answer is `Mark-Chat` (case and surrounding spaces/quotes ignored)."""
    if not isinstance(text, str):
        return False
    return text.strip().strip("`'\"“”«».").strip().lower() == MARK_CHAT.lower()


def mark_answer() -> GeneratedAnswer:
    """The hand-over answer: exactly `Mark-Chat`, no suggestions, no references."""
    return GeneratedAnswer(text=MARK_CHAT)


_FENCE = re.compile(r"^```(?:json)?\s*|\s*```$", re.I)


def clean_suggestions(raw: object) -> list[str]:
    if not isinstance(raw, list):
        return []
    out: list[str] = []
    seen: set[str] = set()
    for item in raw:
        if not isinstance(item, str):
            continue
        s = " ".join(item.split()).strip()
        if not s or len(s) > MAX_SUGGESTION_CHARS or s in seen:
            continue
        seen.add(s)
        out.append(s)
        if len(out) >= MAX_SUGGESTIONS:
            break
    return out


def clean_references(raw: object) -> list[dict]:
    """The model's `references` -> [{type, id, url}]. Tolerant: bad items are skipped.

    Accepted shapes of one item: {"type": "product", "id": "23"} / {"id": "product:23"} (the id
    attribute of a knowledge source) / {"type": "page", "url": "https://..."}. An item needs an id
    or an http(s) url; the site looks the content up by id first, then by url.
    """
    if not isinstance(raw, list):
        return []
    out: list[dict] = []
    seen: set[tuple[str, str]] = set()
    for item in raw:
        if not isinstance(item, dict):
            continue
        kind = str(item.get("type") or "").strip().lower()
        kind = _REF_ALIASES.get(kind, kind)
        rid = item.get("id")
        rid = "" if rid is None or isinstance(rid, (dict, list, bool)) else str(rid).strip()
        if ":" in rid:  # "product:23" -> type product, id 23
            prefix, _, rest = rid.partition(":")
            prefix = _REF_ALIASES.get(prefix.strip().lower(), prefix.strip().lower())
            if prefix in REFERENCE_TYPES and rest.strip():
                kind, rid = prefix, rest.strip()
        url = item.get("url")
        url = url.strip() if isinstance(url, str) else ""
        if not url.lower().startswith(("http://", "https://")) or len(url) > 2048:
            url = ""
        if kind not in REFERENCE_TYPES or len(rid) > 80 or not (rid or url):
            continue
        key = (kind, rid or url)
        if key in seen:
            continue
        seen.add(key)
        out.append({"type": kind, "id": rid or None, "url": url or None})
        if len(out) >= MAX_REFERENCES:
            break
    return out


def parse_reply(raw: str | None) -> GeneratedAnswer:
    """Model output -> GeneratedAnswer. Raises ReplyFormatError when unusable.

    Broken/truncated JSON is never shown to the visitor as text (that would leak `{"text": ...`);
    plain non-JSON text is accepted as the answer without suggestions.
    """
    s = (raw or "").strip()
    if not s:
        raise ReplyFormatError("empty model output")
    s = _FENCE.sub("", s).strip()
    data = None
    if s.startswith("{"):
        try:
            data = json.loads(s)
        except json.JSONDecodeError:
            end = s.rfind("}")
            if end > 0:
                try:
                    data = json.loads(s[: end + 1])
                except json.JSONDecodeError:
                    data = None
        if not isinstance(data, dict):
            raise ReplyFormatError("model output is not valid JSON")
    elif s.startswith("["):
        raise ReplyFormatError("model output is not the expected JSON object")
    else:
        # plain text fallback (a bare "Mark-Chat" is the hand-over answer)
        return mark_answer() if is_mark_text(s) else GeneratedAnswer(text=s)
    text = data.get("text")
    if not isinstance(text, str) or not text.strip():
        raise ReplyFormatError("JSON has no usable `text`")
    if is_mark_text(text):
        return mark_answer()  # nothing else may come with it
    return GeneratedAnswer(
        text=text.strip(),
        suggestions=clean_suggestions(data.get("suggestions")),
        references=clean_references(data.get("references")),
    )
