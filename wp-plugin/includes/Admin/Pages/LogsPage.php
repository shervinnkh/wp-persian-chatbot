<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Admin\Chatbot\Tabs\ServerLogTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «لاگ»: لاگ سرور هوش مصنوعی.
 */
final class LogsPage extends AbstractTabbedPage {

	public const SLUG = 'paia-logs';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'لاگ', 'persian-ai-assistant' );
	}

	public function menu_title(): string {
		return __( 'لاگ', 'persian-ai-assistant' );
	}

	protected function build_tabs(): array {
		return array(
			new ServerLogTab(),
		);
	}
}
