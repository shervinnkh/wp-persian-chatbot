<?php
namespace PersianAIAssistant\Rest;

use PersianAIAssistant\Subscription\Service;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /wp-json/paia/v1/subscription        آخرین وضعیت همگام‌شدهٔ اشتراک
 * POST /wp-json/paia/v1/subscription/sync   «همگام‌سازی»: اتصال به سرور هوش مصنوعی و گرفتن وضعیت تازه
 */
final class SubscriptionController extends AbstractController {

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/subscription',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_state' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/subscription/sync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	public function get_state(): \WP_REST_Response {
		return rest_ensure_response( Service::present( Service::stored() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync() {
		$state = Service::sync();
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return rest_ensure_response( Service::present( $state ) );
	}
}
