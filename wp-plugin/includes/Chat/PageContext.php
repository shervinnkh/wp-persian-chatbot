<?php
/**
 * اطلاعات کوتاه و ثابتِ «صفحه‌ای که بازدیدکننده در آن چت می‌کند» (slug، عنوان، نوع، آدرس).
 *
 * ویجت با هر پیام page_url و page_title را می‌فرستد؛ اینجا کوتاه نگه داشته می‌شود (transient، یک روز) و فقط وقتی
 * پیام به سرور می‌رود که generate_ai_answer = true است در پراپمت می‌آید (ReplyEngine). بدون هیچ ذخیرهٔ دائمی.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class PageContext {

	private static function key( string $uid ): string {
		return 'paia_page_' . md5( $uid );
	}

	/**
	 * آدرس باید مال همین سایت باشد (جلوگیری از جعل آدرس بیرونی).
	 */
	public static function remember( string $uid, string $url, string $title ): void {
		$url = esc_url_raw( $url );
		if ( '' === $url || '' === $uid ) {
			return;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! is_string( $host ) || ! is_string( $own ) || strtolower( $host ) !== strtolower( $own ) ) {
			return;
		}
		$title = sanitize_text_field( $title );
		set_transient(
			self::key( $uid ),
			array(
				'url'   => substr( $url, 0, 500 ),
				'title' => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 150, 'UTF-8' ) : substr( $title, 0, 150 ),
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * @return array{url:string,slug:string,title:string,type:string}|array{}
	 */
	public static function get( string $uid ): array {
		$saved = get_transient( self::key( $uid ) );
		if ( ! is_array( $saved ) || empty( $saved['url'] ) ) {
			return array();
		}

		$url  = (string) $saved['url'];
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		$type = 'other';
		if ( '' === $path ) {
			$type = 'home';
		} else {
			$post_id = (int) url_to_postid( $url );
			if ( $post_id > 0 ) {
				$post_type = (string) get_post_type( $post_id );
				$type      = array(
					'product' => 'product',
					'post'    => 'blog',
					'page'    => 'page',
				)[ $post_type ] ?? $post_type;
			}
		}

		$parts = explode( '/', $path );

		return array(
			'url'   => $url,
			'slug'  => '' === $path ? 'home' : (string) end( $parts ),
			'title' => (string) ( $saved['title'] ?? '' ),
			'type'  => $type,
		);
	}
}
