<?php
namespace PersianAIAssistant\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * پیدا کردن اطلاعات تماس در متن و پیوندها: تلفن (ایران و بین‌المللی)، ایمیل، نشانی، ساعت کاری، شبکه‌های اجتماعی و نقشه.
 */
final class ContactFinder {

	private const SOCIAL = array(
		'instagram.com' => 'instagram',
		't.me'          => 'telegram',
		'telegram.me'   => 'telegram',
		'telegram.org'  => 'telegram',
		'wa.me'         => 'whatsapp',
		'whatsapp.com'  => 'whatsapp',
		'linkedin.com'  => 'linkedin',
		'twitter.com'   => 'twitter',
		'x.com'         => 'twitter',
		'facebook.com'  => 'facebook',
		'fb.com'        => 'facebook',
		'youtube.com'   => 'youtube',
		'youtu.be'      => 'youtube',
		'aparat.com'    => 'aparat',
		'eitaa.com'     => 'eitaa',
		'bale.ai'       => 'bale',
		'ble.ir'        => 'bale',
		'rubika.ir'     => 'rubika',
		'igap.net'      => 'igap',
		'pinterest.com' => 'pinterest',
		'github.com'    => 'github',
		'tiktok.com'    => 'tiktok',
	);

	private const MAP_HOSTS = array( 'google.com/maps', 'maps.google', 'goo.gl/maps', 'maps.app.goo.gl', 'neshan.org', 'nshn.ir', 'balad.ir', 'openstreetmap.org' );

	/**
	 * @return array<int, array{value:string, raw:string, type:string}>
	 */
	public static function phones( string $text ): array {
		$text = Text::digits( $text );
		$out  = array();

		if ( false === preg_match_all( '/(?<![\w.])\+?\d[\d\s().\-]{6,18}\d(?![\w])/u', $text, $m ) ) {
			return $out;
		}

		foreach ( $m[0] as $raw ) {
			$plus   = 0 === strpos( ltrim( $raw ), '+' );
			$digits = (string) preg_replace( '/\D+/', '', $raw );
			$type   = '';
			$value  = '';

			if ( 1 === preg_match( '/^(?:98|0098)?0?(9\d{9})$/', $digits, $x ) && ( $plus || '0' === $digits[0] || 0 === strpos( $digits, '98' ) ) ) {
				$type  = 'mobile';
				$value = '0' . $x[1];
			} elseif ( 1 === preg_match( '/^(?:98|0098)?0?([1-8]\d{9})$/', $digits, $x ) && ( $plus || '0' === $digits[0] || 0 === strpos( $digits, '98' ) ) ) {
				$type  = 'landline';
				$value = '0' . $x[1];
			} elseif ( $plus && strlen( $digits ) >= 10 && strlen( $digits ) <= 15 ) {
				$type  = 'international';
				$value = '+' . $digits;
			}

			if ( '' !== $type && ! isset( $out[ $value ] ) ) {
				$out[ $value ] = array(
					'value' => $value,
					'raw'   => Text::collapse( $raw ),
					'type'  => $type,
				);
			}
		}

		return array_values( $out );
	}

	/**
	 * @return string[]
	 */
	public static function emails( string $text ): array {
		if ( false === preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', Text::digits( $text ), $m ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
	}

	/**
	 * @param array<int, string> $urls
	 * @return array<int, array{network:string, url:string}>
	 */
	public static function socials( array $urls ): array {
		$out = array();
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url || 1 === preg_match( '#share|intent/|sharer|[?&]text=|/send\?#i', $url ) ) {
				continue;
			}
			$host = strtolower( (string) preg_replace( '/^www\./i', '', (string) parse_url( $url, PHP_URL_HOST ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			foreach ( self::SOCIAL as $domain => $network ) {
				if ( $host === $domain || ( strlen( $host ) > strlen( $domain ) && substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain ) ) {
					$path = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
					if ( '' !== $path || 'wa.me' === $domain ) {
						$out[ $url ] = array(
							'network' => $network,
							'url'     => $url,
						);
					}
					break;
				}
			}
		}

		return array_values( $out );
	}

	/**
	 * @param array<int, string> $urls
	 * @return string[]
	 */
	public static function maps( array $urls ): array {
		$out = array();
		foreach ( $urls as $url ) {
			foreach ( self::MAP_HOSTS as $needle ) {
				if ( false !== stripos( (string) $url, $needle ) ) {
					$out[ $url ] = $url;
					break;
				}
			}
		}

		return array_values( $out );
	}

	/**
	 * @return string[]
	 */
	public static function addresses( string $text ): array {
		return self::labelled( $text, '(?:آدرس|نشانی|آدرس\s*دفتر|address)' );
	}

	/**
	 * @return string[]
	 */
	public static function hours( string $text ): array {
		return self::labelled( $text, '(?:ساعات?(?:\s*های)?\s*(?:کاری|کار|پاسخگویی|فعالیت)|working\s*hours|opening\s*hours|business\s*hours)' );
	}

	/**
	 * خطی که با برچسب شروع می‌شود («آدرس: ...»)؛ اگر مقدار خالی بود، خط بعد.
	 *
	 * @return string[]
	 */
	private static function labelled( string $text, string $label ): array {
		$lines = preg_split( '/\n/u', $text ) ?: array();
		$out   = array();

		foreach ( $lines as $i => $line ) {
			if ( 1 !== preg_match( '/^[\s•\-–#*]*' . $label . '\s*[:：\-–]?\s*(.*)$/iu', trim( $line ), $m ) ) {
				continue;
			}
			$value = Text::collapse( $m[1] );
			if ( '' === $value && isset( $lines[ $i + 1 ] ) ) {
				$value = Text::collapse( $lines[ $i + 1 ] );
			}
			if ( '' !== $value && Text::length( $value ) >= 4 ) {
				$out[ $value ] = Text::cut( $value, 300 );
			}
		}

		return array_values( $out );
	}
}
