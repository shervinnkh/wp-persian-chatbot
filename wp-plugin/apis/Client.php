<?php
namespace PersianAIAssistant\Apis;

use PersianAIAssistant\Chat\ChatSettings;

defined( 'ABSPATH' ) || exit;

/**
 * کلاینت امضادار «افزونه ← سرور هوش مصنوعی» (جهت A در راهنمای ارتباط؛ همهٔ مسیرهای /v1/...).
 *
 * امضا (متفاوت از وب‌هوک جهت B که در Auth\Signature است):
 *   X-Timestamp = زمان یونیکس (ثانیه)
 *   X-Signature = hex( HMAC_SHA256( secret, timestamp . "\n" . بدنهٔ خام ) )   ← حروف کوچک، ۶۴ نویسه، بدون پیشوند
 * امضا شامل متد و مسیر نیست. بدنه یک‌بار ساخته می‌شود و همان رشته هم امضا هم ارسال می‌شود.
 * برای GET و DELETEِ بی‌بدنه، بدنه رشتهٔ خالی است (امضا روی «timestamp\n»).
 *
 * تنظیم اتصال (به ترتیب اولویت):
 *   - ثابت‌های wp-config.php:  PAIA_API_URL ، PAIA_API_SECRET   ← روش اصلی و توصیه‌شده؛ با تعریف PAIA_API_SECRET کلید
 *     هرگز در دیتابیس (option) ذخیره یا در صفحهٔ تنظیمات نمایش داده نمی‌شود (S-5).
 *   - تنظیمات افزونه: api_url ، api_key (ChatSettings)
 *   - فیلترها: paia_api_url ، paia_api_request_secret
 *
 * کلیدهای جدا (اختیاری؛ در صورت تعریف نشدن به کلید مشترک برمی‌گردند):
 *   - PAIA_CALLBACK_SECRET  : امضای درخواست‌های ورودی از سرور (Auth\Signature) ← باید با PLUGIN_CALLBACK_SECRET سرور یکی باشد
 *   - PAIA_FILE_LINK_SECRET : امضای لینک دانلود فایل‌ها (FileLink) ← فقط در همین افزونه استفاده می‌شود
 *
 * هر تلاش دوباره زمان تازه می‌گیرد و دوباره امضا می‌شود (S-6: سرور هر امضای تکراریِ POST/PUT/DELETE را replay می‌داند؛
 * پس دو درخواست هم‌بدنه نباید زمان یکسان داشته باشند: next_timestamp()). فقط خطاهای موقت (timeout/408/429/5xx) تلاش مجدد دارند؛
 * 4xx (امضا، اعتبارسنجی، …) باگ/تنظیم است و تلاش مجدد بی‌فایده.
 */
final class Client {

	/** سقف بدنه سمت سرور ۵ مگابایت است؛ پیش از ارسال همین‌جا رد می‌شود. */
	public const MAX_BODY = 5242880;

	/** کد خطای محلی (پیش از رسیدن به شبکه). */
	public const ERR_NOT_CONFIGURED = 'not_configured';
	public const ERR_HTTPS_REQUIRED = 'https_required';
	public const ERR_ENCODE_FAILED  = 'encode_failed';
	public const ERR_TOO_LARGE      = 'payload_too_large';
	public const ERR_NETWORK        = 'network_error';

	/**
	 * آدرس پایهٔ سرور بدون «/» انتهایی (مثل https://ai.example.com).
	 */
	public static function base_url(): string {
		$url = defined( 'PAIA_API_URL' ) && '' !== (string) PAIA_API_URL ? (string) PAIA_API_URL : (string) ChatSettings::get( 'api_url', '' );

		return (string) apply_filters( 'paia_api_url', untrailingslashit( trim( $url ) ) );
	}

	/**
	 * رازِ مشترک خام (همان API_SHARED_SECRET سرور): ثابت PAIA_API_SECRET یا api_key تنظیمات. رشتهٔ ۶۴ نویسه، نه بایت‌های دیکدشده.
	 */
	public static function shared_secret(): string {
		if ( defined( 'PAIA_API_SECRET' ) && '' !== (string) PAIA_API_SECRET ) {
			return trim( (string) PAIA_API_SECRET );
		}

		return trim( (string) ChatSettings::get( 'api_key', '' ) );
	}

	/**
	 * آیا کلید اصلی از ثابت PAIA_API_SECRET (wp-config.php) خوانده می‌شود؟ (آن‌وقت UI چیزی ذخیره/نمایش نمی‌دهد.)
	 */
	public static function secret_from_constant(): bool {
		return defined( 'PAIA_API_SECRET' ) && '' !== trim( (string) PAIA_API_SECRET );
	}

	/**
	 * کلید امضای درخواست‌های ورودی از سرور (وب‌هوک‌ها). ثابت PAIA_CALLBACK_SECRET (= PLUGIN_CALLBACK_SECRET سرور)
	 * یا، اگر تعریف نشده، همان کلید مشترک.
	 */
	public static function callback_secret(): string {
		if ( defined( 'PAIA_CALLBACK_SECRET' ) && '' !== trim( (string) PAIA_CALLBACK_SECRET ) ) {
			return trim( (string) PAIA_CALLBACK_SECRET );
		}

		return self::shared_secret();
	}

