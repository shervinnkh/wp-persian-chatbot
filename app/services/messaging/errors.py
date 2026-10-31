"""Messaging errors (reuse the project's uniform error format)."""

from __future__ import annotations

from app.core.errors import NotFoundError


class ConversationNotFoundError(NotFoundError):
    pass


class MessageNotFoundError(NotFoundError):
    pass
