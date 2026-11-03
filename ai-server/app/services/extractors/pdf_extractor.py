
from __future__ import annotations
 
import re
from collections import Counter
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any
 
from common import (
    Block,
    ExtractionError,
    build_result,
    check_input_file,
    garbage_ratio,
    is_ltr_letter,
    is_rtl_letter,
    median,
    run_cli,
    visual_to_logical,
)
 
EXTRACTOR_NAME = "pdf_extractor"
 
_BOLD_RE = re.compile(r"bold|black|heavy|demi|titr", re.I)
_SENTENCE_END_RE = re.compile(r"[.!?؟…:؛»”\")\]]\s*$")
_LIST_RE = re.compile(r"^\s*(?:[•●▪■◦○‣∙·*\-–—]\s+|\(?\d{1,3}[.)\-]\s+|\(?[۰-۹٠-٩]{1,3}[.)\-]\s+|\(?[A-Za-z][.)]\s+)")
_DIGIT_RE = re.compile(r"[0-9۰-۹٠-٩]+")
 
 
# --------------------------------------------------------------------------- #
# ساختارهای داخلی
# --------------------------------------------------------------------------- #
 
 
@dataclass
class _Seg:
    text: str  # متن منطقی
    x0: float
    x1: float
    nchars: int
 
 
@dataclass
class _Line:
    page: int
    top: float
    bottom: float
    baseline: float
    x0: float
    x1: float
    size: float
    bold: bool
    rtl: bool
    segs: list[_Seg] = field(default_factory=list)
    text: str = ""
    table_row: bool = False
 
    @property
    def width(self) -> float:
        return self.x1 - self.x0
 
 
@dataclass
class _Page:
    number: int
    width: float
    height: float
    lines: list[_Line] = field(default_factory=list)
    n_chars: int = 0
    n_images: int = 0
 
 
# --------------------------------------------------------------------------- #
# نویسه‌ها → خط‌ها
# --------------------------------------------------------------------------- #
 
 
def _baseline(c: dict[str, Any], page_height: float) -> float:
    size = float(c.get("size") or 10)
    m = c.get("matrix")
    if m:
        y = page_height - float(m[5])
        if abs(y - float(c["bottom"])) < size * 1.2:
            return y
    return float(c["bottom"]) - 0.22 * size
 
 
def _segment_text(chars: list[dict[str, Any]], size: float) -> str:
    """رشتهٔ بصری یک قطعه (از چپ به راست) با درج فاصله در جاهایی که فاصلهٔ هندسی بزرگ است."""
    out: list[str] = []
    prev_x1: float | None = None
    for c in chars:
        t = c["text"]
        if prev_x1 is not None and t != " " and (not out or out[-1] != " ") and c["x0"] - prev_x1 > 0.25 * size:
            out.append(" ")
        if len(t) > 1 and all(is_rtl_letter(ch) for ch in t):
            t = t[::-1]  # لیگاتور چندنویسه‌ای (مثلاً «لا») در رشتهٔ بصری باید برعکس باشد
        out.append(t)
        prev_x1 = c["x1"] if prev_x1 is None else max(prev_x1, c["x1"])
    return "".join(out)
 
 
