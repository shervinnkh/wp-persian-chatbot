<?php
namespace PersianAIAssistant\Apis;

use PersianAIAssistant\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * دسترسی سبک و دسته‌ای به وضعیت رکوردهای قابل‌آموزش در سه جدول: paia_items، paia_texts، paia_files.
 *
 * نوع رکورد از پیشوند شناسه معلوم می‌شود: «text:…» متن دستی، «file:…» فایل آپلودی، بقیه (product:، blog:، page:، site:، …) آیتم‌ها.
 *
 * «نسخه» (rev): توکن مبهمی که افزونه هنگام ارسال رکورد به سرور می‌دهد و سرور موقع اعلام نتیجه عیناً پس می‌فرستد.
 * اگر رکورد بین ارسال و نتیجه تغییر کرده باشد نسخه فرق می‌کند و نتیجهٔ کهنه نادیده گرفته می‌شود
 * (وگرنه رکوردِ ویرایش‌شده اشتباهاً «آموزش‌دیده» می‌شد):
 *   - آیتم: ستون sig  - متن: MD5 ستون data  - فایل: updated_at
 */
final class Records {

	/** ai_status: ۰ = ارسال نشده / آموزش‌ندیده */
	public const STATUS_NONE = 0;
	/** ai_status: ۱ = برای سرور فرستاده شد (سرور ۲۰۰ داد)؛ منتظر اعلام نتیجه (در حال پردازش سمت سرور) */
	public const STATUS_PROCESSING = 1;
	/** ai_status: ۲ = سرور پردازش را تمام و به افزونه خبر داد (آیکن هوش مصنوعی) */
	public const STATUS_DONE = 2;

	public const ITEM = 'item';
	public const TEXT = 'text';
	public const FILE = 'file';

	public static function kind_of( string $id ): string {
		if ( 0 === strpos( $id, 'text:' ) ) {
			return self::TEXT;
		}
		if ( 0 === strpos( $id, 'file:' ) ) {
			return self::FILE;
		}

		return self::ITEM;
	}

	private static function table( string $kind ): string {
		switch ( $kind ) {
			case self::TEXT:
				return Schema::texts_table();
			case self::FILE:
				return Schema::files_table();
			default:
				return Schema::table();
		}
	}

	/**
	 * نسخهٔ فعلی یک رکورد (برای افزودن به درخواست ارسال به سرور). رشتهٔ خالی = رکورد نیست/نسخه ندارد.
	 */
	public static function revision( string $id ): string {
		$map = self::revisions( self::kind_of( $id ), array( $id ) );

		return $map[ $id ] ?? '';
	}

	/**
	 * فقط شناسه‌هایی که در جدول هستند برمی‌گردند (شناسه => نسخه).
	 *
	 * @param string[] $ids
	 * @return array<string, string>
	 */
	public static function revisions( string $kind, array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
		$out = array();
		if ( ! $ids ) {
			return $out;
		}

		switch ( $kind ) {
			case self::TEXT:
				$rev = 'MD5(`data`)';
				break;
			case self::FILE:
				$rev = 'CAST(`updated_at` AS CHAR)';
				break;
			default:
				$rev = '`sig`';
		}

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql     = $wpdb->prepare( 'SELECT `id`, ' . $rev . ' AS rev FROM `' . self::table( $kind ) . '` WHERE `id` IN (' . $holders . ')', $chunk ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows    = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$out[ (string) $row['id'] ] = (string) $row['rev'];
			}
		}

