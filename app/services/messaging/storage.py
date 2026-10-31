"""Safe download of chat attachments (image / voice) into UPLOADS_DIR.

- Only from the WordPress site (PLUGIN_BASE_URL host + port), redirects are not followed (SSRF).
- The file type comes from the leading bytes, never from the URL's extension.
- Per-file size limit: MESSAGE_ATTACHMENT_MAX_MB. Stored as {kind}/{year}/{month}/{uuid}.{ext}.
- The path kept in the database is RELATIVE to UPLOADS_DIR (see `resolve_attachment_path`).
"""

from __future__ import annotations

import asyncio
import ipaddress
import logging
import os
import uuid
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse

import httpx

from app.core.config import Settings

logger = logging.getLogger(__name__)

_CHUNK = 64 * 1024


class AttachmentError(Exception):
    """Its message is safe (no URL/secret) and is logged as is."""


def uploads_root(settings: Settings) -> Path:
    return settings.uploads_dir.resolve()


def resolve_attachment_path(settings: Settings, relative: str | None) -> Path | None:
    """Safe absolute path of an attachment; None when it would escape UPLOADS_DIR."""
    if not relative:
        return None
    root = uploads_root(settings)
    full = (root / relative).resolve()
    try:
        full.relative_to(root)
    except ValueError:
        return None
    return full


def sniff_extension(kind: str, head: bytes) -> str | None:
    if kind == "image":
        if head.startswith(b"\xff\xd8\xff"):
            return "jpg"
        if head.startswith(b"\x89PNG\r\n\x1a\n"):
            return "png"
        if head[:6] in (b"GIF87a", b"GIF89a"):
            return "gif"
        if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
            return "webp"
        return None
    if kind == "voice":
        if head.startswith(b"OggS"):
            return "ogg"
        if head.startswith(b"RIFF") and head[8:12] == b"WAVE":
            return "wav"
        if head.startswith(b"\x1a\x45\xdf\xa3"):
            return "webm"
        if head[4:8] == b"ftyp":
            return "m4a"
        if head.startswith(b"#!AMR"):
            return "amr"
        if head.startswith(b"fLaC"):
            return "flac"
        frame_sync = len(head) > 1 and head[0] == 0xFF
        if frame_sync and (head[1] & 0xF6) == 0xF0:
            return "aac"
        if head.startswith(b"ID3") or (frame_sync and (head[1] & 0xE0) == 0xE0):
            return "mp3"
        return None
    return None


def _check_url(url: str, settings: Settings) -> None:
    base = settings.PLUGIN_BASE_URL
    if not base:
        raise AttachmentError("PLUGIN_BASE_URL is not configured")
    dev = not settings.is_production

    u, b = urlparse(url), urlparse(base)
    if u.scheme not in ("https", "http") or (u.scheme == "http" and not dev):
        raise AttachmentError("attachment url must be https")
    if not u.hostname or u.hostname.lower() != (b.hostname or "").lower():
        raise AttachmentError("attachment host is not the plugin site")
    default = {"http": 80, "https": 443}
    if (u.port or default[u.scheme]) != (b.port or default.get(b.scheme, 443)):
        raise AttachmentError("attachment port is not the plugin site port")
    if not dev:
        try:
            ip = ipaddress.ip_address(u.hostname)
            if not ip.is_global:
                raise AttachmentError("attachment host is not public")
        except ValueError:
            pass  # a domain name, not an IP literal


def _write_atomic(path: Path, data: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".part")
    try:
        tmp.write_bytes(data)
        os.replace(tmp, path)
    finally:
        if tmp.exists():
            tmp.unlink(missing_ok=True)


async def download_attachment(*, url: str, kind: str, settings: Settings) -> tuple[str, int]:
    """Download a file -> (relative path, size in bytes). Raises AttachmentError."""
    _check_url(url, settings)
    max_bytes = settings.MESSAGE_ATTACHMENT_MAX_MB * 1024 * 1024
    timeout = float(settings.MESSAGE_DOWNLOAD_TIMEOUT)

    buf = bytearray()
    ext: str | None = None
    try:
        async with httpx.AsyncClient(
            timeout=httpx.Timeout(timeout, connect=10.0), follow_redirects=False
        ) as client:
            async with client.stream("GET", url) as resp:
                if resp.status_code != 200:
                    raise AttachmentError(f"download failed with status {resp.status_code}")
                declared = resp.headers.get("content-length")
                if declared and declared.isdigit() and int(declared) > max_bytes:
                    raise AttachmentError("file is larger than the allowed size")
                async for chunk in resp.aiter_bytes(_CHUNK):
                    buf.extend(chunk)
                    if len(buf) > max_bytes:
                        raise AttachmentError("file is larger than the allowed size")
                    if ext is None and len(buf) >= 32:
                        ext = sniff_extension(kind, bytes(buf[:32]))
                        if ext is None:
                            raise AttachmentError(f"file content is not a valid {kind}")
    except httpx.HTTPError as exc:
        raise AttachmentError(f"download error ({type(exc).__name__})") from exc

    if not buf:
        raise AttachmentError("empty file")
    if ext is None:
        ext = sniff_extension(kind, bytes(buf[:32]))
        if ext is None:
            raise AttachmentError(f"file content is not a valid {kind}")

    now = datetime.now(timezone.utc)
    relative = f"{kind}/{now:%Y}/{now:%m}/{uuid.uuid4().hex}.{ext}"
    full = resolve_attachment_path(settings, relative)
    if full is None:  # cannot happen
        raise AttachmentError("invalid storage path")
    await asyncio.to_thread(_write_atomic, full, bytes(buf))
    return relative, len(buf)


def delete_files(settings: Settings, relatives: list[str | None]) -> int:
    """Best-effort file removal; returns how many files were deleted."""
    removed = 0
    for rel in relatives:
        full = resolve_attachment_path(settings, rel)
        if full is None:
            continue
        try:
            full.unlink()
            removed += 1
        except FileNotFoundError:
            pass
        except OSError:
            logger.warning("messaging.file_delete_failed")
    return removed
