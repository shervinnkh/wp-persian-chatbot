<?php
/**
 * پاسخ‌دهی با «تأخیر هوش مصنوعی» (ai_delay).
 *
 * پیام‌های کاربر ابتدا فقط ذخیره می‌شوند. وقتی کاربر به‌اندازهٔ ai_delay ثانیه تایپ نکرد
 * (و پیام تازه‌ای هم نفرستاد)، همهٔ پیام‌های جمع‌شده یک‌جا پردازش می‌شوند:
 *  1) اگر این اولین پیام کاربر در کل گفتگو بوده: «پاسخ پیش‌فرض به پیام اول» (مثلاً «ثبت شد») فقط همین یک بار.
 *  2) اگر هوش مصنوعی برای این گفتگو خاموش نیست و پشتیبان انسانی وسط نیامده: پیام‌های جمع‌شده + همهٔ پیام‌های قبلیِ ارسال‌نشده (ai_sent = 0) به هوش مصنوعی داده می‌شود.
 *
 * اجرا از سه مسیر (هر کدام با قفل اتمیِ ChatStore::claim، پس پاسخ دوبار ساخته نمی‌شود):
 *  - polling ویجت (سریع‌ترین)، - WP-Cron (اگر کاربر صفحه را بسته باشد)، - مستقیم وقتی ai_delay = 0.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class Responder {

	public const CRON_HOOK = 'paia_chat_process';

	/** @var ChatStore */
	private $store;

	/** @var ReplyEngine */
	private $engine;

	public function __construct() {
		$this->store  = new ChatStore();
		$this->engine = new ReplyEngine();
	}

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'cron' ), 10, 1 );
	}

	/**
	 * پشتیبان‌گیری با WP-Cron: اگر کاربر صفحه را ببندد هم پیام‌ها پاسخ داده شوند.
	 */
	public function schedule( string $uid ): void {
		$args = array( $uid );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + ChatSettings::ai_delay() + 2, self::CRON_HOOK, $args );
		}
	}

	/**
	 * @param mixed $uid شناسهٔ بازدیدکننده.
	 */
	public function cron( $uid ): void {
		$uid     = (string) $uid;
		$visitor = $this->store->visitor( $uid );
		if ( ! $visitor ) {
			return;
		}
		if ( null === $this->maybe_run( $visitor ) && $this->has_pending( $visitor ) ) {
			$this->schedule( $uid ); // هنوز وقتش نرسیده (کاربر در حال تایپ است).
		}
	}

	/**
	 * چند ثانیه دیگر مانده تا پیام‌های جمع‌شده برای هوش مصنوعی فرستاده شوند (تأخیر هوش مصنوعی).
	 * 0 = وقتش رسیده (یا پیامی در انتظار نیست). با هر پیام/تایپ تازهٔ کاربر دوباره از اول شروع می‌شود.
	 * ویجت تا صفر نشدن این مقدار «در حال تایپِ هوش مصنوعی» نشان نمی‌دهد.
	 */
	public function wait_seconds( object $visitor ): int {
		$uid  = (string) $visitor->uid;
		$rows = $this->store->pending_rows( $uid, (int) $visitor->wp_user_id, (int) $visitor->ai_done_id );
		if ( ! $rows ) {
			return 0;
		}
		$last = end( $rows );
		$ref  = max( (int) strtotime( $last->created_at . ' UTC' ), $this->store->user_typing_at( $uid ) );

		return max( 0, ChatSettings::ai_delay() - ( time() - $ref ) );
	}

	public function has_pending( object $visitor ): bool {
		return (bool) $this->store->pending_rows( (string) $visitor->uid, (int) $visitor->wp_user_id, (int) $visitor->ai_done_id );
	}

	/**
	 * اگر پیام‌های جمع‌شده آمادهٔ پاسخ باشند پردازش می‌کند.
	 *
	 * @param object $visitor ردیف بازدیدکننده.
	 * @param bool   $force   بدون انتظار برای تأخیر (ai_delay = 0).
	 * @return array<int,array<string,mixed>>|null null = کاری انجام نشد؛ آرایه = پیام‌های ساخته‌شده (ممکن است خالی باشد)
	 */
	public function maybe_run( object $visitor, bool $force = false ): ?array {
		$uid     = (string) $visitor->uid;
		$user_id = (int) $visitor->wp_user_id;
		$rows    = $this->store->pending_rows( $uid, $user_id, (int) $visitor->ai_done_id );
		if ( ! $rows ) {
			return null;
		}
		$last = end( $rows );

		if ( ! $force ) {
			$ref = max( (int) strtotime( $last->created_at . ' UTC' ), $this->store->user_typing_at( $uid ) );
			if ( time() - $ref < ChatSettings::ai_delay() ) {
				return null;
			}
		}

		// فقط یک درخواست اجازهٔ پاسخ‌دادن به این دسته پیام را دارد.
		if ( ! $this->store->claim( $uid, (int) $last->id ) ) {
			return null;
		}

		$first_id = (int) $rows[0]->id;
		$created  = array();
		$took     = $this->store->has_admin_message_after( $uid, $user_id, $first_id );

		// ۱) پاسخ پیش‌فرض به پیام اول (فقط اولین بار)
		if ( ! $took && ! $this->store->has_earlier_user_message( $uid, $user_id, $first_id ) ) {
			$default = trim( (string) ChatSettings::get( 'fallback_reply' ) );
			if ( '' !== $default ) {
				$msg = $this->store->add_message(
					$uid,
					$user_id,
					array(
						'role'        => 'assistant',
						'content'     => $default,
						'suggestions' => ChatSettings::default_suggestions(),
					)
				);
				if ( $msg ) {
					$created[] = $msg;
				}
			}
		}

		// ۲) هوش مصنوعی (پیام‌های جمع‌شده یک‌جا)
		if ( (int) $visitor->ai_muted <= 0 && ! $took ) {
			$texts      = array();
			$attachment = null;
			foreach ( $rows as $row ) {
				if ( '' !== trim( (string) $row->content ) ) {
					$texts[] = (string) $row->content;
				}
				if ( '' !== (string) $row->attachment_type ) {
					$attachment = array(
						'type' => (string) $row->attachment_type,
						'url'  => (string) $row->attachment_url,
					);
				}
			}

			$history = array();
			foreach ( $this->store->history( $uid, $user_id, 10 ) as $m ) {
				$history[] = array(
					'role' => 'user' === $m['role'] ? 'user' : 'assistant',
					'text' => $m['text'],
				);
			}

			$reply = $this->engine->generate(
				array(
					'visitor_uid' => $uid,
					'user_id'     => $user_id,
					'name'        => (string) $visitor->name,
					'contact'     => (string) $visitor->contact,
					'message'     => implode( "\n", $texts ),
					'messages'    => $texts,
					'rows'        => $rows,
					'attachment'  => $attachment,
					'history'     => $history,
				)
			);
			if ( is_array( $reply ) ) {
				$msg = $this->store->add_message(
					$uid,
					$user_id,
					array(
						'role'        => 'assistant',
						'content'     => $reply['text'],
						'suggestions' => $reply['suggestions'],
					)
				);
				if ( $msg ) {
					$created[] = $msg;
				}
			}
		}
		// هوش مصنوعی پاسخ نمی‌دهد (خاموش یا پشتیبان انسانی جواب می‌دهد): هیچ درخواستی به سرور هوش مصنوعی زده نمی‌شود.
		// پیام‌ها با ai_sent = 0 می‌مانند و وقتی هوش مصنوعی فعال شد و کاربر پیام داد، همراه آن (به ترتیب) فرستاده می‌شوند.

		return $created;
	}
}
