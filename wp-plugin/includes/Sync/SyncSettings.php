<?php
namespace PersianAIAssistant\Sync;

use PersianAIAssistant\Extraction\Sources;
use PersianAIAssistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * تنظیمات «استخراج خودکار اطلاعات سایت» برای هر منبع (محصولات، وبلاگ، برگه‌ها).
 *
 * کلید auto_sync در option اصلی افزونه (paia_settings):
 *   { products: { add: bool, train: bool }, blog: {...}, pages: {...} }
 *
 *  - add   = به‌محض افزودن/انتشار (و ویرایش) در سایت، به لیست مطالب افزونه (جدول paia_items) اضافه/به‌روز شود.
 *  - train = بلافاصله برای آموزش به بک‌اند هوش مصنوعی هم فرستاده شود. بدون add معنی ندارد (همیشه false می‌شود).
 *
 * ذخیره فقط با دکمهٔ «ذخیرهٔ تغییرات» تب تنظیمات انجام می‌شود (همان فرمِ options.php)؛ تیک‌ها تکی ذخیره نمی‌شوند.
 */
final class SyncSettings {

	public const KEY = 'auto_sync';

	/** نام فیلدهای فرم تنظیمات: paia_autosync[منبع][add|train] */
	public const FORM_FIELD = 'paia_autosync';

	/** فیلد مخفی که نشان می‌دهد فرم، تیک‌های این بخش را هم فرستاده است (تا ذخیرهٔ بخش دیگر، آن‌ها را پاک نکند). */
	public const FORM_MARKER = 'paia_autosync_present';

	/**
	 * @return array<string, array{add:bool, train:bool}>
	 */
	public static function all(): array {
		$stored = Settings::get( self::KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		foreach ( Sources::ids() as $id ) {
			$row        = isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ? $stored[ $id ] : array();
			$add        = ! empty( $row['add'] );
			$out[ $id ] = array(
				'add'   => $add,
				'train' => $add && ! empty( $row['train'] ),
			);
		}

		return $out;
	}

	/**
	 * @return array{add:bool, train:bool}
	 */
	public static function get( string $source ): array {
		$all = self::all();

		return $all[ $source ] ?? array(
			'add'   => false,
			'train' => false,
		);
	}

	/**
	 * ذخیرهٔ همهٔ منبع‌ها یک‌جا از فرم تنظیمات. تیکِ نخورده در فرم ارسال نمی‌شود، پس غیبتش یعنی false.
	 *
	 * @param mixed $input مقدار $_POST['paia_autosync'] (ناسالم‌سازی‌نشده؛ فقط ارزش‌های بولی از آن خوانده می‌شود).
	 * @return array<string, array{add:bool, train:bool}> تنظیمات کامل پس از ذخیره.
	 */
	public static function save_from_form( $input ): array {
		$in  = is_array( $input ) ? $input : array();
		$all = array();

		foreach ( Sources::ids() as $id ) {
			$row        = isset( $in[ $id ] ) && is_array( $in[ $id ] ) ? $in[ $id ] : array();
			$add        = ! empty( $row['add'] );
			$all[ $id ] = array(
				'add'   => $add,
				'train' => $add && ! empty( $row['train'] ),
			);
		}

		Settings::update( array( self::KEY => $all ) );

		return $all;
	}
}
