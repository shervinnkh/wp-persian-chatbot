<?php
namespace PersianWPChatbot\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * تحلیل HTML با DOMDocument: متن پاک‌سازی‌شده + سرتیترها + پیوندها + تصاویر.
 * مستقل از قالب و صفحه‌ساز؛ فقط به ساختار استاندارد HTML تکیه می‌کند.
 */
final class Html {

	private const SKIP = array(
		'script', 'style', 'noscript', 'svg', 'template', 'iframe', 'object', 'embed', 'canvas',
		'select', 'option', 'textarea', 'input', 'button', 'form', 'head', 'title', 'meta', 'link',
	);

	private const BLOCK = array(
		'p', 'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'ul', 'ol', 'li',
		'table', 'tr', 'blockquote', 'pre', 'figure', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'hr', 'dl', 'dt', 'dd', 'details', 'summary', 'address', 'fieldset',
	);

	/**
	 * @param array{host?:string} $opts host: میزبان سایت برای تشخیص پیوند داخلی.
	 * @return array{text:string, headings:array, links:array, images:array}
	 */
	public static function analyze( string $html, array $opts = array() ): array {
		$opts  = $opts + array(
			'host' => '',
		);
		$empty = array(
			'text'     => '',
			'headings' => array(),
			'links'    => array(),
			'images'   => array(),
		);

		if ( '' === trim( $html ) ) {
			return $empty;
		}

		$doc = self::load( $html );
		if ( null === $doc ) {
			$empty['text'] = Text::lines_clean( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
			return $empty;
		}

		$xp = new \DOMXPath( $doc );

		$headings = array();
		foreach ( $xp->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) ?: array() as $h ) {
			$t = Text::collapse( $h->textContent );
			if ( '' !== $t && count( $headings ) < 100 ) {
				$headings[] = array(
					'level' => (int) substr( $h->nodeName, 1 ),
					'text'  => Text::cut( $t, 200 ),
				);
			}
		}

		$links = array();
		foreach ( $xp->query( '//a[@href]' ) ?: array() as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'javascript:' ) || isset( $links[ $href ] ) || count( $links ) >= 100 ) {
				continue;
			}
			$links[ $href ] = array(
				'text'  => Text::cut( Text::collapse( $a->textContent ), 120 ),
				'url'   => $href,
				'scope' => self::scope( $href, (string) $opts['host'] ),
			);
		}

		$images = array();
		foreach ( $xp->query( '//img' ) ?: array() as $img ) {
			$src = '';
			foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original', 'data-orig-src' ) as $attr ) {
				$candidate = trim( $img->getAttribute( $attr ) );
				if ( '' !== $candidate && 0 !== strpos( $candidate, 'data:' ) ) {
					$src = $candidate;
					break;
				}
			}
			if ( '' === $src || isset( $images[ $src ] ) || count( $images ) >= 100 ) {
				continue;
			}
			$images[ $src ] = array(
				'url' => $src,
				'alt' => Text::collapse( $img->getAttribute( 'alt' ) ),
			);
		}

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		$text = $body ? self::node_text( $body ) : '';

		return array(
			'text'     => $text,
			'headings' => $headings,
			'links'    => array_values( $links ),
			'images'   => array_values( $images ),
		);
	}

	/**
	 * متن ساده از یک تکه HTML (برای فیلدهای کوتاه مثل خلاصه).
	 */
	public static function to_text( string $html ): string {
		if ( false === strpos( $html, '<' ) ) {
			return Text::lines_clean( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
		}

		return self::analyze( $html )['text'];
	}

	/**
	 * متن تمیز یک گره DOM (عنصر یا متن).
	 */
	public static function node_text( \DOMNode $node ): string {
		$out = '';
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			$out = (string) preg_replace( '/\s+/u', ' ', (string) $node->nodeValue );
		} else {
			self::walk( $node, $out );
		}

		$out = (string) preg_replace( '/ *\| *(?=\n|$)/u', '', $out );
		$out = Text::lines_clean( $out );
		// آیتم‌های فهرست و ردیف‌های جدول پشت‌سرهم، بدون خط خالی میان‌شان.
		$out = (string) preg_replace( '/(^• [^\n]*)\n\n(?=• )/mu', "$1\n", $out );
		$out = (string) preg_replace( '/(^[^\n]* \| [^\n]*)\n\n(?=[^\n]* \| )/mu', "$1\n", $out );

		return $out;
	}

	private static function walk( \DOMNode $node, string &$out ): void {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$out .= (string) preg_replace( '/\s+/u', ' ', (string) $child->nodeValue );
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );
			if ( in_array( $tag, self::SKIP, true ) ) {
				continue;
			}
			if ( 'br' === $tag ) {
				$out .= "\n";
				continue;
			}

			$block = in_array( $tag, self::BLOCK, true );
			if ( $block ) {
				$out .= "\n";
			}
			if ( 'li' === $tag ) {
				$out .= '• ';
			} elseif ( 1 === preg_match( '/^h([1-6])$/', $tag, $m ) ) {
				$out .= str_repeat( '#', (int) $m[1] ) . ' ';
			}

			self::walk( $child, $out );

			if ( 'td' === $tag || 'th' === $tag ) {
				$out .= ' | ';
			}
			if ( $block ) {
				$out .= "\n";
			}
		}
	}

	private static function scope( string $href, string $host ): string {
		if ( 1 === preg_match( '/^(tel|mailto|sms):/i', $href, $m ) ) {
			return strtolower( $m[1] );
		}
		$parts = parse_url( $href ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'internal';
		}
		$link_host = strtolower( (string) preg_replace( '/^www\./i', '', $parts['host'] ) );
		$site_host = strtolower( (string) preg_replace( '/^www\./i', '', $host ) );

		return ( '' !== $site_host && $link_host === $site_host ) ? 'internal' : 'external';
	}

	public static function load( string $html ): ?\DOMDocument {
		if ( '' === trim( $html ) || ! class_exists( '\DOMDocument' ) ) {
			return null;
		}

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $doc : null;
	}
}
