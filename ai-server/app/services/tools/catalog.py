"""The tools the AI may call for one visitor message, and their execution.

Two kinds (both are plain OpenAI function-calling tools):

  * default  - `get_product_live_info`: live price / stock of products. Offered ONLY when the site
               said it has live product data (`live_product_datas` of the request is not empty);
               the described and fetched fields are exactly those the site allowed.
  * dynamic  - `site_tool_<id>`: one per tool the site manager defined (title + "when to call
               it"). No arguments; the site returns the tool's current text by id.

`ToolKit.run` never raises: a failed call becomes a short error text that goes back to the model
as the tool result, so the answer is still produced (the model must then say it has no data).
"""

from __future__ import annotations

import asyncio
import json
import logging
import re
import time
from collections.abc import Sequence
from dataclasses import dataclass, field

from app.core.config import Settings
from app.core.monitoring import emit
from app.services.tools.client import SiteToolClient, ToolCallError

logger = logging.getLogger(__name__)

PRODUCT_TOOL_NAME = "get_product_live_info"
DYNAMIC_TOOL_PREFIX = "site_tool_"

_FIELD_TEXT = {
    "price": "price (regular price, sale price / whether the product is on sale)",
    "stock": "stock (in stock / out of stock, stock quantity, backorder / pre-order)",
}
_DIGITS = re.compile(r"(\d+)\s*$")
_NAME_SAFE = re.compile(r"[^A-Za-z0-9_-]")


@dataclass(frozen=True)
class DynamicTool:
    id: int | str
    title: str
    description: str = ""


def product_tool_definition(fields: Sequence[str], *, max_products: int) -> dict:
    live = "; ".join(_FIELD_TEXT[f] for f in fields if f in _FIELD_TEXT)
    return {
        "type": "function",
        "function": {
            "name": PRODUCT_TOOL_NAME,
            "description": (
                "Get LIVE, up-to-date data of products from the website: " + live + ". "
                "Call it whenever the visitor asks about "
                + ", ".join(
                    {
                        "price": "the price or whether a product is on sale / discounted",
                        "stock": "whether a product is available, how many are left, or "
                        "whether it can be pre-ordered",
                    }[f]
                    for f in fields
                    if f in _FIELD_TEXT
                )
                + ". Identify each product by its id, taken from the `id` attribute of the "
                "knowledge sources (e.g. id=\"product:23\" -> 23) or from the current-page info. "
                "Only when no id is known, send the product's name instead (the website searches "
                "by name, less reliable). Ask several products in ONE call."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "products": {
                        "type": "array",
                        "minItems": 1,
                        "maxItems": max_products,
                        "description": "Products to look up.",
                        "items": {
                            "type": "object",
                            "properties": {
                                "id": {
                                    "type": ["integer", "string"],
                                    "description": "Product id (preferred), e.g. 23.",
                                },
                                "name": {
                                    "type": "string",
                                    "description": "Product name; ONLY when the id is unknown.",
                                },
                            },
                            "additionalProperties": False,
                        },
                    }
                },
                "required": ["products"],
                "additionalProperties": False,
            },
        },
    }


def dynamic_tool_definition(name: str, tool: DynamicTool) -> dict:
    when = f" {tool.description}" if tool.description else ""
    return {
        "type": "function",
        "function": {
            "name": name,
            "description": f"{tool.title}.{when}".strip()[:1000],
            "parameters": {"type": "object", "properties": {}, "additionalProperties": False},
        },
    }


def _clean_product(raw: object) -> dict | None:
    if not isinstance(raw, dict):
        return None
    out: dict = {}
    pid = raw.get("id")
    if isinstance(pid, bool):
        pid = None
    if isinstance(pid, int) and pid > 0:
        out["id"] = pid
    elif isinstance(pid, str) and (m := _DIGITS.search(pid.strip())):
        out["id"] = int(m.group(1))  # "product:23" -> 23
    name = raw.get("name")
    if isinstance(name, str) and name.strip():
        out["name"] = " ".join(name.split())[:200]
    return out or None


