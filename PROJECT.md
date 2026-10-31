# راهنمای پروژه — بک‌اند هوش مصنوعی (FastAPI) برای افزونهٔ «دستیار هوشمند» وردپرس

> این سند برای «ایجنت/توسعه‌دهندهٔ بعدی» نوشته شده تا بدون خواندن تک‌تک فایل‌ها بتواند ادامه دهد.
> افزونهٔ وردپرس مستندات خودش را در `ARCHITECTURE.md` (ریپوی افزونه) دارد؛ قرارداد بین دو پروژه در بخش ۹ همین سند خلاصه شده است.

| مورد | مقدار |
|---|---|
| نسخهٔ فعلی | `0.4.0` (پایگاه دانش: رکوردهای استخراج‌شده + **متن‌های دستی** + **فایل‌ها** pdf/txt/docx: دانلود ← استخراج ← چانک هم‌اندازه ← امبدینگ ← Qdrant + دیتابیس متنی؛ افزودن/ویرایش/حذف) |
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
   - همهٔ workerها را (فعلاً هیچ) و FastAPI را هرکدام در یک پنجرهٔ cmd جدا (با venv فعال) اجرا می‌کند. پنجرهٔ خودِ دستور هم venv فعال می‌ماند.
3. پیش از اولین ارسال رکورد: Qdrant روشن باشد (`QDRANT_URL`) و `OPENAI_API_KEY` در `.env` پر شده باشد (اگر خالی باشد `run.bat` هشدار می‌دهد).
4. آدرس محلی: `http://127.0.0.1:8000` — `/health` عمومی، `/docs` فقط در حالت development.
5. تست اتصال امضادار: `python scripts\sign_request.py GET http://127.0.0.1:8000/v1/ping`

پیش‌نمایش چانک‌ها بدون هیچ ارتباط شبکه‌ای: `python scripts\preview_chunks.py record.json --id product:13199` (از همان `CHUNK_*` در `.env` استفاده می‌کند).

دستورهای دیگر: `run.bat install` (نصب دوبارهٔ پکیج‌ها)، `run.bat test` (نصب وابستگی‌های dev + اجرای تست‌ها)، `run.bat secret` (چاپ یک راز تازه).

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
├─ requirements-dev.txt    pytest, ruff
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
│  │  └─ v1/               health.py (عمومی)، system.py (/v1/ping)، knowledge.py (رکوردها)،
│  │                       knowledge_files.py (متن‌ها و فایل‌ها، 0.4.0)
│  ├─ schemas/             مدل‌های Pydantic درخواست/پاسخ (common.py، knowledge.py)
│  ├─ services/
│  │  ├─ chunking/         normalize.py (نرمال‌سازی فارسی)، render.py (رکورد ← متن فارسی)،
│  │  │                    splitter.py (چانک بر اساس توکن)، tokens.py (tiktoken)،
│  │  │                    jalali.py (تاریخ شمسی)، pipeline.py (build_plan)،
│  │  │                    doc_chunker.py (چانکر هم‌اندازهٔ DP برای فایل‌ها و متن‌های بزرگ)
│  │  ├─ embedding/        openai_embedder.py (batch + لاگ usage)
│  │  ├─ vectorstore/      qdrant.py (collection، upsert، حذف با فیلتر record_id)
│  │  ├─ ingestion/        service.py (KnowledgeService: upsert_many / delete_many / store_prebuilt)،
│  │  │                    content.py (ContentService: متن‌ها و فایل‌ها، 0.4.0)
│  │  ├─ extractors/       pdf_extractor.py، txt_extractor.py، docs_extractor.py (docx)، common.py + loader.py
│  │  ├─ files/            download.py (DownloadManager)
│  │  └─ llm, tools, speech, vision, conversation   (آیندهٔ نزدیک؛ فعلاً docstring)
│  ├─ db/                  base.py (engine/session)، models.py (records, chunks)،
│  │                       repository.py (تنها جای SQL پایگاه دانش)
│  └─ workers/             (در صورت نیاز)
├─ scripts/
│  ├─ preview_chunks.py    پیش‌نمایش چانک‌ها از یک فایل JSON (بدون شبکه)
│  ├─ kb_upsert.py / kb_delete.py / kb_show.py   تست endpointها با امضا (سرور باید روشن باشد)
│  ├─ kb_files.py          تست endpointهای متن‌ها/فایل‌ها (`files|texts add|update|delete`)
│  ├─ inspect_db.py        خواندن مستقیم SQLite (رکوردها و متن چانک‌ها)؛ `_client.py` کمکی
│  ├─ generate_secret.py   ساخت راز (`--write` داخل .env می‌نویسد)
│  └─ sign_request.py      کلاینت مرجع برای تست دستی با امضا
├─ deploy/                 nginx.conf.example، firewall-ufw.sh، ai-backend.service
├─ tests/                  pytest (امنیت + API + چانکینگ + پایگاه دانش؛ ۶۲ تست)
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

