"""چانکر «هم‌اندازه» برای فایل‌ها و متن‌های بزرگ.

ایده: به‌جای بسته‌بندی حریصانه (که آخرین چانک را ریز می‌کند)، کل سند به «اتم»ها (جمله / خط / ردیف جدول)
شکسته می‌شود و با برنامه‌ریزی پویا (DP) به چانک‌هایی افراز می‌شود که اندازه‌شان تا حد ممکن نزدیک
`CHUNK_TARGET_TOKENS` باشد و ترجیحاً در مرز عنوان/پاراگراف بریده شوند. ورودی ناقص/بههم‌ریختهٔ استخراج‌گر
(خط‌های شکسته‌شده وسط جمله) قبل از آن ترمیم می‌شود.

آستانه‌ها همان `CHUNK_*` در `.env` (از `ChunkConfig`) و توکن‌ها با `TokenCounter` پروژه شمرده می‌شوند.
هر چانک فایل با «فایل: عنوان» و «بخش: الف › ب» شروع می‌شود (همان قالب splitter.py).
"""

from __future__ import annotations

import hashlib
import logging
import re
from dataclasses import dataclass

from app.services.chunking.normalize import normalize_text
from app.services.chunking.splitter import SECTION_LABEL, SECTION_SEP, ChunkConfig
from app.services.chunking.tokens import TokenCounter

logger = logging.getLogger(__name__)

CHUNKER_VERSION = "doc-1"  # با تغییر الگوریتم بالا ببر ← فایل/متن‌ها دوباره چانک می‌شوند
FILE_LABEL = "فایل: "

_SENT_END = re.compile(r"[.!?؟…؛]+[»”\"')\]]*(?=\s)")
_LIST_NUM_ONLY = re.compile(r"^\(?[0-9]{1,3}[.)]$")
_BULLET_START = re.compile(r"^\s*(?:[•●▪■◦○‣∙·*\-–—]\s+|\(?[0-9]{1,3}[.)\-]\s+)")
_END_PUNCT = re.compile(r"[.!?؟…؛:»”\"')\]]\s*$")
_ZERO_WIDTH = re.compile(r"[\u200b\u200e\u200f\u202a-\u202e\u2066-\u2069\ufeff]")

# جریمهٔ بریدن قبل از اتمی با این «قدرت مرز»
_CUT_PENALTY = {3: 0.0, 2: 0.08, 1: 0.35, 0: 1.2}


# --------------------------------------------------------------------------- #
# ساختارها
# --------------------------------------------------------------------------- #


@dataclass(slots=True)
class DocBlock:
    kind: str  # heading | paragraph | list_item | table | footnote
    text: str
    level: int = 1


@dataclass(slots=True)
class BuiltChunk:
    text: str
    tokens: int
    hash: str
    section: str = ""


@dataclass(slots=True)
class _Atom:
    text: str
    tokens: int
    sep: str  # جداکننده قبل از این اتم داخل چانک
    strength: int  # قدرت مرز قبل از اتم: 3 بخش جدید، 2 بلوک جدید، 1 جمله/خط، 0 برش اجباری
    path: tuple[str, ...]


# --------------------------------------------------------------------------- #
# پاک‌سازی و ترمیم
# --------------------------------------------------------------------------- #


def _clean(text: str) -> str:
    text = _ZERO_WIDTH.sub("", normalize_text(text or ""))
    lines = [re.sub(r"[ \t]+", " ", ln).strip() for ln in text.replace("\r", "").split("\n")]
    return "\n".join(lines).strip()


def _prepare_blocks(blocks: list[DocBlock]) -> list[DocBlock]:
    """نرمال‌سازی + ادغام پاراگراف‌هایی که وسط جمله شکسته شده‌اند."""
    out: list[DocBlock] = []
    for b in blocks:
        t = _clean(b.text)
        if not t:
            continue
        kind = (
            b.kind
            if b.kind in ("heading", "paragraph", "list_item", "table", "footnote")
            else "paragraph"
        )
        cur = DocBlock(kind, t, b.level)
        prev = out[-1] if out else None
        if (
            prev is not None
            and prev.kind == "paragraph"
            and cur.kind == "paragraph"
            and len(prev.text) >= 40
            and "\n" not in prev.text
            and not _END_PUNCT.search(prev.text)
            and not _BULLET_START.match(cur.text)
        ):
            prev.text = prev.text + " " + cur.text
            continue
        out.append(cur)
    return out


# --------------------------------------------------------------------------- #
# اتم‌سازی
# --------------------------------------------------------------------------- #


def _split_sentences(line: str) -> list[str]:
    out: list[str] = []
    start = 0
    for m in _SENT_END.finditer(line):
        piece = line[start : m.end()].strip()
        if not piece or _LIST_NUM_ONLY.match(piece):  # «1.» تنها = شمارهٔ فهرست، به جملهٔ بعد بچسبد
            continue
        out.append(piece)
        start = m.end()
    tail = line[start:].strip()
    if tail:
        out.append(tail)
    return out


