<?php
/**
 * تولید پاسخ چت‌بات.
 *
 * اولویت:
 *  1) فیلتر `paia_chat_reply` (برای اتصال سرویس پایتون/RAG از کد)
 *  2) سرور هوش مصنوعی (Apis\Client امضادار): POST /v1/chat/messages ؛ سرور خودش تاریخچه/خلاصه را نگه می‌دارد، پس هر پیام کاربر
 *     همان‌جا ذخیره می‌شود و روی آخرین پیامِ دسته generate_ai_answer = true است.
 *  3) (قدیمی) آدرس سرویس در تنظیمات ویجت بدون کلید (POST JSON) — فقط وقتی اتصال امضادار تنظیم نباشد
 *  (بدون سرویس/پاسخ: null؛ پاسخ پیش‌فرض به پیام اول در Responder است)
 *
 * خروجی: ['text' => string, 'suggestions' => string[]] یا null (یعنی هیچ پیامی ارسال نشود؛
 * وقتی هیچ سرویسی پاسخ نداده و «پاسخ پیش‌فرض» در تنظیمات خالی است).
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Apis\Callback;
use PersianAIAssistant\Apis\Client;
use PersianAIAssistant\Database\LogStore;

defined( 'ABSPATH' ) || exit;

final class ReplyEngine {

	/**
	 * @param array<string,mixed> $ctx visitor_uid, user_id, name, contact, message, attachment, history[].
	 * @return array{text:string,suggestions:string[]}|null
	 */
	public function generate( array $ctx ): ?array {
		/**
		 * فیلتر پاسخ: آرایه‌ای با کلیدهای text و suggestions برگردانید.
		 *
		 * @param array|null $reply
		 * @param array      $ctx
		 */
		$reply = apply_filters( 'paia_chat_reply', null, $ctx );

		if ( ( ! is_array( $reply ) || empty( $reply['text'] ) ) && Client::is_configured() ) {
			$reply = $this->via_server( $ctx );
		} elseif ( ! is_array( $reply ) || empty( $reply['text'] ) ) {
			$reply = $this->remote( $ctx );
		}
		if ( ! is_array( $reply ) || empty( $reply['text'] ) ) {
			// سرویس پاسخی نداد: ساکت می‌مانیم. «پاسخ پیش‌فرض به پیام اول» را Responder می‌فرستد، نه اینجا.
			return null;
		}

		return array(
			'text'        => (string) $reply['text'],
			'suggestions' => $this->clean_suggestions( $reply['suggestions'] ?? array() ),
		);
	}

	/**
	 * پیام‌های جمع‌شدهٔ کاربر را به سرور هوش مصنوعی می‌دهد و پاسخ آخرین پیام را برمی‌گرداند.
	 * (هر پیام جدا ذخیره می‌شود؛ فقط آخری generate_ai_answer = true دارد.)
	 *
	 * @param array<string,mixed> $ctx زمینه؛ ctx['rows'] = ردیف‌های پیام کاربر (به ترتیب).
	 * @return array{text:string,suggestions:array<int,string>}|null
	 */
	private function via_server( array $ctx ): ?array {
		$rows = isset( $ctx['rows'] ) && is_array( $ctx['rows'] ) ? array_values( $ctx['rows'] ) : array();
		if ( ! $rows ) {
			return null;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$uid     = (string) ( $ctx['visitor_uid'] ?? '' );
		$user_id = (int) ( $ctx['user_id'] ?? 0 );
		$last    = count( $rows ) - 1;
		$answer  = null;

		foreach ( $rows as $i => $row ) {
			$res = $this->push(
				$uid,
				$user_id,
				array(
					'role'       => 'user',
					'content'    => (string) $row->content,
					'attachment' => array(
						'type' => (string) $row->attachment_type,
						'url'  => (string) $row->attachment_url,
					),
				),
				$i === $last
			);
			if ( $i === $last ) {
				$answer = $res;
			}
		}

		if ( ! is_array( $answer ) || ! $answer['ok'] ) {
			return null;
		}

		$ai = isset( $answer['body']['ai_answer'] ) && is_array( $answer['body']['ai_answer'] ) ? $answer['body']['ai_answer'] : array();
		if ( 'queued' === ( $ai['status'] ?? '' ) ) {
			// پاسخ بعداً با وب‌هوک می‌آید (ChatAnswers)؛ تا آن موقع ویجت «در حال تایپ» نشان می‌دهد.
			ChatAnswers::mark_waiting( $uid );
			return null;
		}
		if ( 'generated' !== ( $ai['status'] ?? '' ) ) {
			LogStore::add(
				LogStore::ERROR,
				__( 'سرور هوش مصنوعی پیام را ذخیره کرد ولی پاسخی تولید نکرد (جزئیات در لاگ سرور AI).', 'persian-ai-assistant' ),
				array(
					'method'      => 'POST',
					'endpoint'    => '/v1/chat/messages',
					'http_status' => $answer['code'],
					'response'    => $answer['body'],
				)
			);
			return null;
		}

		$msg  = isset( $ai['message'] ) && is_array( $ai['message'] ) ? $ai['message'] : array();
		$text = isset( $msg['content'] ) && is_string( $msg['content'] ) ? $msg['content'] : '';
		if ( '' === trim( $text ) ) {
			return null;
		}

		return array(
			'text'        => $text,
			'suggestions' => isset( $msg['suggestions'] ) && is_array( $msg['suggestions'] ) ? $msg['suggestions'] : array(),
		);
	}

	/**
	 * پیام‌های کاربرِ بدون پاسخ هوش مصنوعی (هوش مصنوعی خاموش است یا پشتیبان انسانی جواب می‌دهد) فقط ذخیره می‌شوند
	 * تا تاریخچهٔ گفتگو در سرور کامل بماند.
	 *
	 * @param array<int,object> $rows ردیف‌های پیام کاربر.
	 */
	public function mirror_rows( string $uid, int $user_id, array $rows ): void {
		if ( ! Client::is_configured() ) {
			return;
		}
		foreach ( $rows as $row ) {
			$this->push(
				$uid,
				$user_id,
				array(
					'role'       => 'user',
					'content'    => (string) $row->content,
					'attachment' => array(
						'type' => (string) $row->attachment_type,
						'url'  => (string) $row->attachment_url,
					),
				),
				false,
				15
			);
		}
	}

	/**
	 * پیام پشتیبان انسانی را در گفتگوی سرور ذخیره می‌کند (تا هوش مصنوعی از گفته‌های پشتیبان باخبر باشد).
	 *
	 * @param array<string,mixed> $attachment type/url (می‌تواند خالی باشد).
	 */
	public function mirror_admin( string $uid, int $user_id, string $text, array $attachment, int $admin_id, string $admin_name ): void {
		if ( ! Client::is_configured() ) {
			return;
		}
		$this->push(
			$uid,
			$user_id,
			array(
				'role'          => 'admin',
				'content'       => $text,
				'attachment'    => $attachment,
				'admin_user_id' => max( 1, $admin_id ),
				'admin_name'    => $admin_name,
			),
			false,
			15
		);
	}

	/**
	 * یک پیام را به POST /v1/chat/messages می‌فرستد. شکست را در «لاگ سرور» ثبت می‌کند و هرگز استثنا نمی‌اندازد.
	 *
	 * @param array<string,mixed> $msg role, content, attachment{type,url}, admin_user_id?, admin_name?
	 * @return array<string,mixed> پاسخ Client::request
	 */
	private function push( string $uid, int $user_id, array $msg, bool $generate, int $timeout = 65 ): array {
		$payload = array(
			'visitor_uid' => $uid,
			'wp_user_id'  => $user_id,
			'role'        => (string) $msg['role'],
			'content'     => (string) ( $msg['content'] ?? '' ),
		);

		$attachment = isset( $msg['attachment'] ) && is_array( $msg['attachment'] ) ? $msg['attachment'] : array();
		$type       = (string) ( $attachment['type'] ?? '' );
		$url        = (string) ( $attachment['url'] ?? '' );
		if ( '' !== $type && '' !== $url ) {
			// در افزونه ویس «audio» ذخیره می‌شود ولی سرور فقط image | voice می‌پذیرد.
			$payload['attachment_type'] = 'audio' === $type ? 'voice' : $type;
			$payload['attachment_url']  = $url;
		}
		if ( 'admin' === $payload['role'] ) {
			$payload['admin_user_id'] = (int) ( $msg['admin_user_id'] ?? 1 );
			$payload['admin_name']    = (string) ( $msg['admin_name'] ?? '' );
		}
		if ( $generate ) {
			$payload['generate_ai_answer'] = true;
			// فقط همراه درخواست پاسخ: مدل، لحن، اطلاعات سایت و صفحهٔ جاری (برای پراپمت).
			$payload['model'] = (string) ChatSettings::get( 'ai_service', 'gpt-5-4' );
			$payload['tone']  = 'friendly' === ChatSettings::get( 'tone', 'formal' ) ? 'friendly' : 'formal';
			$payload['site']  = array_filter(
				array(
					'title'       => ChatSettings::site_title(),
					'description' => (string) ChatSettings::get( 'site_desc', '' ),
				),
				'strlen'
			);
			$page = PageContext::get( $uid );
			if ( $page ) {
				$payload['page'] = $page;
			}
			// پاسخ غیرهمزمان: سرور فوراً «queued» می‌دهد و پاسخ را بعداً به این آدرس (وب‌هوک) می‌فرستد.
			$payload['callback'] = Callback::descriptor();
		}

		// attempts = 1: تلاش دوباره پس از timeout ممکن است پیام را در سرور دوبار ذخیره کند.
		$res = Client::request( 'POST', '/v1/chat/messages', $payload, $timeout, 1 );

		if ( ! $res['ok'] ) {
			LogStore::add(
				LogStore::ERROR,
				sprintf(
					/* translators: %s: reason */
					__( 'ارسال پیام چت به سرور هوش مصنوعی ناموفق بود: %s', 'persian-ai-assistant' ),
					'' !== (string) $res['error'] ? $res['error'] : ( 'HTTP ' . (int) $res['code'] )
				),
				array(
					'method'      => 'POST',
					'endpoint'    => '/v1/chat/messages',
					'http_status' => $res['code'],
					'duration_ms' => $res['duration_ms'],
					'error'       => (string) $res['error_code'],
					'request_id'  => (string) $res['request_id'],
					'request'     => array(
						'role'     => $payload['role'],
						'visitor'  => $uid,
						'generate' => $generate,
					),
				)
			);
		}

		return $res;
	}

	/**
	 * @param mixed $list پیشنهادها.
	 * @return string[]
	 */
	private function clean_suggestions( $list ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $item ) {
			$item = trim( sanitize_text_field( is_scalar( $item ) ? (string) $item : '' ) );
			if ( '' === $item ) {
				continue;
			}
			$out[] = function_exists( 'mb_substr' ) ? mb_substr( $item, 0, 80 ) : substr( $item, 0, 80 );
			if ( count( $out ) >= 4 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * قرارداد (فعلاً پیشنهادی): POST JSON به api_url
	 *   { visitor_id, user_id, name, contact, message, attachment:{type,url}|null,
	 *     history:[{role,text}], site:{name,url} }
	 * پاسخ مورد انتظار: { "reply": "...", "suggestions": ["...", "..."] }
	 *
	 * @param array<string,mixed> $ctx زمینه.
	 * @return array<string,mixed>|null
	 */
	private function remote( array $ctx ): ?array {
		$url = (string) ChatSettings::get( 'api_url', '' );
		if ( '' === $url ) {
			return null;
		}
		$headers = array( 'Content-Type' => 'application/json' );
		$key     = (string) ChatSettings::get( 'api_key', '' );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 25,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'visitor_id' => $ctx['visitor_uid'] ?? '',
						'user_id'    => $ctx['user_id'] ?? 0,
						'name'       => $ctx['name'] ?? '',
						'contact'    => $ctx['contact'] ?? '',
						'message'    => $ctx['message'] ?? '',
						'attachment' => $ctx['attachment'] ?? null,
						'history'    => $ctx['history'] ?? array(),
						'site'       => array(
							'name'        => ChatSettings::site_title(),
							'url'         => home_url( '/' ),
							'description' => (string) ChatSettings::get( 'site_desc', '' ),
						),
						'ai'         => array(
							'service' => (string) ChatSettings::get( 'ai_service', 'gpt-5-4' ),
							'tone'    => (string) ChatSettings::get( 'tone', 'formal' ),
							'notes'   => (string) ChatSettings::get( 'important_notes', '' ),
						),
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 300 ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$text = $data['reply'] ?? ( $data['answer'] ?? ( $data['text'] ?? '' ) );
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return null;
		}
		return array(
			'text'        => $text,
			'suggestions' => $data['suggestions'] ?? array(),
		);
	}
}
