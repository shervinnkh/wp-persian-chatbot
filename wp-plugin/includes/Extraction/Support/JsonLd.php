<?php
namespace PersianAIAssistant\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * خواندن داده‌های ساختاریافتهٔ schema.org (JSON-LD) از HTML صفحه.
 * همهٔ افزونه‌های سئو (Yoast, Rank Math, ...) این داده را در صفحهٔ عمومی تولید می‌کنند.
 */
final class JsonLd {

	/**
	 * @return array<int, array<string, mixed>> گره‌های مسطح‌شده (شامل @graph)
	 */
	public static function parse( string $html ): array {
		if ( 1 > preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $matches[1] as $raw ) {
			$data = json_decode( trim( $raw ), true );
			if ( null === $data ) {
				$data = json_decode( trim( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) ), true );
			}
			if ( ! is_array( $data ) ) {
				continue;
			}
			$list = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ? $data : array( $data ) );
			foreach ( $list as $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = $node;
				}
			}
		}

		return $nodes;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return string[]
	 */
	public static function types( array $node ): array {
		return array_map( 'strval', (array) ( $node['@type'] ?? array() ) );
	}
}
