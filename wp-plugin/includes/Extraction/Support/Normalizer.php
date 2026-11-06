<?php
namespace PersianWPChatbot\Extraction\Support;

defined( 'ABSPATH' ) || exit;

/**
 * تبدیل مقدارهای دلخواه (متای پست، ACF، ...) به داده‌ای امن و قابل JSON.
 */
final class Normalizer {

	/**
	 * @param mixed $value
	 * @return mixed null یعنی «خالی/غیرقابل‌استفاده»
	 */
	public static function value( $value, int $depth = 0 ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = trim( (string) wp_check_invalid_utf8( $value, true ) );
			if ( '' === $value ) {
				return null;
			}
			if ( false !== strpos( $value, '<' ) ) {
				$value = Html::to_text( Text::cut( $value, 20000 ) );
			}

			return '' === $value ? null : Text::cut( $value, 3000 );
		}

		if ( $value instanceof \WP_Post ) {
			return $value->post_title;
		}
		if ( $value instanceof \WP_Term ) {
			return $value->name;
		}
		if ( $value instanceof \WP_User ) {
			return $value->display_name;
		}
		if ( is_object( $value ) ) {
			return null;
		}

		if ( is_array( $value ) ) {
			if ( $depth >= 3 ) {
				return null;
			}
			// تصویر/فایل ACF و مشابه
			if ( isset( $value['url'] ) && is_string( $value['url'] ) && ( isset( $value['ID'] ) || isset( $value['mime_type'] ) || isset( $value['sizes'] ) ) ) {
				return array_filter(
					array(
						'url' => $value['url'],
						'alt' => isset( $value['alt'] ) ? (string) $value['alt'] : '',
					)
				);
			}

			$out = array();
			$n   = 0;
			foreach ( $value as $key => $item ) {
				if ( ++$n > 50 ) {
					break;
				}
				$normalized = self::value( $item, $depth + 1 );
				if ( null === $normalized || array() === $normalized ) {
					continue;
				}
				$out[ $key ] = $normalized;
			}

			return array() === $out ? null : $out;
		}

		return null;
	}

	/**
	 * حذف مقدارهای خالی از سطح اول آرایه (صفر و false حفظ می‌شود).
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function prune( array $data ): array {
		return array_filter(
			$data,
			static function ( $v ) {
				return null !== $v && '' !== $v && array() !== $v;
			}
		);
	}
}
