"""Model prices (USD) for the cost cap (S-2). Pure parsing/validation, no I/O.

`MODEL_PRICES_JSON` = {"<model>": {"in": .., "cached_in": .., "out": .., "per_minute": ..}}
  * chat models  : in / out USD per 1,000,000 tokens (cached_in optional, default = in)
  * embedding    : in USD per 1,000,000 tokens
  * speech-to-text: per_minute USD per audio minute
A price of N USD per million tokens is exactly N micro-USD per token, so the cost of a call is an
integer number of micro-USD (no float money anywhere).
"""

from __future__ import annotations

import json
import math
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from functools import lru_cache

MICRO = 1_000_000
_KEYS = ("in", "cached_in", "out", "per_minute")


@dataclass(frozen=True, slots=True)
class ModelPrice:
    in_: Decimal | None = None
    cached_in: Decimal | None = None
    out: Decimal | None = None
    per_minute: Decimal | None = None

    def chat_micro(self, prompt: int, cached: int, completion: int) -> int:
        """Micro-USD of one chat call: uncached prompt + cached prompt + completion tokens."""
        cached = min(max(cached, 0), max(prompt, 0))
        uncached = max(prompt, 0) - cached
        in_ = self.in_ or Decimal(0)
        cin = self.cached_in if self.cached_in is not None else in_
        out = self.out or Decimal(0)
        return math.ceil(uncached * in_ + cached * cin + max(completion, 0) * out)

    def embedding_micro(self, tokens: int) -> int:
        return math.ceil(max(tokens, 0) * (self.in_ or Decimal(0)))

    def audio_micro(self, seconds: float) -> int:
        return math.ceil(Decimal(str(max(seconds, 0.0))) / 60 * (self.per_minute or Decimal(0)) * MICRO)


class PriceConfigError(ValueError):
    pass


@lru_cache(maxsize=8)
def parse_prices(raw: str) -> dict[str, ModelPrice]:
    raw = (raw or "").strip()
    if not raw:
        return {}
    try:
        data = json.loads(raw)
    except ValueError as exc:
        raise PriceConfigError(f"MODEL_PRICES_JSON is not valid JSON ({exc})") from exc
    if not isinstance(data, dict):
        raise PriceConfigError("MODEL_PRICES_JSON must be a JSON object {model: {prices}}")
    out: dict[str, ModelPrice] = {}
    for model, entry in data.items():
        if not isinstance(entry, dict) or not str(model).strip():
            raise PriceConfigError(f"MODEL_PRICES_JSON[{model!r}] must be an object")
        vals: dict[str, Decimal] = {}
        for key, value in entry.items():
            if key not in _KEYS:
                raise PriceConfigError(f"MODEL_PRICES_JSON[{model!r}]: unknown key {key!r}")
            if isinstance(value, bool) or not isinstance(value, (int, float)):
                raise PriceConfigError(f"MODEL_PRICES_JSON[{model!r}].{key} must be a number")
            try:
                d = Decimal(str(value))
            except InvalidOperation as exc:
                raise PriceConfigError(f"MODEL_PRICES_JSON[{model!r}].{key} is invalid") from exc
            if not d.is_finite() or d < 0:
                raise PriceConfigError(f"MODEL_PRICES_JSON[{model!r}].{key} must be >= 0")
            vals[key] = d
        out[str(model).strip()] = ModelPrice(
            in_=vals.get("in"),
            cached_in=vals.get("cached_in"),
            out=vals.get("out"),
            per_minute=vals.get("per_minute"),
        )
    return out


def required_keys(
    chat_models: list[str], embedding_model: str, stt_model: str
) -> dict[str, set[str]]:
    """Which price keys every configured model must have."""
    need: dict[str, set[str]] = {}
    for m in chat_models:
        need.setdefault(m, set()).update({"in", "out"})
    if embedding_model:
        need.setdefault(embedding_model, set()).add("in")
    if stt_model:
        need.setdefault(stt_model, set()).add("per_minute")
    return need


def price_problems(
    prices: dict[str, ModelPrice], need: dict[str, set[str]]
) -> tuple[list[str], list[str]]:
    """-> (missing, zero): models/keys without a price, and chat/embedding/stt prices that are 0."""
    missing: list[str] = []
    zero: list[str] = []
    attr = {"in": "in_", "cached_in": "cached_in", "out": "out", "per_minute": "per_minute"}
    for model, keys in sorted(need.items()):
        price = prices.get(model)
        for key in sorted(keys):
            value = getattr(price, attr[key]) if price is not None else None
            if value is None:
                missing.append(f"{model}.{key}")
            elif value == 0:
                zero.append(f"{model}.{key}")
    return missing, zero
