r"""Delete records completely (text DB rows + Qdrant vectors).

python scripts\kb_delete.py product:13199 blog:15650
"""

from __future__ import annotations

import argparse

from _client import call, default_base_url, show


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("ids", nargs="+")
    ap.add_argument("--url", default=default_base_url())
    args = ap.parse_args()
    r = call("DELETE", f"{args.url}/v1/knowledge/records", {"ids": args.ids})
    show(r)
    return 0 if r.status_code == 200 else 1


if __name__ == "__main__":
    raise SystemExit(main())
