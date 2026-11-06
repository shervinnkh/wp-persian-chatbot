"""WebSocket auth token (must match the plugin's Chat\\Realtime::issue).

token   = b64url(payload_json) + "." + b64url(HMAC_SHA256(ws_key, b64url(payload_json)))
ws_key  = HMAC_SHA256(key=API_SHARED_SECRET, msg=b"pwc-ws-v1")
payload = {"v": visitor_uid, "aud": "host[:port]", "iat": int, "exp": int, "jti": str}
"""

from __future__ import annotations

import base64
import binascii
import hashlib
import hmac
import json
import re
import time
from collections import OrderedDict
from dataclasses import dataclass

KEY_LABEL = b"pwc-ws-v1"
_UID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
_JTI = re.compile(r"^[A-Za-z0-9]{16,64}$")


class TokenError(Exception):
    pass


@dataclass(frozen=True, slots=True)
class WsToken:
    visitor: str
    aud: str
    exp: int
    jti: str


def _unb64(s: str) -> bytes:
    try:
        return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))
    except (binascii.Error, ValueError) as exc:
        raise TokenError("bad_encoding") from exc


def ws_key(secret: str) -> bytes:
    return hmac.new(secret.encode(), KEY_LABEL, hashlib.sha256).digest()


def make_token(secret: str, visitor: str, aud: str, *, now: int | None = None, ttl: int = 600, jti: str = "a" * 32) -> str:
    """Used by tests (the plugin issues real tokens)."""
    now = int(time.time()) if now is None else now
    payload = json.dumps({"v": visitor, "aud": aud, "iat": now, "exp": now + ttl, "jti": jti}, separators=(",", ":"))
    p = base64.urlsafe_b64encode(payload.encode()).rstrip(b"=").decode()
    sig = base64.urlsafe_b64encode(hmac.new(ws_key(secret), p.encode(), hashlib.sha256).digest()).rstrip(b"=").decode()
    return f"{p}.{sig}"


def verify_token(token: str, secret: str, *, max_ttl: int, now: float | None = None) -> WsToken:
    if not isinstance(token, str) or len(token) > 700 or token.count(".") != 1:
        raise TokenError("malformed")
    p, sig = token.split(".")
    expected = hmac.new(ws_key(secret), p.encode("ascii", "ignore"), hashlib.sha256).digest()
    if not hmac.compare_digest(expected, _unb64(sig)):
        raise TokenError("bad_signature")
    try:
        data = json.loads(_unb64(p))
        visitor, aud, exp, iat, jti = str(data["v"]), str(data["aud"]).lower(), int(data["exp"]), int(data["iat"]), str(data["jti"])
    except (ValueError, KeyError, TypeError) as exc:
        raise TokenError("bad_payload") from exc
    current = time.time() if now is None else now
    if exp <= current:
        raise TokenError("expired")
    if exp - iat > max_ttl or iat > current + 60:
        raise TokenError("bad_lifetime")
    if not _UID.match(visitor) or not _JTI.match(jti):
        raise TokenError("bad_payload")
    return WsToken(visitor, aud, exp, jti)


class JtiCache:
    """Single-use tokens: remembers jti until the token expires (bounded)."""

    def __init__(self, max_items: int = 50_000):
        self._d: OrderedDict[str, float] = OrderedDict()
        self._max = max_items

    def use(self, jti: str, exp: float, now: float | None = None) -> bool:
        now = time.time() if now is None else now
        while self._d:
            k, v = next(iter(self._d.items()))
            if v < now or len(self._d) > self._max:
                self._d.popitem(last=False)
            else:
                break
        if jti in self._d:
            return False
        self._d[jti] = exp
        return True