**جدید در 0.4.0** (فایل‌ها):

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `FILES_BASE_URL` | `http://aiwpassistant.local/` | دامنهٔ سایت؛ لینک دانلود = `FILES_BASE_URL + path`. فقط path پذیرفته می‌شود و ریدایرکت فقط روی همین هاست مجاز است. بعداً فقط همین مقدار عوض شود |
| `FILES_MAX_MB` | `100` | سقف حجم هر فایل (هم با `Content-Length` هم هنگام استریم) |
| `FILES_DIR` | خالی ← `<UPLOADS_DIR>/knowledge_files` | پوشهٔ مدیریت‌شدهٔ دانلودها؛ هر رکورد یک پوشه: `file_<id>/source.<ext>` |
| `FILES_KEEP_ORIGINAL` | `true` | `false` ← فایل اصلی بعد از ایندکس موفق پاک می‌شود |
| `FILES_CONNECT_TIMEOUT` `FILES_READ_TIMEOUT` `FILES_DOWNLOAD_RETRIES` | `10` `60` `2` | دانلود (تلاش مجدد فقط برای خطای شبکه/5xx) |
| `FILES_MAX_EXTRACTED_CHARS` | `5000000` | محافظ حجم متن استخراجی |

مرحلهٔ بعد (فعلاً کامنت در `.env.example`): `PLUGIN_BASE_URL`، `PLUGIN_CALLBACK_SECRET`. (سقف کل ۲۰۰MB پوشهٔ آپلود فعلاً اعمال نمی‌شود؛ فقط سقف هر فایل.)

## ۶. فرمت یکنواخت خطا

```json
{"error": {"code": "unauthorized", "message": "Invalid signature", "request_id": "b3f4f577981a441a", "details": null}}
```
کدها: `unauthorized` (401)، `forbidden` (403)، `not_found` (404)، `method_not_allowed` (405)، `payload_too_large` (413)، `validation_error` (422، `details` = فهرست `loc/msg/type` بدون برگرداندن ورودی)، `https_required` (400)، `unprocessable_record` (422؛ رکورد متن قابل‌استفاده ندارد)، `upstream_error` (502؛ API امبدینگ)، `service_unavailable` (503؛ Qdrant/دیتابیس/کلید تنظیم‌نشده)، `internal_error` (500؛ جزئیات فقط در لاگ). در endpointهای دسته‌ای خطای هر رکورد داخل `results[].error` برمی‌گردد و بقیه ادامه می‌دهند. خطای جدید = زیرکلاس `AppError` در `core/errors.py`.

## ۷. Endpointها

