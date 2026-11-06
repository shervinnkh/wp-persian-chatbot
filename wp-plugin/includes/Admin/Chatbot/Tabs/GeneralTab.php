<?php
namespace PersianWPChatbot\Admin\Chatbot\Tabs;

use PersianWPChatbot\Chat\BudgetState;
use PersianWPChatbot\Chat\Unread;
use PersianWPChatbot\Database\LogStore;
use PersianWPChatbot\Documents\Overview as DocsOverview;
use PersianWPChatbot\Extraction\Overview as ExtractionOverview;
use PersianWPChatbot\Support\Status;

defined( 'ABSPATH' ) || exit;

/**
 * تب «عمومی» (صفحهٔ اصلی): آمار کلی افزونه (فقط خواندنی، بدون JS).
 * هر گروه با چند COUNT/SUM سبک خوانده می‌شود: پیام‌ها، اطلاعات استخراج‌شده، اسناد و لاگ سرور.
 */
final class GeneralTab extends AbstractTab {

	public function id(): string {
		return 'general';
	}

	public function title(): string {
		return __( 'عمومی', 'persian-wp-chatbot' );
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function data(): array {
		// چت‌بات خاموش: فقط کلید وضعیت؛ هیچ آماری خوانده نمی‌شود.
		if ( ! Status::is_enabled() ) {
			return array( 'enabled' => false );
		}

		return array(
			'enabled'              => true,
			'unread_messages'      => Unread::count(),
			'unread_conversations' => Unread::conversations(),
			'extraction'           => ExtractionOverview::build(),
			'docs'                 => DocsOverview::build(),
			'logs'                 => LogStore::stats(),
			'budget_banner'        => BudgetState::banner(), // فقط get_option؛ بدون درخواست شبکه
		);
	}
}
