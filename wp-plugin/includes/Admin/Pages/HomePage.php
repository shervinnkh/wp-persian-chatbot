<?php
namespace PersianWPChatbot\Admin\Pages;

use PersianWPChatbot\Admin\Chatbot\Tabs\GeneralTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ اصلی: کلید وضعیت چت‌بات + آمار کلی (تب «عمومی» قدیمی). همیشه باز است، حتی با چت‌بات خاموش.
 */
final class HomePage extends AbstractTabbedPage {

	public const SLUG = 'pwc';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'صفحه اصلی', 'persian-wp-chatbot' );
	}

	public function menu_title(): string {
		return __( 'صفحه اصلی', 'persian-wp-chatbot' );
	}

	protected function lock_when_disabled(): bool {
		return false;
	}

	protected function build_tabs(): array {
		return array(
			new GeneralTab(),
		);
	}
}
