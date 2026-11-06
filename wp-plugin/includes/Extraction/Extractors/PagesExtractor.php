<?php
namespace PersianWPChatbot\Extraction\Extractors;

use PersianWPChatbot\Extraction\PostTypes;

defined( 'ABSPATH' ) || exit;

final class PagesExtractor extends AbstractPostExtractor {

	public function id(): string {
		return 'pages';
	}

	protected function post_types(): array {
		return PostTypes::pages();
	}

	/**
	 * برگه‌های سیستمی ووکامرس (سبد، پرداخت، حساب کاربری) محتوای متنی ندارند و حذف می‌شوند.
	 */
	protected function query_args(): array {
		$exclude = array();
		foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $option ) {
			$id = (int) get_option( $option );
			if ( $id > 0 ) {
				$exclude[] = $id;
			}
		}

		return $exclude ? array( 'post__not_in' => $exclude ) : array();
	}
}
