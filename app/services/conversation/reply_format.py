"""The JSON the model must return, and a tolerant parser for it.

    {"text": "<message for the visitor>", "suggestions": ["<short visitor reply>", ...]}

No third-party imports on purpose (easy to test).
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field

MAX_SUGGESTIONS = 4
MAX_SUGGESTION_CHARS = 60  # a longer "suggestion" is an explanation, not a quick reply

RESPONSE_SCHEMA = {
    "type": "object",
    "properties": {
        "text": {"type": "string"},
        "suggestions": {"type": "array", "items": {"type": "string"}},
    },
    "required": ["text", "suggestions"],
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
        return GeneratedAnswer(text=s)  # plain text fallback
    text = data.get("text")
    if not isinstance(text, str) or not text.strip():
        raise ReplyFormatError("JSON has no usable `text`")
    return GeneratedAnswer(text=text.strip(), suggestions=clean_suggestions(data.get("suggestions")))
