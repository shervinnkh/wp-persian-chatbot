import hashlib
import hmac

import pytest

from app.core.errors import AuthError
from app.core.security import compute_signature, verify_signature

SECRET = "b2" * 32
NOW = 1_800_000_000


def _sig(ts, body=b"{}"):
    return compute_signature(SECRET, str(ts), body)


def _verify(ts, sig, body=b"{}", **kw):
    return verify_signature(
        secret=SECRET, timestamp=str(ts) if ts is not None else None,
        signature=sig, body=body, now=NOW, **kw,
    )


def test_signature_matches_documented_algorithm():
    expected = hmac.new(SECRET.encode(), b"123\n{}", hashlib.sha256).hexdigest()
    assert compute_signature(SECRET, "123", b"{}") == expected


def test_valid_request():
    assert _verify(NOW - 10, _sig(NOW - 10)) == NOW - 10


def test_uppercase_hex_signature_is_accepted():
    assert _verify(NOW, _sig(NOW).upper()) == NOW


@pytest.mark.parametrize("ts,reason", [(NOW - 301, "expired"), (NOW + 301, "expired")])
def test_stale_or_future_rejected(ts, reason):
    with pytest.raises(AuthError) as exc:
        _verify(ts, _sig(ts))
    assert exc.value.reason == reason


def test_boundary_five_minutes_ok():
    assert _verify(NOW - 300, _sig(NOW - 300)) == NOW - 300


def test_tampered_body_rejected():
    with pytest.raises(AuthError) as exc:
        _verify(NOW, _sig(NOW, b'{"a":1}'), body=b'{"a":2}')
    assert exc.value.reason == "bad_signature"


def test_wrong_secret_rejected():
    bad = compute_signature("c3" * 32, str(NOW), b"{}")
    with pytest.raises(AuthError):
        _verify(NOW, bad)


@pytest.mark.parametrize("ts", [None, "", "abc", "12.5", "-5", "١٢٣٤٥٦٧٨٩٠"])
def test_bad_timestamps(ts):
    with pytest.raises(AuthError):
        verify_signature(secret=SECRET, timestamp=ts, signature="x", body=b"", now=NOW)


def test_missing_or_non_ascii_signature():
    with pytest.raises(AuthError):
        _verify(NOW, None)
    with pytest.raises(AuthError):
        _verify(NOW, "امضا")
