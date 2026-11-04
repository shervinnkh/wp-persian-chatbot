"""Speech-to-text for voice messages (OpenAI audio transcription API, default whisper-1 / fa).

Whisper accepts flac, mp3, mp4, mpeg, mpga, m4a, ogg, wav, webm. Phone formats it does not take
(amr, raw aac) are converted to mp3 with ffmpeg when ffmpeg is installed.
"""

from __future__ import annotations

import asyncio
import logging
import shutil
import tempfile
import time
from pathlib import Path

from openai import AsyncOpenAI

from app.core.config import Settings
from app.core.errors import ServiceUnavailableError, UpstreamError
from app.core.monitoring import emit

logger = logging.getLogger(__name__)

_WHISPER_EXTS = {"flac", "mp3", "mp4", "mpeg", "mpga", "m4a", "ogg", "wav", "webm"}
_CONVERT_EXTS = {"amr", "aac"}
_MAX_AUDIO_BYTES = 25 * 1024 * 1024  # OpenAI's limit


class UnusableAudioError(Exception):
    """The audio itself cannot be transcribed (retrying will not help)."""


class Transcriber:
    def __init__(self, settings: Settings, *, client=None):
        self.s = settings
        self._client = client  # tests inject a fake with `.audio.transcriptions.create`
        self._key = settings.OPENAI_API_KEY.get_secret_value()

    def _get_client(self):
        if self._client is None:
            if not self._key:
                raise ServiceUnavailableError("OPENAI_API_KEY is not configured on the server")
            self._client = AsyncOpenAI(
                api_key=self._key,
                base_url=self.s.OPENAI_BASE_URL or None,
                timeout=self.s.STT_TIMEOUT,
                max_retries=self.s.ANSWER_LLM_MAX_RETRIES,
            )
        return self._client

    async def transcribe(self, path: Path) -> str:
        """Audio file -> text ('' when nothing was said). Raises UnusableAudioError for a file
        the API rejects, UpstreamError for temporary failures (the answer job is retried)."""
        ext = path.suffix.lower().lstrip(".")
        t0 = time.perf_counter()
        try:
            data, name = await asyncio.to_thread(self._read, path, ext)
        except UnusableAudioError as exc:
            emit(
                "stt.transcribe", model=self.s.STT_MODEL, status="rejected", ext=ext,
                error_type="UnusableAudio", error=str(exc)[:120],
                ms=(time.perf_counter() - t0) * 1000,
            )  # fmt: skip
            raise
        kwargs: dict = {"model": self.s.STT_MODEL, "file": (name, data)}
        if self.s.STT_LANGUAGE.strip():
            kwargs["language"] = self.s.STT_LANGUAGE.strip()
        try:
            result = await self._get_client().audio.transcriptions.create(**kwargs)
        except Exception as exc:  # noqa: BLE001 - SDK errors differ per version
            rejected = getattr(exc, "status_code", None) in (400, 413, 415, 422)
            emit(
                "stt.transcribe", model=self.s.STT_MODEL,
                status="rejected" if rejected else "error", ext=ext, bytes=len(data),
                error_type=type(exc).__name__, http_status=getattr(exc, "status_code", None),
                ms=(time.perf_counter() - t0) * 1000,
            )  # fmt: skip
            if rejected:
                raise UnusableAudioError(f"{type(exc).__name__}") from exc
            raise UpstreamError(f"Transcription failed ({type(exc).__name__}: {exc})") from exc
        text = getattr(result, "text", None)
        if text is None and isinstance(result, str):
            text = result
        text = " ".join((text or "").split())
        emit(
            "stt.transcribe", model=self.s.STT_MODEL, status="ok", ext=ext, bytes=len(data),
            chars=len(text), ms=(time.perf_counter() - t0) * 1000,
        )  # fmt: skip
        logger.info("stt.done model=%s bytes=%d chars=%d", self.s.STT_MODEL, len(data), len(text))
        return text

    def _read(self, path: Path, ext: str) -> tuple[bytes, str]:
        if ext in _CONVERT_EXTS:
            return self._convert(path)
        if ext not in _WHISPER_EXTS:
            raise UnusableAudioError(f"unsupported audio type {ext!r}")
        data = path.read_bytes()
        if len(data) > _MAX_AUDIO_BYTES:
            raise UnusableAudioError("audio is larger than 25 MB")
        return data, f"voice.{ext}"

    @staticmethod
    def _convert(path: Path) -> tuple[bytes, str]:
        import subprocess

        ffmpeg = shutil.which("ffmpeg")
        if not ffmpeg:
            raise UnusableAudioError(f"{path.suffix} needs ffmpeg, which is not installed")
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "voice.mp3"
            proc = subprocess.run(  # noqa: S603 - fixed argv, no shell
                [ffmpeg, "-y", "-i", str(path), "-vn", "-ac", "1", "-ar", "16000", str(out)],
                capture_output=True,
                timeout=120,
                check=False,
            )
            if proc.returncode != 0 or not out.exists():
                raise UnusableAudioError("ffmpeg could not convert the audio")
            return out.read_bytes(), "voice.mp3"

    async def close(self) -> None:
        if self._client is not None and hasattr(self._client, "close"):
            await self._client.close()
        self._client = None
