<?php
namespace PersianAIAssistant\Extraction\Support;

defined( 'ABSPATH' ) || exit;

final class Media {

	/**
	 * @return array{url:string, alt?:string, caption?:string}|null
	 */
	public static function attachment( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return null;
		}

		return Normalizer::prune(
			array(
				'url'     => $url,
				'alt'     => Text::collapse( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
				'caption' => Text::collapse( (string) wp_get_attachment_caption( $id ) ),
			)
		);
	}
}
