<?php
namespace PersianAIAssistant\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * پیدا کردن جفت پرسش‌وپاسخ در HTML، مستقل از قالب و افزونه:
 * <details>، بلوک‌های Yoast / Rank Math، آکاردئون‌های Elementor / Divi / WPBakery / Kadence / Bootstrap،
 * کلاس‌های عمومی faq / accordion / toggle و (در حالت loose) dl، سرتیترهای پرسشی و پاراگراف‌های بولد پرسشی.
 */
final class FaqFinder {

	private static function cls( string $class ): string {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
	}

	/**
	 * @return array<int, array{question:string, answer:string}>
	 */
	public static function find( \DOMDocument $doc, bool $loose = false ): array {
		$xp    = new \DOMXPath( $doc );
		$pairs = array();
		$seen  = array();

		$add = static function ( string $q, string $a ) use ( &$pairs, &$seen ) {
			$q = Text::collapse( $q );
			$a = Text::lines_clean( $a );
			if ( '' === $q || '' === $a || Text::length( $q ) > 400 || $q === $a ) {
				return;
			}
			$key = md5( Text::lower( $q ) );
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$pairs[]      = array(
				'question' => $q,
				'answer'   => Text::cut( $a, 4000 ),
			);
		};

		// 1) <details><summary>
		foreach ( $xp->query( '//details[summary]' ) ?: array() as $details ) {
			$summary = null;
			foreach ( $details->childNodes as $child ) {
				if ( 'summary' === strtolower( $child->nodeName ) ) {
					$summary = $child;
					break;
				}
			}
			if ( ! $summary ) {
				continue;
			}
			$answer = '';
			foreach ( $details->childNodes as $child ) {
				if ( $child !== $summary ) {
					$answer .= Html::node_text( $child ) . "\n";
				}
			}
			$add( $summary->textContent, $answer );
		}

		// 2) الگوهای شناخته‌شدهٔ افزونه‌ها و صفحه‌سازها: [ظرف, پرسش, پاسخ]
		$patterns = array(
			array( '//*[' . self::cls( 'schema-faq-section' ) . ']', './/*[' . self::cls( 'schema-faq-question' ) . ']', './/*[' . self::cls( 'schema-faq-answer' ) . ']' ),
			array( '//*[' . self::cls( 'rank-math-faq-item' ) . ']', './/*[' . self::cls( 'rank-math-question' ) . ']', './/*[' . self::cls( 'rank-math-answer' ) . ']' ),
			array( '//*[' . self::cls( 'elementor-accordion-item' ) . ' or ' . self::cls( 'elementor-toggle-item' ) . ']', './/*[' . self::cls( 'elementor-tab-title' ) . ']', './/*[' . self::cls( 'elementor-tab-content' ) . ']' ),
			array( '//*[' . self::cls( 'et_pb_toggle' ) . ']', './/*[' . self::cls( 'et_pb_toggle_title' ) . ']', './/*[' . self::cls( 'et_pb_toggle_content' ) . ']' ),
			array( '//*[' . self::cls( 'vc_tta-panel' ) . ']', './/*[' . self::cls( 'vc_tta-panel-title' ) . ']', './/*[' . self::cls( 'vc_tta-panel-body' ) . ']' ),
			array( '//*[' . self::cls( 'uagb-faq-child__outer-wrap' ) . ']', './/*[' . self::cls( 'uagb-question' ) . ']', './/*[' . self::cls( 'uagb-faq-content' ) . ']' ),
			array( '//*[' . self::cls( 'kt-accordion-pane' ) . ']', './/*[' . self::cls( 'kt-blocks-accordion-header' ) . ']', './/*[' . self::cls( 'kt-accordion-panel' ) . ']' ),
			array( '//*[' . self::cls( 'accordion-item' ) . ']', './/*[' . self::cls( 'accordion-header' ) . ' or ' . self::cls( 'accordion-button' ) . ']', './/*[' . self::cls( 'accordion-body' ) . ']' ),
			array( '//*[' . self::cls( 'accordion' ) . ']//*[' . self::cls( 'card' ) . ']', './/*[' . self::cls( 'card-header' ) . ']', './/*[' . self::cls( 'card-body' ) . ']' ),
			// الگوهای عمومی (بر اساس نام کلاس)
			array( '//*[contains(@class, "faq")]', './/*[contains(@class, "question")]', './/*[contains(@class, "answer")]' ),
			array(
				'//*[contains(@class, "accordion") or contains(@class, "toggle")]',
				'.//*[contains(@class, "question") or contains(@class, "trigger") or contains(@class, "-title") or contains(@class, "_title")]',
				'.//*[contains(@class, "answer") or contains(@class, "-content") or contains(@class, "_content") or contains(@class, "-body")]',
			),
		);

		foreach ( $patterns as $pattern ) {
			$containers = $xp->query( $pattern[0] );
			if ( ! $containers ) {
				continue;
			}
			foreach ( $containers as $container ) {
				$questions = $xp->query( $pattern[1], $container );
				$answers   = $xp->query( $pattern[2], $container );
				if ( ! $questions || ! $answers || 0 === $questions->length ) {
					continue;
				}
				if ( $questions->length === $answers->length ) {
					for ( $i = 0; $i < $questions->length; $i++ ) {
						$add( $questions->item( $i )->textContent, Html::node_text( $answers->item( $i ) ) );
					}
				} elseif ( 1 === $questions->length && $answers->length > 0 ) {
					$add( $questions->item( 0 )->textContent, Html::node_text( $answers->item( 0 ) ) );
				}
			}
		}

		if ( $loose ) {
			self::loose( $doc, $xp, $add );
		}

		return $pairs;
	}

