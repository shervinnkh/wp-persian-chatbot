# اعمال نسخهٔ 0.4.0 (متن‌ها + فایل‌ها)

این zip **کل پروژه** است (روی همان نسخهٔ 0.3.3 شما ساخته شده).

1. پروژهٔ فعلی را کنار بگذار (بکاپ) و این zip را به‌جای آن اکستراکت کن. اگر `.env` و پوشهٔ `data/` داری، همان‌ها را نگه دار (در zip نیستند).
2. به `.env` این خطوط را اضافه کن (نمونه در `.env.example` هست):
   ```
   FILES_BASE_URL=http://aiwpassistant.local/
   FILES_MAX_MB=100
   ```
3. `run.bat` را اجرا کن. چون وابستگی‌های جدید (httpx, pdfplumber, lxml) اضافه شده، خودش `pip install -r requirements.txt` می‌زند.
   (اگر نزد، یک‌بار `run.bat install`.)
4. دامنهٔ `aiwpassistant.local` باید روی همین ویندوز resolve شود (فایل hosts) تا دانلود کار کند.

## endpointها (همه امضادار، زیر `/v1/knowledge`)
| | افزودن | ویرایش (اول حذف، بعد افزودن) | حذف |
|---|---|---|---|
| متن‌ها | `POST /texts` | `PUT /texts` | `DELETE /texts` |
| فایل‌ها | `POST /files` | `PUT /files` | `DELETE /files` |

بدنهٔ افزودن/ویرایش فایل: لیست `[{"id":"file:1793261168782","title":"تست","file_orginal_name":"x.pdf","path":"wp-content/uploads/2026/10/x.pdf"}]`
بدنهٔ متن: `[{"id":"text:1793259417135","data":{"type":"text","chunks":["..."],"text":"..."}}]`
حذف: `{"ids":["file:1793261168782"]}`

## تست دستی (سرور روشن)
```
python scripts\kb_files.py files add files.json
python scripts\kb_files.py files update files.json
python scripts\kb_files.py files delete file:1793261168782
python scripts\kb_files.py texts add texts.json
python scripts\kb_show.py file:1793261168782        # چانک‌ها + نقطه‌های Qdrant + in_sync
```
لاگ‌ها در `logs/`: `content.*`، `files.*`، `chunker.document`، `knowledge.*`.
