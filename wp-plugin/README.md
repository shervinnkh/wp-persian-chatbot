# دستیار هوش مصنوعی وردپرس فارسی

نسخهٔ ۰.۲.۰ — پایهٔ معماری افزونه + صفحهٔ «چت‌بات هوشمند» + استخراج واقعی اطلاعات سایت.

## ساختار

```
persian-ai-assistant/
├─ persian-ai-assistant.php      فایل اصلی (هدر افزونه، ثابت‌ها، راه‌اندازی)
├─ uninstall.php                 پاک‌سازی هنگام حذف
├─ apis/                         API‌های ورودی از سرور هوش مصنوعی (وب‌هوک نتیجه، ping) + قرارداد README/openapi
├─ includes/                     کد PHP با namespace و بارگذاری PSR-4
│  ├─ Core/                      Plugin, Activator, Deactivator, Upgrader, I18n
│  ├─ Contracts/                 Module (هر بخش اصلی یک ماژول است)
│  ├─ Admin/                     AdminModule + Pages + Chatbot/Tabs
│  ├─ Rest/                      RestModule + کنترلرهای paia/v1
│  ├─ Extraction/                Sources (منابع قابل استخراج)
│  └─ Support/                   View, Icons, Settings, Capability
├─ views/                        قالب‌های HTML (جدا از منطق)
├─ assets/                       css, js, fonts (وزیرمتن، لایسنس OFL)
└─ languages/                    فایل‌های ترجمه (.pot / .po / .mo)
```

## افزودن‌ها

- **ماژول جدید:** کلاسی که `Contracts\Module` را پیاده می‌کند و در `Core\Plugin::module_classes()` (یا فیلتر `paia_modules`) ثبت می‌شود.
- **تب جدید در چت‌بات:** کلاسی که از `AbstractTab` ارث می‌برد + فایل `views/admin/chatbot/tabs/{id}.php` و ثبت در `ChatbotPage::tabs()` (یا فیلتر `paia_chatbot_tabs`).
- **منبع جدید برای استخراج:** یک ردیف به `Extraction\Sources::all()` (یا فیلتر `paia_extraction_sources`).
- **مسیر REST جدید:** کنترلر از `AbstractController` + ثبت در `RestModule` (یا فیلتر `paia_rest_controllers`).

## چندزبانه‌سازی

زبان مبدأ **فارسی** است و همهٔ متن‌ها با `__()` / `esc_html_e()` و text domain برابر `persian-ai-assistant` نوشته شده‌اند. برای افزودن زبان:

1. فایل `languages/persian-ai-assistant.pot` را در Poedit یا Loco Translate باز کنید.
2. ترجمه را ذخیره کنید؛ مثلاً `persian-ai-assistant-en_US.po` و `.mo` در همان پوشه.

بازتولید `.pot` پس از تغییر متن‌ها: `wp i18n make-pot . languages/persian-ai-assistant.pot`

## هوک‌های مهم

| هوک | کاربرد |
|---|---|
| `paia_loaded` | پس از بارگذاری کامل افزونه |
| `paia_modules` | افزودن/حذف ماژول |
| `paia_chatbot_tabs` | افزودن/تغییر تب‌ها |
| `paia_extraction_sources` | افزودن/تغییر منابع استخراج |
| `paia_rest_controllers` | افزودن کنترلر REST |
| `paia_required_capability` | تغییر سطح دسترسی (پیش‌فرض `manage_options`) |
| `paia_default_settings` | مقادیر پیش‌فرض تنظیمات |
| `paia_upgrade` | اجرای مهاجرت‌ها هنگام ارتقای نسخه |

## وضعیت فعلی

- دکمهٔ «استخراج اطلاعات» منبع‌های انتخاب‌شده (محصولات، وبلاگ، برگه‌ها، سوالات متداول، تماس) را دسته‌دسته از طریق `POST /wp-json/paia/v1/extraction` می‌خواند و خلاصه، دکمهٔ غیرفعال «آموزش هوش مصنوعی» و بخش موقت DEV-ONLY (نمایش کامل داده‌ها) را نشان می‌دهد. جزئیات: `ARCHITECTURE.md` بخش ۴-ب.
- اتصال به سرویس پایتون و آموزش واقعی هوش مصنوعی هنوز پیاده نشده (دکمه‌های آموزش فقط ذخیره می‌کنند)؛ «صفحه اصلی» عمداً خالی است. تب «اسناد اضافه» فعلاً فقط افزودن متن را دارد (آپلود فایل هنوز نیست).

## امنیت (۰.۵.۲۹)

**کلیدها در wp-config.php (روش اصلی):**
```php
define( 'PAIA_API_URL',          'https://ai.example.com' );
define( 'PAIA_API_SECRET',       '...' );  // = API_SHARED_SECRET سرور؛ با تعریفش کلید در دیتابیس/HTML نمی‌آید
define( 'PAIA_CALLBACK_SECRET',  '...' );  // اختیاری؛ = PLUGIN_CALLBACK_SECRET سرور (امضای ورودی از سرور)
define( 'PAIA_FILE_LINK_SECRET', '...' );  // اختیاری؛ امضای لینک دانلود فایل‌ها
define( 'PAIA_GITHUB_REPO',      'owner/repo' ); // اختیاری؛ جای ثابت Updater::GITHUB_REPO
```
توصیه می‌شود سه کلید PAIA_API_SECRET / PAIA_CALLBACK_SECRET / PAIA_FILE_LINK_SECRET مقدارهای متفاوت داشته باشند. اگر دوتای آخر تعریف نشوند به کلید مشترک برمی‌گردند.

