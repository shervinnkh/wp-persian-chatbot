"""Downloading knowledge files from the WordPress site through a SHORT-LIVED SIGNED LINK.

The plugin puts `download: {url, expires}` into the file record (the URL carries `exp` and an
HMAC signature made by the plugin; the AI server never signs anything and needs no secret for it).
Rules (SSRF + quota):

* https only (http only with CALLBACK_ALLOW_PRIVATE_NETWORKS in development), ports 443/8443,
  no credentials, the host must be the site of this key (PLUGIN_BASE_URL or the locked site);
* DNS is resolved right before connecting, non-public IPs are refused and the connection goes to
  the checked IP (same protection as callbacks; SNI/Host stay the original);
* redirects are NOT followed; per-file cap FILES_MAX_MB and a total cap UPLOADS_MAX_TOTAL_MB
  (checked from the declared size before downloading and again while streaming);
* stored as `{FILES_DIR}/{record_id with ':'->'_'}/source.{ext}` (one folder per record, so deleting
  is trivial); written to `*.part` first and moved atomically.
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
from urllib.parse import parse_qs, urlsplit

import httpx

from app.core.config import Settings
from app.core.errors import FileProcessingError, StorageQuotaError
from app.services.ingestion.callback import (
    CallbackBlockedError,
    resolve_public_ip,
    site_key_for_url,
)

logger = logging.getLogger(__name__)

ALLOWED_FILE_EXTENSIONS: tuple[str, ...] = (".pdf", ".txt", ".docx")
_SAFE_DIR = re.compile(r"[^A-Za-z0-9_.\-]")
_ALLOWED_PORTS = (443, 8443)
_EXPIRY_GRACE_SECONDS = 5


@dataclass(slots=True)
class DownloadedFile:
    local_path: Path
    ext: str
    size: int
    sha256: str


def pick_extension(original_name: str, declared_ext: str) -> str:
    """`.pdf` / `.docx` / `.txt` from the declared extension (fallback: the original name)."""
    ext = (declared_ext or "").strip().lower().lstrip(".")
    ext = f".{ext}" if ext else PurePosixPath(original_name or "").suffix.lower()
    if ext == ".doc":
        raise FileProcessingError(
            "unsupported_extension",
            "Legacy .doc files are not supported; convert the file to .docx, .pdf or .txt.",
        )
    if ext not in ALLOWED_FILE_EXTENSIONS:
        raise FileProcessingError(
            "unsupported_extension",
            f"Only {', '.join(ALLOWED_FILE_EXTENSIONS)} files are accepted.",
        )
    return ext


def check_download_url(
    settings: Settings, url: str, *, locked_site: str | None, expires: int | None = None
) -> str:
    """Validate the signed link WITHOUT touching the network. -> site key. Raises
    FileProcessingError (permanent). Called when the request is accepted and again by the worker."""
    url = (url or "").strip()
    dev = settings.CALLBACK_ALLOW_PRIVATE_NETWORKS
    try:
        parts = urlsplit(url)
        host = (parts.hostname or "").lower()
        port = parts.port
    except ValueError as exc:
        raise FileProcessingError(
            "invalid_download_url", "download.url is not a valid URL"
        ) from exc
    if parts.scheme not in ("https", "http") or not host:
        raise FileProcessingError("invalid_download_url", "download.url must be an https URL")
    if parts.scheme == "http" and not dev:
        raise FileProcessingError("invalid_download_url", "download.url must use https")
    if parts.username or parts.password:
        raise FileProcessingError(
            "invalid_download_url", "download.url must not contain credentials"
        )
    if not dev and port is not None and port not in _ALLOWED_PORTS:
        raise FileProcessingError("invalid_download_url", "download.url: only ports 443/8443")
    if not dev and (host == "localhost" or host.endswith((".localhost", ".local", ".internal"))):
        raise FileProcessingError(
            "invalid_download_url", "download.url points to a non-public host"
        )
    if len(url) > 2048:
        raise FileProcessingError("invalid_download_url", "download.url is too long")

    key = site_key_for_url(url, dev=True)
    expected = None
    if settings.PLUGIN_BASE_URL:
        expected = site_key_for_url(settings.PLUGIN_BASE_URL, dev=True)
    elif locked_site:
        expected = locked_site
    if expected is not None and key != expected:
        raise FileProcessingError(
            "invalid_download_url", "download.url host does not match the site of this key"
        )

    # The link carries its own expiry (`exp`, unix seconds). An expired link can never work, so
    # the job fails at once with a clear reason instead of retrying.
    query = parse_qs(parts.query)
    try:
        exp = int((query.get("exp") or [""])[0])
    except ValueError:
        exp = expires or 0
    exp = exp or (expires or 0)
    if exp and exp + _EXPIRY_GRACE_SECONDS < int(time.time()):
        raise FileProcessingError(
            "download_link_expired",
            "The download link expired before the server could use it; train the file again.",
        )
    return key


class DownloadManager:
    def __init__(self, settings: Settings) -> None:
        self.s = settings
        self.root: Path = settings.files_dir
        self.root.mkdir(parents=True, exist_ok=True)
        self._reserved: dict[str, int] = {}  # record id -> bytes promised to a running download
        self._quota_lock = asyncio.Lock()
        self.cleanup_partials()

    # ---------------- paths / quota ----------------
    def record_dir(self, record_id: str) -> Path:
        name = _SAFE_DIR.sub("_", record_id).strip(".")
        if not name:
            raise FileProcessingError("invalid_id", "Invalid record id.")
        return self.root / name

    def used_bytes(self) -> int:
        total = 0
        for dirpath, _dirs, files in os.walk(self.root):
            for name in files:
                try:
                    total += os.path.getsize(os.path.join(dirpath, name))
                except OSError:
                    continue
        return total

    def usage(self) -> dict:
        used = self.used_bytes()
        files = sum(1 for d in self.root.iterdir() if d.is_dir()) if self.root.exists() else 0
        cap = self.s.uploads_max_total_bytes
        return {
            "used_bytes": used,
            "max_bytes": cap,
            "free_bytes": max(0, cap - used),
            "files": files,
            "max_file_bytes": self.s.files_max_bytes,
        }

    async def _reserve(self, record_id: str, declared: int | None) -> int:
        """Reserve room for this download and return how many bytes it may write at most."""
        async with self._quota_lock:
            own = self.record_dir(record_id)
            replaced = 0
            if own.exists():  # a re-download of the same record replaces its old copy
                replaced = sum(p.stat().st_size for p in own.rglob("*") if p.is_file())
            free = (
                self.s.uploads_max_total_bytes
                - self.used_bytes()
                + replaced
                - sum(self._reserved.values())
            )
            if free <= 0 or (declared and declared > free):
                raise StorageQuotaError(
                    f"Storage limit of {self.s.UPLOADS_MAX_TOTAL_MB} MB is reached; "
                    "delete some files and try again."
                )
            if declared and declared > self.s.files_max_bytes:
                raise FileProcessingError(
                    "file_too_large", f"File is larger than {self.s.FILES_MAX_MB} MB."
                )
            allowed = min(free, self.s.files_max_bytes)
            self._reserved[record_id] = allowed
            return allowed

    def _release(self, record_id: str) -> None:
        self._reserved.pop(record_id, None)

    # ---------------- download ----------------
    async def download(
        self,
        record_id: str,
        url: str,
        *,
        ext: str,
        declared_size: int | None,
        locked_site: str | None,
        expires: int | None = None,
    ) -> DownloadedFile:
        check_download_url(self.s, url, locked_site=locked_site, expires=expires)
        allowed = await self._reserve(record_id, declared_size)
        dest_dir = self.record_dir(record_id)
        tmp = dest_dir / f"source{ext}.part"
        final = dest_dir / f"source{ext}"
        t0 = time.monotonic()
        logger.info("files.download_start id=%s ext=%s declared=%s", record_id, ext, declared_size)
        try:
            dest_dir.mkdir(parents=True, exist_ok=True)
            last: FileProcessingError | None = None
            size, digest = 0, ""
            for attempt in range(self.s.FILES_DOWNLOAD_RETRIES + 1):
                try:
                    size, digest = await self._fetch(url, tmp, allowed)
                    last = None
                    break
                except FileProcessingError as exc:
                    last = exc
                    tmp.unlink(missing_ok=True)
                    if not exc.retryable or attempt >= self.s.FILES_DOWNLOAD_RETRIES:
                        break
                    await asyncio.sleep(1.0 * (attempt + 1))
            if last is not None:
                raise last
            self._sniff(tmp, ext)
            # replace any older copy of this record (different extension) atomically-ish
            for old in dest_dir.glob("source.*"):
                if old != final and not old.name.endswith(".part"):
                    old.unlink(missing_ok=True)
            os.replace(tmp, final)
        except BaseException:
            tmp.unlink(missing_ok=True)
            self._rmdir_if_empty(dest_dir)
            raise
        finally:
            self._release(record_id)
        logger.info(
            "files.download_done id=%s bytes=%d ms=%d",
            record_id, size, int((time.monotonic() - t0) * 1000),
        )  # fmt: skip
        return DownloadedFile(final, ext, size, digest)

    async def _fetch(self, url: str, tmp: Path, limit: int) -> tuple[int, str]:
        parts = urlsplit(url)
        host = (parts.hostname or "").lower()
        port = parts.port or (443 if parts.scheme == "https" else 80)
        dev = self.s.CALLBACK_ALLOW_PRIVATE_NETWORKS
        try:
            ip = await resolve_public_ip(host, port, allow_private=dev)
        except CallbackBlockedError as exc:
            raise FileProcessingError(
                "download_failed", f"Site address refused: {exc}", retryable=False
            ) from exc

        # Connect to the IP we checked; keep the real host for TLS (SNI) and the Host header.
        netloc = f"[{ip}]:{port}" if ":" in ip else f"{ip}:{port}"
        target = parts._replace(netloc=netloc).geturl()
        default = 443 if parts.scheme == "https" else 80
        host_header = host if port == default else f"{host}:{port}"
        timeout = httpx.Timeout(
            connect=self.s.FILES_CONNECT_TIMEOUT,
            read=self.s.FILES_READ_TIMEOUT,
            write=30.0,
            pool=10.0,
        )
        try:
            async with httpx.AsyncClient(
                timeout=timeout, follow_redirects=False, trust_env=False
            ) as client:
                request = client.build_request(
                    "GET",
                    target,
                    headers={"Host": host_header, "User-Agent": "PersianAIBackend/0.4"},
                    extensions={"sni_hostname": host},
                )
                resp = await client.send(request, stream=True)
                try:
                    code = resp.status_code
                    if 300 <= code < 400:
                        raise FileProcessingError(
                            "download_failed", "The site redirected the download link."
                        )
                    if code in (401, 403):
                        raise FileProcessingError(
                            "download_forbidden",
                            "The site refused the download link (expired or invalid signature).",
                        )
                    if code == 404:
                        raise FileProcessingError(
                            "file_not_found", "File not found on the website."
                        )
                    if code in (408, 429) or code >= 500:
                        raise FileProcessingError(
                            "download_failed", f"Website returned HTTP {code}.", retryable=True
                        )
                    if code >= 400:
                        raise FileProcessingError(
                            "download_failed", f"Website returned HTTP {code}."
                        )
                    cl = resp.headers.get("content-length")
                    if cl and cl.isdigit() and int(cl) > limit:
                        raise self._too_big(int(cl))
                    h = hashlib.sha256()
                    size = 0
                    with open(tmp, "wb") as f:
                        async for chunk in resp.aiter_bytes(64 * 1024):
                            size += len(chunk)
                            if size > limit:
                                raise self._too_big(size)
                            f.write(chunk)
                            h.update(chunk)
                finally:
                    await resp.aclose()
        except httpx.HTTPError as exc:
            raise FileProcessingError(
                "download_failed", f"Download failed ({type(exc).__name__}).", retryable=True
            ) from exc
        if size == 0:
            raise FileProcessingError("empty_file", "Downloaded file is empty.")
        return size, h.hexdigest()

    def _too_big(self, size: int) -> FileProcessingError:
        if size > self.s.files_max_bytes:
            return FileProcessingError(
                "file_too_large", f"File is larger than {self.s.FILES_MAX_MB} MB."
            )
        return StorageQuotaError(
            f"Storage limit of {self.s.UPLOADS_MAX_TOTAL_MB} MB is reached; "
            "delete some files and try again."
        )

    @staticmethod
    def _sniff(p: Path, ext: str) -> None:
        with open(p, "rb") as f:
            head = f.read(1024)
        low = head.lstrip().lower()
        if low.startswith((b"<!doctype html", b"<html", b'{"code"', b'{"error"')):
            raise FileProcessingError(
                "invalid_file", "The link returned a web page instead of a file."
            )
        if ext == ".pdf" and b"%PDF-" not in head:
            raise FileProcessingError("invalid_file", "File content is not a PDF.")
        if ext == ".docx" and not head.startswith(b"PK\x03\x04"):
            raise FileProcessingError("invalid_file", "File content is not a DOCX.")

    # ---------------- management ----------------
    def remove(self, record_id: str) -> bool:
        d = self.record_dir(record_id)
        if d.exists():
            shutil.rmtree(d, ignore_errors=True)
            return True
        return False

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
