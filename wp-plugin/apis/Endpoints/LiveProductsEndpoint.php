<?php
namespace PersianAIAssistant\Apis\Endpoints;

use PersianAIAssistant\Apis\Live\LiveProducts;
use PersianAIAssistant\Database\LiveStore;
use PersianAIAssistant\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/paia/v1/live/products   (API شمارهٔ ۱ ابزارهای هوش مصنوعی — LIVE_TOOLS_API.md §5)
 *
 * بدنه: { request_id, fields: ["price","stock"], products: [ {id} | {name}, … حداکثر 5 ] }
 * پاسخ: { ok: true, message: "<متن ساختاریافتهٔ قیمت/موجودی>" }  یا  { ok: false, error: "…" }
 *
 * قرارداد پاسخ (§4): برای هر نتیجهٔ «کاری» (حتی «پیدا نشد») HTTP 200؛ فقط احراز هویت ناموفق 401 می‌دهد (permission_callback).
 * هیچ args برای مسیر تعریف نشده تا وردپرس خودش 400 برنگرداند؛ اعتبارسنجی دستی است و خطا همیشه {ok:false} با 200 می‌شود.
 * کش HTTP ندارد (Cache-Control: no-store)، ولی پاسخ موفق ۶۰ ثانیه با transient وردپرس کش می‌شود (option: paia_tool_cache_ttl؛ ۰ = خاموش).
 */
final class LiveProductsEndpoint extends AbstractEndpoint {

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/live/products',
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

			// فقط فیلدهایی که هم سرور خواسته و هم مدیر همین الان در «اطلاعات به‌روز» روشن گذاشته (AI نباید چیزی بیشتر ببیند).
			$asked   = array();
			foreach ( (array) ( $params['fields'] ?? array() ) as $field ) {
				if ( is_string( $field ) ) {
					$asked[] = strtolower( trim( $field ) );
				}
			}
			$groups = array_values( array_intersect( array_unique( $asked ), LiveStore::ai_product_fields() ) );

			$products = array();
			foreach ( (array) ( $params['products'] ?? array() ) as $item ) {
				if ( is_array( $item ) ) {
					$products[] = $item;
				}
			}

			if ( ! $asked || ! $products ) {
				return $this->respond(
					array(
						'ok'    => false,
						'error' => __( 'درخواست نامعتبر', 'persian-ai-assistant' ),
					)
				);
			}
			if ( ! $groups ) {
				return $this->respond(
					array(
						'ok'    => false,
						'error' => __( 'داده زنده محصول در این سایت فعال نیست', 'persian-ai-assistant' ),
					)
				);
			}

			// کش ۶۰ ثانیه‌ای (کلید = فیلدهای فعال + محصولات درخواستی): درخواست یکسان، کوئری تازه به دیتابیس نمی‌زند.
			$products = array_slice( $products, 0, LiveProducts::MAX_REQUESTS );
			$parts    = array(
				'groups'   => $groups,
				'products' => $products,
			);
			$cached   = $this->cache_get( 'products', $parts );
			if ( null !== $cached ) {
				return $this->respond( $cached );
			}
			$result = array(
				'ok'      => true,
				'message' => LiveProducts::render( $groups, $products ),
			);
			$this->cache_put( 'products', $parts, $result );

			return $this->respond( $result );
		} catch ( \Throwable $e ) {
			// جزئیات داخلی (مسیر/SQL) هرگز به AI نمی‌رسد؛ فقط در لاگ سایت می‌ماند.
			LogStore::add(
				LogStore::ERROR,
				__( 'خطا در API زندهٔ محصولات (/live/products).', 'persian-ai-assistant' ),
				array(
					'method'   => 'POST',
					'endpoint' => '/paia/v1/live/products',
					'error'    => get_class( $e ) . ': ' . $e->getMessage(),
				)
			);

			return $this->respond(
				array(
					'ok'    => false,
					'error' => __( 'خطای داخلی سایت در خواندن اطلاعات محصول', 'persian-ai-assistant' ),
				)
			);
		}
	}
}
