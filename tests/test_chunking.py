from __future__ import annotations

import copy

import pytest

from app.core.errors import UnprocessableRecordError
from app.services.chunking.jalali import jalali_text
from app.services.chunking.normalize import ZWNJ, normalize_text
from app.services.chunking.pipeline import build_plan
from app.services.chunking.splitter import ChunkConfig
from app.services.chunking.tokens import TokenCounter
from tests.samples import BLOG, PAGE, PRODUCT, SITE, TAXONOMIES

CFG = ChunkConfig(target_tokens=400, max_tokens=600, overlap_tokens=60, min_tokens=80)
COUNTER = TokenCounter.approximate()  # tiktoken needs the network for its first download


def plan(record_id, data, cfg=CFG):
    return build_plan(record_id, copy.deepcopy(data), COUNTER, cfg)


# ---------------------------------------------------------------- normalization
def test_normalize_letters_digits_and_spaces():
    assert normalize_text("كتاب ي ۱۲۳ ٤٥٦") == "کتاب ی 123 456"
    assert normalize_text("٣٫٥ و ١٬٠٠٠") == "3.5 و 1,000"
    assert normalize_text("a  b \r\n\r\n\r\n\r\nc") == "a b\n\nc"


def test_normalize_half_space():
    assert normalize_text("می رود و نمی دانم") == f"می{ZWNJ}رود و نمی{ZWNJ}دانم"
    assert normalize_text("گیاه ها و این ها") == f"گیاه{ZWNJ}ها و این{ZWNJ}ها"
    assert normalize_text(f"کتاب{ZWNJ}{ZWNJ}ها {ZWNJ}x") == f"کتاب{ZWNJ}ها x"
    assert normalize_text("سلام‍‏") == "سلام"  # invisible joiners/marks are removed
    assert normalize_text("کاملاً") == "کاملا"  # diacritics dropped


def test_normalize_empty():
    assert normalize_text(None) == "" and normalize_text("  ") == ""


def test_jalali():
    assert jalali_text("2025-11-04T06:50:00+03:30") == "13 آبان 1404"
    assert jalali_text("2025-03-21") == "1 فروردین 1404"
    assert jalali_text("garbage") == ""


# ---------------------------------------------------------------- rendering
def test_product_single_main_chunk_plus_reviews_chunk():
    p = plan("product:13199", PRODUCT)
    assert [c.kind for c in p.chunks] == ["main", "reviews"]
    assert [c.index for c in p.chunks] == [0, 1]
    assert [c.chunk_id for c in p.chunks] == ["product:13199#0", "product:13199#1"]
    main, reviews = p.chunks[0].text, p.chunks[1].text

    # title + category + short description + content (+ specs) in the first chunk
    for expected in (
        "محصول: آلوئه آریستاتا",
        "دسته‌بندی: کاکتوس و ساکولنت",
        "توضیح کوتاه:",
        "آلوئه آریستاتا از گونه‌های کوچک",
        "کد محصول (SKU): SB-101",
        "واحد پول قیمت‌ها: تومان (IRT)",
        "تعداد فروش تاکنون: 38 عدد",
        "امتیاز کاربران: 4.5 از 5 (بر پایه 2 امتیاز)",
        "تصاویر: 3 تصویر (1 شاخص، 2 گالری)",
        "تاریخ انتشار: 13 آبان 1404",
        "بخش: راهنمای نگهداری",
    ):
        assert expected in main, expected
    # reviews are separate, with their own context line, rating and Jalali date
    assert reviews.startswith("دیدگاه‌های کاربران درباره محصول «آلوئه آریستاتا»")
    assert "امتیاز 4 از 5" in reviews and "30 آذر 1404" in reviews
    # raw metadata stays in the records table, not in the embedded text
    assert "http" not in main and ".jpg" not in main and "http" not in reviews
    assert p.title == "آلوئه آریستاتا" and p.record_type == "product"
    assert p.url == PRODUCT["url"]


def test_product_without_reviews_or_category():
    data = copy.deepcopy(PRODUCT)
    data.update(reviews=[], taxonomies={}, excerpt="", images=[])
    p = plan("product:1", data)
    assert [c.kind for c in p.chunks] == ["main"]
    assert "دسته‌بندی" not in p.chunks[0].text and "تصاویر" not in p.chunks[0].text


def test_blog_and_page_rendering():
    blog = plan("blog:15650", BLOG)
    assert blog.record_type == "post"
    assert "مقاله: بهترین گیاهان" in blog.chunks[0].text
    assert "برچسب‌ها: گیاه آپارتمانی، نور گیاه" in blog.chunks[0].text

    page = plan("page:15434", PAGE)
    text = page.chunks[0].text
    assert "[vc_" not in text and "[/vc_" not in text  # page-builder shortcodes stripped
    assert "بخش: چه اطلاعات شخصی را جمع‌آوری می‌کنیم و چرا › دیدگاه‌ها" in text


