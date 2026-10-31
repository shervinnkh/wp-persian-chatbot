"""مدیریت دانلود فایل‌ها.

- لینک = `FILES_BASE_URL + path` (فقط path از ورودی می‌آید؛ آدرس کامل/دامنهٔ دیگر پذیرفته نمی‌شود).
- ریدایرکت فقط روی همان هاست؛ سقف حجم `FILES_MAX_MB` هم با Content-Length و هم هنگام استریم.
- ذخیره: `{FILES_DIR}/{record_id با : → _}/source.{ext}` (یک پوشه برای هر رکورد ← حذف ساده و مطمئن).
  ابتدا در `source.ext.part` نوشته و پس از موفقیت atomic جابه‌جا می‌شود.
- فقط pdf / txt / docx.
"""

from __future__ import annotations

import asyncio
import hashlib
import logging
import os
import re
import shutil
import time
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from urllib.parse import quote, urljoin, urlsplit

import httpx

from app.core.config import Settings
from app.core.errors import FileProcessingError

logger = logging.getLogger(__name__)

ALLOWED_FILE_EXTENSIONS: tuple[str, ...] = (".pdf", ".txt", ".docx")
_MAX_REDIRECTS = 3
_SAFE_DIR = re.compile(r"[^A-Za-z0-9_.\-]")


@dataclass(slots=True)
class DownloadedFile:
    local_path: Path
    ext: str
    size: int
    sha256: str
    url: str


