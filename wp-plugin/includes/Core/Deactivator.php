<?php
namespace PersianAIAssistant\Core;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate(): void {
		// کرون بازبینی وضعیت رکوردهای هوش مصنوعی (apis/Reconciler.php).
		\PersianAIAssistant\Apis\Reconciler::unschedule();
		// پاک‌سازی روزانهٔ داده‌های چت (includes/Chat/Cleanup.php).
		\PersianAIAssistant\Chat\Cleanup::unschedule();

		// پاک‌سازی روزانهٔ داده‌های چت (S-8).
		\PersianAIAssistant\Chat\Retention::unschedule();

		do_action( 'paia_deactivated' );
	}
}
