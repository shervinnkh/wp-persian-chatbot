<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «تنظیمات» (زیرمنوی افزونه). فعلاً خالی است؛ محتوا در مراحل بعد اضافه می‌شود.
 */
final class SettingsPage implements PageInterface {

	public const SLUG = 'paia-settings';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'تنظیمات', 'persian-ai-assistant' );
	}

	public function menu_title(): string {
		return __( 'تنظیمات', 'persian-ai-assistant' );
	}

	public function uses_app_shell(): bool {
		return false;
	}

	public function render(): void {
		View::render( 'admin/settings' );
	}
}
