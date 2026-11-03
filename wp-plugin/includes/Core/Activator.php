<?php
namespace PersianAIAssistant\Core;

use PersianAIAssistant\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		Schema::install();

		if ( false === get_option( 'paia_installed_at' ) ) {
			add_option( 'paia_installed_at', time(), '', false );
		}

		do_action( 'paia_activated' );
	}
}
