"""Structure-aware, token-budgeted text splitter.

Input is a `Group` (one logical text: the main body of a record, or its reviews).
Algorithm:
  1. Parse the text into units = paragraphs, remembering the markdown-heading path they live
     under. A paragraph larger than the unit limit is cut by lines, then sentences, then words.
  2. If the whole group fits in CHUNK_MAX_TOKENS it stays ONE chunk (small pages stay whole).
  3. Otherwise units are packed greedily up to CHUNK_TARGET_TOKENS:
       - a new heading section starts a new chunk once the current one is at least half full;
       - a chunk below CHUNK_MIN_TOKENS may grow up to CHUNK_MAX_TOKENS instead of being
         flushed tiny; a tiny tail is merged back into the previous chunk when it fits;
       - when a chunk is cut in the middle of a section, the last sentence(s) (up to
         CHUNK_OVERLAP_TOKENS) are repeated at the start of the next chunk.
  4. Every chunk is prefixed with the group's context line (e.g. "محصول: آلوئه آریستاتا") and a
     "بخش: A › B" line, so each chunk is understandable on its own.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

from app.services.chunking.tokens import TokenCounter

_HEADING = re.compile(r"^ {0,3}(#{1,6})[ \t]+(.+?)[ \t#]*$")
_SENTENCE_END = re.compile(r"(?<=[.!?؟…؛])\s+")
SECTION_LABEL = "بخش: "
SECTION_SEP = " › "


@dataclass(frozen=True)
class ChunkConfig:
    target_tokens: int = 400
    max_tokens: int = 600
    overlap_tokens: int = 60
    min_tokens: int = 80


@dataclass
class Group:
    kind: str  # "main" | "reviews" (stored in the Qdrant payload)
    context: str  # one line repeated at the top of every chunk
    text: str  # normalized body; markdown headings (#..######) define sections
    overlap: bool = True  # repeat trailing sentences between consecutive chunks


@dataclass
class RawChunk:
    kind: str
    text: str
    tokens: int


@dataclass
class _Unit:
    text: str
    tokens: int
    path: tuple[str, ...]
    sep: str  # separator placed before this unit when it is not first in its chunk
    starts_section: bool = False
    overlap: bool = False


# ---------------------------------------------------------------- unit parsing
def _sentences(text: str) -> list[str]:
    return [p for p in _SENTENCE_END.split(text) if p.strip()]


def _fit(text: str, limit: int, counter: TokenCounter) -> list[tuple[str, str]]:
    """Cut `text` into pieces of <= limit tokens: [(piece, separator_before_piece)]."""
    if counter.count(text) <= limit:
        return [(text, "")]
    levels = (("\n", lambda t: [p for p in t.split("\n") if p.strip()]), (" ", _sentences),
              (" ", lambda t: t.split()))  # fmt: skip
    for sep, splitter in levels:
        parts = splitter(text)
        if len(parts) > 1:
            return _pack_parts(parts, sep, limit, counter)
    return [(p, "") for p in counter.hard_split(text, limit)]


def _pack_parts(
    parts: list[str], sep: str, limit: int, counter: TokenCounter
) -> list[tuple[str, str]]:
    out: list[tuple[str, str]] = []
    cur: list[str] = []
    cur_t = 0

    def flush() -> None:
        nonlocal cur, cur_t
        if cur:
            out.append((sep.join(cur), sep if out else ""))
            cur, cur_t = [], 0

    for part in parts:
        pt = counter.count(part)
        if pt > limit:
            flush()
            for i, (piece, inner_sep) in enumerate(_fit(part, limit, counter)):
                out.append((piece, sep if (i == 0 and out) else inner_sep))
            continue
        if cur and cur_t + pt + 1 > limit:
            flush()
        cur.append(part)
        cur_t += pt + 1
    flush()
    return out


def _parse_units(text: str, counter: TokenCounter, limit: int) -> list[_Unit]:
    units: list[_Unit] = []
    stack: list[tuple[int, str]] = []
    para: list[str] = []
    new_section = False

    def flush_para() -> None:
        nonlocal new_section
        body = "\n".join(para).strip()
        para.clear()
        if not body:
            return
        path = tuple(title for _, title in stack)
        for i, (piece, sep) in enumerate(_fit(body, limit, counter)):
            units.append(
                _Unit(
                    text=piece,
                    tokens=counter.count(piece),
                    path=path,
                    sep="\n\n" if i == 0 else sep,
                    starts_section=new_section and i == 0,
                )
            )
        new_section = False

    for line in text.split("\n"):
        m = _HEADING.match(line)
        if m:
            flush_para()
            level, title = len(m.group(1)), m.group(2).strip()
            while stack and stack[-1][0] >= level:
                stack.pop()
            stack.append((level, title))
            new_section = True
        elif not line.strip():
            flush_para()
        else:
            para.append(line)
    flush_para()
    return units


# ---------------------------------------------------------------- assembling
def _section_line(path: tuple[str, ...]) -> str:
    return SECTION_LABEL + SECTION_SEP.join(path)


def _assemble(context: str, units: list[_Unit]) -> str:
    body = ""
    last_path: tuple[str, ...] | None = None
    for u in units:
        if u.path and u.path != last_path:
            body += ("\n\n" if body else "") + _section_line(u.path) + "\n" + u.text
        else:
            body += (u.sep if body else "") + u.text
        last_path = u.path
    return f"{context}\n\n{body}" if context else body


def _tail(text: str, overlap_tokens: int, counter: TokenCounter) -> str:
    """The last sentence(s) of `text` that fit in `overlap_tokens` ('' if none fits)."""
    pieces: list[str] = []
    for line in text.split("\n"):
        pieces.extend(_sentences(line))
    taken: list[str] = []
    total = 0
    for piece in reversed(pieces):
        t = counter.count(piece)
        if total + t > overlap_tokens:
            break
        taken.append(piece)
        total += t
    return " ".join(reversed(taken))


# ---------------------------------------------------------------- main entry
def split_group(group: Group, counter: TokenCounter, cfg: ChunkConfig) -> list[RawChunk]:
    context = group.context.strip()
    ctx_tokens = counter.count(context) + 2
    budget_target = max(cfg.target_tokens - ctx_tokens, 60)
    budget_max = max(cfg.max_tokens - ctx_tokens, 100)
    unit_limit = max(budget_target - 30, 40)  # headroom for the "بخش:" line

    units = _parse_units(group.text, counter, unit_limit)
    if not units:
        return [RawChunk(group.kind, context, counter.count(context))] if context else []

    whole = _assemble(context, units)
    whole_tokens = counter.count(whole)
    if whole_tokens <= cfg.max_tokens:
        return [RawChunk(group.kind, whole, whole_tokens)]

    section_cost: dict[tuple[str, ...], int] = {}

    def cost(u: _Unit, last_path: tuple[str, ...] | None) -> int:
        c = u.tokens + 1
        if u.path and u.path != last_path:
            if u.path not in section_cost:
                section_cost[u.path] = counter.count(_section_line(u.path)) + 1
            c += section_cost[u.path]
        return c

    chunks: list[list[_Unit]] = []
    cur: list[_Unit] = []
    cur_t = 0
    last_path: tuple[str, ...] | None = None

    for u in units:
        c = cost(u, last_path)
        if cur:
            at_section_break = u.starts_section and cur_t >= budget_target * 0.5
            too_big = cur_t + c > budget_target
            may_grow = cur_t < cfg.min_tokens and cur_t + c <= budget_max
            if at_section_break or (too_big and not may_grow):
                chunks.append(cur)
                prev_last = cur[-1]
                cur, cur_t, last_path = [], 0, None
                if (
                    group.overlap
                    and cfg.overlap_tokens > 0
                    and not at_section_break
                    and not u.starts_section
                    and prev_last.path == u.path
                ):
                    tail = _tail(prev_last.text, cfg.overlap_tokens, counter)
                    if tail:
                        carry = _Unit(tail, counter.count(tail), prev_last.path, "", overlap=True)
                        carry_cost = cost(carry, None)
                        if carry_cost + cost(u, carry.path) <= budget_max:
                            cur, cur_t, last_path = [carry], carry_cost, carry.path
                c = cost(u, last_path)
        cur.append(u)
        cur_t += c
        last_path = u.path
    if cur:
        chunks.append(cur)

    # A tiny tail chunk is merged back into its predecessor when the result still fits.
    if len(chunks) >= 2:
        real = [u for u in chunks[-1] if not u.overlap]
        if sum(u.tokens for u in real) < cfg.min_tokens:
            merged = chunks[-2] + real
            if counter.count(_assemble(context, merged)) <= cfg.max_tokens:
                chunks[-2:] = [merged]

    out: list[RawChunk] = []
    for units_in_chunk in chunks:
        text = _assemble(context, units_in_chunk)
        out.append(RawChunk(group.kind, text, counter.count(text)))
    return out