	/**
	 * الگوهای کم‌قطعیت‌تر؛ فقط برای برگه‌هایی که از عنوان/نامک «سوالات متداول» تشخیص داده شده‌اند.
	 */
	private static function loose( \DOMDocument $doc, \DOMXPath $xp, callable $add ): void {
		// dl > dt / dd
		foreach ( $xp->query( '//dl' ) ?: array() as $dl ) {
			$question = '';
			$answer   = '';
			foreach ( $dl->childNodes as $child ) {
				$name = strtolower( $child->nodeName );
				if ( 'dt' === $name ) {
					if ( '' !== $question ) {
						$add( $question, $answer );
					}
					$question = $child->textContent;
					$answer   = '';
				} elseif ( 'dd' === $name ) {
					$answer .= Html::node_text( $child ) . "\n";
				}
			}
			if ( '' !== $question ) {
				$add( $question, $answer );
			}
		}

		// سرتیتر پرسشی + محتوای بعدی تا سرتیتر بعدی
		foreach ( $xp->query( '//h2|//h3|//h4|//h5|//h6' ) ?: array() as $heading ) {
			$question = Text::collapse( $heading->textContent );
			if ( 1 !== preg_match( '/[?؟]\s*$/u', $question ) ) {
				continue;
			}
			$answer = '';
			for ( $n = $heading->nextSibling; $n; $n = $n->nextSibling ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				if ( XML_ELEMENT_NODE === $n->nodeType && 1 === preg_match( '/^h[1-6]$/i', $n->nodeName ) ) {
					break;
				}
				$answer .= Html::node_text( $n ) . "\n";
			}
			$add( $question, $answer );
		}

		// پاراگراف بولد پرسشی + پاراگراف‌های بعدی
		foreach ( $xp->query( '//p[strong or b]' ) ?: array() as $p ) {
			$question = Text::collapse( $p->textContent );
			if ( 1 !== preg_match( '/[?؟]\s*$/u', $question ) ) {
				continue;
			}
			$answer = '';
			for ( $n = $p->nextSibling; $n; $n = $n->nextSibling ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				if ( XML_ELEMENT_NODE === $n->nodeType ) {
					$is_heading = 1 === preg_match( '/^h[1-6]$/i', $n->nodeName );
					$is_bold_q  = 'p' === strtolower( $n->nodeName ) && 1 === preg_match( '/[?؟]\s*$/u', Text::collapse( $n->textContent ) ) && $xp->query( './strong|./b', $n )->length > 0;
					if ( $is_heading || $is_bold_q ) {
						break;
					}
				}
				$answer .= Html::node_text( $n ) . "\n";
			}
			$add( $question, $answer );
		}
	}
}
