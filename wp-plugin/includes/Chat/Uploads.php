<?php
/**
 * ذخیرهٔ فایل‌های پیوست چت (عکس و ویس) — مشترک بین بازدیدکننده و پشتیبان.
 *
 * فایل‌ها در uploads/paia-chat/سال/ماه با نام تصادفی ذخیره می‌شوند.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Uploads {

	private const IMAGE_EXT = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
	private const AUDIO_EXT = array( 'webm', 'ogg', 'oga', 'mp3', 'm4a', 'mp4', 'wav', 'aac' );

	public static function upload_dir( array $dirs ): array {
		$sub            = '/paia-chat/' . gmdate( 'Y/m' );
		$dirs['subdir'] = $sub;
		$dirs['path']   = $dirs['basedir'] . $sub;
		$dirs['url']    = $dirs['baseurl'] . $sub;
		return $dirs;
	}

	/**
	 * @param array<string,mixed> $file            عضو $_FILES.
	 * @param bool                $enforce_toggles اگر true باشد، تیک‌های «ارسال عکس/ویس» در تنظیمات اعمال می‌شود (برای بازدیدکننده). پشتیبان همیشه مجاز است.
	 * @return array<string,string>|WP_Error  type, url, path
	 */
	public static function handle( array $file, bool $enforce_toggles = true ) {
		$ext = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );

		if ( in_array( $ext, self::IMAGE_EXT, true ) ) {
			$kind = 'image';
			if ( $enforce_toggles && ! ChatSettings::get( 'allow_images' ) ) {
				return new WP_Error( 'paia_file', __( 'ارسال عکس فعال نیست.', 'persian-ai-assistant' ), array( 'status' => 403 ) );
			}
		} elseif ( in_array( $ext, self::AUDIO_EXT, true ) ) {
			$kind = 'audio';
			if ( $enforce_toggles && ! ChatSettings::get( 'allow_voice' ) ) {
				return new WP_Error( 'paia_file', __( 'ارسال ویس فعال نیست.', 'persian-ai-assistant' ), array( 'status' => 403 ) );
			}
		} else {
			return new WP_Error( 'paia_file', __( 'فرمت فایل پشتیبانی نمی‌شود.', 'persian-ai-assistant' ), array( 'status' => 415 ) );
		}

		$max = (int) ChatSettings::get( 'max_upload_mb', 5 ) * MB_IN_BYTES;
		if ( (int) ( $file['error'] ?? 0 ) !== UPLOAD_ERR_OK || (int) ( $file['size'] ?? 0 ) <= 0 || (int) $file['size'] > $max ) {
			return new WP_Error( 'paia_file_size', __( 'حجم فایل بیش از حد مجاز است.', 'persian-ai-assistant' ), array( 'status' => 413 ) );
		}
		if ( 'image' === $kind && false === @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'paia_file', __( 'فایل تصویر معتبر نیست.', 'persian-ai-assistant' ), array( 'status' => 415 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file['name'] = 'chat-' . strtolower( wp_generate_password( 16, false, false ) ) . '.' . $ext;

		$filter = array( self::class, 'upload_dir' );
		add_filter( 'upload_dir', $filter );
		$res = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'test_type' => false, // پسوند را خودمان با لیست سفید بالا کنترل کرده‌ایم (webm صوتی را وردپرس تشخیص نمی‌دهد).
			)
		);
		remove_filter( 'upload_dir', $filter );

		if ( ! is_array( $res ) || ! empty( $res['error'] ) ) {
			return new WP_Error( 'paia_file', __( 'ذخیرهٔ فایل ناموفق بود.', 'persian-ai-assistant' ), array( 'status' => 500 ) );
		}
		return array(
			'type' => $kind,
			'url'  => (string) $res['url'],
			'path' => (string) $res['file'],
		);
	}
}
