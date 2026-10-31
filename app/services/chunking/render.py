"""Turn the plugin's extracted JSON records into readable Persian text groups.

Everything that matters for answering questions (SKU, currency, rating, image counts, dates,
categories ...) is written out item by item in Persian. Raw metadata (image URLs, links, the
whole JSON) is NOT put in the text: it lives once in the `records` table and is joined back by
`record_id` at retrieval time.

Deliberately left out of the text: live data (price/stock - they must come from tools) and
technical site details (WordPress/PHP versions, theme, builders - useless and a leak risk
for a customer-facing bot).
"""

from __future__ import annotations

import html
import re
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation
from typing import Any

from app.core.errors import UnprocessableRecordError
from app.services.chunking.jalali import jalali_text
from app.services.chunking.splitter import Group

TAXONOMY_RECORD_KINDS = {"product_taxonomies", "blog_taxonomies", "pages_taxonomies"}


@dataclass
class Rendered:
    record_type: str  # stored in records.type / Qdrant payload.type
    title: str
    url: str | None
    groups: list[Group] = field(default_factory=list)


# ---------------------------------------------------------------- small helpers
_SHORTCODE = re.compile(r"\[/?[a-z][a-z0-9_-]*(?:\s[^\[\]]*)?\]")  # [vc_row], [gallery ids=..]
_BLOCK_TAG = re.compile(r"<(?:br|/p|/div|/li|/h[1-6])\b[^>]*>", re.IGNORECASE)
_TAG = re.compile(r"</?[A-Za-z][^<>]*>")


def clean_content(raw: Any) -> str:
    """Strip page-builder shortcodes and leftover HTML from post content."""
    if not raw:
        return ""
    t = _BLOCK_TAG.sub("\n", str(raw))
    t = _TAG.sub("", t)
    t = _SHORTCODE.sub("", t)
    return html.unescape(t).strip()


def _s(v: Any) -> str:
    return str(v).strip() if v is not None else ""


def _num(v: Any) -> str:
    try:
        return format(Decimal(str(v).strip()).normalize(), "f")
    except (InvalidOperation, ValueError):
        return _s(v)


def _as_int(v: Any) -> int | None:
    try:
        return int(Decimal(str(v)))
    except (InvalidOperation, ValueError, TypeError):
        return None


def _bool(v: Any) -> bool:
    return v is True or str(v).lower() in {"1", "true", "yes"}


def _lines(items: list[str]) -> str:
    return "\n".join(i for i in items if i)


_TAX_LABELS = {
    "product_cat": "دسته‌بندی",
    "category": "دسته‌بندی",
    "product_tag": "برچسب‌ها",
    "post_tag": "برچسب‌ها",
}


def _taxonomy_lines(d: dict) -> list[str]:
    out: list[str] = []
    taxonomies = d.get("taxonomies")
    if not isinstance(taxonomies, dict):
        return out
    for key, info in taxonomies.items():
        if not isinstance(info, dict):
            continue
        terms = [_s(t) for t in (info.get("terms") or []) if _s(t)]
        if terms:
            label = _TAX_LABELS.get(key) or _s(info.get("label")) or key
            out.append(f"{label}: " + "، ".join(terms))
    return out


_ROLE_LABELS = {"featured": "شاخص", "gallery": "گالری", "content": "داخل متن"}


def _images_line(d: dict) -> str:
    images = d.get("images")
    if not isinstance(images, list) or not images:
        return ""
    counts: dict[str, int] = {}
    for img in images:
        role = _s(img.get("role")) if isinstance(img, dict) else ""
        counts[role] = counts.get(role, 0) + 1
    parts = [f"{n} {_ROLE_LABELS.get(role, role or 'بدون نقش')}" for role, n in counts.items()]
    return f"تصاویر: {len(images)} تصویر ({'، '.join(parts)})"


