<?php
namespace PersianAIAssistant\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * فیلدهای سفارشی: ACF (در صورت فعال بودن) + متای عمومی (کلیدهای بدون زیرخط اول).
 */
final class CustomFields {

	private const NOISE = '/^(_|rank_math_|seopress|_?yoast|aioseo|genesis_|footnotes$|slide_template$|classic-editor-remember$|ct_builder|et_pb_|fl_builder|bricks_|vc_|wpb_|wpml|icl_|trp_|elementor|wc_|_wc_)/i';

	/**
	 * @param string[] $via افزودن «acf» در صورت استفاده
	 * @return array<string, mixed>
	 */
	public static function for_post( int $post_id, array &$via ): array {
		$out = array();

		if ( function_exists( 'get_fields' ) ) {
			$acf = get_fields( $post_id );
			if ( is_array( $acf ) && $acf ) {
				foreach ( $acf as $key => $value ) {
					$normalized = Normalizer::value( $value );
					if ( null !== $normalized && array() !== $normalized ) {
						$out[ (string) $key ] = $normalized;
					}
				}
				if ( $out ) {
					$via[] = 'custom_fields:acf';
				}
			}
		}

		$meta = get_post_meta( $post_id );
		if ( is_array( $meta ) ) {
			$added = false;
			foreach ( $meta as $key => $values ) {
				$key = (string) $key;
				if ( isset( $out[ $key ] ) || 1 === preg_match( self::NOISE, $key ) || count( $out ) >= 60 ) {
					continue;
				}
				$values = array_map( 'maybe_unserialize', (array) $values );
				$value  = Normalizer::value( 1 === count( $values ) ? $values[0] : $values );
				if ( null !== $value && array() !== $value ) {
					$out[ $key ] = $value;
					$added       = true;
				}
			}
			if ( $added ) {
				$via[] = 'custom_fields:post_meta';
			}
		}

		return $out;
	}
}
