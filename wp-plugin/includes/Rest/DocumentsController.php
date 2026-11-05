<?php
namespace PersianAIAssistant\Rest;

use PersianAIAssistant\Database\FileStore;
use PersianAIAssistant\Database\TextStore;
use PersianAIAssistant\Documents\FileUploader;
use PersianAIAssistant\Documents\Overview;
use PersianAIAssistant\Apis\Records;
use PersianAIAssistant\Sync\AiBackend;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /wp-json/paia/v1/documents/overview   آمار کارت «مطالب اضافه شده»
 * POST /wp-json/paia/v1/documents/texts      ذخیرهٔ یک باکس متن: {id?, chunks:[...], train}
 *
 * train = false → فقط «ذخیرهٔ پیش‌نویس» (draft = 1).
 * train = true  → اول در دیتابیس ذخیره می‌شود (draft = 0)، بعد برای آموزش به AiBackend داده می‌شود (فعلاً فقط جای فراخوانی است).
 *
 * «لیست مطالب» (تب متن‌ها / فایل‌ها):
 * GET  /documents/texts                 فهرست باکس‌های متن (عنوان = اولین خط)
 * GET  /documents/texts/item?id=        چانک‌های کامل یک باکس
 * POST /documents/texts/train {id}      ثبت برای آموزش + ارسال به AiBackend (ai_status ← ۱ بعد از ۲۰۰ سرور؛ ← ۲ با وب‌هوک نتیجه)
 * POST /documents/texts/delete {id}     حذف کامل رکورد از دیتابیس
 * GET  /documents/files                 فهرست فایل‌ها
 * POST /documents/files                 آپلود (multipart: file, title?) — حداکثر ۱۰۰ مگابایت؛ pdf, doc, docx, txt
 * POST /documents/files/train {id}      ارسال به AiBackend
 * POST /documents/files/delete {id}     حذف کامل: فایل از سایت پاک و رکورد از دیتابیس حذف می‌شود
 *
 * «حذف آموزش» (حذف از دانش هوش مصنوعی) عمداً REST ندارد: فقط در نمایش اعمال می‌شود و ai_status صفر نمی‌شود.
 */