def _hard_split(s: str, limit: int, counter: TokenCounter) -> list[tuple[str, int]]:
    count_tokens = counter.count
    total = count_tokens(s)
    if total <= limit:
        return [(s, total)]
    parts: list[tuple[str, int]] = []
    cur: list[str] = []
    cur_t = 0

    def flush() -> None:
        nonlocal cur, cur_t
        if cur:
            txt = " ".join(cur)
            parts.append((txt, count_tokens(txt)))
        cur, cur_t = [], 0

    for w in s.split(" "):
        wt = count_tokens(w) + 1
        if wt > limit:  # کلمهٔ غول‌آسا (URL/base64)
            flush()
            for k in range(0, len(w), limit):
                piece = w[k : k + limit]
                parts.append((piece, count_tokens(piece)))
            continue
        if cur_t + wt > limit and cur:
            flush()
        cur.append(w)
        cur_t += wt
    flush()
    return parts


def _atomize(
    blocks: list[DocBlock], atom_limit: int, counter: TokenCounter
) -> tuple[list[_Atom], bool]:
    atoms: list[_Atom] = []
    stack: list[tuple[int, str]] = []
    pending = 3
    prev_kind = ""
    in_notes = False
    has_heading = False

    for b in blocks:
        if b.kind == "heading" and len(b.text) <= 200:
            lvl = max(1, min(b.level or 1, 6))
            stack = [(lv, t) for lv, t in stack if lv < lvl] + [(lvl, b.text.replace("\n", " "))]
            pending = 3
            has_heading = True
            continue

        if b.kind == "footnote":
            if not in_notes:
                in_notes = True
                pending = 3
            path: tuple[str, ...] = ("پاورقی‌ها",)
        else:
            path = tuple(t for _, t in stack)

        sep_block = "\n" if (b.kind in ("list_item", "table") and prev_kind == b.kind) else "\n\n"
        first_in_block = True
        for line in b.text.split("\n"):
            line = line.strip()
            if not line:
                continue
            sentences = [line] if b.kind == "table" else _split_sentences(line)
            for k, sent in enumerate(sentences):
                for m, (txt, tok) in enumerate(_hard_split(sent, atom_limit, counter)):
                    if first_in_block:
                        strength, sep = pending, sep_block
                        first_in_block = False
                    elif m > 0:
                        strength, sep = 0, " "
                    elif k == 0:
                        strength, sep = 1, "\n"
                    else:
                        strength, sep = 1, " "
                    atoms.append(_Atom(txt, tok, sep, strength, path))
        pending = 2
        prev_kind = b.kind
    return atoms, has_heading


# --------------------------------------------------------------------------- #
# افراز بهینه (DP)
# --------------------------------------------------------------------------- #


def _partition(
    atoms: list[_Atom], *, target: int, max_tokens: int, min_tokens: int
) -> list[tuple[int, int]]:
    n = len(atoms)
    prefix = [0] * (n + 1)
    for k, a in enumerate(atoms):
        prefix[k + 1] = prefix[k] + a.tokens
    inf = float("inf")
    best = [inf] * (n + 1)
    prev = [0] * (n + 1)
    best[0] = 0.0

    for j in range(1, n + 1):
        cut = _CUT_PENALTY[atoms[j].strength] if j < n else 0.0
        for i in range(j - 1, -1, -1):
            size = prefix[j] - prefix[i] + (j - i - 1)  # ≈ ۱ توکن برای هر جداکننده
            if size > max_tokens and i < j - 1:
                break
            dev = (size - target) / target
            cost = best[i] + dev * dev + cut + 0.05
            if size < min_tokens:
                cost += 1.5 * (min_tokens - size) / min_tokens
            if cost < best[j]:
                best[j] = cost
                prev[j] = i

    spans: list[tuple[int, int]] = []
    j = n
    while j > 0:
        i = prev[j]
        spans.append((i, j))
        j = i
    spans.reverse()
    return spans


# --------------------------------------------------------------------------- #
# مونتاژ
# --------------------------------------------------------------------------- #


def _join(atoms: list[_Atom]) -> str:
    parts: list[str] = []
    for k, a in enumerate(atoms):
        if k:
            parts.append(a.sep)
        parts.append(a.text)
    return "".join(parts)


def _path_text(path: tuple[str, ...]) -> str:
    s = SECTION_SEP.join(p[:80] for p in path[-4:])
    return s[:160]


def _compose(title: str, path: tuple[str, ...], body: str) -> str:
    head: list[str] = []
    if title:
        head.append(f"{FILE_LABEL}{title}")
    if path:
        head.append(SECTION_LABEL + _path_text(path))
    return ("\n".join(head) + "\n\n" + body) if head else body


