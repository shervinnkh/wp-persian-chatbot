<?php
namespace PersianWPChatbot\Apis\Endpoints;

use PersianWPChatbot\Apis\Auth\Signature;
use PersianWPChatbot\Apis\Results;
use PersianWPChatbot\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/pwc/v1/ai/results
 *
 * سرور بعد از پایان پردازش (که ممکن است ۵ دقیقه و بیشتر طول بکشد) نتیجهٔ یک «دسته» رکورد را اینجا اعلام می‌کند و
 * افزونه ai_status آن‌ها را عوض می‌کند (trained → 1 ، untrained → 0). بدنه:
 *
 *   { "job_id": "j_123", "delivery_id": "uuid", "results": [ { "id": "product:23", "status": "trained", "rev": "…" }, … ] }
 *
 * این مسیر عمداً خیلی سبک است: بعد از امضا فقط چند UPDATE دسته‌ای می‌زند و فوراً 200 می‌دهد (سایت ضعیف را معطل نمی‌کند).
 * پاسخ 2xx یعنی «گرفتم»؛ هر پاسخ 5xx/408/429 یعنی «بعداً دوباره بفرست».
 */
final class ResultsEndpoint extends AbstractEndpoint {

	/** سقف حجم بدنه (بایت). */
	private const MAX_BODY = 1048576;

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/results',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dispatch' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$started = microtime( true );
		$body    = (string) $request->get_body();

		if ( strlen( $body ) > self::MAX_BODY ) {
			return new \WP_Error( 'pwc_too_large', __( 'حجم درخواست بیش از حد مجاز است.', 'persian-wp-chatbot' ), array( 'status' => 413 ) );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'pwc_bad_json', __( 'بدنهٔ درخواست JSON معتبر نیست.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
		}

		$results = $json['results'] ?? null;
		if ( ! is_array( $results ) || ! $results ) {
			return new \WP_Error( 'pwc_no_results', __( 'فهرست results خالی است.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
		}
		if ( count( $results ) > Results::MAX_RESULTS ) {
			return new \WP_Error(
				'pwc_too_many',
				__( 'تعداد نتیجه‌ها در یک درخواست بیش از حد مجاز است.', 'persian-wp-chatbot' ),
				array(
					'status'      => 400,
					'max_results' => Results::MAX_RESULTS,
				)
			);
		}

		// شناسهٔ ارسال را Signature::verify اجباری و AbstractEndpoint::dispatch (S-6) تکراری‌بودنش را بررسی کرده است.
		$delivery = Signature::delivery_id( $request );
		$delivery = is_string( $delivery ) ? $delivery : '';
		$job_id   = $this->clean_token( (string) ( $json['job_id'] ?? '' ) );

		// آیتم‌های kind = chat_answer و chat_messages_saved مربوط به چت‌اند (نه نتیجهٔ رکورد): جدا می‌شوند.
		$answers = array();
		$saved   = array(); // kind = chat_messages_saved: «این پیام‌ها در سرور ثبت شدند»
		$records = array();
		foreach ( $results as $row ) {
			$kind = is_array( $row ) ? ( $row['kind'] ?? '' ) : '';
			if ( 'chat_answer' === $kind ) {
				$answers[] = $row;
			} elseif ( 'chat_messages_saved' === $kind ) {
				$saved[] = $row;
			} else {
				$records[] = $row;
			}
		}

		try {
			if ( $saved ) {
				\PersianWPChatbot\Chat\ChatAnswers::apply_saved( $saved );
			}
			if ( $answers ) {
				\PersianWPChatbot\Chat\ChatAnswers::apply( $answers );
			}
			$summary = Results::apply( $records );
		} catch ( \Throwable $e ) {
			LogStore::add(
				LogStore::ERROR,
				__( 'اعمال نتیجهٔ سرور هوش مصنوعی با خطا روبه‌رو شد.', 'persian-wp-chatbot' ),
				array(
					'method'      => 'POST',
					'endpoint'    => '/pwc/v1/ai/results',
					'http_status' => 500,
					'error'       => $e->getMessage(),
				)
			);

			return new \WP_Error( 'pwc_internal', __( 'خطای داخلی در افزونه.', 'persian-wp-chatbot' ), array( 'status' => 500 ) );
		}

		if ( ! $records ) {
			// فقط پاسخ چت بود: لاگ جدا نمی‌نویسیم (تعدادشان زیاد است).
			return $this->respond(
				array(
					'ok'          => true,
					'delivery_id' => $delivery,
				)
			);
		}

		$applied = count( $summary['applied'] );
		$ignored = count( $summary['ignored'] );
		$failed  = count( $summary['failed'] );

		LogStore::add(
			$failed > 0 ? LogStore::ERROR : ( $applied > 0 ? LogStore::SUCCESS : LogStore::INFO ),
			sprintf(
				/* translators: 1: applied count, 2: ignored count, 3: failed count */
				__( 'نتیجهٔ پردازش از سرور هوش مصنوعی دریافت شد: %1$s مورد اعمال شد، %2$s مورد نادیده گرفته شد، %3$s مورد ناموفق بود.', 'persian-wp-chatbot' ),
				number_format_i18n( $applied ),
				number_format_i18n( $ignored ),
				number_format_i18n( $failed )
			),
			array_filter(
				array(
					'method'      => 'POST',
					'endpoint'    => '/pwc/v1/ai/results',
					'http_status' => 200,
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'ip'          => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'job_id'      => $job_id,
					'delivery_id' => $delivery,
					'applied'     => $applied,
					'ignored'     => $summary['ignored'] ? wp_json_encode( array_slice( $summary['ignored'], 0, 20 ), JSON_UNESCAPED_UNICODE ) : '',
					'failed'      => $summary['failed'] ? wp_json_encode( array_slice( $summary['failed'], 0, 20 ), JSON_UNESCAPED_UNICODE ) : '',
				),
				static function ( $value ) {
					return '' !== $value && null !== $value;
				}
			)
		);

		return $this->respond(
			array(
				'ok'          => true,
				'delivery_id' => $delivery,
				'applied'     => $summary['applied'],
				'ignored'     => $summary['ignored'],
			)
		);
	}

	private function clean_token( string $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_\-:.]/', '', $value ), 0, 64 );
	}
}
