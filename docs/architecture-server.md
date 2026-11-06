# معماری سرور (ai-server)

سرور هوش مصنوعی «چت بات هوشمند» — FastAPI، نسخه `1.0.0`. دانش سایت را در پایگاه برداری ذخیره می‌کند (RAG) و پاسخ چت را با LLM می‌سازد.

## جریان کلی

```
افزونه وردپرس ──(HMAC)──▶ API ──▶ صف ──▶ chunk → embedding → Qdrant + DB
                                  └─▶ پیام چت → RAG + ابزارها → LLM → پاسخ
سرور ──(وب‌هوک HMAC)──▶ افزونه   (نتیجه پردازش، پیام ثبت‌شد، پاسخ چت)
```

## ساختار `app/`

| مسیر | نقش |
|---|---|
| `main.py`, `__main__.py` | ساخت اپ، middleware، اجرای `python -m app` |
| `api/v1/` | endpointها: `knowledge`، `messaging`، `jobs`، `logs`، `monitoring`، `realtime` (WebSocket)، `storage`، `system`، `health` |
| `core/` | تنظیمات (`config.py`)، امنیت HMAC، لاگ، مانیتورینگ، قیمت‌ها، replay، خطاها |
| `db/` | مدل‌ها و repositoryها (SQLAlchemy async؛ SQLite یا PostgreSQL) |
| `schemas/` | مدل‌های ورودی/خروجی (Pydantic) |
| `services/chunking` | نرمال‌سازی، شمارش توکن (tiktoken)، تکه‌کردن متن |
| `services/embedding`, `vectorstore` | embedding (OpenAI-compatible) و Qdrant |
| `services/extractors`, `files` | دانلود امن فایل از سایت و استخراج متن PDF/DOCX/TXT |
| `services/ingestion` | صف رکوردها، پردازش، ارسال نتیجه به افزونه |
| `services/conversation` | تاریخچه، prompt، بازیابی (RAG)، تولید پاسخ، خلاصه‌سازی |
| `services/messaging` | ذخیره پیام‌ها و پاسخ‌ها |
| `services/tools` | ابزارهای داده زنده (قیمت/موجودی، متن سفارشی) از سایت |
| `services/speech`, `vision` | ویس به متن، تحلیل تصویر |
| `services/realtime` | WebSocket «فقط اعلان» و توکن‌ها |
| `services/ratelimit`, `budget` | محدودیت نرخ و سقف هزینهٔ دلاری |
| `workers/` | `ingestion`، `delivery`، `summarizer` |

## فرایندها

- `python -m app` — API؛ حلقهٔ پاسخ‌های غیرهمزمان همان‌جا اجرا می‌شود. با Qdrant توکار، حلقهٔ ingestion هم داخل API است.
- `python -m app.workers.ingestion` — پردازش رکوردها (chunk → embed → ذخیره).
- `python -m app.workers.delivery` — ارسال وب‌هوک به سایت (دقیقاً یک نمونه).
- `python -m app.workers.summarizer` — خلاصه‌سازی گفتگوهای طولانی.

`WORKERS` باید `1` بماند: صف پاسخ، مدیر WebSocket و کش replay در حافظهٔ یک پروسه‌اند.

## داده

- **پایگاه متنی**: رکوردها، chunkها، گفتگوها، پیام‌ها، صف‌ها (SQLite پیش‌فرض، PostgreSQL اختیاری).
- **Qdrant**: بردارها در یک collection (`QDRANT_COLLECTION`). حالت `server` برای production؛ `embedded` فقط توسعه. تغییر `EMBEDDING_DIMENSIONS` collection جدید می‌خواهد.
- **پوشه‌ها**: `data/` (DB و کش)، `uploads/` (فایل‌ها و پیوست‌ها)، `logs/` (لاگ و مانیتورینگ JSONL).

## تولید پاسخ

1. آخرین پیام + خلاصهٔ گفتگو + چند جفت آخر پیام → prompt.
2. embedding سؤال → `RAG_TOP_K` chunk نزدیک از Qdrant.
3. LLM تا `ANSWER_MAX_LLM_ROUNDS` بار؛ دور اول می‌تواند ابزار زنده صدا بزند، دور آخر بدون ابزار پاسخ می‌دهد.
4. پاسخ ذخیره و با وب‌هوک به افزونه می‌رسد؛ مصرف توکن به سقف هزینه افزوده می‌شود.

## امنیت

HMAC-SHA256 روی هر درخواست، پنجرهٔ زمانی، محافظت replay، فهرست IP اختیاری، دانلود فایل فقط از میزبان `PLUGIN_BASE_URL` (ضد SSRF)، IP بازدیدکننده فقط به‌صورت hash، محدودیت نرخ در پایگاه داده.

## پیکربندی

همهٔ تنظیمات از `.env` (نمونه: `ai-server/.env.example`). `APP_ENV=production` یعنی HTTPS اجباری، Qdrant در حالت `server` و کلید Qdrant الزامی.
