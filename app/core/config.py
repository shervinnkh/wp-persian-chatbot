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

# Default wording of the two tones (the real values come from TONE_FORMAL / TONE_FRIENDLY in .env;
# these are only the fallbacks and are also used when a prompt is built without Settings).
DEFAULT_TONE_FORMAL = (
    "Formal and professional. Be polite and respectful, write complete, well-formed sentences, "
    "and avoid slang, jokes and emojis. In Persian address the visitor with \u00abشما\u00bb and use "
    "the formal written register."
)
DEFAULT_TONE_FRIENDLY = (
    "Friendly and warm, like a helpful, upbeat shop assistant chatting with a customer. Sound "
    "natural and conversational with short sentences; light colloquial Persian is fine (still "
    "polite: address the visitor with \u00abشما\u00bb unless they use \u00abتو\u00bb). At most one "
    "emoji per message, and never slang or anything that could offend."
)


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

    # --- Chunking (token counts use tiktoken) ---
    TIKTOKEN_ENCODING: str = "cl100k_base"  # also used by text-embedding-3-large
    # tiktoken downloads its vocabulary once; it is cached here (drop the file in manually
    # if the server cannot reach openaipublic.blob.core.windows.net).
    TIKTOKEN_CACHE_DIR: Path = Path("data/tiktoken_cache")
    CHUNK_TARGET_TOKENS: int = Field(400, ge=100, le=4000)
    CHUNK_MAX_TOKENS: int = Field(600, ge=150, le=8000)
    CHUNK_OVERLAP_TOKENS: int = Field(60, ge=0, le=1000)
    CHUNK_MIN_TOKENS: int = Field(80, ge=10, le=2000)

    # --- Messaging (conversations + chat attachments) ---
    # WordPress site address. Chat attachments (image/voice) are downloaded ONLY from this
    # host (SSRF protection). Empty = attachments are not downloaded.
    PLUGIN_BASE_URL: str = ""
    MESSAGE_ATTACHMENT_MAX_MB: int = Field(20, ge=1, le=100)
    MESSAGE_DOWNLOAD_TIMEOUT: int = Field(30, ge=5, le=300)

    # --- Conversation history + summarization ---
    # History sent to the AI = last summary + the last HISTORY_RECENT_PAIRS (user + reply) pairs
    # in full + the newest user message. When the token count of the whole unsummarized
    # conversation exceeds SUMMARY_TRIGGER_TOKENS, a summarization request is queued (after the
    # answer was returned to the client) and the summarizer worker handles it.
    HISTORY_RECENT_PAIRS: int = Field(3, ge=1, le=20)
    SUMMARY_TRIGGER_TOKENS: int = Field(3000, ge=200, le=200000)
    SUMMARY_MODEL: str = "gpt-4.1-mini"  # any chat model of the OpenAI-compatible API
    SUMMARY_MAX_OUTPUT_TOKENS: int = Field(1200, ge=100, le=8000)
    SUMMARY_TIMEOUT: int = Field(120, ge=10, le=900)
    SUMMARY_MAX_RETRIES: int = Field(2, ge=0, le=10)  # SDK-level retries per call
    SUMMARY_MAX_ATTEMPTS: int = Field(3, ge=1, le=20)  # worker-level attempts per request
    SUMMARY_RETRY_DELAY_SECONDS: int = Field(30, ge=1, le=3600)
    SUMMARY_WORKER_POLL_SECONDS: float = Field(3.0, ge=0.2, le=300)
    # a request stuck in "processing" for longer than this (crashed worker) is retried
    SUMMARY_STALE_SECONDS: int = Field(600, ge=30, le=86400)

    # --- AI answer (model list, shared with the plugin's "AI service" select) ---
    # Comma separated model keys the plugin may ask for (`model` in POST /v1/chat/messages).
    # The FIRST one is the default: used when the client sends none or one that is not listed.
    AI_ALLOWED_MODELS: str = "gpt-5-4"

    # --- Tone of the answers. The client sends `tone` = formal | friendly per message; when it
    # sends none (or something else) DEFAULT_TONE is used. The text of the chosen tone is put
    # into the system prompt (English instructions; the answer itself is in the visitor's language).
    DEFAULT_TONE: Literal["formal", "friendly"] = "formal"
    TONE_FORMAL: str = DEFAULT_TONE_FORMAL
    TONE_FRIENDLY: str = DEFAULT_TONE_FRIENDLY

    # --- Real answer generation (RAG + LLM) ---
    # The visitor's question is embedded (EMBEDDING_MODEL), the RAG_TOP_K most similar chunks are
    # found in Qdrant and added to the system prompt. RAG_MIN_SCORE (cosine, 0 = off) drops weak
    # matches; tune it with the `rag.retrieved` log lines (they show the scores).
    RAG_TOP_K: int = Field(3, ge=1, le=10)
    RAG_MIN_SCORE: float = Field(0.0, ge=0.0, le=1.0)
    # Reasoning models count their hidden reasoning inside this limit: keep it generous.
    ANSWER_MAX_OUTPUT_TOKENS: int = Field(2000, ge=200, le=32000)
    ANSWER_LLM_TIMEOUT: int = Field(90, ge=10, le=900)
    ANSWER_LLM_MAX_RETRIES: int = Field(2, ge=0, le=10)  # SDK-level retries per call
    # json_schema = strict structured output (OpenAI) | json_object = JSON mode (many compatible
    # gateways) | none = rely on the prompt only
    ANSWER_RESPONSE_FORMAT: Literal["json_schema", "json_object", "none"] = "json_schema"
    # Optional (reasoning models only): minimal | low | medium | high. Empty = do not send.
    ANSWER_REASONING_EFFORT: str = ""
    # Maximum number of AI calls per visitor message (K). Round 1 may ask for tools (live data);
    # the LAST round never gets tools and must produce the final answer. 1 = tools are disabled.
    ANSWER_MAX_LLM_ROUNDS: int = Field(2, ge=1, le=5)

    # --- Live-data tools (backend -> WordPress site, signed like the completion callbacks) ---
    # The two endpoints live on PLUGIN_BASE_URL (never taken from the client request).
    # products: live price/stock of products (ids, or names when no id is known)
    # dynamic : text of a site-owner-defined tool, fetched by the tool id the client sent
    TOOLS_PRODUCTS_PATH: str = "/wp-json/paia/v1/live/products"
    TOOLS_DYNAMIC_PATH: str = "/wp-json/paia/v1/live/tool"
    TOOLS_TIMEOUT: float = Field(10.0, ge=1, le=60)
    TOOLS_MAX_CALLS_PER_ROUND: int = Field(4, ge=1, le=20)  # tool calls executed per AI round
    TOOLS_MAX_PRODUCTS_PER_CALL: int = Field(5, ge=1, le=20)
    TOOLS_MAX_DYNAMIC: int = Field(30, ge=1, le=100)  # dynamic tools accepted per request
    TOOLS_MAX_RESULT_CHARS: int = Field(12000, ge=500, le=100000)  # cut a longer tool result

    # --- Voice (speech-to-text of voice messages) ---
    STT_MODEL: str = "whisper-1"
    STT_LANGUAGE: str = "fa"  # ISO-639-1 hint; empty = auto-detect
    STT_TIMEOUT: int = Field(120, ge=10, le=900)

    # --- Images sent to the AI (vision) ---
    # A larger image is re-encoded (JPEG, scaled down if needed) until it fits this size.
    IMAGE_MAX_KB: int = Field(1024, ge=50, le=20480)
    IMAGE_DETAIL: Literal["auto", "low", "high"] = "auto"

    # --- Async AI answers (generate_ai_answer + callback): queued, answered by a loop INSIDE the
    # API process, delivered to the plugin by the delivery worker (callback kind "chat_answer").
    # Answer generation is network I/O only, so a high concurrency costs almost no CPU; the real
    # ceiling is the AI provider's rate limit (429 -> the job is retried).
    ANSWER_WORKER_CONCURRENCY: int = Field(50, ge=1, le=500)  # answers generated at once
    ANSWER_WORKER_POLL_SECONDS: float = Field(1.0, ge=0.1, le=60)
    ANSWER_MAX_ATTEMPTS: int = Field(3, ge=1, le=10)  # generating the answer
    ANSWER_RETRY_DELAY_SECONDS: int = Field(5, ge=1, le=300)  # x attempt number
    ANSWER_STALE_SECONDS: int = Field(300, ge=30, le=86400)  # stuck in "processing" (API crash)
    # Delivering the finished answer to the site: after this many failed sends, or this long, the
    # answer is dropped (a chat answer that arrives 10 minutes late is useless).
    ANSWER_CALLBACK_MAX_ATTEMPTS: int = Field(4, ge=1, le=20)
    ANSWER_CALLBACK_TTL_SECONDS: int = Field(600, ge=30, le=86400)

    # --- Ingestion queue (records/texts/files -> chunk -> embed -> Qdrant, done by a worker) ---
    # The API only validates + queues a request (and answers 200 at once); the ingestion worker
    # (python -m app.workers.ingestion) does the heavy work and then queues a completion callback.
    # None = auto: run the worker loop INSIDE the API process only when QDRANT_PATH is set
    # (embedded Qdrant can be opened by one process only). true/false forces it.
    INGEST_RUN_IN_API: bool | None = None
    INGEST_WORKER_CONCURRENCY: int = Field(2, ge=1, le=16)  # records processed at once
    INGEST_WORKER_POLL_SECONDS: float = Field(1.0, ge=0.1, le=300)
    INGEST_MAX_ATTEMPTS: int = Field(3, ge=1, le=20)  # per job, for temporary failures
    INGEST_RETRY_DELAY_SECONDS: int = Field(30, ge=1, le=3600)  # x attempt number
    INGEST_STALE_SECONDS: int = Field(900, ge=30, le=86400)  # job stuck in "processing" (crash)
    INGEST_RETENTION_DAYS: int = Field(7, ge=1, le=365)  # finished jobs/deliveries are purged

    # --- Completion callbacks (AI server -> WordPress plugin; sent by the delivery worker) ---
    # Signing key for callbacks. Empty = API_SHARED_SECRET (the plugin's api_key).
    PLUGIN_CALLBACK_SECRET: SecretStr = SecretStr("")
    # Development only: allow http:// and private/loopback callback hosts (never in production).
    CALLBACK_ALLOW_PRIVATE_NETWORKS: bool = False
    CALLBACK_BATCH_WINDOW_SECONDS: float = Field(10.0, ge=0, le=300)  # collect results per site
    CALLBACK_INITIAL_BATCH_SIZE: int = Field(50, ge=1, le=500)  # adapts to the site's speed
    CALLBACK_CONNECT_TIMEOUT: float = Field(3.0, ge=0.5, le=30)
    CALLBACK_TOTAL_TIMEOUT: float = Field(10.0, ge=1, le=60)  # hard limit per request
    CALLBACK_MAX_CONCURRENCY: int = Field(8, ge=1, le=64)  # different sites at once (1 per site)
    CALLBACK_MIN_INTERVAL_SECONDS: float = Field(1.0, ge=0, le=60)  # gap between sends per site
    CALLBACK_MAX_ATTEMPTS: int = Field(8, ge=1, le=50)  # per result, then "dead"
    CALLBACK_BREAKER_FAILURES: int = Field(5, ge=1, le=100)  # failures in a row -> ping-only
    CALLBACK_PING_INTERVAL_SECONDS: int = Field(300, ge=10, le=86400)
    CALLBACK_WORKER_POLL_SECONDS: float = Field(2.0, ge=0.2, le=300)

    # ---------- validators ----------
    @field_validator("LOG_LEVEL", mode="before")
    @classmethod
    def _upper_level(cls, v):
        return v.upper() if isinstance(v, str) else v

    @field_validator("INGEST_RUN_IN_API", "ENABLE_DOCS", mode="before")
    @classmethod
    def _empty_is_auto(cls, v):
        return None if isinstance(v, str) and not v.strip() else v

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

    @field_validator("AI_ALLOWED_MODELS")
    @classmethod
    def _check_models(cls, v: str) -> str:
        names = [x.strip() for x in v.split(",") if x.strip()]
        if not names:
            raise ValueError("AI_ALLOWED_MODELS needs at least one model name")
        for name in names:
            if not re.fullmatch(r"[A-Za-z0-9_.:\-]{1,64}", name):
                raise ValueError(f"Invalid model name in AI_ALLOWED_MODELS: {name!r}")
        return ",".join(names)

    @field_validator("TOOLS_PRODUCTS_PATH", "TOOLS_DYNAMIC_PATH")
    @classmethod
    def _check_tool_path(cls, v: str) -> str:
        v = v.strip()
        if not v.startswith("/") or "://" in v or ".." in v or "?" in v or "#" in v:
            raise ValueError("TOOLS_*_PATH must be a path starting with / (no host, no query)")
        return v

    @field_validator("PLUGIN_BASE_URL")
    @classmethod
    def _check_plugin_url(cls, v: str) -> str:
        v = v.strip()
        if v and not re.match(r"^https?://[^/\s]+", v, re.I):
            raise ValueError("PLUGIN_BASE_URL must look like https://your-site.com")
        return v

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
        if self.APP_ENV == "production" and self.CALLBACK_ALLOW_PRIVATE_NETWORKS:
            raise ValueError("CALLBACK_ALLOW_PRIVATE_NETWORKS must be false in production")
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

    @property
    def ingest_in_api(self) -> bool:
        """Run the ingestion loop inside the API process (embedded Qdrant needs that)."""
        if self.INGEST_RUN_IN_API is not None:
            return self.INGEST_RUN_IN_API
        return bool(self.QDRANT_PATH)

    @property
    def allowed_models(self) -> list[str]:
        return [x.strip() for x in self.AI_ALLOWED_MODELS.split(",") if x.strip()]

    @property
    def default_model(self) -> str:
        return self.allowed_models[0]

    def tone_text(self, tone: str | None) -> str:
        """Prompt wording of a tone; unknown/empty -> DEFAULT_TONE."""
        texts = {"formal": self.TONE_FORMAL, "friendly": self.TONE_FRIENDLY}
        return texts.get((tone or "").strip().lower()) or texts[self.DEFAULT_TONE]

    def resolve_model(self, requested: str | None) -> str:
        """The requested model if it is allowed, otherwise the first (default) one."""
        wanted = (requested or "").strip()
        return wanted if wanted in self.allowed_models else self.default_model

    @property
    def callback_secret(self) -> str:
        """Key used to sign callbacks to the plugin (default: the shared API secret)."""
        own = self.PLUGIN_CALLBACK_SECRET.get_secret_value()
        return own or self.API_SHARED_SECRET.get_secret_value()

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
