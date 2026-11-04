"""Logging: console + rotating files, optional JSON, request-id in every line."""

from __future__ import annotations

import json
import logging
import logging.config
import sys
from contextvars import ContextVar
from datetime import datetime, timezone

from app.core.config import Settings
from app.core.monitoring import init_monitoring, set_request_id_getter

request_id_var: ContextVar[str] = ContextVar("request_id", default="-")

_STD_ATTRS = set(logging.makeLogRecord({}).__dict__) | {"message", "asctime", "request_id"}


class RequestIdFilter(logging.Filter):
    def filter(self, record: logging.LogRecord) -> bool:
        record.request_id = request_id_var.get()
        return True


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload = {
            "ts": datetime.fromtimestamp(record.created, tz=timezone.utc).isoformat(
                timespec="milliseconds"
            ),
            "level": record.levelname,
            "logger": record.name,
            "request_id": getattr(record, "request_id", "-"),
            "message": record.getMessage(),
        }
        payload.update({k: v for k, v in record.__dict__.items() if k not in _STD_ATTRS})
        if record.exc_info:
            payload["exc"] = self.formatException(record.exc_info)
        return json.dumps(payload, ensure_ascii=False, default=str)


def _force_utf8_streams() -> None:
    """Windows consoles often default to a legacy code page; Persian text would break logging."""
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure:
            try:
                reconfigure(encoding="utf-8", errors="replace")
            except Exception:  # noqa: BLE001 - best effort only
                pass


def setup_logging(settings: Settings, service: str = "api") -> None:
    """Text/JSON logs + the JSONL monitoring of this process (`service`: api | ingestion |
    delivery | summarizer; it becomes part of the monitoring file name)."""
    _force_utf8_streams()
    formatter = "json" if settings.LOG_JSON else "text"

    handlers: dict[str, dict] = {
        "console": {
            "class": "logging.StreamHandler",
            "stream": "ext://sys.stdout",
            "formatter": formatter,
            "filters": ["request_id"],
        }
    }
    if settings.LOG_TO_FILE:
        settings.log_dir.mkdir(parents=True, exist_ok=True)
        max_bytes = settings.LOG_MAX_MB * 1024 * 1024
        handlers["file"] = {
            "class": "logging.handlers.RotatingFileHandler",
            "filename": str(settings.log_dir / "app.log"),
            "maxBytes": max_bytes,
            "backupCount": settings.LOG_BACKUP_COUNT,
            "encoding": "utf-8",
            "delay": True,
            "formatter": formatter,
            "filters": ["request_id"],
        }
        handlers["error_file"] = {
            "class": "logging.handlers.RotatingFileHandler",
            "filename": str(settings.log_dir / "error.log"),
            "maxBytes": max_bytes,
            "backupCount": settings.LOG_BACKUP_COUNT,
            "encoding": "utf-8",
            "delay": True,
            "level": "ERROR",
            "formatter": formatter,
            "filters": ["request_id"],
        }

    logging.config.dictConfig(
        {
            "version": 1,
            "disable_existing_loggers": False,
            "filters": {"request_id": {"()": RequestIdFilter}},
            "formatters": {
                "text": {
                    "format": (
                        "%(asctime)s | %(levelname)-8s | %(request_id)s | "
                        "%(name)s | %(message)s"
                    ),
                    "datefmt": "%Y-%m-%d %H:%M:%S",
                },
                "json": {"()": JsonFormatter},
            },
            "handlers": handlers,
            "root": {"level": settings.LOG_LEVEL, "handlers": list(handlers)},
            "loggers": {
                # uvicorn: let our root handlers print its messages; we write our own access log.
                "uvicorn": {"handlers": [], "level": "INFO", "propagate": True},
                "uvicorn.error": {"handlers": [], "level": "INFO", "propagate": True},
                "uvicorn.access": {"handlers": [], "level": "WARNING", "propagate": False},
                "httpx": {"level": "WARNING"},
                "httpcore": {"level": "WARNING"},
            },
        }
    )
    set_request_id_getter(request_id_var.get)
    init_monitoring(settings, service)
