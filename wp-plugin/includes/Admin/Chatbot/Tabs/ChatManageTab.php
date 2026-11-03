<?php
namespace PersianAIAssistant\Admin\Chatbot\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * تب «مدیریت چت»: فهرست گفتگوهای کاربران + پنجرهٔ چت برای پاسخ‌دادن به‌عنوان پشتیبان.
 * رابط کاربری با assets/js/chat-admin.js ساخته می‌شود.
 */
final class ChatManageTab extends AbstractTab {

	public function id(): string {
		return 'chatmanage';
	}

	public function title(): string {
		return __( 'مدیریت چت', 'persian-ai-assistant' );
	}
}
