"""record JSON -> normalized Persian text -> token-budgeted chunks (pure CPU, no I/O)."""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from typing import Any

from app.core.errors import UnprocessableRecordError
from app.services.chunking.normalize import normalize_text
from app.services.chunking.render import render_record
from app.services.chunking.splitter import ChunkConfig, split_group
from app.services.chunking.tokens import TokenCounter

CHUNKER_VERSION = "1"  # bump when rendering/splitting logic changes -> records are re-chunked


@dataclass(frozen=True)
class PlannedChunk:
    index: int
    chunk_id: str
    kind: str
    text: str
    tokens: int
    hash: str


@dataclass
class ChunkPlan:
    record_id: str
    record_type: str
    title: str
    url: str | None
    chunks: list[PlannedChunk] = field(default_factory=list)
    text_chars: int = 0

    @property
    def total_tokens(self) -> int:
        return sum(c.tokens for c in self.chunks)

    @property
    def kinds(self) -> dict[str, int]:
        out: dict[str, int] = {}
        for c in self.chunks:
            out[c.kind] = out.get(c.kind, 0) + 1
        return out


def text_hash(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def data_hash(data: Any) -> str:
    canonical = json.dumps(data, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return text_hash(canonical)


def make_chunk_id(record_id: str, index: int) -> str:
    return f"{record_id}#{index}"


def build_plan(record_id: str, data: dict, counter: TokenCounter, cfg: ChunkConfig) -> ChunkPlan:
    rendered = render_record(record_id, data)
    plan = ChunkPlan(
        record_id=record_id,
        record_type=rendered.record_type,
        title=normalize_text(rendered.title),
        url=rendered.url,
    )
    index = 0
    for group in rendered.groups:
        group.context = normalize_text(group.context)
        group.text = normalize_text(group.text)
        plan.text_chars += len(group.text)
        for raw in split_group(group, counter, cfg):
            plan.chunks.append(
                PlannedChunk(
                    index=index,
                    chunk_id=make_chunk_id(record_id, index),
                    kind=raw.kind,
                    text=raw.text,
                    tokens=raw.tokens,
                    hash=text_hash(raw.text),
                )
            )
            index += 1
    if not plan.chunks:
        raise UnprocessableRecordError("Record produced no text chunks")
    return plan
