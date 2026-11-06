# یادداشت‌های production سرور

## دیتابیس (SQLite یا PostgreSQL)

تا چند ده کاربر هم‌زمان SQLite کافی است؛ برای production جدی `DATABASE_URL` پستگرس بگذار
(مثلاً `postgresql+asyncpg://user:pass@host:5432/db` با نصب `asyncpg`).

- **SQLite** (پیش‌فرض، وقتی `DATABASE_URL` خالی است): `PRAGMA journal_mode=WAL` و `busy_timeout=5000`
  فعال است (بدون تغییر نسبت به قبل).
- **PostgreSQL:** اتصال‌ها با `pool_pre_ping` و `pool_recycle` مدیریت می‌شوند؛ اتصالی که در بیکاری
  قطع شده باشد (فایروال، PgBouncer، ری‌استارت سرور) خودکار عوض می‌شود و خطا نمی‌دهد. قابل‌تنظیم با env:

  | کلید | پیش‌فرض | توضیح |
  |---|---|---|
  | `DB_POOL_SIZE` | `10` | اتصال‌های دائمی هر پروسه |
  | `DB_MAX_OVERFLOW` | `10` | اتصال اضافهٔ موقت در اوج بار |
  | `DB_POOL_RECYCLE_SECONDS` | `1800` | اتصال قدیمی‌تر از این دیگر استفاده نمی‌شود |
  | `DB_POOL_PRE_PING` | `true` | قبل از هر استفاده، سلامت اتصال تست می‌شود |

  هر پروسه (API و هر worker) pool خودش را دارد؛ مجموع `(DB_POOL_SIZE + DB_MAX_OVERFLOW) × تعداد پروسه‌ها`
  باید زیر `max_connections` پستگرس بماند. این کلیدها برای SQLite بی‌اثرند.

## Production checklist

- [ ] **Qdrant جدا:** `QDRANT_MODE=server`، `QDRANT_PATH` خالی، `QDRANT_URL` و `QDRANT_API_KEY` (در production اجباری).
      Embedded (`QDRANT_MODE=embedded`) فقط برای توسعه است و با `APP_ENV=production` استارت نمی‌شود.
- [ ] **`WORKERS=1`:** چند Worker/نمونهٔ API پشتیبانی نمی‌شود (صف، WebSocket و JtiCache در حافظهٔ یک پروسه‌اند).
- [ ] **`worker_ingestion` جدا:** `python -m app.workers.ingestion` را به‌عنوان سرویس جدا اجرا کن
      (با Qdrant سرور، `INGEST_RUN_IN_API` خودکار خاموش است و حلقهٔ ingestion داخل API اجرا نمی‌شود).
- [ ] **Postgres:** `DATABASE_URL` پستگرس (بخش بالا).

## هزینه و حجم بردارها

هر چانک یک بردار float32 در Qdrant است: حجم خام ≈ `EMBEDDING_DIMENSIONS × 4` بایت
(بدون سربار ایندکس/payload).

| مدل / بُعد | هر بردار | ۱۰۰٬۰۰۰ چانک |
|---|---|---|
| `text-embedding-3-large` / `3072` (پیش‌فرض) | ≈ 12 KB | ≈ 1.2 GB |
| `text-embedding-3-large` / `1024` | ≈ 4 KB | ≈ 0.4 GB |
| `text-embedding-3-small` / `1536` (بُعد پیش‌فرض مدل) | ≈ 6 KB | ≈ 0.6 GB |

برای نصب‌های **جدید** می‌توان `EMBEDDING_MODEL=text-embedding-3-small` گذاشت یا بُعد را کم کرد
(`EMBEDDING_DIMENSIONS=1024`)؛ حجم و هزینهٔ امبدینگ کمتر می‌شود، با کمی افت کیفیت بازیابی.
بُعد نباید از حداکثر بُعد خود مدل بیشتر باشد (برای `3-small` حداکثر `1536`).

> ⚠️ **هشدار مهم:** تغییر مدل یا بُعد روی کالکشن موجود نیازمند **ingestion مجدد کل دانش** است.
> اگر بُعد عوض شود، سرور با خطای ناهماهنگی بُعد بالا نمی‌آید؛ اما اگر فقط **مدل** عوض شود و بُعد
> یکی بماند، هیچ خطایی نمی‌دهد و بردارهای قدیمی و جدید با هم سازگار نیستند (جستجو بی‌کیفیت می‌شود).
> روش درست: `QDRANT_COLLECTION` جدید بگذار و همهٔ رکوردها را با `force=true` دوباره بفرست.
> پیش‌فرض‌ها برای نصب‌های موجود عوض نشده‌اند؛ تغییر فقط با env و آگاهانه است.

**Scalar quantization (اختیاری):** با `QDRANT_SCALAR_QUANTIZATION=true` بردارها در RAM به‌صورت int8
نگه داشته می‌شوند (حدود ۴ برابر RAM کمتر، افت کیفیت ناچیز). پیش‌فرض خاموش است و فقط هنگام
**ساخت** کالکشن جدید اعمال می‌شود؛ کالکشن موجود دست‌نخورده می‌ماند.
