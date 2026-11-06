<?php
namespace PersianWPChatbot\Database;

defined( 'ABSPATH' ) || exit;

/**
 * متن‌های دستی تب «اسناد اضافه» در جدول pwc_texts؛ هر باکس متن یک رکورد.
 *
 * سازوکار مثل ItemStore (pwc_items): شناسهٔ رکورد «text:{عدد}»، ستون data = JSON، ستون ai_status
 * (۰ = به سرور داده نشده، ۱ = در حال پردازش سمت سرور، ۲ = پردازش‌شده)؛ اگر متن ویرایش شود و data تغییر کند، ai_status به ۰ برمی‌گردد.
 * ستون‌های اضافه: draft (۱ = فقط پیش‌نویس ذخیره شده، ۰ = برای آموزش ثبت شده)، chunks و words (برای آمار سریع بدون خواندن JSON)،
 * created_at و updated_at (timestamp).
 *
 * هر «چانک» یک تکهٔ مستقل از دانش است؛ مدیر با زدن دو بار Enter در فرم، چانک‌ها را از هم جدا می‌کند.
 */
final class TextStore {

	public const MAX_CHUNKS = 500;
	public const MAX_CHARS  = 500000;

	/**
	 * فقط چانک‌های غیرخالی، بدون تگ و با فاصله‌های اضافهٔ دو سر حذف‌شده.
	 *
	 * @param array<int, mixed> $chunks
	 * @return string[]
	 */
	public static function normalize( array $chunks ): array {
		$out = array();

		foreach ( $chunks as $chunk ) {
			if ( ! is_scalar( $chunk ) ) {
				continue;
			}
			$text = trim( sanitize_textarea_field( (string) $chunk ) );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}

		return $out;
	}

