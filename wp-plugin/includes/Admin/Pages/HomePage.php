<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Admin\Chatbot\Tabs\GeneralTab;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ اصلی: کلید وضعیت چت‌بات + آمار کلی (تب «عمومی» قدیمی). همیشه باز است، حتی با چت‌بات خاموش.
 */
final class HomePage extends AbstractTabbedPage {

	public const SLUG = 'paia';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'صفحه اصلی', 'persian-ai-assistant' );
	}

	public function menu_title(): string {
		return __( 'صفحه اصلی', 'persian-ai-assistant' );
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
