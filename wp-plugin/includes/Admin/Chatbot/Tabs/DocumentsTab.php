<?php
namespace PersianAIAssistant\Admin\Chatbot\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * تب «اسناد اضافه»: کارت «مطالب اضافه شده» (آمار فایل‌ها و متن‌ها) + فرم «اضافه کردن متن جدید».
 * آمار با JS از window.PAIA_ADMIN.docs رسم و پس از هر ذخیره از پاسخ REST به‌روز می‌شود.
 */
final class DocumentsTab extends AbstractTab {

	public function id(): string {
		return 'documents';
	}

	public function title(): string {
		return __( 'اسناد اضافه', 'persian-ai-assistant' );
	}
}
