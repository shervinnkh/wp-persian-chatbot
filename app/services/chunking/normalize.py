"""Persian text normalization (applied to every text before chunking/embedding).

IMPORTANT: user queries must go through `normalize_text` too at retrieval time, otherwise the
query and the stored chunks would use different letter/digit forms.

Decisions:
  * Arabic ي/ى -> ی and ك -> ک; ة/ھ -> ه; diacritics (harakat) and tatweel are removed.
  * Persian/Arabic-Indic digits -> ASCII digits (shorter in the tokenizer, and consistent with
    SKUs / numbers typed in Latin). Decimal/thousand separators ٫ ٬ -> . ,
  * Half-space (ZWNJ): runs are collapsed, ZWNJ next to spaces is dropped, other invisible
    characters are removed, and "می/نمی + word" and "word + ها/های" are joined with a ZWNJ.
"""

from __future__ import annotations

import re
import unicodedata

ZWNJ = "‌"

_TRANSLATE = str.maketrans(
    {
        **{chr(0x06F0 + i): str(i) for i in range(10)},  # Persian digits
        **{chr(0x0660 + i): str(i) for i in range(10)},  # Arabic-Indic digits
        "ي": "ی",
        "ى": "ی",
        "ك": "ک",
        "ة": "ه",
        "ھ": "ه",
        "ٱ": "ا",
        "٫": ".",
        "٬": ",",
        "٪": "%",
        "ـ": "",  # tatweel
    }
)
_DIACRITICS = re.compile("[ً-ٰٟۖ-ۭ]")
_INVISIBLE = re.compile("[­​‍‎‏⁠﻿‪-‮⁦-⁩]")
_SPACES = re.compile("[   -   　\t\x0b\x0c]")

_ZWNJ_RUN = re.compile(f"{ZWNJ}{{2,}}")
_ZWNJ_AT_EDGE = re.compile(f"(?:(?<=\\s)|^){ZWNJ}+|{ZWNJ}+(?=\\s|$)", re.MULTILINE)
_LETTER = "ء-يٱ-ۓ"
_MI_PREFIX = re.compile(f"(?<![{_LETTER}])(ن?می)[ ]+(?=[{_LETTER}])")
_PLURAL_SUFFIX = re.compile(f"(?<=[{_LETTER}])[ ]+(?=(?:ها|های|هایی)(?![{_LETTER}]))")


def normalize_text(text: str | None) -> str:
    if not text:
        return ""
    t = unicodedata.normalize("NFKC", str(text))
    t = t.replace("\r\n", "\n").replace("\r", "\n")
    t = _DIACRITICS.sub("", t)
    t = _INVISIBLE.sub("", t)
    t = t.translate(_TRANSLATE)
    t = _SPACES.sub(" ", t)
    t = _MI_PREFIX.sub(f"\\1{ZWNJ}", t)
    t = _PLURAL_SUFFIX.sub(ZWNJ, t)
    t = _ZWNJ_RUN.sub(ZWNJ, t)
    t = _ZWNJ_AT_EDGE.sub("", t)
    t = re.sub(r" {2,}", " ", t)
    t = re.sub(r" *\n *", "\n", t)
    t = re.sub(r"\n{3,}", "\n\n", t)
    return t.strip()
