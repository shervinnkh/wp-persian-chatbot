<?php
namespace PersianWPChatbot\Apis\Endpoints;

use PersianWPChatbot\Apis\FileLink;
use PersianWPChatbot\Database\FileStore;
use PersianWPChatbot\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/pwc/v1/ai/file?id=file:…&exp=…&sig=…
 *
 * دانلود فایلِ «اسناد اضافه» برای سرور هوش مصنوعی با لینک امضادار و کوتاه‌عمر (Apis\FileLink).
 * احراز هویت = خودِ امضای لینک (نه هدر HMAC): سرور هوش مصنوعی لینک را از افزونه گرفته و فقط باز می‌کند.
 * فقط GET؛ فقط همان یک فایل؛ بعد از exp هیچ‌چیز نمی‌دهد. فایل به‌صورت attachment و octet-stream برمی‌گردد.
 */
final class FileEndpoint extends AbstractEndpoint {

	private const FAIL_THROTTLE = 'pwc_file_linkfail';

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/file',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'id'  => array(
						'required' => true,
						'type'     => 'string',
					),
					'exp' => array(
						'required' => true,
						'type'     => 'string',
					),
					'sig' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * به‌جای امضای هدر (AbstractEndpoint)، امضای خودِ لینک بررسی می‌شود.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request ) {
		$ok = FileLink::verify( (string) $request->get_param( 'id' ), (string) $request->get_param( 'exp' ), (string) $request->get_param( 'sig' ) );

		if ( is_wp_error( $ok ) && false === get_transient( self::FAIL_THROTTLE ) ) {
			// لاگ رد شدن‌ها حداکثر دقیقه‌ای یک‌بار تا مهاجم جدول لاگ را پر نکند.
			set_transient( self::FAIL_THROTTLE, 1, MINUTE_IN_SECONDS );
			LogStore::add(
				LogStore::ERROR,
				__( 'درخواست دانلود فایل با لینک نامعتبر یا منقضی رد شد.', 'persian-wp-chatbot' ),
				array(
					'method'      => 'GET',
					'endpoint'    => '/pwc/v1/ai/file',
					'http_status' => 403,
					'error'       => $ok->get_error_code(),
				)
			);
		}

		return $ok;
	}

	/**
	 * @return \WP_Error|void
	 */
	public function handle( \WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$row = FileStore::get( $id );
		$abs = $row ? FileStore::absolute_path( (string) $row['path'] ) : '';

		if ( '' === $abs || ! is_file( $abs ) || ! is_readable( $abs ) ) {
			return new \WP_Error( 'pwc_file_missing', __( 'فایل پیدا نشد.', 'persian-wp-chatbot' ), array( 'status' => 404 ) );
		}

		$ext  = strtolower( (string) $row['ext'] );
		$name = sanitize_file_name( (string) $row['original_name'] );
		if ( '' === $name ) {
			$name = 'file.' . $ext;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $abs ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
