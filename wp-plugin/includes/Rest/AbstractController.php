<?php
namespace PersianAIAssistant\Rest;

use PersianAIAssistant\Support\Capability;
use PersianAIAssistant\Support\Status;

defined( 'ABSPATH' ) || exit;

abstract class AbstractController {

	public const REST_NAMESPACE = 'paia/v1';

	abstract public function register_routes(): void;

	/**
	 * فقط مدیران؛ nonce کوکی را خود وردپرس (X-WP-Nonce) بررسی می‌کند.
	 *
	 * @return bool|\WP_Error
	 */
	public function permissions_check() {
		if ( ! Status::is_enabled() ) {
			return new \WP_Error(
				'paia_disabled',
				__( 'چت‌بات غیرفعال است.', 'persian-ai-assistant' ),
				array( 'status' => 403 )
			);
		}

		if ( Capability::current_user_can() ) {
			return true;
		}

		return new \WP_Error(
			'paia_forbidden',
			__( 'شما اجازهٔ انجام این کار را ندارید.', 'persian-ai-assistant' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
