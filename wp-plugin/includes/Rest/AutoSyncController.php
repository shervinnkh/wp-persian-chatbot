<?php
namespace PersianAIAssistant\Rest;

use PersianAIAssistant\Apis\Reconciler;
use PersianAIAssistant\Apis\Records;
use PersianAIAssistant\Database\ItemStore;
use PersianAIAssistant\Database\RemovedStore;
use PersianAIAssistant\Extraction\Sources;
use PersianAIAssistant\Sync\AiBackend;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /wp-json/paia/v1/extraction/auto-new    رکوردهایی که خودکار ثبت شده و هنوز «دیده» نشده‌اند (seen = 0)
 * GET  /wp-json/paia/v1/extraction/record      داده‌ی کامل یک رکورد (برای باز شدن جزئیات هر مورد در فرم): ?id=
 * POST /wp-json/paia/v1/extraction/auto-seen   «همه را دیدم»: {ids} همان رکوردهای نمایش‌داده‌شده را seen = 1 می‌کند
 * POST /wp-json/paia/v1/extraction/auto-train  «آموزش به هوش مصنوعی»: {ids} رکوردهای تیک‌خورده seen = 1 می‌شوند و برای آموزش فرستاده می‌شوند
 * GET  /wp-json/paia/v1/extraction/removed     نوشته‌های حذف‌شده از سایت که هنوز دربارهٔ حذفشان از هوش مصنوعی تصمیمی گرفته نشده
 * POST /wp-json/paia/v1/extraction/removed/delete  «حذف»: {id} برای حذف از آموزش‌ها به سرور داده می‌شود؛ ردیف «در حال حذف» (loading) می‌شود تا وب‌هوک برسد
 * POST /wp-json/paia/v1/extraction/untrain     «حذف آموزش» رکوردهایی که در سایت می‌مانند (درخت استخراج، اسناد): {ids} ← DELETE سرور ← ai_status = 1 ← وب‌هوک ← 0
 *
 * (تیک‌های «استخراج خودکار اطلاعات سایت» دیگر REST ندارند؛ با دکمهٔ «ذخیرهٔ تغییرات» تب تنظیمات ذخیره می‌شوند — SyncSettings.)
 */
final class AutoSyncController extends AbstractController {

	private const LIST_LIMIT = 100;

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/auto-new',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'auto_new' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/auto-seen',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'auto_seen' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'ids' => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array( 'type' => 'string' ),
						'maxItems'          => self::LIST_LIMIT * 2,
						'sanitize_callback' => static function ( $value ) {
							return array_map( 'sanitize_text_field', (array) $value );
						},
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/auto-train',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'auto_train' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'ids' => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array( 'type' => 'string' ),
						'maxItems'          => self::LIST_LIMIT * 2,
						'sanitize_callback' => static function ( $value ) {
							return array_map( 'sanitize_text_field', (array) $value );
						},
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/removed',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'removed' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'check' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/untrain',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'untrain' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'ids' => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array( 'type' => 'string' ),
						'maxItems'          => self::LIST_LIMIT * 2,
						'sanitize_callback' => static function ( $value ) {
							return array_map( 'sanitize_text_field', (array) $value );
						},
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/removed/delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'removed_delete' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/record',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'record' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * @return array<string, string> پیشوند رکورد (product، blog، page) => برچسب منبع
	 */
	private static function category_labels(): array {
		$labels = array();
		foreach ( Sources::all() as $id => $source ) {
			$labels[ Sources::record_category( (string) $id ) ] = (string) $source['label'];
		}

		return $labels;
	}

