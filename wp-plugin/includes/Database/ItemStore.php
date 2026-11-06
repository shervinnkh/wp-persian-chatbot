<?php
namespace PersianWPChatbot\Database;

defined( 'ABSPATH' ) || exit;

/**
 * ذخیره‌سازی اطلاعات استخراج‌شده در جدول pwc_items؛ هر مورد یک رکورد.
 *
 * شناسهٔ رکورد: «دسته:شناسهٔ واقعی» (product:23 ، blog:45 ، page:7).
 * اطلاعات سایت یک رکورد است (site:{blog_id}) و دسته‌بندی/طبقه‌بندی‌های هر منبع هم یک رکورد ({دسته}_taxonomies:all).
 *
 * (ai_status: ۰ ارسال‌نشده، ۱ در حال پردازش سمت سرور، ۲ پردازش‌شده.)
 * اگر مورد دوباره استخراج شود و «آنچه برای هوش مصنوعی مهم است» تغییر کرده باشد، ai_status به 0 برمی‌گردد (نیاز به آموزش دوباره)؛
 * وگرنه ai_status دست‌نخورده می‌ماند. «آنچه مهم است» = ستون sig (signature()): برای محصول فقط عنوان + توضیحات
 * (توضیح کوتاه و کامل)، برای بقیهٔ رکوردها کل داده. داده همیشه به‌روز ذخیره می‌شود.
 */
final class ItemStore {