def _date_lines(d: dict) -> list[str]:
    out = []
    published = jalali_text(d.get("date"))
    modified = jalali_text(d.get("modified"))
    if published:
        out.append(f"تاریخ انتشار: {published}")
    if modified and modified != published:
        out.append(f"آخرین به‌روزرسانی: {modified}")
    return out


def _flat(obj: Any) -> str:
    """Best-effort one-line rendering of an unknown dict/list (attributes, custom fields...)."""
    if isinstance(obj, dict):
        parts = []
        for k, v in obj.items():
            if any(x in str(k).lower() for x in ("url", "image", "link")) or v in (
                None,
                "",
                [],
                {},
            ):
                continue
            parts.append(f"{k}: {_flat(v)}")
        return "، ".join(parts)
    if isinstance(obj, list):
        return "، ".join(x for x in (_flat(i) for i in obj) if x)
    return _s(obj)


def _extra_lines(d: dict, *, skip_custom: set[str]) -> list[str]:
    """attributes / variations / custom_fields - shapes are not fixed, so render generically."""
    out: list[str] = []
    attrs = d.get("attributes")
    if isinstance(attrs, list) and attrs:
        out.append("ویژگی‌ها:")
        for a in attrs:
            if isinstance(a, dict):
                name = _s(a.get("name") or a.get("label"))
                vals = a.get("options") or a.get("values") or a.get("value")
                text = _flat(vals) if vals not in (None, "") else _flat(a)
                out.append(f"- {name}: {text}" if name else f"- {text}")
            elif _s(a):
                out.append(f"- {_s(a)}")
    variations = d.get("variations")
    if isinstance(variations, list) and variations:
        out.append("تنوع‌های محصول:")
        out.extend(f"- {_flat(v)}" for v in variations if _flat(v))
    custom = d.get("custom_fields")
    if isinstance(custom, dict):
        rest = {k: v for k, v in custom.items() if k not in skip_custom and v not in (None, "")}
        if rest:
            out.append("فیلدهای سفارشی:")
            out.extend(f"- {k}: {_flat(v)}" for k, v in rest.items())
    return out


# ---------------------------------------------------------------- product
_PRODUCT_TYPES = {
    "simple": "ساده",
    "variable": "متغیر (دارای تنوع)",
    "grouped": "گروهی",
    "external": "خارجی (وابسته)",
}
_CURRENCIES = {
    "IRT": "تومان",
    "IRR": "ریال",
    "USD": "دلار آمریکا",
    "EUR": "یورو",
    "AED": "درهم امارات",
    "TRY": "لیر ترکیه",
}


def _currency_text(code: str, symbol: str) -> str:
    name = symbol or _CURRENCIES.get(code.upper(), "")
    if name and code:
        return f"{name} ({code})"
    return name or code


def _product_specs(d: dict) -> list[str]:
    p = d.get("product") if isinstance(d.get("product"), dict) else {}
    items: list[str] = []
    if p.get("type"):
        items.append(f"نوع محصول: {_PRODUCT_TYPES.get(_s(p['type']), _s(p['type']))}")
    if p.get("sku"):
        items.append(f"کد محصول (SKU): {_s(p['sku'])}")
    currency = _currency_text(_s(p.get("currency")), _s(p.get("currency_symbol")))
    if currency:
        items.append(f"واحد پول قیمت‌ها: {currency}")
    if "virtual" in p or "downloadable" in p:
        virtual, downloadable = _bool(p.get("virtual")), _bool(p.get("downloadable"))
        if virtual or downloadable:
            kinds = [k for k, on in (("مجازی", virtual), ("قابل دانلود", downloadable)) if on]
            items.append(f"نوع تحویل: {' و '.join(kinds)} (ارسال فیزیکی ندارد)")
        else:
            items.append("نوع تحویل: محصول فیزیکی است (مجازی و دانلودی نیست)")
    if _bool(p.get("sold_individually")):
        items.append("محدودیت خرید: در هر سفارش فقط یک عدد از این محصول قابل خرید است")
    sales = _as_int(p.get("total_sales"))
    if sales is not None:
        items.append(f"تعداد فروش تاکنون: {sales} عدد")
    rating_count = _as_int(p.get("rating_count"))
    if p.get("rating") not in (None, "") and rating_count:
        items.append(f"امتیاز کاربران: {_num(p['rating'])} از 5 (بر پایه {rating_count} امتیاز)")
    elif rating_count == 0:
        items.append("امتیاز کاربران: هنوز امتیازی ثبت نشده است")
    review_count = _as_int(p.get("review_count"))
    if review_count is not None:
        items.append(f"تعداد دیدگاه‌های ثبت‌شده: {review_count}")
    for line in (_images_line(d),):
        if line:
            items.append(line)
    if d.get("author"):
        items.append(f"نویسنده/ثبت‌کننده: {_s(d['author'])}")
    items.extend(_date_lines(d))
    return items


