"""Gregorian -> Jalali (Solar Hijri) conversion for human-readable Persian dates."""

from __future__ import annotations

import re

MONTHS = (
    "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
    "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند",
)  # fmt: skip

_G_DAYS = (0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334)
_ISO_DATE = re.compile(r"^\s*(\d{4})-(\d{2})-(\d{2})")


def gregorian_to_jalali(gy: int, gm: int, gd: int) -> tuple[int, int, int]:
    gy2 = gy + 1 if gm > 2 else gy
    days = (
        355666
        + 365 * gy
        + (gy2 + 3) // 4
        - (gy2 + 99) // 100
        + (gy2 + 399) // 400
        + gd
        + _G_DAYS[gm - 1]
    )
    jy = -1595 + 33 * (days // 12053)
    days %= 12053
    jy += 4 * (days // 1461)
    days %= 1461
    if days > 365:
        jy += (days - 1) // 365
        days = (days - 1) % 365
    if days < 186:
        jm, jd = 1 + days // 31, 1 + days % 31
    else:
        jm, jd = 7 + (days - 186) // 30, 1 + (days - 186) % 30
    return jy, jm, jd


def jalali_text(iso: str | None) -> str:
    """'2025-11-04T06:50:00+03:30' -> '13 آبان 1404' (the date exactly as written, i.e. site time).

    Returns '' for anything that is not an ISO date.
    """
    m = _ISO_DATE.match(iso or "")
    if not m:
        return ""
    gy, gm, gd = (int(x) for x in m.groups())
    if not (1 <= gm <= 12 and 1 <= gd <= 31):
        return ""
    jy, jm, jd = gregorian_to_jalali(gy, gm, gd)
    return f"{jd} {MONTHS[jm - 1]} {jy}"
