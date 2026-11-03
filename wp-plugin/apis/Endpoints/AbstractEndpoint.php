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
	 * @param array<string, mixed> $data
	 */
	protected function respond( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
