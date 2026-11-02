"""Prompt context: the small, static facts that go into the system prompt of an AI answer.

Only built when a user message asks for an answer (`generate_ai_answer=true`). Nothing here calls
an AI; the real generator (RAG + LLM) will use `PromptContext.system_prompt()` as its system text.
"""

from __future__ import annotations

from dataclasses import dataclass

from app.core.config import Settings
from app.schemas.messaging import SendMessageRequest

TONES = {
    "formal": (
        "Use a formal, polite and professional tone (in Persian address the user with «شما»)."
    ),
    "friendly": "Use a warm, friendly and conversational tone, while staying respectful.",
}


@dataclass(frozen=True)
class PromptContext:
    model: str
    tone: str = "formal"
    site_title: str = ""
    site_description: str = ""
    page_type: str = ""
    page_title: str = ""
    page_slug: str = ""
    page_url: str = ""

    @classmethod
    def from_request(cls, req: SendMessageRequest, settings: Settings) -> PromptContext:
        site = req.site
        page = req.page
        return cls(
            model=settings.resolve_model(req.model),
            tone=req.tone or "formal",
            site_title=(site.title or "").strip() if site else "",
            site_description=(site.description or "").strip() if site else "",
            page_type=(page.type or "").strip() if page else "",
            page_title=(page.title or "").strip() if page else "",
            page_slug=(page.slug or "").strip() if page else "",
            page_url=(page.url or "").strip() if page else "",
        )

    @property
    def has_page(self) -> bool:
        return bool(self.page_title or self.page_slug or self.page_url)

    def system_prompt(self) -> str:
        lines: list[str] = []
        if self.site_title:
            intro = f"You are the website assistant of \"{self.site_title}\"."
            if self.site_description:
                intro += f" About the site: {self.site_description}"
            lines.append(intro)
        else:
            lines.append("You are the assistant of this website.")
        lines.append("Answer in the language of the user (Persian by default).")
        lines.append(TONES.get(self.tone, TONES["formal"]))
        if self.has_page:
            parts = []
            if self.page_type:
                parts.append(f"type: {self.page_type}")
            if self.page_title:
                parts.append(f"title: {self.page_title}")
            if self.page_slug:
                parts.append(f"slug: {self.page_slug}")
            if self.page_url:
                parts.append(f"url: {self.page_url}")
            lines.append(
                "The visitor is currently on this page of the site ("
                + "; ".join(parts)
                + "). Use it only to understand what their question refers to."
            )
        return "\n".join(lines)
