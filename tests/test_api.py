import json
import time

import pytest
from pydantic import ValidationError

from tests.conftest import SECRET, make_settings, signed_headers


def test_health_is_public(client):
    r = client.get("/health")
    assert r.status_code == 200 and r.json() == {"status": "ok"}
    assert r.headers["x-request-id"]


def test_ping_requires_auth(client):
    r = client.get("/v1/ping")
    assert r.status_code == 401
    assert r.json()["error"]["code"] == "unauthorized"


def test_ping_get_signed(client):
    r = client.get("/v1/ping", headers=signed_headers())
    assert r.status_code == 200
    data = r.json()
    assert data["authenticated"] is True and abs(data["server_time"] - time.time()) < 5


def test_ping_post_signed_body(client):
    body = json.dumps({"متن": "سلام"}, ensure_ascii=False).encode("utf-8")
    r = client.post("/v1/ping", content=body, headers=signed_headers(body))
    assert r.status_code == 200 and r.json()["received_bytes"] == len(body)


def test_tampered_body_rejected(client):
    headers = signed_headers(b'{"a":1}')
    r = client.post("/v1/ping", content=b'{"a":2}', headers=headers)
    assert r.status_code == 401


def test_old_timestamp_rejected(client):
    r = client.get("/v1/ping", headers=signed_headers(ts=int(time.time()) - 400))
    assert r.status_code == 401


def test_unknown_route_uses_error_format(client):
    r = client.get("/nope")
    assert r.status_code == 404 and r.json()["error"]["code"] == "not_found"


def test_https_required(make_client):
    http_client = make_client(REQUIRE_HTTPS=True)
    r = http_client.get("/v1/ping", headers=signed_headers())
    assert r.status_code == 400 and r.json()["error"]["code"] == "https_required"
    assert http_client.get("/health").status_code == 200  # exempt

    https_client = make_client(base_url="https://testserver", REQUIRE_HTTPS=True)
    assert https_client.get("/v1/ping", headers=signed_headers()).status_code == 200


def test_ip_allowlist(make_client):
    allowed = make_client(ALLOWED_IPS="203.0.113.0/24")
    assert allowed.get("/v1/ping", headers=signed_headers()).status_code == 200
    blocked = make_client(ALLOWED_IPS="198.51.100.7")
    r = blocked.get("/v1/ping", headers=signed_headers())
    assert r.status_code == 403 and r.json()["error"]["code"] == "forbidden"


def test_body_too_large(make_client):
    small = make_client(MAX_BODY_BYTES=1_000)
    body = b"x" * 2_000
    r = small.post("/v1/ping", content=body, headers=signed_headers(body))
    assert r.status_code == 413


def test_docs_disabled_in_production(make_client):
    prod = make_client(
        base_url="https://testserver", APP_ENV="production", REQUIRE_HTTPS=True
    )
    assert prod.get("/docs").status_code == 404


@pytest.mark.parametrize(
    "secret", ["", "short", "a" * 63, "ab" * 10, "0" * 64]
)
def test_weak_secrets_refused(tmp_path, secret):
    with pytest.raises(ValidationError):
        make_settings(tmp_path, API_SHARED_SECRET=secret)


def test_production_requires_https(tmp_path):
    with pytest.raises(ValidationError):
        make_settings(tmp_path, APP_ENV="production", REQUIRE_HTTPS=False)


def test_secret_never_in_repr(tmp_path):
    assert SECRET not in repr(make_settings(tmp_path))
