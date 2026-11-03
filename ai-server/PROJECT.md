# راهنمای پروژه — بک‌اند هوش مصنوعی (FastAPI) برای افزونهٔ «دستیار هوشمند» وردپرس

> این سند برای «ایجنت/توسعه‌دهندهٔ بعدی» نوشته شده تا بدون خواندن تک‌تک فایل‌ها بتواند ادامه دهد.
> افزونهٔ وردپرس مستندات خودش را در `ARCHITECTURE.md` (ریپوی افزونه) دارد؛ قرارداد بین دو پروژه در بخش ۹ همین سند خلاصه شده است.

| مورد | مقدار |
|---|---|
| نسخهٔ فعلی | `0.6.1` (0.6.0 + رفع تعارض merge در `__init__.py`؛ حلقهٔ حداکثر K فراخوانی AI با ابزارهای داده زنده [محصولات + ابزارهای داینامیک سایت]، ورودی ویس [STT] و تصویر [vision]؛ روی 0.5.0: RAG + پرامپت پویا + JSON `{text, suggestions}`) |
| زبان/فریم‌ورک | Python 3.10+، FastAPI، Uvicorn، Pydantic v2 |
| دیتابیس برداری | Qdrant (از قبل موجود؛ باید روشن باشد) |
| دیتابیس متنی | SQLAlchemy async — پیش‌فرض SQLite (`data/app.db`)، قابل‌تعویض با PostgreSQL از `DATABASE_URL` |
| امبدینگ | OpenAI `text-embedding-3-large` (3072 بُعد، cosine) |
| سیستم‌عامل توسعه | Windows (`run.bat`)؛ سرور: Linux VPS (nginx + systemd) |
| محیط مجازی | `0.env\venv` (نسبت به ریشهٔ پروژه؛ مسیر در بالای `run.bat` قابل تغییر است) |

---

## ⚠️ موردهای اجباری (هر بار که md و zip را می‌فرستم، این‌ها حتماً باید در پیاده‌سازی رعایت شوند)

1. **بعد از هر پیاده‌سازی، همین فایل md به‌روز شود** (نسخه، وضعیت نقشهٔ راه، endpointهای جدید، تنظیمات `.env` جدید، تغییرات معماری، changelog در انتهای سند).
2. **اگر پیاده‌سازی نیاز به تغییر در اجرا داشت، `run.bat` هم بازنویسی و تحویل شود** (مثلاً worker جدید، سرویس جدید، دستور جدید) و مورد تغییر در changelog ذکر شود.

> مورد جدید می‌خواهید؟ همین‌جا به لیست بالا اضافه کنید؛ از نوبت بعد اجباری است.

---

## ۱. راه‌اندازی سریع (ویندوز)

1. پروژه را در ریشه‌ای که پوشهٔ `0.env\venv` کنارش است باز کنید (اگر venv نبود، `run.bat` خودش می‌سازد).
2. اجرا: **`run.bat`** — کارهایی که انجام می‌دهد:
   - venv را فعال می‌کند (`0.env\venv\Scripts\activate.bat`)؛ اگر نبود می‌سازد و `requirements.txt` را نصب می‌کند.
   - اگر `.env` نبود از `.env.example` می‌سازد و یک `API_SHARED_SECRET` تصادفی (۳۲ بایت) داخلش می‌نویسد.
   - همهٔ workerها را (فعلاً `summarizer`) و FastAPI را هرکدام در یک پنجرهٔ cmd جدا (با venv فعال) اجرا می‌کند. پنجرهٔ خودِ دستور هم venv فعال می‌ماند.
3. پیش از اولین ارسال رکورد: Qdrant روشن باشد (`QDRANT_URL`) و `OPENAI_API_KEY` در `.env` پر شده باشد (اگر خالی باشد `run.bat` هشدار می‌دهد).
4. آدرس محلی: `http://127.0.0.1:8000` — `/health` عمومی، `/docs` فقط در حالت development.
5. تست اتصال امضادار: `python scripts\sign_request.py GET http://127.0.0.1:8000/v1/ping`

پیش‌نمایش چانک‌ها بدون هیچ ارتباط شبکه‌ای: `python scripts\preview_chunks.py record.json --id product:13199` (از همان `CHUNK_*` در `.env` استفاده می‌کند).

دستورهای دیگر: `run.bat install` (نصب دوبارهٔ پکیج‌ها)، `run.bat test` (نصب وابستگی‌های dev + اجرای تست‌ها)، `run.bat secret` (چاپ یک راز تازه).

**workerها** (همه با `run.bat` بالا می‌آیند):
- `worker_ingestion` (`python -m app.workers.ingestion`): چانکینگ ← امبدینگ ← Qdrant + دیتابیس برای کارهای صف (`ingest_jobs`). بدون آن، رکوردها در صف می‌مانند. **اگر `QDRANT_PATH` پر باشد** (Qdrant توکار فقط با یک پروسه باز می‌شود) همین حلقه داخل خود API اجرا می‌شود (`INGEST_RUN_IN_API` خودکار) و `run.bat` پنجرهٔ ingestion را باز نمی‌کند.
- `worker_delivery` (`python -m app.workers.delivery`): فرستادن «کار فلان رکورد تمام شد» به افزونه (outbox `callback_deliveries`). فقط دیتابیس + HTTP می‌خواهد؛ **دقیقاً یک نمونه** اجرا شود.
- `worker_summarizer` (`python -m app.workers.summarizer`): خلاصه‌سازی گفتگوها؛ بدون آن درخواست‌ها در `summary_requests` منتظر می‌مانند.

**اضافه‌کردن worker در آینده:** در `run.bat` بخش `WORKERS` یک خط `start "AI Worker - نام" cmd /k ""%~f0" worker_نام"` اضافه می‌شود و یک برچسب `:role_worker_نام` (همان الگوی کامنت‌شده) که venv را فعال کرده و `python -m app.workers.نام` را اجرا می‌کند.

## ۲. معماری کلی

```
 سایت وردپرس (هاست)                         VPS هوش مصنوعی
┌────────────────────┐   HTTPS + HMAC    ┌──────────────────────────────────────────┐
│ افزونه (PHP)        │ ───────────────▶ │ nginx (TLS) ─▶ FastAPI (این پروژه)         │
│  - استخراج اطلاعات  │                   │   ├ core: config / logging / security     │
│  - ویجت چت          │ ◀─────────────── │   ├ api/v1: knowledge, chat, ...          │
│  - tools (live)     │  callback امضادار │   ├ services: ingestion, chunking, llm,   │
└────────────────────┘  (ابزارهای زنده)   │   │   tools, speech, vision, conversation │
                                          │   ├ db (کاربر/گفتگو/پیام/خلاصه)           │
                                          │   └ workers (کارهای سنگین)                │
                                          │ Qdrant (فقط 127.0.0.1) · uploads/ (≤200MB)│
                                          └──────────────────────────────────────────┘
```

اصل‌ها: **secure by default** (هر چیز زیر `/v1` فقط با امضای معتبر)، لایه‌بندی (api ← services ← db/vectorstore)، هیچ کار سنگینی داخل handler درخواست (به worker/background)، همهٔ مسیرها/تنظیمات از `.env`.

### ساختار پوشه‌ها

```
persian-ai-backend/
├─ PROJECT.md              این سند (موردهای اجباری در بالا)
├─ run.bat                 راه‌انداز ویندوز (venv + workerها + FastAPI)
├─ requirements.txt        وابستگی‌های اجرا (+ فهرست وابستگی‌های مراحل بعد به‌صورت کامنت)
├─ requirements-dev.txt    pytest, ruff, httpx
├─ pyproject.toml          تنظیم pytest/ruff
├─ .env.example            نمونهٔ تنظیمات (کپی ← .env؛ .env هرگز commit نشود)
├─ app/
│  ├─ __main__.py          `python -m app` (uvicorn با HOST/PORT/RELOAD/WORKERS از .env)
│  ├─ main.py              create_app(): middlewareها، routerها، lifespan
│  ├─ core/
│  │  ├─ config.py         Settings (pydantic-settings) + اعتبارسنجی راز/HTTPS/IP
│  │  ├─ logging.py        dictConfig، request-id، فرمت متنی/JSON، فایل چرخشی
│  │  ├─ security.py       compute_signature / verify_signature (HMAC)
│  │  ├─ errors.py         AppError ها + هندلرهای فرمت یکنواخت خطا
│  │  └─ middleware.py     RequestContext(+access log)، HTTPSOnly، BodySizeLimit
│  ├─ api/
│  │  ├─ deps.py           require_signed_request (احراز هویت + IP allowlist)
│  │  ├─ router.py         مونتاژ routerها؛ `/v1` با وابستگی احراز هویت
│  │  └─ v1/               health.py (عمومی)، system.py (/v1/ping)، knowledge.py (رکوردها/متن‌ها/فایل‌ها؛ فقط صف)، jobs.py (وضعیت job)، messaging.py (پیام‌ها)، storage.py (`/v1/storage`)، logs.py
│  ├─ schemas/             مدل‌های Pydantic درخواست/پاسخ (common.py، knowledge.py، messaging.py، ingest.py)
│  ├─ services/
│  │  ├─ chunking/         normalize.py (نرمال‌سازی فارسی)، render.py (رکورد ← متن فارسی)،
│  │  │                    splitter.py (چانک بر اساس توکن)، tokens.py (tiktoken)،
│  │  │                    jalali.py (تاریخ شمسی)، pipeline.py (build_plan)، doc_chunker.py (چانکر هم‌اندازهٔ فایل/متن بزرگ)
│  │  ├─ embedding/        openai_embedder.py (batch + لاگ usage)
│  │  ├─ vectorstore/      qdrant.py (collection، upsert، حذف با فیلتر record_id)
│  │  ├─ files/            download.py (دانلود با لینک امضادار: SSRF، IP پین‌شده، سقف حجم/۲۰۰MB، ذخیره در `uploads/knowledge/<id>/`)
│  │  ├─ extractors/       pdf_extractor.py · docs_extractor.py · txt_extractor.py · common.py · loader.py (فایل ← `DocBlock`ها)
│  │  ├─ ingestion/        content.py (ContentService: شاخهٔ `text:*` و `file:*`)، service.py (KnowledgeService: upsert_many / delete_many / store_prebuilt)، queue.py (سمت API: اعتبارسنجی + ثبت job + وضعیت)،
│  │  │                    processor.py (سمت worker)، delivery.py (ارسال callback: batch/backoff/breaker/ping)، callback.py (SSRF + قفل سایت)
│  │  ├─ messaging/        service.py (MessagingService: ثبت/حذف پیام + تولید پاسخ)، storage.py (دانلود امن پیوست)، errors.py
│  │  ├─ conversation/     history.py (HistoryBuilder: خلاصه + ۳ زوج آخر + پیام آخر، شمارش توکن)، answer.py (پاسخ ثابت؛ جای LLM)،
│  │  │                    prompt.py (پرامپت پایهٔ انگلیسیِ پویا)، retrieval.py (RAG: امبدینگ پرسش ← Qdrant ← متن چانک)،
│  │  │                    llm_answer.py (LLMAnswerGenerator)، reply_format.py (JSON خروجی + پارسر)،
│  │  │                    summarizer.py (پرامپت انگلیسی + کلاینت OpenAI)، summary_service.py (صف: enqueue / process_next)
│  │  ├─ tools/            client.py (SiteToolClient: فراخوانی امضادار API سایت)، catalog.py (ToolKit: تعریف ابزارها + اجرا) — 0.6.0
│  │  ├─ speech/           transcriber.py (ویس ← متن، whisper-1/fa) — 0.6.0
│  │  └─ vision/           images.py (کوچک‌سازی عکس تا ≤ ۱MB و ساخت data-URL) — 0.6.0
│  ├─ db/                  base.py (engine/session)، models.py (records, chunks)،
│  │                       messaging_models.py (conversations, messages, summary_requests)، messaging_repository.py (MessagingRepository)،
│  │                       repository.py (تنها جای SQL پایگاه دانش)، ingest_models.py + ingest_repository.py (صف ingestion و outbox callback)
│  └─ workers/             summarizer.py · ingestion.py · delivery.py (`python -m app.workers.<نام>`)
├─ scripts/
│  ├─ preview_chunks.py    پیش‌نمایش چانک‌ها از یک فایل JSON (بدون شبکه)
│  ├─ kb_upsert.py / kb_delete.py / kb_show.py   تست endpointها با امضا (سرور باید روشن باشد)
│  ├─ inspect_db.py        خواندن مستقیم SQLite (رکوردها و متن چانک‌ها)؛ `_client.py` کمکی
│  ├─ generate_secret.py   ساخت راز (`--write` داخل .env می‌نویسد)
│  └─ sign_request.py      کلاینت مرجع برای تست دستی با امضا
├─ docs/
│  └─ LIVE_TOOLS_API.md    راهنمای فنی پیاده‌سازی دو API سمت سایت (محصولات + ابزارهای داینامیک) — 0.6.0
├─ deploy/                 nginx.conf.example، firewall-ufw.sh، ai-backend.service
├─ tests/                  pytest (امنیت + API + چانکینگ + پایگاه دانش + پیام‌رسانی + تاریخچه/خلاصه‌سازی)
└─ data/  uploads/  logs/  (اجرا-زمان؛ داخل git نیستند)
```

## ۳. احراز هویت (اجرا شده در 0.1.0)

الگوریتم (طبق خواستهٔ پروژه):

- افزونه روی `timestamp + "\n" + body` (بدنهٔ خام، بایت‌به‌بایت همان چیزی که فرستاده می‌شود) با **HMAC-SHA256** امضا می‌زند و دو هدر می‌گذارد: `X-Timestamp` (زمان یونیکس به **ثانیه**، عدد صحیح) و `X-Signature` (hex).
- سرور همان امضا را حساب می‌کند، با `hmac.compare_digest` مقایسه می‌کند و درخواستی را که زمانش بیش از **۵ دقیقه** با ساعت سرور اختلاف دارد (قدیمی یا آینده) رد می‌کند (`AUTH_MAX_SKEW_SECONDS`).
- بدنهٔ خالی (GET) هم امضا می‌شود: `timestamp + "\n"`.
- راز: حداقل ۳۲ بایت تصادفی. سرور در استارت رد می‌کند اگر رازِ hex کمتر از ۶۴ نویسه (یا غیر-hex کمتر از ۴۳ نویسه) یا کم‌آنتروپی باشد. تولید: `python scripts/generate_secret.py`. کلید HMAC همان رشتهٔ UTF-8 راز است (برای رازِ hex یعنی خودِ ۶۴ نویسه، نه بایت‌های دیکد‌شده).
- پاسخ‌های خطا: ۴۰۱ با `code: "unauthorized"` و پیام مشخص (`Missing authentication headers` / `Invalid timestamp` / `Request timestamp is expired…` / `Invalid signature`)؛ دلیل دقیق (`reason`) فقط در لاگ سرور. امضا و راز هرگز لاگ نمی‌شوند.
- فقط HTTPS: `REQUIRE_HTTPS=true` ← درخواست HTTP با ۴۰۰ `https_required` رد می‌شود (به‌جز `/health`). پشت nginx باید `X-Forwarded-Proto: https` فرستاده شود و `FORWARDED_ALLOW_IPS` فقط IP پروکسی باشد. در `APP_ENV=production` خاموش‌بودن HTTPS اصلاً اجازهٔ استارت نمی‌دهد.
- IP: لایهٔ اصلی فایروال VPS است (`deploy/firewall-ufw.sh <IP هاست> <IP مدیر برای SSH>`)؛ لایهٔ اختیاری برنامه `ALLOWED_IPS` (IP/CIDR با کاما) که با ۴۰۳ رد می‌کند.
- محدودیت حجم بدنه: `MAX_BODY_BYTES` (پیش‌فرض ۵MB)، هم با `Content-Length` هم هنگام دریافت (۴۱۳).

### نمونهٔ کد سمت افزونه (PHP)