| متد و مسیر | دسترسی | وضعیت | توضیح |
|---|---|---|---|
| `GET /health` | عمومی | ✅ | `{"status":"ok"}` |
| `GET\|POST /v1/ping` | امضادار | ✅ | تست اتصال/امضا؛ `server_time`، `received_bytes` |
| `POST /v1/knowledge/records` | امضادار | ✅ 0.3.0 | لیست `{id, data}` ← یکی‌یکی: چانک ← امبدینگ ← Qdrant + دیتابیس متنی (create/update خودکار) |
| `DELETE /v1/knowledge/records` | امضادار | ✅ 0.3.0 | بدنه `{ids:[...]}` ← حذف کامل از دیتابیس متنی و Qdrant |
| `PUT /v1/knowledge/records` | امضادار | ✅ 0.3.3 | همان بدنهٔ POST؛ برای هر رکورد: ابتدا حذف کامل (`delete_many`) و سپس افزودن (`upsert_many`)، یکی‌یکی. اگر حذف شکست بخورد افزودن انجام نمی‌شود |
| `GET /v1/knowledge/records?limit&offset&kind` | امضادار | ✅ 0.3.2 | فهرست رکوردهای ذخیره‌شده (دیباگ) |
| `GET /v1/knowledge/records/{id}` | امضادار | ✅ 0.3.2 | رکورد + داده خام + چانک‌ها + payloadهای Qdrant + `in_sync` (دیباگ) |
| `POST\|PUT\|DELETE /v1/knowledge/texts` | امضادار | ✅ 0.4.0 | متن‌های دستی `text:*`. بدنه: لیست `[{id,data}]` یا `{"records":[...],"force":false}`؛ DELETE: `{"ids":[...]}` (یا لیست خالص). چانک‌های افزونه (`data.chunks`) حفظ می‌شوند و فقط چانک‌های بزرگ‌تر از `CHUNK_MAX_TOKENS` دوباره به چند چانک هم‌اندازه شکسته می‌شوند. PUT = اول حذف، بعد افزودن. پاسخ همان فرمت `/records` |
| `POST\|PUT\|DELETE /v1/knowledge/files` | امضادار | ✅ 0.4.0 | فایل‌های `file:*`. بدنه: لیست `[{id,title,file_orginal_name,path}]` یا `{"files":[...],"force":false}` (حداکثر ۵۰)؛ DELETE: `{"ids":[...]}`. دانلود از `FILES_BASE_URL + path` ← استخراج (pdf/txt/docx) ← چانکر هم‌اندازه ← امبدینگ ← Qdrant + دیتابیس. PUT = اول حذف کامل (دیتابیس + Qdrant + فایل دانلودشده)، بعد افزودن. در `results[]` فیلدهای `error_code`، `url`، `size_bytes` هم هست |
| `GET /v1/storage` | امضادار | ⏳ | مصرف پوشهٔ فایل‌ها (فعلاً فقط سقف هر فایل `FILES_MAX_MB` اعمال می‌شود) |
| `POST /v1/chat/reply` | امضادار | ⏳ 0.5.0 | پیام(ها)+پیوست+tools ← پاسخ/پیشنهادها/ویس/پیوست |
| `POST /v1/chat/messages` | امضادار | ⏳ 0.2.0 | ثبت پیام پشتیبان انسانی در تاریخچه |
| `GET /v1/conversations/{id}/messages` · `DELETE …` | امضادار | ⏳ 0.2.0 | تاریخچه/حذف گفتگو |

اضافه‌کردن endpoint: فایل جدید در `app/api/v1/`، ثبت در `app/api/router.py` زیر `v1_router` (احراز هویت خودکار اعمال می‌شود)، schema در `app/schemas/`، منطق در `app/services/`.

## ۸. نقشهٔ راه

