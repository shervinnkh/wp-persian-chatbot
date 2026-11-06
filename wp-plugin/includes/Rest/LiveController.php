<?php
namespace PersianWPChatbot\Rest;

use PersianWPChatbot\Database\LiveStore;

defined( 'ABSPATH' ) || exit;

/**
 * تب «اطلاعات به‌روز»:
 * GET  /live                      فهرست (تنظیمات محصولات/وبلاگ‌ها/برگه‌ها + اطلاعات تعریف‌شده)
 * GET  /live/item?id=             یک مورد با متن کامل
 * POST /live/item {id?, name, description, content}   ساخت (id=0) یا ویرایش
 * POST /live/item/toggle {id, enabled}                فعال/غیرفعال
 * POST /live/item/delete {id}                         حذف
 * POST /live/source {source, fields:{price:bool,…}}   ذخیرهٔ تنظیمات اطلاعات به‌روز یک منبع (products | blog | pages)
 * POST /live/source/toggle {source, enabled}          فعال/غیرفعال کردن مورد «تمام محصولات/وبلاگ‌ها/برگه‌ها»
 */
final class LiveController extends AbstractController {

	public function register_routes(): void {
		$perm = array( $this, 'permissions_check' );
		$id   = array(
			'required'          => true,
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);

		register_rest_route( self::REST_NAMESPACE, '/live', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'index' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::REST_NAMESPACE, '/live/item', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'item' ),
				'permission_callback' => $perm,
				'args'                => array( 'id' => $id ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id'          => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'name'        => array( 'required' => true, 'type' => 'string' ),
					'description' => array( 'required' => true, 'type' => 'string' ),
					'content'     => array( 'required' => true, 'type' => 'string' ),
				),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/live/item/toggle', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'toggle' ),
			'permission_callback' => $perm,
			'args'                => array(
				'id'      => $id,
				'enabled' => array( 'required' => true, 'type' => 'boolean' ),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/live/item/delete', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'delete' ),
			'permission_callback' => $perm,
			'args'                => array( 'id' => $id ),
		) );

		$source = array(
			'required' => true,
			'type'     => 'string',
			'enum'     => LiveStore::sources(),
		);

		register_rest_route( self::REST_NAMESPACE, '/live/source', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'save_source' ),
			'permission_callback' => $perm,
			'args'                => array(
				'source' => $source,
				'fields' => array( 'required' => true, 'type' => 'object' ),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/live/source/toggle', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'toggle_source' ),
			'permission_callback' => $perm,
			'args'                => array(
				'source'  => $source,
				'enabled' => array( 'required' => true, 'type' => 'boolean' ),
			),
		) );
	}

	public function index() {
		return rest_ensure_response( LiveStore::overview() );
	}

	public function item( \WP_REST_Request $request ) {
		$item = LiveStore::get( (int) $request->get_param( 'id' ) );
		if ( null === $item ) {
			return $this->not_found();
		}

		return rest_ensure_response( $item );
	}

	public function save( \WP_REST_Request $request ) {
		$name        = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
		$description = trim( sanitize_textarea_field( (string) $request->get_param( 'description' ) ) );
		$content     = trim( sanitize_textarea_field( (string) $request->get_param( 'content' ) ) );

		if ( '' === $name || '' === $description || '' === $content ) {
			return new \WP_Error( 'pwc_live_empty', __( 'نام نوع داده، توضیح کوتاه و اطلاعات داده را کامل وارد کنید.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $name ) > LiveStore::NAME_MAX || mb_strlen( $description ) > LiveStore::DESC_MAX || mb_strlen( $content ) > LiveStore::CONTENT_MAX ) {
			return new \WP_Error( 'pwc_live_long', __( 'متن واردشده از حد مجاز بلندتر است.', 'persian-wp-chatbot' ), array( 'status' => 413 ) );
		}

		$id = LiveStore::save( (int) $request->get_param( 'id' ), $name, $description, $content );
		if ( $id <= 0 ) {
			return new \WP_Error( 'pwc_live_save_failed', __( 'ذخیره در پایگاه داده ناموفق بود.', 'persian-wp-chatbot' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'item' => LiveStore::get( $id ) ) );
	}

	public function toggle( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === LiveStore::get( $id ) ) {
			return $this->not_found();
		}
		if ( ! LiveStore::set_enabled( $id, (bool) $request->get_param( 'enabled' ) ) ) {
			return $this->failed();
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function delete( \WP_REST_Request $request ) {
		if ( ! LiveStore::delete( (int) $request->get_param( 'id' ) ) ) {
			return $this->failed();
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function save_source( \WP_REST_Request $request ) {
		$source = (string) $request->get_param( 'source' );
		if ( ! LiveStore::save_source( $source, (array) $request->get_param( 'fields' ) ) ) {
			return $this->failed();
		}

		return rest_ensure_response( array( 'source' => LiveStore::source( $source ) ) );
	}

	public function toggle_source( \WP_REST_Request $request ) {
		if ( ! LiveStore::set_source_enabled( (string) $request->get_param( 'source' ), (bool) $request->get_param( 'enabled' ) ) ) {
			return $this->failed();
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'pwc_live_not_found', __( 'این مورد پیدا نشد.', 'persian-wp-chatbot' ), array( 'status' => 404 ) );
	}

	private function failed(): \WP_Error {
		return new \WP_Error( 'pwc_live_failed', __( 'ذخیره در پایگاه داده ناموفق بود.', 'persian-wp-chatbot' ), array( 'status' => 500 ) );
	}
}
