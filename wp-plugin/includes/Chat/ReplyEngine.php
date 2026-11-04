<?php
/**
 * تولید پاسخ چت‌بات.
 *
 * اولویت:
 *  1) فیلتر `paia_chat_reply` (برای اتصال سرویس پایتون/RAG از کد)
 *  2) سرور هوش مصنوعی (Apis\Client امضادار): POST /v1/chat/messages/batch ؛ سرور خودش تاریخچه/خلاصه را نگه می‌دارد.
 *     از نسخهٔ ۰.۵.۲۶ هیچ پیامی جداگانه یا فوری به سرور نمی‌رود: هر پیام با ai_sent = 0 ذخیره می‌شود و فقط وقتی هوش مصنوعی
 *     باید جواب بدهد، «همهٔ پیام‌های ارسال‌نشده + پیام جدید کاربر» با ترتیب شناسه (قدیمی ← جدید) در یک درخواست فرستاده می‌شوند
 *     (روی آخرین پیام generate_ai_answer = true). پیامی که سرور «دریافت شد» بگوید ai_sent = 1 می‌گیرد.
 *  3) (قدیمی) آدرس سرویس در تنظیمات ویجت بدون کلید (POST JSON) — فقط وقتی اتصال امضادار تنظیم نباشد
 *  (بدون سرویس/پاسخ: null؛ پاسخ پیش‌فرض به پیام اول در Responder است)
 *
 * خروجی: ['text' => string, 'suggestions' => string[], 'references' => array] یا null (یعنی هیچ پیامی ارسال نشود؛
 * وقتی هیچ سرویسی پاسخ نداده و «پاسخ پیش‌فرض» در تنظیمات خالی است).
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Apis\Callback;
use PersianAIAssistant\Apis\Client;
use PersianAIAssistant\Database\LiveStore;
use PersianAIAssistant\Database\LogStore;
use PersianAIAssistant\Extraction\PostTypes;

defined( 'ABSPATH' ) || exit;

final class ReplyEngine {

	/**
	 * @param array<string,mixed> $ctx visitor_uid, user_id, name, contact, message, attachment, history[].
	 * @return array{text:string,suggestions:string[],references:array<int,array<string,string>>}|null
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

		// پاسخ دقیقاً «Mark-Chat» یعنی هوش مصنوعی نمی‌تواند جواب بدهد: هیچ پیامی ذخیره/نمایش داده نمی‌شود و فقط گفتگو مارک می‌شود
		// (اگر تنظیم «مارک کردن چت توسط هوش مصنوعی» خاموش باشد فقط نمایش داده نمی‌شود).
		if ( AiMark::is_mark_reply( $reply['text'] ) ) {
			AiMark::handle( (string) ( $ctx['visitor_uid'] ?? '' ) );
			return null;
		}

		return array(
			'text'        => (string) $reply['text'],
			'suggestions' => $this->clean_suggestions( $reply['suggestions'] ?? array() ),
			'references'  => MessageLinks::clean_references( $reply['references'] ?? array() ),
		);
	}

	/** حداکثر پیام در هر درخواست (سقف سرور ۱۰۰ است؛ تاریخچهٔ بلندتر در چند درخواست پشت‌سرهم می‌رود). */
	private const BATCH_SIZE = 50;

	/**
	 * همهٔ پیام‌های ارسال‌نشده (ai_sent = 0) تا آخرین پیام کاربرِ این دسته را با ترتیب قدیمی ← جدید به سرور هوش مصنوعی می‌دهد
	 * و پاسخ آخرین پیام را می‌گیرد. (هر پیامی که سرور دریافتش را تأیید کرد ai_sent = 1 می‌شود.)
	 *
	 * @param array<string,mixed> $ctx زمینه؛ ctx['rows'] = ردیف‌های پیام کاربرِ این دسته (به ترتیب).
	 * @return array{text:string,suggestions:array<int,string>,references:array<int,array<string,string>>}|null
	 */
	private function via_server( array $ctx ): ?array {
		$rows = isset( $ctx['rows'] ) && is_array( $ctx['rows'] ) ? array_values( $ctx['rows'] ) : array();
		if ( ! $rows ) {
			return null;
		}

		$uid      = (string) ( $ctx['visitor_uid'] ?? '' );
		$user_id  = (int) ( $ctx['user_id'] ?? 0 );
		$last_row = end( $rows );
		$store    = new ChatStore();

		// ارسال‌نشده‌ها (پیام‌های کاربر و پشتیبان که در این مدت — مثلاً با هوش مصنوعی خاموش — جمع شده‌اند) تا همین پیام.
		$batch = $store->unsent_rows( $uid, $user_id, (int) $last_row->id );
		if ( ! $batch || (int) end( $batch )->id !== (int) $last_row->id ) {
			$batch[] = $last_row; // پیامِ درخواست‌دهنده همیشه آخرِ لیست است (generate_ai_answer روی آن)
		}

		$chunks = array_chunk( $batch, self::BATCH_SIZE );
		$answer = null;
		foreach ( $chunks as $i => $chunk ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}
			$is_last = count( $chunks ) - 1 === $i;
			$res     = $this->push_batch( $uid, $user_id, $chunk, $is_last, 90, $store );
			if ( ! $res['ok'] || $res['received'] < count( $chunk ) ) {
				// ارسال کامل نشد: پیام‌های تأییدنشده ai_sent = 0 می‌مانند و دفعهٔ بعد دوباره می‌روند.
				return null;
			}
			$answer = $res;
		}

		if ( ! is_array( $answer ) ) {
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
					'endpoint'    => '/v1/chat/messages/batch',
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
			'references'  => isset( $msg['references'] ) && is_array( $msg['references'] ) ? $msg['references'] : array(),
		);
	}

	/**
	 * مجوزها و ابزارهای داده زندهٔ این سایت را به بدنهٔ پیام اضافه می‌کند:
	 *  - live_product_datas: زیرمجموعهٔ [price, stock] طبق تیک‌های تب «اطلاعات به‌روز ← تمام محصولات» (نبودن = ابزار محصول به AI داده نمی‌شود)؛
	 *  - tools: ردیف‌های فعال «اطلاعات تعریف‌شده» به شکل [{id, title, description}] (حداکثر ۳۰).
	 * متن واقعی را بعداً خود سرور از POST /live/products و POST /live/tool (apis/Endpoints) می‌گیرد.
	 *
	 * @param array<string,mixed> $payload بدنهٔ در حال ساخت (با ارجاع).
	 */
	private function attach_live_tools( array &$payload ): void {
		$fields = PostTypes::products() ? LiveStore::ai_product_fields() : array();
		if ( $fields ) {
			$payload['live_product_datas'] = $fields;
		}

		$tools = LiveStore::ai_tools();
		if ( $tools ) {
			$payload['tools'] = $tools;
		}
	}

	/**
	 * یک دسته پیام (قدیمی ← جدید) را به POST /v1/chat/messages/batch می‌فرستد. شکست را در «لاگ سرور» ثبت می‌کند و هرگز استثنا نمی‌اندازد.
	 * هر پیامی که سرور دریافتش را تأیید کند (received_count پیام اول لیست) ai_sent = 1 می‌گیرد.
	 *
	 * @param object[]  $rows     ردیف‌های پیام (user|admin) به ترتیب شناسه.
	 * @param bool      $generate true = روی آخرین پیام (باید پیام کاربر باشد) پاسخ هوش مصنوعی هم خواسته می‌شود.
	 * @param ChatStore $store    برای ai_sent.
	 * @return array{ok:bool,received:int,code:int,body:array<string,mixed>} received = تعداد پیام‌های دریافت‌شدهٔ سرور
	 */
	private function push_batch( string $uid, int $user_id, array $rows, bool $generate, int $timeout, ChatStore $store ): array {
		$messages = array();
		$ids      = array(); // شناسهٔ پیام برای هر عنصر $messages (هم‌ترتیب)
		$empty    = array(); // پیام‌های بی‌متن و بی‌پیوست: چیزی برای ارسال ندارند؛ فقط علامت می‌خورند.

		foreach ( $rows as $row ) {
			$type = (string) $row->attachment_type;
			$url  = (string) $row->attachment_url;
			$text = (string) $row->content;
			if ( '' === trim( $text ) && ( '' === $type || '' === $url ) ) {
				$empty[] = (int) $row->id;
				continue;
			}
			$msg = array(
				'role'        => 'admin' === $row->role ? 'admin' : 'user',
				'content'     => $text,
				'external_id' => (string) (int) $row->id, // شناسهٔ پیام در سایت: سرور آن را در وب‌هوک «پیام‌ها ثبت شدند» برمی‌گرداند
			);
			if ( '' !== $type && '' !== $url ) {
				// در افزونه ویس «audio» ذخیره می‌شود ولی سرور فقط image | voice می‌پذیرد.
				$msg['attachment_type'] = 'audio' === $type ? 'voice' : $type;
				$msg['attachment_url']  = $url;
			}
			if ( 'admin' === $msg['role'] ) {
				$msg['admin_user_id'] = max( 1, (int) $row->admin_user_id );
				$msg['admin_name']    = (string) $row->admin_name;
			}
			$messages[] = $msg;
			$ids[]      = (int) $row->id;
		}
		$store->mark_sent( $empty );

		$fail = array(
			'ok'       => false,
			'received' => 0,
			'code'     => 0,
			'body'     => array(),
		);
		if ( ! $messages ) {
			return array_merge( $fail, array( 'ok' => true, 'received' => count( $empty ) ) );
		}

		$payload = array(
			'visitor_uid' => $uid,
			'wp_user_id'  => $user_id,
			'request_id'  => wp_generate_uuid4(),
			'messages'    => $messages,
			// وب‌هوک اول: به‌محض ذخیرهٔ پیام‌ها در دیتابیس سرور و قبل از تولید پاسخ، «این پیام‌ها ثبت شدند» (kind = chat_messages_saved)
			// به سایت می‌آید و ChatAnswers::apply_saved آن‌ها را ai_sent = 1 می‌کند؛ حتی اگر پاسخ HTTP همین درخواست گم شود.
			// وب‌هوک دوم (فقط با generate_ai_answer): خودِ پاسخ (kind = chat_answer).
			'callback'    => Callback::descriptor(),
		);
		if ( $generate && 'user' === $messages[ count( $messages ) - 1 ]['role'] ) {
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
			// مارک کردن چت توسط هوش مصنوعی (تنظیمات ← عمومی ← سرویس هوش مصنوعی): true = اگر نتواند جواب بدهد دقیقاً «Mark-Chat» برمی‌گرداند.
			$payload['mark-the-conversation'] = AiMark::enabled();
			// «توضیحات مهم» مدیر سایت (تنظیمات ← عمومی) ← پرامپت.
			$notes = trim( (string) ChatSettings::get( 'important_notes', '' ) );
			if ( '' !== $notes ) {
				$payload['notes'] = $notes;
			}
			// ابزارهای داده زنده (LIVE_TOOLS_API.md §2): همراه درخواستِ دارای generate_ai_answer دوباره فرستاده می‌شوند؛ سرور چیزی بین پیام‌ها نگه نمی‌دارد.
			$this->attach_live_tools( $payload );
		}

		// attempts = 1: تلاش دوباره پس از timeout ممکن است پیام‌ها را در سرور دوبار ذخیره کند (وب‌هوک «ثبت شدند» این خطر را کم می‌کند).
		$res = Client::request( 'POST', '/v1/chat/messages/batch', $payload, $timeout, 1 );

		if ( ! $res['ok'] ) {
			LogStore::add(
				LogStore::ERROR,
				sprintf(
					/* translators: %s: reason */
					__( 'ارسال پیام‌های چت به سرور هوش مصنوعی ناموفق بود: %s', 'persian-ai-assistant' ),
					'' !== (string) $res['error'] ? $res['error'] : ( 'HTTP ' . (int) $res['code'] )
				),
				array(
					'method'      => 'POST',
					'endpoint'    => '/v1/chat/messages/batch',
					'http_status' => $res['code'],
					'duration_ms' => $res['duration_ms'],
					'error'       => (string) $res['error_code'],
					'request_id'  => (string) $res['request_id'],
					'request'     => array(
						'visitor'  => $uid,
						'count'    => count( $messages ),
						'generate' => ! empty( $payload['generate_ai_answer'] ),
					),
				)
			);
			return array_merge( $fail, array( 'code' => (int) $res['code'] ) );
		}

		$body = is_array( $res['body'] ) ? $res['body'] : array();
		// سرور لیست را به ترتیب ذخیره می‌کند و در اولین شکست می‌ایستد: received_count = تعداد پیام‌های اولِ لیست که ذخیره شده‌اند.
		$received = isset( $body['received_count'] ) && is_numeric( $body['received_count'] ) ? max( 0, min( count( $messages ), (int) $body['received_count'] ) ) : count( $messages );
		$store->mark_sent( array_slice( $ids, 0, $received ) );

		if ( $received < count( $messages ) ) {
			LogStore::add(
				LogStore::ERROR,
				__( 'سرور هوش مصنوعی فقط بخشی از پیام‌های چت را دریافت کرد؛ بقیه در درخواست بعدی دوباره ارسال می‌شوند.', 'persian-ai-assistant' ),
				array(
					'method'      => 'POST',
					'endpoint'    => '/v1/chat/messages/batch',
					'http_status' => $res['code'],
					'response'    => $body,
				)
			);
		}

		return array(
			'ok'       => true,
			'received' => $received + count( $empty ),
			'code'     => (int) $res['code'],
			'body'     => $body,
		);
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