- [x] **0.1.0** — ساختار، تنظیمات، لاگینگ، HMAC، `/health` و `/v1/ping`، تست‌ها، `run.bat`، فایل‌های دیپلوی
- [ ] **0.2.0** — دیتابیس (SQLAlchemy async + Alembic؛ پیش‌فرض SQLite، قابل‌تعویض با PostgreSQL)، کاربر/گفتگو/پیام، سه نقش، خلاصهٔ هر ۱۵ پیام
- [~] **0.3.0** — Qdrant + chunking فارسی + embedding + CRUD با شناسهٔ ثابت: ✅ رکوردهای استخراج‌شده (محصول/وبلاگ/برگه/دسته‌بندی‌ها/سایت)؛ ✅ متن‌های دستی (`text:*`) و فایل‌ها (0.4.0)؛ ⏳ endpoint جست‌وجوی برداری (در 0.5.0 با RAG)
- [x] **0.4.0** — متن‌های دستی + فایل‌ها: دانلود (سقف هر فایل `FILES_MAX_MB`)، استخراج متن (PDF/DOCX/TXT)، چانک هم‌اندازه. (فعلاً هم‌زمان داخل درخواست؛ worker پس‌زمینه و سقف کل پوشه ⏳)
- [ ] **0.5.0** — تولید پاسخ: RAG + تاریخچهٔ خلاصه + پرامپت + LLM
- [ ] **0.6.0** — ابزارها (tools) و callback امضادار به افزونه برای داده‌های زنده
- [ ] **0.7.0** — ویس: STT برای ورودی، TTS برای پاسخ
- [ ] **0.8.0** — تصویر: تحلیل عکس ورودی و ارسال عکس به‌عنوان پیوست پاسخ

## ۹. طراحی مراحل بعد (پیشنهاد معماری — پیش از پیاده‌سازی هر مرحله باید تأیید شود)

### قرارداد با افزونه (از `ARCHITECTURE.md` افزونه)
- شناسهٔ رکوردها همان شناسهٔ افزونه است و **همان شناسه در Qdrant و دیتابیس سرور نگه داشته می‌شود**: `site:{blog_id}`، `product:23`، `blog:45`، `page:7`، `product_taxonomies:all`، `blog_taxonomies:all`، `pages_taxonomies:all`، `text:{ms}`، `file:{ms}`.
- رویدادهای ارسال رکورد (`AiBackend::send($id,$data,$event)`): `create | update | delete`. افزونه فقط وقتی `ai_status=1` می‌کند که بک‌اند **تأیید** کرده باشد (`AiBackend::train($ids)` ← فهرست شناسه‌های تأییدشده) ← پاسخ endpointها باید صریح بگویند کدام شناسه‌ها ذخیره شدند.
- اطلاعات «زنده» (قیمت، موجودی، …) عمداً در داده‌های استخراج‌شده **نیست** و باید با tool گرفته شود (فیلدها/ردیف‌های فعال `paia_live_*`).
- تنظیمات هر درخواست چت از افزونه: `site.description`، `ai.{service,tone,notes}` (سرویس فعلاً `gpt-5-4`؛ لحن `friendly|formal`؛ یادداشت‌های مهم تا ۱۵۰۰ نویسه) ← وارد system prompt می‌شود.
- پاسخ فعلی مورد انتظار افزونه: `{reply, suggestions}` (حداکثر ۴ پیشنهاد)؛ برای ویس/عکس فیلد `attachments` اضافه می‌شود.
- تأخیر/تجمیع پیام‌ها (`ai_delay`) سمت افزونه است؛ بک‌اند یک بستهٔ پیام (`message` + `messages[]`) دریافت می‌کند.
- لاگ‌ها: افزونه جدول `paia_logs` دارد (`LogStore::add(status,message,data)`)؛ مسیر انتقال لاگ بک‌اند ← افزونه هنوز طراحی نشده.

