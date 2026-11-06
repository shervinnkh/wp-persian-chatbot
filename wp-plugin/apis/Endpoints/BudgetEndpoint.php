<?php
namespace PersianWPChatbot\Apis\Endpoints;

use PersianWPChatbot\Chat\BudgetState;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/pwc/v1/ai/budget  (امضای HMAC استاندارد وب‌هوک‌ها؛ مجوز خودکار از AbstractEndpoint)
 * بدنه: {"period":"day|month","level":"warn80|limit100|ok","used_usd":1.62,"limit_usd":2.0,"percent":81,"period_end":1730000000}
 */
final class BudgetEndpoint extends AbstractEndpoint {

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/budget',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! BudgetState::apply_event( $body ) ) {
			return new \WP_Error( 'pwc_bad_budget', __( 'رویداد بودجه نامعتبر است.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
		}
		return $this->respond( array( 'ok' => true ) );
	}
}
