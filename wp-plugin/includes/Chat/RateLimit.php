<?php
/**
 * محدودکنندهٔ نرخ سبک (پنجرهٔ ثابت) برای مسیرهای عمومی چت.
 *
 * اگر object cache پایدار (Redis/Memcached) فعال باشد از همان استفاده می‌شود (بدون نوشتن در جدول options)؛
 * وگرنه با transient کار می‌کند. کلید شامل شمارهٔ پنجره است، پس بعد از پایان پنجره خودبه‌خود صفر می‌شود.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	private const GROUP = 'paia_rl';

	/**
	 * یک «ضربه» ثبت می‌کند.
	 *
	 * @param string $bucket نام سطل (مثلاً poll).
	 * @param string $key    شناسهٔ مصرف‌کننده (uid یا ip).
	 * @param int    $limit  حداکثر تعداد در هر پنجره.
	 * @param int    $window طول پنجره (ثانیه).
	 * @return array{ok:bool,retry_after:int,count:int}
	 */
	public static function hit( string $bucket, string $key, int $limit, int $window ): array {
		$window = max( 1, $window );
		$slot   = (int) floor( time() / $window );
		$name   = 'paia_rl_' . $bucket . '_' . md5( $key ) . '_' . $slot;
		$count  = self::incr( $name, $window + 5 );

		if ( $count > $limit ) {
			return array(
				'ok'          => false,
				'retry_after' => max( 1, ( $slot + 1 ) * $window - time() ),
				'count'       => $count,
			);
		}

		return array(
			'ok'          => true,
			'retry_after' => 0,
			'count'       => $count,
		);
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
	 * IP کاربر (برای کلید کمکی). پشت پروکسی معکوس باید REMOTE_ADDR در وب‌سرور درست تنظیم شده باشد.
	 */
	public static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	}

	private static function incr( string $name, int $ttl ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $name, 0, self::GROUP, $ttl );
			$n = wp_cache_incr( $name, 1, self::GROUP );

			return false === $n ? 1 : (int) $n;
		}
		$n = (int) get_transient( $name ) + 1;
		set_transient( $name, $n, $ttl );

		return $n;
	}
}
