"""Request/response models for /v1/knowledge."""

from __future__ import annotations

import json
from typing import Annotated, Any, Literal

from pydantic import BaseModel, Field, StringConstraints, field_validator

# "product:14542", "blog:15650", "product_taxonomies:all", "site:1", "text:1730000000000"
RecordId = Annotated[
    str,
    StringConstraints(strip_whitespace=True, pattern=r"^[A-Za-z0-9_]{1,40}:[A-Za-z0-9_.\-]{1,64}$"),
]


class RecordIn(BaseModel):
    id: RecordId
    data: dict[str, Any]

    @field_validator("data", mode="before")
    @classmethod
    def _data_may_be_a_json_string(cls, v: Any) -> Any:
        if isinstance(v, str):
            try:
                return json.loads(v)
            except ValueError as exc:
                raise ValueError("data is a string but not valid JSON") from exc
        return v


class UpsertRequest(BaseModel):
    records: list[RecordIn] = Field(min_length=1, max_length=100)
    force: bool = Field(False, description="Re-chunk and re-embed even if the record is unchanged.")

    model_config = {
        "json_schema_extra": {
            "example": {
                "records": [{"id": "product:13199", "data": {"id": 13199, "type": "product"}}],
                "force": False,
            }
        }
    }


class UpsertItemResult(BaseModel):
    id: str
    status: Literal["created", "updated", "unchanged", "failed"]
    chunks: int = 0
    embedded_chunks: int = 0  # chunks sent to the embedding API now
    reused_chunks: int = 0  # unchanged chunks whose vectors were kept
    tokens: int = 0  # tiktoken count of all chunks
    embedding_tokens: int = 0  # prompt_tokens billed by the embedding API for this record
    duration_ms: int = 0
    error: str | None = None


class UpsertSummary(BaseModel):
    total: int
    created: int
    updated: int
    unchanged: int
    failed: int


class UpsertResponse(BaseModel):
    confirmed_ids: list[str]  # stored (created/updated/unchanged) - safe to mark as trained
    failed_ids: list[str]
    summary: UpsertSummary
    results: list[UpsertItemResult]


class DeleteRequest(BaseModel):
    ids: list[RecordId] = Field(min_length=1, max_length=500)

    @field_validator("ids")
    @classmethod
    def _dedupe(cls, v: list[str]) -> list[str]:
        return list(dict.fromkeys(v))


class DeleteItemResult(BaseModel):
    id: str
    status: Literal["deleted", "not_found", "failed"]
    chunks_removed: int = 0
    error: str | None = None


class DeleteResponse(BaseModel):
    confirmed_ids: list[str]  # gone from both databases (deleted or already absent)
    failed_ids: list[str]
    results: list[DeleteItemResult]


class RecordSummary(BaseModel):
    id: str
    kind: str
    type: str | None
    title: str | None
    url: str | None
    chunk_count: int
    token_count: int
    updated_at: str


class RecordListResponse(BaseModel):
    total: int
    limit: int
    offset: int
    items: list[RecordSummary]


class ChunkOut(BaseModel):
    chunk_id: str
    index: int
    tokens: int
    hash: str
    text: str


class RecordDetailResponse(BaseModel):
    record: RecordSummary
    data: dict[str, Any]  # the full raw record as stored
    chunks: list[ChunkOut]  # from the text DB
    qdrant_points: list[dict[str, Any]]  # payloads found in Qdrant (no vectors)
    in_sync: bool  # same chunk ids in text DB and Qdrant
