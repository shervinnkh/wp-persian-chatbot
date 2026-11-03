<?php
namespace PersianAIAssistant\Core;

use PersianAIAssistant\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * بارگذاری فایل‌های ترجمه از پوشهٔ languages.
 * زبان مبدأ فارسی است؛ برای افزودن زبان جدید فقط فایل .po/.mo همان زبان را اضافه کنید.
 */
final class I18n implements Module {

	public const DOMAIN = 'persian-ai-assistant';

	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( self::DOMAIN, false, dirname( PAIA_BASENAME ) . '/languages' );
	}
}
