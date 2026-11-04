"""POST /v1/get_monitoring - the plugin's monitoring page asks for a day / month / year report.

Reads the JSONL monitoring files / roll-ups (see MONITORING.md). Light on purpose: the heavy part
(reading files) runs in a worker thread, at most 2 at a time, and results are cached for a short time.
"""

from __future__ import annotations

import asyncio
from typing import Annotated, Any, Literal

from fastapi import APIRouter, Request
from pydantic import BaseModel, Field

from app.core import monitor_view
from app.core.errors import AppError
from app.schemas.common import ErrorResponse

router = APIRouter(tags=["monitoring"])

_SLOTS = asyncio.Semaphore(2)


class GetMonitoringRequest(BaseModel):
    period: Literal["day", "month", "year"] = "day"
    # 2026-11-03 | 2026-11 | 2026 (Jalali digits when calendar = jalali). Empty = current day/month/year.
    date: Annotated[str | None, Field(max_length=10, pattern=r"^\d{4}(-\d{1,2}){0,2}$")] = None
    calendar: Literal["gregorian", "jalali"] = "gregorian"
    # day -> hour; month -> day; year -> month (default) or day
    unit: Literal["hour", "day", "month"] | None = None
    # optional cost estimate: model -> [price per 1M input tokens, price per 1M output tokens]
    prices: Annotated[dict[str, tuple[float, float]], Field(max_length=30)] | None = None


@router.post(
    "/get_monitoring",
    responses={400: {"model": ErrorResponse}, 401: {"model": ErrorResponse}},
    summary="Monitoring report (summary + series) for a day, month or year",
)
async def get_monitoring(body: GetMonitoringRequest, request: Request) -> dict[str, Any]:
    settings = request.app.state.settings
    async with _SLOTS:
        try:
            view = await asyncio.to_thread(
                monitor_view.build_view,
                settings.monitor_dir,
                period=body.period,
                text=body.date,
                calendar=body.calendar,
                unit=body.unit,
                offset_min=settings.monitor_utc_offset_minutes,
                prices=body.prices or None,
            )
        except ValueError as exc:
            raise AppError(str(exc)) from exc
    return {
        **view,
        "monitor": {
            "enabled": settings.MONITOR_ENABLED,
            "retention_days": settings.MONITOR_RETENTION_DAYS,
            "max_total_mb": settings.MONITOR_MAX_TOTAL_MB,
            "utc_offset": settings.MONITOR_UTC_OFFSET,
            "snapshot_seconds": settings.MONITOR_SNAPSHOT_SECONDS,
        },
        "server": {"app": settings.APP_NAME, "env": settings.APP_ENV},
    }
