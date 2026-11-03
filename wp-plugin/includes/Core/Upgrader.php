<?php
namespace PersianAIAssistant\Core;

use PersianAIAssistant\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * مدیریت ارتقای نسخه‌ها و مهاجرت‌های آینده (مثلاً ساخت جدول‌ها).
 */
final class Upgrader {

	private const OPTION = 'paia_version';

	public function maybe_upgrade(): void {
		// جدول اطلاعات استخراج‌شده (روی نصب تازه و روی ارتقا از نسخه‌های قدیمی).
		Schema::maybe_install();

		$installed = (string) get_option( self::OPTION, '0' );

		if ( version_compare( $installed, PAIA_VERSION, '>=' ) ) {
			return;
		}

		// ۰.۵.۱۷: ai_status سه‌حالته شد (۰ ارسال‌نشده، ۱ در حال پردازش، ۲ پردازش‌شده). قبلاً ۱ = «آموزش‌دیده» بود.
		if ( '0' !== $installed && version_compare( $installed, '0.5.17', '<' ) ) {
			$this->migrate_ai_status();
		}

		/**
		 * @param string $installed نسخهٔ قبلی.
		 * @param string $current   نسخهٔ فعلی.
		 */
		do_action( 'paia_upgrade', $installed, PAIA_VERSION );

		update_option( self::OPTION, PAIA_VERSION, false );
	}

	/**
	 * رکوردهایی که با معنای قدیمی «۱ = آموزش‌دیده» ثبت شده‌اند ۲ (پردازش‌شده) می‌شوند. جدول paia_removed دست نمی‌خورد (فقط ۰/۱ است).
	 */
	private function migrate_ai_status(): void {
		global $wpdb;

		foreach ( array( Schema::table(), Schema::texts_table(), Schema::files_table() ) as $table ) {
			$wpdb->query( 'UPDATE `' . $table . '` SET `ai_status` = 2 WHERE `ai_status` = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