```php
$body      = wp_json_encode( $payload );          // همین رشته را بفرستید و امضا کنید
$timestamp = (string) time();
$signature = hash_hmac( 'sha256', $timestamp . "\n" . $body, $secret ); // خروجی hex

$response = wp_remote_post( trailingslashit( $api_url ) . 'v1/ping', [
    'timeout'   => 30,
    'sslverify' => true,
    'headers'   => [
        'Content-Type' => 'application/json',
        'X-Timestamp'  => $timestamp,
        'X-Signature'  => $signature,
    ],
    'body'      => $body,
] );
```

توصیه‌ها: راز را در `wp-config.php` به‌صورت ثابت (`define('PAIA_API_SECRET', '...')`) نگه دارید نه در دیتابیس؛ برای هر تلاش مجدد، زمان را دوباره بگیرید و دوباره امضا کنید؛ ساعت هاست و VPS با NTP هماهنگ باشد (`/v1/ping` مقدار `server_time` را برمی‌گرداند تا اختلاف ساعت دیده شود).

محدودیت‌های شناخته‌شدهٔ الگوریتم فعلی (تصمیم با صاحب پروژه): امضا شامل متد و مسیر نیست (امضای یک درخواست در ۵ دقیقه روی مسیر دیگر با همان بدنه قابل استفاده است) و replay تا ۵ دقیقه ممکن است. در صورت نیاز: افزودن متد+مسیر به رشتهٔ امضا و کش nonce (Redis).

## ۴. لاگینگ (اجرا شده در 0.1.0)

- هر خط: زمان | سطح | `request_id` | نام لاگر | پیام. `request_id` از هدر `X-Request-ID` (اگر معتبر) یا تصادفی ساخته می‌شود، در هدر پاسخ و در بدنهٔ خطا برمی‌گردد.
- مقصدها: کنسول، `logs/app.log` (همهٔ سطح‌ها) و `logs/error.log` (ERROR به بالا)، چرخشی (`LOG_MAX_MB` × `LOG_BACKUP_COUNT`)، UTF-8 (متن فارسی امن است؛ استریم‌های ویندوز هم utf-8 می‌شوند).
- `LOG_JSON=true` ← هر خط یک JSON (برای ابزارهای جمع‌آوری لاگ).
- لاگر `app.access`: یک خط برای هر درخواست (متد، مسیر بدون query، وضعیت، مدت، IP). `/health` در سطح DEBUG. بدنهٔ درخواست‌ها هرگز لاگ نمی‌شود.
- نکته (ویندوز): با `WORKERS>1` چرخش فایل بین چند پردازش ممکن است خطای قفل بدهد؛ روی ویندوز `WORKERS=1` بماند (روی لینوکس مشکلی نیست).
- آینده: ارسال رویدادهای مهم به «لاگ سرور» افزونه (`LogStore::add`) — بخش ۹.

## ۵. تنظیمات `.env`

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `APP_ENV` | `development` | `production` ← HTTPS اجباری، `RELOAD` ممنوع، `/docs` خاموش |
| `HOST` / `PORT` | `127.0.0.1` / `8000` | در production پشت nginx روی 127.0.0.1 بماند |
| `RELOAD` | `false` | فقط توسعه؛ فقط پوشهٔ `app/` را نگاه می‌کند |
| `WORKERS` | `1` | تعداد پردازش uvicorn (بدون reload) |
| `FORWARDED_ALLOW_IPS` | `127.0.0.1` | IPهای پروکسیِ مورد اعتماد برای `X-Forwarded-*` |
| `ENABLE_DOCS` | (خودکار) | دستی: `true/false` |
| `LOG_LEVEL` `LOG_JSON` `LOG_TO_FILE` `LOG_DIR` `LOG_MAX_MB` `LOG_BACKUP_COUNT` | `INFO` `false` `true` `logs` `10` `10` | بخش ۴ |
| `API_SHARED_SECRET` | — (الزامی) | بخش ۳ |
| `AUTH_MAX_SKEW_SECONDS` | `300` | بازهٔ مجاز اختلاف زمان (۳۰..۳۶۰۰) |
| `MAX_BODY_BYTES` | `5000000` | سقف بدنه (با `client_max_body_size` nginx هماهنگ) |
| `REQUIRE_HTTPS` | `true` (در `.env.example` برای توسعه `false`) | بخش ۳ |
| `ALLOWED_IPS` | خالی | لایهٔ اختیاری IP |
| `DATA_DIR` / `UPLOADS_DIR` | `data` / `uploads` | مسیر نسبی = نسبت به ریشهٔ پروژه |

**جدید در 0.3.0:**

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `DATABASE_URL` | خالی ← `sqlite+aiosqlite:///data/app.db` | هر URL آسینک SQLAlchemy (مثلاً `postgresql+asyncpg://…` با نصب `asyncpg`) |
| `QDRANT_PATH` | خالی (در `.env.example`: `data/qdrant`) | پر = حالت محلی توکار بدون سرور (فقط یک پروسه، مناسب توسعه/داده کم)؛ خالی = اتصال به سرور Qdrant. برای production سرور واقعی؛ هنگام مهاجرت `QDRANT_PATH` را خالی و رکوردها را با `force=true` دوباره بفرستید |
| `QDRANT_URL` / `QDRANT_API_KEY` | `http://127.0.0.1:6333` / خالی | `:memory:` فقط برای تست |
| `QDRANT_COLLECTION` / `QDRANT_TIMEOUT` | `knowledge` / `30` | collection خودکار ساخته می‌شود (cosine) و ایندکس payload روی `record_id`, `type`, `chunk_index` |
| `OPENAI_API_KEY` | خالی (الزامی برای ارسال رکورد) | بدون آن، ارسال رکورد ← `service_unavailable` برای هر رکورد |
| `OPENAI_BASE_URL` | خالی | آدرس پروکسی/گیت‌وی سازگار با OpenAI (در صورت نیاز) |
| `EMBEDDING_MODEL` / `EMBEDDING_DIMENSIONS` | `text-embedding-3-large` / `3072` | تغییر بُعد ← collection جدید لازم است (سرور با ناهماهنگی استارت‌آپ‌دار خطا می‌دهد) |
| `EMBEDDING_BATCH_SIZE` `EMBEDDING_TIMEOUT` `EMBEDDING_MAX_RETRIES` | `64` `60` `3` | تعداد چانک در هر درخواست امبدینگ، timeout، تلاش مجدد SDK |
| `TIKTOKEN_ENCODING` / `TIKTOKEN_CACHE_DIR` | `cl100k_base` / `data/tiktoken_cache` | واژگان tiktoken یک‌بار دانلود و اینجا کش می‌شود |
| `CHUNK_TARGET_TOKENS` `CHUNK_MAX_TOKENS` `CHUNK_OVERLAP_TOKENS` `CHUNK_MIN_TOKENS` | `400` `600` `60` `80` | بخش «آستانه‌های چانکینگ» پایین‌تر |

**جدید در 0.3.4:**

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `PLUGIN_BASE_URL` | خالی | آدرس سایت وردپرس (مثلاً `https://example.com`)؛ دانلود پیوست‌های چت **فقط** از همین میزبان/پورت مجاز است. خالی ← هیچ پیوستی دانلود نمی‌شود (`attachment_status=failed`) |
| `MESSAGE_ATTACHMENT_MAX_MB` | `20` | سقف حجم هر عکس/ویس چت |
| `MESSAGE_DOWNLOAD_TIMEOUT` | `30` | timeout دانلود پیوست (ثانیه) |

**جدید در 0.3.5** (تاریخچه و خلاصه‌سازی؛ worker از `OPENAI_API_KEY` / `OPENAI_BASE_URL` بالا استفاده می‌کند):

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `HISTORY_RECENT_PAIRS` | `3` | تعداد زوج‌های آخر (پیام کاربر + پاسخ‌ها) که کامل در تاریخچه می‌آید |
| `SUMMARY_TRIGGER_TOKENS` | `3000` | اگر توکنِ «خلاصه + همهٔ پیام‌های خلاصه‌نشده» از این بیشتر شود، درخواست خلاصه‌سازی ثبت می‌شود |
| `SUMMARY_MODEL` | `gpt-4.1-mini` | مدل چت برای خلاصه‌سازی (هر مدل سازگار با API چت OpenAI) |
| `SUMMARY_MAX_OUTPUT_TOKENS` | `1200` | سقف طول خلاصه |
| `SUMMARY_TIMEOUT` / `SUMMARY_MAX_RETRIES` | `120` / `2` | timeout هر فراخوانی / تلاش مجدد SDK |
| `SUMMARY_MAX_ATTEMPTS` / `SUMMARY_RETRY_DELAY_SECONDS` | `3` / `30` | تلاش‌های worker برای هر درخواست؛ فاصلهٔ تلاش مجدد = تأخیر × شمارهٔ تلاش |
| `SUMMARY_WORKER_POLL_SECONDS` | `3` | فاصلهٔ بررسی صف وقتی کاری نیست |
| `SUMMARY_STALE_SECONDS` | `600` | درخواستِ گیرکرده در `processing` (worker خراب‌شده) بعد از این مدت دوباره pending می‌شود |

**جدید در 0.3.6** (صف ingestion و callback):

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `INGEST_RUN_IN_API` | خالی = خودکار | خالی: فقط وقتی `QDRANT_PATH` پر است حلقهٔ ingestion داخل API اجرا می‌شود. `true/false` اجبار می‌کند |
| `INGEST_WORKER_CONCURRENCY` | `2` | تعداد رکوردی که هم‌زمان پردازش می‌شود (یک رکورد هرگز دو بار هم‌زمان) |
| `INGEST_WORKER_POLL_SECONDS` | `1` | فاصلهٔ بررسی صف وقتی کاری نیست |
| `INGEST_MAX_ATTEMPTS` / `INGEST_RETRY_DELAY_SECONDS` | `3` / `30` | تلاش مجدد برای خطای موقت (OpenAI/Qdrant)؛ تأخیر × شمارهٔ تلاش. خطای دائمی (مثلاً متن خالی) تلاش مجدد ندارد |
| `INGEST_STALE_SECONDS` | `900` | job گیرکرده در `processing` (worker مرده) دوباره pending می‌شود |
| `INGEST_RETENTION_DAYS` | `7` | job/deliveryهای تمام‌شده بعد از این مدت پاک می‌شوند |
| `PLUGIN_CALLBACK_SECRET` | خالی ← `API_SHARED_SECRET` | کلید امضای callback (همان `api_key` افزونه) |
| `CALLBACK_ALLOW_PRIVATE_NETWORKS` | `false` | فقط توسعه: http و آدرس‌های داخلی/لوپ‌بک مجاز. در production ممنوع (استارت رد می‌شود) |
| `CALLBACK_BATCH_WINDOW_SECONDS` | `10` | نتیجه‌ها این‌قدر صبر می‌کنند تا چند تا در یک درخواست بروند |
| `CALLBACK_INITIAL_BATCH_SIZE` | `50` | اندازهٔ اولیهٔ دسته؛ با سرعت سایت تطبیق می‌یابد (۱۰..۵۰۰) |
| `CALLBACK_CONNECT_TIMEOUT` / `CALLBACK_TOTAL_TIMEOUT` | `3` / `10` | timeout سخت هر درخواست به سایت (ثانیه) |
| `CALLBACK_MAX_CONCURRENCY` | `8` | حداکثر سایت‌های هم‌زمان (برای هر سایت همیشه یکی) |
| `CALLBACK_MIN_INTERVAL_SECONDS` | `1` | فاصلهٔ حداقل بین دو ارسال به یک سایت |
| `CALLBACK_MAX_ATTEMPTS` | `8` | بعد از این‌همه ارسال ناموفق، نتیجه `dead` می‌شود (جبران با `/v1/jobs/status`) |
| `CALLBACK_BREAKER_FAILURES` / `CALLBACK_PING_INTERVAL_SECONDS` | `5` / `300` | بعد از N شکست پیاپی فقط ping دوره‌ای تا سایت برگردد |
| `CALLBACK_WORKER_POLL_SECONDS` | `2` | فاصلهٔ حلقهٔ worker تحویل |

**جدید در 0.5.0** (پاسخ واقعی؛ جزئیات: بخش «تولید پاسخ واقعی»):

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `DEFAULT_TONE` | `formal` | لحنِ پیش‌فرض وقتی کلاینت `tone` نفرستد (`formal` یا `friendly`) |
| `TONE_FORMAL` / `TONE_FRIENDLY` | متن انگلیسی داخل `Settings` | متن لحن که داخل پرامپت می‌رود؛ در `.env` یک‌خطی و داخل کوتیشن |
| `RAG_TOP_K` | `3` | تعداد چانک برتر که به پرامپت اضافه می‌شود |
| `RAG_MIN_SCORE` | `0` | حداقل شباهت کسینوسی (۰ = خاموش)؛ با لاگ `rag.retrieved` تنظیم شود |
| `ANSWER_MAX_OUTPUT_TOKENS` | `2000` | سقف خروجی (مدل‌های reasoning استدلال پنهان را هم از همین سقف مصرف می‌کنند) |
| `ANSWER_LLM_TIMEOUT` / `ANSWER_LLM_MAX_RETRIES` | `90` / `2` | timeout و تلاش مجدد SDK برای هر فراخوانی LLM |
| `ANSWER_RESPONSE_FORMAT` | `json_schema` | `json_schema` (OpenAI) \| `json_object` (بسیاری از گیت‌وی‌ها) \| `none` |
| `ANSWER_REASONING_EFFORT` | خالی | اختیاری، فقط مدل‌های reasoning: `minimal\|low\|medium\|high` |

اگر `OPENAI_API_KEY` خالی باشد همچنان پاسخ ثابتِ آزمایشی داده می‌شود (هشدار در لاگ استارت).

**جدید در 0.3.7:**

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `ANSWER_WORKER_CONCURRENCY` | `50` | پاسخ‌هایی که هم‌زمان ساخته می‌شود (حلقهٔ داخل API) |
| `ANSWER_MAX_ATTEMPTS` / `ANSWER_RETRY_DELAY_SECONDS` | `3` / `5` | تلاش برای ساخت پاسخ؛ تأخیر × شمارهٔ تلاش |
| `ANSWER_WORKER_POLL_SECONDS` / `ANSWER_STALE_SECONDS` | `1` / `300` | فاصلهٔ بررسی صف / بازیابی jobِ گیرکرده |
| `ANSWER_CALLBACK_MAX_ATTEMPTS` / `ANSWER_CALLBACK_TTL_SECONDS` | `4` / `600` | سقف ارسال ناموفق وب‌هوک پاسخ / حداکثر عمر آن (ثانیه)، بعد رها می‌شود |
| `AI_ALLOWED_MODELS` | `gpt-5-4` | فهرست نام مدل‌های مجاز با کاما (همان کلیدهای «سرویس هوش مصنوعی» افزونه). **اولی پیش‌فرض است**: اگر افزونه `model` نفرستد یا خارج از فهرست باشد اولی استفاده می‌شود |

**جدید در 0.4.0** (فایل‌های پایگاه دانش؛ لینک دانلود را افزونه می‌سازد، بخش «متن‌ها و فایل‌ها»):

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `UPLOADS_MAX_TOTAL_MB` | `200` | سقف مجموع فایل‌های دانلودشدهٔ پایگاه دانش (`uploads/knowledge`). فایل پیوست چت حساب نمی‌شود. با رسیدن به سقف ← `storage_quota_exceeded` و هیچ دانلود/چانکینگی انجام نمی‌شود |
| `FILES_MAX_MB` | `100` | سقف هر فایل (هم از حجم اعلامی و هم هنگام استریم) |
| `FILES_KEEP_ORIGINAL` | `true` | `false` ← فایل بعد از ایندکس پاک می‌شود (سقف ۲۰۰MB فقط حین پردازش معنا دارد) |
| `FILES_CONNECT_TIMEOUT` / `FILES_READ_TIMEOUT` | `10` / `60` | timeout دانلود (ثانیه) |
| `FILES_DOWNLOAD_RETRIES` | `2` | تلاش مجدد داخل یک job برای خطای موقت سایت (5xx/429/شبکه)؛ بعدش تلاش‌های job (`INGEST_MAX_ATTEMPTS`) |
| `FILES_MAX_EXTRACTED_CHARS` | `3000000` | سقف متن استخراج‌شده |

