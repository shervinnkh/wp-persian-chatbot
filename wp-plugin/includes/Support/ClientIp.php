<?php
namespace PersianAIAssistant\Support;

defined( 'ABSPATH' ) || exit;

/**
 * IP بازدیدکننده (S-1). پیش‌فرض REMOTE_ADDR؛ فقط اگر در wp-config.php ثابت PAIA_IP_HEADER (مثلاً 'HTTP_CF_CONNECTING_IP')
 * تعریف شده باشد از همان هدر می‌خواند. X-Forwarded-For هرگز خوانده نمی‌شود. خروجی با FILTER_VALIDATE_IP اعتبارسنجی می‌شود.
 */
final class ClientIp {

	public static function get(): string {
		$key = 'REMOTE_ADDR';
		if ( defined( 'PAIA_IP_HEADER' ) && is_string( PAIA_IP_HEADER ) && preg_match( '/^[A-Z0-9_]{3,60}$/', PAIA_IP_HEADER ) ) {
			$key = 'HTTP_X_FORWARDED_FOR' === PAIA_IP_HEADER ? 'REMOTE_ADDR' : PAIA_IP_HEADER;
		}
		$raw = isset( $_SERVER[ $key ] ) ? trim( (string) wp_unslash( $_SERVER[ $key ] ) ) : '';
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );

		return false === $ip ? '' : (string) $ip;
	}

	/** IP هش‌شده (IP خام در دیتابیس افزونه ذخیره نمی‌شود). IP نامعتبر یک سطل مشترک می‌گیرد. */
	public static function hash( string $ip = '' ): string {
		return substr( hash_hmac( 'sha256', '' !== $ip ? $ip : 'unknown', wp_salt( 'auth' ) ), 0, 32 );
	}
}
