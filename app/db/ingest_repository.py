"""The only place with SQL for the ingestion queue and the callback outbox.

Every method opens its own short session/transaction; none keeps a connection while the worker
is doing slow work (embedding, HTTP to a plugin site).
"""

from __future__ import annotations

import secrets
import uuid
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Any

from sqlalchemy import delete, func, or_, select, update
from sqlalchemy.orm import defer

from app.db.base import Database
from app.db.ingest_models import CallbackDelivery, CallbackSite, IngestJob
from app.db.messaging_models import utcnow


def aware(dt: datetime | None) -> datetime | None:
    """SQLite returns naive datetimes (stored as UTC); make them comparable."""
    if dt is not None and dt.tzinfo is None:
        return dt.replace(tzinfo=timezone.utc)
    return dt


@dataclass(slots=True)
class NewJob:
    record_id: str
    action: str  # upsert | replace | delete
    event: str | None = None
    rev: str | None = None
    force: bool = False
    payload: dict[str, Any] | None = None
    callback: dict[str, Any] | None = None
    site_key: str | None = None


@dataclass(slots=True)
class SiteUpsert:
    host: str
    callback_url: str
    ping_url: str | None
    site_url: str | None
    max_results: int


@dataclass(slots=True)
class DeliverySpec:
    site_key: str
    callback_url: str
    result_status: str  # trained | untrained | failed
    rev: str | None = None
    error: str | None = None


def new_job_id() -> str:
    return "j_" + secrets.token_hex(5)


