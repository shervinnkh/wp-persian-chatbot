"""Shared helper for the kb_*.py scripts: signed requests to the running backend."""

from __future__ import annotations

import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import httpx  # noqa: E402

from app.core.config import Settings  # noqa: E402
from app.core.security import HEADER_SIGNATURE, HEADER_TIMESTAMP, compute_signature  # noqa: E402

for _stream in (sys.stdout, sys.stderr):
    try:
        _stream.reconfigure(encoding="utf-8")
    except Exception:  # noqa: BLE001
        pass


def default_base_url() -> str:
    return f"http://127.0.0.1:{Settings().PORT}"


def call(method: str, url: str, payload: dict | None = None, timeout: int = 300) -> httpx.Response:
    """Send a request signed exactly like the WordPress plugin does."""
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8") if payload is not None else b""
    secret = Settings().API_SHARED_SECRET.get_secret_value()
    stamp = str(int(time.time()))
    headers = {
        HEADER_TIMESTAMP: stamp,
        HEADER_SIGNATURE: compute_signature(secret, stamp, body),
        "Content-Type": "application/json",
    }
    return httpx.request(method, url, content=body, headers=headers, timeout=timeout)


def show(response: httpx.Response) -> None:
    print(f"HTTP {response.status_code}")
    try:
        print(json.dumps(response.json(), ensure_ascii=False, indent=2))
    except ValueError:
        print(response.text)


def load_records(path: Path, record_id: str | None) -> list[dict]:
    """File may hold: [{id,data},...] | {"records":[...]} | one record's raw data (+ --id)."""
    raw = json.loads(path.read_text(encoding="utf-8-sig"))
    if isinstance(raw, dict) and "records" in raw:
        raw = raw["records"]
    if isinstance(raw, list):
        return raw
    if not record_id:
        raise SystemExit("The file holds a single record's data: pass --id product:13199")
    return [{"id": record_id, "data": raw}]
