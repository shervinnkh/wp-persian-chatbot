<?php
namespace PersianAIAssistant\Admin\Chatbot\Tabs;

defined( 'ABSPATH' ) || exit;

interface TabInterface {

	/** شناسهٔ یکتا (در URL و HTML استفاده می‌شود). */
	public function id(): string;

	public function title(): string;

	public function render(): void;
}
