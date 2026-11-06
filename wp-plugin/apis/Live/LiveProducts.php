<?php
namespace PersianWPChatbot\Apis\Live;

use PersianWPChatbot\Database\LiveStore;
use PersianWPChatbot\Extraction\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * API شمارهٔ ۱ سرور (POST /live/products): متن ساختاریافتهٔ «قیمت و موجودی زنده» چند محصول.
 *
 * قالب خروجی دقیقاً طبق LIVE_TOOLS_API.md §5: برای هر مورد درخواست یک بلاک (به همان ترتیب) با خطوط «کلید: مقدار».
 * این متن عیناً وارد پرامپت می‌شود؛ پس فقط فیلدهایی می‌آیند که هم سرور خواسته (fields) و هم مدیر در تب «اطلاعات به‌روز»
 * روشن گذاشته (LiveStore)، و فقط محصولات منتشرشدهٔ بدون رمز.
 *
 * ووکامرس (WC_Product) اولویت دارد؛ برای نوع‌نوشته‌های محصول‌مانند دیگر (EDD و …) یک مسیر ساده‌تر از متای قیمت/موجودی هست.
 */
final class LiveProducts {

	/** سقف مورد در هر فراخوانی (TOOLS_MAX_PRODUCTS_PER_CALL سرور). */
	public const MAX_REQUESTS = 5;

	/** حداکثر نتیجه برای جست‌وجوی نام. */
	public const MAX_CANDIDATES = 3;

	private const MAX_VARIATIONS = 10;

	private const STATUS_FOUND    = 'پیدا شد';
	private const STATUS_MISSING  = 'پیدا نشد';
	private const STATUS_MULTIPLE = 'چند نتیجه (حداکثر 3 مورد؛ AI از کاربر می‌پرسد کدام)';

	/** @var array<string, bool> کلیدهای فیلد زندهٔ مجاز این فراخوانی (price, regular_price, on_sale, stock_status, stock_qty, backorder). */
	private $keys = array();

	/**
	 * @param string[] $groups گروه‌های مجاز: price | stock (اشتراک خواستهٔ سرور و مجوز فعلی مدیر).
	 */
	private function __construct( array $groups ) {
		$live = LiveStore::ai_product_keys();
		foreach ( $groups as $group ) {
			foreach ( LiveStore::AI_PRODUCT_GROUPS[ $group ] ?? array() as $key ) {
				if ( isset( $live[ $key ] ) ) {
					$this->keys[ $key ] = true;
				}
			}
		}
	}

