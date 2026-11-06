<?php
namespace PersianWPChatbot\Admin\Chatbot\Tabs;

use PersianWPChatbot\Support\View;

defined( 'ABSPATH' ) || exit;

abstract class AbstractTab implements TabInterface {

	/**
	 * قالب پیش‌فرض: views/admin/chatbot/tabs/{id}.php
	 */
	protected function view(): string {
		return 'admin/chatbot/tabs/' . $this->id();
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function data(): array {
		return array();
	}

	public function render(): void {
		View::render( $this->view(), $this->data() );
	}
}