	public function removed( ?\WP_REST_Request $request = null ): \WP_REST_Response {
		$labels = self::category_labels();

		// ردیف‌هایی که مدیر «حذف» زده و منتظر نتیجهٔ سرورند (check=id1,id2): اگر وب‌هوک نرسیده باشد وضعیت از سرور بازبینی می‌شود.
		$finished = array();
		$failed   = array();
		$check    = $request ? array_values( array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'check' ) ) ) ) ) : array();
		if ( $check ) {
			$check = array_slice( $check, 0, 200 );
			if ( RemovedStore::deleting_ids( $check ) ) {
				Reconciler::maybe_run_for( $check );
			}
			$deleting = RemovedStore::deleting_ids( $check );
			foreach ( $check as $id ) {
				if ( in_array( $id, $deleting, true ) ) {
					continue;
				}
				if ( RemovedStore::exists( $id ) ) {
					$failed[] = $id; // سرور حذف را انجام نداد (failed): دوباره قابل حذف.
				} else {
					$finished[] = $id; // سرور حذف را تمام کرد: ردیف رفته است.
				}
			}
		}

		$items = array();
		foreach ( RemovedStore::list( self::LIST_LIMIT ) as $row ) {
			$category = (string) strstr( $row['id'], ':', true );

			$items[] = array(
				'id'      => $row['id'],
				'label'   => $labels[ $category ] ?? $category,
				'title'   => '' !== $row['title'] ? $row['title'] : $row['id'],
				'trained' => $row['trained'],
							'processing' => $row['processing'],
						);
					}

					return rest_ensure_response(
						array(
							'count'    => RemovedStore::count(),
							'items'    => $items,
							'finished' => $finished,
							'failed'   => $failed,
						)
					);
	}

	/**
	 * «حذف» در لیست موارد حذف‌شده: درخواست حذف از آموزش‌ها به سرور هوش مصنوعی می‌رود (AiBackend::delete) و ردیف از لیست برداشته می‌شود.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function removed_delete( \WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! RemovedStore::exists( $id ) ) {
			return new \WP_Error( 'paia_not_found', __( 'این مورد پیدا نشد.', 'persian-ai-assistant' ), array( 'status' => 404 ) );
		}

		// درخواست حذف به سرور می‌رود؛ اگر ناموفق بود (سرور در دسترس نیست) ردیف در لیست می‌ماند تا مدیر دوباره تلاش کند.
		// اگر اتصال به سرور اصلاً تنظیم نشده، چیزی در سرور نیست که حذف شود.
		if ( AiBackend::is_configured() ) {
			if ( ! AiBackend::delete( $id ) ) {
				return new \WP_Error( 'paia_ai_delete_failed', __( 'حذف از دانش هوش مصنوعی ناموفق بود. جزئیات در تب «لاگ سرور» است؛ دوباره تلاش کنید.', 'persian-ai-assistant' ), array( 'status' => 502 ) );
			}

			// سرور درخواست را پذیرفت (۲۰۰ = ۱): ردیف «در حال حذف» می‌شود و بعد از وب‌هوک `untrained` از لیست می‌رود.
			RemovedStore::mark_deleting( $id );
			Reconciler::touch();

			return rest_ensure_response(
				array(
					'count'      => RemovedStore::count(),
					'processing' => true,
				)
			);
		}

		if ( ! RemovedStore::delete( $id ) ) {
			return new \WP_Error( 'paia_failed', __( 'ذخیره در پایگاه داده ناموفق بود.', 'persian-ai-assistant' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'count' => RemovedStore::count() ) );
	}

	/**
	 * «حذف آموزش» رکوردهایی که در سایت می‌مانند: فقط رکوردهای پردازش‌شده (۲) به سرور می‌روند؛ آن‌هایی که سرور پذیرفت ai_status = ۱
	 * (loading) می‌شوند و بعد از وب‌هوک `untrained` ۰ (دوباره قابل آموزش).
	 */
	public function untrain( \WP_REST_Request $request ): \WP_REST_Response {
		$ids      = array_values( array_unique( array_filter( array_map( 'strval', (array) $request->get_param( 'ids' ) ) ) ) );
		$eligible = array_keys(
			array_filter(
				Records::statuses( $ids ),
				static function ( $status ) {
					return Records::STATUS_DONE === (int) $status;
				}
			)
		);
		$accepted = $eligible ? AiBackend::untrain( $eligible ) : array();

		return rest_ensure_response(
			array(
				'accepted' => $accepted,
				'sent'     => count( $eligible ),
				'error'    => count( $accepted ) < count( $eligible ) ? AiBackend::last_error() : '',
			)
		);
	}

	public function auto_new(): \WP_REST_Response {
		$labels = self::category_labels();

		$items = array();
		foreach ( ItemStore::unseen_list( self::LIST_LIMIT ) as $row ) {
			$category = (string) strstr( $row['id'], ':', true );
			$title    = self::field( $row['head'], 'title' );

			$items[] = array(
				'id'      => $row['id'],
				'label'   => $labels[ $category ] ?? $category,
				'title'   => '' !== $title ? $title : $row['id'],
				'url'     => esc_url_raw( self::field( $row['head'], 'url' ) ),
				'trained' => $row['trained'],
				'processing' => $row['processing'],
			);
		}

		return rest_ensure_response(
			array(
				'count' => ItemStore::unseen_count(),
				'items' => $items,
			)
		);
	}

	public function auto_seen( \WP_REST_Request $request ): \WP_REST_Response {
		$marked = ItemStore::mark_seen( (array) $request->get_param( 'ids' ) );

		return rest_ensure_response(
			array(
				'marked' => $marked,
				'count'  => ItemStore::unseen_count(),
			)
		);
	}

	/**
	 * «آموزش به هوش مصنوعی» برای رکوردهای تیک‌خوردهٔ فرم: رکوردها به بک‌اند هوش مصنوعی فرستاده می‌شوند و فقط
	 * آن‌هایی که سرور پذیرفت (۲۰۰) ai_status = 1 (در حال پردازش؛ بعد از وب‌هوک ۲) و seen = 1 می‌شوند (از لیست خارج می‌شوند).
	 * تأییدنشده‌ها (مثلاً بک‌اند هنوز نیست) دست‌نخورده در لیست می‌مانند.
	 */
	public function auto_train( \WP_REST_Request $request ): \WP_REST_Response {
		$ids       = ItemStore::existing_ids( (array) $request->get_param( 'ids' ) );
		// AiBackend::train هر رکورد را می‌فرستد و آن‌هایی را که سرور پذیرفت (۲۰۰) «در حال پردازش» (ai_status = 1) می‌کند.
		$confirmed = $ids ? AiBackend::train( $ids ) : array();

		// فقط مواردی که سرور پذیرفته «دیده‌شده» می‌شوند و از فرم خارج می‌شوند؛ بقیه در لیست می‌مانند.
		$marked = ItemStore::mark_seen( $confirmed );

		return rest_ensure_response(
			array(
				'marked'  => $marked,
				'sent'    => count( $ids ),
				'trained' => count( $confirmed ),
				'count'   => ItemStore::unseen_count(),
				'error'   => count( $confirmed ) < count( $ids ) ? AiBackend::last_error() : '',
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function record( \WP_REST_Request $request ) {
		$row = ItemStore::get( (string) $request->get_param( 'id' ) );
		if ( null === $row ) {
			return new \WP_Error( 'paia_not_found', __( 'این مورد پیدا نشد.', 'persian-ai-assistant' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'id'      => (string) $request->get_param( 'id' ),
				'data'    => $row['data'],
				'trained' => $row['trained'],
				'processing' => $row['processing'],
			)
		);
	}

	/**
	 * مقدار یک کلید رشته‌ای از ابتدای JSON ذخیره‌شده (که ممکن است وسط بریده شده باشد).
	 */
	private static function field( string $head, string $key ): string {
		if ( 1 === preg_match( '/"' . preg_quote( $key, '/' ) . '":("(?:[^"\\\\]|\\\\.)*")/u', $head, $match ) ) {
			$value = json_decode( $match[1], true );

			return is_string( $value ) ? $value : '';
		}

		return '';
	}
}
