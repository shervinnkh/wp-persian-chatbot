<?php
namespace PersianWPChatbot\Support;

defined( 'ABSPATH' ) || exit;

/**
 * کلید اصلی روشن/خاموش بودن کل بخش چت‌بات (از ۰.۵.۱۲).
 *
 * وقتی خاموش است هیچ بخشی از چت‌بات روی سایت و پنل کار نمی‌کند: ویجت نمایش داده نمی‌شود،
 * مسیرهای REST چت ثبت نمی‌شوند، هیچ رکوردی ساخته نمی‌شود (ذخیرهٔ خودکار، پیام، کرون) و
 * تب‌های پنل به‌جز «عمومی» قفل هستند. ذخیره در option مستقل `pwc_chatbot_status`
 * (autoload=yes چون در هر درخواست سایت یک‌بار خوانده می‌شود) تا به بقیهٔ تنظیمات وابسته نباشد.
 */
final class Status {

	public const OPTION = 'pwc_chatbot_status';

	/** @var bool|null کش درون‌درخواستی. */
	private static $cache = null;

	/**
	 * پیش‌فرض: فعال.
	 */
	public static function is_enabled(): bool {
		if ( null === self::$cache ) {
			self::$cache = '0' !== (string) get_option( self::OPTION, '1' );
		}

		return self::$cache;
	}

	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION, $enabled ? '1' : '0', true );
		self::$cache = $enabled;
	}
}
