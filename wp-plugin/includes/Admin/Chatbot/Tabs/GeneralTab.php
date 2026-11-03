<?php
namespace PersianAIAssistant\Admin\Chatbot\Tabs;

use PersianAIAssistant\Chat\Unread;
use PersianAIAssistant\Database\LogStore;
use PersianAIAssistant\Documents\Overview as DocsOverview;
use PersianAIAssistant\Extraction\Overview as ExtractionOverview;
use PersianAIAssistant\Subscription\Service as Subscription;
use PersianAIAssistant\Support\Status;

defined( 'ABSPATH' ) || exit;

/**
 * تب «عمومی»: آمار کلی افزونه (فقط خواندنی، بدون JS).
 * هر گروه با چند COUNT/SUM سبک خوانده می‌شود: پیام‌ها، اطلاعات استخراج‌شده، اسناد و لاگ سرور.
 */
final class GeneralTab extends AbstractTab {

	public function id(): string {
		return 'general';
	}

	public function title(): string {
		return __( 'عمومی', 'persian-ai-assistant' );
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
			'subscription'         => Subscription::present( Subscription::stored() ),
		);
	}
}
