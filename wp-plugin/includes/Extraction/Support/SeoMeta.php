<?php
namespace PersianAIAssistant\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * عنوان و توضیح سئوی هر نوشته؛ از متای افزونه‌های رایج سئو خوانده می‌شود.
 */
final class SeoMeta {

	private const MAP = array(
		'Yoast SEO'         => array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
		),
		'Rank Math'         => array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		),
		'SEOPress'          => array(
			'title'         => '_seopress_titles_title',
			'description'   => '_seopress_titles_desc',
			'focus_keyword' => '_seopress_analysis_target_kw',
		),
		'All in One SEO'    => array(
			'title'         => '_aioseo_title',
			'description'   => '_aioseo_description',
			'focus_keyword' => '_aioseo_keywords',
		),
		'The SEO Framework' => array(
			'title'       => '_genesis_title',
			'description' => '_genesis_description',
		),
	);

	/**
	 * @return array<string, string>
	 */
	public static function for_post( int $post_id ): array {
		foreach ( self::MAP as $plugin => $keys ) {
			$found = array();
			foreach ( $keys as $field => $meta_key ) {
				$value = Text::collapse( (string) get_post_meta( $post_id, $meta_key, true ) );
				// الگوهای متغیر مثل %%title%% یا %title% اطلاعاتی ندارند.
				if ( '' !== $value && 1 !== preg_match( '/%{1,2}[a-z_]+%{1,2}/i', $value ) ) {
					$found[ $field ] = $value;
				}
			}
			if ( $found ) {
				return $found + array( 'plugin' => $plugin );
			}
		}

		return array();
	}
}
