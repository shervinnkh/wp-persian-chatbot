
from __future__ import annotations
 
import argparse
import hashlib
import json
import re
import statistics
import sys
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable, Sequence
 
SCHEMA_VERSION = "1.0"
 
 
class ExtractionError(Exception):
    """خطای قابل‌نمایش به کاربر. ``code`` با کدهای خطای پروژه (core/errors.py) قابل‌نگاشت است."""
 
    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
 
 
# --------------------------------------------------------------------------- #
# نویسه‌ها
# --------------------------------------------------------------------------- #
 
DIGITS = frozenset("0123456789" + "۰۱۲۳۴۵۶۷۸۹" + "٠١٢٣٤٥٦٧٨٩")
 
 
def is_rtl_letter(ch: str) -> bool:
    o = ord(ch)
    return (
        0x0600 <= o <= 0x06FF
        or 0x0750 <= o <= 0x077F
        or 0x08A0 <= o <= 0x08FF
        or 0xFB50 <= o <= 0xFDFF
        or 0xFE70 <= o <= 0xFEFF
    ) and ch.isalpha()
 
 
def is_ltr_letter(ch: str) -> bool:
    return ch.isalpha() and not is_rtl_letter(ch)
 
 
def has_rtl(s: str) -> bool:
    return any(is_rtl_letter(c) for c in s)
 
 
def is_rtl_line(s: str) -> bool:
    """جهت پایهٔ خط: اگر حروف فارسی/عربی کمتر از حروف لاتین نباشند، راست‌به‌چپ."""
    rtl = ltr = 0
    for c in s:
        if is_rtl_letter(c):
            rtl += 1
        elif is_ltr_letter(c):
            ltr += 1
    return rtl > 0 and rtl >= ltr
 
 
# --------------------------------------------------------------------------- #
# بصری → منطقی (bidi)
# --------------------------------------------------------------------------- #
 
_LTR_JOINERS = frozenset(".,:/-_'’&+%@٫٬")
_MIRROR = str.maketrans("()[]{}«»", ")(][}{»«")
_BRACKET_PAIRS = (("(", ")"), ("[", "]"), ("{", "}"), ("«", "»"))
 
 
def _ltr_spans(s: str) -> list[tuple[int, int]]:
    """بازه‌های چپ‌به‌راست (لاتین و عدد) در رشتهٔ بصری؛ مثل ``Python 3.12`` یا ``1404/05/12``."""
    spans: list[tuple[int, int]] = []
    n = len(s)
    i = 0
    while i < n:
        c = s[i]
        if is_ltr_letter(c) or c in DIGITS:
            j = i + 1
            has_letter = is_ltr_letter(c)
            while j < n:
                d = s[j]
                if is_ltr_letter(d):
                    has_letter = True
                    j += 1
                elif d in DIGITS:
                    j += 1
                elif d in _LTR_JOINERS and j + 1 < n and (s[j + 1] in DIGITS or is_ltr_letter(s[j + 1])):
                    j += 1
                elif d == " " and has_letter and j + 1 < n and (is_ltr_letter(s[j + 1]) or s[j + 1] in DIGITS):
                    j += 1
                else:
                    break
            spans.append((i, j))
            i = j
        else:
            i += 1
    return spans
 
 
def _reverse_segments(s: str, mirror: bool) -> str:
    segs: list[tuple[str, bool]] = []
    pos = 0
    for a, b in _ltr_spans(s):
        if a > pos:
            segs.append((s[pos:a], False))
        segs.append((s[a:b], True))
        pos = b
    if pos < len(s):
        segs.append((s[pos:], False))
    out: list[str] = []
    for text, is_ltr in reversed(segs):
        if is_ltr:
            out.append(text)
        else:
            t = text[::-1]
            out.append(t.translate(_MIRROR) if mirror else t)
    return "".join(out)
 
 
def _bracket_penalty(s: str) -> int:
    bad = 0
    for op, cl in _BRACKET_PAIRS:
        depth = 0
        for c in s:
            if c == op:
                depth += 1
            elif c == cl:
                if depth == 0:
                    bad += 1
                else:
                    depth -= 1
        bad += depth
    return bad
 
 