	public static function record_id( string $category, $id ): string {
		return $category . ':' . $id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function encode( array $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * امضای «آنچه هوش مصنوعی باید بداند». محصول: فقط عنوان و توضیحات (نه قیمت، موجودی، تصویر و ...)؛ بقیه: کل داده.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function signature( string $id, array $data ): string {
		if ( 0 === strpos( $id, 'product:' ) ) {
			$basis = self::encode(
				array(
					(string) ( $data['title'] ?? '' ),
					(string) ( $data['excerpt'] ?? '' ),
					(string) ( $data['content'] ?? '' ),
				)
			);
		} else {
			$basis = self::encode( $data );
		}

		return md5( $basis );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param int                  $seen مقدار ستون seen فقط هنگام ساخت رکورد تازه (رکورد موجود دست‌نخورده می‌ماند):
	 *                                   1 = دیده‌شده (پیش‌فرض)، 0 = ثبت خودکار که هنوز دیده نشده.
	 */
	public static function save( string $id, array $data, int $seen = 1 ): bool {
		global $wpdb;

		$json = self::encode( $data );
		if ( '' === $json || '' === $id ) {
			return false;
		}

		// ترتیب انتساب‌ها مهم است: ai_status باید پیش از تغییر sig/data با مقدار قدیمی مقایسه شود.
		// رکورد قدیمی (sig خالی) فقط با مقایسهٔ کل data سنجیده می‌شود.
		$sig = self::signature( $id, $data );
		$sql = $wpdb->prepare(
			'INSERT INTO `' . Schema::table() . '` (`id`, `data`, `sig`, `ai_status`, `seen`) VALUES (%s, %s, %s, 0, %d)
			ON DUPLICATE KEY UPDATE `ai_status` = IF(`sig` = VALUES(`sig`) OR (`sig` = \'\' AND `data` = VALUES(`data`)), `ai_status`, 0), `data` = VALUES(`data`), `sig` = VALUES(`sig`)',
			$id,
			$json,
			$sig,
			$seen ? 1 : 0
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$ok = false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		// یک شناسه هم‌زمان در pwc_items و در لیست «موارد حذف‌شده» نباشد (دو نمایش برای یک مورد).
		if ( $ok ) {
			RemovedStore::forget( $id );
		}

		return $ok;
	}

	/**
	 * کلیدهای «ناپایدار» که با هر ویرایش (حتی ویرایش فقط اطلاعات زنده) عوض می‌شوند و جزو اطلاعات ثابت نیستند.
	 */
	private const VOLATILE_KEYS = array( 'modified', 'comment_count', 'stats', 'extraction' );

	/**
	 * هش «اطلاعات ثابت» یک رکورد: بدون فیلدهای زندهٔ فعال (تب «اطلاعات به‌روز») و بدون کلیدهای ناپایدار
	 * (تاریخ ویرایش، شمارندهٔ دیدگاه، آمار و ردپای استخراج، امتیاز محصول). برای تصمیم «آیا اصلاً باید در دیتابیس نوشت؟».
	 *
	 * @param array<string, mixed> $data
	 */
	private static function stable_hash( string $id, array $data ): string {
		$data     = json_decode( self::encode( $data ), true ); // نرمال‌سازی یکسان برای دادهٔ تازه و دادهٔ خوانده‌شده از دیتابیس
		$data     = is_array( $data ) ? $data : array();
		$category = (string) strstr( $id, ':', true );

		foreach ( \PersianWPChatbot\Extraction\Sources::all() as $source_id => $source ) {
			if ( (string) ( $source['record'] ?? '' ) === $category ) {
				$data = \PersianWPChatbot\Extraction\LiveFields::strip( (string) $source_id, $data );
				break;
			}
		}

		foreach ( self::VOLATILE_KEYS as $key ) {
			unset( $data[ $key ] );
		}
		if ( isset( $data['product'] ) && is_array( $data['product'] ) ) {
			unset( $data['product']['rating'], $data['product']['rating_count'], $data['product']['review_count'] );
		}

		return md5( self::encode( $data ) );
	}

	/**
	 * مثل save() اما گزارش می‌دهد رکورد تازه ساخته شد یا فقط به‌روز شد، آیا داده واقعاً تغییر کرد (changed)،
	 * آیا آنچه برای هوش مصنوعی مهم است تغییر کرد (ai_changed؛ محصول: عنوان یا توضیحات)،
	 * و آیا پیش از این ذخیره به هوش مصنوعی آموزش داده شده بود (برای ذخیرهٔ خودکار هنگام انتشار/ویرایش).
	 *
	 * @param array<string, mixed> $data
	 * @return array{ok:bool, created:bool, changed:bool, ai_changed:bool, was_trained:bool}
	 */
	public static function upsert( string $id, array $data, int $seen_on_insert = 1 ): array {
		global $wpdb;

		$json = self::encode( $data );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT `data`, `sig`, `ai_status` FROM `' . Schema::table() . '` WHERE `id` = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$had  = is_array( $row );

		$old_sig = '';
		if ( $had ) {
			$old_sig = (string) $row['sig'];
			if ( '' === $old_sig ) { // رکورد قدیمی (پیش از ستون sig): امضایش از دادهٔ قبلی ساخته و ذخیره می‌شود.
				$old     = json_decode( (string) $row['data'], true );
				$old_sig = is_array( $old ) ? self::signature( $id, $old ) : '';
				$wpdb->update( Schema::table(), array( 'sig' => $old_sig ), array( 'id' => $id ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		// «قبلاً به هوش مصنوعی فرستاده شده»: در حال پردازش (۱) یا پردازش‌شده (۲)؛ ویرایش هر دو باید دوباره ارسال شود.
		$was_trained = $had && (int) $row['ai_status'] >= 1;

		// فقط اطلاعات زنده/ناپایدار (قیمت، موجودی، تاریخ ویرایش، دیدگاه...) عوض شده: هیچ چیز در دیتابیس نوشته نمی‌شود.
		$old_data = $had ? json_decode( (string) $row['data'], true ) : null;
		if ( $had && is_array( $old_data ) && self::stable_hash( $id, $old_data ) === self::stable_hash( $id, $data ) ) {
			return array(
				'ok'          => true,
				'created'     => false,
				'changed'     => false,
				'ai_changed'  => false,
				'was_trained' => $was_trained,
			);
		}

		$is_product = 0 === strpos( $id, 'product:' );
		$ok         = self::save( $id, $data, $seen_on_insert );

		return array(
			'ok'          => $ok,
			'created'     => $ok && ! $had,
			'changed'     => $ok && ( ! $had || (string) $row['data'] !== $json ),
			// محصول: عنوان/توضیحات؛ بقیه: هر تغییر در اطلاعات ثابت (چون به اینجا رسیده، تغییر ثابت قطعی است).
			'ai_changed'  => $ok && ( ! $had || ! $is_product || $old_sig !== self::signature( $id, $data ) ),
			'was_trained' => $was_trained,
		);
	}

	/**
	 * رکورد را از جدول برمی‌دارد (هنگام حذف نوشته از سایت؛ RemovedStore).
	 */
	public static function delete( string $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::table(), array( 'id' => $id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * هر آیتم یک رکورد. شناسهٔ رکوردها به‌ترتیب آیتم‌ها برمی‌گردد (برای آیتم ناموفق رشتهٔ خالی).
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array{ids:string[], failed:int}
	 */
	public static function save_items( string $category, array $items ): array {
		$ids    = array();
		$failed = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
				$ids[] = '';
				++$failed;
				continue;
			}
			$id = self::record_id( $category, $item['id'] );
			if ( self::save( $id, $item ) ) {
				$ids[] = $id;
			} else {
				$ids[] = '';
				++$failed;
			}
		}

		return array(
			'ids'    => $ids,
			'failed' => $failed,
		);
	}

	/**
	 * دسته‌بندی‌ها و طبقه‌بندی‌های یک منبع = یک رکورد. شناسه یا رشتهٔ خالی (ناموفق) برمی‌گردد.
	 *
	 * @param array<int, array<string, mixed>> $taxonomies
	 */
	public static function save_taxonomies( string $category, array $taxonomies ): string {
		$id = self::record_id( $category . '_taxonomies', 'all' );

		return self::save(
			$id,
			array(
				'category'   => $category,
				'taxonomies' => $taxonomies,
			)
		) ? $id : '';
	}

	/**
	 * اطلاعات سایت و محیط = یک رکورد.
	 *
	 * @param array<string, mixed> $info
	 */
	public static function save_site( array $info ): string {
		$id = self::record_id( 'site', get_current_blog_id() );

		return self::save( $id, $info ) ? $id : '';
	}

	/**
	 * کدام‌یک از این شناسه‌ها از قبل در جدول ثبت شده‌اند.
	 *
	 * @param string[] $ids
	 * @return string[]
	 */
	public static function existing_ids( array $ids ): array {
		return self::ids_where( $ids, '' );
	}

	/**
	 * کدام‌یک از این شناسه‌ها پردازش‌شده‌اند (ai_status = 2؛ آیکن هوش مصنوعی).
	 *
	 * @param string[] $ids
	 * @return string[]
	 */
	public static function trained_ids( array $ids ): array {
		return self::ids_where( $ids, ' AND `ai_status` = 2' );
	}

	/**
	 * کدام‌یک از این شناسه‌ها «در حال پردازش سمت سرور» هستند (ai_status = 1؛ آیکن loading).
	 *
	 * @param string[] $ids
	 * @return string[]
	 */
	public static function processing_ids( array $ids ): array {
		return self::ids_where( $ids, ' AND `ai_status` = 1' );
	}

	/**
	 * @param string[] $ids
	 * @return string[]
	 */
	private static function ids_where( array $ids, string $extra ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$found = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql     = $wpdb->prepare( 'SELECT `id` FROM `' . Schema::table() . '` WHERE `id` IN (' . $holders . ')' . $extra, $chunk ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows    = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$found   = array_merge( $found, is_array( $rows ) ? $rows : array() );
		}

		return $found;
	}

	/**
	 * تعداد رکوردها و رکوردهای آموزش‌دیده به تفکیک دسته (product، blog، page، site، ...).
	 *
	 * @return array<string, array{total:int, trained:int}>
	 */
	public static function counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'SELECT SUBSTRING_INDEX(`id`, \':\', 1) AS cat, COUNT(*) AS total, SUM(`ai_status` = 2) AS trained FROM `' . Schema::table() . '` GROUP BY cat',
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['cat'] ] = array(
				'total'   => (int) $row['total'],
				'trained' => (int) $row['trained'],
			);
		}

		return $out;
	}

	/**
	 * یک صفحه از آیتم‌های ذخیره‌شدهٔ یک دسته.
	 *
	 * @return array{items:array<int,array<string,mixed>>, ids:string[], trained:string[], processing:string[], total:int}
	 */
	public static function page( string $category, int $offset, int $limit ): array {
		global $wpdb;

		$like  = $wpdb->esc_like( $category . ':' ) . '%';
		$table = Schema::table();

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE `id` LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT `id`, `data`, `ai_status` FROM `' . $table . '` WHERE `id` LIKE %s ORDER BY CAST(SUBSTRING_INDEX(`id`, \':\', -1) AS UNSIGNED) ASC, `id` ASC LIMIT %d OFFSET %d', $like, $limit, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array(
			'items'   => array(),
			'ids'     => array(),
			'trained'    => array(),
			'processing' => array(),
			'total'      => $total,
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$data = json_decode( (string) $row['data'], true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$out['items'][] = $data;
			$out['ids'][]   = (string) $row['id'];
			if ( 2 === (int) $row['ai_status'] ) {
				$out['trained'][] = (string) $row['id'];
			} elseif ( 1 === (int) $row['ai_status'] ) {
				$out['processing'][] = (string) $row['id'];
			}
		}

		return $out;
	}

	/**
	 * یک رکورد مشخص؛ null اگر نباشد.
	 *
	 * @return array{data:array<string,mixed>, trained:bool, processing:bool}|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT `data`, `ai_status` FROM `' . Schema::table() . '` WHERE `id` = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}
		$data = json_decode( (string) $row['data'], true );

		return is_array( $data ) ? array(
			'data'       => $data,
			'trained'    => 2 === (int) $row['ai_status'],
			'processing' => 1 === (int) $row['ai_status'],
		) : null;
	}

	/**
	 * تعداد رکوردهایی که به‌صورت خودکار ثبت شده‌اند و مدیر هنوز «همه را دیدم» را نزده است (seen = 0).
	 */
	public static function unseen_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Schema::table() . '` WHERE `seen` = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * رکوردهای دیده‌نشده، تازه‌ترین شناسه اول. برای سبک ماندن، فقط ابتدای JSON خوانده می‌شود
	 * (کلیدهای id، type، title، url همیشه اولِ هر آیتم هستند)، نه کل متن محتوا.
	 *
	 * @return array<int, array{id:string, trained:bool, processing:bool, head:string}>
	 */
	public static function unseen_list( int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT `id`, `ai_status`, LEFT(`data`, 1500) AS head FROM `' . Schema::table() . '` WHERE `seen` = 0 ORDER BY CAST(SUBSTRING_INDEX(`id`, \':\', -1) AS UNSIGNED) DESC, `id` DESC LIMIT %d', max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'         => (string) $row['id'],
				'trained'    => 2 === (int) $row['ai_status'],
				'processing' => 1 === (int) $row['ai_status'],
				'head'       => (string) $row['head'],
			);
		}

		return $out;
	}

	/**
	 * فقط همین شناسه‌ها «دیده‌شده» می‌شوند (نه کل جدول)، تا موردی که بین نمایش لیست و زدن دکمه ثبت شده
	 * و مدیر ندیده، اشتباهاً دیده‌شده نشود.
	 *
	 * @param string[] $ids
	 * @return int تعداد رکوردهای تغییریافته
	 */
	public static function mark_seen( array $ids ): int {
		global $wpdb;

		$ids   = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
		$total = 0;
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql     = $wpdb->prepare( 'UPDATE `' . Schema::table() . '` SET `seen` = 1 WHERE `seen` = 0 AND `id` IN (' . $holders . ')', $chunk ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$done    = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$total  += is_int( $done ) ? $done : 0;
		}

		return $total;
	}
}
