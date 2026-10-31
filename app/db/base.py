"""Async SQLAlchemy engine/session management (SQLite by default, PostgreSQL-ready)."""

from __future__ import annotations

import json
import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from sqlalchemy import event
from sqlalchemy.ext.asyncio import (
    AsyncEngine,
    AsyncSession,
    async_sessionmaker,
    create_async_engine,
)
from sqlalchemy.orm import DeclarativeBase

from app.core.config import Settings

logger = logging.getLogger(__name__)


class Base(DeclarativeBase):
    pass


def _json_dumps(obj: object) -> str:
    return json.dumps(obj, ensure_ascii=False)  # keep Persian readable (and 6x smaller)


class Database:
    def __init__(self, url: str):
        self.url = url
        self.engine: AsyncEngine = create_async_engine(url, json_serializer=_json_dumps)
        self._sessions = async_sessionmaker(self.engine, expire_on_commit=False)
        if self.engine.dialect.name == "sqlite":
            event.listen(self.engine.sync_engine, "connect", _sqlite_pragmas)

    @classmethod
    def from_settings(cls, settings: Settings) -> Database:
        settings.data_dir.mkdir(parents=True, exist_ok=True)
        return cls(settings.database_url)

    async def init(self) -> None:
        """Create missing tables (Alembic migrations arrive with the conversations DB step)."""
        from app.db import models  # noqa: F401  (registers the tables on Base.metadata)

        async with self.engine.begin() as conn:
            await conn.run_sync(Base.metadata.create_all)
        logger.info("Database ready: dialect=%s", self.engine.dialect.name)

    @asynccontextmanager
    async def session(self) -> AsyncIterator[AsyncSession]:
        async with self._sessions() as session:
            yield session

    async def dispose(self) -> None:
        await self.engine.dispose()


def _sqlite_pragmas(dbapi_conn, _record) -> None:
    cursor = dbapi_conn.cursor()
    cursor.execute("PRAGMA foreign_keys=ON")
    cursor.execute("PRAGMA journal_mode=WAL")
    cursor.execute("PRAGMA busy_timeout=5000")
    cursor.close()