	public static function count_words( string $text ): int {
		$parts = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $parts ) ? count( $parts ) : 0;
	}

	/**
	 * @param string[] $chunks خروجی normalize()
	 */
	public static function total_chars( array $chunks ): int {
		$total = 0;
		foreach ( $chunks as $chunk ) {
			$total += function_exists( 'mb_strlen' ) ? mb_strlen( $chunk, 'UTF-8' ) : strlen( $chunk );
		}

		return $total;
	}

	/**
	 * ذخیره (ساخت یا به‌روزرسانی) یک باکس متن. اگر $id خالی یا ناموجود باشد رکورد تازه ساخته می‌شود.
	 *
	 * @param string   $id     شناسهٔ رکورد موجود (برای ادامهٔ ویرایش) یا رشتهٔ خالی.
	 * @param string[] $chunks خروجی normalize() (غیرخالی).
	 * @param bool     $draft  true = فقط پیش‌نویس، false = ثبت برای آموزش به هوش مصنوعی.
	 * @return array{ok:bool, id:string, data:array<string,mixed>}
	 */
	public static function save( string $id, array $chunks, bool $draft ): array {
		global $wpdb;

		$table = Schema::texts_table();
		$now   = time();
		$words = 0;
		foreach ( $chunks as $chunk ) {
			$words += self::count_words( $chunk );
		}

		$data = array(
			'type'   => 'text',
			'chunks' => array_values( $chunks ),
			'text'   => implode( "\n\n", $chunks ),
			'words'  => $words,
			'chars'  => self::total_chars( $chunks ),
		);
		$json = ItemStore::encode( $data );
		$fail = array(
			'ok'   => false,
			'id'   => $id,
			'data' => $data,
		);
		if ( '' === $json ) {
			return $fail;
		}

		$exists = '' !== $id && 0 === strpos( $id, 'text:' ) && null !== $wpdb->get_var( $wpdb->prepare( 'SELECT `id` FROM `' . $table . '` WHERE `id` = %s', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( $exists ) {
			// ترتیب انتساب‌ها مهم است: ai_status باید پیش از تغییر data با داده قدیمی مقایسه شود.
			$sql = $wpdb->prepare(
				'UPDATE `' . $table . '` SET `ai_status` = IF(`data` = %s, `ai_status`, 0), `data` = %s, `draft` = %d, `chunks` = %d, `words` = %d, `updated_at` = %d WHERE `id` = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$json,
				$json,
				$draft ? 1 : 0,
				count( $chunks ),
				$words,
				$now,
				$id
			);

			return array(
				'ok'   => false !== $wpdb->query( $sql ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				'id'   => $id,
				'data' => $data,
			);
		}

		$new_id = 'text:' . (string) (int) round( microtime( true ) * 1000 );
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'id'         => $new_id,
				'data'       => $json,
				'ai_status'  => 0,
				'draft'      => $draft ? 1 : 0,
				'chunks'     => count( $chunks ),
				'words'      => $words,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d' )
		);

		return array(
			'ok'   => false !== $ok,
			'id'   => $new_id,
			'data' => $data,
		);
	}

	/**
	 * آمار همهٔ باکس‌های متن (پیش‌نویس و ثبت‌شده) با یک کوئری.
	 *
	 * @return array{boxes:int, chunks:int, words:int, trained:int, drafts:int}
	 */
	public static function stats(): array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'SELECT COUNT(*) AS boxes, COALESCE(SUM(`chunks`), 0) AS chunks, COALESCE(SUM(`words`), 0) AS words, COALESCE(SUM(`ai_status` = 2), 0) AS trained, COALESCE(SUM(`draft`), 0) AS drafts FROM `' . Schema::texts_table() . '`',
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();

		return array(
			'boxes'   => (int) ( $row['boxes'] ?? 0 ),
			'chunks'  => (int) ( $row['chunks'] ?? 0 ),
			'words'   => (int) ( $row['words'] ?? 0 ),
			'trained' => (int) ( $row['trained'] ?? 0 ),
			'drafts'  => (int) ( $row['drafts'] ?? 0 ),
		);
	}
	/**
	 * فهرست باکس‌های متن (تازه‌ترین اول) برای «لیست مطالب». فقط ابتدای JSON خوانده می‌شود تا عنوان (اولین خط متن) ساخته شود؛
	 * متن کامل با get() و هنگام باز کردن جزئیات خوانده می‌شود.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( int $limit = 500 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT `id`, `ai_status`, `draft`, `chunks`, `words`, `created_at`, LEFT(`data`, 1200) AS head FROM `' . Schema::texts_table() . '` ORDER BY `created_at` DESC, `id` DESC LIMIT %d', max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$time  = (int) $row['created_at'];
			$out[] = array(
				'id'      => (string) $row['id'],
				'title'   => self::title_from_head( (string) $row['head'] ),
				'chunks'  => (int) $row['chunks'],
				'words'   => (int) $row['words'],
				'draft'   => 1 === (int) $row['draft'],
				'trained'    => 2 === (int) $row['ai_status'],
				'processing' => 1 === (int) $row['ai_status'],
				'time'    => $time > 0 ? (string) wp_date( 'Y/m/d - H:i', $time ) : '',
			);
		}

		return $out;
	}

	/**
	 * متن کامل یک باکس (چانک‌ها).
	 *
	 * @return array{id:string, chunks:string[], draft:bool, trained:bool, processing:bool}|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT `id`, `data`, `draft`, `ai_status` FROM `' . Schema::texts_table() . '` WHERE `id` = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}

		$data   = json_decode( (string) $row['data'], true );
		$chunks = is_array( $data ) && isset( $data['chunks'] ) && is_array( $data['chunks'] ) ? array_values( array_map( 'strval', $data['chunks'] ) ) : array();

		return array(
			'id'      => (string) $row['id'],
			'chunks'  => $chunks,
			'draft'   => 1 === (int) $row['draft'],
			'trained' => 2 === (int) $row['ai_status'],
			'processing' => 1 === (int) $row['ai_status'],
		);
	}

	/**
	 * حذف کامل یک باکس متن از دیتابیس.
	 */
	public static function delete( string $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::texts_table(), array( 'id' => $id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * پیش‌نویس را «ثبت‌شده برای آموزش» می‌کند (draft = 0). ai_status دست نمی‌خورد.
	 */
	public static function publish( string $id ): bool {
		global $wpdb;

		return false !== $wpdb->update( Schema::texts_table(), array( 'draft' => 0 ), array( 'id' => $id ), array( '%d' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * عنوان فهرست: اولین خطِ اولین چانک از ابتدای JSON (که ممکن است وسط بریده شده باشد).
	 */
	private static function title_from_head( string $head ): string {
		if ( 1 !== preg_match( '/"chunks":\["((?:[^"\\\\]|\\\\.)*)/u', $head, $match ) ) {
			return '';
		}

		$raw = $match[1];
		// کاراکتر escape ناقصِ انتهای رشتهٔ بریده‌شده حذف می‌شود.
		if ( 1 === preg_match( '/(\\\\+)$/', $raw, $slashes ) && 1 === strlen( $slashes[1] ) % 2 ) {
			$raw = substr( $raw, 0, -1 );
		}
		$raw   = (string) preg_replace( '/\\\\u[0-9a-fA-F]{0,3}$/', '', $raw );
		$value = json_decode( '"' . $raw . '"' );
		if ( ! is_string( $value ) ) {
			return '';
		}

		$line = trim( (string) strtok( trim( $value ), "\n" ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $line, 'UTF-8' ) > 90 ) {
			$line = rtrim( mb_substr( $line, 0, 90, 'UTF-8' ) ) . '…';
		}

		return $line;
	}
}
