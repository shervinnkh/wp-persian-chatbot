<?php
namespace PersianAIAssistant\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * تبدیل هر نوشته (پست، برگه، محصول، نوع‌نوشتهٔ سفارشی) به یک ساختار استاندارد و تمیز.
 */
final class PostMapper {

	/**
	 * @param array<string, mixed> $opts
	 * @return array<string, mixed>
	 */
	public static function map( \WP_Post $post, array $opts = array() ): array {
		$via     = array( 'wp_query' );
		$content = Content::resolve( $post, $opts );
		$via[]   = 'content:' . $content['method'];

		$images   = array();
		$featured = Media::attachment( (int) get_post_thumbnail_id( $post ) );
		if ( $featured ) {
			$featured['role']          = 'featured';
			$images[ $featured['url'] ] = $featured;
		}
		foreach ( $content['images'] as $image ) {
			if ( ! isset( $images[ $image['url'] ] ) ) {
				$images[ $image['url'] ] = array_filter( $image ) + array( 'role' => 'content' );
			}
		}

		$seo = SeoMeta::for_post( $post->ID );
		if ( $seo ) {
			$via[] = 'seo:' . $seo['plugin'];
		}

		$taxonomies = Terms::for_post( $post->ID, $post->post_type );
		$term_count = 0;
		foreach ( $taxonomies as $group ) {
			$term_count += count( $group['terms'] );
		}

		$custom = CustomFields::for_post( $post->ID, $via );
		$text   = $content['text'];
		$words  = Text::words( $text );

		$parent = null;
		if ( $post->post_parent ) {
			$parent_post = get_post( (int) $post->post_parent );
			if ( $parent_post ) {
				$parent = array(
					'id'    => (int) $parent_post->ID,
					'title' => Text::collapse( wp_strip_all_tags( $parent_post->post_title ) ),
				);
			}
		}

		$item = array(
			'id'            => (int) $post->ID,
			'type'          => $post->post_type,
			'title'         => Text::collapse( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ) ),
			'url'           => (string) get_permalink( $post ),
			'slug'          => urldecode( $post->post_name ),
			'date'          => mysql2date( 'c', $post->post_date_gmt, false ),
			'modified'      => mysql2date( 'c', $post->post_modified_gmt, false ),
			'author'        => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'parent'        => $parent,
			'template'      => (string) get_page_template_slug( $post ),
			'menu_order'    => (int) $post->menu_order ?: null,
			'excerpt'       => Html::to_text( (string) $post->post_excerpt ),
			'content'       => $text,
			'headings'      => $content['headings'],
			'taxonomies'    => $taxonomies,
			'images'        => array_values( $images ),
			'links'         => $content['links'],
			'seo'           => $seo,
			'custom_fields' => $custom,
			'comment_count' => (int) $post->comment_count ?: null,
			'extraction'    => array(
				'content_method' => $content['method'],
				'content_chars'  => Text::length( $text ),
				'words'          => $words,
				'alternatives'   => $content['candidates'],
				'via'            => $via,
			),
			'stats'         => array(
				'words'         => $words,
				'images'        => count( $images ),
				'terms'         => $term_count,
				'custom_fields' => count( $custom ),
				'empty_content' => '' === $text ? 1 : 0,
			),
		);

		return Normalizer::prune( $item );
	}

	/**
	 * بلوک جدید را بعد از کلیدی مشخص در آیتم درج می‌کند (برای نمایش مرتب).
	 *
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $new
	 * @return array<string, mixed>
	 */
	public static function insert_after( array $item, string $after, array $new ): array {
		$out = array();
		$done = false;
		foreach ( $item as $key => $value ) {
			$out[ $key ] = $value;
			if ( $key === $after ) {
				$out  = array_merge( $out, $new );
				$done = true;
			}
		}

		return $done ? $out : array_merge( $item, $new );
	}
}
