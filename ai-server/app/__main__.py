"""Entry point: `python -m app` (reads HOST/PORT/RELOAD/WORKERS from .env)."""

from __future__ import annotations

import sys

import uvicorn
from pydantic import ValidationError

from app.core.config import BASE_DIR, get_settings


def main() -> int:
    try:
        settings = get_settings()
    except ValidationError as exc:
        print("\nConfiguration error (check your .env, see .env.example):\n", file=sys.stderr)
        for err in exc.errors():
            field = ".".join(str(p) for p in err["loc"]) or "settings"
            print(f"  - {field}: {err['msg']}", file=sys.stderr)
        return 1

    uvicorn.run(
        "app.main:create_app",
        factory=True,
        host=settings.HOST,
        port=settings.PORT,
        reload=settings.RELOAD,
        reload_dirs=[str(BASE_DIR / "app")] if settings.RELOAD else None,
        workers=settings.WORKERS if not settings.RELOAD else 1,
        proxy_headers=True,
        forwarded_allow_ips=settings.FORWARDED_ALLOW_IPS,
        log_config=None,  # logging is configured by app.core.logging
        access_log=False,  # we write our own access log (RequestContextMiddleware)
        ws_max_size=max(4096, settings.WS_MAX_MESSAGE_BYTES * 4),  # tiny frames only (notify-only sockets)
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
