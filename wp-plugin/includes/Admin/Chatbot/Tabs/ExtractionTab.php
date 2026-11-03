<?php
namespace PersianAIAssistant\Admin\Chatbot\Tabs;

use PersianAIAssistant\Extraction\Overview;
use PersianAIAssistant\Extraction\Sources;

defined( 'ABSPATH' ) || exit;

final class ExtractionTab extends AbstractTab {

	public function id(): string {
		return 'extraction';
	}

	public function title(): string {
		return __( 'استخراج اطلاعات سایت', 'persian-ai-assistant' );
	}

	protected function data(): array {
		return array(
			'sources'  => Sources::all(),
			'overview' => Overview::build(),
		);
	}
}
