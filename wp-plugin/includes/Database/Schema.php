<?php
namespace PersianAIAssistant\Database;

defined( 'ABSPATH' ) || exit;

/**
 * جدول‌های اطلاعات افزونه:
 *  - {prefix}paia_items  اطلاعات استخراج‌شدهٔ سایت (توضیح ستون‌ها در ادامه)
 *  - {prefix}paia_texts  متن‌های دستی تب «اسناد اضافه» (از نسخهٔ ساختار ۳) — سازوکار مثل paia_items:
 *                        هر باکس متن یک رکورد؛ ستون‌های اضافه: draft، chunks، words، created_at، updated_at
 *  - {prefix}paia_files  فایل‌های آپلودشدهٔ تب «اسناد اضافه» (از نسخهٔ ساختار ۴): هر فایل یک رکورد
 *                        (id مثل file:1700000000000، عنوان، نام اصلی، مسیر نسبی داخل uploads/paia-docs، پسوند، حجم، ai_status)
 *  - {prefix}paia_logs   لاگ سرور هوش مصنوعی (از نسخهٔ ساختار ۴): status = success | error | info،
 *                        message = متن لاگ، data = JSON جزئیات (متد، آدرس، کد وضعیت، …)
 *  - {prefix}paia_live_products / {prefix}paia_live_data  تب «اطلاعات به‌روز» (از نسخهٔ ساختار ۵) — ستون‌های جدا، بدون JSON؛ توضیح در LiveStore
 *  - {prefix}paia_live_blog / {prefix}paia_live_pages    اطلاعات به‌روز تمام وبلاگ‌ها و تمام برگه‌ها (از نسخهٔ ساختار ۶) — مثل paia_live_products
 *  - {prefix}paia_removed  نوشته‌هایی که از سایت حذف شده‌اند و هنوز مدیر دربارهٔ حذفشان از هوش مصنوعی تصمیم نگرفته (از نسخهٔ ساختار ۶) — توضیح در RemovedStore
 *
 * جدول اطلاعات استخراج‌شده: {prefix}paia_items
 *
 * ستون‌ها:
 *  - id        شناسهٔ رکورد به شکل «دسته:شناسه»، مثل product:23 ، blog:45 ، page:7 ، site:1 ، product_taxonomies:all
 *  - data      داده به‌صورت JSON
 *  - ai_status 0 = هنوز به سرور هوش مصنوعی داده نشده، 1 = برای سرور فرستاده شد و سرور ۲۰۰ داد (در حال پردازش سمت سرور)،
 *              2 = سرور پردازش را تمام و به افزونه خبر داد (آموزش‌دیده؛ از نسخهٔ ۰.۵.۱۷ — قبلاً ۱ این معنی را داشت). Records::STATUS_*
 *  - seen      1 = دیده‌شده (پیش‌فرض؛ استخراج دستی و رکوردهای قدیمی)، 0 = به‌صورت خودکار هنگام انتشار ثبت شده
 *              و مدیر سایت هنوز «همه را دیدم» را نزده است (از نسخهٔ ساختار ۲)
 *  - sig       امضای MD5 «آنچه برای هوش مصنوعی مهم است» (از نسخهٔ ساختار ۶): برای محصول فقط عنوان + توضیحات، برای بقیه کل داده.
 *              فقط وقتی sig عوض شود ai_status به ۰ برمی‌گردد و رکورد دوباره به هوش مصنوعی فرستاده می‌شود (ItemStore::signature)
 */
final class Schema {

