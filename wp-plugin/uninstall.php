<?php
/**
 * اجرا هنگام حذف کامل افزونه.
 *
 * @package PersianWPChatbot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pwc_options = array(
	'pwc_settings',
	'pwc_version',
	'pwc_installed_at',
	'pwc_chat_settings',
	'pwc_chat_db_version',
	'pwc_logs_seeded',
	'pwc_chatbot_status',
	'pwc_budget_state',
	'pwc_update_info_last',
	'pwc_retention_days',
	'pwc_cleanup_batch',
	'pwc_tool_cache_ttl',
);

foreach ( $pwc_options as $pwc_option ) {
	delete_option( $pwc_option );
}
delete_transient( 'pwc_unread_msgs' );
delete_transient( 'pwc_api_authfail' );
delete_transient( 'pwc_reconcile_lock' );
delete_transient( 'pwc_reconcile_err' );
wp_clear_scheduled_hook( 'pwc_ai_reconcile' );
wp_clear_scheduled_hook( 'pwc_daily_cleanup' );
delete_site_transient( 'pwc_update_info' );

// جدول اطلاعات استخراج‌شده (فایل‌های افزونه در این مرحله هنوز بارگذاری نشده‌اند، پس مستقیم حذف می‌شود).
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_items`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_texts`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_files`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_logs`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_live_data`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_live_products`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_live_blog`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_live_pages`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_removed`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
delete_option( 'pwc_db_version' );

// شناسهٔ ارسال‌های سرور هوش مصنوعی (apis/): transientهای pwc_dlv_* (خودشان ۱۵ دقیقه‌ای منقضی می‌شوند).
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '\\_transient\\_pwc\\_dlv\\_%' OR `option_name` LIKE '\\_transient\\_timeout\\_pwc\\_dlv\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

// محدودیت نرخ (pwc_rl_* transient و، اگر ساخته شده باشد، جدول pwc_rl) — S-1/S-8.
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '\\_transient\\_pwc\\_rl\\_%' OR `option_name` LIKE '\\_transient\\_timeout\\_pwc\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_rl`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

// فایل‌های آپلودشدهٔ تب «اسناد اضافه» (uploads/pwc-docs) و پیوست‌های چت (uploads/pwc-chat، شامل index.php و .htaccess محافظ) هم
// همراه افزونه پاک می‌شوند.
$pwc_uploads = wp_upload_dir();
foreach ( array( 'pwc-docs', 'pwc-chat' ) as $pwc_dirname ) {
	$pwc_dir = untrailingslashit( (string) $pwc_uploads['basedir'] ) . '/' . $pwc_dirname;
	if ( ! is_dir( $pwc_dir ) || is_link( $pwc_dir ) ) {
		continue;
	}
	$pwc_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $pwc_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $pwc_iterator as $pwc_node ) {
		if ( $pwc_node->isDir() && ! $pwc_node->isLink() ) {
			@rmdir( $pwc_node->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		} else {
			@unlink( $pwc_node->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}
	@rmdir( $pwc_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}

// جدول‌های چت سایت (پیام‌ها و بازدیدکننده‌ها).
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_messages`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_chat_visitors`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'pwc_chat_status`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