def _review_paragraphs(reviews: list) -> list[str]:
    out = []
    for r in reviews:
        if not isinstance(r, dict):
            continue
        content = _s(r.get("content"))
        head = []
        rating = _as_int(r.get("rating"))
        if rating is not None:
            head.append(f"امتیاز {rating} از 5")
        when = jalali_text(r.get("date"))
        if when:
            head.append(when)
        if _s(r.get("author")):
            head.append(f"توسط {_s(r['author'])}")
        label = "دیدگاه کاربر" + (f" ({'، '.join(head)})" if head else "")
        out.append(f"{label}: {content}" if content else f"{label}: (بدون متن)")
    return out


def _render_product(d: dict) -> Rendered:
    title = _s(d.get("title"))
    paras: list[str] = []
    pre = _taxonomy_lines(d)
    if pre:
        paras.append(_lines(pre))
    if _s(d.get("excerpt")):
        paras.append("توضیح کوتاه: " + clean_content(d["excerpt"]))
    specs = [f"- {item}" for item in _product_specs(d)]
    specs.extend(_extra_lines(d, skip_custom={"total_sales"}))
    if specs:
        paras.append("\n".join(["مشخصات محصول:", *specs]))
    content = clean_content(d.get("content"))
    if content:
        paras.append(content)

    groups = [Group("main", f"محصول: {title}", "\n\n".join(paras))]
    reviews = _review_paragraphs(d.get("reviews") if isinstance(d.get("reviews"), list) else [])
    if reviews:
        groups.append(
            Group(
                "reviews",
                f"دیدگاه‌های کاربران درباره محصول «{title}»",
                "\n\n".join(reviews),
                overlap=False,
            )  # fmt: skip
        )
    return Rendered("product", title, _s(d.get("url")) or None, groups)


# ---------------------------------------------------------------- post / page / generic
_POST_LABELS = {"post": "مقاله", "page": "برگه", "product": "محصول"}


def _render_post(d: dict, fallback_type: str) -> Rendered:
    rtype = _s(d.get("type")) or fallback_type
    title = _s(d.get("title"))
    label = _POST_LABELS.get(rtype, "محتوا")
    pre = _taxonomy_lines(d)
    if d.get("author"):
        pre.append(f"نویسنده: {_s(d['author'])}")
    pre.extend(_date_lines(d))
    images = _images_line(d)
    if images:
        pre.append(images)
    paras: list[str] = []
    if pre:
        paras.append(_lines(pre))
    if _s(d.get("excerpt")):
        paras.append("خلاصه: " + clean_content(d["excerpt"]))
    content = clean_content(d.get("content"))
    if content:
        paras.append(content)
    extras = _extra_lines(d, skip_custom=set())
    if extras:
        paras.append("\n".join(extras))
    return Rendered(
        rtype,
        title,
        _s(d.get("url")) or None,
        [Group("main", f"{label}: {title}", "\n\n".join(paras))],
    )  # noqa: E501