	/**
	 * کلید امضای لینک دانلود فایل‌ها. ثابت PAIA_FILE_LINK_SECRET یا، اگر تعریف نشده، همان کلید مشترک.
	 */
	public static function file_link_secret(): string {
		if ( defined( 'PAIA_FILE_LINK_SECRET' ) && '' !== trim( (string) PAIA_FILE_LINK_SECRET ) ) {
			return trim( (string) PAIA_FILE_LINK_SECRET );
		}

		return self::shared_secret();
	}

	/**
	 * نمایش ماسک‌شدهٔ یک کلید: •••••••• + ۴ نویسهٔ آخر (کلید کوتاه‌تر از ۸ نویسه کاملاً ماسک می‌شود).
	 */
	public static function mask_secret( string $secret ): string {
		$secret = trim( $secret );
		if ( '' === $secret ) {
			return '';
		}

		return '••••••••' . ( strlen( $secret ) >= 8 ? substr( $secret, -4 ) : '' );
	}

	/**
	 * زمان امضا برای یک تلاش: هرگز دو تلاش/درخواست هم‌بدنه در یک پردازش PHP یک ثانیهٔ یکسان نمی‌گیرند
	 * (وگرنه امضایشان یکی می‌شود و سرور تلاش دوم را replay می‌داند). کلید = بدنه، یا متد+مسیر برای درخواست بی‌بدنه.
	 */
	private static function next_timestamp( string $key ): int {
		static $last = array();
		$ts          = max( time(), ( $last[ $key ] ?? 0 ) + 1 );
		$last[ $key ] = $ts;

		return $ts;
	}

	/**
	 * کلید امضای درخواست‌های خروجی (جهت A). برای وب‌هوک ورودی (جهت B) فیلتر جدای paia_api_secret در Auth\Signature است.
	 */
	public static function secret(): string {
		return (string) apply_filters( 'paia_api_request_secret', self::shared_secret() );
	}

	public static function is_configured(): bool {
		return '' !== self::base_url() && '' !== self::secret();
	}

	/**
	 * تست اتصال و امضا: GET /v1/ping ← body.server_time (برای مقایسهٔ اختلاف ساعت).
	 *
	 * @return array<string, mixed>
	 */
	public static function ping( int $timeout = 10 ): array {
		return self::request( 'GET', '/v1/ping', null, $timeout, 1 );
	}