def test_taxonomies_and_site():
    tax = plan("product_taxonomies:all", TAXONOMIES)
    t = tax.chunks[0].text
    assert tax.record_type == "taxonomies" and tax.url is None
    assert "گلدان‌ها: 4 محصول" in t and "دسته‌بندی نشده: بدون محصول" in t
    assert "هنوز موردی ثبت نشده است" in t  # empty tag taxonomy

    site = plan("site:1", SITE).chunks[0].text
    assert "نام وب‌سایت: گلخانه سبزینه" in site and "فارسی (ایران)" in site
    assert "محصولات: 15 مورد" in site
    assert "6.2.15" not in site and "8.2.12" not in site and "Lukani" not in site  # tech details


def test_unusable_record_rejected():
    with pytest.raises(UnprocessableRecordError):
        plan("blog:1", {"type": "post", "title": "", "content": ""})


# ---------------------------------------------------------------- splitting
def _long_page(paragraphs: int, sections: int = 1) -> dict:
    parts = []
    for s in range(sections):
        parts.append(f"## بخش شماره {s + 1}")
        for i in range(paragraphs):
            sentences = " ".join(
                f"این جمله شماره {j} از پاراگراف {i} در بخش {s} درباره نگهداری و آبیاری گیاهان است."
                for j in range(4)
            )
            parts.append(sentences)
    return {"type": "page", "title": "راهنمای بلند", "content": "\n\n".join(parts)}


def test_long_text_is_chunked_within_limits_with_overlap_and_full_coverage():
    data = _long_page(paragraphs=30)
    p = plan("page:9", data)
    assert len(p.chunks) > 5
    assert all(c.tokens <= CFG.max_tokens for c in p.chunks)
    assert all(c.text.startswith("برگه: راهنمای بلند") for c in p.chunks)  # context on every chunk
    assert all("بخش: بخش شماره 1" in c.text for c in p.chunks)

    # nothing lost: every sentence of the source appears in at least one chunk
    joined = "\n".join(c.text for c in p.chunks)
    for i in range(30):
        for j in range(4):
            assert f"جمله شماره {j} از پاراگراف {i} در" in joined

    # overlap: a chunk that continues inside the same section repeats the previous chunk's tail
    overlaps = 0
    for prev, nxt in zip(p.chunks, p.chunks[1:], strict=False):
        last_sentence = prev.text.rstrip().rsplit(". ", 1)[-1].rstrip(".")
        if last_sentence and last_sentence in nxt.text:
            overlaps += 1
    assert overlaps >= len(p.chunks) // 2


def test_sections_start_new_chunks_and_small_tail_is_merged():
    data = _long_page(paragraphs=5, sections=4)
    p = plan("page:10", data)
    assert len(p.chunks) >= 2
    # a chunk never starts with a tiny orphan: every chunk (but maybe the tail) is substantial
    assert all(c.tokens >= CFG.min_tokens for c in p.chunks)
    for c in p.chunks:
        assert "بخش: بخش شماره" in c.text


def test_giant_paragraph_without_punctuation_and_without_spaces():
    words = " ".join(f"کلمه{i}" for i in range(3000))
    p = plan("blog:11", {"type": "post", "title": "t", "content": words})
    assert len(p.chunks) > 3 and all(c.tokens <= CFG.max_tokens for c in p.chunks)

    blob = "الف" * 9000  # no separators at all
    p2 = plan("blog:12", {"type": "post", "title": "t", "content": blob})
    assert len(p2.chunks) > 3 and all(c.tokens <= CFG.max_tokens for c in p2.chunks)
    assert sum(c.text.count("ف") for c in p2.chunks) == 9000  # hard cuts lose nothing


def test_many_reviews_are_grouped_into_review_chunks_without_overlap():
    data = copy.deepcopy(PRODUCT)
    data["reviews"] = [
        {
            "rating": 5,
            "content": f"نظر شماره {i} " + "خیلی خوب بود و راضی بودم. " * 6,
            "date": "2025-11-16",
        }
        for i in range(40)
    ]
    p = plan("product:2", data)
    reviews = [c for c in p.chunks if c.kind == "reviews"]
    assert len(reviews) > 2
    assert all(c.tokens <= CFG.max_tokens for c in reviews)
    for i in range(40):  # each review appears exactly once (no overlap duplication)
        assert sum(f"نظر شماره {i} " in c.text for c in reviews) == 1


def test_plan_is_deterministic():
    a = plan("product:13199", PRODUCT)
    b = plan("product:13199", PRODUCT)
    assert [(c.chunk_id, c.hash) for c in a.chunks] == [(c.chunk_id, c.hash) for c in b.chunks]