	/**
	 * @param string[]                          $groups   گروه‌های مجاز (price | stock).
	 * @param array<int, array<string, mixed>> $requests هر مورد: ['id' => int] یا ['name' => string].
	 */
	public static function render( array $groups, array $requests ): string {
		$self   = new self( $groups );
		$blocks = array();
		$seen   = array();

		foreach ( array_slice( array_values( $requests ), 0, self::MAX_REQUESTS ) as $i => $query ) {
			$blocks[] = $self->block( $i + 1, is_array( $query ) ? $query : array(), $seen );
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * شناسهٔ محصول از مقدار خام: عدد صحیح، رشتهٔ عددی یا «product:23». 0 = نامعتبر.
	 *
	 * @param mixed $raw مقدار.
	 */
	public static function parse_id( $raw ): int {
		if ( is_bool( $raw ) ) {
			return 0;
		}
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 0;
		}
		if ( is_string( $raw ) && 1 === preg_match( '/(\d{1,12})\s*$/', trim( Text::latin_digits( $raw ) ), $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/* ------------------------------------------------------------------ بلاک هر درخواست */

	/**
	 * @param array<string, mixed> $query درخواست.
	 * @param array<int, bool>     $seen  شناسه‌های قبلاً آمده (تکراری دوباره ساخته نمی‌شود).
	 */
	private function block( int $n, array $query, array &$seen ): string {
		$id   = self::parse_id( $query['id'] ?? null );
		$name = isset( $query['name'] ) && is_string( $query['name'] ) ? Text::cut( Text::plain( $query['name'] ), 200 ) : '';

		if ( $id > 0 ) {
			$head = '[درخواست ' . $n . '] id=' . $id;
			if ( isset( $seen[ $id ] ) ) {
				return $head . "\nوضعیت جست‌وجو: " . self::STATUS_FOUND . "\n(تکراری: اطلاعات همین محصول در بلاک قبلی آمده است)";
			}
			$post = $this->product_post( $id );
			if ( ! $post ) {
				return $head . "\nوضعیت جست‌وجو: " . self::STATUS_MISSING;
			}
			$seen[ $id ] = true;

			return $head . "\nوضعیت جست‌وجو: " . self::STATUS_FOUND . "\n" . implode( "\n", $this->details( $post ) );
		}

		if ( '' === $name ) {
			return '[درخواست ' . $n . "]\nوضعیت جست‌وجو: " . self::STATUS_MISSING;
		}

		$head = '[درخواست ' . $n . '] name=' . $name;
		list( $exact, $others ) = $this->search( $name );

		if ( 1 === count( $exact ) ) {
			$post = $this->product_post( $exact[0] );
			if ( $post ) {
				$seen[ (int) $post->ID ] = true;

				return $head . "\nوضعیت جست‌وجو: " . self::STATUS_FOUND . "\n" . implode( "\n", $this->details( $post ) );
			}
		}

		$list = array_slice( array_merge( $exact, $others ), 0, self::MAX_CANDIDATES );
		if ( ! $list ) {
			return $head . "\nوضعیت جست‌وجو: " . self::STATUS_MISSING;
		}

		$rows = array();
		foreach ( $list as $candidate_id ) {
			$post = $this->product_post( (int) $candidate_id );
			if ( $post ) {
				$rows[] = '- ' . implode( ' | ', $this->summary( $post ) );
			}
		}

		return $rows
			? $head . "\nوضعیت جست‌وجو: " . self::STATUS_MULTIPLE . "\n" . implode( "\n", $rows )
			: $head . "\nوضعیت جست‌وجو: " . self::STATUS_MISSING;
	}

	/**
	 * فقط محصول منتشرشدهٔ بدون رمز از نوع‌نوشته‌های محصول (نه پیش‌نویس/خصوصی/رمزدار/تنوع).
	 */
	private function product_post( int $id ): ?\WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return null;
		}
		if ( ! in_array( $post->post_type, PostTypes::products(), true ) ) {
			return null;
		}

		return $post;
	}

	/* ------------------------------------------------------------------ جست‌وجوی نام */

	/**
	 * @return array{0:int[], 1:int[]} [تطابق دقیق عنوان، بقیهٔ نتایج (اول آن‌هایی که نام را در بر دارند)]
	 */
	private function search( string $name ): array {
		$types = PostTypes::products();
		$norm  = Text::normalize( $name );
		if ( ! $types || '' === $norm ) {
			return array( array(), array() );
		}

		$terms = array_values( array_unique( array( $norm, Text::arabic_variant( $norm ), $name ) ) );
		$ids   = array();
		foreach ( $terms as $term ) {
			$query = new \WP_Query(
				array(
					'post_type'           => $types,
					'post_status'         => 'publish',
					'has_password'        => false,
					's'                   => $term,
					'posts_per_page'      => 10,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
				)
			);
			foreach ( (array) $query->posts as $post_id ) {
				$ids[ (int) $post_id ] = true;
			}
		}

		$exact    = array();
		$contains = array();
		$rest     = array();
		foreach ( array_keys( $ids ) as $post_id ) {
			$title = Text::normalize( Text::plain( (string) get_the_title( $post_id ) ) );
			if ( $title === $norm ) {
				$exact[] = $post_id;
			} elseif ( false !== strpos( $title, $norm ) ) {
				$contains[] = $post_id;
			} else {
				$rest[] = $post_id;
			}
		}

		return array( $exact, array_merge( $contains, $rest ) );
	}

	/* ------------------------------------------------------------------ جزئیات یک محصول */

	/**
	 * @return string[] خطوط «کلید: مقدار»
	 */
	private function details( \WP_Post $post ): array {
		$product = $this->wc( $post );
		$lines   = array(
			'شناسه: ' . (int) $post->ID,
			'عنوان: ' . Text::plain( (string) get_the_title( $post ) ),
			'لینک: ' . (string) get_permalink( $post ),
		);

		if ( $product ) {
			$lines[] = 'نوع محصول: ' . $this->type_label( (string) $product->get_type() );
			$lines   = array_merge( $lines, $this->woo_price_lines( $product ), $this->woo_stock_lines( $product ), $this->woo_variation_lines( $product ) );
		} else {
			$lines = array_merge( $lines, $this->generic_lines( $post ) );
		}

		return $lines;
	}

	/**
	 * @return string[] اجزای یک ردیف «چند نتیجه»
	 */
	private function summary( \WP_Post $post ): array {
		$parts   = array( 'شناسه: ' . (int) $post->ID, 'عنوان: ' . Text::plain( (string) get_the_title( $post ) ) );
		$product = $this->wc( $post );

		if ( $product ) {
			if ( $this->has( 'price' ) ) {
				$parts[] = 'قیمت: ' . $this->price_text( $product );
			}
			if ( $this->has( 'stock_status' ) && '' !== $this->stock_label( $product ) ) {
				$parts[] = 'وضعیت موجودی: ' . $this->stock_label( $product );
			}

			return $parts;
		}

		foreach ( $this->generic_lines( $post ) as $line ) {
			if ( 0 === strpos( $line, 'قیمت:' ) || 0 === strpos( $line, 'وضعیت موجودی:' ) ) {
				$parts[] = $line;
			}
		}

		return $parts;
	}

	private function has( string $key ): bool {
		return isset( $this->keys[ $key ] );
	}

	/**
	 * @return \WC_Product|null
	 */
	private function wc( \WP_Post $post ) {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );

		return $product ? $product : null;
	}

	private function type_label( string $type ): string {
		$map = array(
			'simple'   => 'ساده',
			'variable' => 'متغیر',
			'grouped'  => 'گروهی',
			'external' => 'خارجی',
		);

		return $map[ $type ] ?? $type;
	}

	/* ------------------------------------------------------------------ ووکامرس: قیمت */

	private function unit(): string {
		return function_exists( 'get_woocommerce_currency' ) ? Text::currency_label( (string) get_woocommerce_currency() ) : '';
	}

	private function decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;
	}

