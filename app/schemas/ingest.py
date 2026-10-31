"""Request/response models of the ingestion queue (accept now, finish later, call back)."""

from __future__ import annotations

from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator

RecordId = Annotated[
    str,
    StringConstraints(strip_whitespace=True, pattern=r"^[A-Za-z0-9_]{1,40}:[A-Za-z0-9_.\-]{1,64}$"),
]


class CallbackIn(BaseModel):
    """The `callback` object the plugin puts in every request (Apis\\Callback::descriptor())."""

    model_config = ConfigDict(extra="ignore")

    api_version: int = 1
    url: str = Field(min_length=8, max_length=2048)  # where to POST the results
    ping_url: str | None = Field(None, max_length=2048)  # health check (GET)
    site_url: str | None = Field(None, max_length=2048)
    signature: str = "hmac-sha256"
    max_results: int = Field(500, ge=1, le=500)  # results per callback request the site accepts

    @field_validator("signature")
    @classmethod
    def _known_signature(cls, v: str) -> str:
        if v.lower() != "hmac-sha256":
            raise ValueError("only hmac-sha256 callbacks are supported")
        return v.lower()


class QueuedJob(BaseModel):
    id: str  # record id
    job_id: str
    status: Literal["queued"] = "queued"


class EnqueueResponse(BaseModel):
    """200 = "got it": validated and queued. The work happens later in the ingestion worker."""

    accepted: bool = True
    job_id: str  # the first job (the plugin's single-record request has exactly one)
    queued_ids: list[str]
    jobs: list[QueuedJob]
    superseded: int = 0  # older not-yet-started jobs of the same records that were replaced
    callback: Literal["registered", "none"]  # "registered" = the plugin will be called when done


class JobStatusRequest(BaseModel):
    ids: list[RecordId] = Field(min_length=1, max_length=500)

    @field_validator("ids")
    @classmethod
    def _dedupe(cls, v: list[str]) -> list[str]:
        return list(dict.fromkeys(v))


class JobStatusItem(BaseModel):
    id: str
    # trained|untrained|failed = final (same words as the callback); processing = queued or
    # running; unknown = no job on record (never sent, or older than the retention period).
    status: Literal["trained", "untrained", "failed", "processing", "unknown"]
    job_id: str | None = None
    rev: str | None = None
    error: str | None = None


class JobStatusResponse(BaseModel):
    results: list[JobStatusItem]
