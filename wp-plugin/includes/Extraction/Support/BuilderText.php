<?php
namespace PersianAIAssistant\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * متن صفحه‌سازهایی که محتوا را خارج از post_content ذخیره می‌کنند (Elementor, Bricks).
 * بقیه (Divi, WPBakery, Gutenberg, Beaver) داخل post_content هستند و با Content پوشش داده می‌شوند.
 */
final class BuilderText {

	private const KEYS = array(
		'title', 'sub_title', 'subtitle', 'description', 'description_text', 'title_text', 'editor', 'text', 'content',
		'tab_title', 'tab_content', 'heading', 'caption', 'html', 'testimonial_content', 'testimonial_name', 'testimonial_job',
		'item_description', 'alert_title', 'alert_description', 'inner_text', 'before_text', 'after_text', 'highlighted_text',
		'blockquote_content', 'list_title', 'list_content', 'label',
	);

	/**
	 * @return array{text:string, builder:string}|null
	 */
	public static function for_post( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( $raw ) {
			$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			if ( is_array( $data ) ) {
				$texts = array();
				self::elementor( $data, $texts );

				return array(
					'text'    => Text::lines_clean( implode( "\n\n", $texts ) ),
					'builder' => 'elementor',
				);
			}
		}

		$bricks = get_post_meta( $post_id, '_bricks_page_content_2', true );
		if ( is_array( $bricks ) && $bricks ) {
			$texts = array();
			foreach ( $bricks as $element ) {
				if ( is_array( $element ) && ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
					self::collect( $element['settings'], $texts );
				}
			}

			return array(
				'text'    => Text::lines_clean( implode( "\n\n", $texts ) ),
				'builder' => 'bricks',
			);
		}

		return null;
	}

	/**
	 * @param array<int, mixed> $elements
	 * @param string[]          $texts
	 */
	private static function elementor( array $elements, array &$texts ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			if ( $settings ) {
				self::collect( $settings, $texts );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::elementor( $element['elements'], $texts );
			}
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param string[]             $texts
	 */
	private static function collect( array $settings, array &$texts, int $depth = 0 ): void {
		foreach ( $settings as $key => $value ) {
			if ( is_string( $value ) && in_array( (string) $key, self::KEYS, true ) ) {
				$text = Html::to_text( $value );
				if ( '' !== $text && ! is_numeric( $text ) ) {
					$texts[] = $text;
				}
			} elseif ( is_array( $value ) && $depth < 3 ) {
				self::collect( $value, $texts, $depth + 1 );
			}
		}
	}
}
