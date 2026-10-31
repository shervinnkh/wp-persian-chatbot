# راهنمای پروژه — بک‌اند هوش مصنوعی (FastAPI) برای افزونهٔ «دستیار هوشمند» وردپرس

> این سند برای «ایجنت/توسعه‌دهندهٔ بعدی» نوشته شده تا بدون خواندن تک‌تک فایل‌ها بتواند ادامه دهد.
> افزونهٔ وردپرس مستندات خودش را در `ARCHITECTURE.md` (ریپوی افزونه) دارد؛ قرارداد بین دو پروژه در بخش ۹ همین سند خلاصه شده است.

| مورد | مقدار |
|---|---|
| نسخهٔ فعلی | `0.3.5` (پیام‌رسانی + آماده‌سازی تاریخچه، پاسخ ثابت بدون AI با کلید `generate_ai_answer`، سیستم خلاصه‌سازی با worker؛ بالای بخش اول پایگاه دانش 0.3.x) |
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

**workerها:** `worker_summarizer` (خلاصه‌سازی گفتگوها، `python -m app.workers.summarizer`) همراه `run.bat` بالا می‌آید؛ بدون آن، خلاصه‌ای ساخته نمی‌شود (درخواست‌ها در جدول `summary_requests` منتظر می‌مانند).

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
│  │  └─ v1/               health.py (عمومی)، system.py (/v1/ping)، knowledge.py (رکوردها)، messaging.py (پیام‌ها)
│  ├─ schemas/             مدل‌های Pydantic درخواست/پاسخ (common.py، knowledge.py، messaging.py)
│  ├─ services/
│  │  ├─ chunking/         normalize.py (نرمال‌سازی فارسی)، render.py (رکورد ← متن فارسی)،
│  │  │                    splitter.py (چانک بر اساس توکن)، tokens.py (tiktoken)،
│  │  │                    jalali.py (تاریخ شمسی)، pipeline.py (build_plan)
│  │  ├─ embedding/        openai_embedder.py (batch + لاگ usage)
│  │  ├─ vectorstore/      qdrant.py (collection، upsert، حذف با فیلتر record_id)
│  │  ├─ ingestion/        service.py (KnowledgeService: upsert_many / delete_many)
│  │  ├─ messaging/        service.py (MessagingService: ثبت/حذف پیام + تولید پاسخ)، storage.py (دانلود امن پیوست)، errors.py
│  │  ├─ conversation/     history.py (HistoryBuilder: خلاصه + ۳ زوج آخر + پیام آخر، شمارش توکن)، answer.py (پاسخ ثابت؛ جای LLM)،
│  │  │                    summarizer.py (پرامپت انگلیسی + کلاینت OpenAI)، summary_service.py (صف: enqueue / process_next)
│  │  └─ llm, tools, speech, vision   (آیندهٔ نزدیک؛ فعلاً docstring)
│  ├─ db/                  base.py (engine/session)، models.py (records, chunks)،
│  │                       messaging_models.py (conversations, messages, summary_requests)، messaging_repository.py (MessagingRepository)،
│  │                       repository.py (تنها جای SQL پایگاه دانش)
│  └─ workers/             summarizer.py (`python -m app.workers.summarizer`)
├─ scripts/
│  ├─ preview_chunks.py    پیش‌نمایش چانک‌ها از یک فایل JSON (بدون شبکه)
│  ├─ kb_upsert.py / kb_delete.py / kb_show.py   تست endpointها با امضا (سرور باید روشن باشد)
│  ├─ inspect_db.py        خواندن مستقیم SQLite (رکوردها و متن چانک‌ها)؛ `_client.py` کمکی
│  ├─ generate_secret.py   ساخت راز (`--write` داخل .env می‌نویسد)
│  └─ sign_request.py      کلاینت مرجع برای تست دستی با امضا
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

