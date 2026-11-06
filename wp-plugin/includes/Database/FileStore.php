<?php
namespace PersianWPChatbot\Database;

defined( 'ABSPATH' ) || exit;

/**
 * فایل‌های آپلودشدهٔ تب «اسناد اضافه» در جدول pwc_files؛ هر فایل یک رکورد.
 *
 * خود فایل در uploads/pwc-docs/سال/ماه با نام تصادفی ذخیره می‌شود و فقط مسیر نسبی‌اش در ستون path می‌ماند.
 * ai_status: ۰ = هنوز به سرور داده نشده، ۱ = ارسال شد و سرور ۲۰۰ داد (در حال پردازش؛ AiBackend::send)، ۲ = سرور پردازش را تمام کرد (وب‌هوک apis/Results).
 */
final class FileStore {

	/** سقف حجم هر فایل: ۱۰۰ مگابایت (محدودیت PHP/هاست هم جدا اعمال می‌شود؛ max_bytes()). */
	public const MAX_BYTES = 104857600;

	public const DIR = 'pwc-docs';

	/** پسوندهای مجاز. */
	public const EXTENSIONS = array( 'pdf', 'doc', 'docx', 'txt' );

	/**
	 * سقف واقعی آپلود = کمترِ ۱۰۰ مگابایت و محدودیت آپلود سرور (upload_max_filesize / post_max_size).
	 */
	public static function max_bytes(): int {
		$server = (int) wp_max_upload_size();

		return $server > 0 ? min( self::MAX_BYTES, $server ) : self::MAX_BYTES;
	}

	public static function server_limit(): int {
		return (int) wp_max_upload_size();
	}

	public static function base_dir(): string {
		$uploads = wp_upload_dir();

		return untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::DIR;
	}

	/**
	 * دستهٔ نمایشی هر پسوند در آمار: pdf | word | txt
	 */
	public static function group_of( string $ext ): string {
		$ext = strtolower( $ext );
		if ( 'doc' === $ext || 'docx' === $ext ) {
			return 'word';
		}

		return $ext;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function insert( array $row ): bool {
		global $wpdb;

		$now = time();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::files_table(),
			array(
				'id'            => (string) $row['id'],
				'title'         => (string) $row['title'],
				'original_name' => (string) $row['original_name'],
				'path'          => (string) $row['path'],
				'ext'           => (string) $row['ext'],
				'size'          => (int) $row['size'],
				'ai_status'     => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);

		return false !== $ok;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . Schema::files_table() . '` WHERE `id` = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * فهرست فایل‌ها (تازه‌ترین اول).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( int $limit = 500 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM `' . Schema::files_table() . '` ORDER BY `created_at` DESC, `id` DESC LIMIT %d', max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::present( $row );
		}

		return $out;
	}

	/**
	 * شکل خروجی برای رابط (مسیر روی دیسک هرگز بیرون نمی‌رود).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function present( array $row ): array {
		$ext  = strtolower( (string) $row['ext'] );
		$time = (int) $row['created_at'];

		return array(
			'id'            => (string) $row['id'],
			'title'         => (string) $row['title'],
			'original_name' => (string) $row['original_name'],
			'ext'           => $ext,
			'group'         => self::group_of( $ext ),
			'size'          => (int) $row['size'],
			'trained'       => 2 === (int) $row['ai_status'],
			'processing'    => 1 === (int) $row['ai_status'],
			'time'          => $time > 0 ? (string) wp_date( 'Y/m/d - H:i', $time ) : '',
		);
	}

	/**
	 * حذف کامل: فایل از سایت پاک می‌شود و رکورد از دیتابیس برداشته می‌شود.
	 */
	public static function delete( string $id ): bool {
		global $wpdb;

		$row = self::get( $id );
		if ( null === $row ) {
			return false;
		}

		$path = self::absolute_path( (string) $row['path'] );
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}

		return false !== $wpdb->delete( Schema::files_table(), array( 'id' => $id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * مسیر کامل روی دیسک؛ فقط اگر داخل uploads/pwc-docs باشد (جلوگیری از خروج از پوشه).
	 */
	public static function absolute_path( string $relative ): string {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return '';
		}

		return self::base_dir() . '/' . $relative;
	}

	/**
	 * @return array{total:int, trained:int, groups:array<string, int>}
	 */
	public static function stats(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'SELECT `ext`, COUNT(*) AS total, COALESCE(SUM(`ai_status` = 2), 0) AS trained FROM `' . Schema::files_table() . '` GROUP BY `ext`',
			ARRAY_A
		);

		$total   = 0;
		$trained = 0;
		$groups  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$group            = self::group_of( (string) $row['ext'] );
			$groups[ $group ] = ( $groups[ $group ] ?? 0 ) + (int) $row['total'];
			$total           += (int) $row['total'];
			$trained         += (int) $row['trained'];
		}

		return array(
			'total'   => $total,
			'trained' => $trained,
			'groups'  => $groups,
		);
	}
}
