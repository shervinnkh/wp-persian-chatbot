"""Realtime (WebSocket, notify-only). See PROJECT.md "Realtime"."""

from app.services.realtime.manager import ConnectionManager, get_manager
from app.services.realtime.notifier import LoopbackNotifier

__all__ = ["ConnectionManager", "LoopbackNotifier", "get_manager"]
