<?php
namespace PersianWPChatbot\Admin\Chatbot\Tabs;

defined( 'ABSPATH' ) || exit;

final class SettingsTab extends AbstractTab {

	public function id(): string {
		return 'settings';
	}

	public function title(): string {
		return __( 'تنظیمات', 'persian-wp-chatbot' );
	}
}