# ---------------------------------------------------------------- taxonomy index
_TAX_SUBJECT = {
    "product": ("دسته‌بندی‌ها و برچسب‌های محصولات فروشگاه", "محصول"),
    "blog": ("دسته‌بندی‌ها و برچسب‌های وبلاگ", "نوشته"),
    "pages": ("دسته‌بندی‌ها و برچسب‌های برگه‌ها", "برگه"),
}


def _render_taxonomies(d: dict) -> Rendered:
    category = _s(d.get("category"))
    title, unit = _TAX_SUBJECT.get(category, (f"دسته‌بندی‌ها و برچسب‌ها ({category})", "مورد"))
    sections: list[str] = []
    for tax in d.get("taxonomies") or []:
        if not isinstance(tax, dict):
            continue
        terms = [t for t in (tax.get("terms") or []) if isinstance(t, dict)]
        total = _as_int(tax.get("total"))
        lines = [f"## {_s(tax.get('label')) or _s(tax.get('taxonomy'))}"]
        if not terms:
            lines.append("هنوز موردی ثبت نشده است.")
        else:
            lines.append(f"تعداد کل: {total if total is not None else len(terms)} مورد")
            for t in terms:
                name = _s(t.get("path")) or _s(t.get("name"))
                count = _as_int(t.get("count"))
                tail = "" if count is None else (f": {count} {unit}" if count else f": بدون {unit}")
                lines.append(f"- {name}{tail}")
            if _bool(tax.get("truncated")):
                lines.append("(فهرست کامل نیست و بخشی از موارد نمایش داده نشده است)")
        sections.append("\n".join(lines))
    return Rendered("taxonomies", title, None, [Group("main", title, "\n\n".join(sections))])


# ---------------------------------------------------------------- site
_LANGS = {"fa_IR": "فارسی (ایران)", "en_US": "انگلیسی (آمریکا)", "en_GB": "انگلیسی (بریتانیا)"}


def _render_site(d: dict) -> Rendered:
    name = _s(d.get("name"))
    items = [f"نام وب‌سایت: {name}"]
    if _s(d.get("tagline")):
        items.append(f"شعار: {_s(d['tagline'])}")
    if _s(d.get("url")):
        items.append(f"نشانی وب‌سایت: {_s(d['url'])}")
    if _s(d.get("language")):
        items.append(f"زبان وب‌سایت: {_LANGS.get(_s(d['language']), _s(d['language']))}")
    shop = d.get("woocommerce")
    if isinstance(shop, dict):
        if _bool(shop.get("active")):
            cur = _currency_text(_s(shop.get("currency")), "")
            items.append("فروشگاه اینترنتی: دارد" + (f"، واحد پول: {cur}" if cur else ""))
        else:
            items.append("فروشگاه اینترنتی: ندارد")
    types = [t for t in (d.get("post_types") or []) if isinstance(t, dict)]
    if types:
        items.append("محتوای وب‌سایت:")
        items.extend(
            f"- {_s(t.get('label')) or _s(t.get('name'))}: {_as_int(t.get('count')) or 0} مورد"
            for t in types
        )
    return Rendered(
        "site", name, _s(d.get("url")) or None, [Group("main", f"وب‌سایت: {name}", "\n".join(items))]
    )  # noqa: E501


# ---------------------------------------------------------------- dispatch
def render_record(record_id: str, data: dict) -> Rendered:
    kind = record_id.split(":", 1)[0]
    if kind in TAXONOMY_RECORD_KINDS or (
        isinstance(data.get("taxonomies"), list) and "category" in data
    ):
        rendered = _render_taxonomies(data)
    elif kind == "site":
        rendered = _render_site(data)
    elif kind == "product" or _s(data.get("type")) == "product":
        rendered = _render_product(data)
    else:
        rendered = _render_post(data, fallback_type=kind)
    if not any(g.text.strip() for g in rendered.groups) and not rendered.title:
        raise UnprocessableRecordError("Record has no usable text (empty title and content)")
    return rendered
