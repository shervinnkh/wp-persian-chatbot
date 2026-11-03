<?php
namespace PersianAIAssistant\Apis;

defined( 'ABSPATH' ) || exit;

/**
 * لینک دانلودِ امضادار و کوتاه‌عمر برای فایل‌های «اسناد اضافه» — سرور هوش مصنوعی فایل را با همین لینک برمی‌دارد.
 *
 * چرا؟ فایل روی دیسک محافظت‌شده است (uploads/paia-docs/.htaccess) و دانلود مدیر فقط با نانس کار می‌کند؛ سرور هوش مصنوعی
 * نه نانس دارد و نه کوکی. افزونه برای هر ارسال یک لینک تازه می‌سازد:
 *
 *   https://site/wp-json/paia/v1/ai/file?id=file:1730000000000&exp=1730003600&sig=<hex>
 *
 *   sig = hex( HMAC_SHA256( کلید, "paia-file\n{id}\n{exp}" ) )
 *
 * • exp زمان انقضا (ثانیهٔ یونیکس) است و داخل امضا هست؛ تغییر id یا exp امضا را باطل می‌کند.
 * • پیشوند «paia-file» جداسازی دامنه است: این امضا هرگز با امضای درخواست‌های API (timestamp + "\n" + body) یکی نمی‌شود.
 * • کلید همان api_key است (Client::shared_secret)؛ سرور هوش مصنوعی چیزی را امضا نمی‌کند و فقط لینک را باز می‌کند.
 * • لینک فقط همان یک فایل را می‌دهد (GET) و بعد از exp کار نمی‌کند. عمر پیش‌فرض ۱ ساعت است (تا نوبت صف و تلاش مجدد
 *   سرور برسد)؛ با فیلتر paia_file_link_ttl بین ۵ دقیقه تا ۲۴ ساعت قابل تغییر است.
 */
final class FileLink {

	public const DEFAULT_TTL = 3600;
	public const MIN_TTL     = 300;
	public const MAX_TTL     = 86400;

	/** الگوی شناسهٔ فایل. */
	public const ID_PATTERN = '/^file:\d{1,20}$/';

	public static function ttl(): int {
		$ttl = (int) apply_filters( 'paia_file_link_ttl', self::DEFAULT_TTL );

		return max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) );
	}

	private static function secret(): string {
		return (string) apply_filters( 'paia_file_link_secret', Client::shared_secret() );
	}

	public static function sign( string $id, int $expires, string $secret ): string {
		return hash_hmac( 'sha256', "paia-file\n" . $id . "\n" . $expires, $secret );
	}

	/**
	 * لینک تازه برای یک فایل. خالی = کلید اتصال تنظیم نشده یا شناسه نامعتبر است.
	 *
	 * @return array{url:string, expires:int}|null
	 */
	public static function make( string $id ): ?array {
		$secret = self::secret();
		if ( '' === $secret || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return null;
		}

		$expires = time() + self::ttl();
		$url     = add_query_arg(
			array(
				'id'  => $id,
				'exp' => $expires,
				'sig' => self::sign( $id, $expires, $secret ),
			),
			rest_url( 'paia/v1/ai/file' )
		);

		return array(
			'url'     => (string) apply_filters( 'paia_file_link_url', $url, $id ),
			'expires' => $expires,
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function verify( string $id, string $exp, string $sig ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'paia_not_configured', __( 'کلید اتصال (api_key) در افزونه تنظیم نشده است.', 'persian-ai-assistant' ), array( 'status' => 503 ) );
		}
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) || ! ctype_digit( $exp ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', strtolower( $sig ) ) ) {
			return new \WP_Error( 'paia_bad_link', __( 'لینک دانلود نامعتبر است.', 'persian-ai-assistant' ), array( 'status' => 403 ) );
		}

		// اول امضا (تا از روی خطای «منقضی» نتوان زمان را برای لینک جعلی کاوید)، بعد انقضا.
		if ( ! hash_equals( self::sign( $id, (int) $exp, $secret ), strtolower( $sig ) ) ) {
			return new \WP_Error( 'paia_bad_link', __( 'لینک دانلود نامعتبر است.', 'persian-ai-assistant' ), array( 'status' => 403 ) );
		}
		if ( (int) $exp < time() ) {
			return new \WP_Error( 'paia_link_expired', __( 'لینک دانلود منقضی شده است.', 'persian-ai-assistant' ), array( 'status' => 403 ) );
		}

		return true;
	}
}