	/**
	 * کمینه و بیشینهٔ قیمت قابل‌پرداخت (با تنظیم نمایش مالیات). null = قیمت تعیین نشده.
	 *
	 * @param \WC_Product $p محصول.
	 * @return array{0:float,1:float}|null
	 */
	private function price_range( $p ): ?array {
		if ( $p->is_type( 'variable' ) ) {
			$min = $p->get_variation_price( 'min', true );
			$max = $p->get_variation_price( 'max', true );
			if ( ! is_numeric( $min ) || ! is_numeric( $max ) ) {
				return null;
			}

			return array( (float) $min, (float) $max );
		}

		if ( $p->is_type( 'grouped' ) ) {
			$prices = array();
			foreach ( array_slice( (array) $p->get_children(), 0, 50 ) as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && 'publish' === $child->get_status() && '' !== (string) $child->get_price() ) {
					$prices[] = (float) wc_get_price_to_display( $child );
				}
			}

			return $prices ? array( min( $prices ), max( $prices ) ) : null;
		}

		if ( '' === (string) $p->get_price() ) {
			return null;
		}
		$now = (float) wc_get_price_to_display( $p );

		return array( $now, $now );
	}

	/**
	 * @param \WC_Product $p محصول.
	 */
	private function price_text( $p ): string {
		$range = $this->price_range( $p );
		if ( null === $range ) {
			return 'ثبت نشده';
		}
		$unit = $this->unit();
		$dec  = $this->decimals();
		$lo   = Text::number( $range[0], $dec );
		$hi   = Text::number( $range[1], $dec );
		$text = $lo === $hi ? $lo : 'از ' . $lo . ' تا ' . $hi;

		return trim( $text . ' ' . $unit );
	}

	/**
	 * @param \WC_Product $p محصول.
	 * @return string[]
	 */
	private function woo_price_lines( $p ): array {
		$lines = array();
		$unit  = $this->unit();
		$dec   = $this->decimals();
		$multi = $p->is_type( 'variable' ) || $p->is_type( 'grouped' );

		if ( $this->has( 'price' ) ) {
			$lines[] = 'قیمت: ' . $this->price_text( $p );
		}

		$now = null;
		$reg = null;
		if ( ! $multi ) {
			$range = $this->price_range( $p );
			$now   = $range ? $range[0] : null;
			if ( '' !== (string) $p->get_regular_price() ) {
				$reg = (float) wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) );
			}
		}

		$on_sale = (bool) $p->is_on_sale();

		if ( $this->has( 'regular_price' ) && null !== $reg && $reg > 0 && ( $on_sale || ! $this->has( 'price' ) ) ) {
			$lines[] = 'قیمت اصلی: ' . trim( Text::number( $reg, $dec ) . ' ' . $unit );
		}

		if ( $this->has( 'on_sale' ) ) {
			if ( $on_sale ) {
				$sale = 'حراج: بله';
				if ( null !== $now && null !== $reg && $reg > 0 && $now > 0 && $now < $reg ) {
					$sale .= ' — ' . (int) round( ( $reg - $now ) / $reg * 100 ) . '% تخفیف';
				}
				$to = $p->get_date_on_sale_to();
				if ( $to ) {
					$sale .= ' — تا ' . $to->date( 'Y-m-d' );
				}
				$lines[] = $sale;
			} else {
				$lines[] = 'حراج: خیر';
			}
		}

		return $lines;
	}

	/* ------------------------------------------------------------------ ووکامرس: موجودی */

	/**
	 * @param \WC_Product $p محصول.
	 */
	private function stock_label( $p ): string {
		$map = array(
			'instock'     => 'موجود',
			'outofstock'  => 'ناموجود',
			'onbackorder' => 'پیش‌سفارش',
		);

		return $map[ (string) $p->get_stock_status() ] ?? '';
	}

	/**
	 * @param \WC_Product $p محصول.
	 * @return string[]
	 */
	private function woo_stock_lines( $p ): array {
		$lines = array();

		if ( $this->has( 'stock_status' ) && '' !== $this->stock_label( $p ) ) {
			$lines[] = 'وضعیت موجودی: ' . $this->stock_label( $p );
		}

		// تعداد فقط وقتی مدیریت موجودی فعال است؛ وگرنه کلاً نیاید.
		if ( $this->has( 'stock_qty' ) && $p->managing_stock() ) {
			$qty = $p->get_stock_quantity();
			if ( null !== $qty && '' !== $qty ) {
				$lines[] = 'تعداد موجودی: ' . max( 0, (int) $qty );
			}
		}

		if ( $this->has( 'backorder' ) ) {
			$mode    = (string) $p->get_backorders();
			$labels  = array(
				'yes'    => 'فعال',
				'notify' => 'فعال (با اطلاع‌رسانی)',
			);
			$lines[] = 'پیش‌سفارش: ' . ( $labels[ $mode ] ?? 'غیرفعال' );
		}

		return $lines;
	}

	/* ------------------------------------------------------------------ ووکامرس: متغیرها */

	/**
	 * @param \WC_Product $p محصول.
	 * @return string[]
	 */
	private function woo_variation_lines( $p ): array {
		if ( ! $p->is_type( 'variable' ) || ! $this->keys ) {
			return array();
		}

		$rows = array();
		foreach ( array_slice( (array) $p->get_children(), 0, 50 ) as $variation_id ) {
			$v = wc_get_product( $variation_id );
			if ( ! $v instanceof \WC_Product_Variation || 'publish' !== $v->get_status() ) {
				continue;
			}

			$attrs = array();
			foreach ( (array) $v->get_variation_attributes( false ) as $key => $value ) {
				$term    = ( '' !== (string) $value && taxonomy_exists( (string) $key ) ) ? get_term_by( 'slug', (string) $value, (string) $key ) : false;
				$attrs[] = Text::plain( (string) wc_attribute_label( (string) $key, $p ) ) . ': ' . ( $term ? Text::plain( (string) $term->name ) : ( '' === (string) $value ? 'همه' : Text::plain( (string) $value ) ) );
			}

			$cells = array( 'شناسه: ' . (int) $v->get_id() );
			if ( $attrs ) {
				$cells[] = 'مشخصات: ' . implode( '، ', $attrs );
			}
			if ( $this->has( 'price' ) ) {
				$cells[] = 'قیمت: ' . ( '' === (string) $v->get_price() ? 'ثبت نشده' : trim( Text::number( wc_get_price_to_display( $v ), $this->decimals() ) . ' ' . $this->unit() ) );
			}
			if ( $this->has( 'stock_status' ) && '' !== $this->stock_label( $v ) ) {
				$cells[] = 'وضعیت موجودی: ' . $this->stock_label( $v );
			}
			if ( $this->has( 'stock_qty' ) && $v->managing_stock() && null !== $v->get_stock_quantity() ) {
				$cells[] = 'تعداد موجودی: ' . max( 0, (int) $v->get_stock_quantity() );
			}
			$rows[] = '- ' . implode( ' | ', $cells );

			if ( count( $rows ) >= self::MAX_VARIATIONS ) {
				break;
			}
		}

		return $rows ? array_merge( array( 'متغیرها (حداکثر ' . self::MAX_VARIATIONS . '):' ), $rows ) : array();
	}

	/* ------------------------------------------------------------------ نوع‌نوشته‌های محصول‌مانند (بدون ووکامرس) */

	/**
	 * متای عمومی قیمت (_price / edd_price) و وضعیت موجودی (_stock_status)؛ فقط فیلدهای مجاز.
	 *
	 * @return string[]
	 */
	private function generic_lines( \WP_Post $post ): array {
		$lines = array();

		if ( $this->has( 'price' ) ) {
			$price = '';
			foreach ( array( '_price', 'edd_price' ) as $meta_key ) {
				$value = get_post_meta( $post->ID, $meta_key, true );
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$price = (string) $value;
					break;
				}
			}

			$code = '';
			if ( function_exists( 'edd_get_currency' ) ) {
				$code = (string) edd_get_currency();
			} elseif ( function_exists( 'get_woocommerce_currency' ) ) {
				$code = (string) get_woocommerce_currency();
			}

			if ( is_numeric( $price ) ) {
				$lines[] = 'قیمت: ' . Text::number( $price, 2 ) . ( '' !== $code ? ' ' . Text::currency_label( $code ) : ' (واحد پول مشخص نیست)' );
			} else {
				$lines[] = 'قیمت: ثبت نشده';
			}
		}

		if ( $this->has( 'stock_status' ) ) {
			$status = (string) get_post_meta( $post->ID, '_stock_status', true );
			$map    = array(
				'instock'     => 'موجود',
				'outofstock'  => 'ناموجود',
				'onbackorder' => 'پیش‌سفارش',
			);
			if ( isset( $map[ $status ] ) ) {
				$lines[] = 'وضعیت موجودی: ' . $map[ $status ];
			}
		}

		return $lines;
	}
}