def _rtl_to_logical(s: str) -> str:
    """یک بازهٔ کاملاً راست‌به‌چپ: برعکس‌کردن، با حفظ ترتیب اعداد/لاتین و درست‌کردن پرانتزها."""
    a = _reverse_segments(s, True)
    b = _reverse_segments(s, False)
    return a if _bracket_penalty(a) <= _bracket_penalty(b) else b
 
 
def _rtl_runs(s: str) -> list[tuple[int, int]]:
    runs: list[tuple[int, int]] = []
    n = len(s)
    i = 0
    while i < n:
        if is_rtl_letter(s[i]):
            j = i
            last = i + 1
            while j < n:
                c = s[j]
                if is_rtl_letter(c):
                    last = j + 1
                elif is_ltr_letter(c):
                    break
                j += 1
            runs.append((i, last))
            i = last
        else:
            i += 1
    return runs
 
 
def visual_to_logical(s: str, base_rtl: bool | None = None) -> str:
    """رشتهٔ «بصری» (ترتیب چپ‌به‌راست روی صفحه) را به ترتیب منطقی تبدیل می‌کند.
 
    مثال: ``"دوب 1404 لاس"`` ← ``"سال 1404 بود"``.
    """
    if not has_rtl(s):
        return s
    if base_rtl is None:
        base_rtl = is_rtl_line(s)
    if base_rtl:
        return _rtl_to_logical(s)
    out: list[str] = []
    pos = 0
    for a, b in _rtl_runs(s):
        out.append(s[pos:a])
        out.append(_rtl_to_logical(s[a:b]))
        pos = b
    out.append(s[pos:])
    return "".join(out)
 
 
# --------------------------------------------------------------------------- #
# پاک‌سازی
# --------------------------------------------------------------------------- #
 
_PRESENTATION_RE = re.compile("[\uFB50-\uFDFF\uFE70-\uFEFF]")
_SPACE_RE = re.compile(r"[\u00a0\u1680\u2000-\u200a\u202f\u205f\u3000\t\x0b\x0c]")
_CTRL_RE = re.compile(r"[\x00-\x08\x0e-\x1f\x7f-\x9f]")
_MULTI_SPACE_RE = re.compile(r" {2,}")
 
_REMOVE = (
    [0x200B, 0x200D, 0x200E, 0x200F, 0x2060, 0x061C, 0xFEFF, 0x00AD, 0x0640]
    + list(range(0x202A, 0x202F))
    + list(range(0x2066, 0x206A))
    + list(range(0x064B, 0x0660))  # اعراب
    + [0x0670, 0x06E7, 0x06E8, 0x06EA, 0x06EB, 0x06EC, 0x06ED]
    + list(range(0x06D6, 0x06DD))
    + list(range(0x06DF, 0x06E5))
)
_TRANSLATE: dict[int, Any] = {c: None for c in _REMOVE}
_TRANSLATE.update({ord("ي"): "ی", ord("ى"): "ی", ord("ك"): "ک"})
 
 
def _nfkc(m: re.Match[str]) -> str:
    return unicodedata.normalize("NFKC", m.group())
 
 
def clean_line(s: str) -> str:
    """پاک‌سازی یک خط (بعد از اصلاح ترتیب!). ارقام و نیم‌فاصله دست‌نخورده می‌مانند؛
    نرمال‌سازی کامل بعداً در مرحلهٔ چانکینگ (``normalize_text``) انجام می‌شود."""
    s = _PRESENTATION_RE.sub(_nfkc, s)  # ﻻ → لا ، ﺳ → س
    s = _SPACE_RE.sub(" ", s)
    s = _CTRL_RE.sub("", s)
    s = s.translate(_TRANSLATE)
    s = _MULTI_SPACE_RE.sub(" ", s)
    return s.strip()
 
 
_GARBAGE_RE = re.compile(r"\ufffd|[\ue000-\uf8ff]|\(cid:\d+\)")
 
 
def garbage_ratio(text: str) -> float:
    if not text:
        return 0.0
    bad = sum(len(m.group()) for m in _GARBAGE_RE.finditer(text))
    return bad / len(text)
 
 
