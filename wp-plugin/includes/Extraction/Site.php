<?php
namespace PersianWPChatbot\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * اطلاعات کلی سایت و محیط (قالب، افزونه‌های مؤثر، نوع‌نوشته‌ها) برای گزارش توسعه‌دهنده.
 */
final class Site {

	/**
	 * @return array<string, mixed>
	 */
	public static function info(): array {
		$theme = wp_get_theme();

		$builders = array();
		$checks   = array(
			'Elementor'    => defined( 'ELEMENTOR_VERSION' ),
			'Divi'         => defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_theme' ),
			'WPBakery'     => defined( 'WPB_VC_VERSION' ),
			'Beaver'       => class_exists( 'FLBuilder' ),
			'Bricks'       => defined( 'BRICKS_VERSION' ),
			'Oxygen'       => defined( 'CT_VERSION' ),
			'Gutenberg'    => function_exists( 'parse_blocks' ),
		);
		foreach ( $checks as $name => $on ) {
			if ( $on ) {
				$builders[] = $name;
			}
		}

		$seo    = array();
		$checks = array(
			'Yoast SEO'         => defined( 'WPSEO_VERSION' ),
			'Rank Math'         => class_exists( 'RankMath' ),
			'SEOPress'          => defined( 'SEOPRESS_VERSION' ),
			'All in One SEO'    => defined( 'AIOSEO_VERSION' ),
			'The SEO Framework' => defined( 'THE_SEO_FRAMEWORK_VERSION' ),
		);
		foreach ( $checks as $name => $on ) {
			if ( $on ) {
				$seo[] = $name;
			}
		}

		$multilingual = array();
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$multilingual[] = 'WPML';
		}
		if ( defined( 'POLYLANG_VERSION' ) ) {
			$multilingual[] = 'Polylang';
		}

		$woo = array( 'active' => class_exists( 'WooCommerce' ) );
		if ( $woo['active'] ) {
			$woo['version']  = defined( 'WC_VERSION' ) ? WC_VERSION : '';
			$woo['currency'] = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		}

		$types = array();
		foreach ( PostTypes::public_types() as $name => $object ) {
			$count   = wp_count_posts( $name );
			$types[] = array(
				'name'       => $name,
				'label'      => isset( $object->labels->name ) ? (string) $object->labels->name : $name,
				'count'      => isset( $count->publish ) ? (int) $count->publish : 0,
				'covered_by' => PostTypes::role_of( $name ),
			);
		}

		return array(
			'name'         => (string) get_bloginfo( 'name' ),
			'tagline'      => (string) get_bloginfo( 'description' ),
			'url'          => home_url( '/' ),
			'language'     => get_locale(),
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'multisite'    => is_multisite(),
			'theme'        => array_filter(
				array(
					'name'    => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
					'parent'  => $theme->parent() ? (string) $theme->parent()->get( 'Name' ) : '',
				)
			),
			'builders'     => $builders,
			'seo_plugins'  => $seo,
			'multilingual' => $multilingual,
			'woocommerce'  => $woo,
			'acf'          => function_exists( 'get_fields' ),
			'post_types'   => $types,
		);
	}
}