### دیتابیس گفتگو (0.2.0)
- `conversations(id, site_id, visitor_uid, wp_user_id, summary, summarized_upto_message_id, created_at, updated_at)`
- `messages(id, conversation_id, role, content, attachments JSON, support_name, external_id, created_at)` با `role ∈ {user, assistant, support}`.
- برای LLM فقط دو نقش وجود دارد: `support` به‌صورت `assistant` با برچسب «این پیام را پشتیبان انسانی فرستاده» در تاریخچه می‌آید.
- خلاصه‌سازی: با رسیدن هر ۱۵ پیامِ خلاصه‌نشده، پیام‌های قدیمی‌تر با خلاصهٔ قبلی ادغام و `summary` به‌روز می‌شود؛ پرامپت = system + ابزارها + RAG + خلاصه + پیام‌های اخیرِ خلاصه‌نشده.

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

**قرارداد API:**

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
افزونه باید فقط `confirmed_ids` را «آموزش‌دیده» علامت بزند. `status` ∈ `created|updated|unchanged|failed`. اگر کل بدنه نامعتبر باشد 422 و اگر Qdrant/دیتابیس از ابتدا در دسترس نباشد 503 می‌آید. بدنه‌های بزرگ: با سقف ۵MB، دسته‌های ~۲۰ رکوردی بفرستید.

**لاگ‌ها** (`logs/app.log`، بدون متن رکورد/راز؛ فقط شناسه و عدد): `knowledge.upsert_batch` ← `knowledge.upsert_start` ← `knowledge.chunked` (نوع، تعداد چانک، توکن هر چانک، مجموع، طول متن، دقیق‌بودن tokenizer، زمان) ← `embedding.request` (تعداد ورودی، `prompt_tokens`، `total_tokens`، زمان) ← `knowledge.qdrant_upserted` / `knowledge.qdrant_stale_removed` ← `knowledge.db_saved` ← `knowledge.upsert_done` (وضعیت نهایی، توکن‌ها، زمان). حالت‌های ویژه: `knowledge.unchanged`، `knowledge.no_new_chunks`، `knowledge.token_mismatch` (اختلاف >۱۵٪ بین tiktoken و usage API)، `knowledge.upsert_failed`، و برای حذف `knowledge.delete_batch` / `knowledge.delete_done`. اگر tiktoken نتواند واژگانش را دانلود کند، هشدار «APPROXIMATE token counter» در لاگ می‌آید و شمارش تقریبی (نویسه ÷ ۲٫۲) استفاده می‌شود.

**محدودیت‌های شناخته‌شده:** (۱) lock هر رکورد فقط درون یک پردازش است؛ با `WORKERS>1` دو درخواست همزمان روی یک شناسه محافظت نمی‌شوند. (۲) جدول‌ها با `create_all` ساخته می‌شوند؛ Alembic همراه مرحلهٔ 0.2.0 می‌آید. (۳) فیلد `site_id` فعلاً نیست (تک‌سایتی؛ تصمیم باز ۵). (۴) رندر `attributes`/`variations` حدسی/عمومی است چون نمونهٔ واقعی نداشتیم؛ با اولین نمونهٔ واقعی دقیق شود.

