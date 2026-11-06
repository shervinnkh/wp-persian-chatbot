<?php
namespace PersianWPChatbot\Apis\Endpoints;

use PersianWPChatbot\Apis\Auth\Signature;
use PersianWPChatbot\Rest\AbstractController;

defined( 'ABSPATH' ) || exit;

/**
 * پایهٔ مسیرهای ورودی از سرور هوش مصنوعی. permission_callback همه = authorize() (امضای HMAC).
 */
abstract class AbstractEndpoint extends AbstractController {

	/**
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request ) {
		return Signature::verify( $request );
	}

	/** option: عمر کش پاسخ ابزارهای زنده (ثانیه)؛ پیش‌فرض ۶۰، ۰ = کش خاموش. */
	public const CACHE_TTL_OPTION  = 'pwc_tool_cache_ttl';
	public const CACHE_TTL_DEFAULT = 60;

	private static function cache_ttl(): int {
		return max( 0, (int) get_option( self::CACHE_TTL_OPTION, self::CACHE_TTL_DEFAULT ) );
	}

	/**
	 * @param string               $kind  products | tool.
	 * @param array<string, mixed> $parts پارامترهای درخواست (بدون request_id)؛ کلید = hash آن‌ها.
	 */
	private static function cache_key( string $kind, array $parts ): string {
		return 'pwc_tc_' . substr( hash( 'sha256', $kind . '|' . wp_json_encode( $parts ) ), 0, 40 );
	}

	/**
	 * کش ۶۰ ثانیه‌ایِ پاسخ موفقِ ابزار (خواندنی) با transient وردپرس؛ انقضا تنها روش باطل‌شدن است.
	 *
	 * @param array<string, mixed> $parts
	 * @return array<string, mixed>|null پاسخ ذخیره‌شده یا null.
	 */
	protected function cache_get( string $kind, array $parts ): ?array {
		if ( self::cache_ttl() <= 0 ) {
			return null;
		}
		$hit = get_transient( self::cache_key( $kind, $parts ) );

		return is_array( $hit ) && ! empty( $hit['ok'] ) ? $hit : null;
	}

	/**
	 * فقط پاسخ موفق (ok=true) کش می‌شود؛ خطا هرگز.
	 *
	 * @param array<string, mixed> $parts
	 * @param array<string, mixed> $data
	 */
	protected function cache_put( string $kind, array $parts, array $data ): void {
		$ttl = self::cache_ttl();
		if ( $ttl > 0 && ! empty( $data['ok'] ) ) {
			set_transient( self::cache_key( $kind, $parts ), $data, $ttl );
		}
	}

	/**
	 * callback ثبت‌شدهٔ همهٔ endpointهای POST (به‌جای handle مستقیم): شناسهٔ تکراری ← 200 {"duplicate":true} بدون پردازش دوباره؛
	 * شناسه فقط بعد از پردازشِ موفق ثبت می‌شود تا تلاش مجدد یک ارسال ناموفق (5xx) دوباره پردازش شود. شناسه را
	 * Signature::verify (permission_callback) پیش‌تر اجباری کرده است.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function dispatch( \WP_REST_Request $request ) {
		$id = Signature::delivery_id( $request );
		$id = is_string( $id ) ? $id : '';

		if ( Signature::seen( $id ) ) {
			return $this->respond(
				array(
					'ok'          => true,
					'duplicate'   => true,
					'delivery_id' => $id,
				)
			);
		}

		$response = $this->handle( $request );

		$failed = is_wp_error( $response ) || ( $response instanceof \WP_REST_Response && $response->get_status() >= 400 );
		if ( ! $failed ) {
			Signature::remember( $id );
		}

		return $response;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	protected function respond( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
