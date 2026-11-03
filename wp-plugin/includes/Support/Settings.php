<?php
namespace PersianAIAssistant\Support;

defined( 'ABSPATH' ) || exit;

/**
 * دسترسی یکپارچه به تنظیمات افزونه (یک ردیف option).
 */
final class Settings {

	public const OPTION = 'paia_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return (array) apply_filters( 'paia_default_settings', array() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public static function update( array $values ): bool {
		return update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}
}