### متن‌ها و فایل‌ها (پیاده‌شده در 0.4.0)
- **متن‌ها (`text:{ms}`):** `data = {type:"text", chunks:[...], text, words, chars}`. چانک‌های افزونه همان‌طور ذخیره می‌شوند؛ هر چانکِ بزرگ‌تر از `CHUNK_MAX_TOKENS` با `doc_chunker.rechunk_text_chunks` به چند چانک هم‌اندازه (با overlap) شکسته می‌شود. `records.type=text`، `title` = اولین خط متن. اگر `data.chunks` نبود، `data.text` چانک می‌شود.
- **فایل‌ها (`file:{ms}`):** ورودی `{id, title, file_orginal_name, path}` (کلیدهای `file_original_name` و `if` هم پذیرفته می‌شوند). فقط `.pdf .txt .docx` (پسوند path و نام اصلی + بررسی امضای محتوا؛ صفحهٔ HTML به‌جای فایل رد می‌شود). جریان: `DownloadManager.download` (استریم به `.part` ← atomic، سقف حجم) ← استخراج‌گر مناسب داخل thread (`extractors/loader.py` ← `paragraphs` خروجی `common.build_result`) ← `chunk_document` ← `KnowledgeService.store_prebuilt` (امبدینگ فقط چانک‌های تغییرکرده ← Qdrant ← حذف نقطه‌های اضافه ← دیتابیس). در شکست هر مرحله فایل دانلودشده پاک می‌شود. ارسال دوبارهٔ همان فایل (همان sha256/عنوان/path) = `unchanged` بدون امبدینگ؛ `force=true` اجبار.
- **چانکر هم‌اندازه (`doc_chunker.py`):** خط‌های وسط‌جمله‌شکسته ترمیم، سند به اتم‌ها (جمله/خط/ردیف جدول) شکسته و با برنامه‌ریزی پویا افراز می‌شود تا اندازهٔ چانک‌ها نزدیک `CHUNK_TARGET_TOKENS` باشد (آخرین چانک ریز نمی‌شود)، ترجیحاً در مرز عنوان/پاراگراف، و هیچ چانکی از `CHUNK_MAX_TOKENS` نمی‌گذرد. هر چانک با `فایل: عنوان` و `بخش: الف › ب` (همان قالب splitter) شروع می‌شود؛ overlap فقط وسط یک بخش. لاگ اندازه‌ها: `chunker.document` / `chunker.text`. نسخهٔ الگوریتم `CHUNKER_VERSION="doc-1"` در `pipeline` رکورد می‌آید (تغییرش ← بازچانک).
- **ذخیره:** `records.kind=file|text`؛ `records.data` فایل: `download_url, size_bytes, sha256, stored_path, extraction{extractor,language,stats,encoding}, warnings, chars`. payload کیودرنت مثل بقیه (`kind=main`, `type=file|text`, `url`=لینک دانلود).
- **ویرایش (PUT)** = حذف کامل (دیتابیس + Qdrant + پوشهٔ فایل) و بعد افزودن؛ اگر حذف شکست بخورد افزودن انجام نمی‌شود. حذف داخل PUT از همان primitiveهای `delete_many` استفاده می‌کند (زیر قفل رکورد، بدون قفل دوباره).
- **خطاها در `results[].error_code`:** `unsupported_extension, invalid_path, invalid_file, file_not_found, file_too_large, empty_file, download_failed, no_text_extracted, extracted_text_too_large, extractor_unavailable, delete_failed, unprocessable_record`.
- محدودیت‌ها: پردازش هم‌زمان داخل درخواست (≤۵۰ فایل در هر درخواست؛ فایل‌های بزرگ را در دسته‌های کوچک بفرستید)؛ OCR برای PDF اسکن‌شده نیست (`no_text_extracted`).

### ابزارها (0.6.0)
- لیست ابزارها در هر پیام از کلاینت می‌آید (`name, description, parameters`)؛ در پرامپت/function-calling به مدل داده می‌شود.
- اجرای ابزار = درخواست امضادار از بک‌اند به افزونه (جهت معکوس؛ همان الگوریتم HMAC، ترجیحاً با رازِ جدا `PLUGIN_CALLBACK_SECRET`) روی یک endpoint افزونه؛ نتیجه به مدل برمی‌گردد و تولید پاسخ ادامه می‌یابد. **آدرس callback از درخواست کلاینت خوانده نمی‌شود**؛ فقط از تنظیمات سرور.
- سقف تعداد/زمان فراخوانی ابزار در هر پاسخ.

### ویس و تصویر (0.7.0 / 0.8.0)
- ویس ورودی: دانلود ← STT ← متن وارد همان مسیر پاسخ می‌شود. ویس خروجی: TTS ← فایل موقت + لینک دانلود کوتاه‌عمر امضادار که افزونه برمی‌دارد.
- تصویر ورودی: دانلود ← تحلیل با مدل بینایی ← توضیح به زمینهٔ پاسخ اضافه می‌شود. تصویر خروجی: به‌صورت `attachments[]` در پاسخ.