def _assemble(
    atoms: list[_Atom],
    spans: list[tuple[int, int]],
    *,
    title: str,
    overlap_tokens: int,
    max_tokens: int,
    counter: TokenCounter,
) -> list[BuiltChunk]:
    count_tokens = counter.count
    out: list[BuiltChunk] = []
    for idx, (i, j) in enumerate(spans):
        body_atoms = atoms[i:j]
        path = atoms[i].path
        overlap: list[_Atom] = []
        if idx > 0 and overlap_tokens > 0 and atoms[i].strength < 3:
            pi, pj = spans[idx - 1]
            used = 0
            for a in reversed(atoms[pi:pj]):
                if a.path != path or used + a.tokens > overlap_tokens:
                    break
                overlap.insert(0, a)
                used += a.tokens

        text = _compose(title, path, _join(overlap + body_atoms))
        tok = count_tokens(text)
        if tok > max_tokens and overlap:
            text = _compose(title, path, _join(body_atoms))
            tok = count_tokens(text)
        p = path
        while tok > max_tokens and p:  # آخرین چاره: مسیر بخش را کوتاه کن
            p = p[1:]
            text = _compose(title, p, _join(body_atoms))
            tok = count_tokens(text)
        out.append(
            BuiltChunk(
                text=text,
                tokens=tok,
                hash=hashlib.sha256(text.encode("utf-8")).hexdigest(),
                section=_path_text(path),
            )
        )
    return out


def _log_stats(kind: str, chunks: list[BuiltChunk], counter: TokenCounter) -> None:
    if not chunks:
        return
    toks = [c.tokens for c in chunks]
    logger.info(
        "chunker.%s chunks=%d tokens_min=%d tokens_avg=%d tokens_max=%d total=%d exact=%s",
        kind,
        len(chunks),
        min(toks),
        sum(toks) // len(toks),
        max(toks),
        sum(toks),
        counter.exact,
    )


# --------------------------------------------------------------------------- #
# API عمومی
# --------------------------------------------------------------------------- #


def chunk_document(
    blocks: list[DocBlock], *, title: str, counter: TokenCounter, cfg: ChunkConfig
) -> list[BuiltChunk]:
    """بلوک‌های خروجی استخراج‌گر ← چانک‌های هم‌اندازه (با سطر زمینه و مسیر بخش)."""
    title = " ".join((title or "").split())[:150]
    prepared = _prepare_blocks(blocks)
    if not prepared:
        return []

    header_tokens = counter.count(f"{FILE_LABEL}{title}\n\n") if title else 0
    has_heading = any(b.kind == "heading" for b in prepared)
    path_allow = 40 if has_heading else 0
    body_target = max(cfg.target_tokens - header_tokens - path_allow, cfg.target_tokens // 2)
    body_max = max(
        cfg.max_tokens - header_tokens - path_allow - cfg.overlap_tokens, body_target + 40
    )
    body_min = max(30, cfg.min_tokens - header_tokens - path_allow)

    atoms, _ = _atomize(prepared, body_target, counter)
    if not atoms:
        return []
    spans = _partition(atoms, target=body_target, max_tokens=body_max, min_tokens=body_min)
    chunks = _assemble(
        atoms, spans, title=title, overlap_tokens=cfg.overlap_tokens,
        max_tokens=cfg.max_tokens, counter=counter,
    )  # fmt: skip
    _log_stats("document", chunks, counter)
    return chunks


def rechunk_text_chunks(
    chunks: list[str], *, counter: TokenCounter, cfg: ChunkConfig
) -> list[BuiltChunk]:
    """چانک‌های آمادهٔ افزونه (متن‌های دستی): کوچک‌ها همان‌طور می‌مانند، بزرگ‌ها (> CHUNK_MAX_TOKENS) به
    چند چانک هم‌اندازه شکسته می‌شوند (با همان الگوریتم و overlap)."""
    out: list[BuiltChunk] = []
    for raw in chunks:
        text = _clean(raw)
        if not text:
            continue
        tok = counter.count(text)
        if tok <= cfg.max_tokens:
            out.append(BuiltChunk(text, tok, hashlib.sha256(text.encode("utf-8")).hexdigest()))
            continue
        paras = [DocBlock("paragraph", p) for p in re.split(r"\n\s*\n", text) if p.strip()]
        atoms, _ = _atomize(paras, cfg.target_tokens, counter)
        spans = _partition(
            atoms,
            target=cfg.target_tokens,
            max_tokens=max(cfg.max_tokens - cfg.overlap_tokens, cfg.target_tokens + 40),
            min_tokens=cfg.min_tokens,
        )
        out.extend(
            _assemble(
                atoms,
                spans,
                title="",
                overlap_tokens=cfg.overlap_tokens,
                max_tokens=cfg.max_tokens,
                counter=counter,
            )  # fmt: skip
        )
    _log_stats("text", out, counter)
    return out
