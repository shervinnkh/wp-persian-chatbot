<?php
namespace PersianWPChatbot\Support;

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
		$file     = PWC_PATH . 'views/' . $template . '.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		( static function ( string $pwc_file, array $pwc_data ) {
			extract( $pwc_data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $pwc_file;
		} )( $file, $data );
	}
}
