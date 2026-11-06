<?php
/**
 * کارت‌های لینک زیر پیام هوش مصنوعی.
 *
 * روش جدید (از ۰.۵.۲۸): هوش مصنوعی لینک محصول/مقاله/صفحهٔ خود سایت را داخل متن نمی‌آورد؛ در پاسخ سرور (`message.references`)
 * فهرست ارجاع‌ها را جدا می‌دهد: [{ "type": "product|blog|page", "id": "23", "url": "https://…" }, …]. `from_references()` هر ارجاع را
 * اول با شناسه (id نوشتهٔ وردپرس) و اگر نشد با آدرس از دیتابیس می‌خواند و عنوان/عکس/قیمت را می‌گیرد. اگر رکوردی پیدا نشد
 * (یا منتشر نشده بود) کارت بدون عکس با خودِ آدرس به‌جای عنوان ساخته می‌شود (در صورت نبودن آدرس، ارجاع نادیده گرفته می‌شود).
 *
 * روش قدیمی (تا ۰.۵.۲۷، هنوز برای پیام‌های قبلی کار می‌کند): نشانگرهای داخل متن
 *
 *   [product-link:https://site.com/product/x/]   ← لینک یک محصول
 *   [page-link:https://site.com/about/]          ← لینک یک برگه
 *   [blog-link:https://site.com/my-post/]        ← لینک یک نوشتهٔ وبلاگ
 *
 * هوش مصنوعی هر تعداد از این نشانگرها را لابه‌لای متن می‌گذارد. این کلاس:
 *  1) نشانگرها را از متن پیام برمی‌دارد (خود لینک در حباب پیام نمایش داده نمی‌شود)،
 *  2) هر لینک را مستقیم از دیتابیس وردپرس می‌خواند (عنوان، عکس شاخص، قیمت/تاریخ) و کارت کوچکی می‌سازد
 *     که ویجت زیر پیام نشان می‌دهد (فقط عکس + عنوان + یک خط اطلاعات؛ با کلیک لینک باز می‌شود).
 *
 * فقط نوشته‌های «منتشرشدهٔ» همین سایت (محصول/وبلاگ/برگه) کارت می‌شوند؛ لینک ناشناخته یا بیرونی کارت نمی‌گیرد
 * (ولی نشانگرش از متن حذف می‌شود). آدرس کارت همیشه permalink واقعی نوشته است نه رشتهٔ خام هوش مصنوعی.
 * متن ذخیره‌شده در دیتابیس دست‌نخورده می‌ماند (نشانگرها در همان `content` هستند)؛ فقط هنگام ساخت خروجی حذف می‌شوند.
 *
 * @package PersianWPChatbot
 */

namespace PersianWPChatbot\Chat;

use PersianWPChatbot\Extraction\PostTypes;

defined( 'ABSPATH' ) || exit;

final class MessageLinks {

	/** حداکثر کارت زیر یک پیام. */
	public const MAX_CARDS = 12;

	private const PATTERN = '/\[\s*(product|page|blog)-link\s*:\s*(https?:\/\/[^\]\s]+?)\s*\]/iu';

	/** @var array<string, array<string,string>|null> کش همین درخواست (کلید: نوع|آدرس) */
	private static $cache = array();

	/** بررسی ارزان: آیا متن می‌تواند نشانگر داشته باشد؟ */
	public static function has_markers( string $text ): bool {
		return false !== stripos( $text, '-link' ) && 1 === preg_match( self::PATTERN, $text );
	}

	/**
	 * نشانگرها را از متن برمی‌دارد، بدون هیچ پرس‌وجو به دیتابیس.
	 *
	 * @return array{text:string, found:array<int, array{kind:string, url:string}>}
	 */
	public static function strip( string $text ): array {
		$found = array();
		$clean = preg_replace_callback(
			self::PATTERN,
			static function ( array $m ) use ( &$found ): string {
				$found[] = array(
					'kind' => strtolower( $m[1] ),
					'url'  => $m[2],
				);
				return '';
			},
			$text
		);
		$clean = is_string( $clean ) ? $clean : $text;

		if ( $found ) {
			// جای خالی نشانگرها را مرتب کن: فاصله‌های دوتایی، فاصلهٔ آخر خط، چند خط خالی پشت‌سرهم.
			$clean = (string) preg_replace( '/[ \t]{2,}/u', ' ', $clean );
			$clean = (string) preg_replace( '/[ \t]+\n/u', "\n", $clean );
			$clean = (string) preg_replace( '/\n{3,}/u', "\n\n", $clean );
			$clean = trim( $clean );
		}

		return array(
			'text'  => $clean,
			'found' => $found,
		);
	}