@dataclass
class ToolKit:
    settings: Settings
    client: SiteToolClient
    product_fields: list[str] = field(default_factory=list)
    dynamic: dict[str, DynamicTool] = field(default_factory=dict)  # function name -> tool
    definitions: list[dict] = field(default_factory=list)

    @classmethod
    def build(
        cls,
        settings: Settings,
        client: SiteToolClient,
        *,
        live_product_datas: Sequence[str] | None,
        tools: Sequence[dict] | None,
    ) -> ToolKit:
        kit = cls(settings=settings, client=client)
        if not client.configured:
            if live_product_datas or tools:
                logger.warning("tools.disabled reason=PLUGIN_BASE_URL_empty")
            return kit
        fields = [f for f in (live_product_datas or []) if f in _FIELD_TEXT]
        if fields:
            kit.product_fields = fields
            kit.definitions.append(
                product_tool_definition(fields, max_products=settings.TOOLS_MAX_PRODUCTS_PER_CALL)
            )
        for raw in list(tools or [])[: settings.TOOLS_MAX_DYNAMIC]:
            try:
                tool = DynamicTool(
                    id=raw["id"],
                    title=str(raw.get("title") or "").strip(),
                    description=str(raw.get("description") or "").strip(),
                )
            except (KeyError, TypeError, AttributeError):
                continue
            if not tool.title:
                continue
            name = DYNAMIC_TOOL_PREFIX + _NAME_SAFE.sub("_", str(tool.id))[:40]
            if name in kit.dynamic:
                continue
            kit.dynamic[name] = tool
            kit.definitions.append(dynamic_tool_definition(name, tool))
        return kit

    @property
    def enabled(self) -> bool:
        return bool(self.definitions)

    @property
    def has_product_tool(self) -> bool:
        return bool(self.product_fields)

    # ------------------------------------------------------------------ execution
    async def run_all(self, calls: Sequence[tuple[str, str, str]]) -> list[str]:
        """[(call_id, name, raw_json_arguments)] -> one result text per call, same order.
        Calls beyond TOOLS_MAX_CALLS_PER_ROUND are answered with a "not executed" text."""
        limit = self.settings.TOOLS_MAX_CALLS_PER_ROUND
        tasks = [self.run(name, args) for _id, name, args in calls[:limit]]
        results = list(await asyncio.gather(*tasks)) if tasks else []
        results += ["Not executed: too many tool calls in one step."] * (len(calls) - len(results))
        return results

    async def run(self, name: str, raw_args: str) -> str:
        t0 = time.monotonic()
        try:
            text = await self._dispatch(name, raw_args)
            status = "ok"
        except ToolCallError as exc:
            text, status = f"ERROR: {exc}. The information is not available right now.", "error"
        except Exception:  # noqa: BLE001 - a tool must never break the answer
            logger.exception("tools.crashed name=%s", name)
            text, status = "ERROR: the information is not available right now.", "crashed"
        limit = self.settings.TOOLS_MAX_RESULT_CHARS
        if len(text) > limit:
            text = text[:limit].rstrip() + "\n[...cut: result too long]"
        logger.info(
            "tools.call name=%s status=%s chars=%d ms=%d",
            name, status, len(text), (time.monotonic() - t0) * 1000,
        )  # fmt: skip
        is_product = name == PRODUCT_TOOL_NAME
        emit(
            "tool.call", tool=name, status=status, chars=len(text),
            kind="product" if is_product else "dynamic",
            tool_id=None if is_product else name.removeprefix(DYNAMIC_TOOL_PREFIX),
            ms=(time.monotonic() - t0) * 1000,
        )  # fmt: skip
        return text

    async def _dispatch(self, name: str, raw_args: str) -> str:
        if name == PRODUCT_TOOL_NAME and self.has_product_tool:
            try:
                args = json.loads(raw_args or "{}")
            except json.JSONDecodeError as exc:
                raise ToolCallError("the tool arguments were not valid JSON") from exc
            items = args.get("products") if isinstance(args, dict) else None
            products = [p for p in (_clean_product(x) for x in (items or [])) if p]
            products = products[: self.settings.TOOLS_MAX_PRODUCTS_PER_CALL]
            if not products:
                raise ToolCallError("no product id or name was given")
            return await self.client.product_info(fields=self.product_fields, products=products)
        tool = self.dynamic.get(name)
        if tool is not None:
            return await self.client.dynamic_tool(tool.id)
        raise ToolCallError("unknown tool")
