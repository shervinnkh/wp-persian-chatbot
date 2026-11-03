<?php
namespace PersianAIAssistant\Extraction\Extractors;

use PersianAIAssistant\Extraction\LiveFields;
use PersianAIAssistant\Extraction\Support\PostMapper;
use PersianAIAssistant\Extraction\Support\Terms;

defined( 'ABSPATH' ) || exit;

/**
 * پایهٔ استخراج‌کننده‌هایی که از WP_Query می‌خوانند و به‌صورت دسته‌ای (offset/limit) کار می‌کنند.
 */
abstract class AbstractPostExtractor implements ExtractorInterface {

	/** @var string[] */
	protected $warnings = array();

	/**
	 * @return string[]
	 */
	abstract protected function post_types(): array;

	/**
	 * آرگومان‌های اضافهٔ WP_Query.
	 *
	 * @return array<string, mixed>
	 */
	protected function query_args(): array {
		return array();
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function map_options(): array {
		return array();
	}

	public function default_limit(): int {
		return 20;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function map_post( \WP_Post $post ): array {
		return PostMapper::map( $post, $this->map_options() );
	}

	/**
	 * آیا این نوشته در این منبع جا دارد؟ (برای ذخیرهٔ خودکار یک نوشته هنگام انتشار/ویرایش؛
	 * همان شرط‌هایی که استخراج دسته‌ای با WP_Query اعمال می‌کند.)
	 */
	public function accepts( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		if ( ! in_array( $post->post_type, $this->post_types(), true ) ) {
			return false;
		}
		$args = $this->query_args();
		if ( ! empty( $args['post__not_in'] ) && in_array( (int) $post->ID, array_map( 'intval', (array) $args['post__not_in'] ), true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * همان ساختار JSON استخراج دسته‌ای، برای یک نوشتهٔ مشخص.
	 *
	 * @return array<string, mixed>
	 */
	public function item_for( \WP_Post $post ): array {
		return LiveFields::strip( $this->id(), $this->map_post( $post ) );
	}

	public function extract( int $offset, int $limit ): array {
		$types = $this->post_types();
		$extra = array();

		if ( 0 === $offset ) {
			$extra['taxonomies'] = $types ? Terms::catalog( $types ) : array();
		}

		if ( ! $types ) {
			$this->warnings[] = __( 'هیچ نوع نوشتهٔ مناسبی روی این سایت پیدا نشد.', 'persian-ai-assistant' );

			return $this->result( array(), 0, $offset, $limit, $extra );
		}

		$query = new \WP_Query(
			array_merge(
				array(
					'post_type'           => $types,
					'post_status'         => 'publish',
					'has_password'        => false,
					'posts_per_page'      => $limit,
					'offset'              => $offset,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => false,
				),
				$this->query_args()
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			try {
				$items[] = LiveFields::strip( $this->id(), $this->map_post( $post ) );
			} catch ( \Throwable $e ) {
				/* translators: 1: post ID, 2: error message */
				$this->warnings[] = sprintf( __( 'خطا در خواندن مورد %1$d: %2$s', 'persian-ai-assistant' ), (int) $post->ID, $e->getMessage() );
			}
		}

		return $this->result( $items, (int) $query->found_posts, $offset, $limit, $extra );
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $extra
	 * @return array<string, mixed>
	 */
	protected function result( array $items, int $total, int $offset, int $limit, array $extra = array() ): array {
		$done = ( $offset + $limit ) >= $total;

		return array_merge(
			array(
				'items'       => $items,
				'total'       => $total,
				'offset'      => $offset,
				'limit'       => $limit,
				'next_offset' => $done ? null : $offset + $limit,
				'done'        => $done,
				'warnings'    => $this->warnings,
			),
			$extra
		);
	}
}