مرحلهٔ بعد (فعلاً کامنت در `.env.example`): `UPLOADS_MAX_TOTAL_MB=200`، `PLUGIN_CALLBACK_SECRET`.

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
| `PUT /v1/knowledge/texts/{id}` · `DELETE …` | امضادار | ⏳ 0.3.0 | متن‌های دستی (چانک‌ها از افزونه می‌آیند) |
| `PUT /v1/knowledge/files/{id}` · `DELETE …` | امضادار | ⏳ 0.4.0 | `{url,title}` ← دانلود ← متن ← چانک؛ سقف ۲۰۰MB |
| `GET /v1/storage` | امضادار | ⏳ 0.4.0 | مصرف uploads نسبت به سقف |
| `POST /v1/chat/reply` | امضادار | ⏳ 0.5.0 | پیام(ها)+پیوست+tools ← پاسخ/پیشنهادها/ویس/پیوست |
| `POST /v1/chat/messages` | امضادار | ✅ 0.3.4 | ثبت پیام (`user\|admin\|assistant`)؛ ساخت/یافتن گفتگوی یکتای کاربر؛ ذخیرهٔ فوری؛ دانلود عکس/ویس. با `generate_ai_answer=true` روی پیام کاربر، پاسخ (فعلاً ثابت) هم تولید و ذخیره و برگردانده می‌شود؛ بخش «پیام‌رسانی» و «تاریخچه، تولید پاسخ و خلاصه‌سازی» |
| `DELETE /v1/conversations/{conversation_id}/messages/{message_id}` | امضادار | ✅ 0.3.4 | حذف یک پیام (+ فایل پیوست) |
| `DELETE /v1/conversations/{conversation_id}/messages` | امضادار | ✅ 0.3.4 | حذف همهٔ پیام‌های گفتگو (+ فایل‌ها)؛ خود گفتگو می‌ماند |
| `GET /v1/conversations/{id}/messages` | امضادار | ⏳ | تاریخچه (تابع `list_messages` در repository آماده است) |

اضافه‌کردن endpoint: فایل جدید در `app/api/v1/`، ثبت در `app/api/router.py` زیر `v1_router` (احراز هویت خودکار اعمال می‌شود)، schema در `app/schemas/`، منطق در `app/services/`.

## ۸. نقشهٔ راه

- [x] **0.1.0** — ساختار، تنظیمات، لاگینگ، HMAC، `/health` و `/v1/ping`، تست‌ها، `run.bat`، فایل‌های دیپلوی
- [~] **0.2.0** — دیتابیس (SQLAlchemy async + Alembic؛ پیش‌فرض SQLite، قابل‌تعویض با PostgreSQL)، کاربر/گفتگو/پیام، سه نقش، خلاصه‌سازی: ✅ گفتگو/پیام/سه نقش/ثبت و حذف/دانلود پیوست (0.3.4)؛ ✅ آماده‌سازی تاریخچه، کلید `generate_ai_answer`، پاسخ ثابت، خلاصه‌سازی بر پایهٔ توکن با worker (0.3.5)؛ ⏳ Alembic، endpoint تاریخچه
- [~] **0.3.0** — Qdrant + chunking فارسی + embedding + CRUD با شناسهٔ ثابت: ✅ رکوردهای استخراج‌شده (محصول/وبلاگ/برگه/دسته‌بندی‌ها/سایت)؛ ⏳ متن‌های دستی (`text:*`) و فایل‌ها (0.4.0)؛ ⏳ endpoint جست‌وجوی برداری (در 0.5.0 با RAG)
- [ ] **0.4.0** — دانلود فایل، سقف ۲۰۰MB، استخراج متن (PDF/DOCX/TXT)، worker پس‌زمینه
- [ ] **0.5.0** — تولید پاسخ: RAG + تاریخچهٔ خلاصه + پرامپت + LLM (جای `FixedAnswerGenerator` در `answer.py`؛ ورودی آماده است: `PreparedHistory`)
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

### پیام‌رسانی (پیاده‌شده در 0.3.4)

