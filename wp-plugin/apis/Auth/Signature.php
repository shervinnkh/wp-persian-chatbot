<?php
namespace PersianAIAssistant\Apis\Auth;

use PersianAIAssistant\Apis\Client;
use PersianAIAssistant\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * احراز هویت درخواست‌های ورودی از سرور هوش مصنوعی (امضای HMAC، مثل وب‌هوک‌های Stripe/GitHub).
 *
 * سرور باید این سه هدر را بفرستد:
 *   X-PAIA-Timestamp : زمان یونیکس (ثانیه، UTC)
 *   X-PAIA-Signature : sha256=<hex>  که hex = HMAC_SHA256( کلید , "{timestamp}.{بدنهٔ خام} )
 *   X-PAIA-Delivery  : شناسهٔ یکتای هر ارسال (برای جلوگیری از پردازش تکراریِ تلاش‌های مجدد؛ اختیاری ولی توصیه‌شده)
 *
 * «کلید» همان api_key سایت در تنظیمات افزونه است (سرور آن را برای هر سایت می‌شناسد). کلید هرگز روی شبکه نمی‌رود.
 * زمان‌سنج جلوی حملهٔ تکرار (replay) را می‌گیرد: درخواست قدیمی‌تر/جلوتر از ۵ دقیقه رد می‌شود.
 */
final class Signature {

	public const HEADER_TIMESTAMP = 'X-PAIA-Timestamp';
	public const HEADER_SIGNATURE = 'X-PAIA-Signature';
	public const HEADER_DELIVERY  = 'X-PAIA-Delivery';

	/** حداکثر اختلاف مجاز ساعت سرور و سایت (ثانیه). */
	public const TOLERANCE = 300;

	private const FAIL_THROTTLE = 'paia_api_authfail';

	/**
	 * کلید امضا؛ پیش‌فرض api_key تنظیمات. فیلتر paia_api_secret برای جداکردن کلید امضا از کلید دسترسی.
	 */
	public static function secret(): string {
		return (string) apply_filters( 'paia_api_secret', Client::shared_secret() );
	}

	public static function sign( string $timestamp, string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function verify( \WP_REST_Request $request ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new \WP_Error(
				'paia_not_configured',
				__( 'کلید اتصال (api_key) در افزونه تنظیم نشده است.', 'persian-ai-assistant' ),
				array( 'status' => 503 )
			);
		}

		$timestamp = trim( (string) $request->get_header( self::HEADER_TIMESTAMP ) );
		$signature = strtolower( trim( (string) $request->get_header( self::HEADER_SIGNATURE ) ) );

		if ( '' === $timestamp || '' === $signature || ! ctype_digit( $timestamp ) ) {
			return self::reject( 'paia_bad_signature', __( 'هدرهای امضا ارسال نشده یا نامعتبرند.', 'persian-ai-assistant' ) );
		}

		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE ) {
			return self::reject(
				'paia_stale_timestamp',
				__( 'زمان درخواست با ساعت سایت اختلاف زیادی دارد.', 'persian-ai-assistant' ),
				array( 'server_time' => time() )
			);
		}

		$expected = self::sign( $timestamp, (string) $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::reject( 'paia_bad_signature', __( 'امضای درخواست معتبر نیست.', 'persian-ai-assistant' ) );
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private static function reject( string $code, string $message, array $extra = array() ): \WP_Error {
		// لاگ رد شدن‌ها حداکثر دقیقه‌ای یک‌بار تا مهاجم جدول لاگ را پر نکند.
		if ( false === get_transient( self::FAIL_THROTTLE ) ) {
			set_transient( self::FAIL_THROTTLE, 1, MINUTE_IN_SECONDS );
			LogStore::add(
				LogStore::ERROR,
				__( 'درخواست ورودی از سرور هوش مصنوعی رد شد (احراز هویت ناموفق).', 'persian-ai-assistant' ),
				array(
					'method'      => 'POST',
					'endpoint'    => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'http_status' => 401,
					'ip'          => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'error'       => $code,
				)
			);
		}

		return new \WP_Error( $code, $message, array_merge( array( 'status' => 401 ), $extra ) );
	}
}
