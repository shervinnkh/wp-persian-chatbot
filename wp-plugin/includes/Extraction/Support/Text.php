<?php
namespace PersianWPChatbot\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * ابزارهای متنی کوچک و بدون وابستگی (ارقام فارسی، فاصله‌ها، شمارش کلمه، برش).
 */
final class Text {

	/**
	 * ارقام فارسی و عربی را به لاتین تبدیل می‌کند.
	 */
	public static function digits( string $text ): string {
		return strtr(
			$text,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/**
	 * یک‌خطی‌سازی: همهٔ فاصله‌ها (و خط جدید) به یک فاصله تبدیل می‌شود. نیم‌فاصله حفظ می‌شود.
	 */
	public static function collapse( string $text ): string {
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF" ), array( ' ', '', '' ), $text );
		$out  = preg_replace( '/\s+/u', ' ', $text );

		return trim( null === $out ? $text : $out );
	}

	/**
	 * پاک‌سازی متن چندخطی: فاصله‌های اضافه و خط‌های خالی پشت‌سرهم حذف می‌شود.
	 */
	public static function lines_clean( string $text ): string {
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF" ), array( "\n", "\n", ' ', '', '' ), $text );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/ ?\n ?/u', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	public static function words( string $text ): int {
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}
		$parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $parts ) ? count( $parts ) : 0;
	}

	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	public static function cut( string $text, int $max ): string {
		if ( self::length( $text ) <= $max ) {
			return $text;
		}

		return ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max ) ) . '…';
	}

	public static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