def _build_lines(page_no: int, chars: list[dict[str, Any]], page_height: float) -> list[_Line]:
    items = [(c, _baseline(c, page_height)) for c in chars]
    items.sort(key=lambda t: t[1])
 
    groups: list[list[tuple[dict[str, Any], float]]] = []
    means: list[float] = []
    for c, b in items:
        size = float(c.get("size") or 10)
        if groups and abs(b - means[-1]) <= 0.4 * max(size, 4):
            groups[-1].append((c, b))
            means[-1] += (b - means[-1]) / len(groups[-1])
        else:
            groups.append([(c, b)])
            means.append(b)
 
    lines: list[_Line] = []
    for grp in groups:
        cs = sorted((c for c, _ in grp), key=lambda c: c["x0"])
        sizes = [float(c.get("size") or 10) for c in cs if c["text"].strip()] or [10.0]
        size = median(sizes, 10.0)
        gap_limit = max(3.0 * size, 18.0)
 
        seg_chars: list[list[dict[str, Any]]] = [[]]
        max_x1 = None
        for c in cs:
            if max_x1 is not None and c["x0"] - max_x1 > gap_limit and c["text"].strip():
                seg_chars.append([])
            seg_chars[-1].append(c)
            max_x1 = c["x1"] if max_x1 is None else max(max_x1, c["x1"])
 
        segs: list[_Seg] = []
        for sc in seg_chars:
            visual = _segment_text(sc, size).strip()
            if not visual:
                continue
            logical = visual_to_logical(visual)
            segs.append(_Seg(logical, min(c["x0"] for c in sc), max(c["x1"] for c in sc), len(logical)))
        if not segs:
            continue
 
        letters_rtl = sum(1 for s in segs for ch in s.text if is_rtl_letter(ch))
        letters_ltr = sum(1 for s in segs for ch in s.text if is_ltr_letter(ch))
        bold_chars = sum(1 for c in cs if c["text"].strip() and _BOLD_RE.search(str(c.get("fontname", ""))))
        n_vis = sum(1 for c in cs if c["text"].strip()) or 1
        lines.append(
            _Line(
                page=page_no,
                top=min(float(c["top"]) for c in cs),
                bottom=max(float(c["bottom"]) for c in cs),
                baseline=sum(b for _, b in grp) / len(grp),
                x0=min(s.x0 for s in segs),
                x1=max(s.x1 for s in segs),
                size=size,
                bold=bold_chars / n_vis >= 0.8,
                rtl=letters_rtl > 0 and letters_rtl >= letters_ltr,
                segs=segs,
            )
        )
    return lines
 
 
# --------------------------------------------------------------------------- #
# ترتیب خواندن (ستون‌ها) و جدول
# --------------------------------------------------------------------------- #
 
 
def _join_segs(line: _Line, sep: str = " ") -> str:
    ordered = sorted(line.segs, key=lambda s: s.x0, reverse=line.rtl)
    return sep.join(s.text for s in ordered)
 
 
def _order_zone(zone: list[_Line], page_w: float, rtl: bool) -> list[_Line]:
    long_per_line = [sum(1 for s in ln.segs if s.nchars >= 20) for ln in zone]
    with_long = sum(1 for n in long_per_line if n >= 1)
    multi = sum(1 for n in long_per_line if n >= 2)
    if not (len(zone) >= 8 and multi >= 4 and multi >= 0.5 * max(with_long, 1)):
        return zone
 
    centers = sorted((s.x0 + s.x1) / 2 for ln in zone for s in ln.segs if s.nchars >= 20)
    clusters: list[list[float]] = [[centers[0]]]
    for c in centers[1:]:
        if c - clusters[-1][-1] > 0.15 * page_w:
            clusters.append([c])
        else:
            clusters[-1].append(c)
    cols = [sum(cl) / len(cl) for cl in clusters]
    if len(cols) < 2:
        return zone
 
    buckets: list[list[_Line]] = [[] for _ in cols]
    for ln in zone:
        for s in ln.segs:
            mid = (s.x0 + s.x1) / 2
            k = min(range(len(cols)), key=lambda i: abs(cols[i] - mid))
            buckets[k].append(
                _Line(ln.page, ln.top, ln.bottom, ln.baseline, s.x0, s.x1, ln.size, ln.bold, ln.rtl, [s], s.text)
            )
    order = sorted(range(len(cols)), key=lambda i: cols[i], reverse=rtl)
    out: list[_Line] = []
    for k in order:
        out.extend(sorted(buckets[k], key=lambda ln: ln.baseline))
    return out
 
 