دانلود فقط از میزبان `PLUGIN_BASE_URL` (یا اولین سایت ثبت‌شده) و با `CALLBACK_ALLOW_PRIVATE_NETWORKS=false` فقط https/پورت 443 و 8443 و IP عمومی.

**جدید در 0.6.0** (حلقهٔ AI + ابزارها + ویس + تصویر؛ جزئیات: بخش «حلقهٔ AI، ابزارها، ویس و تصویر»):

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `ANSWER_MAX_LLM_ROUNDS` | `2` | **K**: حداکثر تعداد فراخوانی AI برای هر پیام. دور آخر هرگز ابزار ندارد و حتماً پاسخ نهایی تولید می‌کند. `1` = ابزارها خاموش |
| `TOOLS_PRODUCTS_PATH` | `/wp-json/paia/v1/live/products` | مسیر API محصولات روی سایت (میزبان = `PLUGIN_BASE_URL`) |
| `TOOLS_DYNAMIC_PATH` | `/wp-json/paia/v1/live/tool` | مسیر API ثابت ابزارهای داینامیک |
| `TOOLS_TIMEOUT` | `10` | timeout هر فراخوانی API سایت (ثانیه) |
| `TOOLS_MAX_CALLS_PER_ROUND` | `4` | حداکثر فراخوانی ابزار در هر دور (بقیه «اجرا نشد» برمی‌گردند) |
| `TOOLS_MAX_PRODUCTS_PER_CALL` | `5` | حداکثر محصول در یک فراخوانی ابزار محصول |
| `TOOLS_MAX_DYNAMIC` | `30` | حداکثر ابزار داینامیکِ پذیرفته‌شده در هر درخواست |
| `TOOLS_MAX_RESULT_CHARS` | `12000` | نتیجهٔ بلندتر از این بریده می‌شود |
| `STT_MODEL` / `STT_LANGUAGE` | `whisper-1` / `fa` | مدل و زبان پیش‌فرض تبدیل ویس به متن (زبان خالی = تشخیص خودکار) |
| `STT_TIMEOUT` | `120` | timeout تبدیل ویس |
| `IMAGE_MAX_KB` | `1024` | عکسِ بزرگ‌تر بازکدگذاری (JPEG) و کوچک می‌شود تا از این حجم بیشتر نباشد |
| `IMAGE_DETAIL` | `auto` | `auto\|low\|high` — پارامتر detail بینایی OpenAI |

وابستگی جدید: **`Pillow`** (کوچک‌سازی عکس) — `pip install Pillow` و در `requirements.txt` اضافه شود. (برای ویس‌های amr/aac اختیاری: ffmpeg روی سرور.)

مرحلهٔ بعد (فعلاً کامنت در `.env.example`): `UPLOADS_MAX_TOTAL_MB=200`.

## ۶. فرمت یکنواخت خطا

```json
{"error": {"code": "unauthorized", "message": "Invalid signature", "request_id": "b3f4f577981a441a", "details": null}}
```
کدها: `unauthorized` (401)، `forbidden` (403)، `not_found` (404)، `method_not_allowed` (405)، `payload_too_large` (413)، `validation_error` (422، `details` = فهرست `loc/msg/type` بدون برگرداندن ورودی)، `https_required` (400)، `unprocessable_record` (422؛ رکورد متن قابل‌استفاده ندارد)، `invalid_callback` (422؛ آدرس callback نامعتبر/خطرناک/متعلق به سایت دیگر)، `upstream_error` (502؛ API امبدینگ)، `service_unavailable` (503؛ Qdrant/دیتابیس/کلید تنظیم‌نشده)، `internal_error` (500؛ جزئیات فقط در لاگ). در endpointهای دسته‌ای خطای هر رکورد داخل `results[].error` برمی‌گردد و بقیه ادامه می‌دهند. خطای جدید = زیرکلاس `AppError` در `core/errors.py`.

## ۷. Endpointها

| متد و مسیر | دسترسی | وضعیت | توضیح |
|---|---|---|---|
| `GET /health` | عمومی | ✅ | `{"status":"ok"}` |
| `GET\|POST /v1/ping` | امضادار | ✅ | تست اتصال/امضا؛ `server_time`، `received_bytes` |
| `POST /v1/knowledge/records` | امضادار | ✅ 0.3.6 | **فقط صف**: اعتبارسنجی + ثبت job ← **200 فوری**؛ worker بعداً چانک ← امبدینگ ← Qdrant + دیتابیس و بعد callback. دو شکل بدنه (دسته‌ای / تک‌رکوردی افزونه) |
| `DELETE /v1/knowledge/records` | امضادار | ✅ 0.3.6 | بدنه `{ids:[...], callback?}` ← صف؛ worker حذف کامل از دیتابیس متنی و Qdrant (callback: `untrained`) |
| `PUT /v1/knowledge/records` | امضادار | ✅ 0.3.6 | همان بدنهٔ POST؛ صف؛ worker برای هر رکورد: ابتدا حذف کامل و سپس افزودن. اگر حذف شکست بخورد افزودن انجام نمی‌شود |
| `POST /v1/get_log` | امضادار | ✅ 0.3.7 | بدنه `{after:[id,...], limit?}` ← `{entries:[{id,time,level,request_id,logger,message}], count, reset}`؛ یک خواندن محدود (حداکثر ۵۱۲KB) از انتهای `app.log`؛ فقط خطوط بعد از آخرین idِ شناخته‌شده؛ بدون `after` ← آخرین `limit` خط (پیش‌فرض ۲۰۰، حداکثر ۵۰۰)؛ `reset=true` یعنی idها پیدا نشد (چرخش فایل) و تازه‌ترین خطوط آمده |
| `POST /v1/jobs/status` | امضادار | ✅ 0.3.6 | بدنه `{ids:[...]}` ← آخرین وضعیت هر رکورد: `trained`/`untrained`/`failed`/`processing`/`unknown` (+`rev`, `job_id`, `error`, و `operation:"delete"` برای حذفِ شکست‌خورده)؛ تور ایمنی وقتی callback گم شود (README افزونه §6) |
| `GET /v1/knowledge/records?limit&offset&kind` | امضادار | ✅ 0.3.2 | فهرست رکوردهای ذخیره‌شده (دیباگ) |
| `GET /v1/knowledge/records/{id}` | امضادار | ✅ 0.3.2 | رکورد + داده خام + چانک‌ها + payloadهای Qdrant + `in_sync` (دیباگ) |
| `text:*` و `file:*` روی `POST/PUT/DELETE /v1/knowledge/records` | امضادار | ✅ 0.4.0 | **همان endpointهای صف‌دار** (endpoint جدا ندارند؛ `/texts` و `/files` حذف شدند). `text:*` ← چانک‌های افزونه (`data.chunks`) نگه داشته می‌شود؛ `file:*` ← `data.download.url` (لینک امضادار) ← دانلود ← استخراج ← چانک. بخش «متن‌ها و فایل‌ها» |
| `GET /v1/storage` | امضادار | ✅ 0.4.0 | `{used_bytes, max_bytes, free_bytes, files, max_file_bytes}` از فایل‌های پایگاه دانش |
| `POST /v1/chat/reply` | امضادار | ⏳ 0.5.0 | پیام(ها)+پیوست+tools ← پاسخ/پیشنهادها/ویس/پیوست |
| `POST /v1/chat/messages` | امضادار | ✅ 0.3.4 | ثبت پیام (`user\|admin\|assistant`)؛ ساخت/یافتن گفتگوی یکتای کاربر؛ ذخیرهٔ فوری؛ دانلود عکس/ویس. با `generate_ai_answer=true` روی پیام کاربر، پاسخ (RAG + LLM؛ بدون `OPENAI_API_KEY` ثابت) هم تولید و ذخیره و برگردانده می‌شود. فقط وقتی `generate_ai_answer=true`: فیلدهای `model`، `tone` (`formal|friendly`)، `site{title,description}`، `page{url,slug,title,type}`، `notes` (یادداشت‌های مهم صاحب سایت، تا ۱۵۰۰ نویسه؛ بلندتر بریده می‌شود، خطا نیست) و `callback`؛ همه اختیاری‌اند؛ با `callback` پاسخ **غیرهمزمان** است (`ai_answer.status="queued"` + `job_id`) و بعداً با وب‌هوک `kind:"chat_answer"` می‌آید (بخش «پاسخ غیرهمزمان»)؛ بدون `callback` پاسخ داخل همان درخواست ساخته می‌شود؛ بخش «پیام‌رسانی» و «تاریخچه، تولید پاسخ و خلاصه‌سازی» |
| `DELETE /v1/conversations/{conversation_id}/messages/{message_id}` | امضادار | ✅ 0.3.4 | حذف یک پیام (+ فایل پیوست) |
| `DELETE /v1/conversations/{conversation_id}/messages` | امضادار | ✅ 0.3.4 | حذف همهٔ پیام‌های گفتگو (+ فایل‌ها)؛ خود گفتگو می‌ماند |
| `GET /v1/conversations/{id}/messages` | امضادار | ⏳ | تاریخچه (تابع `list_messages` در repository آماده است) |

**فیلدهای جدید `POST /v1/chat/messages` در 0.6.0** (فقط وقتی `generate_ai_answer=true`؛ همراه هر پیام):
- `live_product_datas`: آرایه‌ای از `"price"` و/یا `"stock"` (یا آبجکت `{"price":true,"stock":false}`). **نبود/خالی = سایت داده لایو محصول ندارد ← ابزار محصول اصلاً به AI داده نمی‌شود.**
- `tools`: `[{"id":0,"title":"خدمات نصب کولر","description":"اگر درباره هزینه … پرسیدند …"}, …]` (کلید `disciprtion` افزونه هم پذیرفته می‌شود). هر مورد = یک ابزار داینامیک.
- `page.id`: (اختیاری) شناسهٔ نوشتهٔ وردپرس صفحه؛ در صفحهٔ محصول = شناسهٔ محصول.
- در پاسخ، `message.transcript` (متن ویس) پر می‌شود.

اضافه‌کردن endpoint: فایل جدید در `app/api/v1/`، ثبت در `app/api/router.py` زیر `v1_router` (احراز هویت خودکار اعمال می‌شود)، schema در `app/schemas/`، منطق در `app/services/`.

## ۸. نقشهٔ راه

- [x] **0.1.0** — ساختار، تنظیمات، لاگینگ، HMAC، `/health` و `/v1/ping`، تست‌ها، `run.bat`، فایل‌های دیپلوی
- [~] **0.2.0** — دیتابیس (SQLAlchemy async + Alembic؛ پیش‌فرض SQLite، قابل‌تعویض با PostgreSQL)، کاربر/گفتگو/پیام، سه نقش، خلاصه‌سازی: ✅ گفتگو/پیام/سه نقش/ثبت و حذف/دانلود پیوست (0.3.4)؛ ✅ آماده‌سازی تاریخچه، کلید `generate_ai_answer`، پاسخ ثابت، خلاصه‌سازی بر پایهٔ توکن با worker (0.3.5)؛ ⏳ Alembic، endpoint تاریخچه
- [~] **0.3.0** — Qdrant + chunking فارسی + embedding + CRUD با شناسهٔ ثابت: ✅ رکوردهای استخراج‌شده (محصول/وبلاگ/برگه/دسته‌بندی‌ها/سایت)؛ ✅ متن‌های دستی و فایل‌ها (0.4.0)؛ ⏳ endpoint جست‌وجوی برداری (در 0.5.0 با RAG)
- [x] **0.4.0** — متن‌های دستی و فایل‌ها از صف: ✅ `text:*` (چانک‌های افزونه)، ✅ `file:*` (لینک امضادار ← دانلود ← PDF/DOCX/TXT ← چانک)، ✅ سقف ۲۰۰MB + `/v1/storage`، ✅ حذف فایل دانلودشده هنگام حذف رکورد. (`.doc` قدیمی پشتیبانی نمی‌شود)
- [x] **0.5.0** — ✅ پیاده‌شده (بخش «تولید پاسخ واقعی»؛ هنوز بدون tools/ویس/تصویر). متن قبلی: تولید پاسخ (سازوکار `model`/`tone`/`site`/`page`/پاسخ غیرهمزمان و `PromptContext` از 0.3.7 آماده است؛ فقط LLM واقعی مانده): RAG + تاریخچهٔ خلاصه + پرامپت + LLM (جای `FixedAnswerGenerator` در `answer.py`؛ ورودی آماده است: `PreparedHistory`)
- [x] **0.6.0** — ✅ ابزارهای داده زنده (محصولات + داینامیک سایت) داخل حلقهٔ حداکثر K فراخوانی AI؛ API سمت سایت: `docs/LIVE_TOOLS_API.md`
- [~] **0.7.0** — ویس: ✅ STT ورودی (whisper-1، فارسی) در 0.6.0؛ ⏳ TTS برای پاسخ
- [~] **0.8.0** — تصویر: ✅ عکس ورودی (کوچک‌سازی ≤ ۱MB + ارسال به مدل) در 0.6.0؛ ⏳ ارسال عکس به‌عنوان پیوست پاسخ

## ۹. طراحی مراحل بعد (پیشنهاد معماری — پیش از پیاده‌سازی هر مرحله باید تأیید شود)

### قرارداد با افزونه (از `ARCHITECTURE.md` افزونه)
- شناسهٔ رکوردها همان شناسهٔ افزونه است و **همان شناسه در Qdrant و دیتابیس سرور نگه داشته می‌شود**: `site:{blog_id}`، `product:23`، `blog:45`، `page:7`، `product_taxonomies:all`، `blog_taxonomies:all`، `pages_taxonomies:all`، `text:{ms}`، `file:{ms}`.
- رویدادهای ارسال رکورد (`AiBackend::send($id,$data,$event)`): `create | update | delete`. افزونه فقط وقتی `ai_status=1` می‌کند که بک‌اند **تأیید** کرده باشد (`AiBackend::train($ids)` ← فهرست شناسه‌های تأییدشده) ← پاسخ endpointها باید صریح بگویند کدام شناسه‌ها ذخیره شدند.
- اطلاعات «زنده» (قیمت، موجودی، …) عمداً در داده‌های استخراج‌شده **نیست** و باید با tool گرفته شود (فیلدها/ردیف‌های فعال `paia_live_*`).
- تنظیمات هر درخواست چت از افزونه: `site.description`، `ai.{service,tone,notes}` (سرویس فعلاً `gpt-5-4`؛ لحن `friendly|formal`؛ یادداشت‌های مهم تا ۱۵۰۰ نویسه) ← وارد system prompt می‌شود.
- پاسخ فعلی مورد انتظار افزونه: `{reply, suggestions}` (حداکثر ۴ پیشنهاد)؛ برای ویس/عکس فیلد `attachments` اضافه می‌شود.
- تأخیر/تجمیع پیام‌ها (`ai_delay`) سمت افزونه است؛ بک‌اند یک بستهٔ پیام (`message` + `messages[]`) دریافت می‌کند.
- لاگ‌ها: افزونه جدول `paia_logs` دارد (`LogStore::add(status,message,data)`)؛ مسیر انتقال لاگ بک‌اند ← افزونه هنوز طراحی نشده.

### پیام‌رسانی (پیاده‌شده در 0.3.4)

**نقش‌ها:** `user` (بازدیدکننده)، `admin` (پشتیبان انسانی)، `assistant` (هوش مصنوعی). برای LLM فقط دو نقش وجود دارد: `admin` به‌صورت `assistant` با برچسب «این پیام را پشتیبان انسانی فرستاده» در تاریخچه می‌آید (مرحلهٔ 0.5.0).

**هر کاربر فقط یک گفتگو دارد.** کلید یکتا `conversations.owner_key`: `u:{wp_user_id}` برای کاربر واردشده، `v:{visitor_uid}` برای مهمان. اگر مهمانی که قبلاً چت کرده وارد شود (و گفتگوی `u:` نداشته باشد) همان گفتگو به حساب او منتقل می‌شود؛ `wp_user_id=0` (مهمان وردپرس) معادل `null` است. ساخت همزمان با unique + savepoint امن است.

