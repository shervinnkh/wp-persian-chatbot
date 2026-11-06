<?php
namespace PersianWPChatbot\Extraction\Extractors;

use PersianWPChatbot\Extraction\PostTypes;

defined( 'ABSPATH' ) || exit;

final class BlogExtractor extends AbstractPostExtractor {

	public function id(): string {
		return 'blog';
	}

	protected function post_types(): array {
		return PostTypes::blog();
	}
}
