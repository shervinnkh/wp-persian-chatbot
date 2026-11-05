<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Admin\Chatbot\Tabs\ExtractionTab;
use PersianAIAssistant\Admin\Chatbot\Tabs\DocumentsTab;
use PersianAIAssistant\Admin\Chatbot\Tabs\UpdatesTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «منابع AI»: سه تب استخراج اطلاعات سایت، اسناد اضافه و اطلاعات به‌روز (لایو).
 */
final class ResourcesPage extends AbstractTabbedPage {

	public const SLUG = 'paia-resources';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'منابع AI', 'persian-ai-assistant' );
	}

	public function menu_title(): string {
		return __( 'منابع AI', 'persian-ai-assistant' );
	}

	protected function build_tabs(): array {
		return array(
			new ExtractionTab(),
			new DocumentsTab(),
			new UpdatesTab(),
		);
	}
}
