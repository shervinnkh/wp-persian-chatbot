"""Reading the end of `app.log` for the plugin's "server log" tab (POST /v1/get_log).

Light by design: one seek + one bounded read from the END of the current file (no scan of the
whole file, no database, no state). Every entry gets a content-hash id; the plugin sends the ids
of the last entries it already has and receives only what came after them.
"""

from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path
from typing import Any

MAX_TAIL_BYTES = 512 * 1024  # never read more than this from the end of the file
MAX_LIMIT = 500
MAX_AFTER_IDS = 10

_TEXT_LINE = re.compile(
    r"^(?P<ts>\d{4}-\d\d-\d\d \d\d:\d\d:\d\d,\d{3}) \| (?P<level>[A-Z]+)\s* \| "
    r"(?P<request_id>[^|]*?) \| (?P<logger>[^|]*?) \| (?P<message>.*)$"
)


def _entry_id(raw: str) -> str:
    return hashlib.sha1(raw.encode("utf-8", "replace")).hexdigest()[:12]


def _parse_entries(text: str) -> list[dict[str, Any]]:
    """Text format: a line that doesn't start with a timestamp (traceback) belongs to the entry
    above it. JSON format (LOG_JSON=true): one object per line."""
    entries: list[dict[str, Any]] = []
    for line in text.splitlines():
        if not line.strip():
            continue
        if line.startswith("{"):
            try:
                obj = json.loads(line)
            except ValueError:
                obj = None
            if isinstance(obj, dict):
                message = str(obj.get("message", ""))
                if obj.get("exc"):
                    message += "\n" + str(obj["exc"])
                entries.append(
                    {
                        "raw": line,
                        "time": str(obj.get("ts", "")),
                        "level": str(obj.get("level", "INFO")),
                        "request_id": str(obj.get("request_id", "-")),
                        "logger": str(obj.get("logger", "")),
                        "message": message,
                    }
                )
                continue
        m = _TEXT_LINE.match(line)
        if m:
            entries.append(
                {
                    "raw": line,
                    "time": m["ts"],
                    "level": m["level"],
                    "request_id": m["request_id"].strip(),
                    "logger": m["logger"].strip(),
                    "message": m["message"],
                }
            )
        elif entries:  # continuation (traceback line)
            entries[-1]["raw"] += "\n" + line
            entries[-1]["message"] += "\n" + line
    return entries


def read_tail(path: Path) -> list[dict[str, Any]]:
    """Entries of the last MAX_TAIL_BYTES of the file, oldest first. [] when there is no file."""
    try:
        with path.open("rb") as fh:
            fh.seek(0, 2)
            size = fh.tell()
            start = max(0, size - MAX_TAIL_BYTES)
            fh.seek(start)
            data = fh.read()
    except OSError:
        return []
    text = data.decode("utf-8", "replace")
    if start > 0:  # the first line is probably cut in the middle: drop it
        nl = text.find("\n")
        text = text[nl + 1 :] if nl >= 0 else ""
    entries = _parse_entries(text)
    for e in entries:
        e["id"] = _entry_id(e.pop("raw"))
    return entries


def logs_after(
    path: Path, after: list[str], limit: int
) -> tuple[list[dict[str, Any]], bool]:
    """Entries newer than the newest id of `after` that is still in the window.

    Returns (entries oldest->newest, reset). `reset` is true when `after` was given but none of
    its ids was found (file rotated / too old): then the last `limit` entries are returned.
    """
    limit = max(1, min(limit, MAX_LIMIT))
    entries = read_tail(path)
    wanted = {a for a in after[:MAX_AFTER_IDS] if a}
    if not wanted:
        return entries[-limit:], False
    pos = -1
    for i in range(len(entries) - 1, -1, -1):
        if entries[i]["id"] in wanted:
            pos = i
            break
    if pos < 0:
        return entries[-limit:], True
    return entries[pos + 1 :][-limit:], False