	public const DB_VERSION = '6';
	public const OPTION     = 'paia_db_version';

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_items';
	}

	public static function texts_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_texts';
	}

	public static function files_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_files';
	}

	public static function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_logs';
	}

	public static function live_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_live_data';
	}

	public static function live_products_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_live_products';
	}

	public static function live_blog_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_live_blog';
	}

	public static function live_pages_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_live_pages';
	}

	public static function removed_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'paia_removed';
	}

	/**
	 * جدول تنظیمات اطلاعات به‌روز هر منبع (products | blog | pages).
	 */
	public static function live_source_table( string $source ): string {
		switch ( $source ) {
			case 'blog':
				return self::live_blog_table();
			case 'pages':
				return self::live_pages_table();
			default:
				return self::live_products_table();
		}
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id varchar(191) NOT NULL,
  data longtext NOT NULL,
  ai_status tinyint(1) NOT NULL DEFAULT 0,
  seen tinyint(1) NOT NULL DEFAULT 1,
  sig char(32) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY seen (seen)
) {$charset};"
		);

		// متن‌های دستی «اسناد اضافه»: هر باکس متن یک رکورد (data = JSON شامل chunks).
		$texts = self::texts_table();
		dbDelta(
			"CREATE TABLE {$texts} (
  id varchar(191) NOT NULL,
  data longtext NOT NULL,
  ai_status tinyint(1) NOT NULL DEFAULT 0,
  draft tinyint(1) NOT NULL DEFAULT 1,
  chunks int(10) unsigned NOT NULL DEFAULT 0,
  words int(10) unsigned NOT NULL DEFAULT 0,
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY draft (draft)
) {$charset};"
		);

		// فایل‌های آپلودشدهٔ «اسناد اضافه»: خود فایل در uploads/paia-docs است و اینجا فقط رکوردش (مسیر نسبی) نگه‌داری می‌شود.
		$files = self::files_table();
		dbDelta(
			"CREATE TABLE {$files} (
  id varchar(191) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  original_name varchar(255) NOT NULL DEFAULT '',
  path varchar(255) NOT NULL DEFAULT '',
  ext varchar(10) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  ai_status tinyint(1) NOT NULL DEFAULT 0,
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$charset};"
		);

		// لاگ سرور: بعداً از سرور هوش مصنوعی پر می‌شود (LogStore::add).
		$logs = self::logs_table();
		dbDelta(
			"CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(10) NOT NULL DEFAULT 'info',
  message longtext NOT NULL,
  data longtext NOT NULL,
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY created_at (created_at)
) {$charset};"
		);

		// اطلاعات به‌روز: تنظیمات محصولات (یک ردیف ثابت) و اطلاعات تعریف‌شده توسط مدیر.
		$live_products = self::live_products_table();
		dbDelta(
			"CREATE TABLE {$live_products} (
  id tinyint(3) unsigned NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  price tinyint(1) NOT NULL DEFAULT 1,
  regular_price tinyint(1) NOT NULL DEFAULT 1,
  on_sale tinyint(1) NOT NULL DEFAULT 1,
  stock_status tinyint(1) NOT NULL DEFAULT 1,
  stock_qty tinyint(1) NOT NULL DEFAULT 1,
  backorder tinyint(1) NOT NULL DEFAULT 1,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) {$charset};"
		);

		// اطلاعات به‌روز تمام وبلاگ‌ها و تمام برگه‌ها (هر کدام یک ردیف ثابت، مثل محصولات).
		$live_blog = self::live_blog_table();
		dbDelta(
			"CREATE TABLE {$live_blog} (
  id tinyint(3) unsigned NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  comment_count tinyint(1) NOT NULL DEFAULT 1,
  modified tinyint(1) NOT NULL DEFAULT 1,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) {$charset};"
		);

		$live_pages = self::live_pages_table();
		dbDelta(
			"CREATE TABLE {$live_pages} (
  id tinyint(3) unsigned NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  modified tinyint(1) NOT NULL DEFAULT 1,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) {$charset};"
		);

		// نوشته‌های حذف‌شده از سایت که منتظر تصمیم مدیر برای حذف از هوش مصنوعی‌اند.
		$removed = self::removed_table();
		dbDelta(
			"CREATE TABLE {$removed} (
  id varchar(191) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  ai_status tinyint(1) NOT NULL DEFAULT 0,
  removed_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY removed_at (removed_at)
) {$charset};"
		);

		$live = self::live_table();
		dbDelta(
			"CREATE TABLE {$live} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL DEFAULT '',
  description text NOT NULL,
  content longtext NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY enabled (enabled)
) {$charset};"
		);
		LiveStore::ensure_rows();

		update_option( self::OPTION, self::DB_VERSION, true );

		// دو لاگ آزمایشی (فقط یک بار) تا تب «لاگ سرور» پیش از اتصال به سرور قابل دیدن باشد.
		LogStore::seed_test();
	}

	/**
	 * اگر جدول ساخته نشده یا نسخهٔ ساختارش قدیمی است، می‌سازد/به‌روز می‌کند.
	 */
	public static function maybe_install(): void {
		if ( (string) get_option( self::OPTION, '' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function uninstall(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::texts_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::files_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::logs_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::live_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::live_products_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::live_blog_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::live_pages_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::removed_table() . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::OPTION );
		delete_option( LogStore::SEEDED_OPTION );
	}
}
