<?php
/**
 * REST عمومی چت (namespace: pwc/v1)
 *
 *  GET  /chat/init     بارگذاری هویت، تاریخچه و پیشنهادها
 *  POST /chat/profile  ثبت نام/تماس (یا ادامه به‌عنوان مهمان)
 *  POST /chat/message  ارسال پیام (متن، عکس یا ویس) + دریافت پاسخ
 *  GET  /chat/history  صفحهٔ پیام‌های قدیمی‌تر (before = شناسهٔ قدیمی‌ترین پیام نمایش‌داده‌شده؛ ۱۰ تا ۱۰ تا)
 *  GET  /chat/poll     به‌روزرسانی دوره‌ای (پیام‌های جدید/حذف‌شده، تایپینگ پشتیبان، وضعیت آنلاین)
 *  POST /chat/typing   اعلام «کاربر در حال تایپ است»
 *
 * هویت: هدر X-PWC-Visitor (UUID). برای کاربر واردشده، کاربر وردپرس ملاک است.
 *
 * امنیت/سبک‌سازی (۰.۵.۲۸):
 *  - مسیرهای GET و poll/typing/history هرگز بازدیدکننده نمی‌سازند (رفع S-1)؛ ردیف فقط هنگام ارسال پیام یا ذخیرهٔ پروفایل ساخته می‌شود
 *    (با سقف نرخ ساخت برای هر IP). uid ناشناخته = «بازدیدکنندهٔ خالی» و هیچ دادهٔ دیگری برنمی‌گردد.
 *  - poll فقط پیام‌های همان بازدیدکننده را برمی‌گرداند (uid پارامتر دسترسی نیست؛ UUID تصادفی ۱۲۲ بیتی که فقط مرورگر همان کاربر دارد)،
 *    سقف نرخ دارد (۴۲۹ + Retry-After) و حداکثر ۵۰ پیام در هر پاسخ می‌دهد.
 *
 * @package PersianWPChatbot
 */

namespace PersianWPChatbot\Chat;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class ChatController {

	/** تعداد پیام در هر بار بارگذاری تاریخچه (۱۰ پیام آخر؛ با اسکرول به بالا ۱۰ تای قبلی). */
	public const PAGE_SIZE = 10;

	/** حداکثر تعداد پیام در هر پاسخ poll. */
	public const POLL_MAX = 50;

	/** @var ChatStore */
	private $store;

	/** @var Responder */
	private $responder;

	public function __construct() {
		$this->store     = new ChatStore();
		$this->responder = new Responder();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		// بعد از بررسی کوکی/nonce هستهٔ وردپرس اجرا شود (اولویت بالا).
		add_filter( 'rest_authentication_errors', array( $this, 'tolerate_stale_nonce' ), 200 );
	}

	/**
	 * مسیرهای عمومی چت (/pwc/v1/chat/*) با شناسهٔ بازدیدکننده (X-PWC-Visitor) کار می‌کنند، نه با nonce.
	 * nonce داخل HTML صفحه چاپ می‌شود و با کش صفحه، تغییر نشست ورود یا انقضا نامعتبر می‌شود و
	 * وردپرس خطای 403 «rest_cookie_invalid_nonce» می‌دهد. برای همین‌ها خطا را نادیده می‌گیریم و
	 * درخواست را مهمان حساب می‌کنیم (مسیرهای مدیریتی /chat-admin/* و بقیهٔ API دست‌نخورده می‌مانند).
	 *
	 * @param mixed $result نتیجهٔ بررسی‌های قبلی احراز هویت.
	 * @return mixed
	 */
	public function tolerate_stale_nonce( $result ) {
		if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
			return $result;
		}

		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( '' === $route ) {
			$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
			$pos   = strpos( $path, '/' . rest_get_url_prefix() . '/' );
			$route = false !== $pos ? substr( $path, $pos + strlen( rest_get_url_prefix() ) + 1 ) : '';
		}
		$route = '/' . ltrim( $route, '/' );

		if ( 0 !== strpos( $route, '/pwc/v1/chat/' ) ) {
			return $result;
		}

		wp_set_current_user( 0 ); // مهمان؛ مثل رفتار خودِ وردپرس وقتی nonce اصلاً فرستاده نشود.

