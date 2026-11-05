"""Tables of the security layer (S-1 rate limits, S-2 cost cap)."""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import BigInteger, Boolean, DateTime, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base
from app.db.messaging_models import utcnow


class RateLimit(Base):
    """Fixed-window counters. `key` = "ip:<hash>" | "visitor:<uid>"; `window` = floor(time/60)."""

    __tablename__ = "rate_limits"

    key: Mapped[str] = mapped_column(String(120), primary_key=True)
    window: Mapped[int] = mapped_column("window", Integer, nullable=False)
    n: Mapped[int] = mapped_column(Integer, nullable=False, default=0)


class BudgetCounter(Base):
    """Money spent in one period. Integer micro-USD, increased atomically (no float)."""

    __tablename__ = "budget_counters"

    kind: Mapped[str] = mapped_column(String(8), primary_key=True)  # day | month
    period_key: Mapped[str] = mapped_column(String(10), primary_key=True)  # YYYY-MM-DD | YYYY-MM
    spent_micro_usd: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0)
    warned80: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    limited100: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )


class BudgetLimit(Base):
    """The limit the site owner set in the plugin (sent with every chat request). 0 = none."""

    __tablename__ = "budget_limits"

    kind: Mapped[str] = mapped_column(String(8), primary_key=True)  # day | month
    limit_micro_usd: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, nullable=False
    )
