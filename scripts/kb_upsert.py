r"""Queue records for the knowledge base (the worker does chunk -> embed -> Qdrant + DB).

  python scripts\kb_upsert.py records.json                     # [{"id":..,"data":{..}}, ...]
  python scripts\kb_upsert.py product.json --id product:13199  # file = one record's data
  add --force to re-embed unchanged records;  --batch-size N (default 10)
  add --no-wait to only queue (default: wait until the worker finished and print the status)
The server AND the ingestion worker must be running (run.bat).
"""

from __future__ import annotations

import argparse
import time
from pathlib import Path

from _client import call, default_base_url, load_records


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("file", type=Path)
    ap.add_argument("--id", dest="record_id")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--batch-size", type=int, default=10)
    ap.add_argument("--no-wait", action="store_true")
    ap.add_argument("--timeout", type=int, default=600, help="seconds to wait for the worker")
    ap.add_argument("--url", default=default_base_url())
    args = ap.parse_args()

    records = load_records(args.file, args.record_id)
    print(f"{len(records)} record(s) -> {args.url}")
    queued: list[str] = []
    failed = 0
    for i in range(0, len(records), args.batch_size):
        batch = records[i : i + args.batch_size]
        r = call(
            "POST", f"{args.url}/v1/knowledge/records", {"records": batch, "force": args.force}
        )
        if r.status_code != 200:
            print(f"batch {i // args.batch_size + 1}: HTTP {r.status_code}: {r.text[:500]}")
            failed += len(batch)
            continue
        body = r.json()
        queued += body["queued_ids"]
        print(f"batch {i // args.batch_size + 1}: queued {len(body['queued_ids'])} (200 OK)")

    if queued and not args.no_wait:
        deadline = time.time() + args.timeout
        pending = list(queued)
        while pending and time.time() < deadline:
            time.sleep(2)
            r = call("POST", f"{args.url}/v1/jobs/status", {"ids": pending[:500]})
            if r.status_code != 200:
                print(f"status check failed: HTTP {r.status_code}")
                return 1
            still = []
            for item in r.json()["results"]:
                if item["status"] == "processing":
                    still.append(item["id"])
                else:
                    print(f"  {item['status']:<10} {item['id']}" + (f"  ERROR: {item['error']}" if item["error"] else ""))
                    failed += item["status"] == "failed"
            pending = still
        if pending:
            print(f"still processing after {args.timeout}s: {len(pending)} record(s)")
            return 1
    print("done" if not failed else f"done with {failed} failure(s)")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
