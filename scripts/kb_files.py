r"""Send texts / files to the knowledge base through the API (signed like the plugin).

  python scripts\kb_files.py files add    files.json          # [{id,title,file_orginal_name,path}, ...]
  python scripts\kb_files.py files update files.json          # delete, then add again
  python scripts\kb_files.py files delete file:1793261168782 file:2
  python scripts\kb_files.py texts add    texts.json          # [{id:"text:..", data:{..}}, ...]
  python scripts\kb_files.py texts update texts.json
  python scripts\kb_files.py texts delete text:1793259417135
add --force to re-chunk/re-embed unchanged items.  The server must be running (run.bat).
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from _client import call, default_base_url, show

_METHOD = {"add": "POST", "update": "PUT"}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("kind", choices=["files", "texts"])
    ap.add_argument("action", choices=["add", "update", "delete"])
    ap.add_argument("target", nargs="+", help="JSON file (add/update) or record ids (delete)")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--url", default=default_base_url())
    args = ap.parse_args()
    url = f"{args.url}/v1/knowledge/{args.kind}"

    if args.action == "delete":
        r = call("DELETE", url, {"ids": args.target})
    else:
        items = json.loads(Path(args.target[0]).read_text(encoding="utf-8-sig"))
        key = "files" if args.kind == "files" else "records"
        if isinstance(items, dict) and key in items:
            items = items[key]
        r = call(_METHOD[args.action], url, {key: items, "force": args.force}, timeout=900)
    show(r)
    return 0 if r.status_code == 200 else 1


if __name__ == "__main__":
    raise SystemExit(main())
