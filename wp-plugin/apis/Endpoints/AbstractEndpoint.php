<?php
namespace PersianAIAssistant\Apis\Endpoints;

use PersianAIAssistant\Apis\Auth\Signature;
use PersianAIAssistant\Rest\AbstractController;

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