| جدول | ستون‌ها |
|---|---|
| `conversations` | `id`، `owner_key` (unique)، `visitor_uid`، `wp_user_id`، `created_at`، `updated_at`، `summary`، `summarized_upto_message_id`، `summary_updated_at` |
| `messages` | `id`، `conversation_id` (FK، cascade)، `visitor_uid`، `wp_user_id`، `role`، `content`، `attachment_type` (`image\|voice`)، `attachment_url`، `attachment_path`، `suggestions` (JSON، حداکثر ۴)، `admin_user_id`، `admin_name`، `transcript` (0.6.0؛ متن ویس)، `created_at` |

ستون‌های خلاصه (`summary`، `summarized_upto_message_id`، `summary_updated_at`) روی `conversations` و جدول `summary_requests` در 0.3.5 اضافه شدند. چون Alembic هنوز نیست، `Database.init()` ستون‌های nullableِ جدید را روی جدول‌های موجود با `ALTER TABLE ... ADD COLUMN` اضافه می‌کند (`_add_missing_columns` در `app/db/base.py`)؛ دیتابیس قدیمی نیازی به پاک‌شدن ندارد.

**`POST /v1/chat/messages`**

```jsonc
{"conversation_id": null,            // اختیاری؛ اگر نبود، با visitor_uid (+wp_user_id) گفتگو پیدا/ساخته می‌شود
 "visitor_uid": "abc123", "wp_user_id": 12,
 "role": "user",                     // user | admin | assistant
 "content": "سلام",
 "attachment_type": "voice",         // image | voice (همراه attachment_url)
 "attachment_url": "https://example.com/wp-content/uploads/…/a.ogg",
 "suggestions": ["…"],               // حداکثر ۴ (برای assistant)
 "admin_user_id": 3, "admin_name": "علی",   // برای role=admin، admin_user_id اجباری است
 "generate_ai_answer": true}                 // فقط برای role=user معنا دارد؛ نبود/false = فقط ذخیره
// ← 200
{"conversation_id": 7, "created_conversation": false,
 "attachment_status": "stored",      // none | stored | failed
 "message": {"id": 91, "conversation_id": 7, "visitor_uid": "abc123", "wp_user_id": 12, "role": "user",
             "content": "سلام", "attachment_type": "voice", "attachment_url": "…",
             "attachment_path": "voice/2026/10/<uuid>.ogg", "suggestions": null,
             "created_at": "2026-10-30T10:00:00Z", "admin_user_id": null, "admin_name": null},
 "ai_answer": {"status": "generated",         // skipped | generated | failed
               "message": {"id": 92, "role": "assistant", "content": "…", …},   // پاسخ ذخیره‌شده؛ null اگر skipped/failed
               "full_history_tokens": 812, "context_tokens": 640,
               "summarization_scheduled": false}}
```
جریان: ① اعتبارسنجی ② گفتگو پیدا/ساخته می‌شود ③ پیام **بلافاصله commit** می‌شود ④ اگر پیوست بود دانلود می‌شود و `attachment_path` در همان ردیف به‌روز می‌شود. اگر دانلود شکست بخورد پیام می‌ماند، `attachment_path=null` و `attachment_status=failed` (دلیل در لاگ `messaging.attachment_failed`)؛ افزونه نباید پیام را دوباره بفرستد.

**پیوست‌ها:** مسیر در دیتابیس **نسبی به `UPLOADS_DIR`** است (`{نوع}/{سال}/{ماه}/{uuid}.{پسوند}`)؛ برای خواندن فایل در مرحلهٔ LLM از `storage.resolve_attachment_path(settings, path)` استفاده کنید (مسیر خارج از uploads را رد می‌کند). نوع فایل از امضای بایت‌ها تشخیص داده می‌شود (عکس: jpg/png/gif/webp؛ ویس: ogg/mp3/wav/webm/m4a/aac/amr/flac). SSRF: فقط میزبان/پورت `PLUGIN_BASE_URL`، بدون redirect، https (در development http هم مجاز). فایل‌های چت فعلاً در سقف ۲۰۰MB پایگاه دانش حساب نمی‌شوند (تصمیم باز ۴).

**حذف:** `DELETE …/messages/{message_id}` یک پیام و فایلش را پاک می‌کند (404 اگر پیام در آن گفتگو نبود)؛ `DELETE …/messages` همهٔ پیام‌ها و فایل‌ها را پاک می‌کند و خود گفتگو می‌ماند. پاک‌کردن فایل پس از commit و بهترین‌تلاش است.

**ساخت سرویس:** `MessagingService` (با `Database` مخصوص خودش، مثل `KnowledgeService`) در startup روی `app.state.messaging_service` ساخته می‌شود و در `lifespan` بسته می‌شود؛ تست‌ها با `create_app(settings, messaging_service=...)` سرویس خودشان را تزریق می‌کنند. `Database.init()` جدول‌های messaging را هم با `create_all` می‌سازد (import مدل‌ها در `app/db/base.py`).

**لاگ‌ها** (بدون متن پیام و URL): `messaging.message_saved`، `messaging.attachment_stored` / `attachment_failed` / `attachment_error` / `attachment_db_failed`، `messaging.message_deleted`، `messaging.messages_cleared`.

### تاریخچه، تولید پاسخ و خلاصه‌سازی (پیاده‌شده در 0.3.5)

**کلید `generate_ai_answer`:** داخل همان JSON پیام (`POST /v1/chat/messages`). پیام `admin` و `assistant` همیشه فقط ذخیره می‌شود (حتی اگر کلید `true` باشد). پیام `user` بدون کلید یا با `false` فقط ذخیره می‌شود. با `true`: ① پیام ذخیره (commit) ② تاریخچه ساخته می‌شود ③ پاسخ تولید و با نقش `assistant` ذخیره می‌شود ④ پاسخ در `ai_answer.message` به کلاینت برمی‌گردد ⑤ **بعد از ارسال پاسخ به کلاینت** (FastAPI `BackgroundTasks`) در صورت نیاز درخواست خلاصه‌سازی ثبت می‌شود؛ کلاینت منتظر آن نمی‌ماند. اگر ساخت پاسخ خطا بدهد، پیام کاربر ذخیره‌شده می‌ماند و `ai_answer.status="failed"` است (دلیل در لاگ `messaging.answer_failed`).

**تاریخچه (`app/services/conversation/history.py` ← `HistoryBuilder.build`):** اگر خلاصه‌ای نیست ← **کل گفتگو** کامل + پیام آخر. اگر خلاصه هست ← خلاصه + **همهٔ پیام‌های بعد از مرز خلاصه** کامل + پیام آخر؛ و اگر بعد از مرز کمتر از ۳ زوج باشد، ۳ زوج آخر هم کامل می‌آید (با خلاصه کمی هم‌پوشانی دارد، عمدی). «زوج» = یک پیام کاربر + همهٔ پاسخ‌های بعدش (assistant و/یا admin) تا پیام بعدی کاربر؛ زوج‌ها با شمارش پیام‌های *user* از آخر پیدا می‌شوند. خروجی `PreparedHistory`: `summary`، `recent`، `question`، `full_history_tokens`، `context_tokens`، `needs_summary` و `llm_messages()` (فهرست `{role, content}`؛ `admin` برای مدل `assistant` است با برچسب «[Message from a human support agent]»). **هیچ اتصال به AI و system prompt هنوز نیست**؛ مرحلهٔ 0.5.0 فقط `PreparedHistory` را می‌گیرد. پیام بدون متن: ویس/عکس با `[voice message]` / `[image]` نشان داده می‌شود.

**پاسخ ثابت:** `FixedAnswerGenerator` (`answer.py`) همیشه `FIXED_ANSWER` را برمی‌گرداند؛ رابط `AnswerGenerator.generate(history, ctx)` همان است که LLM واقعی جایش می‌آید (تزریق از `MessagingService(answer_generator=...)`).

**شمارش توکن و آستانه:** `full_history_tokens` = توکن (tiktoken) «خلاصهٔ ذخیره‌شده + همهٔ پیام‌هایی که هنوز در خلاصه نیامده‌اند (شامل پیام آخر)». اگر از `SUMMARY_TRIGGER_TOKENS` بیشتر باشد، `needs_summary=true` و بعد از ارسال پاسخ درخواست ثبت می‌شود (`upto_message_id` = شناسهٔ همان پاسخ). اگر برای آن گفتگو درخواست pending/processing باشد، درخواست تازه ثبت نمی‌شود.

**جدول `summary_requests`:** `id`، `conversation_id` (FK، cascade)، `upto_message_id`، `status` (`pending|processing|done|failed`)، `attempts`، `error`، `available_at`، `started_at`، `finished_at`، `created_at`، `updated_at`.

**worker (`python -m app.workers.summarizer`):** صف را poll می‌کند؛ هر درخواست را اتمیک برمی‌دارد (`pending→processing`)، «خلاصهٔ قبلی + پیام‌های بعد از آن تا `upto_message_id`» را به AI می‌دهد (پرامپت انگلیسی `SUMMARIZATION_SYSTEM_PROMPT` در `summarizer.py`؛ خلاصه به زبان کاربر یعنی فارسی نوشته می‌شود، شناسه‌ها/عددها دقیق نگه داشته می‌شوند، متن گفتگو «داده» است نه دستور)، و نتیجه را در `conversations.summary` + `summarized_upto_message_id` ذخیره می‌کند. خطا ← تلاش مجدد با تأخیر (`SUMMARY_MAX_ATTEMPTS`)، سپس `failed`. اگر وسط کار گفتگو پاک شده باشد نتیجه دور ریخته می‌شود؛ خلاصهٔ جدیدتر هرگز با قدیمی‌تر جایگزین نمی‌شود.

**پاک‌کردن:** `DELETE …/messages` (پاک‌کردن کل گفتگو) خلاصه، مرز آن و همهٔ `summary_requests` را هم پاک می‌کند. حذف *یک* پیام خلاصهٔ موجود را تغییر نمی‌دهد.

**لاگ‌ها** (بدون متن): `messaging.answer_generated` (توکن‌ها و `needs_summary`)، `messaging.answer_failed`، `summary.enqueued` / `enqueue_skipped` / `enqueue_failed`، `summary.done` (نتیجه: `saved|already_covered|nothing_to_summarize|conversation_deleted|discarded_*`)، `summary.retry`، `summary.failed`، `summarizer.request` (توکن‌های مصرفی).

### تولید پاسخ واقعی (پیاده‌شده در 0.5.0)

**جریان هر پاسخ** (`LLMAnswerGenerator.generate`، جای `FixedAnswerGenerator`؛ در `lifespan` خودکار وصل می‌شود و embedder/Qdrant همان `KnowledgeService` را مشترک می‌گیرد چون Qdrant توکار فقط یک پروسه است):

```
PreparedHistory ─▶ 1) query: آخرین پیام کاربر (اگر <۲۵ نویسه بود، پیام قبلیِ کاربر هم پیش‌بند می‌شود؛ ویس/عکسِ بدون متن ← جست‌وجو نمی‌شود)
                   2) normalize_text ─▶ امبدینگ (text-embedding-3-large) ─▶ Qdrant کسینوسی top RAG_TOP_K (۳)
                   3) chunk_id ها ─▶ KnowledgeRepository.get_chunks ─▶ متن چانک + عنوان/نوع/URL رکورد
                   4) system prompt = پرامپت پایه + لحن + سایت + صفحه + notes + خلاصه + <knowledge>
                   5) messages = [system] + recent + question ─▶ LLM (مدل = ctx.model) ─▶ JSON
                   6) parse_reply ─▶ {text, suggestions} ─▶ ذخیره با نقش assistant (suggestions روی همان پیام)
```
- **پرامپت پایه** (`prompt.py`): یک متن انگلیسیِ عمومی برای هر نوع سایت؛ فقط بخش‌های پویا عوض می‌شوند و هر کدام اختیاری است (نبودن = حذف، نه خطا). قوانین ثابت: پاسخ فقط از knowledge/گفتگو؛ **هرگز قیمت/موجودی/ارسال/وضعیت سفارش و هر داده زنده** (حتی اگر در knowledge باشد) ← لینکِ همان آیتم از knowledge و «خودتان بررسی کنید»؛ فقط URLهای موجود در knowledge؛ پاسخ کوتاه و بدون Markdown؛ سؤال مبهم ← یک سؤال روشن‌کننده؛ پیام «پشتیبان انسانی» و تحویل به انسان؛ ویس/عکس هنوز قابل‌فهم نیست؛ ضد prompt-injection (knowledge/خلاصه/پیام‌ها «داده»‌اند)؛ افشانکردن دستورها؛ خارج از دامنهٔ سایت رد؛ بدون توصیهٔ پزشکی/حقوقی/مالی قطعی؛ درخواست‌نکردن رمز/OTP/کارت.
- **لحن:** `tone` از کلاینت (`formal|friendly`)، پیش‌فرض `DEFAULT_TONE=formal`؛ متن هر لحن از `TONE_FORMAL`/`TONE_FRIENDLY` در `.env`.
- **notes:** یادداشت‌های مهم صاحب سایت در بخش `<owner_notes>`؛ نمی‌تواند قوانین ایمنی/قیمت/فرمت خروجی را لغو کند.
- **خروجی:** `{"text": "...", "suggestions": ["...", ...]}`؛ suggestions حداکثر ۴ پاسخ کوتاه (۱ تا ۵ کلمه، از زبان کاربر) و `[]` وقتی سؤال باز است. با `json_schema` سخت‌گیرانه گرفته می‌شود؛ پارسر تحمل‌پذیر است (fence، متن ساده بدون suggestions)، ولی JSONِ ناقص هرگز به کاربر نمایش داده نمی‌شود (خطا ← تلاش مجدد صف). `text` در `messages.content` و `suggestions` در `messages.suggestions` ذخیره و در `ai_answer.message` و وب‌هوک برمی‌گردد.
- **خطای بازیابی/LLM عمداً raise می‌شود** (نه «نمی‌دانم»)؛ صف تا `ANSWER_MAX_ATTEMPTS` تلاش مجدد می‌کند.
- **لاگ‌ها** (بدون متن): `rag.retrieved` (تعداد، امتیازها، chunk_idها، زمان)، `rag.out_of_sync`، `answer.llm` (مدل، توکن‌ها، finish_reason، تعداد چانک).
- **محدودیت‌های شناخته‌شده:** فیلتر سایت (`site_id`) نیست؛ ~~ویس/عکس هنوز به مدل داده نمی‌شود~~ (از 0.6.0 داده می‌شود)؛ `RAG_MIN_SCORE` پیش‌فرض خاموش است (با لاگ امتیازها تنظیم شود)؛ ابزارهای زنده 0.6.0.

### پایگاه دانش: چانکینگ ← امبدینگ ← Qdrant (پیاده‌شده در 0.3.0)

**جریان هر رکورد** (`KnowledgeService.upsert_many`، یکی‌یکی و به‌ترتیب دریافت):

```
{id, data} → (hash بدون تغییر؟ → "unchanged") → render فارسی → نرمال‌سازی → چانک (tiktoken)
           → امبدینگ فقط چانک‌های تغییرکرده → upsert در Qdrant → حذف نقطه‌های اضافهٔ چانک‌های حذف‌شده
           → ذخیرهٔ record + chunks در دیتابیس متنی (یک تراکنش)
```
اگر هر مرحله‌ای قبل از تراکنش آخر شکست بخورد، رکورد در `failed_ids` می‌آید (افزونه نباید `ai_status=1` بزند) و ارسال مجدد امن است (idempotent؛ شناسهٔ نقطه‌ها قطعی است). حذف: ابتدا Qdrant (فیلتر `record_id`) و بعد دیتابیس متنی؛ شناسهٔ ناموجود هم تأیید می‌شود (`not_found`) ولی Qdrant برای پاک‌کردن یتیم‌ها در هر حال فراخوانی می‌شود. روی هر `record_id` یک lock درون‌پردازشی هست.

