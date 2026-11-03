<?php
namespace PersianAIAssistant\Database;

defined( 'ABSPATH' ) || exit;

/**
 * اطلاعات به‌روز (تب «اطلاعات به‌روز») — جدول‌های استاندارد با ستون‌های جدا (بدون JSON):
 *
 *  - {prefix}paia_live_products / paia_live_blog / paia_live_pages  تنظیمات «اطلاعات به‌روز تمام محصولات/وبلاگ‌ها/برگه‌ها»؛
 *      در هر جدول همیشه فقط یک ردیف (id = 1). enabled = فعال بودن کل این مورد؛ هر ستون دیگر (FIELDS) یعنی
 *      «این فیلد باید هنگام پاسخ، زنده از سایت گرفته شود» (پیش‌فرض همه ۱).
 *      فیلدی که «زنده» باشد (enabled = 1 و تیک خورده) دیگر در اطلاعات استخراج‌شده ذخیره/نمایش/ارسال نمی‌شود (Extraction\LiveFields).
 *  - {prefix}paia_live_data      اطلاعات به‌روزی که مدیر تعریف می‌کند: name (نام نوع داده = نام ابزار هوش مصنوعی)،
 *      description (توضیح کوتاه = توضیح ابزار؛ هوش مصنوعی با آن تشخیص می‌دهد کی سراغ داده برود)،
 *      content (اطلاعات داده)، enabled، created_at، updated_at.
 */
final class LiveStore {

	/** فیلدهای قابل «زنده» بودن هر منبع (نام ستون‌های جدول تنظیمات همان‌هاست). */
	public const FIELDS = array(
		'products' => array( 'price', 'regular_price', 'on_sale', 'stock_status', 'stock_qty', 'backorder' ),
		'blog'     => array( 'comment_count', 'modified' ),
		'pages'    => array( 'modified' ),
	);

	/**
	 * منبع‌هایی که فعلاً در افزونه نمایش داده نمی‌شوند و همیشه «غیرفعال» حساب می‌شوند (از ۰.۵.۲۴): اطلاعات به‌روز وبلاگ‌ها و برگه‌ها.
	 * کد و جدول‌هایشان عمداً نگه داشته شده؛ برای فعال‌کردن دوباره کافی است اینجا را خالی کنید.
	 */
	public const HIDDEN_SOURCES = array( 'blog', 'pages' );

	public const NAME_MAX    = 120;
	public const DESC_MAX    = 500;
	public const CONTENT_MAX = 20000;

	/** @var array<string, array{enabled:bool, fields:array<string,bool>}> کش همین درخواست (برای استخراج دسته‌ای) */
	private static $cache = array();

	/* ------------------- محصولات / وبلاگ‌ها / برگه‌ها ------------------- */

	/**
	 * @return string[]
	 */
	public static function sources(): array {
		return array_values( array_diff( array_keys( self::FIELDS ), self::HIDDEN_SOURCES ) );
	}

	public static function is_hidden( string $source ): bool {
		return in_array( $source, self::HIDDEN_SOURCES, true );
	}

