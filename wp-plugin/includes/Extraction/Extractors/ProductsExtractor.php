<?php
namespace PersianAIAssistant\Extraction\Extractors;

use PersianAIAssistant\Extraction\PostTypes;
use PersianAIAssistant\Extraction\Support\Html;
use PersianAIAssistant\Extraction\Support\Media;
use PersianAIAssistant\Extraction\Support\Normalizer;
use PersianAIAssistant\Extraction\Support\PostMapper;
use PersianAIAssistant\Extraction\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * محصولات: ووکامرس (با API رسمی WC_Product) و در نبود آن، نوع‌نوشته‌های محصول‌مانند (EDD و ...).
 */
final class ProductsExtractor extends AbstractPostExtractor {

	private const GENERIC_COMMERCE_META = array( '_price', '_regular_price', '_sale_price', '_sku', '_stock', '_stock_status', 'edd_price', 'edd_variable_prices', 'edd_sku' );

	public function id(): string {
		return 'products';
	}

	public function default_limit(): int {
		return 10;
	}

	protected function post_types(): array {
		return PostTypes::products();
	}

	protected function map_post( \WP_Post $post ): array {
		$item = PostMapper::map( $post, $this->map_options() );

		if ( function_exists( 'wc_get_product' ) && 'product' === $post->post_type ) {
			return $this->woo( $post, $item );
		}

		$commerce = array();
		foreach ( self::GENERIC_COMMERCE_META as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( '' !== $value && false !== $value ) {
				$commerce[ ltrim( $key, '_' ) ] = is_scalar( $value ) ? $value : Normalizer::value( maybe_unserialize( $value ) );
			}
		}

		return $commerce ? PostMapper::insert_after( $item, 'slug', array( 'product' => Normalizer::prune( $commerce ) ) ) : $item;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function woo( \WP_Post $post, array $item ): array {
		$p = wc_get_product( $post->ID );
		if ( ! $p ) {
			return $item;
		}

		$product = array(
			'type'          => $p->get_type(),
			'sku'           => $p->get_sku(),
			'price'         => $p->get_price(),
			'regular_price' => $p->get_regular_price(),
			'sale_price'    => $p->get_sale_price(),
			'on_sale'       => $p->is_on_sale(),
		);
		if ( $p->is_type( 'variable' ) ) {
			$product['price_min'] = $p->get_variation_price( 'min' );
			$product['price_max'] = $p->get_variation_price( 'max' );
		}
		$from = $p->get_date_on_sale_from();
		$to   = $p->get_date_on_sale_to();

		$dims = array_filter(
			array(
				'length' => $p->get_length(),
				'width'  => $p->get_width(),
				'height' => $p->get_height(),
				'unit'   => get_option( 'woocommerce_dimension_unit' ),
			)
		);

		$product += array(
			'sale_from'         => $from ? $from->date( 'c' ) : '',
			'sale_to'           => $to ? $to->date( 'c' ) : '',
			'currency'          => get_woocommerce_currency(),
			'currency_symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'stock_status'      => $p->get_stock_status(),
			'stock_quantity'    => $p->get_stock_quantity(),
			'manage_stock'      => $p->get_manage_stock(),
			'backorders'        => $p->get_backorders(),
			'sold_individually' => $p->get_sold_individually(),
			'virtual'           => $p->is_virtual(),
			'downloadable'      => $p->is_downloadable(),
			'weight'            => $p->get_weight() ? trim( $p->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' ) ) : '',
			'dimensions'        => count( $dims ) > 1 ? $dims : array(),
			'total_sales'       => (int) $p->get_total_sales(),
			'rating'            => $p->get_average_rating(),
			'rating_count'      => (int) $p->get_rating_count(),
			'review_count'      => (int) $p->get_review_count(),
		);
		if ( method_exists( $p, 'get_global_unique_id' ) ) {
			$product['global_unique_id'] = $p->get_global_unique_id();
		}
		if ( $p->is_type( 'external' ) ) {
			$product['external_url'] = $p->get_product_url();
			$product['button_text']  = $p->get_button_text();
		}
		if ( $p->is_type( 'grouped' ) ) {
			$product['grouped_children'] = $this->titles( $p->get_children() );
		}

		$attributes = array();
		foreach ( $p->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$name    = $attribute->get_name();
			$options = $attribute->is_taxonomy() ? wc_get_product_terms( $p->get_id(), $name, array( 'fields' => 'names' ) ) : $attribute->get_options();
			$attributes[] = array(
				'name'                => wc_attribute_label( $name, $p ),
				'options'             => array_values( array_map( static function ( $o ) {
					return Text::collapse( html_entity_decode( (string) $o, ENT_QUOTES, 'UTF-8' ) );
				}, (array) $options ) ),
				'visible'             => $attribute->get_visible(),
				'used_for_variations' => $attribute->get_variation(),
			);
		}

		$variations = array();
		if ( $p->is_type( 'variable' ) ) {
			foreach ( array_slice( $p->get_children(), 0, 50 ) as $variation_id ) {
				$v = wc_get_product( $variation_id );
				if ( ! $v instanceof \WC_Product_Variation ) {
					continue;
				}
				$attrs = array();
				foreach ( $v->get_variation_attributes( false ) as $key => $value ) {
					$label = wc_attribute_label( $key, $p );
					$term  = taxonomy_exists( $key ) && '' !== $value ? get_term_by( 'slug', $value, $key ) : false;
					$attrs[ $label ] = $term ? $term->name : ( '' === $value ? '—' : $value );
				}
				$variations[] = Normalizer::prune(
					array(
						'id'             => $v->get_id(),
						'sku'            => $v->get_sku(),
						'attributes'     => $attrs,
						'price'          => $v->get_price(),
						'regular_price'  => $v->get_regular_price(),
						'sale_price'     => $v->get_sale_price(),
						'stock_status'   => $v->get_stock_status(),
						'stock_quantity' => $v->get_stock_quantity(),
						'description'    => Html::to_text( (string) $v->get_description() ),
					)
				);
			}
		}

		$reviews = array();
		foreach ( get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => 20,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		) as $comment ) {
			$reviews[] = Normalizer::prune(
				array(
					'rating'  => (int) get_comment_meta( $comment->comment_ID, 'rating', true ),
					'content' => Html::to_text( (string) $comment->comment_content ),
					'date'    => mysql2date( 'c', $comment->comment_date_gmt, false ),
				)
			);
		}

		$related = Normalizer::prune(
			array(
				'upsells'     => $this->titles( $p->get_upsell_ids() ),
				'cross_sells' => $this->titles( $p->get_cross_sell_ids() ),
			)
		);

		// تصاویر گالری به فهرست تصاویر اضافه می‌شود.
		$images = isset( $item['images'] ) ? $item['images'] : array();
		$known  = array();
		foreach ( $images as $image ) {
			$known[ $image['url'] ] = true;
		}
		foreach ( $p->get_gallery_image_ids() as $attachment_id ) {
			$media = Media::attachment( (int) $attachment_id );
			if ( $media && ! isset( $known[ $media['url'] ] ) ) {
				$images[] = $media + array( 'role' => 'gallery' );
			}
		}

		$item = PostMapper::insert_after(
			$item,
			'slug',
			array(
				'product'    => Normalizer::prune( $product ),
				'attributes' => $attributes,
				'variations' => $variations,
				'reviews'    => $reviews,
				'related'    => $related,
			)
		);
		$item['images'] = $images;

		$item['stats']['images']     = count( $images );
		$item['stats']['attributes'] = count( $attributes );
		$item['stats']['variations'] = count( $variations );
		$item['stats']['reviews']    = count( $reviews );
		if ( isset( $item['extraction']['via'] ) ) {
			$item['extraction']['via'][] = 'woocommerce:WC_Product';
		}

		return Normalizer::prune( $item );
	}

	/**
	 * @param int[] $ids
	 * @return string[]
	 */
	private function titles( array $ids ): array {
		$out = array();
		foreach ( array_slice( $ids, 0, 20 ) as $id ) {
			$title = get_the_title( (int) $id );
			if ( '' !== $title ) {
				$out[] = Text::collapse( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) );
			}
		}

		return $out;
	}
}
