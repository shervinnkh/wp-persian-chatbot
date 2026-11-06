<?php
namespace PersianWPChatbot\Rest;

use PersianWPChatbot\Database\ItemStore;
use PersianWPChatbot\Extraction\Overview;
use PersianWPChatbot\Extraction\Registry;
use PersianWPChatbot\Extraction\Site;
use PersianWPChatbot\Extraction\Sources;
use PersianWPChatbot\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/pwc/v1/extraction       یک دستهٔ (batch) استخراج برای یک منبع: {source, offset, limit}
 * GET  /wp-json/pwc/v1/extraction/site  اطلاعات کلی سایت و محیط
 *
 * مرورگر منبع‌ها و دسته‌ها را پشت‌سرهم صدا می‌زند؛ بنابراین حتی روی سایت‌های بزرگ تایم‌اوت نمی‌شود.
 * هر چیزی که استخراج شود همان لحظه در جدول pwc_items ذخیره می‌شود (هر آیتم یک رکورد، ai_status = 0)
 * و شناسهٔ رکوردها (record_ids / taxonomies_record_id / record_id) در پاسخ برمی‌گردد.
 * هنوز هیچ دادهای به سرویس پایتون فرستاده نمی‌شود.
 */
final class ExtractionController extends AbstractController {

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'source' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => Sources::ids(),
						'sanitize_callback' => 'sanitize_key',
					),
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
					// new: فقط آیتم‌هایی که قبلاً در جدول نبوده‌اند ذخیره و برگردانده می‌شوند.
					'mode'   => array(
						'type'    => 'string',
						'enum'    => array( 'initial', 'new' ),
						'default' => 'initial',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/site',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'site' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		// خواندن اطلاعات ذخیره‌شده از جدول (برای دیالوگ «نمایش جزئیات»).
		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/saved',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'saved' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'source' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array_merge( array( 'site' ), Sources::ids() ),
						'sanitize_callback' => 'sanitize_key',
					),
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 100,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/saved-all',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'saved_all' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/extraction/settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'auto_new' => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		$source    = (string) $request->get_param( 'source' );
		$extractor = Registry::make( $source );

		if ( null === $extractor ) {
			return new \WP_Error(
				'pwc_unknown_source',
				__( 'برای این منبع هنوز استخراج‌کننده‌ای تعریف نشده است.', 'persian-wp-chatbot' ),
				array( 'status' => 400 )
			);
		}

		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$limit  = (int) $request->get_param( 'limit' );
		$limit  = $limit > 0 ? min( 50, $limit ) : $extractor->default_limit();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$started = microtime( true );
		try {
			$result = $extractor->extract( $offset, $limit );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'pwc_extraction_failed',
				/* translators: %s: error message */
				sprintf( __( 'استخراج با خطا متوقف شد: %s', 'persian-wp-chatbot' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}

		$category = Sources::record_category( $source );
		$is_new   = 'new' === $request->get_param( 'mode' );
		$items    = isset( $result['items'] ) ? (array) $result['items'] : array();

		if ( $is_new ) {
			// فقط آیتم‌هایی که قبلاً در جدول نبوده‌اند؛ دسته‌بندی‌ها دست‌نخورده می‌مانند.
			$all_ids = array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) ) {
					$all_ids[] = ItemStore::record_id( $category, $item['id'] );
				}
			}
			$known = array_flip( ItemStore::existing_ids( $all_ids ) );
			$fresh = array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) && ! isset( $known[ ItemStore::record_id( $category, $item['id'] ) ] ) ) {
					$fresh[] = $item;
				}
			}
			$result['skipped'] = count( $items ) - count( $fresh );
			$items             = $fresh;
			$result['items']   = $fresh;
			unset( $result['taxonomies'] );
		}

		$saved  = ItemStore::save_items( $category, $items );
		$failed = $saved['failed'];

		$result['record_ids'] = $saved['ids'];
		$touched              = count( array_filter( $saved['ids'] ) ) > 0;
		if ( ! empty( $result['taxonomies'] ) ) {
			$result['taxonomies_record_id'] = ItemStore::save_taxonomies( $category, (array) $result['taxonomies'] );
			if ( '' === $result['taxonomies_record_id'] ) {
				++$failed;
			} else {
				$touched = true;
			}
		}
		if ( $failed > 0 ) {
			$result['warnings']   = isset( $result['warnings'] ) ? (array) $result['warnings'] : array();
			/* translators: %d: number of items */
			$result['warnings'][] = sprintf( __( 'ذخیرهٔ %d مورد در پایگاه داده ناموفق بود.', 'persian-wp-chatbot' ), $failed );
		}
		if ( $touched ) {
			Overview::touch();
		}

		// رکوردهایی که قبلاً به هوش مصنوعی آموزش داده شده‌اند (به‌جای تیک، آیکن هوش مصنوعی نمایش داده می‌شود).
		$check                 = array_filter( $saved['ids'] );
		if ( ! empty( $result['taxonomies_record_id'] ) ) {
			$check[] = $result['taxonomies_record_id'];
		}
		$result['trained_ids']    = ItemStore::trained_ids( $check );
		$result['processing_ids'] = ItemStore::processing_ids( $check );

		$result['source']      = $source;
		$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		return rest_ensure_response( $result );
	}

	public function site(): \WP_REST_Response {
		$info      = Site::info();
		$record_id = ItemStore::save_site( $info );
		if ( '' !== $record_id ) {
			Overview::touch();
		}

		return rest_ensure_response(
			array(
				'info'        => $info,
				'record_id'   => $record_id,
				'trained_ids' => ItemStore::trained_ids( array( $record_id ) ),
				'processing_ids' => ItemStore::processing_ids( array( $record_id ) ),
			)
		);
	}

	public function overview(): \WP_REST_Response {
		return rest_ensure_response( Overview::build() );
	}

	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		Settings::update( array( 'auto_extract_new' => (bool) $request->get_param( 'auto_new' ) ) );

		return rest_ensure_response( Overview::build() );
	}

	/**
	 * GET /extraction/saved?source=site|products|blog|pages&offset=0&limit=100
	 * همان قالب پاسخ استخراج را برمی‌گرداند، اما از جدول pwc_items خوانده می‌شود.
	 */
	public function saved( \WP_REST_Request $request ): \WP_REST_Response {
		$source = (string) $request->get_param( 'source' );

		if ( 'site' === $source ) {
			return rest_ensure_response( $this->saved_site() );
		}

		return rest_ensure_response(
			$this->saved_source(
				$source,
				max( 0, (int) $request->get_param( 'offset' ) ),
				max( 1, min( 200, (int) $request->get_param( 'limit' ) ) )
			)
		);
	}

	/**
	 * GET /extraction/saved-all — اطلاعات سایت + اولین صفحهٔ همهٔ منبع‌ها در یک درخواست
	 * (هر درخواست REST کل وردپرس را بالا می‌آورد؛ یک درخواست به‌جای چند درخواست پشت‌سرهم).
	 */
	public function saved_all(): \WP_REST_Response {
		$sources = array();
		foreach ( Sources::ids() as $id ) {
			$sources[ $id ] = $this->saved_source( (string) $id, 0, 100 );
		}

		return rest_ensure_response(
			array(
				'site'    => $this->saved_site(),
				'sources' => $sources,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function saved_site(): array {
		$id  = ItemStore::record_id( 'site', get_current_blog_id() );
		$row = ItemStore::get( $id );

		return array(
			'info'        => $row ? $row['data'] : null,
			'record_id'   => $row ? $id : '',
			'trained_ids' => $row && $row['trained'] ? array( $id ) : array(),
			'processing_ids' => $row && $row['processing'] ? array( $id ) : array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function saved_source( string $source, int $offset, int $limit ): array {
		$category = Sources::record_category( $source );
		$page     = ItemStore::page( $category, $offset, $limit );
		$next     = $offset + $limit;
		$done     = $next >= $page['total'];

		$response = array(
			'source'      => $source,
			'items'       => $page['items'],
			'record_ids'  => $page['ids'],
			'trained_ids' => $page['trained'],
			'processing_ids' => $page['processing'],
			'total'       => $page['total'],
			'next_offset' => $done ? null : $next,
			'done'        => $done,
		);

		if ( 0 === $offset && $page['total'] > 0 ) {
			$tax_id = ItemStore::record_id( $category . '_taxonomies', 'all' );
			$row    = ItemStore::get( $tax_id );
			if ( $row && ! empty( $row['data']['taxonomies'] ) ) {
				$response['taxonomies']            = $row['data']['taxonomies'];
				$response['taxonomies_record_id'] = $tax_id;
				if ( $row['trained'] ) {
					$response['trained_ids'][] = $tax_id;
				} elseif ( $row['processing'] ) {
					$response['processing_ids'][] = $tax_id;
				}
			}
		}

		return $response;
	}
}
