<?php
/**
 * پاسخ‌های غیرهمزمان هوش مصنوعی.
 *
 * ReplyEngine پیام را با callback به سرور می‌دهد و سرور فوراً «queued» برمی‌گرداند؛ وقتی پاسخ آماده شد سرور آن را
 * با وب‌هوک امضادارِ معمول (POST /paia/v1/ai/results) در قالب آیتمی با kind = "chat_answer" می‌فرستد و اینجا به‌صورت پیام
 * «assistant» در دیتابیس ذخیره می‌شود؛ ویجت با polling عادی آن را می‌بیند.
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
	 * @return array{saved:int, failed:int, ignored:int}
	 */
	public static function apply( array $items ): array {
		$out   = array(
			'saved'   => 0,
			'failed'  => 0,
			'ignored' => 0,
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
			if ( AiMark::is_mark_reply( $text ) ) {
				// «Mark-Chat»: هیچ پیامی ذخیره نمی‌شود؛ فقط گفتگو مارک می‌شود.
				AiMark::mark( (string) $uid );
				if ( '' !== $job ) {
					set_transient( $dedupe, 1, DAY_IN_SECONDS );
				}
				++$out['saved'];
				continue;
			}
			if ( '' === $text ) {
				++$out['ignored'];
				continue;
			}

			$saved = $store->add_message(
				(string) $uid,
				(int) ( $item['wp_user_id'] ?? 0 ),
				array(
					'role'        => 'assistant',
					'content'     => $text,
					'suggestions' => self::suggestions( $msg['suggestions'] ?? array() ),
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
