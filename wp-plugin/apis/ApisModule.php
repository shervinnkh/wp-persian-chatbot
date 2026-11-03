<?php
namespace PersianAIAssistant\Apis;

use PersianAIAssistant\Apis\Endpoints\AbstractEndpoint;
use PersianAIAssistant\Apis\Endpoints\FileEndpoint;
use PersianAIAssistant\Apis\Endpoints\PingEndpoint;
use PersianAIAssistant\Apis\Endpoints\ResultsEndpoint;
use PersianAIAssistant\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * API‌های «ورودی» افزونه: چیزهایی که سرور هوش مصنوعی صدا می‌زند (نه مدیر سایت).
 *
 * تفاوت با Rest\RestModule: آنجا مسیرها با کوکی/nonce مدیر محافظت می‌شوند؛ اینجا با امضای HMAC (Apis\Auth\Signature).
 * قرارداد کامل برای تیم سرور: apis/README.md و apis/openapi.yaml
 *
 * فقط وقتی چت‌بات روشن است ثبت می‌شود (Core\Plugin::module_classes). در حالت خاموش مسیرها 404 می‌دهند.
 */
final class ApisModule implements Module {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// کرون «بازبینی» وضعیت رکوردهای در حال پردازش وقتی وب‌هوک گم شود (apis/Reconciler.php).
		( new Reconciler() )->register();
	}

	public function register_routes(): void {
		$endpoints = (array) apply_filters(
			'paia_api_endpoints',
			array(
				PingEndpoint::class,
				ResultsEndpoint::class,
				FileEndpoint::class,
			)
		);

		foreach ( $endpoints as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$endpoint = new $class();
			if ( $endpoint instanceof AbstractEndpoint ) {
				$endpoint->register_routes();
			}
		}
	}
}