def detect_language(text: str) -> dict[str, Any]:
    rtl = ltr = persian_only = 0
    for c in text:
        if is_rtl_letter(c):
            rtl += 1
            if c in "پچژگکی":
                persian_only += 1
        elif is_ltr_letter(c):
            ltr += 1
    total = rtl + ltr
    if total == 0:
        return {"primary": "unknown", "rtl_ratio": 0.0, "latin_ratio": 0.0}
    rtl_ratio = rtl / total
    if rtl_ratio >= 0.85:
        primary = "fa" if persian_only / max(rtl, 1) > 0.05 else "ar"
    elif rtl_ratio <= 0.1:
        primary = "en"
    else:
        primary = "mixed"
    return {"primary": primary, "rtl_ratio": round(rtl_ratio, 3), "latin_ratio": round(ltr / total, 3)}
 
 
# --------------------------------------------------------------------------- #
# تشخیص متن برعکس (سطح سند؛ برای txt/docx و شبکهٔ اطمینان pdf)
# --------------------------------------------------------------------------- #
 
_FA_WORDS = frozenset(
    "و در به از که این را با است برای آن یک تا هم بر یا اما نیز هر هیچ او ما شما آنها آنان ایشان خود بین پس "
    "چه چون اگر همه بیشتر کمتر کار بعد قبل روی زیر مورد طور دیگر مانند نظر سال شد شده شود شوند می نمی بود "
    "باشد باشند دارد دارند کرد کرده کند کنند کنید کنم کنیم داد داده دهد دهند گفت گفته شدن کردن بودن داشتن "
    "آنچه آنکه همین همان هنوز تنها حتی ولی بلکه زیرا بنابراین بنابر البته چنین چنان برخی بعضی دو سه چهار پنج "
    "همچنین همچون درباره درون بدون علیه توسط طریق نسبت درصد میلیون هزار صورت داخل خارج باید نباید توان "
    "های ترین تر".split()
)
_FA_SUFFIXES = ("ها", "های", "ان", "ات", "ین", "ند", "ید", "یم")
_TOKEN_SPLIT_RE = re.compile(r"[\s\u200c.,;:!?()\[\]{}«»\"'،؛؟…\-–—/\\|0-9۰-۹٠-٩]+")
 
 
def _persian_score(lines: Sequence[str]) -> float:
    score = 0.0
    for line in lines:
        for tok in _TOKEN_SPLIT_RE.split(clean_line(line)):
            if len(tok) < 2 or not is_rtl_letter(tok[0]):
                continue
            if tok in _FA_WORDS:
                score += 1.0
            elif len(tok) >= 4 and tok.endswith(_FA_SUFFIXES):
                score += 0.4
    return score
 
 
def analyze_order(lines: Sequence[str], strict: bool = False) -> dict[str, Any]:
    """بررسی می‌کند متن «برعکس» است یا نه (مقایسهٔ امتیاز واژه‌های پرتکرار فارسی در دو جهت)."""
    rtl_lines = [ln for ln in lines if has_rtl(ln)]
    report: dict[str, Any] = {
        "checked": False, "reversed_detected": False, "fixed": False,
        "confidence": 0.0, "forward_score": 0.0, "reversed_score": 0.0,
    }
    if not rtl_lines:
        return report
    fwd = _persian_score(rtl_lines)
    rev = _persian_score([visual_to_logical(ln) for ln in rtl_lines])
    report.update(checked=True, forward_score=round(fwd, 1), reversed_score=round(rev, 1))
    if fwd + rev < 5:  # شواهد کافی نیست؛ دست نزن
        return report
    ratio = 2.5 if strict else 1.6
    if rev > fwd * ratio and rev - fwd >= 4:
        report.update(reversed_detected=True, fixed=True, confidence=round((rev - fwd) / (rev + fwd), 3))
    else:
        report["confidence"] = round(max(fwd - rev, 0.0) / (rev + fwd), 3)
    return report
 
 
