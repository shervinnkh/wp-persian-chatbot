<?php
/**
 * جدول‌های چت: بازدیدکننده‌ها و پیام‌ها.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class ChatSchema {

	public const VERSION = '1.2.0';
	public const OPTION  = 'paia_chat_db_version';

	public static function visitors_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'paia_chat_visitors';
	}

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'paia_messages';
	}

	/** جدول وضعیت لحظه‌ای (تایپینگ کاربر/پشتیبان). */
	public static function status_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'paia_chat_status';
	}

	/**
	 * اگر جدول‌ها هنوز ساخته نشده یا نسخه قدیمی است می‌سازد/به‌روز می‌کند.
	 */
	public static function maybe_install(): void {
		$prev = get_option( self::OPTION );
		if ( $prev === self::VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$visitors = self::visitors_table();
		$messages = self::messages_table();

		dbDelta(
			"CREATE TABLE {$visitors} (
  uid varchar(36) NOT NULL,
  wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  contact varchar(190) NOT NULL DEFAULT '',
  profile_done tinyint(1) NOT NULL DEFAULT 0,
  marked tinyint(1) NOT NULL DEFAULT 0,
  ai_muted tinyint(1) NOT NULL DEFAULT 0,
  admin_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ai_done_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (uid),
  KEY wp_user_id (wp_user_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$messages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visitor_uid varchar(36) NOT NULL,
  wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  role varchar(12) NOT NULL DEFAULT 'user',
  content longtext NOT NULL,
  attachment_type varchar(10) NOT NULL DEFAULT '',
  attachment_url text NULL,
  attachment_path text NULL,
  suggestions text NULL,
  admin_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  admin_name varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY visitor_uid (visitor_uid,id),
  KEY wp_user_id (wp_user_id)
) {$charset};"
		);

		$status = self::status_table();
		dbDelta(
			"CREATE TABLE {$status} (
  visitor_uid varchar(36) NOT NULL,
  user_typing_at int(10) unsigned NOT NULL DEFAULT 0,
  admin_typing_at int(10) unsigned NOT NULL DEFAULT 0,
  admin_typing_by varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (visitor_uid)
) {$charset};"
		);

		// ارتقا از نسخه‌های قبل: پیام‌های قدیمی دوباره به هوش مصنوعی داده نشوند.
		if ( $prev && version_compare( (string) $prev, '1.2.0', '<' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "UPDATE {$visitors} v SET v.ai_done_id = COALESCE((SELECT MAX(m.id) FROM {$messages} m WHERE m.visitor_uid = v.uid), 0)" );
		}

		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * برای uninstall.php
	 */
	public static function drop(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::messages_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::visitors_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::status_table() );
		delete_option( self::OPTION );
		delete_option( ChatSettings::OPTION );
	}
}
