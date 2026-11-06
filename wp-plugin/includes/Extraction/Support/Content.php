<?php
namespace PersianWPChatbot\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * بهترین متن ممکن از محتوای یک نوشته، مستقل از قالب و صفحه‌ساز.
 *
 * سه روش موازی اجرا و کامل‌ترین نتیجه انتخاب می‌شود (همهٔ طول‌ها برای بررسی توسعه‌دهنده برگردانده می‌شود):
 *  1) the_content : همان خروجی‌ای که REST API وردپرس به‌عنوان content.rendered می‌دهد (بلوک‌ها، شورت‌کدها، صفحه‌سازها)
 *  2) static      : پیمایش ایمن بلوک‌ها + حذف شورت‌کدها (بدون اجرای کد افزونه‌ها)
 *  3) builder     : داده‌های خام Elementor / Bricks
 */
final class Content {

	private const SHORTCODE_TEXT_ATTRS = array( 'title', 'heading', 'text', 'question', 'answer', 'label', 'subtitle', 'description', 'caption', 'header', 'tab_title', 'button_text' );
	private const SHORTCODE_IGNORED    = array( 'contact-form-7', 'caption', 'wp_caption', 'gallery', 'embed', 'audio', 'video', 'playlist', 'wpforms', 'gravityform', 'ninja_form', 'formidable' );

	/**
	 * @param array<string, mixed> $opts
	 * @return array{text:string, headings:array, links:array, images:array, method:string, candidates:array<string,int>}
	 */
	public static function resolve( \WP_Post $post, array $opts = array() ): array {
		$analyze = array(
			'host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		$candidates = array();
		$results    = array();

		$rendered = self::render( $post );
		if ( '' !== trim( $rendered ) ) {
			$results['the_content']    = Html::analyze( $rendered, $analyze );
			$candidates['the_content'] = Text::length( $results['the_content']['text'] );
		}

		$static = self::static_html( $post );
		if ( '' !== trim( $static ) ) {
			$results['static']    = Html::analyze( $static, $analyze );
			$candidates['static'] = Text::length( $results['static']['text'] );
		}

		$builder = BuilderText::for_post( $post->ID );
		if ( $builder && '' !== $builder['text'] ) {
			$key                = $builder['builder'] . '_data';
			$candidates[ $key ] = Text::length( $builder['text'] );
		}

		$method = 'empty';
		$best   = -1;
		foreach ( array( 'the_content', 'static', isset( $key ) ? $key : '' ) as $name ) {
			if ( '' !== $name && isset( $candidates[ $name ] ) && $candidates[ $name ] > $best ) {
				$best   = $candidates[ $name ];
				$method = $name;
			}
		}

		// ساختار (سرتیتر/پیوند/تصویر) از HTML روش انتخاب‌شده، و در نبود آن از بهترین HTML موجود.
		$structure = $results[ $method ] ?? ( $results['the_content'] ?? ( $results['static'] ?? Html::analyze( '' ) ) );
		$text      = isset( $results[ $method ] ) ? $results[ $method ]['text'] : ( $builder && isset( $key ) && $method === $key ? $builder['text'] : '' );

		return array(
			'text'       => $text,
			'headings'   => $structure['headings'],
			'links'      => $structure['links'],
			'images'     => $structure['images'],
			'method'     => $method,
			'candidates' => $candidates,
		);
	}

	/**
	 * همان رندر استاندارد REST (content.rendered)، با محافظ خطا و بدون خروجی مستقیم.
	 */
	private static function render( \WP_Post $post ): string {
		static $busy = false;
		if ( $busy || '' === trim( $post->post_content ) ) {
			return '';
		}
		$busy     = true;
		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$html     = '';

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		ob_start();
		try {
			$html = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		} catch ( \Throwable $e ) {
			$html = '';
		}
		ob_end_clean();

		if ( $previous instanceof \WP_Post ) {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $previous );
		} else {
			unset( $GLOBALS['post'] );
			wp_reset_postdata();
		}
		$busy = false;

		return $html;
	}

	private static function static_html( \WP_Post $post ): string {
		$content = (string) $post->post_content;
		if ( '' === trim( $content ) ) {
			return '';
		}

		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			$content = self::blocks_html( parse_blocks( $content ), 0 );
		}

		return self::strip_shortcodes( $content );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 */
	private static function blocks_html( array $blocks, int $depth ): string {
		$out = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = (string) ( $block['blockName'] ?? '' );

			// بلوک قابل‌استفادهٔ مجدد (reusable)
			if ( 'core/block' === $name && $depth < 2 && ! empty( $block['attrs']['ref'] ) ) {
				$ref = get_post( (int) $block['attrs']['ref'] );
				if ( $ref && 'publish' === $ref->post_status ) {
					$out .= self::blocks_html( parse_blocks( $ref->post_content ), $depth + 1 );
				}
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && ! empty( $block['innerContent'] ) ) {
				$i = 0;
				foreach ( $block['innerContent'] as $piece ) {
					if ( is_string( $piece ) ) {
						$out .= $piece;
					} else {
						$out .= self::blocks_html( array( $block['innerBlocks'][ $i ] ?? array() ), $depth );
						++$i;
					}
				}
			} else {
				$out .= (string) ( $block['innerHTML'] ?? '' );
			}

			// بلوک رنک‌مث: متن‌ها در attrs ذخیره می‌شوند
			if ( 'rank-math/faq-block' === $name && ! empty( $block['attrs']['questions'] ) && is_array( $block['attrs']['questions'] ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				foreach ( $block['attrs']['questions'] as $qa ) {
					if ( is_array( $qa ) && ! empty( $qa['title'] ) ) {
						$out .= '<details><summary>' . $qa['title'] . '</summary>' . ( $qa['content'] ?? '' ) . '</details>';
					}
				}
			}
		}

		return $out;
	}

	/**
	 * شورت‌کدها را بدون اجرا حذف می‌کند؛ متنِ داخل آن‌ها و ویژگی‌های متنی مهم (title, text, question ...) حفظ می‌شود.
	 */
	private static function strip_shortcodes( string $html ): string {
		$html = (string) preg_replace_callback(
			'/\[([a-zA-Z][\w\-]*)((?:\s+[^\]]*)?)\]/u',
			static function ( array $m ) {
				$tag   = $m[1];
				$attrs = $m[2];
				$known = function_exists( 'shortcode_exists' ) && shortcode_exists( $tag );
				if ( ! $known && false === strpos( $tag, '_' ) && false === strpos( $tag, '-' ) && false === strpos( $attrs, '=' ) ) {
					return $m[0]; // احتمالاً متن عادی داخل کروشه است
				}
				if ( in_array( strtolower( $tag ), self::SHORTCODE_IGNORED, true ) || '' === $attrs ) {
					return '';
				}

				$out = '';
				if ( preg_match_all( '/([\w\-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))/u', $attrs, $found, PREG_SET_ORDER ) ) {
					foreach ( $found as $f ) {
						$value = html_entity_decode( $f[2] . ( $f[3] ?? '' ) . ( $f[4] ?? '' ), ENT_QUOTES, 'UTF-8' );
						if ( in_array( strtolower( $f[1] ), self::SHORTCODE_TEXT_ATTRS, true ) && Text::length( $value ) >= 2 && ! is_numeric( $value ) && 0 !== strpos( $value, 'http' ) && 0 !== strpos( $value, '#' ) ) {
							$out .= '<p>' . esc_html( $value ) . '</p>';
						}
					}
				}

				return $out;
			},
			$html
		);

		return (string) preg_replace( '/\[\/[a-zA-Z][\w\-]*\]/u', '', $html );
	}
}
