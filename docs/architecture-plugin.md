# معماری افزونه (wp-plugin)

افزونهٔ وردپرس «چت بات هوشمند» — نسخه `1.0.0`، slug: `persian-wp-chatbot`، PHP ‏7.4+، وردپرس ‏6.0+. ویجت چت را روی سایت نشان می‌دهد، اطلاعات سایت را به سرور هوش مصنوعی می‌فرستد و پاسخ‌ها را دریافت می‌کند.

## قراردادها

- namespace: `PersianWPChatbot` (autoloader ساده، `includes/Autoloader.php`)
- پیشوند همه‌چیز: `pwc` (گزینه‌ها، جدول‌ها، هوک‌ها، CSS، REST `pwc/v1`)
- متن‌ها فارسی و i18n-ready با text domain برابر `persian-wp-chatbot`
- هر بخش یک `Module` است (`register()`)؛ فهرست در `Core/Plugin.php` و فیلتر `pwc_modules`

## ساختار

| مسیر | نقش |
|---|---|
| `persian-wp-chatbot.php` | هدر، ثابت‌ها، راه‌اندازی |
| `includes/Core/` | `Plugin`، `Activator`، `Deactivator`، `Upgrader`، `I18n` |
| `includes/Admin/` | منو، صفحه‌ها و تب‌ها |
| `includes/Rest/` | کنترلرهای REST پنل مدیریت |
| `includes/Chat/` | ویجت، ذخیرهٔ گفتگو، ارسال پیام به سرور، پاسخ‌ها، WebSocket، پاک‌سازی |
| `includes/Extraction/` | استخراج اطلاعات سایت: محصولات، وبلاگ، برگه‌ها |
| `includes/Documents/` | متن و فایل‌های اضافه |
| `includes/Database/` | جدول‌ها و storeها |
| `includes/Sync/` | ارسال رکوردها به سرور، ذخیرهٔ خودکار هنگام انتشار/ویرایش |
| `includes/Updates/` | به‌روزرسانی از GitHub Releases (با sha256) |
| `includes/Support/` | ابزارهای کمکی: تنظیمات، IP کلاینت، آیکن‌ها، وضعیت |
| `apis/` | ارتباط با سرور: کلاینت امضادار و endpointهای ورودی |
| `views/`, `assets/` | قالب‌های PHP، CSS/JS، فونت |

## صفحه‌های مدیریت

صفحه اصلی (نمای کلی) · چت بات (مدیریت چت) · منابع AI (استخراج اطلاعات سایت، اسناد اضافه، اطلاعات به‌روز) · لاگ (لاگ سرور) · پنل مانیتورینگ · تنظیمات.

## داده

- جدول‌ها: `pwc_items`، `pwc_texts`، `pwc_files`، `pwc_removed`، `pwc_logs`، `pwc_live_data`، `pwc_live_products`، `pwc_live_blog`، `pwc_live_pages`، `pwc_chat_visitors`، `pwc_messages`، `pwc_rl`
- وضعیت هر رکورد (`ai_status`): ‏`0` ارسال‌نشده · `1` در حال پردازش سمت سرور · `2` پردازش‌شده
- هر پیام چت ستون `ai_sent` دارد؛ وقتی هوش مصنوعی خاموش است چیزی به سرور نمی‌رود و پیام‌ها بعداً به‌ترتیب با هم ارسال می‌شوند.
- `uninstall.php` گزینه‌ها، جدول‌ها و پوشه‌های آپلود (`pwc-docs`، `pwc-chat`) را حذف می‌کند.

## جریان‌ها

- **آموزش**: استخراج/افزودن → ذخیره در جدول → ارسال امضادار به سرور (`ai_status=1`) → وب‌هوک `/ai/results` → `ai_status=2`.
- **چت**: پیام بازدیدکننده → ذخیره → (اگر AI روشن) ارسال دسته‌ای به سرور → وب‌هوک «پیام‌ها ثبت شد» → وب‌هوک پاسخ → نمایش با polling یا اعلان WebSocket.
- **ابزار زنده**: سرور هنگام پاسخ، قیمت/موجودی یا متن سفارشی را از `/live/*` می‌گیرد.
- **بازبینی**: cron هر ۱۰ دقیقه رکوردهای «در حال پردازش» را با سرور تطبیق می‌دهد؛ cron روزانه گفتگوهای قدیمی را پاک می‌کند.

## تنظیمات حساس (`wp-config.php`)

| ثابت | کاربرد |
|---|---|
| `PWC_API_URL` | آدرس https سرور |
| `PWC_API_SECRET` | کلید مشترک HMAC |
| `PWC_CALLBACK_SECRET` | کلید وب‌هوک (پیش‌فرض: همان کلید مشترک) |
| `PWC_FILE_LINK_SECRET` | کلید لینک دانلود فایل‌ها |
| `PWC_TRUSTED_PROXIES`, `PWC_IP_HEADER` | تشخیص IP پشت پروکسی |
| `PWC_GITHUB_REPO` | مخزن به‌روزرسانی (`owner/repo`) |

## هوک‌های توسعه

فیلترها: `pwc_modules`، `pwc_rest_controllers`، `pwc_extractors`، `pwc_extraction_sources`، `pwc_page_tabs`، `pwc_chat_reply`، `pwc_default_settings`، `pwc_trusted_proxies`، `pwc_file_link_ttl`، `pwc_monitor_prices`.
اکشن‌ها: `pwc_loaded`، `pwc_activated`، `pwc_deactivated`، `pwc_upgrade`، `pwc_ai_results_applied`، `pwc_retention_run`.

## افزودن منبع استخراج

کلاس `ExtractorInterface` را پیاده کنید، در فیلتر `pwc_extractors` با شناسهٔ منبع ثبت کنید و شناسه را به `pwc_extraction_sources` بدهید.