**نقش‌ها:** `user` (بازدیدکننده)، `admin` (پشتیبان انسانی)، `assistant` (هوش مصنوعی). برای LLM فقط دو نقش وجود دارد: `admin` به‌صورت `assistant` با برچسب «این پیام را پشتیبان انسانی فرستاده» در تاریخچه می‌آید (مرحلهٔ 0.5.0).

**هر کاربر فقط یک گفتگو دارد.** کلید یکتا `conversations.owner_key`: `u:{wp_user_id}` برای کاربر واردشده، `v:{visitor_uid}` برای مهمان. اگر مهمانی که قبلاً چت کرده وارد شود (و گفتگوی `u:` نداشته باشد) همان گفتگو به حساب او منتقل می‌شود؛ `wp_user_id=0` (مهمان وردپرس) معادل `null` است. ساخت همزمان با unique + savepoint امن است.

| جدول | ستون‌ها |
|---|---|
| `conversations` | `id`، `owner_key` (unique)، `visitor_uid`، `wp_user_id`، `created_at`، `updated_at`، `summary`، `summarized_upto_message_id`، `summary_updated_at` |
| `messages` | `id`، `conversation_id` (FK، cascade)، `visitor_uid`، `wp_user_id`، `role`، `content`، `attachment_type` (`image\|voice`)، `attachment_url`، `attachment_path`، `suggestions` (JSON، حداکثر ۴)، `admin_user_id`، `admin_name`، `created_at` |

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

**پاسخ ثابت:** `FixedAnswerGenerator` (`answer.py`) همیشه `FIXED_ANSWER` را برمی‌گرداند؛ رابط `AnswerGenerator.generate(history)` همان است که LLM واقعی جایش می‌آید (تزریق از `MessagingService(answer_generator=...)`).

**شمارش توکن و آستانه:** `full_history_tokens` = توکن (tiktoken) «خلاصهٔ ذخیره‌شده + همهٔ پیام‌هایی که هنوز در خلاصه نیامده‌اند (شامل پیام آخر)». اگر از `SUMMARY_TRIGGER_TOKENS` بیشتر باشد، `needs_summary=true` و بعد از ارسال پاسخ درخواست ثبت می‌شود (`upto_message_id` = شناسهٔ همان پاسخ). اگر برای آن گفتگو درخواست pending/processing باشد، درخواست تازه ثبت نمی‌شود.

**جدول `summary_requests`:** `id`، `conversation_id` (FK، cascade)، `upto_message_id`، `status` (`pending|processing|done|failed`)، `attempts`، `error`، `available_at`، `started_at`، `finished_at`، `created_at`، `updated_at`.

**worker (`python -m app.workers.summarizer`):** صف را poll می‌کند؛ هر درخواست را اتمیک برمی‌دارد (`pending→processing`)، «خلاصهٔ قبلی + پیام‌های بعد از آن تا `upto_message_id`» را به AI می‌دهد (پرامپت انگلیسی `SUMMARIZATION_SYSTEM_PROMPT` در `summarizer.py`؛ خلاصه به زبان کاربر یعنی فارسی نوشته می‌شود، شناسه‌ها/عددها دقیق نگه داشته می‌شوند، متن گفتگو «داده» است نه دستور)، و نتیجه را در `conversations.summary` + `summarized_upto_message_id` ذخیره می‌کند. خطا ← تلاش مجدد با تأخیر (`SUMMARY_MAX_ATTEMPTS`)، سپس `failed`. اگر وسط کار گفتگو پاک شده باشد نتیجه دور ریخته می‌شود؛ خلاصهٔ جدیدتر هرگز با قدیمی‌تر جایگزین نمی‌شود.

**پاک‌کردن:** `DELETE …/messages` (پاک‌کردن کل گفتگو) خلاصه، مرز آن و همهٔ `summary_requests` را هم پاک می‌کند. حذف *یک* پیام خلاصهٔ موجود را تغییر نمی‌دهد.

