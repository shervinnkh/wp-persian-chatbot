"""Request authentication: HMAC-SHA256 over ``timestamp + "\\n" + body``.

Client (WordPress plugin) sends:
    X-Timestamp: <unix seconds, integer>
    X-Signature: <hex(hmac_sha256(secret, timestamp + "\\n" + raw_body))>
"""

from __future__ import annotations

import hashlib
import hmac
import time

from app.core.errors import AuthError

HEADER_TIMESTAMP = "X-Timestamp"
HEADER_SIGNATURE = "X-Signature"


def compute_signature(secret: str | bytes, timestamp: str, body: bytes) -> str:
    key = secret.encode("utf-8") if isinstance(secret, str) else secret
    message = timestamp.encode("ascii") + b"\n" + body
    return hmac.new(key, message, hashlib.sha256).hexdigest()


def verify_signature(
    *,
    secret: str | bytes,
    timestamp: str | None,
    signature: str | None,
    body: bytes,
    max_skew: int = 300,
    now: float | None = None,
) -> int:
    """Validate a request. Returns the timestamp (int) or raises AuthError."""
    if not timestamp or not signature:
        raise AuthError("Missing authentication headers", reason="missing_headers")

    ts = timestamp.strip()
    if not (ts.isascii() and ts.isdigit()) or len(ts) > 12:
        raise AuthError("Invalid timestamp", reason="bad_timestamp")

    current = time.time() if now is None else now
    if abs(current - int(ts)) > max_skew:
        raise AuthError("Request timestamp is expired or too far in the future", reason="expired")

    provided = signature.strip().lower()
    if not provided.isascii():
        raise AuthError("Invalid signature", reason="bad_signature")

    expected = compute_signature(secret, ts, body)
    if not hmac.compare_digest(expected.encode("ascii"), provided.encode("ascii")):
        raise AuthError("Invalid signature", reason="bad_signature")
    return int(ts)
