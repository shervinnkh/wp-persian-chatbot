<?php
namespace PersianWPChatbot\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * دسته‌بندی‌ها، برچسب‌ها و هر طبقه‌بندی (taxonomy) دیگری که افزونه‌ها یا قالب ثبت کرده‌اند.
 */
final class Terms {

	private const EXCLUDED = array(
		'post_format', 'nav_menu', 'link_category', 'product_visibility', 'product_type', 'product_shipping_class',
		'wp_pattern_category', 'wp_theme', 'translation_priority', 'term_language', 'term_translations', 'post_translations',
	);

	/** @var array<string, string[]> */
	private static $taxonomy_cache = array();

	/**
	 * @return string[]
	 */
	public static function taxonomies_for( string $post_type ): array {
		if ( ! isset( self::$taxonomy_cache[ $post_type ] ) ) {
			$names = array();
			foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
				if ( ! in_array( $taxonomy, self::EXCLUDED, true ) ) {
					$names[] = $taxonomy;
				}
			}
			self::$taxonomy_cache[ $post_type ] = $names;
		}

		return self::$taxonomy_cache[ $post_type ];
	}

	/**
	 * طبقه‌بندی‌های یک نوشته: [taxonomy => [label, terms[]]]
	 *
	 * @return array<string, array{label:string, terms:string[]}>
	 */
	public static function for_post( int $post_id, string $post_type ): array {
		$out = array();
		foreach ( self::taxonomies_for( $post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( ! is_array( $terms ) || ! $terms ) {
				continue;
			}
			$object = get_taxonomy( $taxonomy );
			$names  = array();
			foreach ( $terms as $term ) {
				$names[] = self::path( $term );
			}
			$out[ $taxonomy ] = array(
				'label' => $object ? (string) $object->labels->name : $taxonomy,
				'terms' => $names,
			);
		}

		return $out;
	}

	/**
	 * مسیر سلسله‌مراتبی یک ترم: «والد > فرزند».
	 */
	public static function path( \WP_Term $term ): string {
		$names = array( $term->name );
		$guard = 0;
		$id    = (int) $term->parent;
		while ( $id > 0 && $guard++ < 8 ) {
			$parent = get_term( $id, $term->taxonomy );
			if ( ! $parent || is_wp_error( $parent ) ) {
				break;
			}
			array_unshift( $names, $parent->name );
			$id = (int) $parent->parent;
		}

		return html_entity_decode( implode( ' > ', $names ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * فهرست کامل ترم‌های طبقه‌بندی‌های نوع‌های نوشتهٔ داده‌شده (مستقل از اینکه به چه نوشته‌ای وصل‌اند).
	 *
	 * @param string[] $post_types
	 * @return array<int, array<string, mixed>>
	 */
	public static function catalog( array $post_types, int $cap = 1000 ): array {
		$taxonomies = array();
		foreach ( $post_types as $type ) {
			foreach ( self::taxonomies_for( $type ) as $taxonomy ) {
				$taxonomies[ $taxonomy ] = true;
			}
		}

		$out = array();
		foreach ( array_keys( $taxonomies ) as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			$total  = wp_count_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			$list   = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => $cap,
					'orderby'    => 'term_id',
				)
			);
			if ( is_wp_error( $list ) || ! is_array( $list ) ) {
				continue;
			}

			$by_id = array();
			foreach ( $list as $term ) {
				$by_id[ $term->term_id ] = $term;
			}

			$rows = array();
			foreach ( $list as $term ) {
				$names = array( $term->name );
				$p     = (int) $term->parent;
				$guard = 0;
				while ( $p > 0 && isset( $by_id[ $p ] ) && $guard++ < 8 ) {
					array_unshift( $names, $by_id[ $p ]->name );
					$p = (int) $by_id[ $p ]->parent;
				}
				$link   = get_term_link( $term );
				$rows[] = Normalizer::prune(
					array(
						'id'          => (int) $term->term_id,
						'name'        => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
						'path'        => html_entity_decode( implode( ' > ', $names ), ENT_QUOTES, 'UTF-8' ),
						'slug'        => urldecode( $term->slug ),
						'description' => Html::to_text( (string) $term->description ),
						'count'       => (int) $term->count,
						'url'         => is_wp_error( $link ) ? '' : $link,
					)
				);
			}

			$out[] = array(
				'taxonomy'  => $taxonomy,
				'label'     => $object ? (string) $object->labels->name : $taxonomy,
				'total'     => is_wp_error( $total ) ? count( $rows ) : (int) $total,
				'truncated' => ! is_wp_error( $total ) && (int) $total > count( $rows ),
				'terms'     => $rows,
			);
		}

		return $out;
	}
}
