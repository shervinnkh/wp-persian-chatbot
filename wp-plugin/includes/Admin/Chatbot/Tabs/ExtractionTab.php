<?php
namespace PersianWPChatbot\Admin\Chatbot\Tabs;

use PersianWPChatbot\Extraction\Overview;
use PersianWPChatbot\Extraction\Sources;

defined( 'ABSPATH' ) || exit;

final class ExtractionTab extends AbstractTab {

	public function id(): string {
		return 'extraction';
	}

	public function title(): string {
		return __( 'استخراج اطلاعات سایت', 'persian-wp-chatbot' );
	}

	protected function data(): array {
		return array(
			'sources'  => Sources::all(),
			'overview' => Overview::build(),
		);
	}
}
