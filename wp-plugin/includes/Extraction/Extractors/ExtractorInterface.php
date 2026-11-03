<?php
namespace PersianAIAssistant\Extraction\Extractors;

defined( 'ABSPATH' ) || exit;

interface ExtractorInterface {

	public function id(): string;

	/**
	 * اندازهٔ پیش‌فرض هر دسته (batch) برای جلوگیری از تایم‌اوت.
	 */
	public function default_limit(): int;

	/**
	 * یک دسته از آیتم‌ها را برمی‌گرداند.
	 *
	 * @return array<string, mixed> items, total, offset, limit, next_offset, done, warnings, [taxonomies]
	 */
	public function extract( int $offset, int $limit ): array;
}
