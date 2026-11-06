<?php
namespace PersianWPChatbot\Rest;

use PersianWPChatbot\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * تب «لاگ سرور»:
 * GET /wp-json/pwc/v1/logs        آمار (تعداد کل/موفق/ناموفق) + صفحه‌ای از لاگ‌ها (تازه‌ترین اول): ?before=&limit=
 * GET /wp-json/pwc/v1/logs/item   متن کامل و جزئیات یک لاگ: ?id=
 */
final class LogsController extends AbstractController {

	private const PAGE = 30;

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'before' => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'  => array(
						'type'              => 'integer',
						'default'           => self::PAGE,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/logs/sync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/logs/item',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$page = LogStore::list( (int) $request->get_param( 'limit' ), (int) $request->get_param( 'before' ) );

		return rest_ensure_response(
			array(
				'stats'    => LogStore::stats(),
				'last_update' => \PersianWPChatbot\Apis\ServerLogs::last_update()['label'],
				'items'    => $page['items'],
				'has_more' => $page['has_more'],
			)
		);
	}

	/**
	 * دکمهٔ «به‌روزرسانی»: لاگ‌های تازهٔ سرور را می‌گیرد و ذخیره می‌کند.
	 */
	public function sync(): \WP_REST_Response {
		$result = \PersianWPChatbot\Apis\ServerLogs::sync();
		$result['last_update'] = \PersianWPChatbot\Apis\ServerLogs::last_update()['label'];
		$result['stats']       = LogStore::stats();

		return rest_ensure_response( $result );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function item( \WP_REST_Request $request ) {
		$log = LogStore::get( (int) $request->get_param( 'id' ) );
		if ( null === $log ) {
			return new \WP_Error( 'pwc_not_found', __( 'این لاگ پیدا نشد.', 'persian-wp-chatbot' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $log );
	}
}
