<?php
namespace PersianAIAssistant\Apis\Live;

defined( 'ABSPATH' ) || exit;

/**
 * ابزارهای متنی مشترک دو API زندهٔ «ابزارهای هوش مصنوعی» (apis/Live).
 */
final class Text {

	/** ارقام فارسی/عربی → لاتین (قرارداد: «ارقام را لاتین بنویسید»). */
	public static function latin_digits( string $text ): string {
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
	 * نرمال‌سازی برای مقایسه/جست‌وجوی نام: ي→ی ، ك→ک ، ارقام لاتین ، نیم‌فاصله و فاصلهٔ اضافه → یک فاصله ، حروف کوچک.
	 */
	public static function normalize( string $text ): string {
		$text = strtr( $text, array( 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ە' => 'ه' ) );
		$text = self::latin_digits( $text );
		$text = str_replace( array( "\u{200C}", "\u{200F}", "\u{200E}", "\u{00A0}" ), ' ', $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * همان نام با حروف عربی (ی→ي ، ک→ك): بعضی سایت‌ها عنوان محصول را قدیمی با حروف عربی ذخیره کرده‌اند.
	 */
	public static function arabic_variant( string $text ): string {
		return strtr( $text, array( 'ی' => 'ي', 'ک' => 'ك' ) );
	}

	/** متن ساده و تک‌خطی (بدون HTML و نهادهٔ HTML). */
	public static function plain( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function cut( string $text, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max ? mb_substr( $text, 0, $max, 'UTF-8' ) : $text;
		}

		return strlen( $text ) > $max ? substr( $text, 0, $max ) : $text;
	}

	/**
	 * برچسب واحد پول برای AI (AI مقدار را عیناً به کاربر می‌گوید، پس واحد باید صریح باشد).
	 * کدهای ایرانی ووکامرس: IRT تومان ، IRR ریال ، IRHT هزار تومان ، IRHR هزار ریال.
	 */
	public static function currency_label( string $code ): string {
		$code = strtoupper( trim( $code ) );
		$map  = array(
			'IRT'  => 'تومان',
			'IRR'  => 'ریال',
			'IRHT' => 'هزار تومان',
			'IRHR' => 'هزار ریال',
			'USD'  => 'دلار آمریکا',
			'EUR'  => 'یورو',
			'GBP'  => 'پوند',
			'AED'  => 'درهم امارات',
			'TRY'  => 'لیر ترکیه',
		);

		return $map[ $code ] ?? $code;
	}

	/**
	 * عدد قیمت بدون جداکننده هزارگان و بدون صفرهای اعشاریِ اضافه ("1250000" ، "12.5").
	 *
	 * @param mixed $value عدد یا رشتهٔ عددی.
	 */
	public static function number( $value, int $decimals = 0 ): string {
		$value = (float) $value;
		$out   = number_format( $value, max( 0, $decimals ), '.', '' );
		if ( false !== strpos( $out, '.' ) ) {
			$out = rtrim( rtrim( $out, '0' ), '.' );
		}

		return '' === $out || '-0' === $out ? '0' : $out;
	}
}
