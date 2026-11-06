<?php
namespace PersianWPChatbot\Core;

use PersianWPChatbot\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * مدیریت ارتقای نسخه‌ها و مهاجرت‌های آینده (مثلاً ساخت جدول‌ها).
 */
final class Upgrader {

	private const OPTION = 'pwc_version';

	public function maybe_upgrade(): void {
		// جدول اطلاعات استخراج‌شده (روی نصب تازه و روی ارتقا از نسخه‌های قدیمی).
		Schema::maybe_install();
		\PersianWPChatbot\Chat\ChatSchema::maybe_install(); // جدول pwc_rl (S-1)

		$installed = (string) get_option( self::OPTION, '0' );

		if ( version_compare( $installed, PWC_VERSION, '>=' ) ) {
			return;
		}

		/**
		 * @param string $installed نسخهٔ قبلی.
		 * @param string $current   نسخهٔ فعلی.
		 */
		do_action( 'pwc_upgrade', $installed, PWC_VERSION );

		update_option( self::OPTION, PWC_VERSION, false );
	}

}
