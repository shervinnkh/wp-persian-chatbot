<?php
namespace PersianWPChatbot\Admin\Pages;

use PersianWPChatbot\Admin\Chatbot\Tabs\SettingsTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «تنظیمات»: همهٔ تنظیمات افزونه (منوی کناری عمومی / استخراج اطلاعات سایت / مدیریت چت).
 */
final class SettingsPage extends AbstractTabbedPage {

	public const SLUG = 'pwc-settings';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'تنظیمات', 'persian-wp-chatbot' );
	}

	public function menu_title(): string {
		return __( 'تنظیمات', 'persian-wp-chatbot' );
	}

	protected function build_tabs(): array {
		return array(
			new SettingsTab(),
		);
	}
}
