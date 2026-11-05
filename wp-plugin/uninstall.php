<?php
/**
 * اجرا هنگام حذف کامل افزونه.
 *
 * @package PersianAIAssistant
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$paia_options = array(
	'paia_settings',
	'paia_version',
	'paia_installed_at',
	'paia_chat_settings',
	'paia_chat_db_version',
	'paia_logs_seeded',
	'paia_chatbot_status',
	'paia_subscription',
	'paia_budget_state',
	'paia_update_info_last',
);

foreach ( $paia_options as $paia_option ) {
	delete_option( $paia_option );
}
delete_transient( 'paia_unread_msgs' );
delete_transient( 'paia_api_authfail' );
delete_transient( 'paia_reconcile_lock' );
delete_transient( 'paia_reconcile_err' );
wp_clear_scheduled_hook( 'paia_ai_reconcile' );
wp_clear_scheduled_hook( 'paia_daily_cleanup' );
delete_site_transient( 'paia_update_info' );

// جدول اطلاعات استخراج‌شده (فایل‌های افزونه در این مرحله هنوز بارگذاری نشده‌اند، پس مستقیم حذف می‌شود).
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_items`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_texts`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_files`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_logs`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_live_data`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_live_products`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_live_blog`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_live_pages`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_removed`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
delete_option( 'paia_db_version' );

// شناسهٔ ارسال‌های سرور هوش مصنوعی (apis/): transientهای paia_dlv_* (خودشان ۱۵ دقیقه‌ای منقضی می‌شوند).
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '\\_transient\\_paia\\_dlv\\_%' OR `option_name` LIKE '\\_transient\\_timeout\\_paia\\_dlv\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

// محدودیت نرخ (paia_rl_* transient و، اگر ساخته شده باشد، جدول paia_rl) — S-1/S-8.
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '\\_transient\\_paia\\_rl\\_%' OR `option_name` LIKE '\\_transient\\_timeout\\_paia\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_rl`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

// فایل‌های آپلودشدهٔ تب «اسناد اضافه» (uploads/paia-docs) و پیوست‌های چت (uploads/paia-chat، شامل index.php و .htaccess محافظ) هم
// همراه افزونه پاک می‌شوند.
$paia_uploads = wp_upload_dir();
foreach ( array( 'paia-docs', 'paia-chat' ) as $paia_dirname ) {
	$paia_dir = untrailingslashit( (string) $paia_uploads['basedir'] ) . '/' . $paia_dirname;
	if ( ! is_dir( $paia_dir ) || is_link( $paia_dir ) ) {
		continue;
	}
	$paia_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $paia_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $paia_iterator as $paia_node ) {
		if ( $paia_node->isDir() && ! $paia_node->isLink() ) {
			@rmdir( $paia_node->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		} else {
			@unlink( $paia_node->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}
	@rmdir( $paia_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}

// جدول‌های چت سایت (پیام‌ها و بازدیدکننده‌ها).
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_messages`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_chat_visitors`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'paia_chat_status`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
