<?php
/**
 * مارک کردن گفتگو توسط هوش مصنوعی (از ۰.۵.۲۶).
 *
 * وقتی پاسخ هوش مصنوعی دقیقاً «Mark-Chat» باشد یعنی نمی‌تواند به پرسش کاربر جواب بدهد: هیچ پیامی در چت ذخیره/نمایش
 * داده نمی‌شود و فقط گفتگو مارک می‌شود تا پشتیبانان پاسخ بدهند.
 * تنظیم «مارک کردن چت توسط هوش مصنوعی» (`ai_mark`) به‌صورت `mark-the-conversation: true|false` همراه پیام به سرور می‌رود.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class AiMark {

	/** متن قراردادیِ پاسخ هوش مصنوعی. */
	public const REPLY = 'Mark-Chat';

	/**
	 * آیا این پاسخ همان نشانهٔ «فقط مارک کن» است؟ (فاصله‌های دور متن و تفاوت حروف بزرگ/کوچک نادیده گرفته می‌شود.)
	 *
	 * @param mixed $text متن پاسخ.
	 */
	public static function is_mark_reply( $text ): bool {
		return is_string( $text ) && 0 === strcasecmp( trim( $text ), self::REPLY );
	}

	/** آیا تنظیم «مارک کردن چت توسط هوش مصنوعی» روشن است؟ */
	public static function enabled(): bool {
		return (bool) ChatSettings::get( 'ai_mark', 0 );
	}

	/**
	 * پاسخ «Mark-Chat» رسید: تنظیم روشن = گفتگو مارک می‌شود. در هر حال هیچ پیامی نمایش داده نمی‌شود
	 * (اگر تنظیم خاموش باشد سرور اصلاً چنین پاسخی نمی‌دهد؛ این فقط محافظ است).
	 */
	public static function handle( string $uid ): void {
		if ( self::enabled() ) {
			self::mark( $uid );
		}
	}

	/**
	 * گفتگو را مارک می‌کند (هیچ پیامی اضافه نمی‌شود).
	 */
	public static function mark( string $uid ): void {
		global $wpdb;
		if ( '' === $uid ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( ChatSchema::visitors_table(), array( 'marked' => 1 ), array( 'uid' => $uid ) );
	}
}
