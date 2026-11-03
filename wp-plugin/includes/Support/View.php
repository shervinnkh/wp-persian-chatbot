<?php
namespace PersianAIAssistant\Support;

defined( 'ABSPATH' ) || exit;

/**
 * رندر قالب‌ها از پوشهٔ views تا منطق و HTML از هم جدا بمانند.
 */
final class View {

	/**
	 * @param string               $template مسیر نسبی بدون پسوند، مثل admin/chatbot/index
	 * @param array<string, mixed> $data     متغیرهایی که در قالب در دسترس‌اند.
	 */
	public static function render( string $template, array $data = array() ): void {
		$template = trim( str_replace( array( '..', '\\' ), '', $template ), '/' );
		$file     = PAIA_PATH . 'views/' . $template . '.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		( static function ( string $paia_file, array $paia_data ) {
			extract( $paia_data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $paia_file;
		} )( $file, $data );
	}
}