class IngestRepository:
    def __init__(self, db: Database):
        self.db = db

    # ================================================================ API side
    async def enqueue(
        self, jobs: list[NewJob], site: SiteUpsert | None, *, initial_batch_size: int
    ) -> tuple[list[IngestJob], int]:
        """Insert jobs in ONE transaction. Older jobs of the same records that have not started
        yet are superseded (only the newest version of a record is worth processing).
        -> (inserted jobs, number of superseded jobs)"""
        now = utcnow()
        async with self.db.session() as s:
            superseded = await s.execute(
                update(IngestJob)
                .where(
                    IngestJob.record_id.in_({j.record_id for j in jobs}),
                    IngestJob.status == "pending",
                )
                .values(status="superseded", payload=None, finished_at=now, updated_at=now)
            )
            if site is not None:
                row = await s.get(CallbackSite, site.host)
                if row is None:
                    s.add(
                        CallbackSite(
                            host=site.host,
                            callback_url=site.callback_url,
                            ping_url=site.ping_url,
                            site_url=site.site_url,
                            max_results=site.max_results,
                            batch_limit=min(initial_batch_size, site.max_results),
                        )
                    )
                else:
                    row.callback_url = site.callback_url
                    row.ping_url = site.ping_url
                    row.site_url = site.site_url
                    row.max_results = site.max_results
                    row.batch_limit = min(row.batch_limit, site.max_results)
            rows = [
                IngestJob(
                    job_id=new_job_id(),
                    record_id=j.record_id,
                    action=j.action,
                    event=j.event,
                    rev=j.rev,
                    force=j.force,
                    payload=j.payload,
                    callback=j.callback,
                    site_key=j.site_key,
                    status="pending",
                    available_at=now,
                )
                for j in jobs
            ]
            s.add_all(rows)
            await s.commit()
            return rows, int(superseded.rowcount or 0)

    async def locked_site(self) -> str | None:
        """The first site that ever registered a callback (the key is locked to it)."""
        async with self.db.session() as s:
            return (
                await s.execute(
                    select(CallbackSite.host).order_by(CallbackSite.created_at, CallbackSite.host)
                )
            ).scalars().first()

    async def latest_jobs(self, record_ids: list[str]) -> dict[str, IngestJob]:
        async with self.db.session() as s:
            rows = (
                await s.execute(
                    select(IngestJob)
                    .options(defer(IngestJob.payload), defer(IngestJob.callback))
                    .where(IngestJob.record_id.in_(record_ids))
                    .order_by(IngestJob.id.desc())
                )
            ).scalars()
            latest: dict[str, IngestJob] = {}
            for row in rows:
                latest.setdefault(row.record_id, row)
            return latest

    async def queue_counts(self) -> dict[str, int]:
        async with self.db.session() as s:
            rows = (
                await s.execute(select(IngestJob.status, func.count()).group_by(IngestJob.status))
            ).all()
            return {status: n for status, n in rows}

    # ================================================================ ingestion worker side
    async def claim_next_job(self, *, stale_after_seconds: int) -> IngestJob | None:
        """Atomically take the oldest due job (pending -> processing, attempts + 1).

        A job stuck in `processing` for longer than `stale_after_seconds` (crashed worker) is made
        pending again first. A record that is being processed right now is skipped, so two jobs
        of one record never run at the same time.
        """
        now = utcnow()
        async with self.db.session() as s:
            await s.execute(
                update(IngestJob)
                .where(
                    IngestJob.status == "processing",
                    IngestJob.started_at < now - timedelta(seconds=stale_after_seconds),
                )
                .values(status="pending", available_at=now, updated_at=now)
            )
            busy = select(IngestJob.record_id).where(IngestJob.status == "processing")
            row_id = (
                await s.execute(
                    select(IngestJob.id)
                    .where(
                        IngestJob.status == "pending",
                        IngestJob.available_at <= now,
                        IngestJob.record_id.not_in(busy),
                    )
                    .order_by(IngestJob.id.asc())
                    .limit(1)
                )
            ).scalar_one_or_none()
            if row_id is None:
                await s.commit()
                return None
            claimed = await s.execute(
                update(IngestJob)
                .where(IngestJob.id == row_id, IngestJob.status == "pending")
                .values(
                    status="processing",
                    started_at=now,
                    attempts=IngestJob.attempts + 1,
                    updated_at=now,
                )
            )
            await s.commit()
            if claimed.rowcount != 1:  # another worker task took it first
                return None
            return await s.get(IngestJob, row_id, populate_existing=True)

    async def finish_job(
        self,
        job_pk: int,
        *,
        ok: bool,
        outcome: str | None,
        result: dict | None,
        error: str | None,
        delivery: DeliverySpec | None,
        job_id: str,
        record_id: str,
        batch_window_seconds: float,
    ) -> None:
        """Mark the job finished AND queue its callback in the same transaction (outbox)."""
        now = utcnow()
        async with self.db.session() as s:
            await s.execute(
                update(IngestJob)
                .where(IngestJob.id == job_pk)
                .values(
                    status="done" if ok else "failed",
                    outcome=outcome,
                    result=result,
                    error=error,
                    payload=None,  # the data is in the knowledge tables now; free the space
                    finished_at=now,
                    updated_at=now,
                )
            )
            if delivery is not None:
                s.add(
                    CallbackDelivery(
                        site_key=delivery.site_key,
                        callback_url=delivery.callback_url,
                        job_id=job_id,
                        record_id=record_id,
                        result_status=delivery.result_status,
                        rev=delivery.rev,
                        error=delivery.error,
                        available_at=now + timedelta(seconds=batch_window_seconds),
                    )
                )
            await s.commit()

    async def retry_job(self, job_pk: int, *, error: str, delay_seconds: float) -> None:
        now = utcnow()
        async with self.db.session() as s:
            await s.execute(
                update(IngestJob)
                .where(IngestJob.id == job_pk)
                .values(
                    status="pending",
                    error=error,
                    available_at=now + timedelta(seconds=delay_seconds),
                    updated_at=now,
                )
            )
            await s.commit()

    async def purge_finished(self, retention_days: int) -> tuple[int, int]:
        cutoff = utcnow() - timedelta(days=retention_days)
        async with self.db.session() as s:
            jobs = await s.execute(
                delete(IngestJob).where(
                    IngestJob.status.in_(("done", "failed", "superseded")),
                    IngestJob.finished_at < cutoff,
                )
            )
            deliveries = await s.execute(
                delete(CallbackDelivery).where(
                    CallbackDelivery.state.in_(("done", "dead")),
                    CallbackDelivery.finished_at < cutoff,
                )
            )
            await s.commit()
            return int(jobs.rowcount or 0), int(deliveries.rowcount or 0)

    # ================================================================ delivery worker side
    async def get_site(self, host: str) -> CallbackSite | None:
        async with self.db.session() as s:
            return await s.get(CallbackSite, host)

    async def update_site(self, host: str, **values: Any) -> None:
        async with self.db.session() as s:
            await s.execute(
                update(CallbackSite)
                .where(CallbackSite.host == host)
                .values(updated_at=utcnow(), **values)
            )
            await s.commit()

    async def sites_ready_to_send(self, now: datetime | None = None) -> list[CallbackSite]:
        now = now or utcnow()
        due = (
            select(CallbackDelivery.id)
            .where(
                CallbackDelivery.site_key == CallbackSite.host,
                CallbackDelivery.state == "pending",
                CallbackDelivery.available_at <= now,
            )
            .exists()
        )
        async with self.db.session() as s:
            return list(
                (
                    await s.execute(
                        select(CallbackSite).where(
                            CallbackSite.state == "healthy",
                            or_(
                                CallbackSite.next_attempt_at.is_(None),
                                CallbackSite.next_attempt_at <= now,
                            ),
                            due,
                        )
                    )
                ).scalars()
            )

    async def sites_ready_to_ping(self, now: datetime | None = None) -> list[CallbackSite]:
        """Sites we stopped sending to (circuit open / suspended / auth failed) that still have
        results waiting - only a ping is allowed until it answers again."""
        now = now or utcnow()
        waiting = (
            select(CallbackDelivery.id)
            .where(
                CallbackDelivery.site_key == CallbackSite.host,
                CallbackDelivery.state == "pending",
            )
            .exists()
        )
        async with self.db.session() as s:
            return list(
                (
                    await s.execute(
                        select(CallbackSite).where(
                            CallbackSite.state != "healthy",
                            or_(
                                CallbackSite.next_ping_at.is_(None),
                                CallbackSite.next_ping_at <= now,
                            ),
                            waiting,
                        )
                    )
                ).scalars()
            )

    async def claim_delivery_batch(
        self, site_key: str, limit: int, now: datetime | None = None
    ) -> list[CallbackDelivery]:
        """Pick the results to send next for one site.

        A batch that failed before keeps its batch id, so a retry sends exactly the same results
        under the same X-PAIA-Delivery value (the plugin then answers `duplicate`, not twice).
        """
        now = now or utcnow()
        async with self.db.session() as s:
            first = (
                await s.execute(
                    select(CallbackDelivery)
                    .where(
                        CallbackDelivery.site_key == site_key,
                        CallbackDelivery.state == "pending",
                        CallbackDelivery.available_at <= now,
                    )
                    .order_by(CallbackDelivery.id)
                    .limit(1)
                )
            ).scalar_one_or_none()
            if first is None:
                return []
            if first.batch_id:
                rows = list(
                    (
                        await s.execute(
                            select(CallbackDelivery)
                            .where(
                                CallbackDelivery.batch_id == first.batch_id,
                                CallbackDelivery.state == "pending",
                            )
                            .order_by(CallbackDelivery.id)
                        )
                    ).scalars()
                )
                return rows
            rows = list(
                (
                    await s.execute(
                        select(CallbackDelivery)
                        .where(
                            CallbackDelivery.site_key == site_key,
                            CallbackDelivery.state == "pending",
                            CallbackDelivery.batch_id.is_(None),
                            CallbackDelivery.available_at <= now,
                            CallbackDelivery.callback_url == first.callback_url,
                        )
                        .order_by(CallbackDelivery.id)
                        .limit(limit)
                    )
                ).scalars()
            )
            batch_id = str(uuid.uuid4())
            await s.execute(
                update(CallbackDelivery)
                .where(CallbackDelivery.id.in_([r.id for r in rows]))
                .values(batch_id=batch_id, updated_at=now)
            )
            await s.commit()
            for r in rows:
                r.batch_id = batch_id
            return rows

    async def mark_deliveries_done(self, ids: list[int]) -> None:
        now = utcnow()
        async with self.db.session() as s:
            await s.execute(
                update(CallbackDelivery)
                .where(CallbackDelivery.id.in_(ids))
                .values(state="done", finished_at=now, updated_at=now)
            )
            await s.commit()

    async def fail_deliveries(
        self, ids: list[int], *, error: str, max_attempts: int
    ) -> tuple[int, int]:
        """One failed send: attempts + 1; rows that reached `max_attempts` become dead.
        -> (rows still pending, rows now dead)"""
        now = utcnow()
        async with self.db.session() as s:
            await s.execute(
                update(CallbackDelivery)
                .where(CallbackDelivery.id.in_(ids))
                .values(attempts=CallbackDelivery.attempts + 1, last_error=error, updated_at=now)
            )
            dead = await s.execute(
                update(CallbackDelivery)
                .where(CallbackDelivery.id.in_(ids), CallbackDelivery.attempts >= max_attempts)
                .values(state="dead", finished_at=now)
            )
            await s.commit()
            n_dead = int(dead.rowcount or 0)
            return len(ids) - n_dead, n_dead

    async def kill_deliveries(self, ids: list[int], *, error: str) -> None:
        now = utcnow()
        async with self.db.session() as s:
            await s.execute(
                update(CallbackDelivery)
                .where(CallbackDelivery.id.in_(ids))
                .values(state="dead", last_error=error, finished_at=now, updated_at=now)
            )
            await s.commit()

    async def release_batch(self, ids: list[int]) -> None:
        """Forget the batch grouping (used when a batch was too large and is split)."""
        async with self.db.session() as s:
            await s.execute(
                update(CallbackDelivery)
                .where(CallbackDelivery.id.in_(ids))
                .values(batch_id=None, updated_at=utcnow())
            )
            await s.commit()

    async def delivery_counts(self) -> dict[str, int]:
        async with self.db.session() as s:
            rows = (
                await s.execute(
                    select(CallbackDelivery.state, func.count()).group_by(CallbackDelivery.state)
                )
            ).all()
            return {state: n for state, n in rows}
