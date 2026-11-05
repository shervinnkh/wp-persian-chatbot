<?php
namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * شمارندهٔ اتمیک پنجرهٔ ثابت روی جدول {prefix}paia_rl (S-1). از transient استفاده نمی‌شود چون اتمیک نیست.
 * w همیشه «شمارهٔ دقیقه» است (برای پنجرهٔ یک‌ساعته: دقیقهٔ ابتدای ساعت)، پس پاک‌سازی یکسان است.
 */
final class RateLimit {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'paia_rl';
	}

	/**
	 * یک رویداد را در پنجرهٔ جاری می‌شمارد.
	 *
	 * @return array{0:int,1:int} [تعداد در این پنجره، ثانیه‌های مانده تا پایان پنجره]
	 */
	public static function hit( string $key, int $window_seconds = 60 ): array {
		global $wpdb;
		$table  = self::table();
		$now    = time();
		$window = max( 60, $window_seconds );
		$w      = intdiv( $now, $window ) * intdiv( $window, 60 );
		$k      = md5( $key );
		$retry  = ( intdiv( $now, $window ) + 1 ) * $window - $now;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (k, w, n) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE n = IF(w = VALUES(w), n + 1, 1), w = VALUES(w)", $k, $w ) );
		if ( false === $ok ) {
			return array( 0, $retry ); // خطای دیتابیس: چت را نمی‌بندیم.
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT n FROM {$table} WHERE k = %s", $k ) );

		if ( 1 === wp_rand( 1, 100 ) ) {
			self::purge();
		}

		return array( $n, max( 1, $retry ) );
	}

	/** ردیف‌های با w قدیمی‌تر از ۱ روز. */
	public static function purge(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE w < %d", intdiv( time(), 60 ) - 1440 ) );
	}
}
