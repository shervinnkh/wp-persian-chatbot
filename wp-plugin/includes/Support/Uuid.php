<?php
namespace PersianWPChatbot\Support;

defined( 'ABSPATH' ) || exit;

/**
 * تولید UUID نسخهٔ ۴ با مولد تصادفی رمزنگاری‌شده (SEC-02).
 *
 * شناسهٔ بازدیدکننده (هدر X-PWC-Visitor) نقش credential دارد؛ بنابراین فقط random_bytes() استفاده می‌شود.
 * هیچ fallback ضعیفی (mt_rand / uniqid / wp_rand) وجود ندارد: اگر منبع تصادفی در دسترس نباشد استثنا پرتاب می‌شود
 * و فراخوان باید خطا را امن مدیریت کند.
 */
final class Uuid {

	/** الگوی UUID (همان الگوی اعتبارسنجی ChatController/AdminChatController). */
	public const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * @return string UUID v4 به‌صورت ۳۶ نویسهٔ حروف کوچک.
	 * @throws \Exception اگر منبع تصادفی رمزنگاری‌شده در دسترس نباشد.
	 */
	public static function v4(): string {
		return self::from_bytes( random_bytes( 16 ) );
	}

	/**
	 * ۱۶ بایت را به UUID v4 تبدیل می‌کند (بیت‌های version و variant تنظیم می‌شوند). جدا شده تا قابل تست باشد.
	 */
	public static function from_bytes( string $bytes ): string {
		if ( 16 !== strlen( $bytes ) ) {
			throw new \InvalidArgumentException( 'UUID needs exactly 16 bytes.' );
		}
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 ); // version 4
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 ); // variant RFC 4122
		$hex      = bin2hex( $bytes );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}
}
