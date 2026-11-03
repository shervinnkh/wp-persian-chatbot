<?php
namespace PersianAIAssistant\Admin\Pages;

use PersianAIAssistant\Admin\Chatbot\Tabs\ChatManageTab;
use PersianAIAssistant\Admin\Chatbot\Tabs\DocumentsTab;
use PersianAIAssistant\Admin\Chatbot\Tabs\ExtractionTab;
use PersianAIAssistant\Admin\Chatbot\Tabs\GeneralTab;
use PersianAIAssistant\Admin\Chatbot\Tabs\ServerLogTab;
use PersianAIAssistant\Admin\Chatbot\Tabs\SettingsTab;
use PersianAIAssistant\Admin\Chatbot\Tabs\TabInterface;
use PersianAIAssistant\Admin\Chatbot\Tabs\UpdatesTab;
use PersianAIAssistant\Support\Status;
use PersianAIAssistant\Support\View;

defined( 'ABSPATH' ) || exit;

final class ChatbotPage implements PageInterface {

	public const SLUG = 'paia-chatbot';

	public function slug(): string {
		return self::SLUG;
	}

	public function page_title(): string {
		return __( 'چت‌بات هوشمند', 'persian-ai-assistant' );
	}

	public function menu_title(): string {
		return __( 'چت‌بات هوشمند', 'persian-ai-assistant' );
	}

	public function uses_app_shell(): bool {
		return true;
	}

	public function render(): void {
		View::render(
			'admin/chatbot/index',
			array(
				'title'    => __( 'دستیار هوشمند', 'persian-ai-assistant' ),
				'tabs'     => $this->tabs(),
				'enabled'  => Status::is_enabled(),
			)
		);
	}

	/**
	 * تب‌ها به ترتیب نمایش. با فیلتر paia_chatbot_tabs می‌توان تب جدید اضافه کرد.
	 *
	 * @return TabInterface[]
	 */
	private function tabs(): array {
		$tabs = (array) apply_filters(
			'paia_chatbot_tabs',
			array(
				new GeneralTab(),
				new ChatManageTab(),
				new ExtractionTab(),
				new DocumentsTab(),
				new UpdatesTab(),
				new ServerLogTab(),
				new SettingsTab(),
			)
		);

		return array_values(
			array_filter(
				$tabs,
				static function ( $tab ) {
					return $tab instanceof TabInterface;
				}
			)
		);
	}
}
