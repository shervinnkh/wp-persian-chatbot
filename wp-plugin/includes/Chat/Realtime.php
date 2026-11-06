<?php
/**
 * پیام‌رسانی لحظه‌ای (WebSocket «فقط اعلان») — سمت وردپرس.
 *
 * وردپرس منبع حقیقت است و WebSocket فقط می‌گوید «چیزی عوض شد، یک‌بار poll کن»:
 *  - توکن کوتاه‌عمر امضاشده برای اتصال ویجت به سرور هوش مصنوعی (issue)؛ راز مشترک هرگز به مرورگر نمی‌رسد.
 *  - وب‌هوک امضادار و غیرمسدودکننده به سرور (push) وقتی پیام پشتیبان انسانی ذخیره شد / پشتیبان تایپ می‌کند.
 *    (پاسخ هوش مصنوعی را خود سرور بعد از موفقیت callback اعلان می‌کند.)
 *
 * قرارداد توکن (باید با app/services/realtime/tokens.py یکی باشد):
 *   token   = base64url(payload_json) . '.' . base64url(HMAC_SHA256(ws_key, base64url(payload_json)))
 *   ws_key  = HMAC_SHA256(کلید = رازِ مشترک، پیام = "pwc-ws-v1")  (۳۲ بایت خام؛ کلید جدا از کلید امضای درخواست‌ها)
 *   payload = {"v": visitor_uid, "aud": "host[:port] سایت", "iat": …, "exp": iat+600, "jti": "۳۲ نویسهٔ hex"}
 *
 * @package PersianWPChatbot
 */

namespace PersianWPChatbot\Chat;

use PersianWPChatbot\Apis\Client;
use PersianWPChatbot\Database\LogStore;

defined( 'ABSPATH' ) || exit;

final class Realtime {

	/** برچسب مشتق‌سازی کلید توکن. */
	public const KEY_LABEL = 'pwc-ws-v1';

	/** عمر توکن (ثانیه)؛ سرور هم سقف می‌گذارد. */
	public const TOKEN_TTL = 600;

	/**
	 * آیا realtime روشن است؟ (kill switch وردپرس + اتصال به سرور تنظیم شده باشد)
	 */
	public static function enabled(): bool {
		return ChatSettings::realtime_enabled() && Client::is_configured();
	}

	/**
	 * آدرس WebSocket: تنظیم دستی (ws_url) وگرنه wss://<آدرس سرور هوش مصنوعی>/v1/ws.
	 */
	public static function ws_url(): string {
		$custom = trim( (string) ChatSettings::get( 'ws_url', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$base = Client::base_url();
		if ( '' === $base ) {
			return '';
		}

		return (string) preg_replace( '#^http#i', 'ws', $base ) . '/v1/ws'; // https → wss ، http → ws (فقط توسعه)
	}

	/** host[:port] سایت؛ سرور آن را با Origin و با سایتِ قفل‌شدهٔ کلید مقایسه می‌کند. */
	public static function site_key(): string {
		$parts = wp_parse_url( home_url() );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port  = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$https = isset( $parts['scheme'] ) && 'https' === strtolower( (string) $parts['scheme'] );
		if ( $port && ! ( $https && 443 === $port ) && ! ( ! $https && 80 === $port ) ) {
			$host .= ':' . $port;
		}

		return $host;
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/** کلید مشتق‌شدهٔ توکن (خام). */
	public static function key(): string {
		return hash_hmac( 'sha256', self::KEY_LABEL, Client::secret(), true );
	}

	/**
	 * توکن اتصال برای یک بازدیدکنندهٔ موجود.
	 *
	 * @return array{token:string,expires_in:int}
	 */
	public static function issue( string $uid, ?int $now = null ): array {
		$now     = null === $now ? time() : $now;
		$payload = self::b64(
			(string) wp_json_encode(
				array(
					'v'   => $uid,
					'aud' => self::site_key(),
					'iat' => $now,
					'exp' => $now + self::TOKEN_TTL,
					'jti' => bin2hex( random_bytes( 16 ) ),
				),
				JSON_UNESCAPED_SLASHES
			)
		);
		$sig     = self::b64( hash_hmac( 'sha256', $payload, self::key(), true ) );

		return array(
			'token'      => $payload . '.' . $sig,
			'expires_in' => self::TOKEN_TTL,
		);
	}

	/**
	 * اعلان به سرور: ویجت‌های این بازدیدکننده را خبر کن. غیرمسدودکننده و بی‌خطر (شکست فقط لاگ می‌شود؛ بدترین حالت: کاربر با polling پیام را می‌بیند).
	 *
	 * @param string $event new_message (= «گفتگو تغییر کرد؛ poll کن») | typing
	 * @param array<string,mixed> $extra مثلاً array('on' => true) برای typing.
	 */
	public static function push( string $uid, string $event = 'new_message', array $extra = array() ): void {
		if ( '' === $uid || ! self::enabled() || ! in_array( $event, array( 'new_message', 'typing' ), true ) ) {
			return;
		}
		// تایپ پشتیبان را حداکثر هر ۲ ثانیه یک‌بار اعلان می‌کنیم (روشن‌شدن)؛ خاموش‌شدن همیشه می‌رود.
		if ( 'typing' === $event && ! empty( $extra['on'] ) && ! RateLimit::lock( 'pwc_ptyp_' . md5( $uid ), 2 ) ) {
			return;
		}
		$payload = array(
			'visitor_id' => $uid,
			'event'      => $event,
		);
		if ( 'typing' === $event ) {
			$payload['on'] = ! empty( $extra['on'] );
		}

		$res = Client::request( 'POST', '/v1/push', $payload, 2, 1, false );
		if ( ! $res['ok'] && 'network_error' === $res['error_code'] ) {
			// بدنه/توکن لاگ نمی‌شود؛ فقط نوع خطا. (با blocking=false تقریباً هرگز خطا نمی‌دهد.)
			LogStore::add(
				LogStore::ERROR,
				__( 'اعلان لحظه‌ای به سرور هوش مصنوعی ارسال نشد (پیام ذخیره شده و با polling می‌رسد).', 'persian-wp-chatbot' ),
				array(
					'endpoint' => '/v1/push',
					'error'    => (string) $res['error_code'],
				)
			);
		}
	}
}
