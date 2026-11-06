<?php
/**
 * Plugin Name:       چت بات هوشمند
 * Description:       چت‌بات هوشمند فارسی برای وردپرس؛ پاسخ‌گویی خودکار به بازدیدکنندگان بر اساس اطلاعات سایت شما با هوش مصنوعی (RAG).
 * Version:           1.0.0
 * Update URI:        https://github.com/OWNER/REPO
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            شروین نخعی‌نیا
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       persian-wp-chatbot
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'PWC_VERSION', '1.0.0' );
define( 'PWC_FILE', __FILE__ );
define( 'PWC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PWC_URL', plugin_dir_url( __FILE__ ) );
define( 'PWC_BASENAME', plugin_basename( __FILE__ ) );
define( 'PWC_MIN_PHP', '7.4' );
define( 'PWC_MIN_WP', '6.0' );

/*
 * بررسی حداقل نیازمندی‌ها پیش از بارگذاری هر کد دیگر.
 */
if (
	version_compare( PHP_VERSION, PWC_MIN_PHP, '<' ) ||
	version_compare( $GLOBALS['wp_version'], PWC_MIN_WP, '<' )
) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: minimum PHP version, 2: minimum WordPress version */
						__( 'افزونه چت بات هوشمند به PHP نسخه %1$s و وردپرس نسخه %2$s یا بالاتر نیاز دارد.', 'persian-wp-chatbot' ),
						PWC_MIN_PHP,
						PWC_MIN_WP
					)
				)
			);
		}
	);
	return;
}

require_once PWC_PATH . 'includes/Autoloader.php';
\PersianWPChatbot\Autoloader::register();

register_activation_hook( PWC_FILE, array( \PersianWPChatbot\Core\Activator::class, 'activate' ) );
register_deactivation_hook( PWC_FILE, array( \PersianWPChatbot\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\PersianWPChatbot\Core\Plugin::instance()->boot();
	}
);