def _order_page(page: _Page, rtl: bool) -> list[_Line]:
    lines = sorted(page.lines, key=lambda ln: ln.baseline)
    ordered: list[_Line] = []
    zone: list[_Line] = []
    for ln in lines:
        if len(ln.segs) == 1 and ln.width >= 0.6 * page.width:  # عنوان تمام‌عرض: ناحیه‌ها را جدا می‌کند
            ordered.extend(_order_zone(zone, page.width, rtl))
            zone = []
            ordered.append(ln)
        else:
            zone.append(ln)
    ordered.extend(_order_zone(zone, page.width, rtl))
 
    for i, ln in enumerate(ordered):
        n = len(ln.segs)
        if n >= 2 and all(s.nchars <= 40 for s in ln.segs):
            neighbours = [ordered[j] for j in (i - 1, i + 1) if 0 <= j < len(ordered)]
            ln.table_row = any(len(o.segs) == n for o in neighbours)
        ln.text = _join_segs(ln, " | " if ln.table_row else " ") if not ln.text else ln.text
    return ordered
 
 
# --------------------------------------------------------------------------- #
# هدر/فوتر، پاراگراف، عنوان
# --------------------------------------------------------------------------- #
 
 
def _strip_headers_footers(pages: list[_Page]) -> int:
    margin_keys: Counter[str] = Counter()
    per_page: list[list[tuple[_Line, str]]] = []
    for pg in pages:
        found = []
        for ln in pg.lines:
            if ln.top < 0.08 * pg.height or ln.bottom > 0.92 * pg.height:
                key = _DIGIT_RE.sub("#", _join_segs(ln).strip())
                found.append((ln, key))
        per_page.append(found)
        for key in {k for _, k in found}:
            margin_keys[key] += 1
 
    removed = 0
    n_pages = len(pages)
    for pg, found in zip(pages, per_page):
        drop = set()
        for ln, key in found:
            repeated = n_pages >= 3 and margin_keys[key] >= max(2, 0.4 * n_pages)
            page_number = key in ("#", "-#-", "- # -") or (len(key) <= 8 and key.replace("#", "").strip(" -–/") == "")
            if repeated or page_number:
                drop.add(id(ln))
        if drop:
            removed += len(drop)
            pg.lines = [ln for ln in pg.lines if id(ln) not in drop]
    return removed
 
 
def _build_blocks(pages: list[_Page], rtl_doc: bool) -> tuple[list[Block], float]:
    sizes: Counter[float] = Counter()
    for pg in pages:
        for ln in pg.lines:
            sizes[round(ln.size * 2) / 2] += sum(s.nchars for s in ln.segs)
    body = sizes.most_common(1)[0][0] if sizes else 10.0
 
    ordered_pages = [(pg, _order_page(pg, rtl_doc)) for pg in pages]
 
    pitches = [
        b.baseline - a.baseline
        for _, ls in ordered_pages
        for a, b in zip(ls, ls[1:])
        if 0 < b.baseline - a.baseline < 3 * body and abs(a.size - body) < 0.15 * body and abs(b.size - body) < 0.15 * body
    ]
    pitch = median(pitches, 1.3 * body)
 
    blocks: list[Block] = []
    for pg, ls in ordered_pages:
        long_lines = [ln for ln in ls if sum(s.nchars for s in ln.segs) >= 30 and not ln.table_row]
        ref_w = sorted(ln.width for ln in long_lines)[int(0.9 * (len(long_lines) - 1))] if long_lines else pg.width * 0.7
        edge = median([ln.x1 if ln.rtl else ln.x0 for ln in long_lines], 0.0)
 
        cur: Block | None = None
        cur_lines: list[_Line] = []
        for ln in ls:
            prev = cur_lines[-1] if cur_lines else None
            new = cur is None or prev is None
            if not new and prev is not None and cur is not None:
                if ln.table_row != prev.table_row:
                    new = True
                elif ln.table_row:
                    new = False
                else:
                    gap = ln.baseline - prev.baseline
                    indent = ((edge - ln.x1) if ln.rtl else (ln.x0 - edge)) > 1.2 * ln.size
                    if gap < 0 or gap > pitch * 1.45:
                        new = True
                    elif abs(ln.size - prev.size) > 0.12 * max(ln.size, prev.size):
                        new = True
                    elif ln.bold != prev.bold:
                        new = True
                    elif _LIST_RE.match(ln.text):
                        new = True
                    elif _SENTENCE_END_RE.search(prev.text) and (prev.width < 0.65 * ref_w or indent):
                        new = True
            if new:
                kind = "table" if ln.table_row else ("list_item" if _LIST_RE.match(ln.text) else "paragraph")
                cur = Block(kind, [], page=pg.number, page_end=pg.number, join="\n" if ln.table_row else " ")
                if kind == "list_item" and re.match(r"^\s*\(?[\d۰-۹٠-٩A-Za-z]", ln.text):
                    cur.meta["ordered"] = True
                blocks.append(cur)
                cur_lines = []
            assert cur is not None
            cur.lines.append(ln.text)
            cur_lines.append(ln)
            cur.meta["_size"] = median([x.size for x in cur_lines], body)
            cur.meta["_bold"] = all(x.bold for x in cur_lines)
    return blocks, body
 
 
