<?php
namespace PersianWPChatbot\Rest;

use PersianWPChatbot\Apis\Client;
use PersianWPChatbot\Support\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * صفحهٔ «پنل مانیتورینگ»:
 * GET /wp-json/pwc/v1/monitoring   گزارش روزانه/ماهانه/سالانهٔ سرور هوش مصنوعی (?period=day|month|year&date=&calendar=jalali|gregorian)
 *
 * سرور (POST /v1/get_monitoring) از فایل‌های JSONL مانیتورینگ گزارش می‌سازد؛ اینجا فقط امضا و ارسال می‌شود.
 * این مسیر مستقل از کلید «وضعیت چت‌بات» است: مدیر باید بتواند وضعیت سرور را ببیند حتی وقتی چت‌بات خاموش است.
 */
final class MonitoringController extends AbstractController {

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/monitoring',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'period'   => array(
						'type'    => 'string',
						'default' => 'day',
						'enum'    => array( 'day', 'month', 'year' ),
					),
					'date'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return '' === (string) $value || 1 === preg_match( '/^\d{4}(-\d{1,2}){0,2}$/', (string) $value );
						},
					),
					'calendar' => array(
						'type'    => 'string',
						'default' => 'jalali',
						'enum'    => array( 'jalali', 'gregorian' ),
					),
				),
			)
		);
	}

	/**
	 * فقط مدیران (چت‌بات روشن بودن لازم نیست).
	 *
	 * @return bool|\WP_Error
	 */
	public function permissions_check() {
		if ( Capability::current_user_can() ) {
			return true;
		}

		return new \WP_Error(
			'pwc_forbidden',
			__( 'شما اجازهٔ انجام این کار را ندارید.', 'persian-wp-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$payload = array(
			'period'   => (string) $request->get_param( 'period' ),
			'calendar' => (string) $request->get_param( 'calendar' ),
		);
		$date = (string) $request->get_param( 'date' );
		if ( '' !== $date ) {
			$payload['date'] = $date;
		}

		/**
		 * قیمت هر ۱ میلیون توکن برای برآورد هزینه: array( 'نام-مدل' => array( ورودی, خروجی ) ).
		 * مثال: add_filter( 'pwc_monitor_prices', fn() => array( 'gpt-5-4' => array( 1.25, 10 ), 'text-embedding-3-large' => array( 0.13, 0 ) ) );
		 */
		$prices = (array) apply_filters( 'pwc_monitor_prices', array() );
		if ( $prices ) {
			$payload['prices'] = $prices;
		}

		// گزارش سال ممکن است چند ثانیه طول بکشد؛ تلاش مجدد نداریم (سرور خودش کش دارد).
		$res = Client::request( 'POST', '/v1/get_monitoring', $payload, 45, 1 );

		if ( ! $res['ok'] || ! isset( $res['body']['summary'] ) ) {
			$message = '' !== (string) $res['error'] ? (string) $res['error'] : sprintf( 'HTTP %d', (int) $res['code'] );

			return new \WP_Error(
				'pwc_monitoring_failed',
				$message,
				array(
					'status'     => in_array( (int) $res['code'], array( 400, 401, 403 ), true ) ? (int) $res['code'] : 502,
					'error_code' => (string) $res['error_code'],
				)
			);
		}

		return rest_ensure_response( $res['body'] );
	}
}
