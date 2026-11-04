"""Daily / monthly / yearly reports from the monitoring JSONL files.

  python scripts/monitor_export.py --period day                       # today
  python scripts/monitor_export.py --period day   --date 2026-11-03
  python scripts/monitor_export.py --period month --date 2026-11
  python scripts/monitor_export.py --period year  --date 2026
  python scripts/monitor_export.py --period month --date 1405-08 --calendar jalali
  python scripts/monitor_export.py --from 2026-10-01 --to 2026-10-15   # custom range (daily series)
  python scripts/monitor_export.py --period day --raw --event chat.answer,llm.chat --gzip
  python scripts/monitor_export.py --period month --price gpt-5-4=1.25,10 --price text-embedding-3-large=0.13,0

Writes <monitor dir>/reports/<label>.json (+ .summary.csv / .series.csv; --raw also .jsonl[.gz]).
Series: day -> hours, month/year/range -> days (year: months with --unit month).
Days whose raw files were already deleted are taken from their roll-up (see MONITORING.md).
"""

from __future__ import annotations

import argparse
import gzip
import json
import sys
import time
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.core import monitor_report as rep  # noqa: E402


def _settings_defaults() -> tuple[Path, str]:
    try:
        from app.core.config import get_settings

        s = get_settings()
        return s.monitor_dir, s.MONITOR_UTC_OFFSET
    except Exception:  # noqa: BLE001 - .env missing/invalid: fall back to defaults
        return ROOT / "logs" / "monitoring", "+00:00"


def _prices(items: list[str]) -> dict[str, tuple[float, float]]:
    out = {}
    for it in items:
        try:
            model, rest = it.rsplit("=", 1)
            a, b = rest.split(",")
            out[model] = (float(a), float(b))
        except ValueError:
            raise SystemExit(f"bad --price {it!r}; use MODEL=INPUT,OUTPUT (per 1M tokens)")
    return out


def main() -> int:
    base_default, offset_default = _settings_defaults()
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    ap.add_argument("--period", choices=("day", "month", "year"), default="day")
    ap.add_argument("--date", help="2026-11-03 | 2026-11 | 2026 (Jalali digits with --calendar jalali)")
    ap.add_argument("--from", dest="start", help="custom range start (YYYY-MM-DD, in --calendar)")
    ap.add_argument("--to", dest="end", help="custom range end, inclusive")
    ap.add_argument("--calendar", choices=("gregorian", "jalali"), default="gregorian")
    ap.add_argument("--unit", choices=("hour", "day", "month"), help="series unit (default by period)")
    ap.add_argument("--dir", type=Path, default=base_default, help="monitoring folder")
    ap.add_argument("--utc-offset", default=offset_default, help="day boundary the files were written with")
    ap.add_argument("--out", type=Path, help="output folder (default <dir>/reports)")
    ap.add_argument("--price", action="append", default=[], help="MODEL=INPUT,OUTPUT per 1M tokens")
    ap.add_argument("--wide", action="store_true", help="series.csv with every metric as a column")
    ap.add_argument("--no-rollups", action="store_true", help="always read raw lines when present")
    ap.add_argument("--raw", action="store_true", help="also export the raw events of the period")
    ap.add_argument("--event", help="raw export: comma separated event names")
    ap.add_argument("--service", help="raw export: api,ingestion,delivery,summarizer")
    ap.add_argument("--gzip", action="store_true", help="raw export: compress")
    ap.add_argument("--quiet", action="store_true")
    a = ap.parse_args()

    off = rep.parse_offset(a.utc_offset)
    now = time.time()
    try:
        if a.start or a.end:
            def conv(t: str) -> date:
                y, m, d = (int(x) for x in t.split("-"))
                return rep.jalali_to_gregorian(y, m, d) if a.calendar == "jalali" else date(y, m, d)

            first = conv(a.start or a.end)
            last = conv(a.end or a.start)
            label = f"{a.start or a.end}_to_{a.end or a.start}"
            default_unit = "day"
        else:
            first, last, label = rep.resolve_period(a.period, a.date, a.calendar, off, now)
            default_unit = {"day": "hour", "month": "day", "year": "day"}[a.period]
    except ValueError as exc:
        raise SystemExit(f"error: {exc}")
    if last < first:
        raise SystemExit("error: --to is before --from")
    unit = a.unit or default_unit

    report = rep.build_report(
        a.dir, first, last, unit=unit, calendar=a.calendar, offset_min=off,
        prices=_prices(a.price) or None, use_rollups=not a.no_rollups,
    )
    outdir = a.out or (a.dir / "reports")
    outdir.mkdir(parents=True, exist_ok=True)
    stem = f"{a.period if not (a.start or a.end) else 'range'}-{label}"
    jpath = outdir / f"{stem}.json"
    jpath.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    written = [jpath, *rep.write_csv_files(report, outdir, stem, wide=a.wide)]

    if a.raw:
        rpath = outdir / (f"{stem}.raw.jsonl" + (".gz" if a.gzip else ""))
        opener = gzip.open if a.gzip else open
        n = 0
        with opener(rpath, "wt", encoding="utf-8") as fh:
            for rec in rep.iter_raw(
                a.dir, first, last,
                events=set(a.event.split(",")) if a.event else None,
                services=set(a.service.split(",")) if a.service else None,
            ):
                fh.write(json.dumps(rec, ensure_ascii=False, separators=(",", ":")) + "\n")
                n += 1
        written.append(rpath)
        if not a.quiet:
            print(f"raw events exported: {n}")

    if not a.quiet:
        sm, cov = report["summary"], report["coverage"]
        print(f"period {first} .. {last} ({a.calendar}), series by {unit}")
        print(f"coverage: raw days={cov['days_raw']} roll-up days={cov['days_rollup']} "
              f"missing={len(cov['days_missing'])} bad lines={cov['bad_lines']}")
        for name, path in rep.HEADLINE:
            v = rep._get(sm, path)
            if v is not None:
                print(f"  {name:28s} {v}")
        for p in written:
            print("wrote", p)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