**شناسه‌ها:** `record_id` = همان شناسهٔ افزونه (`product:14542`، `blog:15650`، `page:15434`، `site:1`، `product_taxonomies:all`، …؛ الگو: `^[A-Za-z0-9_]{1,40}:[A-Za-z0-9_.\-]{1,64}$`). `chunk_id` = `{record_id}#{index}` (مثلاً `product:14542#1`). شناسهٔ نقطهٔ Qdrant = `uuid5(chunk_id)`.

**دیتابیس متنی** (مرجع واحد داده؛ بردارها فقط در Qdrant):

| جدول | ستون‌ها |
|---|---|
| `records` | `record_id` (PK)، `kind` (پیشوند شناسه)، `type`، `title`، `url`، `data` (JSON کامل خام: تصاویر، sku، واحد پول، لینک…)، `data_hash`، `pipeline`، `chunk_count`، `token_count`، `created_at`، `updated_at` |
| `chunks` | فقط `chunk_id` (PK)، `record_id` (FK، cascade)، `index`، `text`، `tokens`، `hash` |

**Payload کیودرنت** (فقط فیلدهای کوچک فیلتر + شناسه‌ها): `record_id`, `chunk_id`, `chunk_index`, `kind` (`main`|`reviews`), `type`, `url`, `title`. متن و عکس‌ها در payload نیست.

**مسیر بازیابی (برای مرحلهٔ RAG):** جست‌وجوی برداری ← `payload.chunk_id`ها ← `KnowledgeRepository.get_chunks(chunk_ids)` (چانک + رکورد والد با `record_id`، به‌ترتیب نتایج) ← متن از `chunks.text`، تصاویر/sku/لینک از `records.data`. تغییر عکس‌ها فقط در `records` اتفاق می‌افتد. **پرسش کاربر هم باید با `normalize_text` همین پروژه نرمال شود.**

**چه چیزی وارد متن امبدینگ می‌شود؟** هر مورد به فارسی «مورد‌به‌مورد» نوشته می‌شود؛ URL عکس/لینک و JSON خام وارد متن نمی‌شوند (در `records.data` می‌مانند).

| رکورد | چانک‌ها |
|---|---|
| محصول | `main`: عنوان (سطر زمینه) + دسته‌بندی/برچسب + «توضیح کوتاه» + «مشخصات محصول» + محتوا. مشخصات: نوع محصول، SKU، واحد پول (`تومان (IRT)`)، نوع تحویل (فیزیکی/مجازی/دانلودی)، محدودیت خرید تکی، تعداد فروش، امتیاز و تعداد امتیاز، تعداد دیدگاه، تعداد/نقش تصاویر (`3 تصویر (1 شاخص، 2 گالری)`)، نویسنده، تاریخ انتشار/به‌روزرسانی **شمسی**، و در صورت وجود `attributes`/`variations`/`custom_fields` (رندر عمومی). `reviews`: دیدگاه‌ها **جدا**، هرکدام `دیدگاه کاربر (امتیاز 4 از 5، 30 آذر 1404): …`، گروه‌بندی‌شده تا سقف چانک و بدون overlap |
| مقاله/برگه/سایر | `main`: عنوان + دسته/برچسب + نویسنده + تاریخ + تصاویر + خلاصه + محتوا (shortcodeهای صفحه‌ساز مثل `[vc_row]` و HTML پاک می‌شود) |
| دسته‌بندی‌ها (`*_taxonomies:all`) | یک متن: هر taxonomy یک بخش، هر term یک سطر `- گلدان‌ها: 4 محصول` |
| سایت (`site:1`) | نام، شعار، آدرس، زبان، وجود فروشگاه و واحد پول، تعداد محتواها. **عمداً حذف:** نسخهٔ وردپرس/PHP، قالب، صفحه‌ساز (بی‌فایده و افشای اطلاعات فنی) |
| همه | قیمت/موجودی عمداً در متن نیست (باید با tool زنده گرفته شود) |

**نرمال‌سازی فارسی** (`normalize.py`): `ي/ى→ی`، `ك→ک`، `ة/ھ→ه`، حذف اعراب و کشیده؛ **ارقام فارسی/عربی → ASCII** (و `٫ ٬ → . ,`؛ توکن کمتر و هم‌خوان با SKU)؛ نیم‌فاصله: ادغام تکراری‌ها، حذف کنار فاصله/خط، حذف نویسه‌های نامرئی، `می/نمی + فعل` و `کلمه + ها/های` با نیم‌فاصله؛ فاصله/خط خالی اضافه جمع می‌شود.

**آستانه‌های چانکینگ** (توکن‌ها با `tiktoken` / `cl100k_base`؛ همه از `.env`):

| تنظیم | مقدار | معنا |
|---|---|---|
| `CHUNK_MAX_TOKENS` | 600 | متنی که کلاً تا این اندازه باشد **یک چانک** می‌ماند (بیشتر محصولات/مقاله‌های کوتاه) |
| `CHUNK_TARGET_TOKENS` | 400 | هدف اندازهٔ هر چانک برای متن‌های بلندتر (محدودهٔ رایج RAG: ۳۰۰–۵۰۰) |
| `CHUNK_OVERLAP_TOKENS` | 60 | ≈۱۵٪؛ آخرین جمله/جمله‌ها (تا ۶۰ توکن) ابتدای چانک بعدی تکرار می‌شود (فقط وقتی برش وسط یک بخش است) |
| `CHUNK_MIN_TOKENS` | 80 | چانک کوچک‌تر یا تا سقف MAX رشد می‌کند یا (اگر آخرین چانک باشد) به قبلی ادغام می‌شود |

الگوریتم: تقسیم بر اساس عنوان‌های markdown (`#`…) ← پاراگراف ← (در صورت بزرگ‌بودن) خط ← جمله (`. ! ? ؟ … ؛`) ← کلمه ← برش نویسه‌ای؛ بسته‌بندی حریصانه تا TARGET؛ بخش جدید وقتی چانک جاری ≥ نصف TARGET باشد چانک جدید شروع می‌کند. **هر چانک** با سطر زمینه (`محصول: …` / `مقاله: …`) و مسیر بخش (`بخش: الف › ب`) شروع می‌شود تا مستقل قابل‌فهم باشد. تغییر هر آستانه، `CHUNKER_VERSION` یا مدل/بُعد امبدینگ ← مقدار `pipeline` عوض می‌شود و رکورد در ارسال بعدی دوباره چانک و امبدینگ می‌شود.

**ارسال مجدد هوشمند:** اگر `data_hash` و `pipeline` همان قبلی باشد ← `unchanged` (بدون امبدینگ). اگر داده عوض شده باشد فقط چانک‌هایی که `hash` متنشان فرق کرده امبدینگ می‌شوند (`embedded_chunks` / `reused_chunks`). `force=true` همه را دوباره می‌سازد.

**قرارداد API (از 0.3.6 پاسخ POST/PUT/DELETE فقط «پذیرفته شد» است؛ این بخش بدنهٔ ورودی و فیلدهای نتیجهٔ هر رکورد را توضیح می‌دهد که حالا در `ingest_jobs.result` ذخیره می‌شود؛ شکل پاسخ جدید: بخش «صف ingestion»):**

```jsonc
// POST /v1/knowledge/records   (بدنه حداکثر MAX_BODY_BYTES؛ ۱..۱۰۰ رکورد؛ `data` شیء JSON یا رشتهٔ JSON)
{"records": [{"id": "product:13199", "data": {…}}, {"id": "blog:15650", "data": {…}}], "force": false}
// ← 200
{"confirmed_ids": ["product:13199"], "failed_ids": ["blog:15650"],
 "summary": {"total": 2, "created": 1, "updated": 0, "unchanged": 0, "failed": 1},
 "results": [{"id": "product:13199", "status": "created", "chunks": 2, "embedded_chunks": 2,
              "reused_chunks": 0, "tokens": 508, "embedding_tokens": 510, "duration_ms": 830, "error": null},
             {"id": "blog:15650", "status": "failed", "error": "Embedding request failed (…)"}]}

// DELETE /v1/knowledge/records   (بدنهٔ JSON روی DELETE؛ ۱..۵۰۰ شناسه)
{"ids": ["product:13199", "page:15434"]}
// ← 200 {"confirmed_ids": [...], "failed_ids": [...],
//        "results": [{"id": "...", "status": "deleted|not_found|failed", "chunks_removed": 2, "error": null}]}
```
از 0.3.6 افزونه رکورد را با 200 «در حال پردازش» (`ai_status=1`) می‌کند و فقط با callback `trained` (یا `/v1/jobs/status`) «پردازش‌شده» (`2`) می‌شود. `status` ∈ `created|updated|unchanged|failed`. اگر کل بدنه نامعتبر باشد 422 و اگر Qdrant/دیتابیس از ابتدا در دسترس نباشد 503 می‌آید. بدنه‌های بزرگ: با سقف ۵MB، دسته‌های ~۲۰ رکوردی بفرستید.

**لاگ‌ها** (`logs/app.log`، بدون متن رکورد/راز؛ فقط شناسه و عدد): `knowledge.upsert_batch` ← `knowledge.upsert_start` ← `knowledge.chunked` (نوع، تعداد چانک، توکن هر چانک، مجموع، طول متن، دقیق‌بودن tokenizer، زمان) ← `embedding.request` (تعداد ورودی، `prompt_tokens`، `total_tokens`، زمان) ← `knowledge.qdrant_upserted` / `knowledge.qdrant_stale_removed` ← `knowledge.db_saved` ← `knowledge.upsert_done` (وضعیت نهایی، توکن‌ها، زمان). حالت‌های ویژه: `knowledge.unchanged`، `knowledge.no_new_chunks`، `knowledge.token_mismatch` (اختلاف >۱۵٪ بین tiktoken و usage API)، `knowledge.upsert_failed`، و برای حذف `knowledge.delete_batch` / `knowledge.delete_done`. اگر tiktoken نتواند واژگانش را دانلود کند، هشدار «APPROXIMATE token counter» در لاگ می‌آید و شمارش تقریبی (نویسه ÷ ۲٫۲) استفاده می‌شود.

**محدودیت‌های شناخته‌شده:** (۱) lock هر رکورد فقط درون یک پردازش است؛ با `WORKERS>1` دو درخواست همزمان روی یک شناسه محافظت نمی‌شوند. (۲) جدول‌ها با `create_all` ساخته می‌شوند؛ Alembic همراه مرحلهٔ 0.2.0 می‌آید. (۳) فیلد `site_id` فعلاً نیست (تک‌سایتی؛ تصمیم باز ۵). (۴) رندر `attributes`/`variations` حدسی/عمومی است چون نمونهٔ واقعی نداشتیم؛ با اولین نمونهٔ واقعی دقیق شود.

### صف ingestion و callback تکمیل (پیاده‌شده در 0.3.6)

**اصل:** کلاینت (افزونه) و سرور هیچ‌وقت منتظر چانکینگ/امبدینگ نمی‌مانند. درخواست فقط ثبت می‌شود؛ کار سنگین در worker جدا؛ و وقتی تمام شد، سرور به افزونه خبر می‌دهد (قرارداد افزونه: `apis/README.md`، نسخهٔ قرارداد 1).

```
افزونه ─POST {event,id,rev,data,callback}─▶ API: اعتبارسنجی + یک تراکنش insert ─▶ 200 {job_id}   (ارتباط بسته می‌شود؛ افزونه ai_status=1)
                                                │ ingest_jobs (pending)
                      worker_ingestion:  چانک → امبدینگ → Qdrant → دیتابیس متنی   (هر job یک رکورد؛ تلاش مجدد برای خطای موقت)
                                                │ در همان تراکنشِ پایان job:  یک ردیف در callback_deliveries (outbox)
                      worker_delivery:   دسته‌ای  POST {callback.url} {job_id, delivery_id, results:[{id,status,rev}]}  (امضا HMAC)
افزونه: ai_status=2 (trained) ◀──────────────┘
```

**درخواست (دو شکل معتبر):**

```jsonc
// شکل افزونه (README §7): یک رکورد
{"event": "create|update", "id": "product:23", "rev": "5d41402a…", "data": {…}, "callback": {…descriptor…}}
// شکل دسته‌ای (۱..۱۰۰ رکورد)؛ rev/event هر رکورد اختیاری، callback یکی برای همه
{"records": [{"id": "product:23", "data": {…}, "rev": "…"}], "force": false, "callback": {…}}
// ← 200 فوری
{"accepted": true, "job_id": "j_8f3a1c2d0e", "queued_ids": ["product:23"],
 "jobs": [{"id": "product:23", "job_id": "j_8f3a1c2d0e", "status": "queued"}],
 "superseded": 0, "callback": "registered"}      // "none" اگر callback نبود (آن‌وقت فقط با /v1/jobs/status پیگیری شود)
```
- کد پاسخ **200** است (افزونه هر 2xx را «گرفتم» می‌داند؛ اگر 202 خواستید فقط `status_code` در `knowledge.py` عوض شود).
- `422 invalid_callback`: آدرس نامعتبر؛ `503`: صف/دیتابیس در دسترس نیست ← افزونه `ai_status` را 0 نگه می‌دارد. همهٔ رکوردهای یک درخواست یا با هم ثبت می‌شوند یا هیچ‌کدام.
- ثبت درخواست **به OpenAI/Qdrant وابسته نیست**؛ حتی اگر آن‌ها خاموش باشند درخواست ثبت می‌شود و job بعداً انجام می‌شود.
- `rev` ذخیره و **بدون تغییر** در callback برمی‌گردد (افزونه نتیجهٔ کهنه را `stale` می‌گیرد). `callback` هم کنار job ذخیره می‌شود؛ آدرس هرگز از جای دیگر نمی‌آید.

**قواعد صف:**
- جایگزینی: ارسال تازه برای رکوردی که job قبلی‌اش هنوز شروع نشده، آن را `superseded` می‌کند (فقط آخرین نسخه پردازش می‌شود؛ callback نسخهٔ قدیمی فرستاده نمی‌شود).
- یک رکورد هرگز دو job هم‌زمان ندارد (job بعدی تا پایان قبلی منتظر می‌ماند).
- `create|update` ← upsert هوشمند (فقط چانک‌های تغییرکرده امبدینگ می‌شوند)؛ `PUT` ← حذف کامل و افزودن؛ `DELETE` ← حذف.
- خطای موقت (`upstream_error` / `service_unavailable` / `internal_error`) ← تلاش مجدد با تأخیر؛ خطای دائمی (`unprocessable_record`) ← فوراً `failed`. بعد از `INGEST_MAX_ATTEMPTS` هم `failed`. worker مرده ← job بعد از `INGEST_STALE_SECONDS` دوباره برداشته می‌شود.
- نتیجهٔ callback: upsert موفق ← `trained` (+`rev`)؛ حذف ← `untrained`؛ شکست نهایی ← `failed` (+`error` کوتاه). ستون `payload` بعد از پایان null می‌شود.

