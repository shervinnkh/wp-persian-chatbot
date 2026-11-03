
from __future__ import annotations

import re
import zipfile
from pathlib import Path
from typing import Any, Iterator

from common import Block, ExtractionError, build_result, check_input_file, run_cli
from lxml import etree

EXTRACTOR_NAME = "docx_extractor"
MAX_UNCOMPRESSED_BYTES = 500 * 1024 * 1024  # محافظ zip-bomb
 
W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
MC = "{http://schemas.openxmlformats.org/markup-compatibility/2006}"
_XML_SPACE = "{http://www.w3.org/XML/1998/namespace}space"
_HEADING_NAME_RE = re.compile(r"^(?:heading|عنوان)\s*([1-9])$")
_OLE_MAGIC = b"\xd0\xcf\x11\xe0"
 
 
# --------------------------------------------------------------------------- #
# سبک‌ها
# --------------------------------------------------------------------------- #
 
 
class _Styles:
    def __init__(self, styles_xml: bytes | None) -> None:
        self.by_id: dict[str, dict[str, Any]] = {}
        if not styles_xml:
            return
        root = etree.fromstring(styles_xml)
        for st in root.iter(W + "style"):
            sid = st.get(W + "styleId")
            if not sid:
                continue
            name_el = st.find(W + "name")
            based_el = st.find(W + "basedOn")
            outline = st.find(f"{W}pPr/{W}outlineLvl")
            self.by_id[sid] = {
                "name": (name_el.get(W + "val") if name_el is not None else sid).lower(),
                "based": based_el.get(W + "val") if based_el is not None else None,
                "outline": int(outline.get(W + "val")) if outline is not None and outline.get(W + "val", "").isdigit() else None,
            }
 
    def chain(self, sid: str | None) -> Iterator[dict[str, Any]]:
        seen = 0
        while sid and sid in self.by_id and seen < 8:
            yield self.by_id[sid]
            sid = self.by_id[sid]["based"]
            seen += 1
 
    def name(self, sid: str | None) -> str:
        return self.by_id.get(sid or "", {}).get("name", sid or "")
 
    def heading_level(self, sid: str | None) -> int | None:
        for st in self.chain(sid):
            if st["outline"] is not None:
                return st["outline"] + 1 if st["outline"] < 9 else None
            m = _HEADING_NAME_RE.match(st["name"])
            if m:
                return int(m.group(1))
            if st["name"] == "title":
                return 1
        return None
 
 
# --------------------------------------------------------------------------- #
# متن پاراگراف
# --------------------------------------------------------------------------- #
 
 
def _para_text(p: etree._Element) -> tuple[str, list[etree._Element]]:
    """متن پاراگراف + المان‌های textbox داخل آن (جدا پردازش می‌شوند تا تکرار نشوند)."""
    parts: list[str] = []
    textboxes: list[etree._Element] = []
 
    def walk(el: etree._Element) -> None:
        for child in el:
            tag = child.tag
            if tag == W + "t":
                parts.append(child.text or "")
            elif tag == W + "tab":
                parts.append("\t")
            elif tag in (W + "br", W + "cr"):
                if child.get(W + "type") in (None, "textWrapping"):
                    parts.append("\n")
            elif tag == W + "noBreakHyphen":
                parts.append("-")
            elif tag == W + "txbxContent":
                textboxes.append(child)
            elif tag in (MC + "Fallback", W + "delText", W + "instrText", W + "pPr", W + "rPr"):
                continue
            elif not isinstance(tag, str):  # comment / PI
                continue
            else:
                walk(child)
 
    walk(p)
    return "".join(parts), textboxes
 
 
def _iter_blocks(container: etree._Element) -> Iterator[tuple[str, etree._Element]]:
    for child in container:
        tag = child.tag
        if tag == W + "p":
            yield "p", child
        elif tag == W + "tbl":
            yield "tbl", child
        elif tag in (W + "sdt", W + "sdtContent", W + "customXml", W + "ins", W + "smartTag"):
            inner = child.find(W + "sdtContent") if tag == W + "sdt" else child
            if inner is not None:
                yield from _iter_blocks(inner)
 
 
def _norm_ws(s: str) -> str:
    return " ".join(s.replace("\t", " ").split())
 
 
