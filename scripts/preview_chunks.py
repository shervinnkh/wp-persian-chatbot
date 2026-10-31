"""Preview how a record is chunked (no network, nothing is stored).

  python scripts/preview_chunks.py record.json --id product:13199
  python scripts/preview_chunks.py records.json          # [{"id": "...", "data": {...}}, ...]

Uses the CHUNK_* / TIKTOKEN_* settings from .env, so you can tune thresholds and re-run.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.core.config import Settings  # noqa: E402
from app.services.chunking import ChunkConfig, TokenCounter, build_plan  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "file", type=Path, help="JSON file: one record's data, or a list of {id,data}"
    )
    parser.add_argument("--id", dest="record_id", help="record id when the file holds only `data`")
    args = parser.parse_args()

    raw = json.loads(args.file.read_text(encoding="utf-8-sig"))
    items = raw if isinstance(raw, list) else [{"id": args.record_id, "data": raw}]
    if any(not i.get("id") for i in items):
        parser.error("--id is required when the file contains a single record's data")

    settings = Settings()
    counter = TokenCounter.from_settings(settings)
    cfg = ChunkConfig(
        settings.CHUNK_TARGET_TOKENS,
        settings.CHUNK_MAX_TOKENS,
        settings.CHUNK_OVERLAP_TOKENS,
        settings.CHUNK_MIN_TOKENS,
    )
    print(f"tokenizer: {counter.name} ({'exact' if counter.exact else 'APPROXIMATE'})  cfg: {cfg}")
    for item in items:
        data = json.loads(item["data"]) if isinstance(item["data"], str) else item["data"]
        plan = build_plan(item["id"], data, counter, cfg)
        print(f"\n######## {plan.record_id}  type={plan.record_type}  chunks={len(plan.chunks)}"
              f"  tokens={plan.total_tokens}")  # fmt: skip
        for c in plan.chunks:
            print(f"\n--- chunk {c.index}  kind={c.kind}  tokens={c.tokens}  id={c.chunk_id}")
            print(c.text)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