**callback (worker_delivery)** — طبق README افزونه §3 و §5:
- امضا: `X-PAIA-Timestamp`، `X-PAIA-Signature: sha256=hex(HMAC(key, "{ts}.{body}"))`، `X-PAIA-Delivery` (UUID؛ در تلاش مجدد همان، با همان مجموعه نتیجه). کلید = `PLUGIN_CALLBACK_SECRET` یا `API_SHARED_SECRET`.
- **دسته‌ای:** نتایج هر سایت با `CALLBACK_BATCH_WINDOW_SECONDS` تجمیع و در یک درخواست می‌روند (≤ `max_results` سایت، ≤ ~۹۰۰KB). هر نتیجه علاوه بر قرارداد، فیلد `job_id` خودش را دارد؛ `job_id` سطح بالا وقتی یک job است همان، وگرنه شناسهٔ دسته (`b_…`).
- **کلاینت کند:** timeout سخت (connect ≤ 3s، کل ≤ 10s، سقف مطلق با `wait_for`)، بدنهٔ پاسخ حداکثر ۸KB، بدون redirect؛ برای هر سایت یک ارسال در هر زمان + فاصلهٔ حداقل؛ سقف سراسری سایت‌های هم‌زمان؛ سایت کند هیچ سایت دیگر و هیچ worker هوش مصنوعی را معطل نمی‌کند.
- **تلاش مجدد:** timeout/408/429/5xx/۴۰۳ WAF ← backoff نمایی با jitter (30ث، ۲د، ۱۰د، ۱س، ۶س) و احترام به `Retry-After`؛ حداکثر `CALLBACK_MAX_ATTEMPTS` سپس `dead`.
- **circuit breaker:** بعد از `CALLBACK_BREAKER_FAILURES` شکست پیاپی سایت `unhealthy` می‌شود: هیچ نتیجه‌ای نمی‌رود و فقط `ping_url` هر `CALLBACK_PING_INTERVAL_SECONDS` صدا زده می‌شود؛ با اولین ping موفق ارسال از سر گرفته می‌شود. `401` (کلید اشتباه) ← `auth_failed`؛ `404` / `503 paia_not_configured` ← `suspended`؛ هر دو فقط ping، نتیجه‌ها می‌مانند. `401 paia_stale_timestamp` ← با `server_time` سایت دوباره امضا و یک‌بار تلاش. `400` ← تلاش مجدد ندارد (باگ سمت ما، لاگ ERROR). `413` ← دسته نصف می‌شود.
- **تطبیق با سرعت سایت:** اندازهٔ دسته با زمان پاسخ تنظیم می‌شود (کند > ۴ث ← نصف، سریع < ۱٫۵ث ← دو برابر، ۱۰..۵۰۰).
- **SSRF** (README §3): فقط https (در توسعه با `CALLBACK_ALLOW_PRIVATE_NETWORKS` http هم)، فقط پورت 443/8443، بدون credential؛ **هاست `callback.url` باید با `PLUGIN_BASE_URL` (یا اولین سایتی که ثبت شده؛ جدول `callback_sites`) یکی باشد**؛ `ping_url` همان هاست؛ قبل از هر ارسال DNS حل می‌شود، آدرس‌های خصوصی/loopback/link-local/CGNAT رد می‌شوند و اتصال به **همان IP بررسی‌شده** انجام می‌شود (ضد DNS-rebinding؛ SNI/Host اصلی حفظ می‌شود).
- وضعیت سلامت هر سایت در جدول `callback_sites` (`state`, `consecutive_failures`, `last_error`, `last_latency_ms`, `batch_limit`) قابل مشاهده است.

**جدول‌ها:** `ingest_jobs` (job_id، record_id، action، rev، payload، callback، status، attempts، outcome، result، error…)، `callback_sites`، `callback_deliveries` (outbox: پایان job و ثبت delivery در یک تراکنش؛ نتیجه بین دو مرحله گم نمی‌شود).

**لاگ‌ها** (بدون متن رکورد/راز): `ingest.enqueued`، `ingest.job_done` / `job_retry` / `job_failed` / `job_crashed`، `ingest.purged`، `callback.delivered` / `failed` / `site_stopped` / `site_recovered` / `ping_failed` / `bad_request` / `batch_split` / `delivery_dead` / `clock_offset`.

**محدودیت‌های شناخته‌شده:** (۱) worker تحویل باید یک نمونه باشد (دو نمونه فقط تحویل تکراریِ بی‌ضرر می‌سازند). (۲) `dead` شدن نتیجه یعنی افزونه باید با `POST /v1/jobs/status` وضعیت را بگیرد؛ فراخوانی خودکار آن سمت افزونه هنوز نیست. (۳) فیلد `job_id` هر نتیجه و `job_id` دسته‌ای (`b_…`) افزودهٔ ما به قرارداد است (افزونه باید فیلد ناشناخته را نادیده بگیرد). (۴) SQLite با چند پروسه (API + workerها) کار می‌کند (WAL)؛ برای بار بالا PostgreSQL. (۵) در حالت `QDRANT_PATH` حلقهٔ ingestion داخل API است (چانکینگ در thread)؛ برای production Qdrant سرور + worker جدا. (۶) *(برطرف‌شد در 0.4.0)* `text:*` و `file:*` از همین صف می‌گذرند.

### متن‌ها و فایل‌ها (پیاده‌شده در 0.4.0)

هر دو از **همان مسیر صف 0.3.6** می‌گذرند (`POST|PUT|DELETE /v1/knowledge/records` ← job ← worker ← callback)؛ `IngestProcessor` بر اساس پیشوند شناسه به `ContentService` (`services/ingestion/content.py`) می‌دهد و بقیهٔ رکوردها مثل قبل به `KnowledgeService`. هر دو با `KnowledgeService.store_prebuilt` ذخیره می‌شوند (همان قفل، همان ترتیب Qdrant ← دیتابیس، همان ارسال مجدد هوشمند).

**`text:*`** — بدنه `data: {type:"text", chunks:[...], text:"..."}`:
- چانک‌های افزونه **همان‌طور** نگه داشته می‌شوند؛ فقط چانکی بزرگ‌تر از `CHUNK_MAX_TOKENS` با همان الگوریتم هم‌اندازه (+overlap) دوباره تقسیم می‌شود (`rechunk_text_chunks`). بدون `chunks` از `text` استفاده می‌شود؛ هیچ‌کدام نبود ← 422 `unprocessable_record` همان لحظهٔ ثبت.
- `records.type="text"`، عنوان = اولین خط غیرخالی (≤۸۰ نویسه). `render_record` هم شاخهٔ `text` دارد (پیش‌نمایش/`scripts/preview_chunks.py`).

**`file:*`** — بدنه `data: {type:"file", title, original_name, ext, size, sha256, download:{url, expires}}`:
```
افزونه: لینک امضادار کوتاه‌عمر می‌سازد  https://site/wp-json/paia/v1/ai/file?id=file:…&exp=…&sig=…
   sig = HMAC_SHA256(api_key, "paia-file\n{id}\n{exp}")     (سرور هوش مصنوعی هیچ چیز را امضا/تأیید نمی‌کند)
POST records ─▶ 200 فوری ─▶ worker: بررسی لینک ─▶ دانلود (استریم، SSRF، سقف) ─▶ sha256 ─▶ استخراج ─▶ چانک ─▶ امبدینگ ─▶ Qdrant+DB ─▶ callback
```
- **لینک:** عمر پیش‌فرض ۱ ساعت (افزونه، فیلتر `paia_file_link_ttl`، ۵ دقیقه..۲۴ ساعت). لینک منقضی ← 422 هنگام ثبت یا `failed` با `download_link_expired` در worker بدون تلاش مجدد (افزونه دوباره «آموزش» می‌زند؛ کرون بازبینی هم با `unknown` لینک تازه می‌سازد). لینک **هرگز ذخیره نمی‌شود**: بعد از پایان job ستون `payload` null می‌شود و `records.data`/hash آن را ندارند.
- **SSRF:** https (توسعه: http با `CALLBACK_ALLOW_PRIVATE_NETWORKS`)، پورت 443/8443، بدون credential، هاست باید با `PLUGIN_BASE_URL` (یا سایت قفل‌شده) یکی باشد؛ DNS قبل از اتصال حل و IPهای غیرعمومی رد و اتصال به همان IP بررسی‌شده انجام می‌شود؛ redirect دنبال نمی‌شود.
- **سقف:** هر فایل `FILES_MAX_MB`؛ مجموع `UPLOADS_MAX_TOTAL_MB` (فقط فایل‌های پایگاه دانش؛ فضای همین رکورد در جایگزینی آزاد حساب می‌شود). از حجم اعلامی پیش از دانلود و هنگام استریم بررسی می‌شود ← `storage_quota_exceeded` / `file_too_large`، بدون چانکینگ و بدون تلاش مجدد.
- **ذخیره:** `uploads/knowledge/file_<id>/source.<ext>` (`*.part` ← جابه‌جایی اتمیک). با `FILES_KEEP_ORIGINAL=false` بعد از ایندکس پاک می‌شود. حذف رکورد (`DELETE`/`PUT`) پوشه را هم پاک می‌کند؛ شکست در هر مرحله فایل یتیم باقی نمی‌گذارد.
- **تغییرنکرده:** `sha256` + عنوان + نام + پسوند + حجم، hash رکورد است؛ فایل و عنوان یکسان ← بدون دانلود `unchanged`. `sha256` اعلامی با فایل دانلودشده نخواند ← `checksum_mismatch`.
- **فرمت:** `.pdf` `.docx` `.txt` (استخراج‌گرها در `services/extractors/`، همان CLIهای مستقل). `.doc` قدیمی ← `unsupported_extension`؛ PDF اسکن‌شده بدون لایهٔ متن ← `no_text_extracted` (OCR نداریم). چانک‌ها با `فایل: عنوان` و `بخش: …` شروع می‌شوند (`doc_chunker.py`).
- **خطاها** (`error_code`، در callback `failed` + `error`): `unsupported_extension`، `invalid_download_url`، `download_link_expired`، `download_forbidden` (سایت 401/403)، `file_not_found`، `download_failed` (5xx/شبکه: تلاش مجدد)، `file_too_large`، `storage_quota_exceeded`، `invalid_file`، `checksum_mismatch`، `extraction_failed`، `no_text_extracted`، `extracted_text_too_large`.
- **`GET /v1/storage`** مصرف را نشان می‌دهد.

### ابزارها (0.6.0) — پیاده‌شده؛ جزئیات در بخش «حلقهٔ AI، ابزارها، ویس و تصویر» پایین‌تر
(طرح اولیه — «ابزارها با `name/description/parameters` از کلاینت» — کنار گذاشته شد: کلاینت فقط **مجوزها و فهرست ابزارهای داینامیک** را می‌فرستد؛ تعریف function را سرور می‌سازد. آدرس API از درخواست خوانده نمی‌شود، فقط `PLUGIN_BASE_URL` + مسیرهای `.env`.)

### حلقهٔ AI، ابزارها، ویس و تصویر (پیاده‌شده در 0.6.0)

**حلقهٔ فراخوانی AI** (`LLMAnswerGenerator.generate`؛ `K = ANSWER_MAX_LLM_ROUNDS`، پیش‌فرض ۲):

```
RAG ─▶ ToolKit.build(live_product_datas, tools) ─▶ system prompt ─▶ messages (+ عکس)
for دور in 1..K:
    دور < K  : مدل می‌تواند tool_calls بدهد (tool_choice=auto)
               ├ tool_call ندارد  ─▶ پاسخ نهایی (پایان؛ حالت رایج = فقط یک فراخوانی)
               └ tool_call دارد   ─▶ اجرای همزمان ابزارها (API سایت) ─▶ نتیجه‌ها با نقش `tool` به messages ─▶ دور بعد
    دور = K  : tool_choice=none + پیام سیستمی FINAL_ROUND_NOTICE («دیگر ابزار نداری؛ فقط از داشته‌هایت پاسخ بده، چیزی از خودت درنیاور»)
               ─▶ پاسخ نهایی همیشه تولید می‌شود
```
- ابزار فقط وقتی داده می‌شود که `K ≥ 2` و `PLUGIN_BASE_URL` تنظیم باشد و سایت مجوز/ابزار فرستاده باشد؛ وگرنه رفتار 0.5.0 (یک فراخوانی، بدون ابزار).
- **خطای ابزار هرگز پاسخ را خراب نمی‌کند:** متن `ERROR: …` به‌عنوان نتیجهٔ ابزار به مدل برمی‌گردد و مدل صادقانه می‌گوید اطلاعات ندارد. (خطای خود LLM مثل قبل raise می‌شود و صف پاسخ تلاش مجدد می‌کند.)
- لاگ‌ها (بدون متن): `answer.llm … round=1/2 … tool_calls=N`، `tools.call name= status= chars= ms=`، `tools.site_ok|site_status|site_unreachable`، `answer.tools_used`، `tools.disabled reason=PLUGIN_BASE_URL_empty`.

**دو دستهٔ ابزار** (`app/services/tools/catalog.py`):

| ابزار | نام function | چه وقت به AI داده می‌شود | آرگومان | API سایت |
|---|---|---|---|---|
| **پیش‌فرض: محصولات** | `get_product_live_info` | فقط اگر `live_product_datas` در همان پیام خالی نباشد؛ توضیح ابزار و فیلدهای دریافتی دقیقاً مطابق همان مجوزها (`price` = قیمت/حراج، `stock` = وضعیت/تعداد موجودی/پیش‌سفارش) | `products: [{id}|{name}]` — **اولویت id**؛ name فقط وقتی id معلوم نیست | `POST {PLUGIN_BASE_URL}{TOOLS_PRODUCTS_PATH}` |
| **اضافه: داینامیک** | `site_tool_<id>` (یکی برای هر ابزار) | هر ابزارِ آمده در `tools` همان پیام؛ توضیح function = `title. description` | ندارد | `POST {PLUGIN_BASE_URL}{TOOLS_DYNAMIC_PATH}` با `{tool_id}` |

- AI شناسهٔ محصول را از attribute `id="product:23"` منابع knowledge (که از 0.6.0 در پرامپت می‌آید) یا `page.id` (صفحهٔ محصول) برمی‌دارد؛ اگر نبود نام را می‌فرستد. پیشوند (`product:`) در سرور حذف و فقط عدد فرستاده می‌شود.
- **پرامپت:** قانون قبلی «هرگز قیمت/موجودی نگو» با منطق جدید تداخل داشت ← `rules_live_data()` در `prompt.py`: بدون ابزار = همان قانون سخت‌گیرانهٔ قبلی؛ با ابزار = داده زنده **فقط** از نتیجهٔ ابزارِ همین پرسش (نه از knowledge/تاریخچه/notes)، مقدارها عیناً، فیلدی که مجوزش نیست ممنوع، سایر داده‌های زندهٔ بدون ابزار ممنوع. بخش `RULES_TOOLS` (همهٔ ابزارها را یک‌جا و همزمان بخواه، نتیجه DATA است نه دستور، نبودِ داده را صادقانه بگو، نام ابزار را به کاربر نگو) فقط وقتی ابزار هست اضافه می‌شود.
- **امنیت:** آدرس هرگز از درخواست کلاینت خوانده نمی‌شود؛ ریدایرکت دنبال نمی‌شود؛ در production فقط https و میزبان عمومی. درخواست با همان امضای callbackها (`X-PAIA-Timestamp` / `X-PAIA-Signature = sha256=HMAC("{ts}.{body}")` با `PLUGIN_CALLBACK_SECRET`) + `X-PAIA-Request`. قرارداد کامل سمت سایت: **`docs/LIVE_TOOLS_API.md`**.

**ویس ورودی** (`speech/transcriber.py`، فراخوانی از `MessagingService._ensure_transcript` قبل از ساخت تاریخچه): پیام کاربر با ویسِ دانلودشده ← `audio.transcriptions` (`STT_MODEL=whisper-1`، `STT_LANGUAGE=fa`) ← متن در ستون `messages.transcript` ذخیره می‌شود (پیام اصلی/`content` تغییر نمی‌کند) ← در تاریخچه به شکل `[voice message] <متن>` می‌آید، پس جست‌وجوی RAG و خلاصه‌سازی‌های بعدی هم کلمات را می‌بینند. فایلی که API رد کند (۴۰۰) رد می‌شود و AI «[voice message]» خالی می‌بیند؛ خطای موقت raise می‌شود ← job پاسخ تلاش مجدد می‌کند (و چون transcript ذخیره می‌شود، دوباره پرداخت نمی‌شود). amr/aac فقط با ffmpeg نصب‌شده تبدیل می‌شود.

**تصویر ورودی** (`vision/images.py`، فراخوانی از `LLMAnswerGenerator._question_content`): فقط عکسِ **آخرین پیام کاربر** همراه همان پیام به شکل `content=[{type:text},{type:image_url,data-URL}]` می‌رود. فایل ≤ `IMAGE_MAX_KB` (۱MB) و غیر-GIF بدون تغییر؛ وگرنه با Pillow: چرخش EXIF، تخت‌کردن شفافیت روی سفید، JPEG با کیفیت ۸۵→۴۵ و در صورت نیاز کاهش ضلع بلندتر (از ۲۰۴۸ تا ۳۲۰) تا حجم جا شود. عکس‌های قدیمی‌تر فقط با برچسب `[image]` می‌مانند. اگر باز نشد (فایل نیست/Pillow نصب نیست) متن `[image - could not be opened]` می‌شود.