### تصمیم‌های باز (قبل از مراحل مربوطه روشن شود)
1. مدل/سرویس LLM، STT و TTS (پیشنهاد: ارائه‌دهندهٔ واحد برای سادگی؛ افزونه فعلاً `gpt-5-4` را نشان می‌دهد). *(Embedding روشن شد: OpenAI `text-embedding-3-large`.)*
2. پایگاه داده: SQLite کافی است یا از ابتدا PostgreSQL؟ *(فعلاً SQLite پیش‌فرض؛ با `DATABASE_URL` به PostgreSQL قابل‌تعویض است.)*
3. صف کارها: `BackgroundTasks`/asyncio داخل پروسه یا worker جدا (ARQ/Celery + Redis)؟ (روی `run.bat` اثر دارد.)
4. آیا سقف ۲۰۰MB فقط برای فایل‌های دانش‌پایه است یا ویس/عکس‌های چت هم حساب می‌شوند؟ (پیشنهاد: فقط دانش‌پایه؛ ویس/عکس بعد از پردازش پاک یا با TTL.)
5. یک سایت (فعلی) یا چندسایته با راز جدا برای هر سایت؟ (payload از همین حالا `site_id` دارد.)

## ۱۰. دیپلوی (لینوکس VPS)

1. کد و venv در `/opt/persian-ai-backend`؛ `.env` با `APP_ENV=production`, `REQUIRE_HTTPS=true`, `RELOAD=false`, `HOST=127.0.0.1`.
2. nginx مطابق `deploy/nginx.conf.example` (TLS، `X-Forwarded-Proto`, سقف بدنه).
3. systemd مطابق `deploy/ai-backend.service`.
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

### 0.4.0
- **متن‌های دستی:** `POST|PUT|DELETE /v1/knowledge/texts` (چانک‌های افزونه حفظ، چانک‌های بزرگ دوباره شکسته می‌شوند).
- **فایل‌ها:** `POST|PUT|DELETE /v1/knowledge/files` — دانلود از `FILES_BASE_URL + path` (سقف `FILES_MAX_MB`)، فقط pdf/txt/docx، سه استخراج‌گر، چانکر هم‌اندازهٔ جدید، ذخیره در همان دیتابیس/Qdrant، ویرایش = حذف + افزودن.
- کد: `services/{ingestion/content.py, chunking/doc_chunker.py, files/download.py, extractors/*}`، `api/v1/knowledge_files.py`، `KnowledgeService.store_prebuilt` (مرحله‌های ۲–۵ از `_upsert_one` به `_commit_plan` منتقل شد؛ رفتار قبلی بدون تغییر)، `FileProcessingError` در `errors.py`، تنظیمات `FILES_*`، اسکریپت `scripts/kb_files.py`.
- وابستگی‌های جدید: `httpx` (حالا runtime)، `pdfplumber`، `lxml`، `charset-normalizer`.
- **`run.bat` بازنویسی شد:** بررسی بسته‌ها (`ensure_packages`) حالا `httpx, pdfplumber, lxml` را هم چک می‌کند ← بعد از اعمال این نسخه، `run.bat` خودش وابستگی‌ها را نصب می‌کند.
- ruff: برای `app/services/extractors/*` قوانین سبک‌تر (فایل‌های استخراج‌گر مستقل/CLI هستند).
- تست: ۶۲ تست قبلی سبز؛ جریان کامل متن/فایل (دانلود از وب‌سرور محلی، استخراج واقعی docx/txt/pdf، امبدینگ جعلی، Qdrant درون‌حافظه، SQLite) با یک اسکریپت موقت بررسی شد (تست دائمی طبق درخواست اضافه نشد). اجرا نشده: OpenAI/Qdrant واقعی و tiktoken واقعی.

