<?php
/**
 * پاک‌سازی روزانهٔ داده‌های چت (P-2 Retention): جدول‌ها و پوشهٔ uploads/paia-chat بی‌پایان رشد نکنند.
 *
 * هوک کرون: paia_daily_cleanup (روزانه؛ ثبت در فعال‌سازی + اطمینان در init، لغو در غیرفعال‌سازی).
 * هر اجرا:
 *  ۱. پیام‌های قدیمی‌تر از `paia_retention_days` روز (پیش‌فرض ۹۰؛ ۰ = خاموش) + فایل پیوست آن‌ها (فقط داخل uploads/paia-chat).
 *  ۲. بازدیدکننده‌هایی که هیچ پیامی ندارند و last_seen‌شان قدیمی‌تر از ۷ روز است (+ ردیف وضعیت تایپینگشان).
 * هر مرحله حداکثر `paia_cleanup_batch` ردیف (پیش‌فرض ۵۰۰) در هر اجرا؛ اگر هنوز کار مانده باشد یک اجرای
 * تک‌باره ۱ دقیقه بعد با WP-Cron زمان‌بندی می‌شود تا هاست قفل نشود و انبار قدیمی هم کم‌کم خالی شود.
 *
 * تنظیمات (option، بدون رابط): paia_retention_days، paia_cleanup_batch.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class Cleanup {

	public const HOOK               = 'paia_daily_cleanup';
	public const OPT_RETENTION      = 'paia_retention_days';
	public const OPT_BATCH          = 'paia_cleanup_batch';
	public const DEFAULT_DAYS       = 90;
	public const DEFAULT_BATCH      = 500;
	public const EMPTY_VISITOR_DAYS = 7;

	/** فقط هوک را می‌بندد (کار سنگینی در register نیست). زمان‌بندی در schedule() است. */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/** اگر هنوز زمان‌بندی نشده، اجرای روزانه را ثبت می‌کند (فعال‌سازی + init). */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function retention_days(): int {
		return max( 0, (int) get_option( self::OPT_RETENTION, self::DEFAULT_DAYS ) );
	}

	private static function batch_size(): int {
		return max( 50, min( 5000, (int) get_option( self::OPT_BATCH, self::DEFAULT_BATCH ) ) );
	}

	/**
	 * @return array{messages:int, files:int, visitors:int, more:bool} آمار این اجرا.
	 */
	public function run(): array {
		global $wpdb;
		$report = array(
			'messages' => 0,
			'files'    => 0,
			'visitors' => 0,
			'more'     => false,
		);
		// چت‌بات هرگز نصب نشده: جدولی نیست.
		if ( false === get_option( ChatSchema::OPTION ) ) {
			return $report;
		}

		$batch    = self::batch_size();
		$messages = ChatSchema::messages_table();
		$visitors = ChatSchema::visitors_table();
		$status   = ChatSchema::status_table();
		$days     = self::retention_days();

		// ۱) پیام‌های قدیمی + فایل‌هایشان.
		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, attachment_path FROM {$messages} WHERE created_at < %s ORDER BY id ASC LIMIT %d", $cutoff, $batch ) );
			if ( $rows ) {
				foreach ( $rows as $row ) {
					if ( self::delete_attachment( (string) $row->attachment_path ) ) {
						++$report['files'];
					}
				}
				$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$wpdb->query( "DELETE FROM {$messages} WHERE id IN (" . implode( ',', $ids ) . ')' );
				$report['messages'] = count( $ids );
				$report['more']     = count( $ids ) >= $batch;
				Unread::flush();
			}
		}

		// ۲) بازدیدکننده‌های بدون پیام که مدتی است برنگشته‌اند (پیام‌های بالا همین الان پاک شده‌اند، پس بازدیدکنندهٔ خالی‌شده هم می‌آید).
		$seen_before = gmdate( 'Y-m-d H:i:s', time() - self::EMPTY_VISITOR_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$uids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT v.uid FROM {$visitors} v WHERE v.last_seen < %s
				 AND NOT EXISTS ( SELECT 1 FROM {$messages} m WHERE m.visitor_uid = v.uid )
				 AND NOT ( v.wp_user_id > 0 AND EXISTS ( SELECT 1 FROM {$messages} m2 WHERE m2.wp_user_id = v.wp_user_id ) )
				 LIMIT %d",
				$seen_before,
				$batch
			)
		);
		if ( $uids ) {
			$in = implode( ',', array_fill( 0, count( $uids ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$visitors} WHERE uid IN ({$in})", $uids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$status} WHERE visitor_uid IN ({$in})", $uids ) );
			$report['visitors'] = count( $uids );
			$report['more']     = $report['more'] || count( $uids ) >= $batch;
		}

		// هنوز کار مانده: یک اجرای تک‌باره بعد (WP-Cron خودش اجرای تکراریِ هم‌زمان را یکی می‌کند).
		if ( $report['more'] ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}

		return $report;
	}

	/** فایل پیوست را فقط اگر داخل uploads/paia-chat باشد پاک می‌کند. */
	private static function delete_attachment( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		$uploads = wp_upload_dir();
		$base    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'paia-chat/' );
		$norm    = wp_normalize_path( $path );
		if ( 0 === strpos( $norm, $base ) && false === strpos( $norm, '..' ) && is_file( $norm ) ) {
			wp_delete_file( $norm );
			return true;
		}
		return false;
	}
}
