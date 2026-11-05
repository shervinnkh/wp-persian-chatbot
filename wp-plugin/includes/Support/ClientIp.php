<?php
namespace PersianAIAssistant\Support;

defined( 'ABSPATH' ) || exit;

/**
 * IP بازدیدکننده (S-1 / SEC-04).
 *
 * - حالت عادی: فقط REMOTE_ADDR. X-Forwarded-For هرگز خوانده نمی‌شود.
 * - اگر ثابت PAIA_IP_HEADER (مثلاً 'HTTP_CF_CONNECTING_IP') تعریف شده باشد، هدر فقط وقتی خوانده می‌شود که
 *   REMOTE_ADDR (IP اتصال‌دهنده) داخل محدودهٔ یک Proxy مورد اعتماد باشد. بدون فهرست Proxy مورد اعتماد، هدر نادیده گرفته می‌شود.
 * - فهرست Proxy مورد اعتماد (به ترتیب):
 *     1) ثابت PAIA_TRUSTED_PROXIES در wp-config.php: آرایه یا رشتهٔ جداشده با کاما/فاصله از CIDR/IP؛
 *        کلیدواژهٔ 'cloudflare' فهرست پیوست‌شده (includes/Support/trusted-proxies/cloudflare.txt) را اضافه می‌کند.
 *     2) فیلتر 'paia_trusted_proxies' برای اصلاح/تأمین فهرست از منبع به‌روز.
 * - مقدار هدر با FILTER_VALIDATE_IP اعتبارسنجی می‌شود؛ هدر خالی/نامعتبر یعنی بازگشت به REMOTE_ADDR.
 */
final class ClientIp {

	/** @var array<string,list<string>> کش پارس فهرست‌ها در طول یک درخواست. */
	private static $preset_cache = array();

	public static function get(): string {
		$header = '';
		if ( defined( 'PAIA_IP_HEADER' ) && is_string( PAIA_IP_HEADER ) && preg_match( '/^[A-Z0-9_]{3,60}$/', PAIA_IP_HEADER ) ) {
			$header = PAIA_IP_HEADER;
		}

		$server = array(
			'REMOTE_ADDR' => isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		);
		if ( '' !== $header && isset( $_SERVER[ $header ] ) ) {
			$server[ $header ] = trim( (string) wp_unslash( $_SERVER[ $header ] ) );
		}

		return self::resolve( $server, $header, '' !== $header ? self::trusted_proxies() : array() );
	}

	/**
	 * منطق خالص تشخیص IP (بدون وابستگی به وردپرس؛ قابل تست).
	 *
	 * @param array<string,string> $server  مقادیر REMOTE_ADDR و (در صورت وجود) هدر.
	 * @param string               $header  نام کلید $_SERVER برای هدر IP یا ''.
	 * @param string[]             $trusted فهرست IP/CIDR پراکسی‌های مورد اعتماد.
	 */
	public static function resolve( array $server, string $header, array $trusted ): string {
		$remote = self::valid_ip( isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '' );

		// X-Forwarded-For هرگز خوانده نمی‌شود (زنجیرهٔ قابل جعل).
		if ( '' === $header || 'HTTP_X_FORWARDED_FOR' === $header || '' === $remote || empty( $trusted ) ) {
			return $remote;
		}
		if ( ! self::in_ranges( $remote, $trusted ) ) {
			return $remote; // اتصال‌دهنده Proxy مورد اعتماد نیست: هدر کاملاً نادیده گرفته می‌شود.
		}

		$candidate = self::valid_ip( isset( $server[ $header ] ) ? (string) $server[ $header ] : '' );

		return '' !== $candidate ? $candidate : $remote; // هدر خالی/نامعتبر: fallback امن.
	}

	/** @return string IP معتبر یا ''. */
	private static function valid_ip( string $raw ): string {
		$ip = filter_var( trim( $raw ), FILTER_VALIDATE_IP );

		return false === $ip ? '' : (string) $ip;
	}

	/**
	 * فهرست IP/CIDR پراکسی‌های مورد اعتماد از wp-config و فیلتر.
	 *
	 * @return string[]
	 */
	public static function trusted_proxies(): array {
		$raw = defined( 'PAIA_TRUSTED_PROXIES' ) ? PAIA_TRUSTED_PROXIES : array();
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}
		$list = array();
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$item = strtolower( trim( (string) $item ) );
			if ( 'cloudflare' === $item ) {
				$list = array_merge( $list, self::preset( 'cloudflare' ) );
			} elseif ( '' !== $item ) {
				$list[] = $item;
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'paia_trusted_proxies', $list );
			$list     = is_array( $filtered ) ? $filtered : $list;
		}

		return array_values( array_filter( array_map( 'strval', $list ), 'strlen' ) );
	}

	/** @return string[] */
	private static function preset( string $name ): array {
		if ( isset( self::$preset_cache[ $name ] ) ) {
			return self::$preset_cache[ $name ];
		}
		$file  = __DIR__ . '/trusted-proxies/' . $name . '.txt';
		$lines = is_readable( $file ) ? file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : false;
		$out   = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line && '#' !== $line[0] ) {
				$out[] = $line;
			}
		}
		self::$preset_cache[ $name ] = $out;

		return $out;
	}

	/** @param string[] $ranges */
	public static function in_ranges( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::in_cidr( $ip, (string) $range ) ) {
				return true;
			}
		}

		return false;
	}

	/** آیا IP داخل CIDR (یا برابر IP تکی) است؛ ورودی نامعتبر = false. IPv4-mapped IPv6 به IPv4 نرمال می‌شود. */
	public static function in_cidr( string $ip, string $cidr ): bool {
		$cidr = trim( $cidr );
		if ( '' === $cidr ) {
			return false;
		}
		$parts  = explode( '/', $cidr, 2 );
		$subnet = self::pack_ip( $parts[0] );
		$addr   = self::pack_ip( $ip );
		if ( null === $subnet || null === $addr || strlen( $subnet ) !== strlen( $addr ) ) {
			return false;
		}
		$max = strlen( $subnet ) * 8;
		if ( isset( $parts[1] ) ) {
			if ( ! ctype_digit( $parts[1] ) ) {
				return false;
			}
			$bits = (int) $parts[1];
			if ( $bits > $max ) {
				return false;
			}
		} else {
			$bits = $max;
		}
		$full = intdiv( $bits, 8 );
		if ( 0 !== $full && substr( $subnet, 0, $full ) !== substr( $addr, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;

		return ( ord( $subnet[ $full ] ) & $mask ) === ( ord( $addr[ $full ] ) & $mask );
	}

	/** @return string|null بایت‌های باینری IP (۴ یا ۱۶ بایت). */
	private static function pack_ip( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$bin = @inet_pton( $ip );
		if ( false === $bin ) {
			return null;
		}
		// ::ffff:a.b.c.d  =>  a.b.c.d
		if ( 16 === strlen( $bin ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $bin, 0, 12 ) ) {
			$bin = substr( $bin, 12 );
		}

		return $bin;
	}

	/** IP هش‌شده (IP خام در دیتابیس افزونه ذخیره نمی‌شود). IP نامعتبر یک سطل مشترک می‌گیرد. */
	public static function hash( string $ip = '' ): string {
		return substr( hash_hmac( 'sha256', '' !== $ip ? $ip : 'unknown', wp_salt( 'auth' ) ), 0, 32 );
	}
}
