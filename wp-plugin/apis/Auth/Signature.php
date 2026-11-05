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
 *   X-PAIA-Delivery  : شناسهٔ یکتای هر ارسال (نتیجه‌ها) — یا —
 *   X-PAIA-Request   : شناسهٔ یکتای هر درخواست (ابزارهای زندهٔ محصول/متن)
 *
 * «کلید» ثابت PAIA_CALLBACK_SECRET است و اگر تعریف نشده، همان کلید مشترک (PAIA_API_SECRET / api_key). کلید هرگز روی شبکه نمی‌رود.
 *
 * حفاظت replay (S-6):
 *   • زمان‌سنج: درخواست قدیمی‌تر/جلوتر از ۵ دقیقه رد می‌شود.
 *   • هر درخواست POST باید یکی از دو هدر شناسه را داشته باشد؛ نبودنش ← 401. اگر بدنهٔ امضاشده هم شناسه دارد
 *     (delivery_id / request_id)، باید با هدر یکی باشد (هدر امضا نمی‌شود، بدنه می‌شود) و همان شناسهٔ امضاشده معیار است.
 *   • شناسهٔ پردازش‌شده ۱۵ دقیقه به‌صورت transient (paia_dlv_…) ثبت می‌شود؛ تکراری ← 200 {"duplicate":true} بدون پردازش
 *     دوباره (AbstractEndpoint::dispatch برای همهٔ endpointهای POST).
 */
final class Signature {

	public const HEADER_TIMESTAMP = 'X-PAIA-Timestamp';
	public const HEADER_SIGNATURE = 'X-PAIA-Signature';
	public const HEADER_DELIVERY  = 'X-PAIA-Delivery';
	public const HEADER_REQUEST   = 'X-PAIA-Request';

	/** عمر ثبت شناسهٔ پردازش‌شده (بیشتر از ۲ × TOLERANCE تا replay داخل بازهٔ زمانی هم گرفته شود). */
	public const DELIVERY_TTL = 15 * MINUTE_IN_SECONDS;

	/** حداکثر اختلاف مجاز ساعت سرور و سایت (ثانیه). */
	public const TOLERANCE = 300;

	private const FAIL_THROTTLE = 'paia_api_authfail';

	/**
	 * کلید امضا؛ پیش‌فرض ثابت PAIA_CALLBACK_SECRET و در نبودش کلید مشترک. فیلتر paia_api_secret برای جداکردن کلید امضا از کلید دسترسی.
	 */
	public static function secret(): string {
		return (string) apply_filters( 'paia_api_secret', Client::callback_secret() );
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

		// S-6: هر POST باید شناسهٔ یکتا داشته باشد (برای دفع replay؛ GET مثل ping بی‌اثر است).
		if ( 'POST' === strtoupper( (string) $request->get_method() ) ) {
			$id = self::delivery_id( $request );
			if ( is_wp_error( $id ) ) {
				return self::reject( (string) $id->get_error_code(), $id->get_error_message() );
			}
		}

		return true;
	}

	/**
	 * شناسهٔ یکتای درخواست ورودی: ''= نیامده (فقط برای GET)، WP_Error = نبود/ناهمخوان (401).
	 * اگر بدنه شناسهٔ امضاشده دارد (delivery_id یا request_id) همان معیار است و باید با هدر یکی باشد.
	 *
	 * @return string|\WP_Error
	 */
	public static function delivery_id( \WP_REST_Request $request ) {
		$header = self::clean_id( (string) $request->get_header( self::HEADER_DELIVERY ) );
		if ( '' === $header ) {
			$header = self::clean_id( (string) $request->get_header( self::HEADER_REQUEST ) );
		}
		if ( '' === $header ) {
			if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
				return '';
			}

			return new \WP_Error( 'paia_missing_delivery', __( 'شناسهٔ درخواست (X-PAIA-Delivery یا X-PAIA-Request) ارسال نشده است.', 'persian-ai-assistant' ) );
		}

		$json = json_decode( (string) $request->get_body(), true );
		if ( is_array( $json ) ) {
			foreach ( array( 'delivery_id', 'request_id' ) as $field ) {
				if ( isset( $json[ $field ] ) && is_string( $json[ $field ] ) && '' !== $json[ $field ] ) {
					$signed = self::clean_id( $json[ $field ] );
					if ( '' !== $signed && ! hash_equals( $signed, $header ) ) {
						return new \WP_Error( 'paia_bad_delivery', __( 'شناسهٔ هدر با شناسهٔ داخل بدنه یکی نیست.', 'persian-ai-assistant' ) );
					}

					return $signed;
				}
			}
		}

		return $header;
	}

	/** شناسه فقط از نویسه‌های امن و حداکثر ۶۴ نویسه. */
	private static function clean_id( string $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_\-:.]/', '', $value ), 0, 64 );
	}

	/** آیا این شناسه قبلاً با موفقیت پردازش شده است؟ */
	public static function seen( string $id ): bool {
		return '' !== $id && false !== get_transient( 'paia_dlv_' . md5( $id ) );
	}

	/** ثبت شناسهٔ پردازش‌شده (۱۵ دقیقه). */
	public static function remember( string $id ): void {
		if ( '' !== $id ) {
			set_transient( 'paia_dlv_' . md5( $id ), 1, self::DELIVERY_TTL );
		}
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
