<?php
namespace PersianAIAssistant\Admin\Chatbot\Tabs;

defined( 'ABSPATH' ) || exit;

final class SettingsTab extends AbstractTab {

	public function id(): string {
		return 'settings';
	}

	public function title(): string {
		return __( 'تنظیمات', 'persian-ai-assistant' );
	}
}
