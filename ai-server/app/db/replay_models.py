"""Table of the replay guard (S-6): one row per accepted signed request.

`seen_signatures`  key = the request signature (or a digest of it, see app.core.replay) and the
                   unix time after which the row is useless: a request can only be replayed
                   while its timestamp is still inside the accepted window, so rows live for
                   2 x AUTH_MAX_SKEW_SECONDS after the request's own timestamp.
"""

from __future__ import annotations

from sqlalchemy import BigInteger, String
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base


class SeenSignature(Base):
    __tablename__ = "seen_signatures"

    sig: Mapped[str] = mapped_column(String(128), primary_key=True)
    expires_at: Mapped[int] = mapped_column(BigInteger, nullable=False, index=True)