**لاگ‌ها** (بدون متن): `messaging.answer_generated` (توکن‌ها و `needs_summary`)، `messaging.answer_failed`، `summary.enqueued` / `enqueue_skipped` / `enqueue_failed`، `summary.done` (نتیجه: `saved|already_covered|nothing_to_summarize|conversation_deleted|discarded_*`)، `summary.retry`، `summary.failed`، `summarizer.request` (توکن‌های مصرفی).

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

### فایل‌ها و سقف ۲۰۰MB (0.4.0)
- ورودی فقط URL است (بدنهٔ JSON کوچک)؛ سرور با httpx استریم‌دانلود می‌کند در `uploads/{نوع}/{سال}/{ماه}/{uuid}.{پسوند}`، با بررسی نوع/امضای فایل و سقف حجم هر فایل.
- سقف کل ۲۰۰MB: مجموع حجم ثبت‌شده + حجم فایل جدید (و هنگام استریم) از سقف بیشتر شود ← خطای `storage_quota_exceeded` (HTTP 507 یا 413) و هیچ دانلود/چانکینگی انجام نمی‌شود.
- **SSRF:** دانلود فقط از دامنهٔ سایت پذیرنده (`PLUGIN_BASE_URL`) و https؛ آدرس‌های داخلی/لوپ‌بک رد می‌شوند.

### ابزارها (0.6.0)
- لیست ابزارها در هر پیام از کلاینت می‌آید (`name, description, parameters`)؛ در پرامپت/function-calling به مدل داده می‌شود.
- اجرای ابزار = درخواست امضادار از بک‌اند به افزونه (جهت معکوس؛ همان الگوریتم HMAC، ترجیحاً با رازِ جدا `PLUGIN_CALLBACK_SECRET`) روی یک endpoint افزونه؛ نتیجه به مدل برمی‌گردد و تولید پاسخ ادامه می‌یابد. **آدرس callback از درخواست کلاینت خوانده نمی‌شود**؛ فقط از تنظیمات سرور.
- سقف تعداد/زمان فراخوانی ابزار در هر پاسخ.

### ویس و تصویر (0.7.0 / 0.8.0)
- ویس ورودی: دانلود ← STT ← متن وارد همان مسیر پاسخ می‌شود. ویس خروجی: TTS ← فایل موقت + لینک دانلود کوتاه‌عمر امضادار که افزونه برمی‌دارد.
- تصویر ورودی: دانلود ← تحلیل با مدل بینایی ← توضیح به زمینهٔ پاسخ اضافه می‌شود. تصویر خروجی: به‌صورت `attachments[]` در پاسخ.

### تصمیم‌های باز (قبل از مراحل مربوطه روشن شود)
1. مدل/سرویس LLM، STT و TTS (پیشنهاد: ارائه‌دهندهٔ واحد برای سادگی؛ افزونه فعلاً `gpt-5-4` را نشان می‌دهد). *(Embedding روشن شد: OpenAI `text-embedding-3-large`. مدل خلاصه‌سازی فعلاً `SUMMARY_MODEL=gpt-4.1-mini` از همان کلید OpenAI؛ قابل‌تغییر از `.env`.)*
2. پایگاه داده: SQLite کافی است یا از ابتدا PostgreSQL؟ *(فعلاً SQLite پیش‌فرض؛ با `DATABASE_URL` به PostgreSQL قابل‌تعویض است.)*
3. صف کارها: `BackgroundTasks`/asyncio داخل پروسه یا worker جدا (ARQ/Celery + Redis)؟ (روی `run.bat` اثر دارد.)
4. آیا سقف ۲۰۰MB فقط برای فایل‌های دانش‌پایه است یا ویس/عکس‌های چت هم حساب می‌شوند؟ (پیشنهاد: فقط دانش‌پایه؛ ویس/عکس بعد از پردازش پاک یا با TTL.) *(فعلاً در 0.3.4 فایل‌های چت حساب نمی‌شوند و تا حذف پیام می‌مانند.)*
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
