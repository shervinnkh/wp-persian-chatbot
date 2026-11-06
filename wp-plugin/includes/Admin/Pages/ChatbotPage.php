<?php
namespace PersianWPChatbot\Admin\Pages;

use PersianWPChatbot\Admin\Chatbot\Tabs\ChatManageTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «چت بات»: مدیریت چت (فهرست گفتگوها + پنجرهٔ پاسخ پشتیبان).
 */
final class ChatbotPage extends AbstractTabbedPage {

	public const SLUG = 'pwc-chatbot';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'چت بات', 'persian-wp-chatbot' );
	}

	public function menu_title(): string {
		return __( 'چت بات', 'persian-wp-chatbot' );
	}

	protected function build_tabs(): array {
		return array(
			new ChatManageTab(),
		);
	}
}
