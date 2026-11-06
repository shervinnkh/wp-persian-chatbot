<?php
namespace PersianWPChatbot\Admin\Pages;

use PersianWPChatbot\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «پنل مانیتورینگ» (زیرمنوی افزونه): یک صفحهٔ کامل بدون تب؛ هر بار باز شود گزارش را از سرور می‌گیرد
 * (روزانه / ماهانه / سالانه). رندر در assets/js/monitoring.js و دادهٔ آن از GET pwc/v1/monitoring.
 */
final class MonitoringPage implements PageInterface {

	public const SLUG = 'pwc-monitoring';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'پنل مانیتورینگ', 'persian-wp-chatbot' );
	}

	public function menu_title(): string {
		return __( 'پنل مانیتورینگ', 'persian-wp-chatbot' );
	}

	public function uses_app_shell(): bool {
		return true; // همان پوستهٔ تیرهٔ صفحه‌های چت‌بات و تنظیمات
	}

	public function render(): void {
		View::render( 'admin/monitoring', array( 'title' => $this->page_title() ) );
	}
}
