<?php
namespace PersianAIAssistant\Apis;

defined( 'ABSPATH' ) || exit;

/**
 * «کارت معرفی» سایت برای سرور هوش مصنوعی: سرور از کجا بفهمد نتیجه را به کدام آدرس بزند؟
 *
 * جواب: خود افزونه در هر درخواستش (AiBackend::send / train / delete) این شیء را زیر کلید `callback` می‌فرستد.
 * آدرس با rest_url() ساخته می‌شود، پس برای سایت‌های بدون پیوند یکتای زیبا (?rest_route=) و زیرپوشه‌ها هم درست است.
 * سرور آن را کنار کار (job) ذخیره می‌کند و بعد از پایان پردازش همان آدرس را صدا می‌زند.
 */
final class Callback {

	/** نسخهٔ قرارداد؛ با تغییر ناسازگار بالا می‌رود. */
	public const API_VERSION = 1;

	public static function url(): string {
		return (string) apply_filters( 'paia_callback_url', rest_url( 'paia/v1/ai/results' ) );
	}

	public static function ping_url(): string {
		return rest_url( 'paia/v1/ai/ping' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function descriptor(): array {
		return array(
			'api_version' => self::API_VERSION,
			'url'         => self::url(),
			'ping_url'    => self::ping_url(),
			'site_url'    => site_url(),
			'signature'   => 'hmac-sha256',
			'max_results' => Results::MAX_RESULTS,
		);
	}
}
