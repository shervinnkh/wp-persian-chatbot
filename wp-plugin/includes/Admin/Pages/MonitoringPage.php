<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «پنل مانیتورینگ» (زیرمنوی افزونه). فعلاً خالی است؛ محتوا در مراحل بعد اضافه می‌شود.
 */
final class MonitoringPage implements PageInterface {

	public const SLUG = 'paia-monitoring';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'پنل مانیتورینگ', 'persian-ai-assistant' );
	}

	public function menu_title(): string {
		return __( 'پنل مانیتورینگ', 'persian-ai-assistant' );
	}

	public function uses_app_shell(): bool {
		return false;
	}

	public function render(): void {
		View::render( 'admin/monitoring' );
	}
}
