"""Delivery worker -> API process: "this visitor has a new answer".

The delivery loop runs in its own process (python -m app.workers.delivery), the connection table
lives in the API process, so the worker calls the API's signed POST /v1/push over loopback
(same endpoint the WordPress plugin uses for human-support messages). Errors never propagate.
"""

from __future__ import annotations

import json
import logging
import time

import httpx

from app.core.config import Settings
from app.core.security import HEADER_SIGNATURE, HEADER_TIMESTAMP, compute_signature

logger = logging.getLogger(__name__)


class LoopbackNotifier:
    def __init__(self, settings: Settings, client: httpx.AsyncClient | None = None):
        self.settings = settings
        self._client = client

    async def notify(self, visitor_id: str, event: str = "new_message") -> bool:
        if not self.settings.ws_active:
            return False
        body = json.dumps({"visitor_id": visitor_id, "event": event}, separators=(",", ":")).encode()
        ts = str(int(time.time()))
        headers = {
            "Content-Type": "application/json",
            "X-Forwarded-Proto": "https",  # loopback hop; FORWARDED_ALLOW_IPS must include 127.0.0.1 (default)
            HEADER_TIMESTAMP: ts,
            HEADER_SIGNATURE: compute_signature(self.settings.API_SHARED_SECRET.get_secret_value(), ts, body),
        }
        url = f"{self.settings.ws_internal_url}/v1/push"
        try:
            client = self._client or httpx.AsyncClient(timeout=httpx.Timeout(2.0), trust_env=False)
            try:
                r = await client.post(url, content=body, headers=headers)
            finally:
                if self._client is None:
                    await client.aclose()
            return r.status_code == 200
        except Exception as exc:  # noqa: BLE001
            logger.warning("realtime.notify_failed type=%s", type(exc).__name__)
            return False
