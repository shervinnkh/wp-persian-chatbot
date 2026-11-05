<?php
namespace PersianAIAssistant\Apis\Endpoints;

use PersianAIAssistant\Apis\Live\Text;
use PersianAIAssistant\Database\LiveStore;
use PersianAIAssistant\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/paia/v1/live/tool   (API شمارهٔ ۲ ابزارهای هوش مصنوعی — LIVE_TOOLS_API.md §6)
 *
 * بدنه: { request_id, tool_id: 0 }   ← tool_id همان id ابزار در tools[] پیام (= شناسهٔ ردیف «اطلاعات تعریف‌شده»)
 * پاسخ: { ok: true, text: "<متن زندهٔ ابزار>" }  یا  { ok: false, error: "این ابزار وجود ندارد یا غیرفعال است" }
 *
 * پاسخ موفق ۶۰ ثانیه با transient کش می‌شود (option: paia_tool_cache_ttl؛ ۰ = خاموش)؛ ویرایش مدیر حداکثر تا همین مدت دیر اثر می‌کند.
 * tool_id فقط به عدد تبدیل و با prepare در LiveStore::get استفاده می‌شود (هرگز مستقیم در SQL/مسیر فایل).
 */
final class LiveToolEndpoint extends AbstractEndpoint {

	/** سقف متن (TOOLS_MAX_RESULT_CHARS سرور)؛ بیشتر از آن سمت سرور بریده می‌شود. */
	private const MAX_CHARS = 12000;

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/live/tool',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dispatch' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$params = $request->get_json_params();
			$params = is_array( $params ) ? $params : array();
			$id     = $this->tool_id( $params['tool_id'] ?? null );

			// کش ۶۰ ثانیه‌ای پاسخ موفق: ضربهٔ کش هیچ کوئری‌ای به دیتابیس نمی‌زند.
			$parts  = array( 'tool_id' => $id );
			$cached = $id > 0 ? $this->cache_get( 'tool', $parts ) : null;
			if ( null !== $cached ) {
				return $this->respond( $cached );
			}

			$tool = $id > 0 ? LiveStore::get( $id ) : null;

			if ( ! $tool || empty( $tool['enabled'] ) ) {
				return $this->respond(
					array(
						'ok'    => false,
						'error' => __( 'این ابزار وجود ندارد یا غیرفعال است', 'persian-ai-assistant' ),
					)
				);
			}

			$text = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) ( $tool['content'] ?? '' ) ) );
			if ( '' === $text ) {
				return $this->respond(
					array(
						'ok'    => false,
						'error' => __( 'متن این ابزار خالی است', 'persian-ai-assistant' ),
					)
				);
			}

			$result = array(
				'ok'   => true,
				'text' => Text::cut( Text::latin_digits( $text ), self::MAX_CHARS ),
			);
			$this->cache_put( 'tool', $parts, $result );

			return $this->respond( $result );
		} catch ( \Throwable $e ) {
			LogStore::add(
				LogStore::ERROR,
				__( 'خطا در API زندهٔ ابزارها (/live/tool).', 'persian-ai-assistant' ),
				array(
					'method'   => 'POST',
					'endpoint' => '/paia/v1/live/tool',
					'error'    => get_class( $e ) . ': ' . $e->getMessage(),
				)
			);

			return $this->respond(
				array(
					'ok'    => false,
					'error' => __( 'خطای داخلی سایت در خواندن ابزار', 'persian-ai-assistant' ),
				)
			);
		}
	}

	/**
	 * عدد صحیح مثبت از tool_id (عدد یا رشتهٔ عددی)؛ هر چیز دیگر (آرایه، bool، رشتهٔ غیرعددی) = 0.
	 *
	 * @param mixed $raw مقدار خام.
	 */
	private function tool_id( $raw ): int {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 0;
		}
		if ( is_string( $raw ) && 1 === preg_match( '/^\s*(\d{1,12})\s*$/', Text::latin_digits( $raw ), $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}
}