### ویس و تصویر (0.7.0 / 0.8.0) — ورودی‌ها در 0.6.0 پیاده شد
- ویس ورودی: دانلود ← STT ← متن وارد همان مسیر پاسخ می‌شود. ویس خروجی: TTS ← فایل موقت + لینک دانلود کوتاه‌عمر امضادار که افزونه برمی‌دارد.
- تصویر ورودی: دانلود ← تحلیل با مدل بینایی ← توضیح به زمینهٔ پاسخ اضافه می‌شود. تصویر خروجی: به‌صورت `attachments[]` در پاسخ.

### تصمیم‌های باز (قبل از مراحل مربوطه روشن شود)
1. مدل/سرویس LLM، STT و TTS (پیشنهاد: ارائه‌دهندهٔ واحد برای سادگی؛ افزونه فعلاً `gpt-5-4` را نشان می‌دهد). *(Embedding روشن شد: OpenAI `text-embedding-3-large`. مدل خلاصه‌سازی فعلاً `SUMMARY_MODEL=gpt-4.1-mini` از همان کلید OpenAI؛ قابل‌تغییر از `.env`.)*
2. پایگاه داده: SQLite کافی است یا از ابتدا PostgreSQL؟ *(فعلاً SQLite پیش‌فرض؛ با `DATABASE_URL` به PostgreSQL قابل‌تعویض است.)*
3. صف کارها: *(روشن شد در 0.3.6: صف در جدول‌های دیتابیس (SQLite/PostgreSQL) + worker جدا؛ بدون Redis. اگر چند سرور یا نرخ بالا لازم شد، همین رابط می‌تواند به Redis/ARQ منتقل شود.)*
4. آیا سقف ۲۰۰MB فقط برای فایل‌های دانش‌پایه است یا ویس/عکس‌های چت هم حساب می‌شوند؟ (پیشنهاد: فقط دانش‌پایه؛ ویس/عکس بعد از پردازش پاک یا با TTL.) *(فعلاً در 0.3.4 فایل‌های چت حساب نمی‌شوند و تا حذف پیام می‌مانند.)*
5. یک سایت (فعلی) یا چندسایته با راز جدا برای هر سایت؟ (payload از همین حالا `site_id` دارد.)

## ۱۰. دیپلوی (لینوکس VPS)

1. کد و venv در `/opt/persian-ai-backend`؛ `.env` با `APP_ENV=production`, `REQUIRE_HTTPS=true`, `RELOAD=false`, `HOST=127.0.0.1`.
2. nginx مطابق `deploy/nginx.conf.example` (TLS، `X-Forwarded-Proto`, سقف بدنه).
3. systemd مطابق `deploy/ai-backend.service`؛ برای workerها هم سرویس مشابه بسازید: `python -m app.workers.ingestion`، `python -m app.workers.delivery`، `python -m app.workers.summarizer` (همان `.env`/venv، `Restart=always`). Qdrant سرور (نه `QDRANT_PATH`) تا ingestion پروسهٔ جدا باشد.
4. فایروال: `sudo deploy/firewall-ufw.sh <IP هاست> <IP مدیر>` ← فقط 443 از IP هاست و SSH از IP مدیر؛ Qdrant و پورت 8000 هرگز عمومی نشوند. اگر IP خروجی هاست ثابت نیست/اشتراکی است این قاعده را فعال نکنید و به HMAC تکیه کنید.
5. ساعت سرور با NTP هماهنگ باشد (احراز هویت به زمان وابسته است).

## ۱۱. قراردادهای کدنویسی

- لایه‌ها: `api` فقط HTTP؛ منطق در `services`؛ دسترسی به دیتا در `db`/`vectorstore`. endpoint بدون وابستگی احراز هویت فقط در `health.py`.
- همهٔ I/O به‌صورت async؛ کار CPU-سنگین (استخراج PDF، چانک بزرگ) بیرون از event loop.
- تنظیم جدید = فیلد در `Settings` + مثال در `.env.example` + ردیف در جدول بخش ۵.
- لاگ با `logging.getLogger(__name__)`؛ هرگز راز، امضا یا بدنهٔ درخواست لاگ نشود.
- قبل از تحویل: `ruff check .` و `python -m pytest` (یا `run.bat test`).

## ۱۲. Changelog

### 0.1.0
- ساختار پروژه، `requirements*.txt`، `pyproject.toml`، `.env.example`، `.gitignore`.
- `core`: تنظیمات با اعتبارسنجی (راز ≥ ۳۲ بایت تصادفی، قوانین production)، لاگینگ (کنسول+فایل چرخشی، JSON اختیاری، request-id)، خطاهای یکنواخت، middlewareها (access log، HTTPS-only، سقف بدنه).
- احراز هویت HMAC-SHA256 روی `timestamp\nbody` (هدرهای `X-Timestamp`/`X-Signature`، `compare_digest`، بازهٔ ۵ دقیقه، IP allowlist اختیاری).
- endpointها: `/health`، `/v1/ping` (GET/POST).
- اسکریپت‌ها: `generate_secret.py`، `sign_request.py`. فایل‌های `deploy/`: nginx، ufw، systemd.
- `run.bat`: فعال‌سازی venv (`0.env\venv`)، ساخت خودکار venv/.env/راز، اجرای workerها (فعلاً هیچ) و FastAPI در پنجره‌های جدا.
- تست: ۳۳ تست pytest (امنیت و API) — اجرا شد و سبز بود؛ سرور واقعی هم با `sign_request.py` آزمایش شد. `run.bat` روی ویندوز اجرا/تست نشده است (محیط توسعه لینوکس بود).

### 0.3.0
- **پایگاه دانش (بخش اول):** `POST /v1/knowledge/records` (لیست `{id,data}` ← یکی‌یکی) و `DELETE /v1/knowledge/records` (لیست شناسه‌ها؛ حذف کامل از دیتابیس متنی و Qdrant).
- چانکینگ فارسی: رندر هر نوع رکورد (محصول، وبلاگ، برگه، دسته‌بندی‌ها، سایت) به متن فارسی مورد‌به‌مورد، نرمال‌سازی (حروف، ارقام، نیم‌فاصله)، تاریخ شمسی، پاک‌سازی shortcode صفحه‌ساز، چانک ساختاریافته با آستانه‌های توکنی (`CHUNK_*`)، محصول: متن اصلی یک‌چانک/چندچانک + دیدگاه‌ها جدا.
- دیتابیس متنی (`records` + `chunks`) با SQLAlchemy async/SQLite؛ Qdrant با payload کوچک (`record_id`, `chunk_id`, `chunk_index`, `kind`, `type`, `url`, `title`)؛ امبدینگ OpenAI با batch و لاگ usage؛ ارسال مجدد هوشمند (hash رکورد و چانک).
- تنظیمات جدید `.env` (جدول بخش ۵)، `scripts/preview_chunks.py`، وابستگی‌های جدید در `requirements.txt` (`sqlalchemy`, `aiosqlite`, `qdrant-client`, `openai`, `tiktoken`).
- **`run.bat`:** بررسی نصب بسته‌ها اکنون وابستگی‌های جدید را هم چک می‌کند (در venv موجود خودکار `pip install -r requirements.txt` اجرا می‌شود) و اگر `OPENAI_API_KEY` در `.env` خالی باشد هشدار می‌دهد. (روی ویندوز اجرا نشده است.)
- تست: ۵۹ تست pytest سبز (۲۶ تست جدید: نرمال‌سازی، رندر، چانکینگ، و جریان کامل API با امبدینگ جعلی + Qdrant درون‌حافظه + SQLite موقت). `ruff check .` تمیز.
- اجرا نشده: ارتباط واقعی با OpenAI و سرور Qdrant، و دانلود واژگان tiktoken (محیط توسعه شبکهٔ بیرونی نداشت؛ مسیر fallback تست شد). اولین ارسال واقعی را با `LOG_LEVEL=INFO` ببینید و خط‌های `embedding.request` / `knowledge.token_mismatch` را بررسی کنید.

### 0.3.1
- `QDRANT_PATH`: حالت محلی توکار Qdrant بدون نصب سرور (توسعه روی ویندوز). `.env.example` آن را `data/qdrant` می‌گذارد. `run.bat` تغییری نکرد.

### 0.3.2
- endpointهای دیباگ `GET /v1/knowledge/records` و `GET /v1/knowledge/records/{id}` (رکورد، چانک‌ها، payload نقطه‌های Qdrant، `in_sync`).
- اسکریپت‌ها: `kb_upsert.py`، `kb_delete.py`، `kb_show.py` (با امضا، از طریق API)، `inspect_db.py` (مستقیم SQLite). در حالت محلی Qdrant پوشه توسط سرور قفل است، پس بردارها را فقط با `kb_show.py` ببینید. `run.bat` تغییری نکرد.

### 0.3.3
- `PUT /v1/knowledge/records` (ویرایش): برای هر `{id,data}` ابتدا متد حذف و سپس متد افزودن فراخوانی می‌شود (بدون منطق جدید؛ در پاسخ وضعیت همیشه `created` است چون از نو ساخته می‌شود). `run.bat` تغییری نکرد.

### 0.3.4
- **پیام‌رسانی (بخشی از 0.2.0):** جدول‌های `conversations` و `messages` (`app/db/messaging_models.py`)، سه نقش `user|admin|assistant`، یک گفتگو برای هر کاربر (`owner_key`)، `MessagingRepository` در `app/db/messaging_repository.py`، `MessagingService` در `app/services/messaging/`.
- endpointها (`app/api/v1/messaging.py`، ثبت‌شده در `app/api/router.py`): `POST /v1/chat/messages` (ثبت فوری پیام + دانلود عکس/ویس داخل `UPLOADS_DIR` و ذخیرهٔ مسیر نسبی)، `DELETE /v1/conversations/{id}/messages/{message_id}` و `DELETE /v1/conversations/{id}/messages`.
- `app/db/base.py`: `Database.init()` مدل‌های messaging را هم ثبت می‌کند. `app/main.py`: ساخت/بستن `MessagingService` در `lifespan` و پارامتر `create_app(messaging_service=...)`. نسخهٔ برنامه و `pyproject.toml` ← `0.3.4`.
- تنظیمات جدید `.env`: `PLUGIN_BASE_URL`، `MESSAGE_ATTACHMENT_MAX_MB`، `MESSAGE_DOWNLOAD_TIMEOUT` (در `Settings`، `.env.example` و جدول بخش ۵). `httpx==0.28.1` از `requirements-dev.txt` به `requirements.txt` منتقل شد (دانلود پیوست در runtime).
- **`run.bat` بازنویسی شد:** بررسی نصب بسته‌ها (`:ensure_packages`) اکنون `httpx` را هم import می‌کند، پس در venv موجود `pip install -r requirements.txt` خودکار اجرا می‌شود. worker/دستور جدیدی لازم نبود.
- تست: `tests/test_messaging_api.py` (احراز هویت، یک گفتگو برای هر کاربر، مهمان←کاربر واردشده، اعتبارسنجی، نقش‌ها، دانلود عکس/ویس با میزبان جعلی، شکست دانلود، SSRF، سقف حجم، حذف تکی/کامل، تشخیص نوع فایل) نوشته شد. **اجرا نشده:** محیط توسعه شبکه/پکیج نداشت (pytest/ruff/fastapi نصب نبود). فقط compile و منطق `storage.py` (تشخیص نوع فایل و بررسی URL) بدون وابستگی بیرونی آزموده شد. قبل از استفاده `run.bat test` و `ruff check .` را اجرا کنید.
- محدودیت‌های شناخته‌شده: Alembic هنوز نیست؛ ثبت پیام idempotent نیست (ارسال مجدد = پیام تکراری)؛ دانلود پیوست داخل handler انجام می‌شود (سقف حجم/زمان دارد)؛ لاک همزمانی روی یک گفتگو نیست؛ خلاصه‌سازی و endpoint تاریخچه در مراحل بعد.

### 0.3.5
- **تاریخچه + پاسخ ثابت:** کلید `generate_ai_answer` در بدنهٔ `POST /v1/chat/messages`؛ `true` روی پیام کاربر ← ساخت تاریخچه (بدون خلاصه: کل گفتگو؛ با خلاصه: خلاصه + همهٔ پیام‌های بعد از آن، حداقل ۳ زوج آخر؛ `HistoryBuilder`)، پاسخ ثابت (`FixedAnswerGenerator`، بدون AI و بدون system prompt) ذخیره با نقش `assistant` و برگشت در `ai_answer`. `admin`/`assistant`/بدون کلید/`false` ← فقط ذخیره.
- **خلاصه‌سازی:** شمارش توکن کل تاریخچهٔ خلاصه‌نشده؛ بیش از `SUMMARY_TRIGGER_TOKENS` ← بعد از ارسال پاسخ به کلاینت (BackgroundTasks) یک ردیف در `summary_requests`؛ worker جدید `app/workers/summarizer.py` آن را با پرامپت انگلیسی و `SUMMARY_MODEL` خلاصه و در رکورد گفتگو (`summary`، `summarized_upto_message_id`، `summary_updated_at`) ذخیره می‌کند. تلاش مجدد/شکست، بازیابی درخواست گیرکرده، دورریختن نتیجه اگر گفتگو پاک شده.
- دیتابیس: مدل `SummaryRequest`، ستون‌های جدید `conversations`، و `_add_missing_columns` (افزودن خودکار ستون‌های nullable به جدول‌های موجود تا زمان Alembic). `DELETE …/messages` خلاصه و درخواست‌ها را هم پاک می‌کند.
- تنظیمات جدید `.env`: `HISTORY_RECENT_PAIRS`، `SUMMARY_*` (جدول بخش ۵، `.env.example`). نسخهٔ برنامه و `pyproject.toml` ← `0.3.5`.
- **`run.bat` بازنویسی شد:** worker جدید `worker_summarizer` (پنجرهٔ جدا، `python -m app.workers.summarizer`) هم‌زمان با FastAPI بالا می‌آید. وابستگی جدیدی لازم نیست.
- تست: `tests/test_conversation_summary.py` (کلید generate، نقش‌ها، شکل تاریخچه با admin، آستانه و صف، worker، تلاش مجدد، پاک‌کردن گفتگو، پرامپت، افزودن ستون به دیتابیس قدیمی). `pytest` و `ruff check .` سبز. اجرا نشده: فراخوانی واقعی OpenAI برای خلاصه‌سازی (کلید/شبکه نبود؛ در تست با summarizer جعلی) و `run.bat` روی ویندوز.
- محدودیت‌های شناخته‌شده: تا وقتی worker خلاصه را نساخته، پیام‌های خلاصه‌نشده همه در تاریخچه می‌آیند (اگر worker خاموش باشد تاریخچه رشد می‌کند)؛ حذف یک پیام خلاصهٔ موجود را بازنویسی نمی‌کند؛ دو پیام همزمان از یک گفتگو ممکن است دو پاسخ بسازد (قفل گفتگو نیست).