**nginx** (Apache با `.htaccess` خودکار محافظت می‌شود):
```nginx
location ~* /uploads/paia-chat/.*\.php$ { deny all; }
```
فایل‌های چت عمداً عمومی‌اند (نام تصادفی ۱۶ نویسه‌ای). پیام‌ها و پیوست‌های قدیمی‌تر از ۹۰ روز با cron روزانهٔ `paia_daily_cleanup` پاک می‌شوند (فیلتر `paia_retention_days`).

## شناسهٔ بازدیدکننده (UID) — SEC-02

- هدر `X-PAIA-Visitor` برای دسترسی به تاریخچهٔ گفتگوی همان بازدیدکننده استفاده می‌شود؛ پس **عملاً یک credential است** (نباید در لاگ، URL یا اشتراک‌گذاری ظاهر شود).
- UID فقط در سرور (وردپرس) ساخته می‌شود: UUID نسخهٔ ۴ از `random_bytes(16)` (کلاس `Support\Uuid`)، با بیت‌های version/variant استاندارد، به‌صورت ۳۶ نویسه با حروف کوچک (`xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx`).
- اگر منبع تصادفی رمزنگاری‌شده در دسترس نباشد، هیچ روش ضعیفی (`mt_rand` و مشابه) جایگزین نمی‌شود: ساخت بازدیدکنندهٔ جدید با خطای `500 paia_db` رد می‌شود و در لاگ خطای PHP یک پیام ثبت می‌شود.
- قالب خروجی تغییر نکرده است؛ UIDهای قبلی (که با `wp_generate_uuid4()` ساخته شده بودند) با همان regex پذیرفته می‌شوند و نیازی به مهاجرت نیست.
- توکن WebSocket جدا تولید می‌شود (از قبل با `random_bytes`) و تغییری نکرده است.

## IP بازدیدکننده، Proxy و Cloudflare — SEC-04

سقف نرخ مبتنی بر IP (`Chat\RateLimit`) از `Support\ClientIp` استفاده می‌کند.

- **سایت معمولی** (بدون Proxy): هیچ ثابتی تعریف نکنید. فقط `REMOTE_ADDR` خوانده می‌شود و هدرهای IP که کلاینت می‌فرستد (از جمله `X-Forwarded-For`، `CF-Connecting-IP`) نادیده گرفته می‌شوند.
- **سایت پشت Cloudflare/Proxy:** `PAIA_IP_HEADER` به‌تنهایی کافی نیست. هدر فقط وقتی خوانده می‌شود که IP اتصال‌دهنده (`REMOTE_ADDR`) داخل محدودهٔ Proxy مورد اعتماد باشد؛ بدون فهرست مورد اعتماد، هدر نادیده گرفته می‌شود.

```php
// wp-config.php — پشت Cloudflare
define( 'PAIA_IP_HEADER',       'HTTP_CF_CONNECTING_IP' );
define( 'PAIA_TRUSTED_PROXIES', 'cloudflare' );   // فهرست پیوست‌شده: includes/Support/trusted-proxies/cloudflare.txt

// یا فهرست دلخواه (آرایه یا رشتهٔ جداشده با کاما؛ می‌توان با 'cloudflare' ترکیب کرد)
define( 'PAIA_TRUSTED_PROXIES', array( 'cloudflare', '203.0.113.0/24' ) );
```

- رفتار: اگر Proxy معتبر نباشد ← هدر کاملاً نادیده گرفته و `REMOTE_ADDR` برگردانده می‌شود. اگر Proxy معتبر ولی هدر خالی/نامعتبر باشد (با `FILTER_VALIDATE_IP`) ← `REMOTE_ADDR`. `X-Forwarded-For` هرگز خوانده نمی‌شود.
- **محدوده‌های IP باید به‌روز بمانند.** فایل `includes/Support/trusted-proxies/cloudflare.txt` یک snapshot است؛ منبع رسمی `https://www.cloudflare.com/ips-v4` و `https://www.cloudflare.com/ips-v6` است. پیش از استقرار آن را با منبع رسمی مطابقت دهید و دوره‌ای به‌روز کنید. جایگزین‌ها: فهرست مستقیم در `PAIA_TRUSTED_PROXIES` یا فیلتر `paia_trusted_proxies` (برای دریافت از منبع به‌روز).
- اگر Cloudflare را برای سایت فعال کرده‌اید ولی این تنظیم را انجام نداده‌اید، همهٔ کاربران یک IP (IP پروکسی) دیده می‌شوند و سقف نرخ بین آن‌ها مشترک می‌شود؛ پس پیکربندی بالا را کامل کنید.
- سرور `client_ip` را در بدنهٔ امضاشده دریافت می‌کند؛ هر IP معتبر همان مقدار اعتبارسنجی‌شده است.

## سرور هوش مصنوعی

محدودیت تک‌پروسه‌ای، حالت‌های Qdrant و مهاجرت داده در `ai-server/README.md` توضیح داده شده‌اند. افزونه فرض می‌کند سرور با `WORKERS=1` اجرا می‌شود.

## تست

بدون وابستگی (فقط PHP CLI): `php tests/run.php` (کد خروج غیرصفر = شکست).