def fix_order_lines(lines: Sequence[str], strict: bool = False) -> tuple[list[str], dict[str, Any]]:
    report = analyze_order(lines, strict)
    if report["fixed"]:
        return [visual_to_logical(ln) for ln in lines], report
    return list(lines), report
 
 
# --------------------------------------------------------------------------- #
# ساختار خروجی
# --------------------------------------------------------------------------- #
 
 
@dataclass
class Block:
    """یک واحد پاراگراف‌مانند. ``type``: paragraph | heading | list_item | table | footnote."""
 
    type: str = "paragraph"
    lines: list[str] = field(default_factory=list)
    level: int | None = None
    page: int | None = None
    page_end: int | None = None
    join: str = " "  # ترکیب خط‌ها در text: " " (بازچینی خط‌های شکسته) یا "\n"
    meta: dict[str, Any] = field(default_factory=dict)
 
 
def join_lines(lines: Sequence[str], sep: str = " ") -> str:
    if not lines:
        return ""
    if sep != " ":
        return sep.join(lines)
    out = lines[0]
    for nxt in lines[1:]:
        if (
            len(out) > 1 and out.endswith("-") and out[-2].isascii() and out[-2].isalpha()
            and nxt[:1].isascii() and nxt[:1].islower()
        ):
            out = out[:-1] + nxt  # word-\nbreak → wordbreak (فقط لاتین)
        else:
            out += " " + nxt
    return out
 
 
def finalize_blocks(blocks: list[Block], strict: bool = False) -> dict[str, Any]:
    """اصلاح ترتیب (در صورت نیاز) ← پاک‌سازی ← حذف خط/بلوک‌های خالی. روی ``blocks`` درجا کار می‌کند."""
    flat = [ln for b in blocks for ln in b.lines]
    _, report = fix_order_lines(flat, strict)
    for b in blocks:
        new = []
        for ln in b.lines:
            if report["fixed"]:
                ln = visual_to_logical(ln)
            ln = clean_line(ln)
            if ln:
                new.append(ln)
        b.lines = new
    blocks[:] = [b for b in blocks if b.lines]
    return report
 
 
def file_info(path: Path) -> dict[str, Any]:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return {
        "filename": path.name,
        "extension": path.suffix.lower().lstrip("."),
        "size_bytes": path.stat().st_size,
        "sha256": h.hexdigest(),
    }
 
 
def check_input_file(path: str | Path, exts: Sequence[str]) -> Path:
    p = Path(path)
    if not p.is_file():
        raise ExtractionError("file_not_found", f"File not found: {p.name}")
    if p.suffix.lower() not in exts:
        raise ExtractionError("unsupported_extension", f"Unsupported file type '{p.suffix}'. Allowed: {', '.join(exts)}")
    if p.stat().st_size == 0:
        raise ExtractionError("empty_file", "The file is empty.")
    return p
 
 
def build_result(
    blocks: list[Block],
    *,
    path: Path,
    extractor: str,
    library: str | None = None,
    pages: int | None = None,
    warnings: list[dict[str, Any]] | None = None,
    strict_order: bool = False,
    extra: dict[str, Any] | None = None,
) -> dict[str, Any]:
    order = finalize_blocks(blocks, strict_order)
    warnings = list(warnings or [])
    if order["fixed"]:
        warnings.append({
            "code": "reversed_text_fixed",
            "message": "Text looked reversed (visual order) and was converted to logical order.",
        })
 
    paragraphs: list[dict[str, Any]] = []
    lines_out: list[dict[str, Any]] = []
    md_parts: list[str] = []
    text_parts: list[str] = []
    for b in blocks:
        text = join_lines(b.lines, b.join)
        start = len(lines_out)
        pidx = len(paragraphs)
        for ln in b.lines:
            item: dict[str, Any] = {"index": len(lines_out), "paragraph": pidx, "text": ln}
            if b.page is not None:
                item["page"] = b.page
            lines_out.append(item)
        para: dict[str, Any] = {"index": pidx, "type": b.type}
        if b.type == "heading":
            para["level"] = b.level or 1
        if b.page is not None:
            para["page"] = b.page
            if b.page_end and b.page_end != b.page:
                para["page_end"] = b.page_end
        para["text"] = text
        para["line_range"] = [start, len(lines_out) - 1]
        para.update(b.meta)
        paragraphs.append(para)
 
        text_parts.append(text)
        if b.type == "heading":
            md_parts.append("#" * min(max(b.level or 1, 1), 6) + " " + text)
        elif b.type == "list_item" and not b.meta.get("ordered"):
            md_parts.append("- " + text)
        elif b.type == "table":
            md_parts.append("\n".join(b.lines))
        else:
            md_parts.append(text)
 
    full_text = "\n\n".join(text_parts)
    markdown = "\n\n".join(md_parts)
    if not blocks:
        warnings.append({"code": "no_text_found", "message": "No extractable text was found in the file."})
 
    stats: dict[str, Any] = {
        "paragraphs": len(paragraphs),
        "lines": len(lines_out),
        "words": len(full_text.split()),
        "characters": len(full_text),
    }
    if pages is not None:
        stats["pages"] = pages
 
    result: dict[str, Any] = {
        "schema_version": SCHEMA_VERSION,
        "source": file_info(path),
        "extractor": {"name": extractor, "library": library},
        "language": detect_language(full_text),
        "text_order": order,
        "stats": stats,
        "warnings": warnings,
        "paragraphs": paragraphs,
        "lines": lines_out,
        "full_text": full_text,
        "markdown": markdown,
    }
    if extra:
        result.update(extra)
    return result
 
 
