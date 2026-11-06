<?php
namespace PersianWPChatbot\Support;

defined( 'ABSPATH' ) || exit;

final class Capability {

	/**
	 * سطح دسترسی لازم برای مدیریت افزونه.
	 */
	public static function required(): string {
		return (string) apply_filters( 'pwc_required_capability', 'manage_options' );
	}

	public static function current_user_can(): bool {
		return current_user_can( self::required() );
	}
}
