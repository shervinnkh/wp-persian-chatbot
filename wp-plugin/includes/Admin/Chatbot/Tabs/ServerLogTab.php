<?php
namespace PersianAIAssistant\Admin\Chatbot\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * تب «لاگ سرور»: آمار کلی درخواست‌ها + فهرست لاگ‌ها (موفق سبز، ناموفق قرمز، عادی بدون رنگ ویژه).
 * داده با JS از REST (LogsController) خوانده می‌شود؛ آمار اولیه در window.PAIA_ADMIN.logs است.
 */
final class ServerLogTab extends AbstractTab {

	public function id(): string {
		return 'serverlog';
	}

	public function title(): string {
		return __( 'لاگ سرور', 'persian-ai-assistant' );
	}
}
