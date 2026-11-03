<?php
namespace PersianAIAssistant\Database;

defined( 'ABSPATH' ) || exit;

/**
 * نوشته‌هایی (محصول، وبلاگ، برگه) که از سایت حذف شده‌اند و رکوردشان در paia_items بود؛ جدول {prefix}paia_removed.
 *
 * جریان: هنگام حذف (انتقال به زباله‌دان یا حذف دائمی) رکورد از paia_items برداشته و اینجا ثبت می‌شود (add_from_record)
 * تا در تب «استخراج اطلاعات سایت» بیاید و مدیر تصمیم بگیرد از آموزش‌های هوش مصنوعی هم حذف شود یا نه.
 * با زدن «حذف» (RemovedStore::delete) ردیف از لیست خارج و درخواست حذف به بک‌اند هوش مصنوعی می‌رود (AiBackend::delete).
 */
final class RemovedStore {

	/**
	 * true = فقط رکوردهایی که هوش مصنوعی دربارهٔ آن‌ها چیزی دارد/خواهد داشت (ai_status ≥ 1: در حال پردازش یا پردازش‌شده) در لیست می‌آیند؛
	 * بقیه بی‌صدا پاک می‌شوند. (از ۰.۵.۱۸ با اتصال واقعی به سرور فعال است.)
	 */
	public const ONLY_TRAINED = true;

	/**
	 * رکورد را از paia_items برمی‌دارد و (در صورت نیاز) در لیست حذف‌شده‌ها ثبت می‌کند.
	 *
	 * @return bool true اگر در لیست حذف‌شده‌ها ثبت شد.
	 */
	public static function add_from_record( string $record_id ): bool {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT `ai_status`, LEFT(`data`, 1500) AS head FROM `' . Schema::table() . '` WHERE `id` = %s', $record_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) ) {
			return false; // رکوردی نبود؛ هوش مصنوعی هم چیزی دربارهٔ آن نمی‌داند.
		}

		// «هوش مصنوعی چیزی دربارهٔ آن دارد/خواهد داشت»: در حال پردازش (۱) یا پردازش‌شده (۲). ستون ai_status جدول paia_removed فقط ۰/۱ است.
		$trained = (int) $row['ai_status'] >= 1;
		ItemStore::delete( $record_id );

		if ( self::ONLY_TRAINED && ! $trained ) {
			return false;
		}

