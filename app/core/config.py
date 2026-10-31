"""Application settings (single source of truth: .env)."""

from __future__ import annotations

import ipaddress
import re
from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, SecretStr, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

BASE_DIR = Path(__file__).resolve().parents[2]
_HEX = re.compile(r"[0-9a-fA-F]+")


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=BASE_DIR / ".env",
        env_file_encoding="utf-8-sig",  # tolerate a BOM added by Notepad
        extra="ignore",
        case_sensitive=True,
    )

    # --- App ---
    APP_NAME: str = "Persian AI Backend"
    APP_ENV: Literal["development", "production"] = "development"
    HOST: str = "127.0.0.1"
    PORT: int = Field(8000, ge=1, le=65535)
    RELOAD: bool = False
    WORKERS: int = Field(1, ge=1, le=32)
    FORWARDED_ALLOW_IPS: str = "127.0.0.1"
    ENABLE_DOCS: bool | None = None

    # --- Logging ---
    LOG_LEVEL: Literal["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"] = "INFO"
    LOG_JSON: bool = False
    LOG_TO_FILE: bool = True
    LOG_DIR: Path = Path("logs")
    LOG_MAX_MB: int = Field(10, ge=1)
    LOG_BACKUP_COUNT: int = Field(10, ge=1)

    # --- Auth ---
    API_SHARED_SECRET: SecretStr = Field(default=SecretStr(""), validate_default=True)
    AUTH_MAX_SKEW_SECONDS: int = Field(300, ge=30, le=3600)
    MAX_BODY_BYTES: int = Field(5_000_000, ge=1_000)
    REQUIRE_HTTPS: bool = True
    ALLOWED_IPS: str = ""

    # --- Paths ---
    DATA_DIR: Path = Path("data")
    UPLOADS_DIR: Path = Path("uploads")

    # --- Text database (records + chunks) ---
    # Empty = SQLite file at <DATA_DIR>/app.db. Any SQLAlchemy async URL works
    # (e.g. postgresql+asyncpg://user:pass@host/db).
    DATABASE_URL: str = ""

    # --- Qdrant (vector DB) ---
    # QDRANT_PATH set = embedded local mode (no server needed; data in this folder, one process
    # only, fine for development / small data). Empty = connect to a Qdrant server at QDRANT_URL.
    QDRANT_PATH: str = ""
    QDRANT_URL: str = "http://127.0.0.1:6333"  # ":memory:" = in-process (tests only)
    QDRANT_API_KEY: SecretStr = SecretStr("")
    QDRANT_COLLECTION: str = "knowledge"
    QDRANT_TIMEOUT: int = Field(30, ge=1, le=600)

    # --- Embeddings (OpenAI-compatible API) ---
    OPENAI_API_KEY: SecretStr = SecretStr("")
    OPENAI_BASE_URL: str = ""  # empty = official API; set it for a proxy / compatible gateway
    EMBEDDING_MODEL: str = "text-embedding-3-large"
    EMBEDDING_DIMENSIONS: int = Field(3072, ge=8, le=3072)
    EMBEDDING_BATCH_SIZE: int = Field(64, ge=1, le=256)
    EMBEDDING_TIMEOUT: int = Field(60, ge=5, le=600)
    EMBEDDING_MAX_RETRIES: int = Field(3, ge=0, le=10)

    # --- Knowledge files (pdf / txt / docx downloaded from the WordPress site) ---
    # Download link = FILES_BASE_URL + path (the plugin only sends the relative path).
    FILES_BASE_URL: str = "http://aiwpassistant.local/"
    FILES_MAX_MB: int = Field(100, ge=1, le=2000)  # per-file size limit
    FILES_DIR: Path | None = None  # None = <UPLOADS_DIR>/knowledge_files
    FILES_KEEP_ORIGINAL: bool = True  # False = delete the downloaded file after indexing
    FILES_CONNECT_TIMEOUT: float = Field(10.0, ge=1, le=120)
    FILES_READ_TIMEOUT: float = Field(60.0, ge=5, le=600)
    FILES_DOWNLOAD_RETRIES: int = Field(2, ge=0, le=5)
    FILES_MAX_EXTRACTED_CHARS: int = Field(5_000_000, ge=10_000)  # guard against huge texts

    # --- Chunking (token counts use tiktoken) ---
    TIKTOKEN_ENCODING: str = "cl100k_base"  # also used by text-embedding-3-large
    # tiktoken downloads its vocabulary once; it is cached here (drop the file in manually
    # if the server cannot reach openaipublic.blob.core.windows.net).
    TIKTOKEN_CACHE_DIR: Path = Path("data/tiktoken_cache")
    CHUNK_TARGET_TOKENS: int = Field(400, ge=100, le=4000)
    CHUNK_MAX_TOKENS: int = Field(600, ge=150, le=8000)
    CHUNK_OVERLAP_TOKENS: int = Field(60, ge=0, le=1000)
    CHUNK_MIN_TOKENS: int = Field(80, ge=10, le=2000)

    # ---------- validators ----------
    @field_validator("LOG_LEVEL", mode="before")
    @classmethod
    def _upper_level(cls, v):
        return v.upper() if isinstance(v, str) else v

    @field_validator("API_SHARED_SECRET")
    @classmethod
    def _check_secret(cls, v: SecretStr) -> SecretStr:
        raw = v.get_secret_value()
        if not raw:
            raise ValueError(
                "API_SHARED_SECRET is empty. Generate one with: "
                "python scripts/generate_secret.py --write"
            )
        # 32 random bytes = 64 hex chars, or 43 base64url chars.
        minimum = 64 if _HEX.fullmatch(raw) else 43
        if len(raw) < minimum:
            raise ValueError(
                f"API_SHARED_SECRET is too short ({len(raw)} chars, need >= {minimum} "
                "for 32 random bytes). Use: python scripts/generate_secret.py"
            )
        if len(set(raw)) < 8:
            raise ValueError("API_SHARED_SECRET looks low-entropy; generate a random one.")
        return v

    @field_validator("FILES_DIR", mode="before")
    @classmethod
    def _empty_files_dir(cls, v):
        return None if isinstance(v, str) and not v.strip() else v

    @field_validator("FILES_BASE_URL")
    @classmethod
    def _check_files_base_url(cls, v: str) -> str:
        v = v.strip()
        if not v.lower().startswith(("http://", "https://")):
            raise ValueError("FILES_BASE_URL must start with http:// or https://")
        return v if v.endswith("/") else v + "/"

    @field_validator("ALLOWED_IPS")
    @classmethod
    def _check_ips(cls, v: str) -> str:
        for item in (x.strip() for x in v.split(",") if x.strip()):
            try:
                ipaddress.ip_network(item, strict=False)
            except ValueError as exc:
                raise ValueError(f"Invalid IP/CIDR in ALLOWED_IPS: {item!r}") from exc
        return v

    @model_validator(mode="after")
    def _production_rules(self) -> Settings:
        if self.APP_ENV == "production":
            if not self.REQUIRE_HTTPS:
                raise ValueError("REQUIRE_HTTPS must be true when APP_ENV=production")
            if self.RELOAD:
                raise ValueError("RELOAD must be false when APP_ENV=production")
        if not (self.CHUNK_MIN_TOKENS < self.CHUNK_TARGET_TOKENS <= self.CHUNK_MAX_TOKENS):
            raise ValueError(
                "Chunk sizes must satisfy CHUNK_MIN_TOKENS < CHUNK_TARGET_TOKENS "
                "<= CHUNK_MAX_TOKENS"
            )
        if self.CHUNK_OVERLAP_TOKENS > self.CHUNK_TARGET_TOKENS // 3:
            raise ValueError("CHUNK_OVERLAP_TOKENS must be at most a third of CHUNK_TARGET_TOKENS")
        return self

    # ---------- derived ----------
    @property
    def is_production(self) -> bool:
        return self.APP_ENV == "production"

    @property
    def docs_enabled(self) -> bool:
        return self.ENABLE_DOCS if self.ENABLE_DOCS is not None else not self.is_production

    @property
    def allowed_networks(self) -> list[ipaddress.IPv4Network | ipaddress.IPv6Network]:
        items = (x.strip() for x in self.ALLOWED_IPS.split(",") if x.strip())
        return [ipaddress.ip_network(i, strict=False) for i in items]

    @staticmethod
    def _abs(p: Path) -> Path:
        return p if p.is_absolute() else BASE_DIR / p

    @property
    def log_dir(self) -> Path:
        return self._abs(self.LOG_DIR)

    @property
    def data_dir(self) -> Path:
        return self._abs(self.DATA_DIR)

    @property
    def uploads_dir(self) -> Path:
        return self._abs(self.UPLOADS_DIR)

    @property
    def files_dir(self) -> Path:
        return self._abs(self.FILES_DIR) if self.FILES_DIR else self.uploads_dir / "knowledge_files"

    @property
    def files_max_bytes(self) -> int:
        return self.FILES_MAX_MB * 1024 * 1024

    @property
    def tiktoken_cache_dir(self) -> Path:
        return self._abs(self.TIKTOKEN_CACHE_DIR)

    @property
    def qdrant_path(self) -> Path | None:
        return self._abs(Path(self.QDRANT_PATH)) if self.QDRANT_PATH else None

    @property
    def database_url(self) -> str:
        if self.DATABASE_URL:
            return self.DATABASE_URL
        return f"sqlite+aiosqlite:///{(self.data_dir / 'app.db').as_posix()}"


@lru_cache
def get_settings() -> Settings:
    return Settings()
