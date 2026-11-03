<?php
/**
 * شمارندهٔ سبک پیام‌های خوانده‌نشدهٔ چت‌بات برای نوتیف منوی وردپرس.
 *
 * یک کوئری COUNT ساده (JOIN روی کلید اصلی بازدیدکننده + ایندکس پیام‌ها). در حالت «بهینه‌شده» نتیجه
 * ۳۰ ثانیه کش می‌شود و با هر پیام جدید/خوانده‌شدن/حذف پیام کش پاک می‌شود.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class Unread {

	private const CACHE_KEY = 'paia_unread_msgs';
	private const CACHE_TTL = 30;
	private const CACHE_KEY_CONV = 'paia_unread_convs';

	public static function count(): int {
		$optimized = ChatSettings::is_optimized();
		if ( $optimized ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		global $wpdb;
		$m = ChatSchema::messages_table();
		$v = ChatSchema::visitors_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} m INNER JOIN {$v} v ON v.uid = m.visitor_uid WHERE m.role = 'user' AND m.id > v.admin_read_id" );

		if ( $optimized ) {
			set_transient( self::CACHE_KEY, $n, self::CACHE_TTL );
		}
		return $n;
	}

	/**
	 * تعداد گفت‌وگوهایی که دست‌کم یک پیام خوانده‌نشده دارند (برای تب «عمومی»).
	 */
	public static function conversations(): int {
		$optimized = ChatSettings::is_optimized();
		if ( $optimized ) {
			$cached = get_transient( self::CACHE_KEY_CONV );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		global $wpdb;
		$m = ChatSchema::messages_table();
		$v = ChatSchema::visitors_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$n = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT m.visitor_uid) FROM {$m} m INNER JOIN {$v} v ON v.uid = m.visitor_uid WHERE m.role = 'user' AND m.id > v.admin_read_id" );

		if ( $optimized ) {
			set_transient( self::CACHE_KEY_CONV, $n, self::CACHE_TTL );
		}
		return $n;
	}

	/** پس از هر تغییری که تعداد نخوانده‌ها را عوض می‌کند صدا زده می‌شود. */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::CACHE_KEY_CONV );
	}

	/**
	 * نشان استاندارد وردپرس (مثل نشان افزونه‌ها/دیدگاه‌ها) برای کنار عنوان منو.
	 */
	public static function bubble( int $n ): string {
		if ( $n <= 0 ) {
			return '';
		}
		return sprintf(
			' <span class="update-plugins paia-bubble count-%1$d"><span class="plugin-count">%2$s</span></span>',
			$n,
			esc_html( number_format_i18n( $n ) )
		);
	}
}
