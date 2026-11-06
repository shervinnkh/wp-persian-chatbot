<?php
namespace PersianWPChatbot\Chat;

use PersianWPChatbot\Support\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * سقف نرخ (S-1) + قفل کوتاه‌مدت.
 *
 * شمارنده‌ها اتمیک و روی جدول {prefix}pwc_rl هستند (transient اتمیک نیست). w همیشه «شمارهٔ دقیقه» است
 * (برای پنجرهٔ یک‌ساعته: دقیقهٔ ابتدای ساعت)، پس پاک‌سازی یکسان است.
 */
final class RateLimit {

	private const GROUP = 'pwc_rl';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pwc_rl';
	}

	/**
	 * یک «ضربه» ثبت می‌کند و می‌گوید زیر سقف هست یا نه.
	 *
	 * @param string $bucket نام سطل (مثلاً poll).
	 * @param string $key    شناسهٔ مصرف‌کننده (uid یا ip).
	 * @param int    $limit  حداکثر تعداد در هر پنجره.
	 * @param int    $window طول پنجره (ثانیه؛ حداقل ۶۰).
	 * @return array{ok:bool,retry_after:int,count:int}
	 */
	public static function hit( string $bucket, string $key, int $limit, int $window ): array {
		list( $n, $wait ) = self::count( $bucket . '|' . $key, $window );
		$ok               = $n <= $limit;

		return array(
			'ok'          => $ok,
			'retry_after' => $ok ? 0 : $wait,
			'count'       => $n,
		);
	}

	/**
	 * یک رویداد را در پنجرهٔ جاری می‌شمارد (لایهٔ پایین؛ بیشتر از hit() استفاده کنید).
	 *
	 * @return array{0:int,1:int} [تعداد در این پنجره، ثانیه‌های مانده تا پایان پنجره]
	 */
	public static function count( string $key, int $window_seconds = 60 ): array {
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

	/**
	 * قفل کوتاه‌مدت (مثلاً برای اینکه چند poll هم‌زمان یک کار را شروع نکنند).
	 * با object cache اتمی است؛ با transient «تقریباً» (و جلوی دوباره‌کاری را ChatStore::claim می‌گیرد).
	 */
	public static function lock( string $name, int $ttl ): bool {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $name, 1, self::GROUP, $ttl );
		}
		if ( false !== get_transient( $name ) ) {
			return false;
		}
		set_transient( $name, 1, $ttl );

		return true;
	}

	public static function unlock( string $name ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $name, self::GROUP );
			return;
		}
		delete_transient( $name );
	}

	/**
	 * IP کاربر برای کلید سقف نرخ (تشخیص IP پشت پروکسی با ClientIp است؛ مقدار فقط به‌صورت md5 در جدول ذخیره می‌شود).
	 */
	public static function ip(): string {
		return (string) ClientIp::get();
	}
}