# --------------------------------------------------------------------------- #
# CLI مشترک
# --------------------------------------------------------------------------- #
 
 
def median(values: Sequence[float], default: float = 0.0) -> float:
    return statistics.median(values) if values else default
 
 
def run_cli(
    extract: Callable[..., dict[str, Any]],
    *,
    description: str,
    exts: Sequence[str],
    add_args: Callable[[argparse.ArgumentParser], None] | None = None,
    kwargs_from_args: Callable[[argparse.Namespace], dict[str, Any]] | None = None,
    argv: Sequence[str] | None = None,
) -> int:
    ap = argparse.ArgumentParser(description=description)
    ap.add_argument("inputs", nargs="+", help=f"فایل(های) ورودی ({', '.join(exts)})")
    ap.add_argument("-o", "--output", help="فایل JSON خروجی (برای چند ورودی: پوشه). پیش‌فرض: کنار فایل ورودی")
    ap.add_argument("--compact", action="store_true", help="JSON فشرده (بدون indent)")
    ap.add_argument("--txt", action="store_true", help="کنار JSON یک فایل .md هم بنویس (برای دیدن متن در VS Code/Notepad)")
    if add_args:
        add_args(ap)
    args = ap.parse_args(argv)
    kwargs = kwargs_from_args(args) if kwargs_from_args else {}
 
    try:  # ویندوز: خطای encoding در چاپ نام فارسی نیاید
        sys.stdout.reconfigure(errors="replace")  # type: ignore[attr-defined]
    except Exception:  # noqa: BLE001
        pass
 
    multi = len(args.inputs) > 1
    code = 0
    for item in args.inputs:
        src = Path(item)
        try:
            result = extract(src, **kwargs)
        except ExtractionError as exc:
            print(f"[FAIL] {src.name}: {exc.code}: {exc.message}")
            code = 1
            continue
        if args.output and not multi and not Path(args.output).is_dir():
            out = Path(args.output)
        else:
            folder = Path(args.output) if args.output else src.parent
            folder.mkdir(parents=True, exist_ok=True)
            out = folder / f"{src.stem}.{src.suffix.lstrip('.').lower()}.json"
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(
            json.dumps(result, ensure_ascii=False, indent=None if args.compact else 2),
            encoding="utf-8",
        )
        if args.txt:
            out.with_suffix(".md").write_text(result["markdown"], encoding="utf-8")
        st = result["stats"]
        print(
            f"[OK] {src.name} -> {out}\n"
            f"     paragraphs={st['paragraphs']} lines={st['lines']} words={st['words']} "
            f"lang={result['language']['primary']} order_fixed={result['text_order']['fixed']}"
        )
        for w in result["warnings"]:
            print(f"     warning: {w['code']}")
    return code
 