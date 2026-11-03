<?php
namespace PersianAIAssistant\Rest;

use PersianAIAssistant\Contracts\Module;
use PersianAIAssistant\Support\Status;

defined( 'ABSPATH' ) || exit;

/**
 * ثبت همهٔ کنترلرهای REST افزونه (فضای نام paia/v1).
 */
final class RestModule implements Module {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		// کلید وضعیت چت‌بات همیشه ثبت می‌شود؛ بقیه فقط وقتی چت‌بات روشن است.
		( new StatusController() )->register_routes();

		if ( ! Status::is_enabled() ) {
			return;
		}

		$controllers = (array) apply_filters(
			'paia_rest_controllers',
			array(
				ExtractionController::class,
				AutoSyncController::class,
				DocumentsController::class,
				LogsController::class,
				LiveController::class,
				SubscriptionController::class,
				AiStatusController::class,
			)
		);

		foreach ( $controllers as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$controller = new $class();
			if ( $controller instanceof AbstractController ) {
				$controller->register_routes();
			}
		}
	}
}