		$ok = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::removed_table(),
			array(
				'id'         => $record_id,
				'title'      => self::title_from_head( (string) $row['head'] ),
				'ai_status'  => $trained ? 1 : 0,
				'removed_at' => time(),
			),
			array( '%s', '%s', '%d', '%d' )
		);

		return false !== $ok;
	}

	/**
	 * اگر نوشته دوباره منتشر شد (مثلاً از زباله‌دان بازگردانده شد)، از لیست حذف‌شده‌ها خارج می‌شود.
	 *
	 * @return bool true اگر ردیفی پاک شد.
	 */
	public static function forget( string $record_id ): bool {
		return self::delete( $record_id );
	}

	/**
	 * ai_status ردیف: ۰ = (بدون آموزش) · ۱ = هوش مصنوعی دربارهٔ آن دانش دارد · ۲ = درخواست حذف به سرور رفت و سرور پذیرفت (در حال حذف؛ loading).
	 */
	public const DELETING = 2;

	/**
	 * سرور درخواست حذف را پذیرفت: ردیف در لیست می‌ماند ولی «در حال حذف» (loading) می‌شود تا وب‌هوک `untrained` برسد.
	 */
	public static function mark_deleting( string $record_id ): bool {
		global $wpdb;

		return false !== $wpdb->update( Schema::removed_table(), array( 'ai_status' => self::DELETING ), array( 'id' => $record_id ), array( '%d' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * حذف در سرور شکست خورد (وب‌هوک failed / بازبینی): ردیف دوباره «قابل حذف» می‌شود.
	 *
	 * @param string[] $ids
	 */
	public static function release_deleting( array $ids ): int {
		global $wpdb;

		$total = 0;
		foreach ( array_chunk( array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) ), 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql     = $wpdb->prepare( 'UPDATE `' . Schema::removed_table() . '` SET `ai_status` = 1 WHERE `ai_status` = 2 AND `id` IN (' . $holders . ')', $chunk ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$done    = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$total  += is_int( $done ) ? $done : 0;
		}

		return $total;
	}

	/**
	 * سرور حذف را تمام کرد (وب‌هوک `untrained`): ردیف‌های «در حال حذف» از لیست برداشته می‌شوند.
	 *
	 * @param string[] $ids
	 * @return string[] شناسهٔ ردیف‌هایی که واقعاً برداشته شدند.
	 */
	public static function finish( array $ids ): array {
		global $wpdb;

		$gone = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) ), 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$found   = $wpdb->get_col( $wpdb->prepare( 'SELECT `id` FROM `' . Schema::removed_table() . '` WHERE `ai_status` = 2 AND `id` IN (' . $holders . ')', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $found ) || ! $found ) {
				continue;
			}
			$h2 = implode( ',', array_fill( 0, count( $found ), '%s' ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . Schema::removed_table() . '` WHERE `id` IN (' . $h2 . ')', $found ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$gone = array_merge( $gone, array_map( 'strval', $found ) );
		}

		return $gone;
	}

	/**
	 * شناسهٔ ردیف‌های «در حال حذف» (ورودی بازبینی از سرور).
	 *
	 * @param string[]|null $only اگر داده شود فقط از بین این شناسه‌ها.
	 * @return string[]
	 */
	public static function deleting_ids( ?array $only = null, int $limit = 500 ): array {
		global $wpdb;

		if ( null !== $only ) {
			$only = array_slice( array_values( array_unique( array_filter( array_map( 'strval', $only ) ) ) ), 0, 200 );
			if ( ! $only ) {
				return array();
			}
			$holders = implode( ',', array_fill( 0, count( $only ), '%s' ) );
			$rows    = $wpdb->get_col( $wpdb->prepare( 'SELECT `id` FROM `' . Schema::removed_table() . '` WHERE `ai_status` = 2 AND `id` IN (' . $holders . ')', $only ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		} else {
			$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT `id` FROM `' . Schema::removed_table() . '` WHERE `ai_status` = 2 ORDER BY `id` LIMIT %d', max( 1, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	public static function delete( string $record_id ): bool {
		global $wpdb;

		$done = $wpdb->delete( Schema::removed_table(), array( 'id' => $record_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_int( $done ) && $done > 0;
	}

	public static function exists( string $record_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . Schema::removed_table() . '` WHERE `id` = %s', $record_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Schema::removed_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * تازه‌ترین حذف‌شده‌ها اول.
	 *
	 * @return array<int, array{id:string, title:string, trained:bool, processing:bool, time:int}>
	 */
	public static function list( int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT `id`, `title`, `ai_status`, `removed_at` FROM `' . Schema::removed_table() . '` ORDER BY `removed_at` DESC, `id` DESC LIMIT %d', max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'      => (string) $row['id'],
				'title'   => (string) $row['title'],
				'trained' => 1 === (int) $row['ai_status'],
				'processing' => self::DELETING === (int) $row['ai_status'],
				'time'    => (int) $row['removed_at'],
			);
		}

		return $out;
	}

	/**
	 * عنوان از ابتدای JSON ذخیره‌شده (کلید title همیشه اول هر آیتم است؛ JSON ممکن است وسط بریده شده باشد).
	 */
	private static function title_from_head( string $head ): string {
		if ( 1 === preg_match( '/"title":("(?:[^"\\\\]|\\\\.)*")/u', $head, $match ) ) {
			$value = json_decode( $match[1], true );
			if ( is_string( $value ) ) {
				return mb_substr( $value, 0, 250 );
			}
		}

		return '';
	}
}
