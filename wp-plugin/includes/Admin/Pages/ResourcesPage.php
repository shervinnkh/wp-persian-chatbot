<?php
namespace PersianWPChatbot\Admin\Pages;

use PersianWPChatbot\Admin\Chatbot\Tabs\ExtractionTab;
use PersianWPChatbot\Admin\Chatbot\Tabs\DocumentsTab;
use PersianWPChatbot\Admin\Chatbot\Tabs\UpdatesTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «منابع AI»: سه تب استخراج اطلاعات سایت، اسناد اضافه و اطلاعات به‌روز (لایو).
 */
final class ResourcesPage extends AbstractTabbedPage {

	public const SLUG = 'pwc-resources';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'منابع AI', 'persian-wp-chatbot' );
	}

	public function menu_title(): string {
		return __( 'منابع AI', 'persian-wp-chatbot' );
	}

	protected function build_tabs(): array {
		return array(
			new ExtractionTab(),
			new DocumentsTab(),
			new UpdatesTab(),
		);
	}
}
