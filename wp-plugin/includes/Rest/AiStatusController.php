<?php
namespace PersianAIAssistant\Rest;

use PersianAIAssistant\Apis\Reconciler;
use PersianAIAssistant\Apis\Records;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/paia/v1/ai-status?ids=product:1,text:1700…,file:1700…
 *
 * وضعیت فعلی ai_status چند رکورد (۰ = آموزش‌ندیده، ۱ = در حال پردازش سمت سرور، ۲ = پردازش‌شده).
 * رابط مدیر فقط برای رکوردهایی که «در حال پردازش» نشان می‌دهد هر چند ثانیه این را می‌پرسد تا وقتی سرور
 * نتیجه را اعلام کرد (وب‌هوک) آیکن loading همان لحظه به آیکن هوش مصنوعی تبدیل شود. یک کوئری دسته‌ای و سبک است.
 */
final class AiStatusController extends AbstractController {

	/** سقف شناسه در هر درخواست. */
	private const MAX_IDS = 200;

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_statuses' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'ids' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function get_statuses( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = array_slice( array_values( array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'ids' ) ) ) ) ), 0, self::MAX_IDS );

		$statuses = Records::statuses( $ids );

		// وب‌هوک مسیر سریع است ولی اگر به سایت نرسد (WAF/رمز/localhost) رکورد برای همیشه loading می‌ماند؛ پس وقتی پنل باز است و
		// رکوردی هنوز «در حال پردازش» است، حداکثر دقیقه‌ای یک‌بار وضعیت از سرور بازبینی می‌شود (Apis\Reconciler).
		if ( in_array( Records::STATUS_PROCESSING, $statuses, true ) && Reconciler::maybe_run_for( array_keys( $statuses ) ) ) {
			$statuses = Records::statuses( $ids );
		}

		return rest_ensure_response( array( 'statuses' => (object) $statuses ) );
	}
}
