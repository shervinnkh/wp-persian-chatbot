<?php
/**
 * REST عمومی چت (namespace: paia/v1)
 *
 *  GET  /chat/init     بارگذاری هویت، تاریخچه و پیشنهادها
 *  POST /chat/profile  ثبت نام/تماس (یا ادامه به‌عنوان مهمان)
 *  POST /chat/message  ارسال پیام (متن، عکس یا ویس) + دریافت پاسخ
 *  GET  /chat/history  صفحهٔ پیام‌های قدیمی‌تر (before = شناسهٔ قدیمی‌ترین پیام نمایش‌داده‌شده؛ ۱۰ تا ۱۰ تا)
 *  GET  /chat/poll     به‌روزرسانی دوره‌ای (پیام‌های جدید/حذف‌شده، تایپینگ پشتیبان، وضعیت آنلاین)
 *  POST /chat/typing   اعلام «کاربر در حال تایپ است»
 *
 * هویت: هدر X-PAIA-Visitor (UUID). برای کاربر واردشده، کاربر وردپرس ملاک است.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use WP_Error;
use PersianAIAssistant\Support\ClientIp;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class ChatController {

	/** تعداد پیام در هر بار بارگذاری تاریخچه (۱۰ پیام آخر؛ با اسکرول به بالا ۱۰ تای قبلی). */
	public const PAGE_SIZE = 10;

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
	}

	public function routes(): void {
		register_rest_route(
			'paia/v1',
			'/chat/init',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'init' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		register_rest_route(
			'paia/v1',
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
			'paia/v1',
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
			'paia/v1',
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
			'paia/v1',
			'/chat/typing',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'typing' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		register_rest_route(
			'paia/v1',
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
			return new WP_Error( 'paia_chat_disabled', __( 'چت فعال نیست.', 'persian-ai-assistant' ), array( 'status' => 403 ) );
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
				'data'    => array( 'status' => 429 ),
			),
			429,
			array( 'Retry-After' => (string) max( 1, $retry ) )
		);
	}

	/**
	 * هویت درخواست (S-1). فقط init (و profile فقط برای uid موجود) می‌تواند بازدیدکنندهٔ تازه بسازد — init با سقف ۱۰ بازدیدکنندهٔ
	 * تازه در ساعت برای هر IP. بقیه با uid نامعتبر/ناموجود 400 با کد paia_bad_visitor می‌دهند (ویجت init را دوباره صدا می‌زند).
	 *
	 * @return array{0:object,1:int}|WP_Error|WP_REST_Response [visitor, user_id]
	 */
	private function identity( WP_REST_Request $request, bool $create = false ) {
		$uid     = $this->clean_uid( (string) $request->get_header( 'x_paia_visitor' ) );
		$user_id = get_current_user_id();
		$visitor = $this->store->resolve_visitor( $uid, $user_id );
		if ( $visitor ) {
			return array( $visitor, $user_id );
		}
		if ( ! $create ) {
			return new WP_Error( 'paia_bad_visitor', __( 'هویت بازدیدکننده نامعتبر است.', 'persian-ai-assistant' ), array( 'status' => 400 ) );
		}
		list( $n, $wait ) = RateLimit::hit( 'new|' . ClientIp::hash( ClientIp::get() ), HOUR_IN_SECONDS );
		if ( $n > 10 ) {
			return $this->limited( 'paia_rate', __( 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 'persian-ai-assistant' ), $wait );
		}
		return array( $this->store->ensure_visitor( $uid, $user_id ), $user_id );
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
		$paia_id = $this->identity( $request, true );
		if ( ! is_array( $paia_id ) ) {
			return $paia_id;
		}
		list( $visitor, $user_id ) = $paia_id;
		return rest_ensure_response( $this->payload( $visitor, $user_id ) );
	}

	/**
	 * به‌روزرسانی دوره‌ای. اگر امضای گفتگو تغییر نکرده باشد فقط وضعیت‌ها برمی‌گردد (سبک).
	 */
	public function poll( WP_REST_Request $request ) {
		$paia_id = $this->identity( $request, false );
		if ( ! is_array( $paia_id ) ) {
			return $paia_id;
		}
		list( $visitor, $user_id ) = $paia_id;
		$uid = (string) $visitor->uid;

		// اگر کاربر ai_delay ثانیه تایپ نکرده، پیام‌های جمع‌شده همین‌جا پاسخ داده می‌شوند.
		// فقط وقتی چیزی پردازش شد ردیف بازدیدکننده دوباره خوانده می‌شود (کم‌کردن کوئری در هر polling).
		if ( null !== $this->responder->maybe_run( $visitor ) ) {
			$visitor = $this->store->visitor( $uid );
		}
		$sig = $this->store->sig( $uid, $user_id );

		$out = array(
			'pending'      => (int) $visitor->ai_muted <= 0 && $this->is_pending( $visitor ),
			'thinking'     => $this->is_thinking( $visitor ),
			'wait'         => $this->wait_for( $visitor ),
			'sig'          => $sig,
			'changed'      => $sig !== (string) $request->get_param( 'sig' ),
			'online'       => ChatSettings::is_online(),
			'ai_muted'     => (int) $visitor->ai_muted > 0,
			'admin_typing' => $this->store->typing( $uid )['admin'],
		);
		if ( $out['changed'] ) {
			// فقط به‌اندازهٔ پیام‌هایی که ویجت نشان می‌دهد (پیش‌فرض ۳۰): پیام‌های جدید و حذف‌شده در همین بازه تشخیص داده می‌شوند.
			$limit           = max( 10, min( 200, (int) $request->get_param( 'limit' ) ) );
			$out['messages'] = $this->store->thread( $uid, $user_id, $limit );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * پیام‌های قدیمی‌تر برای اسکرول به بالا: ۱۰ پیام قبل از شناسهٔ `before`.
	 */
	public function history( WP_REST_Request $request ) {
		$paia_id = $this->identity( $request, false );
		if ( ! is_array( $paia_id ) ) {
			return $paia_id;
		}
		list( $visitor, $user_id ) = $paia_id;

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
		$paia_id = $this->identity( $request );
		if ( ! is_array( $paia_id ) ) {
			return $paia_id;
		}
		list( $visitor ) = $paia_id;
		$this->store->touch_user_typing( (string) $visitor->uid );
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
		$paia_id = $this->identity( $request, false );
		if ( ! is_array( $paia_id ) ) {
			return $paia_id;
		}
		list( $visitor, $user_id ) = $paia_id;

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
				return new WP_Error( 'paia_name', __( 'نام خود را وارد کنید.', 'persian-ai-assistant' ), array( 'status' => 400 ) );
			}
			$phone = preg_replace( '/[\s\-()]/', '', $contact );
			if ( ! is_email( $contact ) && ! preg_match( '/^\+?\d{8,15}$/', (string) $phone ) ) {
				return new WP_Error( 'paia_contact', __( 'ایمیل یا شماره تماس معتبر وارد کنید.', 'persian-ai-assistant' ), array( 'status' => 400 ) );
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
		list( $n, $wait ) = RateLimit::hit( 'msg|' . ClientIp::hash( ClientIp::get() ), 60 );
		if ( $n > 30 ) {
			return $wait;
		}
		list( $n, $wait ) = RateLimit::hit( 'uid|' . $uid, 60 );
		return $n > 10 ? $wait : 0;
	}

	public function message( WP_REST_Request $request ) {
		$paia_id = $this->identity( $request, false );
		if ( ! is_array( $paia_id ) ) {
			return $paia_id;
		}
		list( $visitor, $user_id ) = $paia_id;
		$uid = (string) $visitor->uid;

		$retry = $this->throttle( $uid );
		if ( $retry > 0 ) {
			return $this->limited( 'paia_rate', __( 'تعداد پیام‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 'persian-ai-assistant' ), $retry );
		}

		$text = sanitize_textarea_field( (string) $request->get_param( 'text' ) );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2000 ) : substr( $text, 0, 2000 );

		$files = $request->get_file_params();
		$file  = ( isset( $files['file'] ) && is_array( $files['file'] ) && ! empty( $files['file']['tmp_name'] ) ) ? $files['file'] : null;

		if ( '' === $text && ! $file ) {
			return new WP_Error( 'paia_empty', __( 'پیام خالی است.', 'persian-ai-assistant' ), array( 'status' => 400 ) );
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
			return new WP_Error( 'paia_db', __( 'ذخیرهٔ پیام ناموفق بود.', 'persian-ai-assistant' ), array( 'status' => 500 ) );
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
