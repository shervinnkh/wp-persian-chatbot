<?php
namespace PersianWPChatbot\Core;

use PersianWPChatbot\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		Schema::install();
		\PersianWPChatbot\Chat\Cleanup::schedule(); // پاک‌سازی روزانهٔ داده‌های چت (P-2)

		if ( false === get_option( 'pwc_installed_at' ) ) {
			add_option( 'pwc_installed_at', time(), '', false );
		}

		// پاک‌سازی روزانهٔ داده‌های چت (S-8).
		\PersianWPChatbot\Chat\Retention::schedule();

		do_action( 'pwc_activated' );
	}
}