# --------------------------------------------------------------------------- #
# پردازش
# --------------------------------------------------------------------------- #
 
 
class _Walker:
    def __init__(self, styles: _Styles) -> None:
        self.styles = styles
        self.blocks: list[Block] = []
 
    def paragraph(self, p: etree._Element, *, source: str | None = None) -> None:
        text, textboxes = _para_text(p)
        ppr = p.find(W + "pPr")
        sid = None
        direct_outline = None
        numbered = False
        if ppr is not None:
            ps = ppr.find(W + "pStyle")
            sid = ps.get(W + "val") if ps is not None else None
            ol = ppr.find(W + "outlineLvl")
            if ol is not None and ol.get(W + "val", "").isdigit():
                direct_outline = int(ol.get(W + "val"))
            num = ppr.find(f"{W}numPr/{W}numId")
            numbered = num is not None and num.get(W + "val") not in (None, "0")
 
        style_name = self.styles.name(sid)
        meta = {"source": source} if source else {}
 
        if style_name.startswith(("toc", "table of figures")):
            pass  # فهرست مطالب = نویز برای RAG
        elif text.strip():
            lines = [ln for ln in (_norm_ws(x) for x in text.split("\n")) if ln]
            level = (direct_outline + 1 if direct_outline is not None and direct_outline < 9 else None) or self.styles.heading_level(sid)
            if direct_outline == 9:
                level = None
            if lines and level:
                self.blocks.append(Block("heading", [" ".join(lines)], level=min(level, 6), meta=meta))
            elif lines and (numbered or "list" in style_name):
                self.blocks.append(Block("list_item", lines, join="\n", meta=meta))
            elif lines:
                self.blocks.append(Block("paragraph", lines, join="\n", meta=meta))
 
        for tb in textboxes:
            for kind, el in _iter_blocks(tb):
                if kind == "p":
                    self.paragraph(el, source="textbox")
                else:
                    self.table(el)
 
    def table(self, tbl: etree._Element) -> None:
        rows: list[str] = []
        for tr in tbl.findall(W + "tr"):
            cells: list[str] = []
            for tc in tr.findall(W + "tc"):
                parts: list[str] = []
                for kind, el in _iter_blocks(tc):
                    if kind == "p":
                        t, _ = _para_text(el)
                        t = _norm_ws(t)
                        if t:
                            parts.append(t)
                    else:  # جدول تو در تو
                        parts.append(self._flat_table(el))
                cells.append(" ".join(x for x in parts if x))
            if any(cells):
                rows.append(" | ".join(cells))
        if rows:
            self.blocks.append(Block("table", rows, join="\n"))
 
    def _flat_table(self, tbl: etree._Element) -> str:
        out = []
        for tr in tbl.iter(W + "tr"):
            cells = [_norm_ws("".join(t.text or "" for t in tc.iter(W + "t"))) for tc in tr.findall(W + "tc")]
            if any(cells):
                out.append(" | ".join(cells))
        return "; ".join(out)
 
    def run(self, body: etree._Element) -> None:
        for kind, el in _iter_blocks(body):
            if kind == "p":
                self.paragraph(el)
            else:
                self.table(el)
 
 
def _read_notes(zf: zipfile.ZipFile, name: str, tag: str) -> list[Block]:
    if name not in zf.namelist():
        return []
    root = etree.fromstring(zf.read(name))
    blocks: list[Block] = []
    for note in root.iter(W + tag):
        if note.get(W + "type") in ("separator", "continuationSeparator", "continuationNotice"):
            continue
        texts = [_norm_ws(_para_text(p)[0]) for p in note.iter(W + "p")]
        text = " ".join(t for t in texts if t)
        if text:
            blocks.append(Block("footnote", [text], meta={"note_id": note.get(W + "id")}))
    return blocks
 
 
# --------------------------------------------------------------------------- #
# API
# --------------------------------------------------------------------------- #
 
 
def extract_docx(path: str | Path, *, include_notes: bool = True) -> dict[str, Any]:
    p = check_input_file(path, (".docx", ".docm"))
    with p.open("rb") as f:
        magic = f.read(4)
    if magic == _OLE_MAGIC:
        raise ExtractionError(
            "unsupported_or_encrypted",
            "This is a legacy .doc file or a password-protected document. Convert/save it as .docx first.",
        )
    try:
        zf = zipfile.ZipFile(p)
    except zipfile.BadZipFile as exc:
        raise ExtractionError("corrupt_file", "The file is not a valid .docx (Word) document.") from exc
 
    with zf:
        total = sum(i.file_size for i in zf.infolist())
        if total > MAX_UNCOMPRESSED_BYTES:
            raise ExtractionError("file_too_large", "The document expands to an unreasonable size.")
        if "word/document.xml" not in zf.namelist():
            raise ExtractionError("corrupt_file", "word/document.xml is missing; not a valid .docx.")
        try:
            styles_xml = zf.read("word/styles.xml") if "word/styles.xml" in zf.namelist() else None
            doc_root = etree.fromstring(zf.read("word/document.xml"))
            body = doc_root.find(W + "body")
            if body is None:
                raise ExtractionError("corrupt_file", "Document body is missing.")
            walker = _Walker(_Styles(styles_xml))
            walker.run(body)
            blocks = walker.blocks
            if include_notes:
                blocks += _read_notes(zf, "word/footnotes.xml", "footnote")
                blocks += _read_notes(zf, "word/endnotes.xml", "endnote")
        except etree.XMLSyntaxError as exc:
            raise ExtractionError("corrupt_file", "The document XML is damaged.") from exc
 
    return build_result(blocks, path=p, extractor=EXTRACTOR_NAME, library="lxml (direct OOXML)")
 
 
def main(argv: list[str] | None = None) -> int:
    return run_cli(
        extract_docx,
        description="استخراج متن از DOCX → JSON (خط‌به‌خط + پاراگراف)",
        exts=(".docx",),
        add_args=lambda ap: ap.add_argument("--no-notes", action="store_true", help="پاورقی/پایان‌نوشت وارد نشود"),
        kwargs_from_args=lambda a: {"include_notes": not a.no_notes},
        argv=argv,
    )
 
 
if __name__ == "__main__":
    raise SystemExit(main())