<?php
namespace PersianWPChatbot\Core;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate(): void {
		// کرون بازبینی وضعیت رکوردهای هوش مصنوعی (apis/Reconciler.php).
		\PersianWPChatbot\Apis\Reconciler::unschedule();
		// پاک‌سازی روزانهٔ داده‌های چت (includes/Chat/Cleanup.php).
		\PersianWPChatbot\Chat\Cleanup::unschedule();

		// پاک‌سازی روزانهٔ داده‌های چت (S-8).
		\PersianWPChatbot\Chat\Retention::unschedule();

		do_action( 'pwc_deactivated' );
	}
}