def _classify_headings(blocks: list[Block], body: float) -> None:
    candidates: list[tuple[Block, float, bool]] = []
    for b in blocks:
        size = float(b.meta.get("_size", body))
        bold = bool(b.meta.get("_bold"))
        text = " ".join(b.lines)
        if b.type != "paragraph" or len(b.lines) > 3 or len(text) > 200:
            continue
        big = size >= body * 1.18
        bold_short = bold and size >= body * 0.98 and len(b.lines) <= 2 and len(text) <= 100 and not _SENTENCE_END_RE.search(text)
        if big or bold_short:
            candidates.append((b, round(size * 2) / 2, big))
    if not candidates:
        return
    big_sizes = sorted({s for _, s, big in candidates if big}, reverse=True)
    for b, size, big in candidates:
        b.type = "heading"
        b.level = min(big_sizes.index(size) + 1, 5) if big else min(len(big_sizes) + 1, 6)
 
 
def _merge_across_pages(blocks: list[Block]) -> list[Block]:
    out: list[Block] = []
    for b in blocks:
        if (
            out and b.type == "paragraph" and out[-1].type == "paragraph"
            and b.page is not None and out[-1].page_end is not None and b.page == out[-1].page_end + 1
            and not _SENTENCE_END_RE.search(out[-1].lines[-1])
        ):
            out[-1].lines.extend(b.lines)
            out[-1].page_end = b.page_end
            continue
        out.append(b)
    return out
 
 
