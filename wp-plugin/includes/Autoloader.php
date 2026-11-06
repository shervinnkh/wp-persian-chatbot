<?php
namespace PersianWPChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * بارگذار خودکار کلاس‌ها (PSR-4).
 *
 * PersianWPChatbot\Core\Plugin  =>  includes/Core/Plugin.php
 * PersianWPChatbot\Apis\Results =>  apis/Results.php
 */
final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( string $class ): void {
		if ( strpos( $class, self::PREFIX ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );

		// API‌های ورودی از سرور هوش مصنوعی در پوشهٔ apis/ هستند: PersianWPChatbot\Apis\X => apis/X.php
		if ( 0 === strpos( $relative, 'Apis\\' ) ) {
			$file = PWC_PATH . 'apis/' . str_replace( '\\', '/', substr( $relative, 5 ) ) . '.php';
		} else {
			$file = PWC_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		}

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
