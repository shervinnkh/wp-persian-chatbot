"""Send a signed request to the backend (manual testing / reference client).

  python scripts/sign_request.py GET  http://127.0.0.1:8000/v1/ping
  python scripts/sign_request.py POST http://127.0.0.1:8000/v1/ping --json '{"a": 1}'
  add --no-verify for self-signed TLS certificates
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import httpx  # noqa: E402

from app.core.config import Settings  # noqa: E402
from app.core.security import HEADER_SIGNATURE, HEADER_TIMESTAMP, compute_signature  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("method")
    parser.add_argument("url")
    parser.add_argument("--json", dest="body", default="")
    parser.add_argument("--no-verify", action="store_true")
    args = parser.parse_args()

    secret = Settings().API_SHARED_SECRET.get_secret_value()
    body = args.body.encode("utf-8")
    ts = str(int(time.time()))
    headers = {
        HEADER_TIMESTAMP: ts,
        HEADER_SIGNATURE: compute_signature(secret, ts, body),
        "Content-Type": "application/json",
    }
    response = httpx.request(
        args.method.upper(), args.url, content=body, headers=headers, verify=not args.no_verify
    )
    print(response.status_code)
    print(response.text)
    return 0 if response.is_success else 1


if __name__ == "__main__":
    raise SystemExit(main())
