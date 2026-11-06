# چت‌بات هوشمند فارسی برای وردپرس

چت‌بات فارسی برای سایت‌های وردپرسی که بر اساس اطلاعات خود سایت (محصولات، نوشته‌ها، برگه‌ها و فایل‌ها) به بازدیدکننده‌ها پاسخ می‌دهد (RAG).
پروژه دو بخش دارد:

| پوشه | چیست | کجا اجرا می‌شود |
|---|---|---|
| [`wp-plugin/`](wp-plugin) | افزونهٔ وردپرس (PHP ۷.۴+، وردپرس ۶.۰+) | سایت وردپرس |
| [`ai-server/`](ai-server) | سرور هوش مصنوعی (FastAPI + Qdrant + OpenAI) با Docker | VPS |

## شروع سریع

۱. سرور را طبق [INSTALL.md](INSTALL.md) روی VPS بالا بیاور.
۲. فایل `persian-wp-chatbot.zip` را از صفحهٔ **Releases** همین ریپو دانلود و در وردپرس نصب کن.
۳. دو ثابت `PWC_API_URL` و `PWC_API_SECRET` را در `wp-config.php` بگذار (جزئیات در INSTALL.md).

## به‌روزرسانی افزونه

افزونه خودش Releases همین ریپو را بررسی می‌کند. نسخهٔ جدید در صفحهٔ «افزونه‌ها» وردپرس دیده می‌شود و نصب فقط با تأیید مدیر سایت انجام می‌شود.
پیش از نصب، sha256 بسته با فایل `.sha256` کنار همان release مقایسه می‌شود.

### انتشار نسخهٔ جدید (برای توسعه‌دهنده)

۱. نسخه را در هدر `wp-plugin/persian-wp-chatbot.php` (`Version:`) و ثابت `PWC_VERSION` همان فایل — هر دو — بالا ببر.
۲. تغییرات را در `CHANGELOG.md` بنویس و commit کن.
۳. تگ بزن و push کن:

```bash
git tag plugin-v1.0.1
git push origin plugin-v1.0.1
```

GitHub Actions ([`plugin-release.yml`](.github/workflows/plugin-release.yml)) خودش بسته را می‌سازد و Release را با دو فایل
`persian-wp-chatbot.zip` و `persian-wp-chatbot.zip.sha256` منتشر می‌کند. ریپو باید **public** باشد تا سایت‌ها بتوانند آپدیت را بگیرند.

ساخت دستی بسته برای تست محلی: `bash scripts/build-plugin.sh OWNER/REPO` ← خروجی در `dist/`.

## مستندات

- [INSTALL.md](INSTALL.md) — نصب سرور و افزونه
- [docs/contract.md](docs/contract.md) — قرارداد API بین افزونه و سرور
- [docs/architecture-plugin.md](docs/architecture-plugin.md) و [docs/architecture-server.md](docs/architecture-server.md)
- [docs/server-production-notes.md](docs/server-production-notes.md) — چک‌لیست production

## مجوز

[MIT](LICENSE)
