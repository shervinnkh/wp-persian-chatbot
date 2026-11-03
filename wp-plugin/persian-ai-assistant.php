<?php
/**
 * Plugin Name:       دستیار هوشمند
 * Description:       سیستم مدیریت هوشمند وب‌سایت با هوش مصنوعی؛ شروع با چت‌بات مبتنی بر اطلاعات سایت و توسعه به ابزارهای مدیریتی بیشتر.
 * Version:           0.5.25
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            شروین نخعی‌نیا
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       persian-ai-assistant
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'PAIA_VERSION', '0.5.25' );
define( 'PAIA_FILE', __FILE__ );
define( 'PAIA_PATH', plugin_dir_path( __FILE__ ) );
define( 'PAIA_URL', plugin_dir_url( __FILE__ ) );
define( 'PAIA_BASENAME', plugin_basename( __FILE__ ) );
define( 'PAIA_MIN_PHP', '7.4' );
define( 'PAIA_MIN_WP', '6.0' );

/*
 * بررسی حداقل نیازمندی‌ها پیش از بارگذاری هر کد دیگر.
 */
if (
	version_compare( PHP_VERSION, PAIA_MIN_PHP, '<' ) ||
	version_compare( $GLOBALS['wp_version'], PAIA_MIN_WP, '<' )
) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: minimum PHP version, 2: minimum WordPress version */
						__( 'افزونه دستیار هوشمند به PHP نسخه %1$s و وردپرس نسخه %2$s یا بالاتر نیاز دارد.', 'persian-ai-assistant' ),
						PAIA_MIN_PHP,
						PAIA_MIN_WP
					)
				)
			);
		}
	);
	return;
}

require_once PAIA_PATH . 'includes/Autoloader.php';
\PersianAIAssistant\Autoloader::register();

register_activation_hook( PAIA_FILE, array( \PersianAIAssistant\Core\Activator::class, 'activate' ) );
register_deactivation_hook( PAIA_FILE, array( \PersianAIAssistant\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\PersianAIAssistant\Core\Plugin::instance()->boot();
	}
);
