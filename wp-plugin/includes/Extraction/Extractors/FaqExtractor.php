<?php
namespace PersianAIAssistant\Extraction\Extractors;

use PersianAIAssistant\Extraction\PostTypes;
use PersianAIAssistant\Extraction\Support\Content;
use PersianAIAssistant\Extraction\Support\Normalizer;
use PersianAIAssistant\Extraction\Support\Terms;
use PersianAIAssistant\Extraction\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * سوالات متداول: (۱) نوع‌نوشته‌های FAQ افزونه‌ها، (۲) برگه‌هایی که عنوان/نامکشان «سوالات متداول» است.
 */
final class FaqExtractor implements ExtractorInterface {

	private const PAGE_PATTERN = '/faq|f\.a\.q|سوالات|سؤالات|سئوالات|پرسش|questions/ui';

	public function id(): string {
		return 'faq';
	}

	public function default_limit(): int {
		return 20;
	}

	/**
	 * @return array<int, array{id:int, kind:string}>
	 */
	private function candidates(): array {
		global $wpdb;
		$out = array();

		$faq_types = PostTypes::faq();
		if ( $faq_types ) {
			$placeholders = implode( ',', array_fill( 0, count( $faq_types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC", $faq_types ) );
			foreach ( (array) $ids as $id ) {
				$out[] = array(
					'id'   => (int) $id,
					'kind' => 'faq_entry',
				);
			}
		}

		$pages = $wpdb->get_results( "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $pages as $page ) {
			if ( 1 === preg_match( self::PAGE_PATTERN, $page->post_title . ' ' . urldecode( $page->post_name ) ) ) {
				$out[] = array(
					'id'   => (int) $page->ID,
					'kind' => 'faq_page',
				);
			}
		}

		return $out;
	}

	public function extract( int $offset, int $limit ): array {
		$all      = $this->candidates();
		$total    = count( $all );
		$types    = PostTypes::faq();
		$warnings = array();
		$extra    = array();

		if ( 0 === $offset ) {
			$extra['info']       = array(
				$types
					/* translators: %s: post type names */
					? sprintf( __( 'نوع‌نوشته‌های پرسش‌وپاسخ پیدا شد: %s (هر نوشته = یک پرسش؛ عنوان = پرسش، محتوا = پاسخ).', 'persian-ai-assistant' ), implode( '، ', $types ) )
					: __( 'هیچ نوع‌نوشتهٔ اختصاصی FAQ روی سایت پیدا نشد.', 'persian-ai-assistant' ),
				__( 'برگه‌هایی که عنوان یا نامک آن‌ها شامل «سوالات متداول / پرسش / FAQ» است بررسی می‌شود و جفت‌های پرسش‌وپاسخ از ساختار HTML آن‌ها (details، آکاردئون Elementor/Divi/WPBakery/Kadence، بلوک Yoast و Rank Math، dl، سرتیتر پرسشی) بیرون کشیده می‌شود.', 'persian-ai-assistant' ),
				__( 'پرسش‌وپاسخ‌های داخل محتوای سایر منابع (محصول، مقاله، برگه) در همان آیتم‌ها و در شمارندهٔ «پرسش و پاسخ» ثبت می‌شود.', 'persian-ai-assistant' ),
			);
			$extra['taxonomies'] = $types ? Terms::catalog( $types ) : array();
		}

		$items = array();
		foreach ( array_slice( $all, $offset, $limit ) as $candidate ) {
			$post = get_post( $candidate['id'] );
			if ( ! $post ) {
				continue;
			}
			try {
				$items[] = 'faq_entry' === $candidate['kind'] ? $this->entry( $post ) : $this->page( $post );
			} catch ( \Throwable $e ) {
				/* translators: 1: post ID, 2: error message */
				$warnings[] = sprintf( __( 'خطا در خواندن مورد %1$d: %2$s', 'persian-ai-assistant' ), $candidate['id'], $e->getMessage() );
			}
		}

		$done = ( $offset + $limit ) >= $total;

		return array_merge(
			array(
				'items'       => $items,
				'total'       => $total,
				'offset'      => $offset,
				'limit'       => $limit,
				'next_offset' => $done ? null : $offset + $limit,
				'done'        => $done,
				'warnings'    => $warnings,
			),
			$extra
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function entry( \WP_Post $post ): array {
		$content = Content::resolve( $post );

		return Normalizer::prune(
			array(
				'id'         => (int) $post->ID,
				'kind'       => 'faq_entry',
				'type'       => $post->post_type,
				'question'   => Text::collapse( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ) ),
				'answer'     => $content['text'],
				'url'        => (string) get_permalink( $post ),
				'taxonomies' => Terms::for_post( $post->ID, $post->post_type ),
				'extraction' => array(
					'content_method' => $content['method'],
					'alternatives'   => $content['candidates'],
					'via'            => array( 'wp_query', 'content:' . $content['method'] ),
				),
				'stats'      => array(
					'faqs'          => $content['text'] ? 1 : 0,
					'words'         => Text::words( $content['text'] ),
					'empty_content' => $content['text'] ? 0 : 1,
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function page( \WP_Post $post ): array {
		$content = Content::resolve( $post, array( 'loose' => true ) );
		$faqs    = $content['faqs'];

		return Normalizer::prune(
			array(
				'id'         => (int) $post->ID,
				'kind'       => 'faq_page',
				'type'       => $post->post_type,
				'title'      => Text::collapse( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ) ),
				'url'        => (string) get_permalink( $post ),
				'faqs'       => $faqs,
				// اگر جفت پرسش‌وپاسخی تشخیص داده نشد، متن کامل صفحه برای بررسی نگه داشته می‌شود.
				'content'    => $faqs ? '' : $content['text'],
				'extraction' => array(
					'content_method' => $content['method'],
					'pairs_found'    => count( $faqs ),
					'alternatives'   => $content['candidates'],
					'via'            => array( 'wp_query', 'content:' . $content['method'], 'faq_finder' ),
				),
				'stats'      => array(
					'faqs'          => count( $faqs ),
					'words'         => Text::words( $content['text'] ),
					'empty_content' => $content['text'] ? 0 : 1,
				),
			)
		);
	}
}
