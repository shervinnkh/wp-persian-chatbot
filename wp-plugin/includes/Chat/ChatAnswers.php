<?php
/**
 * پاسخ‌های غیرهمزمان هوش مصنوعی.
 *
 * ReplyEngine پیام را با callback به سرور می‌دهد و سرور فوراً «queued» برمی‌گرداند؛ وقتی پاسخ آماده شد سرور آن را
 * با وب‌هوک امضادارِ معمول (POST /paia/v1/ai/results) در قالب آیتمی با kind = "chat_answer" می‌فرستد و اینجا به‌صورت پیام
 * «assistant» در دیتابیس ذخیره می‌شود؛ ویجت با polling عادی آن را می‌بیند.
 *
 * وب‌هوک اولِ هر درخواست (kind = "chat_messages_saved"، قبل از تولید پاسخ) فقط می‌گوید پیام‌ها در سرور ثبت شده‌اند: apply_saved.
 *
 * تا رسیدن پاسخ، یک transient کوتاه «در انتظار پاسخ» نگه داشته می‌شود تا ویجت نقطه‌های تایپ را نشان دهد؛
 * اگر وب‌هوک هرگز نرسد خودش بعد از چند دقیقه منقضی می‌شود (ویجت دیگر منتظر نمی‌ماند).
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Database\LogStore;

defined( 'ABSPATH' ) || exit;

final class ChatAnswers {

	/** بیشترین مدت نمایش «در حال تایپ» برای پاسخ در راه (ثانیه). */
	private const WAIT = 180;

	private static function wait_key( string $uid ): string {
		return 'paia_wait_' . md5( $uid );
	}

	public static function mark_waiting( string $uid ): void {
		set_transient( self::wait_key( $uid ), 1, self::WAIT );
	}

	public static function clear_waiting( string $uid ): void {
		delete_transient( self::wait_key( $uid ) );
	}

	public static function is_waiting( string $uid ): bool {
		return false !== get_transient( self::wait_key( $uid ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $items آیتم‌های kind = chat_answer.
	 * @return array{saved:int, failed:int, ignored:int, marked:int} marked = پاسخ‌های «Mark-Chat» (فقط گفتگو مارک شد)
	 */
	public static function apply( array $items ): array {
		$out   = array(
			'saved'   => 0,
			'failed'  => 0,
			'ignored' => 0,
			'marked'  => 0,
		);
		$store = new ChatStore();

		foreach ( $items as $item ) {
			$uid     = isset( $item['visitor_uid'] ) && is_scalar( $item['visitor_uid'] ) ? preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $item['visitor_uid'] ) : '';
			$visitor = '' !== $uid ? $store->visitor( (string) $uid ) : null;
			if ( ! $visitor ) {
				++$out['ignored'];
				continue;
			}
			self::clear_waiting( (string) $uid );

			$job = isset( $item['job_id'] ) && is_scalar( $item['job_id'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $item['job_id'] ) : '';
			if ( 'answered' !== ( $item['status'] ?? '' ) ) {
				++$out['failed'];
				LogStore::add(
					LogStore::ERROR,
					__( 'سرور هوش مصنوعی نتوانست به پیام کاربر پاسخ بدهد.', 'persian-ai-assistant' ),
					array_filter(
						array(
							'endpoint' => '/paia/v1/ai/results',
							'job_id'   => $job,
							'error'    => isset( $item['error'] ) && is_scalar( $item['error'] ) ? sanitize_text_field( (string) $item['error'] ) : '',
						),
						'strlen'
					)
				);
				continue;
			}

			// تکرار همان پاسخ (وب‌هوک دوباره ارسال شده) دوباره ذخیره نمی‌شود.
			$dedupe = 'paia_ans_' . md5( $job );
			if ( '' !== $job && false !== get_transient( $dedupe ) ) {
				++$out['ignored'];
				continue;
			}
			// در این فاصله هوش مصنوعی برای این گفتگو خاموش شده (مثلاً پشتیبان انسانی آمده): پاسخ نمایش داده نمی‌شود.
			if ( (int) $visitor->ai_muted > 0 ) {
				++$out['ignored'];
				continue;
			}

			$msg  = isset( $item['message'] ) && is_array( $item['message'] ) ? $item['message'] : array();
			$text = isset( $msg['content'] ) && is_string( $msg['content'] ) ? trim( $msg['content'] ) : '';
			if ( '' === $text ) {
				++$out['ignored'];
				continue;
			}

			// پاسخ دقیقاً «Mark-Chat»: هیچ پیامی ذخیره/نمایش داده نمی‌شود، فقط گفتگو مارک می‌شود (سرور هم آن را در تاریخچهٔ خودش نگه نمی‌دارد).
			if ( AiMark::is_mark_reply( $text ) ) {
				AiMark::handle( (string) $uid );
				if ( '' !== $job ) {
					set_transient( $dedupe, 1, DAY_IN_SECONDS );
				}
				++$out['marked'];
				continue;
			}

			$saved = $store->add_message(
				(string) $uid,
				(int) ( $item['wp_user_id'] ?? 0 ),
				array(
					'role'        => 'assistant',
					'content'     => $text,
					'suggestions' => self::suggestions( $msg['suggestions'] ?? array() ),
					// ارجاع‌ها (محصول/مقاله/صفحهٔ سایت که پاسخ دربارهٔ آن‌هاست): هنگام نمایش از دیتابیس خوانده و کارت می‌شوند.
					'references'  => MessageLinks::clean_references( $msg['references'] ?? array() ),
				)
			);
			if ( $saved ) {
				++$out['saved'];
				if ( '' !== $job ) {
					set_transient( $dedupe, 1, DAY_IN_SECONDS );
				}
			} else {
				++$out['failed'];
			}
		}

		return $out;
	}

	/**
	 * وب‌هوک اول: سرور پیام‌ها را در دیتابیس خودش ذخیره کرده (قبل از تولید پاسخ) و می‌گوید کدام‌ها ثبت شدند.
	 * پیام‌های ثبت‌شده ai_sent = 1 می‌گیرند تا حتی اگر پاسخ HTTP درخواست گم شده باشد (timeout) یا تولید پاسخ شکست بخورد،
	 * دوباره به سرور فرستاده نشوند. تکرارِ همین وب‌هوک بی‌ضرر است.
	 *
	 * @param array<int, array<string, mixed>> $items آیتم‌های kind = chat_messages_saved.
	 * @return array{marked:int}
	 */
	public static function apply_saved( array $items ): array {
		$ids = array();
		foreach ( $items as $item ) {
			$list = isset( $item['messages'] ) && is_array( $item['messages'] ) ? $item['messages'] : array();
			foreach ( $list as $m ) {
				// external_id = شناسهٔ ردیف پیام در سایت که خودمان فرستاده بودیم.
				if ( is_array( $m ) && isset( $m['external_id'] ) && is_scalar( $m['external_id'] ) && ctype_digit( (string) $m['external_id'] ) ) {
					$ids[] = (int) $m['external_id'];
				}
			}
		}
		if ( $ids ) {
			( new ChatStore() )->mark_sent( $ids );
		}

		return array( 'marked' => count( $ids ) );
	}

	/**
	 * @param mixed $list پیشنهادها.
	 * @return string[]
	 */
	private static function suggestions( $list ): array {
		$res = array();
		foreach ( is_array( $list ) ? $list : array() as $s ) {
			$s = trim( sanitize_text_field( is_scalar( $s ) ? (string) $s : '' ) );
			if ( '' !== $s ) {
				$res[] = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 80 ) : substr( $s, 0, 80 );
			}
			if ( count( $res ) >= 4 ) {
				break;
			}
		}

		return $res;
	}
}