class DownloadManager:
    def __init__(self, settings: Settings) -> None:
        self.s = settings
        self.root: Path = settings.files_dir
        self.root.mkdir(parents=True, exist_ok=True)
        self._base_host = (urlsplit(settings.FILES_BASE_URL).hostname or "").lower()
        self.cleanup_partials()

    # ---------------- مسیرها ----------------

    def record_dir(self, record_id: str) -> Path:
        name = _SAFE_DIR.sub("_", record_id).strip(".")
        if not name:
            raise FileProcessingError("invalid_id", "Invalid record id.")
        return self.root / name

    def build_url(self, path: str) -> str:
        p = (path or "").strip()
        if (
            not p
            or "://" in p
            or p.startswith(("//", "\\"))
            or "\\" in p
            or ".." in PurePosixPath(p).parts
            or any(ord(c) < 32 for c in p)
            or "?" in p
            or "#" in p
        ):
            raise FileProcessingError("invalid_path", "File path is not allowed.")
        return urljoin(self.s.FILES_BASE_URL, quote(p.lstrip("/"), safe="/-_.~%"))

    @staticmethod
    def pick_extension(path: str, original_name: str) -> str:
        ext = PurePosixPath(path).suffix.lower()
        if ext not in ALLOWED_FILE_EXTENSIONS:
            raise FileProcessingError(
                "unsupported_extension",
                f"Only {', '.join(ALLOWED_FILE_EXTENSIONS)} files are accepted.",
            )
        if original_name:
            oext = PurePosixPath(original_name).suffix.lower()
            if oext and oext not in ALLOWED_FILE_EXTENSIONS:
                raise FileProcessingError(
                    "unsupported_extension", f"Original file type {oext} is not accepted."
                )
        return ext

    def _assert_same_host(self, url: str) -> None:
        parts = urlsplit(url)
        if (
            parts.scheme not in ("http", "https")
            or (parts.hostname or "").lower() != self._base_host
        ):
            raise FileProcessingError(
                "invalid_redirect", "Redirect to another host is not allowed."
            )

    # ---------------- دانلود ----------------

    async def download(self, record_id: str, path: str, original_name: str = "") -> DownloadedFile:
        ext = self.pick_extension(path, original_name)
        url = self.build_url(path)
        dest_dir = self.record_dir(record_id)
        dest_dir.mkdir(parents=True, exist_ok=True)
        tmp = dest_dir / f"source{ext}.part"
        final = dest_dir / f"source{ext}"
        logger.info("files.download_start id=%s ext=%s", record_id, ext)
        t0 = time.monotonic()

        last: FileProcessingError | None = None
        size = 0
        digest = ""
        for attempt in range(self.s.FILES_DOWNLOAD_RETRIES + 1):
            try:
                size, digest = await self._fetch(url, tmp)
                last = None
                break
            except FileProcessingError as exc:
                last = exc
                tmp.unlink(missing_ok=True)
                if not exc.retryable or attempt >= self.s.FILES_DOWNLOAD_RETRIES:
                    break
                await asyncio.sleep(1.0 * (attempt + 1))
        if last is not None:
            self._rmdir_if_empty(dest_dir)
            raise last

        try:
            self._sniff(tmp, ext)
            os.replace(tmp, final)
        except Exception:
            tmp.unlink(missing_ok=True)
            self._rmdir_if_empty(dest_dir)
            raise
        logger.info(
            "files.download_done id=%s bytes=%d ms=%d",
            record_id,
            size,
            int((time.monotonic() - t0) * 1000),
        )
        return DownloadedFile(final, ext, size, digest, url)

    async def _fetch(self, url: str, tmp: Path) -> tuple[int, str]:
        limit = self.s.files_max_bytes
        timeout = httpx.Timeout(
            connect=self.s.FILES_CONNECT_TIMEOUT,
            read=self.s.FILES_READ_TIMEOUT,
            write=30.0,
            pool=10.0,
        )
        try:
            async with httpx.AsyncClient(
                timeout=timeout,
                follow_redirects=False,
                headers={"User-Agent": "PersianAIBackend/0.4"},
            ) as client:
                current = url
                for _ in range(_MAX_REDIRECTS + 1):
                    async with client.stream("GET", current) as resp:
                        code = resp.status_code
                        if code in (301, 302, 303, 307, 308):
                            loc = resp.headers.get("location")
                            if not loc:
                                raise FileProcessingError(
                                    "download_failed", "Redirect without Location."
                                )
                            nxt = urljoin(current, loc)
                            self._assert_same_host(nxt)
                            current = nxt
                            continue
                        if code == 404:
                            raise FileProcessingError(
                                "file_not_found", "File not found on the website (404)."
                            )
                        if code >= 500:
                            raise FileProcessingError(
                                "download_failed", f"Website returned HTTP {code}.", retryable=True
                            )
                        if code >= 400:
                            raise FileProcessingError(
                                "download_failed", f"Website returned HTTP {code}."
                            )

                        cl = resp.headers.get("content-length")
                        if cl and cl.isdigit() and int(cl) > limit:
                            raise FileProcessingError(
                                "file_too_large", f"File is larger than {self.s.FILES_MAX_MB} MB."
                            )

                        h = hashlib.sha256()
                        size = 0
                        with open(tmp, "wb") as f:
                            async for chunk in resp.aiter_bytes(64 * 1024):
                                size += len(chunk)
                                if size > limit:
                                    raise FileProcessingError(
                                        "file_too_large",
                                        f"File is larger than {self.s.FILES_MAX_MB} MB.",
                                    )
                                f.write(chunk)
                                h.update(chunk)
                        if size == 0:
                            raise FileProcessingError("empty_file", "Downloaded file is empty.")
                        return size, h.hexdigest()
                raise FileProcessingError("download_failed", "Too many redirects.")
        except httpx.HTTPError as exc:
            raise FileProcessingError(
                "download_failed", f"Download failed ({type(exc).__name__}).", retryable=True
            ) from exc

    @staticmethod
    def _sniff(p: Path, ext: str) -> None:
        with open(p, "rb") as f:
            head = f.read(1024)
        low = head.lstrip().lower()
        if low.startswith((b"<!doctype html", b"<html")):
            raise FileProcessingError(
                "invalid_file", "The link returned a web page instead of a file."
            )
        if ext == ".pdf" and b"%PDF-" not in head:
            raise FileProcessingError("invalid_file", "File content is not a PDF.")
        if ext == ".docx" and not head.startswith(b"PK\x03\x04"):
            raise FileProcessingError("invalid_file", "File content is not a DOCX.")

    # ---------------- مدیریت ----------------

    def remove(self, record_id: str) -> bool:
        d = self.record_dir(record_id)
        if d.exists():
            shutil.rmtree(d, ignore_errors=True)
            return True
        return False

    def remove_source(self, record_id: str) -> None:
        """فقط فایل‌های اصلی (برای FILES_KEEP_ORIGINAL=false)؛ پوشه حذف می‌شود."""
        self.remove(record_id)

    def relative(self, p: Path) -> str:
        try:
            return p.relative_to(self.root).as_posix()
        except ValueError:
            return p.name

    @staticmethod
    def _rmdir_if_empty(d: Path) -> None:
        try:
            d.rmdir()
        except OSError:
            pass

    def cleanup_partials(self, max_age_seconds: int = 3600) -> int:
        """`*.part` قدیمی (دانلود نیمه‌کاره) را پاک می‌کند؛ در ساخت manager یک‌بار اجرا می‌شود."""
        n = 0
        now = time.time()
        for p in self.root.glob("*/*.part"):
            try:
                if now - p.stat().st_mtime > max_age_seconds:
                    p.unlink()
                    n += 1
            except OSError:
                continue
        if n:
            logger.info("files.cleanup_partials removed=%d", n)
        return n
