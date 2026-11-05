<?php
/**
 * نگهداری محدود داده‌های چت (S-8): رویداد روزانهٔ WP-Cron با نام paia_daily_cleanup.
 *
 *  • پیام‌ها و فایل‌های پیوستِ قدیمی‌تر از ۹۰ روز (قابل‌تغییر با فیلتر paia_retention_days)؛
 *  • بازدیدکننده‌های بدون پیام که ۷ روز است دیده نشده‌اند (و ردیف‌های وضعیت/تایپینگِ بی‌صاحب)؛
 *  • transientهای منقضی‌شدهٔ محدودیت نرخ (paia_rl_*) و شناسهٔ ارسال‌ها (paia_dlv_*)؛
 *  • نقطهٔ توسعه برای جدول محدودیت نرخ (S-1): action با نام paia_retention_run.
 *
 * حذف فایل‌ها فقط با wp_delete_file و فقط وقتی مسیر واقعاً داخل uploads/paia-chat باشد.
 * زمان‌بندی: Core\Activator (فعال‌سازی) و init (برای افزونه‌های به‌روزرسانی‌شده)؛ حذف: Core\Deactivator.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Database\LogStore;

defined( 'ABSPATH' ) || exit;

final class Retention {

	public const CRON_HOOK    = 'paia_daily_cleanup';
	public const DEFAULT_DAYS = 90;
	public const VISITOR_DAYS = 7;

	/** هر دسته چند پیام حذف شود، و حداکثر چند دسته در یک اجرا (باقی‌مانده فردا). */
	private const BATCH       = 500;
	private const MAX_BATCHES = 40;

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** تعداد روزهای نگهداری پیام‌ها (حداقل ۱). */
	public static function days(): int {
		return max( 1, (int) apply_filters( 'paia_retention_days', self::DEFAULT_DAYS ) );
	}

	/**
	 * اجرای پاک‌سازی (cron یا دستی: do_action( 'paia_daily_cleanup' )).
	 *
	 * @return array{messages:int, files:int, visitors:int, transients:int}
	 */
	public function run(): array {
		global $wpdb;

		$now      = time();
		$cutoff   = gmdate( 'Y-m-d H:i:s', $now - self::days() * DAY_IN_SECONDS );
		$messages = ChatSchema::messages_table();
		$visitors = ChatSchema::visitors_table();
		$status   = ChatSchema::status_table();
		$sum      = array(
			'messages'   => 0,
			'files'      => 0,
			'visitors'   => 0,
			'transients' => 0,
		);

		// ۱) پیام‌های قدیمی + فایل‌های پیوستشان (دسته‌ای).
		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, attachment_path FROM {$messages} WHERE created_at < %s ORDER BY id ASC LIMIT %d", $cutoff, self::BATCH ) );
			if ( ! $rows ) {
				break;
			}
			$ids = array();
			foreach ( $rows as $row ) {
				$ids[] = (int) $row->id;
				if ( '' !== (string) $row->attachment_path && self::delete_file( (string) $row->attachment_path ) ) {
					++$sum['files'];
				}
			}
			$in = implode( ',', array_map( 'intval', $ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$deleted = $wpdb->query( "DELETE FROM {$messages} WHERE id IN ({$in})" );
			$sum['messages'] += (int) $deleted;
			if ( count( $rows ) < self::BATCH || ! $deleted ) {
				break;
			}
		}

		// ۲) بازدیدکنندهٔ بدون هیچ پیام که ۷ روز است دیده نشده + ردیف‌های وضعیتِ بی‌صاحب.
		$visitor_cut = gmdate( 'Y-m-d H:i:s', $now - self::VISITOR_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$sum['visitors'] = (int) $wpdb->query( $wpdb->prepare( "DELETE v FROM {$visitors} v LEFT JOIN {$messages} m ON m.visitor_uid = v.uid WHERE m.id IS NULL AND v.last_seen < %s", $visitor_cut ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE s FROM {$status} s LEFT JOIN {$visitors} v ON v.uid = s.visitor_uid WHERE v.uid IS NULL" );

		// ۳) transientهای منقضی‌شده (محدودیت نرخ و شناسهٔ ارسال‌ها).
		foreach ( array( 'paia_rl_', 'paia_dlv_' ) as $prefix ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$sum['transients'] += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE a, b FROM {$wpdb->options} a LEFT JOIN {$wpdb->options} b ON b.option_name = REPLACE( a.option_name, '_transient_timeout_', '_transient_' ) WHERE a.option_name LIKE %s AND a.option_value < %d",
					$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%',
					$now
				)
			);
		}

		/**
		 * نقطهٔ توسعه (مثلاً پاک‌کردن ردیف‌های جدول محدودیت نرخ paia_rl در S-1).
		 *
		 * @param int                  $now     زمان اجرا (unix).
		 * @param array<string, int>   $summary آمار حذف‌ها تا اینجا.
		 */
		do_action( 'paia_retention_run', $now, $sum );

		if ( $sum['messages'] || $sum['files'] || $sum['visitors'] ) {
			LogStore::add(
				LogStore::INFO,
				sprintf(
					/* translators: 1: messages, 2: files, 3: visitors */
					__( 'پاک‌سازی روزانهٔ چت: %1$s پیام، %2$s فایل پیوست و %3$s بازدیدکنندهٔ بدون پیام حذف شد.', 'persian-ai-assistant' ),
					number_format_i18n( $sum['messages'] ),
					number_format_i18n( $sum['files'] ),
					number_format_i18n( $sum['visitors'] )
				),
				array( 'endpoint' => 'cron:' . self::CRON_HOOK )
			);
		}

		return $sum;
	}

	/**
	 * فایل پیوست را فقط اگر داخل uploads/paia-chat باشد با wp_delete_file پاک می‌کند.
	 * مسیرِ ذخیره‌شده‌ای که از سایت قدیمی/جابه‌جاشده مانده (مسیر مطلق دیگر) با نشانهٔ «/paia-chat/» به پوشهٔ فعلی نگاشت می‌شود.
	 *
	 * @return bool true = فایل واقعاً حذف شد.
	 */
	public static function delete_file( string $path ): bool {
		$base = realpath( Uploads::base_dir() );
		if ( false === $base || '' === $path ) {
			return false;
		}
		$path = str_replace( '\\', '/', $path );
		$mark = '/' . Uploads::DIR . '/';
		$pos  = strrpos( $path, $mark );
		if ( false === $pos ) {
			return false;
		}
		$rel = substr( $path, $pos + strlen( $mark ) );
		if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
			return false;
		}

		$real = realpath( $base . '/' . $rel );
		if ( false === $real || ! is_file( $real ) || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		wp_delete_file( $real );

		return ! file_exists( $real );
	}
}
