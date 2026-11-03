<?php
/**
 * REST مدیریت چت (فقط مدیران) — namespace: paia/v1
 *
 *  GET  /chat-admin/conversations   فهرست همهٔ گفتگوها به ترتیب: خوانده‌نشده ← مارک‌شده ← تاریخ (فیلتر تگ‌ها سمت کلاینت). با پارامتر sig اگر چیزی عوض نشده باشد فقط {changed:false} برمی‌گردد
 *  GET  /chat-admin/conversation    پیام‌ها و اطلاعات یک گفتگو (polling با پارامتر sig)
 *  POST /chat-admin/send            ارسال پیام به‌عنوان پشتیبان (متن و/یا عکس/ویس؛ multipart با فیلد file). نام حساب وردپرس زیر پیام نمایش داده می‌شود
 *  POST /chat-admin/delete-message  حذف پیام ارسالیِ خود پشتیبان
 *  POST /chat-admin/clear           حذف کل تاریخچهٔ یک گفتگو
 *  POST /chat-admin/flags           مارک کردن / خاموش‌کردن پاسخ هوش مصنوعی برای یک گفتگو
 *  POST /chat-admin/typing          اعلام «پشتیبان در حال تایپ است»
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Support\Capability;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class AdminChatController {

	private const NS = 'paia/v1';

	/** @var ChatStore */
	private $store;

	public function __construct() {
		$this->store = new ChatStore();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$perm = array( $this, 'permission' );
		$uid  = array(
			'type'     => 'string',
			'required' => true,
		);

		register_rest_route(
			self::NS,
			'/chat-admin/conversations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'conversations' ),
				'permission_callback' => $perm,
				'args'                => array(
					'sig' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/chat-admin/conversation',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'conversation' ),
				'permission_callback' => $perm,
				'args'                => array(
					'uid'  => $uid,
					'sig'  => array( 'type' => 'string', 'default' => '' ),
					'read' => array( 'type' => 'boolean', 'default' => true ),
					'full' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/chat-admin/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $perm,
				'args'                => array(
					'uid'  => $uid,
					'text' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/chat-admin/delete-message',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete_message' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/chat-admin/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clear' ),
				'permission_callback' => $perm,
				'args'                => array( 'uid' => $uid ),
			)
		);
		register_rest_route(
			self::NS,
			'/chat-admin/flags',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'flags' ),
				'permission_callback' => $perm,
				'args'                => array(
					'uid'      => $uid,
					'marked'   => array( 'type' => 'boolean' ),
					'ai_muted' => array( 'type' => 'boolean' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/chat-admin/typing',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'typing' ),
				'permission_callback' => $perm,
				'args'                => array( 'uid' => $uid ),
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public function permission() {
		if ( Capability::current_user_can() ) {
			return true;
		}
		return new WP_Error(
			'paia_forbidden',
			__( 'شما اجازهٔ انجام این کار را ندارید.', 'persian-ai-assistant' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @return object|WP_Error
	 */
	private function visitor_from( WP_REST_Request $request ) {
		$uid = strtolower( trim( (string) $request->get_param( 'uid' ) ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uid ) ) {
			return new WP_Error( 'paia_uid', __( 'شناسهٔ گفتگو معتبر نیست.', 'persian-ai-assistant' ), array( 'status' => 400 ) );
		}
		$visitor = $this->store->visitor( $uid );
		if ( ! $visitor ) {
			return new WP_Error( 'paia_not_found', __( 'گفتگو پیدا نشد.', 'persian-ai-assistant' ), array( 'status' => 404 ) );
		}
		return $visitor;
	}

	private function iso( string $mysql_gmt ): string {
		return gmdate( 'c', (int) strtotime( $mysql_gmt . ' UTC' ) );
	}

	private function display_name( object $visitor ): string {
		if ( '' !== (string) $visitor->name ) {
			return (string) $visitor->name;
		}
		if ( (int) $visitor->wp_user_id > 0 ) {
			$user = get_userdata( (int) $visitor->wp_user_id );
			if ( $user ) {
				return (string) $user->display_name;
			}
		}
		/* translators: %s: first characters of the visitor id */
		return sprintf( __( 'مهمان %s', 'persian-ai-assistant' ), strtoupper( substr( (string) $visitor->uid, 0, 4 ) ) );
	}

	private function admin_name(): string {
		$user = wp_get_current_user();
		$name = $user && $user->exists() ? (string) $user->display_name : '';
		if ( '' === $name && $user && $user->exists() ) {
			$name = (string) $user->user_login;
		}
		return '' !== $name ? $name : __( 'پشتیبانی', 'persian-ai-assistant' );
	}

	/**
	 * شناسهٔ آخرین پیام کاربر در گفتگو.
	 */
	private function last_user_message_id( string $uid, int $user_id ): int {
		global $wpdb;
		$table = ChatSchema::messages_table();
		$where = ChatStore::thread_where( 'm', $uid, $user_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT MAX(m.id) FROM {$table} m WHERE {$where} AND m.role = 'user'" );
	}

	private function mark_read( object $visitor ): void {
		global $wpdb;
		$last = $this->last_user_message_id( (string) $visitor->uid, (int) $visitor->wp_user_id );
		if ( $last > (int) $visitor->admin_read_id ) {
			// همهٔ ردیف‌های بازدیدکنندهٔ همین کاربر وردپرس (اگر چند مرورگر داشته) با هم خوانده‌شده می‌شوند.
			$table = ChatSchema::visitors_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET admin_read_id = %d WHERE admin_read_id < %d AND ( uid = %s OR ( wp_user_id > 0 AND wp_user_id = %d ) )", $last, $last, (string) $visitor->uid, (int) $visitor->wp_user_id ) );
			Unread::flush();
		}
	}

	/**
	 * امضای سبک کل فهرست گفتگوها: آخرین پیام، تعداد پیام‌ها و مجموع وضعیت‌ها (خوانده‌شدن، مارک، خاموشی هوش مصنوعی، نام/تماس).
	 * اگر با امضای کلاینت یکی باشد، نیازی به ساختن و فرستادن فهرست نیست.
	 */
	private function list_sig(): string {
		global $wpdb;
		$m = ChatSchema::messages_table();
		$v = ChatSchema::visitors_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$a = $wpdb->get_row( "SELECT MAX(id) AS last_id, COUNT(*) AS total FROM {$m}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$b = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(admin_read_id),0) AS r, COALESCE(SUM(marked),0) AS k, COALESCE(SUM(ai_muted),0) AS a, COALESCE(SUM(wp_user_id + profile_done + CHAR_LENGTH(name) + CHAR_LENGTH(contact)),0) AS p FROM {$v}" );
		return implode(
			':',
			array(
				$a ? (int) $a->last_id : 0,
				$a ? (int) $a->total : 0,
				$b ? (int) $b->n : 0,
				$b ? (int) $b->r : 0,
				$b ? (int) $b->k : 0,
				$b ? (int) $b->a : 0,
				$b ? (int) $b->p : 0,
			)
		);
	}

	/* ------------------------------------------------------------------ */

	public function conversations( WP_REST_Request $request ) {
		global $wpdb;
		$v = ChatSchema::visitors_table();
		$m = ChatSchema::messages_table();

		// امضا پیش از ساخت فهرست گرفته می‌شود تا تغییرِ هم‌زمان در polling بعدی از دست نرود.
		$list_sig = $this->list_sig();
		if ( '' !== (string) $request->get_param( 'sig' ) && (string) $request->get_param( 'sig' ) === $list_sig ) {
			return rest_ensure_response(
				array(
					'changed' => false,
					'sig'     => $list_sig,
				)
			);
		}

		$cond_any  = '(m.visitor_uid = v.uid OR (v.wp_user_id > 0 AND m.wp_user_id = v.wp_user_id))';
		$sql       = "SELECT v.*,
			(SELECT MAX(m.id) FROM {$m} m WHERE {$cond_any}) AS last_id,
			(SELECT COUNT(*) FROM {$m} m WHERE {$cond_any} AND m.role = 'user' AND m.id > v.admin_read_id) AS unread
			FROM {$v} v ORDER BY v.last_seen DESC LIMIT 500";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $sql );

		// هر کاربر وردپرس فقط یک بار (اگر چند ردیف بازدیدکننده داشته باشد).
		$items = array();
		foreach ( $rows as $row ) {
			if ( null === $row->last_id ) {
				continue; // هنوز پیامی ندارد.
			}
			$key = (int) $row->wp_user_id > 0 ? 'u' . (int) $row->wp_user_id : (string) $row->uid;
			if ( ! isset( $items[ $key ] ) || (int) $row->last_id > (int) $items[ $key ]->last_id ) {
				$items[ $key ] = $row;
			}
		}
		$items = array_values( $items );

		// خلاصهٔ آخرین پیام هر گفتگو.
		$last_ids = array_map(
			static function ( $row ) {
				return (int) $row->last_id;
			},
			$items
		);
		$last_map = array();
		if ( $last_ids ) {
			$in = implode( ',', array_map( 'intval', $last_ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$m} WHERE id IN ({$in})" ) as $msg ) {
				$last_map[ (int) $msg->id ] = $this->store->format( $msg, false ); // خلاصهٔ فهرست: بدون پرس‌وجوی کارت لینک
			}
		}

		$counts       = array(
			'all'    => count( $items ),
			'unread' => 0,
			'marked' => 0,
		);
		$total_unread = 0;
		$list   = array();
		foreach ( $items as $row ) {
			$unread = (int) $row->unread;
			$marked = (int) $row->marked > 0;
			if ( $unread > 0 ) {
				++$counts['unread'];
				$total_unread += $unread;
			}
			if ( $marked ) {
				++$counts['marked'];
			}
			$last   = $last_map[ (int) $row->last_id ] ?? null;
			$list[] = array(
				'uid'        => (string) $row->uid,
				'name'       => $this->display_name( $row ),
				'contact'    => (string) $row->contact,
				'registered' => (int) $row->wp_user_id > 0,
				'marked'     => $marked,
				'ai_muted'   => (int) $row->ai_muted > 0,
				'unread'     => $unread,
				'last_id'    => (int) $row->last_id,
				'last'       => $last ? array(
					'role'       => $last['role'],
					'text'       => mb_substr( (string) $last['text'], 0, 120 ),
					'attachment' => $last['attachment'] ? $last['attachment']['type'] : '',
					'time'       => $last['time'],
					'admin_name' => $last['admin_name'],
				) : null,
			);
		}

		// فیلتر تگ‌ها (خوانده‌نشده / مارک‌شده با شرط «و») سمت کلاینت و آنی انجام می‌شود تا نیازی به انتظار برای سرور نباشد.

		// ترتیب: ۱) خوانده‌نشده‌ها ۲) مارک‌شده‌ها ۳) تاریخ آخرین پیام.
		usort(
			$list,
			static function ( $a, $b ) {
				$ua = $a['unread'] > 0 ? 1 : 0;
				$ub = $b['unread'] > 0 ? 1 : 0;
				if ( $ua !== $ub ) {
					return $ub - $ua;
				}
				$ma = $a['marked'] ? 1 : 0;
				$mb = $b['marked'] ? 1 : 0;
				if ( $ma !== $mb ) {
					return $mb - $ma;
				}
				return $b['last_id'] <=> $a['last_id'];
			}
		);

		return rest_ensure_response(
			array(
				'changed'      => true,
				'sig'          => $list_sig,
				'items'        => $list,
				'counts'       => $counts,
				'total_unread' => $total_unread,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function info( object $visitor ): array {
		$info = array(
			'name'       => $this->display_name( $visitor ),
			'contact'    => (string) $visitor->contact,
			'registered' => (int) $visitor->wp_user_id > 0,
			'first_seen' => $this->iso( (string) $visitor->created_at ),
			'last_seen'  => $this->iso( (string) $visitor->last_seen ),
			'user'       => null,
		);

		if ( (int) $visitor->wp_user_id > 0 ) {
			$user = get_userdata( (int) $visitor->wp_user_id );
			if ( $user ) {
				$names = wp_roles()->get_names();
				$roles = array();
				foreach ( (array) $user->roles as $role ) {
					$roles[] = isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
				}
				$info['user'] = array(
					'id'           => (int) $user->ID,
					'login'        => (string) $user->user_login,
					'display_name' => (string) $user->display_name,
					'email'        => (string) $user->user_email,
					'registered'   => $user->user_registered ? $this->iso( (string) $user->user_registered ) : '',
					'roles'        => $roles,
					'edit_url'     => (string) get_edit_user_link( $user->ID ),
				);
			}
		}
		return $info;
	}

	public function conversation( WP_REST_Request $request ) {
		$visitor = $this->visitor_from( $request );
		if ( is_wp_error( $visitor ) ) {
			return $visitor;
		}
		$uid     = (string) $visitor->uid;
		$user_id = (int) $visitor->wp_user_id;

		$sig     = $this->store->sig( $uid, $user_id );
		$changed = $sig !== (string) $request->get_param( 'sig' );
		$full    = (bool) $request->get_param( 'full' ); // باز کردن گفتگو (نه polling)

		// در polling سبک اگر چیزی عوض نشده، علامت خوانده‌شدن و اطلاعات کاربر دوباره حساب نمی‌شود.
		if ( $request->get_param( 'read' ) && ( $changed || $full ) ) {
			$this->mark_read( $visitor );
		}

		$out = array(
			'uid'      => $uid,
			'sig'      => $sig,
			'changed'  => $changed,
			'marked'   => (int) $visitor->marked > 0,
			'ai_muted' => (int) $visitor->ai_muted > 0,
			'typing'   => $this->store->typing( $uid )['user'],
		);
		if ( $changed || $full ) {
			$out['info'] = $this->info( $visitor );
		}
		if ( $changed ) {
			$out['messages'] = $this->store->thread( $uid, $user_id, 200 );
		}
		return rest_ensure_response( $out );
	}

	public function send( WP_REST_Request $request ) {
		$visitor = $this->visitor_from( $request );
		if ( is_wp_error( $visitor ) ) {
			return $visitor;
		}
		$text = trim( sanitize_textarea_field( (string) $request->get_param( 'text' ) ) );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2000 ) : substr( $text, 0, 2000 );

		$files = $request->get_file_params();
		$file  = ( isset( $files['file'] ) && is_array( $files['file'] ) && ! empty( $files['file']['tmp_name'] ) ) ? $files['file'] : null;

		if ( '' === $text && ! $file ) {
			return new WP_Error( 'paia_empty', __( 'پیام خالی است.', 'persian-ai-assistant' ), array( 'status' => 400 ) );
		}

		// پشتیبان مثل کاربر می‌تواند عکس و ویس بفرستد (تیک‌های «ارسال عکس/ویس» فقط برای بازدیدکننده است).
		$attach = array();
		if ( $file ) {
			$attach = Uploads::handle( $file, false );
			if ( is_wp_error( $attach ) ) {
				return $attach;
			}
		}

		$uid     = (string) $visitor->uid;
		$user_id = (int) $visitor->wp_user_id;
		$message = $this->store->add_message(
			$uid,
			$user_id,
			array(
				'role'          => 'admin',
				'content'       => $text,
				'attachment'    => $attach,
				'admin_user_id' => get_current_user_id(),
				'admin_name'    => $this->admin_name(),
			)
		);
		if ( ! $message ) {
			return new WP_Error( 'paia_db', __( 'ذخیرهٔ پیام ناموفق بود.', 'persian-ai-assistant' ), array( 'status' => 500 ) );
		}

		$this->store->clear_admin_typing( $uid );
		$this->mark_read( $visitor );

		// پیام پشتیبان در گفتگوی سرور هوش مصنوعی هم ذخیره می‌شود (شکست آن، ارسال پیام را نمی‌شکند).
		try {
			( new ReplyEngine() )->mirror_admin( $uid, $user_id, $text, $attach, get_current_user_id(), $this->admin_name() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return rest_ensure_response(
			array(
				'message' => $message,
				'sig'     => $this->store->sig( $uid, $user_id ),
			)
		);
	}

	public function delete_message( WP_REST_Request $request ) {
		global $wpdb;
		$id    = (int) $request->get_param( 'id' );
		$table = ChatSchema::messages_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new WP_Error( 'paia_not_found', __( 'پیام پیدا نشد.', 'persian-ai-assistant' ), array( 'status' => 404 ) );
		}
		// فقط پیام‌های ارسالیِ خودِ همین پشتیبان.
		if ( 'admin' !== $row->role || (int) $row->admin_user_id !== get_current_user_id() ) {
			return new WP_Error( 'paia_not_own', __( 'فقط پیام‌هایی که خودتان فرستاده‌اید قابل حذف است.', 'persian-ai-assistant' ), array( 'status' => 403 ) );
		}
		// فایل پیوست (عکس/ویس) هم پاک می‌شود، فقط داخل پوشهٔ paia-chat.
		if ( ! empty( $row->attachment_path ) ) {
			$uploads = wp_upload_dir();
			$base    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'paia-chat/' );
			$norm    = wp_normalize_path( (string) $row->attachment_path );
			if ( 0 === strpos( $norm, $base ) && is_file( $norm ) ) {
				wp_delete_file( $norm );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'id' => $id ) );
		Unread::flush();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function clear( WP_REST_Request $request ) {
		global $wpdb;
		$visitor = $this->visitor_from( $request );
		if ( is_wp_error( $visitor ) ) {
			return $visitor;
		}
		$uid     = (string) $visitor->uid;
		$user_id = (int) $visitor->wp_user_id;
		$table   = ChatSchema::messages_table();
		$where   = ChatStore::thread_where( 'm', $uid, $user_id );

		// فایل‌های پیوست (عکس/ویس) هم پاک می‌شوند، فقط داخل پوشهٔ paia-chat.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$paths   = (array) $wpdb->get_col( "SELECT m.attachment_path FROM {$table} m WHERE {$where} AND m.attachment_path <> ''" );
		$uploads = wp_upload_dir();
		$base    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'paia-chat/' );
		foreach ( $paths as $path ) {
			$norm = wp_normalize_path( (string) $path );
			if ( 0 === strpos( $norm, $base ) && is_file( $norm ) ) {
				wp_delete_file( $norm );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE m FROM {$table} m WHERE {$where}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( ChatSchema::visitors_table(), array( 'admin_read_id' => 0 ), array( 'uid' => $uid ) );
		$this->store->clear_user_typing( $uid );
		$this->store->clear_admin_typing( $uid );
		Unread::flush();

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function flags( WP_REST_Request $request ) {
		global $wpdb;
		$visitor = $this->visitor_from( $request );
		if ( is_wp_error( $visitor ) ) {
			return $visitor;
		}
		$data = array();
		if ( null !== $request->get_param( 'marked' ) ) {
			$data['marked'] = $request->get_param( 'marked' ) ? 1 : 0;
		}
		if ( null !== $request->get_param( 'ai_muted' ) ) {
			$data['ai_muted'] = $request->get_param( 'ai_muted' ) ? 1 : 0;
		}
		if ( $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( ChatSchema::visitors_table(), $data, array( 'uid' => $visitor->uid ) );
		}
		$fresh = $this->store->visitor( (string) $visitor->uid );
		return rest_ensure_response(
			array(
				'marked'   => $fresh && (int) $fresh->marked > 0,
				'ai_muted' => $fresh && (int) $fresh->ai_muted > 0,
			)
		);
	}

	public function typing( WP_REST_Request $request ) {
		$visitor = $this->visitor_from( $request );
		if ( is_wp_error( $visitor ) ) {
			return $visitor;
		}
		$this->store->touch_admin_typing( (string) $visitor->uid, $this->admin_name() );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