### 0.3.7
- **مدل/لحن/سایت/صفحه:** `SendMessageRequest` فقط با `generate_ai_answer=true` این‌ها را می‌پذیرد و در `PromptContext` (`app/services/conversation/prompt.py`) می‌گذارد: `model` (با `AI_ALLOWED_MODELS`؛ اولی پیش‌فرض و جایگزین مقدار نامعتبر/خالی؛ مدل نهایی در `ai_answer.model`)، `tone`، `site{title,description}`، `page{url,slug,title,type}`. `PromptContext.system_prompt()` متن system prompt را می‌سازد (LLM واقعی همین را می‌گیرد).
- **پاسخ غیرهمزمان (جایگزین stream):** چون stream هر درخواست را به یک worker پی‌اچ‌پی وصل نگه می‌داشت، حذف شد. `generate_ai_answer=true` + `callback` ← پاسخ فوری `ai_answer.status="queued"`؛ ردیف در `answer_jobs` (`app/db/answer_models.py`)؛ `AnswerQueue` (`app/services/messaging/answers.py`) داخل پردازش API (lifespan) تا `ANSWER_WORKER_CONCURRENCY` پاسخ را هم‌زمان می‌سازد (فقط انتظار شبکه؛ CPU ناچیز)، شکست موقت تا `ANSWER_MAX_ATTEMPTS` با تأخیر `ANSWER_RETRY_DELAY_SECONDS × تلاش` تکرار می‌شود، jobِ گیرکرده بعد از `ANSWER_STALE_SECONDS` دوباره pending می‌شود. نتیجه در **همان تراکنش** به‌صورت ردیف outbox در `callback_deliveries` (`kind="answer"`، `payload` = آیتم JSON) ثبت و با worker تحویل معمول (`python -m app.workers.delivery`) امضا و به `callback.url` فرستاده می‌شود (بدون پنجرهٔ batch). آیتم: `{kind:"chat_answer", id:"answer:<job>", status:"answered"|"failed", job_id, conversation_id, visitor_uid, wp_user_id, question_message_id, model, message:{id,content,suggestions,created_at} | error}`. تلاش مجدد تحویل **محدود** است: `ANSWER_CALLBACK_MAX_ATTEMPTS` (۴) ارسال ناموفق یا `ANSWER_CALLBACK_TTL_SECONDS` (۶۰۰) ثانیه، بعد `dead` (با backoff و breaker معمول سایت). شکست نهایی تولید پاسخ هم با `status:"failed"` به سایت گزارش می‌شود. فرض: یک پردازش API (claim برای چند پردازش هم‌زمان ایمن نیست).
- **`POST /v1/get_log`:** `app/services/logs.py` + `app/api/v1/logs.py`.
- **callback شکست حذف:** ستون nullable `callback_deliveries.action`؛ برای نتیجهٔ `failed` با job از نوع `delete`، هر آیتم callback و `/v1/jobs/status` فیلد `operation:"delete"` دارد تا افزونه رکورد را به «پردازش‌شده» (۲) برگرداند. اگر callback نرسد رکورد در loading می‌ماند (فعلاً).
- `.env.example`: `AI_ALLOWED_MODELS`، `ANSWER_*`. نسخه ← `0.3.8` (stream حذف شد: `AnswerGenerator` فقط `generate`). تست: همهٔ تست‌های pytest سبز (۱۶۹ تست؛ جدید `tests/test_chat_context_stream_logs.py`: لیست مدل، پراپمت، `get_log`، `operation`؛ `tests/test_async_answers.py`: صف ← پاسخ ← وب‌هوک، تلاش مجدد تولید، سقف تلاش تحویل)؛ `ruff check .` تمیز. **اجرا نشده:** اتصال واقعی افزونه (دریافت وب‌هوک پاسخ روی سایت واقعی).

### 0.3.6
- **صف ingestion + worker جدا:** `POST/PUT/DELETE /v1/knowledge/records` دیگر چانک/امبدینگ/Qdrant را داخل درخواست انجام نمی‌دهند؛ فقط اعتبارسنجی + ثبت در `ingest_jobs` (یک تراکنش) و **پاسخ 200 فوری** با `job_id`. ثبت به OpenAI/Qdrant وابسته نیست. پذیرش هر دو شکل بدنه (دسته‌ای و تک‌رکوردی افزونه `{event,id,rev,data,callback}`)؛ `rev` ذخیره و در callback برگردانده می‌شود.
- `app/workers/ingestion.py` + `IngestProcessor`: برداشتن اتمیک job، یک رکورد در هر لحظه، جایگزینی jobهای قدیمیِ شروع‌نشده (`superseded`)، تلاش مجدد برای خطای موقت، بازیابی job گیرکرده، پاکسازی دوره‌ای. `KnowledgeService`: نتیجه‌ها `error_code`/`retryable` دارند.
- **callback تکمیل:** worker جدا `app/workers/delivery.py` (`DeliveryService`) با outbox (`callback_deliveries`، همان تراکنشِ پایان job)، امضای HMAC طبق README افزونه، دسته‌ای، timeout سخت، backoff+jitter+Retry-After، circuit breaker با ping، تطبیق اندازهٔ دسته، اصلاح اختلاف ساعت با `server_time`، SSRF (قفل هاست، بررسی DNS، اتصال به IP بررسی‌شده). جدول‌های `ingest_jobs`، `callback_sites`، `callback_deliveries`.
- `POST /v1/jobs/status` (بازبینی برای callback گم‌شده) و خطای جدید `invalid_callback` (422).
- حالت Qdrant توکار (`QDRANT_PATH`): حلقهٔ ingestion خودکار داخل API اجرا می‌شود (`INGEST_RUN_IN_API`)؛ worker جدا در آن حالت با پیام واضح خارج می‌شود.
- تنظیمات جدید `.env` (جدول بخش ۵)، `.env.example`، `scripts/kb_upsert.py` (صف + انتظار برای نتیجه؛ `--no-wait`). نسخه ← `0.3.6`. مقدار خالی برای `INGEST_RUN_IN_API`/`ENABLE_DOCS` = خودکار.
- **`run.bat` بازنویسی شد:** workerهای جدید `worker_delivery` و `worker_ingestion` (پنجرهٔ جدا)؛ اگر `QDRANT_PATH` در `.env` پر باشد پنجرهٔ ingestion باز نمی‌شود چون API خودش اجرا می‌کند.
- تست: ۱۵۹ تست pytest سبز (جدید: `tests/test_ingest_queue.py` صف/تلاش مجدد/اعتبارسنجی و قفل callback؛ `tests/test_callback_delivery.py` دسته‌بندی، امضا، backoff، breaker، 401/404/413، سایت کند، سقف هم‌زمانی، ترنسپورت واقعی روی سرور محلی)؛ `test_knowledge_api.py` با صف تطبیق داده شد. `ruff check .` تمیز. **اجرا نشده:** ارسال به یک سایت وردپرس واقعی با HTTPS، `run.bat` روی ویندوز، OpenAI و Qdrant واقعی.
- تغییر رفتار (شکستن سازگاری): پاسخ POST/PUT/DELETE دیگر `confirmed_ids/results` ندارد؛ افزونه باید با 200 `ai_status=1` بزند و نتیجه را از callback (یا `/v1/jobs/status`) بگیرد.

### 0.4.0
- **`text:*` از صف:** `ContentService` (`services/ingestion/content.py`) را `IngestProcessor` برای هر job با پیشوند `text` صدا می‌زند: چانک‌های افزونه نگه داشته می‌شوند و فقط چانک بزرگ‌تر از `CHUNK_MAX_TOKENS` دوباره تقسیم می‌شود. `render_record` شاخهٔ `text` گرفت (قبلاً مثل مقاله رندر می‌شد). متن بدون چانک/متن ← 422 هنگام ثبت.
- **`file:*` از صف:** افزونه در `data.download` یک **لینک امضادار کوتاه‌عمر** می‌فرستد (`url`, `expires`)؛ worker آن را با `files/download.py` دانلود می‌کند (SSRF: https، هاست قفل‌شدهٔ سایت، DNS بررسی‌شده و اتصال به IP پین‌شده، بدون redirect؛ سقف `FILES_MAX_MB` و `UPLOADS_MAX_TOTAL_MB` هم از حجم اعلامی و هم هنگام استریم)، `sha256` را با اعلامیِ افزونه تطبیق می‌دهد، با `extractors/` متن می‌گیرد و با `doc_chunker.py` چانک می‌کند. لینک هیچ‌جا ذخیره نمی‌شود. فایل و عنوان یکسان ← `unchanged` بدون دانلود. حذف/جایگزینی رکورد پوشهٔ دانلودشده را هم پاک می‌کند.
- `KnowledgeService`: `store_prebuilt` (مراحل امبدینگ ← Qdrant ← دیتابیس برای چانک‌های آماده؛ `_upsert_one` هم از آن استفاده می‌کند)، `pipeline_for`، `get_state`، `lock`. `FileProcessingError` / `StorageQuotaError` در `core/errors.py` (کدهای خطا در بخش «متن‌ها و فایل‌ها»). `IngestQueueService` بررسی ارزان `text:*`/`file:*` را هنگام ثبت انجام می‌دهد (422 فوری برای `.doc`، لینک نامعتبر/منقضی/هاست غریبه، متن خالی).
- `GET /v1/storage`. **حذف شد:** `api/v1/knowledge_files.py` (`/texts` و `/files` که هرگز ثبت نشده بودند)، `scripts/kb_files.py`، `INSTALL_0.4.0.md`، `FILES_BASE_URL` (دیگر `path` نمی‌آید).
- تنظیمات جدید `.env`: `UPLOADS_MAX_TOTAL_MB`، `FILES_MAX_MB`، `FILES_KEEP_ORIGINAL`، `FILES_CONNECT_TIMEOUT`، `FILES_READ_TIMEOUT`، `FILES_DOWNLOAD_RETRIES`، `FILES_MAX_EXTRACTED_CHARS` (جدول بخش ۵ + `.env.example`). `requirements.txt`: `pdfplumber`، `lxml`. نسخه ← `0.4.0`.
- **`run.bat` تغییر کرد:** worker جدید ندارد، ولی بررسی وابستگی‌ها حالا `pdfplumber` و `lxml` را هم می‌آزماید تا نصب‌های قبلی خودکار `pip install -r requirements.txt` بزنند.
- تست: همهٔ pytest سبز (۱۷۹ تست؛ جدید `tests/test_content_files.py`: متن با چانک‌های افزونه و تقسیم چانک بزرگ، دانلود با لینک امضادار از یک سایت محلی، استخراج، ایندکس، `unchanged` بدون دانلود، حذف فایل، سقف ۱MB، لینک منقضی، 403 سایت، checksum، PDF جعلی، `.doc` و هاست غریبه)؛ `ruff check .` تمیز؛ استخراج واقعی DOCX و PDF دستی امتحان شد. **اجرا نشده:** دانلود از یک سایت وردپرس واقعی روی HTTPS (آزمون با سرور HTTP محلی و حالت توسعه)، OpenAI و Qdrant واقعی، `run.bat` روی ویندوز، PDF اسکن‌شده/فارسیِ پیچیده.

### 0.5.0
- **پاسخ واقعی:** `LLMAnswerGenerator` (RAG top-۳ + پرامپت پایهٔ پویا + تاریخچه/خلاصه + LLM) جای پاسخ ثابت؛ خروجی JSON `{text, suggestions}`؛ `suggestions` روی پیام assistant ذخیره می‌شود. `AnswerGenerator.generate` حالا `GeneratedAnswer` برمی‌گرداند (`str` هم پذیرفته می‌شود).
- فایل‌های جدید: `conversation/prompt.py` (بازنویسی)، `retrieval.py`، `llm_answer.py`، `reply_format.py`؛ `VectorStore.search`؛ فیلد اختیاری `notes` در `POST /v1/chat/messages`؛ اتصال خودکار در `lifespan` (`_attach_llm_generator`).
- تنظیمات جدید `.env` (جدول بخش ۵): `DEFAULT_TONE`، `TONE_FORMAL`، `TONE_FRIENDLY`، `RAG_*`، `ANSWER_MAX_OUTPUT_TOKENS`، `ANSWER_LLM_*`، `ANSWER_RESPONSE_FORMAT`، `ANSWER_REASONING_EFFORT`. نسخه ← `0.5.0`.
- **`run.bat`:** تغییری لازم نبود (حلقهٔ پاسخ داخل API است؛ worker و وابستگی جدیدی نیامد).
- تست: `tests/test_llm_answer.py` (پرامپت و بخش‌های اختیاری، پارسر، پرس‌وجوی کوتاه، retriever، generator با OpenAI/Qdrant جعلی، ذخیرهٔ suggestions). **اجرا نشد:** محیط توسعه شبکه/پکیج نداشت؛ فقط compile، پارسر و سازندهٔ پرامپت (با stub) آزموده شد. قبل از استفاده `run.bat test` و `ruff check .` را اجرا کنید. اجرا نشده: OpenAI/Qdrant واقعی و کیفیت پاسخ فارسی.

### 0.6.0
- **حلقهٔ AI:** `LLMAnswerGenerator.generate` حالا حداکثر `ANSWER_MAX_LLM_ROUNDS` (K، پیش‌فرض ۲) بار AI را صدا می‌زند؛ دورِ اول می‌تواند ابزار بخواهد، دورِ آخر بدون ابزار و با پیام «همین حالا پاسخ بده، از خودت درنیاور» پاسخ نهایی می‌دهد.
- **ابزارها:** پیش‌فرض «محصولات» (`get_product_live_info`؛ قیمت/حراج و موجودی/تعداد/پیش‌سفارش؛ فقط با `live_product_datas`) و ابزارهای داینامیک مدیر سایت (`site_tool_<id>`؛ فهرست `tools` همراه هر پیام). فراخوانی امضادار به سایت با `SiteToolClient`؛ دو API سایت و قرارداد آن‌ها در `docs/LIVE_TOOLS_API.md`.
- **ویس:** STT با `whisper-1` و زبان `fa` (قابل‌تغییر در `.env`)، ذخیرهٔ متن در `messages.transcript` (ستون جدید؛ با `ALTER TABLE` خودکار)، نمایش در `MessageOut.transcript`.
- **تصویر:** عکس آخرین پیام کاربر (کوچک‌شده تا ≤ ۱MB با Pillow) همراه پیام به مدل داده می‌شود.
- **پرامپت انگلیسی:** `RULES_LIVE_DATA` با منطق ابزار هماهنگ شد (`rules_live_data()`)، `RULES_TOOLS` و `FINAL_ROUND_NOTICE` اضافه شد، `RULES_ATTACHMENTS` بازنویسی شد (ویس = متن رونویسی‌شده، عکس = قابل‌دیدن)، attribute `id` به منابع knowledge و `id` صفحه به «Current page» اضافه شد.
- فیلدهای جدید درخواست: `live_product_datas`، `tools`، `page.id`. `PromptContext` فیلدهای `page_id`، `live_product_datas`، `tools` را گرفت (jobهای صفِ قدیمی بدون این فیلدها سالم کار می‌کنند).
- تنظیمات جدید `.env`: `ANSWER_MAX_LLM_ROUNDS`، `TOOLS_*`، `STT_*`، `IMAGE_*` (جدول بخش ۵). وابستگی جدید: `Pillow`. نسخه ← `0.6.0`.
- **`run.bat`:** تغییری لازم نبود (worker/سرویس جدیدی نیامد). فقط `Pillow` باید نصب شود (`requirements.txt` در zip این نوبت نبود؛ `Pillow>=10` را خودتان به آن اضافه کنید).
- تست: طبق دستور، هیچ تستی نوشته/اجرا نشد (فقط compile).

### 0.6.1 (بازبینی ارتباط سرور ↔ افزونه)
- **باگ بحرانی:** `app/__init__.py` علامت‌های تعارض merge گیت (`<<<<<<< HEAD`) داشت ← `SyntaxError` و بالا نیامدن کل سرور. نسخه یکی شد (`0.6.1`). همین علامت‌ها در همین سند هم بود؛ ادغام شد (بخش‌های 0.4.0 و 0.6.0 هر دو نگه داشته شد).
- فایل مرده و ناسازگار `api/v1/knowledge_files.py` (در 0.4.0 حذف‌شده اعلام شده بود ولی در zip مانده بود و به نمادهای ناموجود import می‌کرد) حذف شد.
- افزونه (0.5.24) دو API سمت سایت را طبق `docs/LIVE_TOOLS_API.md` پیاده کرد: `POST /wp-json/paia/v1/live/products` و `/live/tool`؛ همراه هر پیام `live_product_datas`، `tools[]`، `page.id` و `notes` را می‌فرستد.
- گزارش محدودیت‌ها: افزونه هنوز `GET {api_url}/subscription` (Bearer) می‌زند که در سرور وجود ندارد؛ حذف پیام/پاک‌کردن گفتگو در افزونه به سرور نمی‌رسد.
