"""Generate the shared HMAC secret (32 random bytes = 64 hex chars).

  python scripts/generate_secret.py            # print a new secret
  python scripts/generate_secret.py --write    # put it into .env if API_SHARED_SECRET is empty
  python scripts/generate_secret.py --write --force   # replace an existing secret
  (--quiet hides the 'already set' message; used by run.bat)
"""

from __future__ import annotations

import re
import secrets
import sys
from pathlib import Path

ENV_PATH = Path(__file__).resolve().parents[1] / ".env"
KEY = "API_SHARED_SECRET"


def main(argv: list[str]) -> int:
    secret = secrets.token_hex(32)
    if "--write" not in argv:
        print(secret)
        return 0

    text = ENV_PATH.read_text(encoding="utf-8-sig") if ENV_PATH.exists() else ""
    pattern = re.compile(rf"^{KEY}=(.*)$", re.MULTILINE)
    match = pattern.search(text)
    if match and match.group(1).strip() and "--force" not in argv:
        if "--quiet" not in argv:
            print(f"{KEY} is already set in .env (use --force to replace it).")
        return 0

    line = f"{KEY}={secret}"
    if match:
        text = pattern.sub(lambda _: line, text, count=1)
    else:
        text = text.rstrip("\n") + ("\n" if text else "") + line + "\n"
    ENV_PATH.write_text(text, encoding="utf-8")
    print(f"{KEY} written to {ENV_PATH}. Put the same value in the WordPress plugin.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