# --------------------------------------------------------------------------- #
# API
# --------------------------------------------------------------------------- #
 
 
def extract_pdf(
    path: str | Path,
    *,
    password: str | None = None,
    max_pages: int | None = None,
    strip_headers_footers: bool = True,
) -> dict[str, Any]:
    p = check_input_file(path, (".pdf",))
    try:
        import pdfplumber
    except ImportError as exc:  # pragma: no cover
        raise ExtractionError("service_unavailable", "pdfplumber is not installed (pip install pdfplumber).") from exc
 
    warnings: list[dict[str, Any]] = []
    pages: list[_Page] = []
    rotated = 0
    try:
        pdf = pdfplumber.open(p, password=password)
    except Exception as exc:  # noqa: BLE001
        msg = str(exc).lower() + type(exc).__name__.lower()
        if "password" in msg or "encrypt" in msg:
            raise ExtractionError("password_protected", "The PDF is password-protected.") from exc
        raise ExtractionError("corrupt_file", "The file is not a valid PDF or is damaged.") from exc
 
    with pdf:
        total_pages = len(pdf.pages)
        for idx, page in enumerate(pdf.pages, start=1):
            if max_pages is not None and idx > max_pages:
                warnings.append({"code": "page_limit", "message": f"Only the first {max_pages} pages were processed."})
                break
            try:
                raw = page.chars
                usable = []
                for c in raw:
                    if float(c.get("size") or 0) < 2:
                        continue
                    if c.get("upright") is False:
                        rotated += 1
                        continue
                    usable.append(c)
                pg = _Page(idx, float(page.width), float(page.height), n_chars=len(usable), n_images=len(page.images))
                pg.lines = _build_lines(idx, usable, float(page.height))
            except Exception as exc:  # noqa: BLE001
                warnings.append({"code": "page_failed", "message": f"Page {idx} could not be read ({type(exc).__name__}).", "page": idx})
                pg = _Page(idx, float(page.width), float(page.height))
            finally:
                page.flush_cache()
            pages.append(pg)
 
    if rotated:
        warnings.append({"code": "rotated_text_skipped", "message": f"{rotated} characters of rotated/vertical text (e.g. watermarks) were skipped."})
 
    empty = [pg.number for pg in pages if not pg.lines]
    if pages and len(empty) == len(pages):
        scanned = any(pg.n_images for pg in pages)
        warnings.append({
            "code": "no_text_layer",
            "message": "The PDF has no text layer" + (" (looks like scanned images; OCR is required)." if scanned else "."),
        })
    elif empty:
        warnings.append({"code": "pages_without_text", "message": f"{len(empty)} page(s) have no text.", "pages": empty[:50]})
 
    if strip_headers_footers and len([pg for pg in pages if pg.lines]) >= 1:
        removed = _strip_headers_footers(pages)
        if removed:
            warnings.append({"code": "headers_footers_removed", "message": f"{removed} header/footer/page-number line(s) removed."})
 
    rtl_chars = sum(1 for pg in pages for ln in pg.lines for s in ln.segs for ch in s.text if is_rtl_letter(ch))
    ltr_chars = sum(1 for pg in pages for ln in pg.lines for s in ln.segs for ch in s.text if is_ltr_letter(ch))
    rtl_doc = rtl_chars > ltr_chars
 
    blocks, body = _build_blocks(pages, rtl_doc)
    _classify_headings(blocks, body)
    blocks = _merge_across_pages(blocks)
    for b in blocks:
        b.meta.pop("_size", None)
        b.meta.pop("_bold", None)
 
    sample = " ".join(" ".join(b.lines) for b in blocks[:400])
    if garbage_ratio(sample) > 0.03:
        warnings.append({
            "code": "unreliable_text_layer",
            "message": "Many characters could not be mapped to Unicode (font without ToUnicode map). The text may be garbled; OCR is recommended.",
        })
 
    return build_result(
        blocks,
        path=p,
        extractor=EXTRACTOR_NAME,
        library=f"pdfplumber {pdfplumber.__version__}",
        pages=total_pages,
        warnings=warnings,
        strict_order=True,  # هندسه قابل‌اعتماد است؛ شبکهٔ اطمینان فقط در شواهد قوی دخالت کند
    )
 
 
def main(argv: list[str] | None = None) -> int:
    def add(ap: Any) -> None:
        ap.add_argument("--password", help="رمز PDF (اگر رمزدار است)")
        ap.add_argument("--max-pages", type=int, help="حداکثر تعداد صفحه")
        ap.add_argument("--keep-headers", action="store_true", help="هدر/فوتر و شمارهٔ صفحه حذف نشود")
 
    return run_cli(
        extract_pdf,
        description="استخراج متن از PDF → JSON (خط‌به‌خط + پاراگراف) با اصلاح ترتیب متن فارسی",
        exts=(".pdf",),
        add_args=add,
        kwargs_from_args=lambda a: {
            "password": a.password,
            "max_pages": a.max_pages,
            "strip_headers_footers": not a.keep_headers,
        },
        argv=argv,
    )
 
 
if __name__ == "__main__":
    raise SystemExit(main())
 