		return $out;
	}

	/**
	 * ai_status این رکوردها را یک‌جا (دسته‌ای) تنظیم می‌کند؛ ایدمپوتنت است (رکوردی که از قبل همین وضعیت را دارد تغییر نمی‌کند).
	 *
	 * @param string[] $ids
	 * @param int      $status ۰ = آموزش‌ندیده، ۱ = در حال پردازش سمت سرور، ۲ = پردازش‌شده.
	 * @return int تعداد رکوردهای واقعاً تغییریافته.
	 */
	public static function set_status( string $kind, array $ids, int $status ): int {
		global $wpdb;

		$status = in_array( $status, array( self::STATUS_PROCESSING, self::STATUS_DONE ), true ) ? $status : self::STATUS_NONE;
		$ids    = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
		$total  = 0;

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql     = $wpdb->prepare( 'UPDATE `' . self::table( $kind ) . '` SET `ai_status` = %d WHERE `ai_status` <> %d AND `id` IN (' . $holders . ')', array_merge( array( $status, $status ), $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$done    = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$total  += is_int( $done ) ? $done : 0;
		}

		return $total;
	}

	/**
	 * «در حال پردازش» (ai_status = 1) برای رکوردهایی که سرور درخواستشان را پذیرفت (۲۰۰).
	 * شناسه‌ها از هر نوعی می‌توانند باشند (آیتم/متن/فایل)؛ خودش بر اساس پیشوند گروه‌بندی می‌کند.
	 *
	 * @param string[] $ids
	 * @return int تعداد رکوردهای تغییریافته.
	 */
	public static function mark_processing( array $ids ): int {
		$total = 0;
		foreach ( self::group_by_kind( $ids ) as $kind => $list ) {
			$total += self::set_status( $kind, $list, self::STATUS_PROCESSING );
		}

		return $total;
	}

	/**
	 * اگر سرور برای رکوردی «failed» گفت، حالت «در حال پردازش» (۱) باید برداشته شود (۰)،
	 * وگرنه آیکن loading برای همیشه می‌ماند. رکوردی که ۱ نیست (مثلاً ۲) دست نمی‌خورد.
	 *
	 * @param string[] $ids
	 * @return int تعداد رکوردهای تغییریافته.
	 */
	public static function release_processing( array $ids ): int {
		global $wpdb;

		$total = 0;
		foreach ( self::group_by_kind( $ids ) as $kind => $list ) {
			foreach ( array_chunk( $list, 200 ) as $chunk ) {
				$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$sql     = $wpdb->prepare( 'UPDATE `' . self::table( $kind ) . '` SET `ai_status` = 0 WHERE `ai_status` = 1 AND `id` IN (' . $holders . ')', $chunk ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$done    = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$total  += is_int( $done ) ? $done : 0;
			}
		}

		return $total;
	}

	/**
	 * وضعیت فعلی (۰/۱/۲) چند رکورد از هر نوعی؛ فقط شناسه‌هایی که در جدول هستند برمی‌گردند.
	 *
	 * @param string[] $ids
	 * @return array<string, int> شناسه => ai_status
	 */
	public static function statuses( array $ids ): array {
		global $wpdb;

		$out = array();
		foreach ( self::group_by_kind( $ids ) as $kind => $list ) {
			foreach ( array_chunk( $list, 200 ) as $chunk ) {
				$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$sql     = $wpdb->prepare( 'SELECT `id`, `ai_status` FROM `' . self::table( $kind ) . '` WHERE `id` IN (' . $holders . ')', $chunk ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows    = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$out[ (string) $row['id'] ] = (int) $row['ai_status'];
				}
			}
		}

		return $out;
	}

	/**
	 * شناسهٔ رکوردهای «در حال پردازش» (ai_status = 1) از هر سه جدول؛ ورودی کرون بازبینی (Reconciler).
	 *
	 * @return string[]
	 */
	public static function processing_ids( int $limit = 500 ): array {
		global $wpdb;

		$out = array();
		foreach ( array( self::ITEM, self::TEXT, self::FILE ) as $kind ) {
			$left = $limit - count( $out );
			if ( $left <= 0 ) {
				break;
			}
			$sql  = $wpdb->prepare( 'SELECT `id` FROM `' . self::table( $kind ) . '` WHERE `ai_status` = 1 ORDER BY `id` LIMIT %d', $left ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( is_array( $rows ) ? $rows : array() as $id ) {
				$out[] = (string) $id;
			}
		}

		return $out;
	}

	/**
	 * @param string[] $ids
	 * @return array<string, string[]> نوع رکورد => شناسه‌ها
	 */
	private static function group_by_kind( array $ids ): array {
		$groups = array();
		foreach ( array_unique( array_filter( array_map( 'strval', $ids ) ) ) as $id ) {
			$groups[ self::kind_of( $id ) ][] = $id;
		}

		return $groups;
	}
}