	/**
	 * @param string                    $method   GET | POST | PUT | DELETE
	 * @param string                    $path     مثل /v1/knowledge/records
	 * @param array<string, mixed>|null $payload  null = بدون بدنه
	 * @param int                       $timeout  ثانیه (چت sync باید ≥ ۶۰ باشد؛ ثبت رکورد ۱۵ کافی است)
	 * @param int                       $attempts حداکثر تعداد تلاش برای خطاهای موقت
	 * @param bool                      $blocking false = «بفرست و منتظر پاسخ نمان» (مثل اعلان realtime): پاسخ سرور خوانده نمی‌شود و نتیجه همیشه ok/202 است مگر ارسال محلی شکست بخورد
	 * @return array{ok:bool, code:int, body:array<string, mixed>, error_code:string, error:string, request_id:string, duration_ms:int, attempts:int}
	 */
	public static function request( string $method, string $path, ?array $payload = null, int $timeout = 15, int $attempts = 2, bool $blocking = true ): array {
		$method = strtoupper( $method );
		$result = self::result( 0, array(), '', '' );

		/**
		 * جای‌گزینی انتقال (تست/توسعه بدون سرور). null = درخواست واقعی.
		 *
		 * @param array<string, mixed>|null $response آرایهٔ {code:int, body?:array}
		 * @param string                    $method
		 * @param string                    $path
		 * @param array<string, mixed>|null $payload
		 */
		$custom = apply_filters( 'paia_api_client_request', null, $method, $path, $payload );
		if ( is_array( $custom ) && isset( $custom['code'] ) ) {
			return self::result( (int) $custom['code'], isset( $custom['body'] ) && is_array( $custom['body'] ) ? $custom['body'] : array(), '', '' );
		}

		$base   = self::base_url();
		$secret = self::secret();
		if ( '' === $base || '' === $secret ) {
			return self::result( 0, array(), self::ERR_NOT_CONFIGURED, __( 'آدرس یا کلید اتصال به سرور هوش مصنوعی تنظیم نشده است.', 'persian-ai-assistant' ) );
		}
		if ( 0 !== stripos( $base, 'https://' ) && ! self::http_allowed() ) {
			return self::result( 0, array(), self::ERR_HTTPS_REQUIRED, __( 'آدرس سرور هوش مصنوعی باید با https:// شروع شود.', 'persian-ai-assistant' ) );
		}

		// بدنه فقط یک‌بار ساخته می‌شود؛ همین رشته امضا و ارسال می‌شود.
		$body = '';
		if ( null !== $payload ) {
			$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) ) {
				return self::result( 0, array(), self::ERR_ENCODE_FAILED, __( 'تبدیل داده به JSON ناموفق بود.', 'persian-ai-assistant' ) );
			}
			$body = $json;
			if ( strlen( $body ) > self::MAX_BODY ) {
				return self::result( 0, array(), self::ERR_TOO_LARGE, __( 'حجم درخواست بیش از ۵ مگابایت است.', 'persian-ai-assistant' ) );
			}
		}

		$url      = $base . '/' . ltrim( $path, '/' );
		$started  = microtime( true );
		$attempts = max( 1, $attempts );

		for ( $try = 1; $try <= $attempts; $try++ ) {
			$timestamp = (string) self::next_timestamp( '' === $body ? $method . ' ' . $path : md5( $body ) ); // هر تلاش: زمان تازه (یکتا) + امضای تازه.
			$signature = hash_hmac( 'sha256', $timestamp . "\n" . $body, $secret );

			$args = array(
				'method'      => $method,
				'timeout'     => max( 1, $timeout ),
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'paia_api_sslverify', true ),
				'user-agent'  => 'PersianAIAssistant/' . PAIA_VERSION . '; ' . home_url(),
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'X-Timestamp'  => $timestamp,
					'X-Signature'  => $signature,
				),
			);
			if ( '' !== $body ) {
				$args['body'] = $body;
			}
			if ( ! $blocking ) {
				$args['blocking'] = false;
			}

			$response = wp_remote_request( $url, $args );

			if ( ! $blocking && ! is_wp_error( $response ) ) {
				// ارسال شد؛ پاسخ را منتظر نمی‌مانیم (ok/202).
				$result             = self::result( 202, array(), '', '' );
				$result['attempts'] = $try;
				break;
			}

			if ( is_wp_error( $response ) ) {
				$result = self::result( 0, array(), self::ERR_NETWORK, $response->get_error_message() );
			} else {
				$code    = (int) wp_remote_retrieve_response_code( $response );
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				$decoded = is_array( $decoded ) ? $decoded : array();
				$err     = isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : array();

				$result = self::result(
					$code,
					$decoded,
					$code >= 400 ? (string) ( $err['code'] ?? 'http_' . $code ) : '',
					$code >= 400 ? (string) ( $err['message'] ?? '' ) : '',
					(string) ( $err['request_id'] ?? '' )
				);
			}

			$result['attempts'] = $try;

			if ( $result['ok'] || ! self::is_transient( $result['code'] ) || $try >= $attempts ) {
				break;
			}
			usleep( 600000 * $try ); // backoff کوتاه؛ این درخواست‌ها داخل ذخیرهٔ نوشته/پنل اجرا می‌شوند.
		}

		$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		return $result;
	}

	/**
	 * خطای موقت = ارزش تلاش مجدد دارد (timeout/قطعی شبکه، 408، 429، 5xx).
	 */
	public static function is_transient( int $code ): bool {
		return 0 === $code || 408 === $code || 429 === $code || $code >= 500;
	}

	/**
	 * HTTP بدون TLS فقط روی محیط توسعه/لوکال (در production سرور هم رد می‌کند: 400 https_required).
	 */
	private static function http_allowed(): bool {
		// محیط توسعه، یا سرور هوش مصنوعی روی همین دستگاه/شبکهٔ محلی (localhost ، 127.x ، ::1 ، *.local ، *.test ، IP خصوصی).
		// (Local by Flywheel و XAMPP معمولاً WP_ENVIRONMENT_TYPE را «local» نمی‌کنند و قبلاً همین باعث می‌شد هیچ درخواستی ارسال نشود.)
		$allowed = in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || self::is_local_host( self::base_url() );

		return (bool) apply_filters( 'paia_api_allow_http', $allowed );
	}

	/**
	 * آیا آدرس به دستگاه/شبکهٔ محلی اشاره می‌کند؟ (فقط برای اجازهٔ http در توسعه؛ در production باید https باشد.)
	 */
	private static function is_local_host( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = trim( $host, '[]' );
		if ( '' === $host ) {
			return false;
		}
		if ( 'localhost' === $host || '::1' === $host ) {
			return true;
		}
		foreach ( array( '.localhost', '.local', '.test' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{ok:bool, code:int, body:array<string, mixed>, error_code:string, error:string, request_id:string, duration_ms:int, attempts:int}
	 */
	private static function result( int $code, array $body, string $error_code, string $error, string $request_id = '' ): array {
		return array(
			'ok'          => $code >= 200 && $code < 300,
			'code'        => $code,
			'body'        => $body,
			'error_code'  => $error_code,
			'error'       => $error,
			'request_id'  => $request_id,
			'duration_ms' => 0,
			'attempts'    => 1,
		);
	}
}
