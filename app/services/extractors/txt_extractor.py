
from __future__ import annotations
 
import re
from pathlib import Path
from typing import Any
 
from common import (
    Block,
    ExtractionError,
    build_result,
    check_input_file,
    is_rtl_letter,
    median,
    run_cli,
)
 
EXTRACTOR_NAME = "txt_extractor"
MAX_BYTES_DEFAULT = 200 * 1024 * 1024
 
_MD_HEADING_RE = re.compile(r"^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$")
_BULLET_RE = re.compile(r"^\s*[•●▪■◦○‣∙·*\-–—]\s+(.*)$")
_NUMBERED_RE = re.compile(r"^\s*(?:\(?\d{1,3}|\(?[۰-۹٠-٩]{1,3})[.)\-]\s+\S")
 
 
# --------------------------------------------------------------------------- #
# تشخیص encoding
# --------------------------------------------------------------------------- #
 
 
def _arabic_ratio(text: str) -> float:
    letters = [c for c in text if c.isalpha()]
    if not letters:
        return 0.0
    return sum(1 for c in letters if is_rtl_letter(c)) / len(letters)
 
 
def decode_bytes(data: bytes, encoding: str | None = None) -> tuple[str, str, list[dict[str, Any]]]:
    """(متن، encoding استفاده‌شده، هشدارها). ترتیب: BOM ← utf-8 ← utf-16 بدون BOM ← cp1256 (فارسی قدیمی) ← fallback."""
    warnings: list[dict[str, Any]] = []
    if encoding:
        try:
            return data.decode(encoding), encoding, warnings
        except (LookupError, UnicodeDecodeError) as exc:
            raise ExtractionError("decode_failed", f"Cannot decode file as {encoding}.") from exc
 
    if data.startswith(b"\xef\xbb\xbf"):
        return data.decode("utf-8-sig"), "utf-8-sig", warnings
    if data.startswith((b"\xff\xfe\x00\x00", b"\x00\x00\xfe\xff")):
        return data.decode("utf-32"), "utf-32", warnings
    if data.startswith((b"\xff\xfe", b"\xfe\xff")):
        return data.decode("utf-16"), "utf-16", warnings
 
    try:
        return data.decode("utf-8"), "utf-8", warnings
    except UnicodeDecodeError:
        pass
 
    sample = data[:20000]
    if sample.count(b"\x00") > len(sample) * 0.2:  # UTF-16 بدون BOM
        for enc in ("utf-16-le", "utf-16-be"):
            try:
                text = data.decode(enc)
            except UnicodeDecodeError:
                continue
            if _arabic_ratio(text) > 0.3 or sum(c.isalpha() for c in text) > 20:
                return text, enc, warnings
 
    candidates: list[tuple[float, str, str]] = []
    for enc in ("cp1256", "iso-8859-6"):
        try:
            text = data.decode(enc)
        except UnicodeDecodeError:
            continue
        candidates.append((_arabic_ratio(text), enc, text))
    if candidates:
        ratio, enc, text = max(candidates, key=lambda t: t[0])
        if ratio >= 0.5:
            warnings.append({"code": "legacy_encoding", "message": f"File was not UTF-8; decoded as {enc}."})
            return text, enc, warnings
 
    try:  # آخرین تلاش: کتابخانهٔ اختیاری
        from charset_normalizer import from_bytes  # type: ignore
 
        best = from_bytes(data).best()
        if best is not None:
            warnings.append({"code": "guessed_encoding", "message": f"Encoding guessed as {best.encoding}."})
            return str(best), best.encoding, warnings
    except ImportError:
        pass
 
    warnings.append({"code": "decode_with_replacement", "message": "Unknown encoding; invalid bytes were replaced."})
    return data.decode("utf-8", errors="replace"), "utf-8", warnings
 
 
# --------------------------------------------------------------------------- #
# پاراگراف‌سازی
# --------------------------------------------------------------------------- #
 
 
def _looks_hard_wrapped(lines: list[str]) -> bool:
    """خط‌های ۷۰–۸۰ نویسه‌ای که وسط جمله شکسته شده‌اند (نه شعر/فهرست)."""
    if len(lines) < 3:
        return False
    lengths = [len(x) for x in lines]
    longest = max(lengths)
    if longest < 50 or median(lengths) < 0.6 * longest:
        return False
    body = lengths[:-1]
    return sum(1 for n in body if n >= 0.75 * longest) >= 0.7 * len(body)
 
 
def _make_blocks(lines: list[str]) -> list[Block]:
    blocks: list[Block] = []
    group: list[str] = []
 
    def flush() -> None:
        if not group:
            return
        items: list[list[str]] = []
        for ln in group:
            if _BULLET_RE.match(ln) or _NUMBERED_RE.match(ln) or not items:
                items.append([ln])
            else:
                items[-1].append(ln)
        # بلوک‌های بدون نشانهٔ فهرست = یک پاراگراف؛ در غیر این‌صورت هر نشانه یک آیتم
        has_markers = any(_BULLET_RE.match(i[0]) or _NUMBERED_RE.match(i[0]) for i in items)
        if not has_markers:
            wrapped = _looks_hard_wrapped(group)
            blocks.append(Block("paragraph", list(group), join=" " if wrapped else "\n"))
        else:
            for item in items:
                first = item[0]
                m = _BULLET_RE.match(first)
                if m:
                    blocks.append(Block("list_item", [m.group(1), *item[1:]], join=" "))
                elif _NUMBERED_RE.match(first):
                    blocks.append(Block("list_item", item, join=" ", meta={"ordered": True}))
                else:
                    blocks.append(Block("paragraph", item, join="\n"))
        group.clear()
 
    for raw in lines:
        if not raw.strip():
            flush()
            continue
        m = _MD_HEADING_RE.match(raw)
        if m:
            flush()
            blocks.append(Block("heading", [m.group(2)], level=len(m.group(1))))
            continue
        group.append(raw.rstrip())
    flush()
    return blocks
 
 
# --------------------------------------------------------------------------- #
# API
# --------------------------------------------------------------------------- #
 
 
def extract_txt(
    path: str | Path,
    *,
    encoding: str | None = None,
    max_bytes: int = MAX_BYTES_DEFAULT,
) -> dict[str, Any]:
    p = check_input_file(path, (".txt", ".text"))
    if p.stat().st_size > max_bytes:
        raise ExtractionError("file_too_large", f"Text file is larger than {max_bytes // (1024 * 1024)} MB.")
 
    text, used_encoding, warnings = decode_bytes(p.read_bytes(), encoding)
    lines = text.replace("\r\n", "\n").replace("\r", "\n").split("\n")
    blocks = _make_blocks(lines)
 
    return build_result(
        blocks,
        path=p,
        extractor=EXTRACTOR_NAME,
        library="stdlib",
        warnings=warnings,
        extra={"encoding": used_encoding},
    )
 
 
def main(argv: list[str] | None = None) -> int:
    return run_cli(
        extract_txt,
        description="استخراج متن از TXT → JSON (خط‌به‌خط + پاراگراف)",
        exts=(".txt",),
        add_args=lambda ap: ap.add_argument("--encoding", help="اجبار encoding (مثلاً cp1256)"),
        kwargs_from_args=lambda a: {"encoding": a.encoding},
        argv=argv,
    )
 
 
if __name__ == "__main__":
    raise SystemExit(main())
 