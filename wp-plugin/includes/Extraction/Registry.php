<?php
namespace PersianAIAssistant\Extraction;

use PersianAIAssistant\Extraction\Extractors\BlogExtractor;
use PersianAIAssistant\Extraction\Extractors\ExtractorInterface;
use PersianAIAssistant\Extraction\Extractors\PagesExtractor;
use PersianAIAssistant\Extraction\Extractors\ProductsExtractor;

defined( 'ABSPATH' ) || exit;

/**
 * نگاشت شناسهٔ منبع (Sources) به کلاس استخراج‌کننده. منبع جدید: فیلتر paia_extractors.
 */
final class Registry {

	/**
	 * @return array<string, class-string<ExtractorInterface>>
	 */
	public static function classes(): array {
		return (array) apply_filters(
			'paia_extractors',
			array(
				'products' => ProductsExtractor::class,
				'blog'     => BlogExtractor::class,
				'pages'    => PagesExtractor::class,
			)
		);
	}

	public static function make( string $id ): ?ExtractorInterface {
		$classes = self::classes();
		if ( empty( $classes[ $id ] ) || ! class_exists( $classes[ $id ] ) ) {
			return null;
		}
		$extractor = new $classes[ $id ]();

		return $extractor instanceof ExtractorInterface ? $extractor : null;
	}
}
