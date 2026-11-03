<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Support\View;

defined( 'ABSPATH' ) || exit;

final class HomePage implements PageInterface {

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

	public function uses_app_shell(): bool {
		return false;
	}

	public function render(): void {
		View::render( 'admin/home' );
	}
}