	/**
	 * @return array{enabled:bool, fields:array<string,bool>}
	 */
	public static function source( string $source ): array {
		if ( ! isset( self::FIELDS[ $source ] ) || self::is_hidden( $source ) ) {
			// منبع ناشناخته یا پنهان (وبلاگ/برگه): همیشه غیرفعال؛ فیلدهایش جزو اطلاعات استخراج‌شده می‌مانند.
			return array( 'enabled' => false, 'fields' => array() );
		}
		if ( isset( self::$cache[ $source ] ) ) {
			return self::$cache[ $source ];
		}

		global $wpdb;

		$row = $wpdb->get_row( 'SELECT * FROM `' . Schema::live_source_table( $source ) . '` WHERE `id` = 1', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = is_array( $row ) ? $row : array();

		$fields = array();
		foreach ( self::FIELDS[ $source ] as $key ) {
			$fields[ $key ] = ! isset( $row[ $key ] ) || 1 === (int) $row[ $key ]; // ردیف نبود = پیش‌فرض فعال
		}

		self::$cache[ $source ] = array(
			'enabled' => ! isset( $row['enabled'] ) || 1 === (int) $row['enabled'],
			'fields'  => $fields,
		);

		return self::$cache[ $source ];
	}

	/**
	 * فیلدهایی از این منبع که «زنده» حساب می‌شوند: کل مورد فعال باشد و فیلد تیک خورده باشد.
	 *
	 * @return string[]
	 */
	public static function live_keys( string $source ): array {
		$config = self::source( $source );
		if ( ! $config['enabled'] ) {
			return array();
		}

		return array_keys( array_filter( $config['fields'] ) );
	}

	/**
	 * @return array<string, array{enabled:bool, fields:array<string,bool>}>
	 */
	public static function sources_config(): array {
		$out = array();
		foreach ( self::sources() as $source ) {
			$out[ $source ] = self::source( $source );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $fields کلید فیلد => bool (کلیدهای ناشناخته نادیده گرفته می‌شوند؛ نبودن کلید = false)
	 */
	public static function save_source( string $source, array $fields ): bool {
		if ( ! isset( self::FIELDS[ $source ] ) || self::is_hidden( $source ) ) {
			return false;
		}

		$data   = array( 'updated_at' => time() );
		$format = array( '%d' );
		foreach ( self::FIELDS[ $source ] as $key ) {
			$data[ $key ] = ! empty( $fields[ $key ] ) ? 1 : 0;
			$format[]     = '%d';
		}

		return self::update_source( $source, $data, $format );
	}

	public static function set_source_enabled( string $source, bool $enabled ): bool {
		if ( ! isset( self::FIELDS[ $source ] ) || self::is_hidden( $source ) ) {
			return false;
		}

		return self::update_source( $source, array( 'enabled' => $enabled ? 1 : 0, 'updated_at' => time() ), array( '%d', '%d' ) );
	}

	/**
	 * @param array<string, int> $data
	 * @param string[]           $format
	 */
	private static function update_source( string $source, array $data, array $format ): bool {
		global $wpdb;

		self::ensure_rows();
		unset( self::$cache[ $source ] );

		return false !== $wpdb->update( Schema::live_source_table( $source ), $data, array( 'id' => 1 ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** ردیف تنظیمات هر منبع را (اگر نبود) با مقدارهای پیش‌فرض می‌سازد. */
	public static function ensure_rows(): void {
		global $wpdb;

		foreach ( self::sources() as $source ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO `' . Schema::live_source_table( $source ) . '` (`id`, `updated_at`) VALUES (1, %d)', time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		self::$cache = array();
	}

	/* ------------------------- اطلاعات تعریف‌شده ------------------------- */

	/**
	 * فهرست (جدیدترین ویرایش اول) بدون متن کامل.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function items(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'SELECT `id`, `name`, `enabled`, `updated_at` FROM `' . Schema::live_table() . '` ORDER BY `id` DESC',
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::present( $row );
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>|null با متن کامل
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . Schema::live_table() . '` WHERE `id` = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? self::present( $row ) : null;
	}

	/**
	 * ساخت ($id = 0) یا ویرایش. خروجی: شناسهٔ رکورد یا 0 در شکست.
	 */
	public static function save( int $id, string $name, string $description, string $content ): int {
		global $wpdb;

		$table = Schema::live_table();
		$now   = time();

		if ( $id > 0 ) {
			if ( null === self::get( $id ) ) {
				return 0;
			}
			$ok = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'name'        => $name,
					'description' => $description,
					'content'     => $content,
					'updated_at'  => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);

			return false === $ok ? 0 : $id;
		}

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'name'        => $name,
				'description' => $description,
				'content'     => $content,
				'enabled'     => 1,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::live_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function set_enabled( int $id, bool $enabled ): bool {
		global $wpdb;

		return false !== $wpdb->update( Schema::live_table(), array( 'enabled' => $enabled ? 1 : 0, 'updated_at' => time() ), array( 'id' => $id ), array( '%d', '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------- اتصال به ابزارهای هوش مصنوعی (Tool Calling) ------------------- */

	/** حداکثر ابزار داینامیکی که با هر پیام به سرور می‌رود (TOOLS_MAX_DYNAMIC سرور = ۳۰). */
	public const AI_TOOLS_MAX = 30;

	/** گروه‌های قابل‌اعلام به سرور (live_product_datas) ← فیلدهای تب «اطلاعات به‌روز» که هر گروه را می‌سازند. */
	public const AI_PRODUCT_GROUPS = array(
		'price' => array( 'price', 'regular_price', 'on_sale' ),
		'stock' => array( 'stock_status', 'stock_qty', 'backorder' ),
	);

	/**
	 * فیلدهای زندهٔ محصول که همین الان مجازند (مدیر در تب «اطلاعات به‌روز» روشن گذاشته): کلید فیلد => true.
	 *
	 * @return array<string, bool>
	 */
	public static function ai_product_keys(): array {
		$out = array();
		foreach ( self::live_keys( 'products' ) as $key ) {
			$out[ $key ] = true;
		}

		return $out;
	}

	/**
	 * مقدار «live_product_datas» پیام: زیرمجموعهٔ [price, stock]. خالی = سایت داده لایو محصول ندارد (سرور ابزار محصول را به AI نمی‌دهد).
	 *
	 * @return string[]
	 */
	public static function ai_product_fields(): array {
		$live = self::ai_product_keys();
		$out  = array();
		foreach ( self::AI_PRODUCT_GROUPS as $group => $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $live[ $key ] ) ) {
					$out[] = $group;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * ابزارهای داینامیک (ردیف‌های فعال «اطلاعات تعریف‌شده») به شکل tools[] پیام: [{id, title, description}].
	 * id = شناسهٔ ردیف (عدد)؛ API شمارهٔ ۲ (/live/tool) با همین id متن زنده را از دیتابیس می‌خواند.
	 *
	 * @return array<int, array{id:int, title:string, description:string}>
	 */
	public static function ai_tools(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT `id`, `name`, `description` FROM `' . Schema::live_table() . "` WHERE `enabled` = 1 AND `content` <> '' ORDER BY `id` ASC LIMIT %d",
				self::AI_TOOLS_MAX
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$title = trim( (string) $row['name'] );
			if ( '' === $title ) {
				continue;
			}
			$out[] = array(
				'id'          => (int) $row['id'],
				'title'       => $title,
				'description' => trim( (string) $row['description'] ),
			);
		}

		return $out;
	}

	/**
	 * دادهٔ اولیهٔ صفحه (window.PAIA_ADMIN.live).
	 *
	 * @return array<string, mixed>
	 */
	public static function overview(): array {
		return array(
			'sources' => self::sources_config(),
			'items'   => self::items(),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function present( array $row ): array {
		$time = (int) ( $row['updated_at'] ?? 0 );
		$out  = array(
			'id'      => (int) $row['id'],
			'name'    => (string) $row['name'],
			'enabled' => 1 === (int) $row['enabled'],
			'time'    => $time > 0 ? (string) wp_date( 'Y/m/d - H:i', $time ) : '',
		);
		if ( isset( $row['description'] ) ) {
			$out['description'] = (string) $row['description'];
			$out['content']     = (string) ( $row['content'] ?? '' );
		}

		return $out;
	}
}
