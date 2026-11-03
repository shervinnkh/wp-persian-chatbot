r"""Read the SQLite text database directly (works even when the server is stopped).

  python scripts\inspect_db.py                    # summary + list of records
  python scripts\inspect_db.py product:13199      # all chunks of a record
  python scripts\inspect_db.py product:13199 --data   # + stored raw JSON
Qdrant (embedded mode) is locked by the running server, so use kb_show.py to see vectors.
"""

from __future__ import annotations

import argparse
import json
import sqlite3
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.core.config import Settings  # noqa: E402

for _s in (sys.stdout, sys.stderr):
    try:
        _s.reconfigure(encoding="utf-8")
    except Exception:  # noqa: BLE001
        pass


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("record_id", nargs="?")
    ap.add_argument("--data", action="store_true")
    ap.add_argument("--db", type=Path, default=None, help="SQLite file (default: from .env)")
    args = ap.parse_args()
    if args.db is None:
        url = Settings().database_url
        if not url.startswith("sqlite"):
            print(f"DATABASE_URL is not SQLite ({url.split(':')[0]}); use your DB client instead")
            return 1
        args.db = Path(url.split(":///", 1)[1])
    if not args.db.exists():
        print(f"database not found: {args.db}")
        return 1
    con = sqlite3.connect(f"file:{args.db}?mode=ro", uri=True)

    if not args.record_id:
        n_rec, n_chunk, n_tok = con.execute(
            "select count(*), coalesce(sum(chunk_count),0), coalesce(sum(token_count),0) from records"
        ).fetchone()
        real = con.execute("select count(*) from chunks").fetchone()[0]
        print(
            f"records={n_rec}  chunks(records.chunk_count)={n_chunk}  chunks(rows)={real}  tokens={n_tok}"
        )
        if n_chunk != real:
            print("!! chunk_count does not match chunk rows")
        for r in con.execute(
            "select record_id, type, chunk_count, token_count, title, updated_at from records order by kind, record_id"
        ):
            print(
                f"  {r[0]:<28} {r[1] or '-':<10} chunks={r[2]:<3} tokens={r[3]:<5} {r[4]}  ({r[5][:19]})"
            )
        return 0

    rec = con.execute(
        "select record_id, kind, type, title, url, chunk_count, token_count, pipeline, data from records where record_id=?",
        (args.record_id,),
    ).fetchone()
    if rec is None:
        print("record not found")
        return 1
    print(f"{rec[0]}  kind={rec[1]} type={rec[2]}\ntitle={rec[3]}\nurl={rec[4]}\n"
          f"chunks={rec[5]} tokens={rec[6]}\npipeline={rec[7]}")  # fmt: skip
    for idx, cid, tokens, h, text in con.execute(
        'select "index", chunk_id, tokens, hash, text from chunks where record_id=? order by "index"',
        (args.record_id,),
    ):
        print(f"\n--- chunk {idx}  id={cid}  tokens={tokens}  chars={len(text)}  hash={h[:12]}")
        print(text)
    if args.data:
        print("\n=== stored raw data")
        print(json.dumps(json.loads(rec[8]), ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
