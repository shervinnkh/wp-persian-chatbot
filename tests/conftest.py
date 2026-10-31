from __future__ import annotations

import time

import pytest
from fastapi.testclient import TestClient

from app.core.config import Settings
from app.core.security import HEADER_SIGNATURE, HEADER_TIMESTAMP, compute_signature
from app.main import create_app

SECRET = "aef4100c420374970e3ac37891073acfa79c7d580550e7a042faf9ac49fb8016"  # noqa: E501  (random test-only secret)


def make_settings(tmp_path, **overrides) -> Settings:
    values = dict(
        API_SHARED_SECRET=SECRET,
        REQUIRE_HTTPS=False,
        LOG_TO_FILE=False,
        LOG_LEVEL="WARNING",
        LOG_DIR=tmp_path / "logs",
        DATA_DIR=tmp_path / "data",
        UPLOADS_DIR=tmp_path / "uploads",
    )
    values.update(overrides)
    return Settings(_env_file=None, **values)


@pytest.fixture
def make_client(tmp_path):
    def _make(base_url="http://testserver", client=("203.0.113.10", 50000), **overrides):
        app = create_app(make_settings(tmp_path, **overrides))
        return TestClient(app, base_url=base_url, client=client)

    return _make


@pytest.fixture
def client(make_client):
    return make_client()


def signed_headers(body: bytes = b"", ts: int | None = None, secret: str = SECRET) -> dict:
    stamp = str(int(time.time()) if ts is None else ts)
    return {
        HEADER_TIMESTAMP: stamp,
        HEADER_SIGNATURE: compute_signature(secret, stamp, body),
        "Content-Type": "application/json",
    }
