<?php
namespace PersianWPChatbot\Admin\Pages;

use PersianWPChatbot\Admin\Chatbot\Tabs\ServerLogTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «لاگ»: لاگ سرور هوش مصنوعی.
 */
final class LogsPage extends AbstractTabbedPage {

	public const SLUG = 'pwc-logs';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'لاگ', 'persian-wp-chatbot' );
	}

	public function menu_title(): string {
		return __( 'لاگ', 'persian-wp-chatbot' );
	}

	protected function build_tabs(): array {
		return array(
			new ServerLogTab(),
		);
	}
}