		return null;
	}

	public function routes(): void {
		register_rest_route(
			'pwc/v1',
			'/chat/init',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'init' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		register_rest_route(
			'pwc/v1',
			'/chat/profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'profile' ),
				'permission_callback' => array( $this, 'allowed' ),
				'args'                => array(
					'name'    => array( 'type' => 'string', 'default' => '' ),
					'contact' => array( 'type' => 'string', 'default' => '' ),
					'guest'   => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			'pwc/v1',
			'/chat/poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'poll' ),
				'permission_callback' => array( $this, 'allowed' ),
				'args'                => array(
					'sig'   => array( 'type' => 'string', 'default' => '' ),
					'limit' => array( 'type' => 'integer', 'default' => 30 ),
				),
			)
		);
		register_rest_route(
			'pwc/v1',
			'/chat/ws-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ws_token' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		register_rest_route(
			'pwc/v1',
			'/chat/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'allowed' ),
				'args'                => array(
					'before' => array( 'type' => 'integer', 'default' => 0 ),
					'limit'  => array( 'type' => 'integer', 'default' => self::PAGE_SIZE ),
				),
			)
		);
		register_rest_route(
			'pwc/v1',
			'/chat/typing',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'typing' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		register_rest_route(
			'pwc/v1',
			'/chat/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'message' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public function allowed() {
		if ( ! ChatSettings::get( 'enabled' ) ) {
			return new WP_Error( 'pwc_chat_disabled', __( 'چت فعال نیست.', 'persian-wp-chatbot' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */

	private function clean_uid( string $uid ): string {
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uid ) ? strtolower( $uid ) : '';
	}

	/** خطای JSON با هدر Retry-After (برای 429). */
	private function limited( string $code, string $message, int $retry ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'        => array( 'status' => 429 ),
				'retry_after' => max( 1, $retry ),
			),
			429,
			array( 'Retry-After' => (string) max( 1, $retry ) )
		);
	}

	/**
	 * بازدیدکنندهٔ «موجود» (هرگز ردیف جدید نمی‌سازد). برای GET/poll/history/typing/ws-token.
	 *
	 * @return array{0:?object,1:int} [visitor|null, user_id]
	 */
	private function existing( WP_REST_Request $request ): array {
		$uid     = $this->clean_uid( (string) $request->get_header( 'x_pwc_visitor' ) );
		$user_id = get_current_user_id();
		return array( $this->store->resolve_visitor( $uid, $user_id ), $user_id );
	}

	/**
	 * بازدیدکننده را می‌یابد و اگر نبود می‌سازد (فقط برای ارسال پیام و ذخیرهٔ پروفایل). ساخت ردیف جدید سقف نرخ هر IP دارد (S-1).
	 *
	 * @return array{0:object,1:int}|WP_REST_Response|WP_Error [visitor, user_id] یا پاسخ خطا (۴۲۹ / ۵۰۰)؛ فراخوان با is_array() بررسی کند.
	 */
	private function identity( WP_REST_Request $request ) {
		$uid     = $this->clean_uid( (string) $request->get_header( 'x_pwc_visitor' ) );
		$user_id = get_current_user_id();
		$visitor = $this->store->resolve_visitor( $uid, $user_id );
		if ( ! $visitor ) {
			$limits = ChatSettings::rate_limits();
			$hit    = RateLimit::hit( 'create', RateLimit::ip(), (int) $limits['create_per_hour'], HOUR_IN_SECONDS );
			if ( ! $hit['ok'] ) {
				return $this->limited( 'pwc_rate', __( 'تعداد گفتگوهای جدید از این شبکه زیاد است؛ کمی بعد دوباره تلاش کنید.', 'persian-wp-chatbot' ), (int) $hit['retry_after'] );
			}
			$visitor = $this->store->ensure_visitor( $uid, $user_id );
			if ( ! $visitor ) {
				return new WP_Error( 'pwc_db', __( 'ذخیرهٔ اطلاعات ناموفق بود.', 'persian-wp-chatbot' ), array( 'status' => 500 ) );
			}
		}
		return array( $visitor, $user_id );
	}

	/**
	 * پاسخ init برای کسی که هنوز بازدیدکنندهٔ ثبت‌شده نیست (هیچ ردیفی ساخته نمی‌شود).
	 *
	 * @return array<string,mixed>
	 */
	private function empty_payload( int $user_id ): array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return array(
			'uid'           => '',
			'user'          => array(
				'logged_in' => $user_id > 0,
				'name'      => $user ? (string) $user->display_name : '',
			),
			'needs_profile' => $user_id <= 0,
			'sig'           => '',
			'online'        => ChatSettings::is_online(),
			'ai_muted'      => false,
			'pending'       => false,
			'thinking'      => false,
			'wait'          => 0,
			'has_more'      => false,
			'messages'      => array(),
			'welcome'       => (string) ChatSettings::get( 'welcome_message' ),
			'suggestions'   => ChatSettings::default_suggestions(),
			'rt'            => Realtime::enabled() ? 1 : 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload( object $visitor, int $user_id ): array {
		$page        = $this->store->page( (string) $visitor->uid, $user_id, self::PAGE_SIZE );
		$messages    = $page['messages'];
		$suggestions = array();
		$welcome     = '';

		if ( empty( $messages ) ) {
			$welcome     = (string) ChatSettings::get( 'welcome_message' );
			$suggestions = ChatSettings::default_suggestions();
		} else {
			$last = end( $messages );
			if ( 'assistant' === $last['role'] ) {
				$suggestions = $last['suggestions'];
			}
		}

		$wait = $this->wait_for( $visitor );

		return array(
			'uid'           => (string) $visitor->uid,
			'user'          => array(
				'logged_in' => $user_id > 0,
				'name'      => (string) $visitor->name,
			),
			'needs_profile' => ! (int) $visitor->profile_done,
			'sig'           => $this->store->sig( (string) $visitor->uid, $user_id ),
			'online'        => ChatSettings::is_online(),
			'ai_muted'      => (int) $visitor->ai_muted > 0,
			'pending'       => $this->is_pending( $visitor ),
			'thinking'      => $this->is_thinking( $visitor ),
			'wait'          => $wait,
			'has_more'      => $page['has_more'],
			'messages'      => $messages,
			'welcome'       => $welcome,
			'suggestions'   => $suggestions,
			'rt'            => Realtime::enabled() ? 1 : 0, // kill switch سمت وردپرس؛ ویجت با rt = 0 اصلاً WebSocket نمی‌زند
		);
	}

	/**
	 * آیا پیام‌های جمع‌شدهٔ کاربر منتظر پاسخ (پس از تأخیر هوش مصنوعی) هستند؟
	 */
	private function is_pending( object $visitor ): bool {
		// «پاسخ در راه است»: یا پیام‌های جمع‌شده منتظر ai_delay‌اند یا سرور پاسخ را گرفته و وب‌هوکش هنوز نرسیده.
		return $this->responder->has_pending( $visitor ) || ChatAnswers::is_waiting( (string) $visitor->uid );
	}

	/**
	 * آیا هوش مصنوعی همین الان دارد پاسخ می‌سازد؟ (فقط وقتی «در حال تایپ» نشان داده می‌شود.)
	 * زمانی که پیام‌ها هنوز در تأخیر هوش مصنوعی جمع می‌شوند، هوش مصنوعی چیزی نمی‌نویسد پس این false است.
	 */
	private function is_thinking( object $visitor ): bool {
		return (int) $visitor->ai_muted <= 0 && ChatAnswers::is_waiting( (string) $visitor->uid );
	}

	/** ثانیه‌های باقی‌ماندهٔ تأخیر هوش مصنوعی برای پیام‌های جمع‌شده (0 = نوبت پردازش رسیده یا پیامی در انتظار نیست). */
	private function wait_for( object $visitor ): int {
		return (int) $visitor->ai_muted <= 0 ? $this->responder->wait_seconds( $visitor ) : 0;
	}

	public function init( WP_REST_Request $request ) {
		list( $visitor, $user_id ) = $this->existing( $request );
		if ( ! $visitor ) {
			return rest_ensure_response( $this->empty_payload( $user_id ) );
		}
		return rest_ensure_response( $this->payload( $visitor, $user_id ) );
	}

	/**
	 * به‌روزرسانی دوره‌ای (و «واکشی بعد از اعلان WebSocket»). اگر امضای گفتگو تغییر نکرده باشد فقط وضعیت‌ها برمی‌گردد (سبک).
	 *
	 * سقف نرخ: ۶۰ در دقیقه برای هر بازدیدکننده (+ سقف سخاوتمندانه‌تر برای IP) ← ۴۲۹ با Retry-After؛ ویجت هنگام ۴۲۹ فاصله را دو برابر می‌کند.
	 */
	public function poll( WP_REST_Request $request ) {
		$limits = ChatSettings::rate_limits();
		$uid_h  = $this->clean_uid( (string) $request->get_header( 'x_pwc_visitor' ) );
		$per    = (int) $limits['poll_per_min'];
		$hit    = $uid_h ? RateLimit::hit( 'poll', $uid_h, $per, MINUTE_IN_SECONDS ) : array( 'ok' => true, 'retry_after' => 0 );
		if ( $hit['ok'] ) {
			$hit = RateLimit::hit( 'pollip', RateLimit::ip(), $per * 5, MINUTE_IN_SECONDS ); // کمکی: چند بازدیدکنندهٔ پشت یک IP
		}
		if ( ! $hit['ok'] ) {
			return $this->limited( 'pwc_rate', __( 'درخواست‌ها زیاد است؛ کمی صبر کنید.', 'persian-wp-chatbot' ), (int) $hit['retry_after'] );
		}

		list( $visitor, $user_id ) = $this->existing( $request );
		if ( ! $visitor ) {
			// بازدیدکنندهٔ ناشناخته: چیزی ساخته یا خوانده نمی‌شود.
			return rest_ensure_response(
				array(
					'pending'      => false,
					'thinking'     => false,
					'wait'         => 0,
					'sig'          => '',
					'changed'      => false,
					'online'       => ChatSettings::is_online(),
					'ai_muted'     => false,
					'admin_typing' => '',
					'rt'           => Realtime::enabled() ? 1 : 0,
				)
			);
		}
		$uid = (string) $visitor->uid;

		// پیام‌های منتظر یک‌بار خوانده می‌شوند و بین مراحل پایین دست‌به‌دست می‌شوند (کوئری تکراری نداریم).
		$rows = $this->responder->pending_rows( $visitor );
		$wait = $rows ? $this->responder->wait_seconds( $visitor, $rows ) : 0;

		// اگر کاربر ai_delay ثانیه تایپ نکرده، پیام‌های جمع‌شده همین‌جا پاسخ داده می‌شوند.
		// فقط وقتی پیامی منتظر است و نوبتش رسیده، و با قفل کوتاه (چند poll هم‌زمان یک کار را شروع نکنند؛ برندهٔ واقعی ChatStore::claim است).
		if ( $rows && 0 === $wait && RateLimit::lock( 'pwc_run_' . $uid, 10 ) ) {
			if ( null !== $this->responder->maybe_run( $visitor, false, $rows ) ) {
				$visitor = $this->store->visitor( $uid );
				$rows    = array();
			}
			RateLimit::unlock( 'pwc_run_' . $uid );
		}
		$sig   = $this->store->sig( $uid, $user_id );
		$muted = (int) $visitor->ai_muted > 0;

		$out = array(
			'pending'      => ! $muted && ( ! empty( $rows ) || ChatAnswers::is_waiting( $uid ) ),
			'thinking'     => $this->is_thinking( $visitor ),
			'wait'         => $muted || ! $rows ? 0 : $this->responder->wait_seconds( $visitor, $rows ),
			'sig'          => $sig,
			'changed'      => $sig !== (string) $request->get_param( 'sig' ),
			'online'       => ChatSettings::is_online(),
			'ai_muted'     => $muted,
			'admin_typing' => $this->store->typing( $uid )['admin'],
			'rt'           => Realtime::enabled() ? 1 : 0,
		);
		if ( $out['changed'] ) {
			// فقط به‌اندازهٔ پیام‌هایی که ویجت نشان می‌دهد (حداکثر ۵۰ در هر پاسخ): پیام‌های جدید و حذف‌شده در همین بازه تشخیص داده می‌شوند.
			$limit           = max( 10, min( self::POLL_MAX, (int) $request->get_param( 'limit' ) ) );
			$out['messages'] = $this->store->thread( $uid, $user_id, $limit );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * توکن کوتاه‌عمر اتصال WebSocket. فقط برای بازدیدکنندهٔ موجود (با همان هویتِ poll)، با سقف نرخ.
	 */
	public function ws_token( WP_REST_Request $request ) {
		if ( ! Realtime::enabled() || '' === Realtime::ws_url() ) {
			return new WP_Error( 'pwc_realtime_off', __( 'پیام‌رسانی لحظه‌ای فعال نیست.', 'persian-wp-chatbot' ), array( 'status' => 403 ) );
		}
		list( $visitor ) = $this->existing( $request );
		if ( ! $visitor ) {
			return new WP_Error( 'pwc_no_visitor', __( 'گفتگویی وجود ندارد.', 'persian-wp-chatbot' ), array( 'status' => 404 ) );
		}
		$limits = ChatSettings::rate_limits();
		$hit    = RateLimit::hit( 'wstoken', (string) $visitor->uid . '|' . RateLimit::ip(), (int) $limits['token_per_min'], MINUTE_IN_SECONDS );
		if ( ! $hit['ok'] ) {
			return $this->limited( 'pwc_rate', __( 'درخواست‌ها زیاد است؛ کمی صبر کنید.', 'persian-wp-chatbot' ), (int) $hit['retry_after'] );
		}
		$t = Realtime::issue( (string) $visitor->uid );
		return rest_ensure_response(
			array(
				'token'      => $t['token'],
				'ws_url'     => Realtime::ws_url(),
				'expires_in' => $t['expires_in'],
			)
		);
	}

	/**
	 * پیام‌های قدیمی‌تر برای اسکرول به بالا: ۱۰ پیام قبل از شناسهٔ `before`.
	 */
	public function history( WP_REST_Request $request ) {
		list( $visitor, $user_id ) = $this->existing( $request );
		if ( ! $visitor ) {
			return rest_ensure_response(
				array(
					'messages' => array(),
					'has_more' => false,
				)
			);
		}

		$page = $this->store->page(
			(string) $visitor->uid,
			$user_id,
			max( 1, min( 30, (int) $request->get_param( 'limit' ) ) ),
			max( 0, (int) $request->get_param( 'before' ) )
		);

		return rest_ensure_response(
			array(
				'messages' => $page['messages'],
				'has_more' => $page['has_more'],
			)
		);
	}

	public function typing( WP_REST_Request $request ) {
		list( $visitor ) = $this->existing( $request );
		if ( $visitor ) { // بازدیدکنندهٔ ناشناخته هنوز پیامی نفرستاده؛ ردیف وضعیت هم ساخته نمی‌شود.
			$this->store->touch_user_typing( (string) $visitor->uid );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * ارقام فارسی/عربی را لاتین می‌کند.
	 */
	private function latin_digits( string $s ): string {
		return strtr(
			$s,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	public function profile( WP_REST_Request $request ) {
		$who = $this->identity( $request );
		if ( ! is_array( $who ) ) {
			return $who;
		}
		list( $visitor, $user_id ) = $who;

		// کاربر واردشده: پروفایل از حساب وردپرس است.
		if ( $user_id > 0 ) {
			return rest_ensure_response( $this->payload( $visitor, $user_id ) );
		}

		$uid = (string) $visitor->uid;

		if ( $request->get_param( 'guest' ) ) {
			$this->store->save_profile( $uid, (string) $visitor->name, (string) $visitor->contact, true );
		} else {
			$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
			$contact = trim( $this->latin_digits( sanitize_text_field( (string) $request->get_param( 'contact' ) ) ) );

			if ( '' === $name ) {
				return new WP_Error( 'pwc_name', __( 'نام خود را وارد کنید.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
			}
			$phone = preg_replace( '/[\s\-()]/', '', $contact );
			if ( ! is_email( $contact ) && ! preg_match( '/^\+?\d{8,15}$/', (string) $phone ) ) {
				return new WP_Error( 'pwc_contact', __( 'ایمیل یا شماره تماس معتبر وارد کنید.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
			}
			$contact = is_email( $contact ) ? sanitize_email( $contact ) : (string) $phone;
			$this->store->save_profile( $uid, mb_substr( $name, 0, 100 ), $contact, true );
		}

		return rest_ensure_response( $this->payload( $this->store->visitor( $uid ), 0 ) );
	}

	/**
	 * دو شمارندهٔ اتمیک، هر دو باید زیر سقف باشند: ۳۰ پیام/دقیقه برای هر IP و ۱۰ پیام/دقیقه برای هر uid.
	 *
	 * @return int 0 = مجاز؛ وگرنه ثانیه‌های Retry-After.
	 */
	private function throttle( string $uid ): int {
		$hit = RateLimit::hit( 'msg', RateLimit::ip(), 30, MINUTE_IN_SECONDS );
		if ( ! $hit['ok'] ) {
			return (int) $hit['retry_after'];
		}
		$hit = RateLimit::hit( 'uid', $uid, 10, MINUTE_IN_SECONDS );
		return $hit['ok'] ? 0 : (int) $hit['retry_after'];
	}

	public function message( WP_REST_Request $request ) {
		$who = $this->identity( $request );
		if ( ! is_array( $who ) ) {
			return $who;
		}
		list( $visitor, $user_id ) = $who;
		$uid = (string) $visitor->uid;

		$retry = $this->throttle( $uid );
		if ( $retry > 0 ) {
			return $this->limited( 'pwc_rate', __( 'تعداد پیام‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 'persian-wp-chatbot' ), $retry );
		}

		$text = sanitize_textarea_field( (string) $request->get_param( 'text' ) );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2000 ) : substr( $text, 0, 2000 );

		$files = $request->get_file_params();
		$file  = ( isset( $files['file'] ) && is_array( $files['file'] ) && ! empty( $files['file']['tmp_name'] ) ) ? $files['file'] : null;

		if ( '' === $text && ! $file ) {
			return new WP_Error( 'pwc_empty', __( 'پیام خالی است.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
		}

		$attach = null;
		if ( $file ) {
			$attach = Uploads::handle( $file, true );
			if ( is_wp_error( $attach ) ) {
				return $attach;
			}
		}

		$user_msg = $this->store->add_message(
			$uid,
			$user_id,
			array(
				'role'       => 'user',
				'content'    => $text,
				'attachment' => $attach ? $attach : array(),
			)
		);
		if ( ! $user_msg ) {
			return new WP_Error( 'pwc_db', __( 'ذخیرهٔ پیام ناموفق بود.', 'persian-wp-chatbot' ), array( 'status' => 500 ) );
		}

		// کاربر پیام فرستاد: دیگر «در حال تایپ» نیست.
		$this->store->clear_user_typing( $uid );

		// صفحهٔ جاری (فقط نگه داشته می‌شود؛ هنگام درخواست پاسخ به سرور می‌رود).
		PageContext::remember( $uid, (string) $request->get_param( 'page_url' ), (string) $request->get_param( 'page_title' ) );

		$replies  = array();
		$pending  = false;
		$thinking = false;
		$wait     = 0;

		if ( 0 === ChatSettings::ai_delay() ) {
			// بدون تأخیر: همین‌جا پاسخ داده می‌شود.
			$done    = $this->responder->maybe_run( $this->store->visitor( $uid ), true );
			$replies = is_array( $done ) ? $done : array();
			$pending  = ChatAnswers::is_waiting( $uid ); // سرور پاسخ را گرفته و با وب‌هوک می‌فرستد
			$thinking = $pending;
		} else {
			// پیام فقط ذخیره می‌شود؛ بعد از ai_delay ثانیه سکوتِ تایپ، همهٔ پیام‌ها یک‌جا پردازش می‌شوند.
			// تا آن موقع «در حال تایپِ هوش مصنوعی» نشان داده نمی‌شود (thinking = false)؛ wait = ثانیه‌های باقی‌مانده.
			$pending = true;
			$wait    = ChatSettings::ai_delay();
			$this->responder->schedule( $uid );
		}

		$last = $replies ? end( $replies ) : null;

		return rest_ensure_response(
			array(
				'uid'         => $uid, // ویجت uid را از همین‌جا می‌گیرد (init برای بازدیدکنندهٔ تازه ردیفی نمی‌سازد)
				'user'        => $user_msg,
				'reply'       => $replies ? $replies[0] : null,
				'replies'     => $replies,
				'pending'     => $pending && (int) $visitor->ai_muted <= 0,
				'thinking'    => $thinking && (int) $visitor->ai_muted <= 0,
				'wait'        => (int) $visitor->ai_muted <= 0 ? $wait : 0,
				'suggestions' => $last ? $last['suggestions'] : array(),
			)
		);
	}
}