	/**
	 * متن بدون نشانگر + کارت‌های لینک‌ها (از دیتابیس).
	 *
	 * @return array{text:string, links:array<int, array<string,string>>}
	 */
	public static function parse( string $text ): array {
		$stripped = self::strip( $text );
		$links    = array();
		$seen     = array();

		foreach ( $stripped['found'] as $item ) {
			if ( count( $links ) >= self::MAX_CARDS ) {
				break;
			}
			$card = self::card( $item['kind'], $item['url'] );
			if ( null === $card || isset( $seen[ $card['url'] ] ) ) {
				continue;
			}
			$seen[ $card['url'] ] = true;
			$links[]              = $card;
		}

		return array(
			'text'  => $stripped['text'],
			'links' => $links,
		);
	}

	/** حداکثر ارجاع در هر پیام. */
	public const MAX_REFERENCES = 8;

	/**
	 * ارجاع‌های خام (از سرور) را پاک و استاندارد می‌کند: [{type: product|blog|page, id: digits|'', url: http(s)|''}].
	 * ارجاعی که نه شناسه دارد نه آدرس معتبر حذف می‌شود.
	 *
	 * @param mixed $list ارجاع‌ها.
	 * @return array<int, array{type:string,id:string,url:string}>
	 */
	public static function clean_references( $list ): array {
		$out  = array();
		$seen = array();
		$map  = array(
			'products' => 'product',
			'post'     => 'blog',
			'posts'    => 'blog',
			'article'  => 'blog',
			'pages'    => 'page',
		);
		foreach ( is_array( $list ) ? $list : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = isset( $item['type'] ) && is_scalar( $item['type'] ) ? strtolower( trim( (string) $item['type'] ) ) : '';
			$type = $map[ $type ] ?? $type;
			$id   = isset( $item['id'] ) && is_scalar( $item['id'] ) ? trim( (string) $item['id'] ) : '';
			if ( false !== strpos( $id, ':' ) ) { // «product:23»
				list( $prefix, $rest ) = array_pad( explode( ':', $id, 2 ), 2, '' );
				$prefix                = strtolower( trim( $prefix ) );
				$prefix                = $map[ $prefix ] ?? $prefix;
				if ( in_array( $prefix, array( 'product', 'blog', 'page' ), true ) ) {
					$type = $prefix;
					$id   = trim( $rest );
				}
			}
			$id  = ctype_digit( $id ) ? (string) (int) $id : ''; // شناسهٔ نوشتهٔ وردپرس فقط عدد است
			$url = isset( $item['url'] ) && is_scalar( $item['url'] ) ? esc_url_raw( trim( (string) $item['url'] ), array( 'http', 'https' ) ) : '';
			if ( ! in_array( $type, array( 'product', 'blog', 'page' ), true ) || ( '' === $id && '' === $url ) ) {
				continue;
			}
			$key = $type . '|' . ( '' !== $id ? $id : $url );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'type' => $type,
				'id'   => $id,
				'url'  => function_exists( 'mb_substr' ) ? mb_substr( $url, 0, 2048 ) : substr( $url, 0, 2048 ),
			);
			if ( count( $out ) >= self::MAX_REFERENCES ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * کارت‌های ارجاع‌ها. هر ارجاع: اول با شناسه از دیتابیس، بعد با آدرس؛ اگر رکوردی پیدا نشد کارت ساده با خودِ آدرس.
	 *
	 * @param array<int, array<string,string>> $refs خروجی clean_references.
	 * @return array<int, array<string,string>> کارت‌ها (kind, url, title, image, meta, label)
	 */
	public static function from_references( array $refs ): array {
		$cards = array();
		$seen  = array();
		foreach ( self::clean_references( $refs ) as $ref ) {
			if ( count( $cards ) >= self::MAX_CARDS ) {
				break;
			}
			$card = self::card_for_reference( $ref );
			if ( null === $card || isset( $seen[ $card['url'] ] ) ) {
				continue;
			}
			$seen[ $card['url'] ] = true;
			$cards[]              = $card;
		}

		return $cards;
	}

	/**
	 * کارت‌های دو منبع (نشانگرهای قدیمی + ارجاع‌ها) را بدون تکرار آدرس و با سقف MAX_CARDS یکی می‌کند.
	 *
	 * @param array<int, array<string,string>> $a کارت‌های اول.
	 * @param array<int, array<string,string>> $b کارت‌های دوم.
	 * @return array<int, array<string,string>>
	 */
	public static function merge( array $a, array $b ): array {
		$out  = array();
		$seen = array();
		foreach ( array_merge( $a, $b ) as $card ) {
			if ( ! is_array( $card ) || empty( $card['url'] ) || isset( $seen[ $card['url'] ] ) ) {
				continue;
			}
			$seen[ $card['url'] ] = true;
			$out[]                = $card;
			if ( count( $out ) >= self::MAX_CARDS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param array{type:string,id:string,url:string} $ref ارجاع پاک‌شده.
	 * @return array<string,string>|null
	 */
	private static function card_for_reference( array $ref ): ?array {
		$kind = (string) $ref['type'];
		$id   = (int) $ref['id'];
		$url  = (string) $ref['url'];

		// ۱) با شناسه
		if ( $id > 0 ) {
			$card = self::card_by_id( $id, $kind );
			if ( null !== $card ) {
				return $card;
			}
		}
		// ۲) با آدرس (همان روش نشانگرهای قدیمی)
		if ( '' !== $url ) {
			$card = self::card( $kind, $url );
			if ( null !== $card ) {
				return $card;
			}
			// ۳) رکوردی پیدا نشد: کارت ساده، بدون عکس و با خودِ آدرس به‌جای عنوان.
			$labels = self::labels();

			return array(
				'kind'  => $kind,
				'url'   => $url,
				'title' => $url,
				'image' => '',
				'meta'  => '',
				'label' => $labels[ $kind ] ?? '',
			);
		}

		return null; // نه رکورد پیدا شد نه آدرسی هست
	}

	/** برچسب نوع کارت. @return array<string,string> */
	private static function labels(): array {
		return array(
			'product' => __( 'محصول', 'persian-wp-chatbot' ),
			'blog'    => __( 'مقاله', 'persian-wp-chatbot' ),
			'page'    => __( 'صفحه', 'persian-wp-chatbot' ),
		);
	}

	/**
	 * کارت یک نوشتهٔ منتشرشده با شناسه؛ null اگر نباشد، منتشر نشده باشد یا محصول/وبلاگ/برگه نباشد.
	 * (نوع واقعی از روی نوع نوشته تعیین می‌شود نه از روی گفتهٔ هوش مصنوعی.)
	 *
	 * @return array<string,string>|null
	 */
	private static function card_by_id( int $id, string $kind ): ?array {
		$key = 'id|' . $id;
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}
		self::$cache[ $key ] = null;

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}
		self::$cache[ $key ] = self::build_card( $post );

		return self::$cache[ $key ];
	}

	/**
	 * کارت یک لینک؛ null اگر نوشتهٔ منتشرشدهٔ این سایت نباشد.
	 *
	 * @return array<string,string>|null kind, url, title, image, meta, label
	 */
	public static function card( string $kind, string $url ): ?array {
		$key = $kind . '|' . $url;
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		self::$cache[ $key ] = null;

		$id   = self::post_id( $kind, $url );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		self::$cache[ $key ] = self::build_card( $post );

		return self::$cache[ $key ];
	}

	/**
	 * کارت یک نوشتهٔ منتشرشده (عنوان، عکس شاخص، قیمت/تاریخ)؛ null اگر محصول/وبلاگ/برگه نباشد یا عنوان نداشته باشد.
	 *
	 * @return array<string,string>|null
	 */
	private static function build_card( \WP_Post $post ): ?array {
		$role = PostTypes::role_of( (string) $post->post_type );
		if ( '' === $role ) {
			return null; // فقط محصول / وبلاگ / برگه
		}
		$real = 'products' === $role ? 'product' : ( 'blog' === $role ? 'blog' : 'page' );

		$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $title ) {
			return null;
		}

		$labels = self::labels();

		return array(
			'kind'  => $real,
			'url'   => (string) get_permalink( $post ),
			'title' => $title,
			'image' => self::image( $post, $real ),
			'meta'  => self::meta( $post, $real ),
			'label' => $labels[ $real ],
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * شناسهٔ نوشته از روی آدرس: اول url_to_postid (همهٔ ساختارهای پیوند یکتا)، بعد جست‌وجوی نامک (slug).
	 */
	private static function post_id( string $kind, string $url ): int {
		$url = esc_url_raw( $url );
		if ( '' === $url || ! self::is_own_host( $url ) ) {
			return 0;
		}

		$id = (int) url_to_postid( $url );
		if ( $id > 0 ) {
			return $id;
		}

		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return 0;
		}
		$slug = sanitize_title( rawurldecode( basename( $path ) ) );
		if ( '' === $slug ) {
			return 0;
		}

		$preferred = 'product' === $kind ? PostTypes::products() : ( 'blog' === $kind ? PostTypes::blog() : PostTypes::pages() );
		$all       = array_values( array_unique( array_merge( $preferred, PostTypes::products(), PostTypes::blog(), PostTypes::pages() ) ) );
		if ( ! $all ) {
			return 0;
		}

		global $wpdb;
		$marks = implode( ',', array_fill( 0, count( $all ), '%s' ) );
		$args  = array_merge( array( $slug ), $all );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type IN ({$marks})", $args ) );
		if ( ! $rows ) {
			return 0;
		}
		// نوعی که نشانگر گفته اولویت دارد.
		foreach ( $rows as $row ) {
			if ( in_array( (string) $row->post_type, $preferred, true ) ) {
				return (int) $row->ID;
			}
		}

		return (int) $rows[0]->ID;
	}

	/** آیا آدرس مال همین سایت است؟ (www و http/https مهم نیست) */
	private static function is_own_host( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		$home = preg_replace( '/^www\./', '', $home );

		return '' !== $host && $host === $home;
	}

	private static function image( \WP_Post $post, string $kind ): string {
		$url = get_the_post_thumbnail_url( $post, 'thumbnail' );
		if ( ! $url && 'product' === $kind && function_exists( 'wc_get_product' ) ) {
			try {
				$product = wc_get_product( $post->ID );
				$ids     = $product ? $product->get_gallery_image_ids() : array();
				if ( $ids ) {
					$url = wp_get_attachment_image_url( (int) $ids[0], 'thumbnail' );
				}
			} catch ( \Throwable $e ) {
				$url = '';
			}
		}

		return $url ? (string) $url : '';
	}

	/** خط اطلاعات کوچک: محصول = قیمت (یا «ناموجود»)، وبلاگ = تاریخ، برگه = خالی. همیشه زنده از دیتابیس. */
	private static function meta( \WP_Post $post, string $kind ): string {
		if ( 'blog' === $kind ) {
			return (string) get_the_date( '', $post );
		}
		if ( 'product' !== $kind || ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_price' ) ) {
			return '';
		}

		try {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				return '';
			}
			if ( ! $product->is_in_stock() ) {
				return __( 'ناموجود', 'persian-wp-chatbot' );
			}

			$prefix = '';
			if ( $product->is_type( 'variable' ) ) {
				$min = (float) $product->get_variation_price( 'min', true );
				$max = (float) $product->get_variation_price( 'max', true );
				if ( $min <= 0 ) {
					return '';
				}
				$value  = $min;
				$prefix = $max > $min ? __( 'از', 'persian-wp-chatbot' ) . ' ' : '';
			} else {
				if ( '' === (string) $product->get_price() ) {
					return '';
				}
				$value = (float) wc_get_price_to_display( $product );
			}

			$text = html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' );
			$text = trim( str_replace( "\xC2\xA0", ' ', $text ) );

			return '' === $text ? '' : $prefix . $text;
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
