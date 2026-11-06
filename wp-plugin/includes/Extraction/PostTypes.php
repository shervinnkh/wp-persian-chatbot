<?php
namespace PersianWPChatbot\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * تشخیص نقش نوع‌نوشته‌ها (محصول / وبلاگ / برگه) بدون وابستگی به یک افزونهٔ خاص.
 * فیلترها: pwc_product_post_types, pwc_blog_post_types.
 */
final class PostTypes {

	private const INTERNAL = array(
		'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
		'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
		'elementor_library', 'e-floating-buttons', 'wpcf7_contact_form', 'acf-field-group', 'acf-field', 'shop_order', 'shop_coupon',
		'product_variation', 'shop_order_refund', 'scheduled-action',
	);

	/** @return string[] */
	public static function products(): array {
		return self::existing( (array) apply_filters( 'pwc_product_post_types', array( 'product', 'download', 'wpsc-product', 'jigoshop_product' ) ) );
	}

	/** @return string[] */
	public static function pages(): array {
		return array( 'page' );
	}

	/** @return string[] پست‌های وبلاگ: post + نوع‌های عمومی با نام خبری/مقاله‌ای */
	public static function blog(): array {
		$types = array( 'post' );
		foreach ( self::public_types() as $name => $object ) {
			if ( 'post' === $name || 'page' === $name ) {
				continue;
			}
			if ( 1 === preg_match( '/(^|[-_])(blog|news|article|articles|story|stories|magazine|journal|tutorial|knowledge)([-_]|$)/i', $name )
				&& ! in_array( $name, self::products(), true ) ) {
				$types[] = $name;
			}
		}

		return self::existing( (array) apply_filters( 'pwc_blog_post_types', $types ) );
	}

	/**
	 * نقش یک نوع‌نوشته برای گزارش سایت.
	 */
	public static function role_of( string $type ): string {
		if ( in_array( $type, self::products(), true ) ) {
			return 'products';
		}
		if ( in_array( $type, self::blog(), true ) ) {
			return 'blog';
		}
		if ( 'page' === $type ) {
			return 'pages';
		}

		return '';
	}

	/**
	 * @return array<string, \WP_Post_Type>
	 */
	public static function public_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( ! in_array( $name, self::INTERNAL, true ) ) {
				$out[ $name ] = $object;
			}
		}

		return $out;
	}

	/**
	 * @param string[] $types
	 * @return string[]
	 */
	private static function existing( array $types ): array {
		return array_values( array_filter( array_unique( array_map( 'strval', $types ) ), 'post_type_exists' ) );
	}
}