final class DocumentsController extends AbstractController {

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/texts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_text' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'chunks' => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'maxItems' => TextStore::MAX_CHUNKS,
					),
					'train'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		$this->register_list_routes();
	}

	private function register_list_routes(): void {
		$id_arg = array(
			'id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/texts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_texts' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/texts/item',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'text_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/texts/train',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'train_text' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/texts/delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'delete_text' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/files',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_files' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_file' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'title' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/files/train',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'train_file' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/files/download',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_file' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/files/delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'delete_file' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $id_arg,
			)
		);
	}

	public function list_texts(): \WP_REST_Response {
		return rest_ensure_response( array( 'items' => TextStore::list() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function text_item( \WP_REST_Request $request ) {
		$id   = (string) $request->get_param( 'id' );
		$item = 1 === preg_match( '/^text:\d+$/', $id ) ? TextStore::get( $id ) : null;
		if ( null === $item ) {
			return new \WP_Error( 'paia_not_found', __( 'این متن پیدا نشد.', 'persian-ai-assistant' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $item );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function train_text( \WP_REST_Request $request ) {
		$id   = (string) $request->get_param( 'id' );
		$item = 1 === preg_match( '/^text:\d+$/', $id ) ? TextStore::get( $id ) : null;
		if ( null === $item || ! $item['chunks'] ) {
			return new \WP_Error( 'paia_not_found', __( 'این متن پیدا نشد.', 'persian-ai-assistant' ), array( 'status' => 404 ) );
		}

		// پیش‌نویس به «ثبت‌شده برای آموزش» تبدیل می‌شود؛ ai_status را AiBackend::send بعد از ۲۰۰ سرور ۱ می‌کند (در حال پردازش) و وب‌هوک ۲.
		TextStore::publish( $id );

		$chunks = TextStore::normalize( $item['chunks'] );
		AiBackend::send(
			$id,
			array(
				'type'   => 'text',
				'chunks' => $chunks,
				'text'   => implode( "\n\n", $chunks ),
			),
			$item['trained'] || $item['processing'] ? AiBackend::EVENT_UPDATE : AiBackend::EVENT_CREATE
		);

		$fresh = TextStore::get( $id );

		return rest_ensure_response(
			array(
				'id'         => $id,
				'trained'    => null !== $fresh && $fresh['trained'],
				'processing' => null !== $fresh && $fresh['processing'],
				'overview'   => Overview::build(),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_text( \WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( 1 === preg_match( '/^text:\d+$/', $id ) ) {
			$blocked = $this->forget_in_ai( $id );
			if ( null !== $blocked ) {
				return $blocked;
			}
		}
		if ( 1 !== preg_match( '/^text:\d+$/', $id ) || ! TextStore::delete( $id ) ) {
			return new \WP_Error( 'paia_delete_failed', __( 'حذف متن ناموفق بود.', 'persian-ai-assistant' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'id'       => $id,
				'overview' => Overview::build(),
			)
		);
	}

	public function list_files(): \WP_REST_Response {
		return rest_ensure_response( array( 'items' => FileStore::list() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_file( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array();
		if ( ! $file ) {
			return new \WP_Error( 'paia_upload_none', __( 'فایلی ارسال نشد. اگر حجم فایل زیاد است، ممکن است از محدودیت آپلود سرور بیشتر باشد.', 'persian-ai-assistant' ), array( 'status' => 400 ) );
		}

		$record = FileUploader::handle( $file, (string) $request->get_param( 'title' ) );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		return rest_ensure_response(
			array(
				'item'     => $record,
				'overview' => Overview::build(),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function train_file( \WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$row = 1 === preg_match( '/^file:\d+$/', $id ) ? FileStore::get( $id ) : null;
		if ( null === $row ) {
			return new \WP_Error( 'paia_not_found', __( 'این فایل پیدا نشد.', 'persian-ai-assistant' ), array( 'status' => 404 ) );
		}

		$item    = FileStore::present( $row );
		$payload = AiBackend::file_payload( $row ); // شامل لینک دانلود امضادار و کوتاه‌عمر برای سرور هوش مصنوعی.
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		AiBackend::send(
			$id,
			$payload,
			$item['trained'] || $item['processing'] ? AiBackend::EVENT_UPDATE : AiBackend::EVENT_CREATE
		);

		$fresh = FileStore::get( $id );

		return rest_ensure_response(
			array(
				'id'         => $id,
				'trained'    => null !== $fresh && 2 === (int) $fresh['ai_status'],
				'processing' => null !== $fresh && 1 === (int) $fresh['ai_status'],
				'overview'   => Overview::build(),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	/**
	 * دانلود فایل آپلودشده (فقط مدیر؛ لینک با _wpnonce ساخته می‌شود). فایل مستقیم روی دیسک محافظت‌شده است.
	 *
	 * @return \WP_Error|void
	 */
	public function download_file( \WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$row = 1 === preg_match( '/^file:\d+$/', $id ) ? FileStore::get( $id ) : null;
		$abs = $row ? FileStore::absolute_path( (string) $row['path'] ) : '';

		if ( '' === $abs || ! is_file( $abs ) || ! is_readable( $abs ) ) {
			return new \WP_Error( 'paia_file_missing', __( 'فایل پیدا نشد.', 'persian-ai-assistant' ), array( 'status' => 404 ) );
		}

		$ext   = strtolower( (string) $row['ext'] );
		$types = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'txt'  => 'text/plain; charset=utf-8',
		);
		$name  = sanitize_file_name( (string) $row['original_name'] );
		if ( '' === $name ) {
			$name = 'file.' . $ext;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( $types[ $ext ] ?? 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode( (string) $row['original_name'] ) );
		header( 'Content-Length: ' . (string) filesize( $abs ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public function delete_file( \WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( 1 === preg_match( '/^file:\d+$/', $id ) ) {
			$blocked = $this->forget_in_ai( $id );
			if ( null !== $blocked ) {
				return $blocked;
			}
		}
		if ( 1 !== preg_match( '/^file:\d+$/', $id ) || ! FileStore::delete( $id ) ) {
			return new \WP_Error( 'paia_delete_failed', __( 'حذف فایل ناموفق بود.', 'persian-ai-assistant' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'id'       => $id,
				'overview' => Overview::build(),
			)
		);
	}

	/**
	 * پیش از حذف محلیِ یک متن/فایل، دانش مربوطه از سرور هوش مصنوعی پاک می‌شود (وگرنه هوش مصنوعی هنوز از متن حذف‌شده جواب می‌دهد).
	 *
	 * - رکوردی که به سرور رفته (ai_status ≥ 1): اگر حذف از سرور ناموفق بود، رکورد محلی نگه داشته می‌شود و خطا برمی‌گردد.
	 * - رکوردی که هیچ‌وقت نرفته (۰): حذف از سرور فقط «تلاش» می‌شود (ممکن است نسخهٔ قدیمی‌اش آنجا مانده باشد) و شکستش مانع حذف نیست.
	 * - اتصال به سرور تنظیم نشده: چیزی در سرور نیست.
	 *
	 * @return \WP_Error|null null = ادامه بده و محلی را حذف کن.
	 */
	private function forget_in_ai( string $id ): ?\WP_Error {
		if ( ! AiBackend::is_configured() ) {
			return null;
		}

		$status = (int) ( Records::statuses( array( $id ) )[ $id ] ?? 0 );
		$ok     = AiBackend::delete( $id );

		if ( ! $ok && $status >= Records::STATUS_PROCESSING ) {
			return new \WP_Error( 'paia_ai_delete_failed', __( 'حذف از دانش هوش مصنوعی ناموفق بود. جزئیات در صفحهٔ «لاگ» است؛ دوباره تلاش کنید.', 'persian-ai-assistant' ), array( 'status' => 502 ) );
		}

		return null;
	}

	public function overview(): \WP_REST_Response {
		return rest_ensure_response( Overview::build() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_text( \WP_REST_Request $request ) {
		$chunks = TextStore::normalize( (array) $request->get_param( 'chunks' ) );
		if ( ! $chunks ) {
			return new \WP_Error( 'paia_empty_text', __( 'متنی برای ذخیره وارد نشده است.', 'persian-ai-assistant' ), array( 'status' => 400 ) );
		}
		if ( TextStore::total_chars( $chunks ) > TextStore::MAX_CHARS ) {
			return new \WP_Error( 'paia_text_too_long', __( 'حجم متن بیش از حد مجاز است. آن را در چند بار ذخیره کنید.', 'persian-ai-assistant' ), array( 'status' => 413 ) );
		}

		$train = (bool) $request->get_param( 'train' );

		// اول ذخیره در دیتابیس، بعد (در صورت درخواست آموزش) ارسال برای هوش مصنوعی.
		$saved = TextStore::save( (string) $request->get_param( 'id' ), $chunks, ! $train );
		if ( ! $saved['ok'] ) {
			return new \WP_Error( 'paia_save_failed', __( 'ذخیرهٔ متن در پایگاه داده ناموفق بود.', 'persian-ai-assistant' ), array( 'status' => 500 ) );
		}

		if ( $train ) {
			AiBackend::send( $saved['id'], $saved['data'], AiBackend::EVENT_CREATE );
		}

		return rest_ensure_response(
			array(
				'id'       => $saved['id'],
				'draft'    => ! $train,
				'queued'   => $train,
				'chunks'   => count( $chunks ),
				'overview' => Overview::build(),
			)
		);
	}
}
