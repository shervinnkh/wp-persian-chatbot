r"""Send records to the knowledge base (chunk -> embed -> Qdrant + DB).

  python scripts\kb_upsert.py records.json                     # [{"id":..,"data":{..}}, ...]
  python scripts\kb_upsert.py product.json --id product:13199  # file = one record's data
  add --force to re-embed unchanged records;  --batch-size N (default 10)
The server must be running (run.bat).
"""

from __future__ import annotations

import argparse
from pathlib import Path

from _client import call, default_base_url, load_records


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("file", type=Path)
    ap.add_argument("--id", dest="record_id")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--batch-size", type=int, default=10)
    ap.add_argument("--url", default=default_base_url())
    args = ap.parse_args()

    records = load_records(args.file, args.record_id)
    print(f"{len(records)} record(s) -> {args.url}")
    failed = 0
    for i in range(0, len(records), args.batch_size):
        batch = records[i : i + args.batch_size]
        r = call(
            "POST",
            f"{args.url}/v1/knowledge/records",
            {"records": batch, "force": args.force},
        )
        if r.status_code != 200:
            print(f"batch {i // args.batch_size + 1}: HTTP {r.status_code}: {r.text[:500]}")
            failed += len(batch)
            continue
        for res in r.json()["results"]:
            line = (
                f"  {res['status']:<9} {res['id']:<28} chunks={res['chunks']} "
                f"embedded={res['embedded_chunks']} reused={res['reused_chunks']} "
                f"tokens={res['tokens']} embedding_tokens={res['embedding_tokens']} "
                f"{res['duration_ms']}ms"
            )
            print(line + (f"  ERROR: {res['error']}" if res["error"] else ""))
            failed += res["status"] == "failed"
    print("done" if not failed else f"done with {failed} failure(s)")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
