<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «پنل مانیتورینگ» (زیرمنوی افزونه): یک صفحهٔ کامل بدون تب؛ هر بار باز شود گزارش را از سرور می‌گیرد
 * (روزانه / ماهانه / سالانه). رندر در assets/js/monitoring.js و دادهٔ آن از GET paia/v1/monitoring.
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
		return true; // همان پوستهٔ تیرهٔ صفحه‌های چت‌بات و تنظیمات
	}

	public function render(): void {
		View::render( 'admin/monitoring', array( 'title' => $this->page_title() ) );
	}
}
