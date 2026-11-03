<?php
namespace PersianAIAssistant\Database;

defined( 'ABSPATH' ) || exit;

/**
 * لاگ سرور هوش مصنوعی در جدول paia_logs (تب «لاگ سرور»).
 *
 * هر لاگ: status (success = موفق، error = ناموفق، info = عادی)، message (متن لاگ؛ در لیست اگر بلند باشد با «…» کوتاه می‌شود)،
 * data (JSON جزئیات: متد، آدرس، کد وضعیت، مدت، خطا، …) و created_at (timestamp).
 *
 * پر کردن از سرور: LogStore::add( LogStore::SUCCESS, 'متن', array( 'method' => 'POST', ... ) ).
 */
final class LogStore {

	public const SUCCESS = 'success';
	public const ERROR   = 'error';
	public const INFO    = 'info';

	public const SEEDED_OPTION = 'paia_logs_seeded';

	/** حداکثر نویسه‌های متنِ پیش‌نمایش در لیست (بقیه با «…» بریده می‌شود). */
	private const PREVIEW = 200;

	/**
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( self::SUCCESS, self::ERROR, self::INFO );
	}

	/**
	 * @param array<string, mixed> $data جزئیات (هر کلید/مقدار دلخواه؛ در تب با برچسب فارسی کلیدهای شناخته‌شده نمایش داده می‌شود).
	 * @param int                  $time timestamp؛ صفر = همین الان.
	 * @return int شناسهٔ لاگ ساخته‌شده (۰ = ناموفق).
	 */
	public static function add( string $status, string $message, array $data = array(), int $time = 0 ): int {
		global $wpdb;

		if ( ! in_array( $status, self::statuses(), true ) ) {
			$status = self::INFO;
		}

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::logs_table(),
			array(
				'status'     => $status,
				'message'    => $message,
				'data'       => $data ? ItemStore::encode( $data ) : '',
				'created_at' => $time > 0 ? $time : time(),
			),
			array( '%s', '%s', '%s', '%d' )
		);

		return false !== $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * آمار کارت بالای تب با یک کوئری.
	 *
	 * @return array{total:int, success:int, error:int, info:int}
	 */
	public static function stats(): array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'SELECT COUNT(*) AS total, COALESCE(SUM(`status` = \'success\'), 0) AS success, COALESCE(SUM(`status` = \'error\'), 0) AS error FROM `' . Schema::logs_table() . '`',
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();

		$total   = (int) ( $row['total'] ?? 0 );
		$success = (int) ( $row['success'] ?? 0 );
		$error   = (int) ( $row['error'] ?? 0 );

		return array(
			'total'   => $total,
			'success' => $success,
			'error'   => $error,
			'info'    => max( 0, $total - $success - $error ),
		);
	}

	/**
	 * لیست لاگ‌ها (تازه‌ترین اول) با متن پیش‌نمایش. صفحه‌بندی با شناسه: فقط لاگ‌های قدیمی‌تر از $before.
	 *
	 * @return array{items:array<int, array<string, mixed>>, has_more:bool}
	 */
	public static function list( int $limit, int $before = 0 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$table = Schema::logs_table();
		$cols  = '`id`, `status`, LEFT(`message`, ' . ( self::PREVIEW + 1 ) . ') AS preview, CHAR_LENGTH(`message`) AS len, `created_at`';

		if ( $before > 0 ) {
			$sql = $wpdb->prepare( 'SELECT ' . $cols . ' FROM `' . $table . '` WHERE `id` < %d ORDER BY `id` DESC LIMIT %d', $before, $limit + 1 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$sql = $wpdb->prepare( 'SELECT ' . $cols . ' FROM `' . $table . '` ORDER BY `id` DESC LIMIT %d', $limit + 1 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = is_array( $rows ) ? $rows : array();

		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );

		$items = array();
		foreach ( $rows as $row ) {
			$long = (int) $row['len'] > self::PREVIEW;
			$text = (string) $row['preview'];
			if ( $long ) {
				$text = rtrim( self::cut( $text, self::PREVIEW ) ) . '…';
			}

			$items[] = array(
				'id'      => (int) $row['id'],
				'status'  => self::clean_status( (string) $row['status'] ),
				'message' => $text,
				'long'    => $long,
				'time'    => self::time_label( (int) $row['created_at'] ),
			);
		}

		return array(
			'items'    => $items,
			'has_more' => $has_more,
		);
	}

	/**
	 * متن کامل و جزئیات یک لاگ (با باز شدن ردیف خوانده می‌شود).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT `id`, `status`, `message`, `data`, `created_at` FROM `' . Schema::logs_table() . '` WHERE `id` = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}

		$data = json_decode( (string) $row['data'], true );

		return array(
			'id'      => (int) $row['id'],
			'status'  => self::clean_status( (string) $row['status'] ),
			'message' => (string) $row['message'],
			'time'    => self::time_label( (int) $row['created_at'] ),
			'data'    => is_array( $data ) ? $data : (object) array(),
		);
	}

	/**
	 * دو لاگ آزمایشی (یک موفق با متن بلند، یک ناموفق) فقط یک بار و فقط اگر جدول خالی باشد.
	 * بعداً که سرور شروع به نوشتن لاگ کرد، این دو را می‌شود از دیتابیس پاک کرد.
	 */
	public static function seed_test(): void {
		global $wpdb;

		if ( '' !== (string) get_option( self::SEEDED_OPTION, '' ) ) {
			return;
		}
		update_option( self::SEEDED_OPTION, '1', false );

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Schema::logs_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $count > 0 ) {
			return;
		}

		$now = time();

		self::add(
			self::SUCCESS,
			'درخواست همگام‌سازی محصولات با سرویس هوش مصنوعی با موفقیت انجام شد. ۱۲۰ محصول دریافت و به چانک‌های کوچک‌تر تقسیم شد و برای ساخت امبدینگ در صف پردازش قرار گرفت. پس از پایان پردازش، نتیجه برای وردپرس ارسال می‌شود و وضعیت رکوردها به‌روز خواهد شد. این متن آزمایشی است و برای نمایش کوتاه‌شدن متن‌های بلند در لیست ساخته شده است.',
			array(
				'method'      => 'POST',
				'endpoint'    => '/api/v1/sync',
				'http_status' => 200,
				'duration_ms' => 842,
				'ip'          => '127.0.0.1',
				'request'     => array(
					'event' => 'create',
					'count' => 120,
				),
				'response'    => array(
					'accepted' => 120,
					'queued'   => true,
				),
			),
			$now - 3600
		);

		self::add(
			self::ERROR,
			'اتصال به سرویس هوش مصنوعی برقرار نشد: زمان انتظار پاسخ به پایان رسید.',
			array(
				'method'      => 'POST',
				'endpoint'    => '/api/v1/chat',
				'http_status' => 504,
				'duration_ms' => 30000,
				'ip'          => '127.0.0.1',
				'error'       => 'cURL error 28: Operation timed out after 30001 milliseconds',
				'request'     => array(
					'message' => 'سلام، قیمت این محصول چنده؟',
				),
			),
			$now - 600
		);
	}

	private static function clean_status( string $status ): string {
		return in_array( $status, self::statuses(), true ) ? $status : self::INFO;
	}

	private static function time_label( int $time ): string {
		return $time > 0 ? (string) wp_date( 'Y/m/d - H:i:s', $time ) : '';
	}

	private static function cut( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}
}
