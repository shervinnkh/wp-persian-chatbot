<?php
namespace PersianWPChatbot\Rest;

use PersianWPChatbot\Support\Capability;
use PersianWPChatbot\Support\Status;

defined( 'ABSPATH' ) || exit;

/**
 * کلید روشن/خاموش کل چت‌بات (تب «عمومی»).
 * این تنها کنترلری است که حتی وقتی چت‌بات خاموش است هم کار می‌کند (تا بشود دوباره روشنش کرد).
 */
final class StatusController extends AbstractController {

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'admin_only' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_status' ),
					'permission_callback' => array( $this, 'admin_only' ),
					'args'                => array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * @return bool|\WP_Error
	 */
	public function admin_only() {
		if ( Capability::current_user_can() ) {
			return true;
		}

		return new \WP_Error(
			'pwc_forbidden',
			__( 'شما اجازهٔ انجام این کار را ندارید.', 'persian-wp-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function get_status(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'enabled' => Status::is_enabled() ) );
	}

	public function set_status( \WP_REST_Request $request ): \WP_REST_Response {
		Status::set_enabled( (bool) $request->get_param( 'enabled' ) );

		return new \WP_REST_Response( array( 'enabled' => Status::is_enabled() ) );
	}
}
