<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Admin\Chatbot\Tabs\SettingsTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «تنظیمات»: همهٔ تنظیمات افزونه (منوی کناری عمومی / استخراج اطلاعات سایت / مدیریت چت).
 */
final class SettingsPage extends AbstractTabbedPage {

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

	protected function build_tabs(): array {
		return array(
			new SettingsTab(),
		);
	}
}
