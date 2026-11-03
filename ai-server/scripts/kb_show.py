r"""Look at what the running server stored (asks the API, so it also sees Qdrant).

python scripts\kb_show.py                       # list records
python scripts\kb_show.py --kind product        # only one kind
python scripts\kb_show.py product:13199         # chunks + Qdrant points of one record
add --data to also print the stored raw JSON of the record
"""

from __future__ import annotations

import argparse
import json

from _client import call, default_base_url


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("record_id", nargs="?")
    ap.add_argument("--kind")
    ap.add_argument("--data", action="store_true")
    ap.add_argument("--url", default=default_base_url())
    args = ap.parse_args()

    if not args.record_id:
        q = "?limit=500" + (f"&kind={args.kind}" if args.kind else "")
        r = call("GET", f"{args.url}/v1/knowledge/records{q}")
        if r.status_code != 200:
            print(r.status_code, r.text)
            return 1
        body = r.json()
        print(f"total: {body['total']}")
        for it in body["items"]:
            print(f"  {it['id']:<28} {it['type'] or '-':<10} chunks={it['chunk_count']:<3} "
                  f"tokens={it['token_count']:<5} {it['title']}")  # fmt: skip
        return 0

    r = call("GET", f"{args.url}/v1/knowledge/records/{args.record_id}")
    if r.status_code != 200:
        print(r.status_code, r.text)
        return 1
    d = r.json()
    rec = d["record"]
    print(f"{rec['id']}  type={rec['type']}  title={rec['title']}\nurl={rec['url']}")
    print(f"chunks={rec['chunk_count']} tokens={rec['token_count']} "
          f"qdrant_points={len(d['qdrant_points'])} in_sync={d['in_sync']}")  # fmt: skip
    for c in d["chunks"]:
        print(
            f"\n--- chunk {c['index']}  id={c['chunk_id']}  tokens={c['tokens']}  hash={c['hash'][:12]}"
        )
        print(c["text"])
    print("\n=== Qdrant payloads")
    for p in d["qdrant_points"]:
        print(json.dumps(p, ensure_ascii=False))
    if args.data:
        print("\n=== stored raw data")
        print(json.dumps(d["data"], ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
