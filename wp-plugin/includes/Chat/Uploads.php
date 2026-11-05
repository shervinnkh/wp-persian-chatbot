<?php
/**
 * ذخیرهٔ فایل‌های پیوست چت (عکس و ویس) — مشترک بین بازدیدکننده و پشتیبان.
 *
 * فایل‌ها در uploads/paia-chat/سال/ماه با نام تصادفی ذخیره می‌شوند. فایل‌ها عمداً عمومی می‌مانند (نام تصادفی ۱۶ نویسه‌ای کافی است)،
 * ولی پوشه محافظت می‌شود (S-8): index.php + .htaccess (بدون فهرست‌شدن، بدون اجرای php/phtml/phar) و محتوای صوت با magic bytes
 * با پسوند تطبیق داده می‌شود. پاک‌سازی دوره‌ای: Chat\Retention.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Uploads {

	private const IMAGE_EXT = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
	private const AUDIO_EXT = array( 'webm', 'ogg', 'oga', 'mp3', 'm4a', 'mp4', 'wav', 'aac' );

	/** نام پوشهٔ فایل‌های چت داخل uploads. */
	public const DIR = 'paia-chat';

	/** مسیر مطلق پوشهٔ ریشهٔ فایل‌های چت (بدون / انتهایی). */
	public static function base_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::DIR;
	}

	/**
	 * ساخت پوشه (و زیرپوشهٔ سال/ماه) و فایل‌های محافظ. چندبار صدا زدنش بی‌ضرر است.
	 *
	 * @param string $path مسیر مطلق پوشهٔ مقصد داخل paia-chat (خالی = فقط ریشه).
	 */
	public static function ensure_dir( string $path = '' ): void {
		$base = self::base_dir();
		wp_mkdir_p( $base );

		$guard = '<?php // Silence is golden.' . "\n";
		if ( ! file_exists( $base . '/index.php' ) ) {
			file_put_contents( $base . '/index.php', $guard ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			file_put_contents( $base . '/.htaccess', self::htaccess() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$path = untrailingslashit( str_replace( '\\', '/', $path ) );
		if ( '' !== $path && $path !== $base && 0 === strpos( $path, $base . '/' ) ) {
			wp_mkdir_p( $path );
			if ( ! file_exists( $path . '/index.php' ) ) {
				file_put_contents( $path . '/index.php', $guard ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * محتوای .htaccess پوشه (Apache 2.4 و 2.2). برای nginx: location ~* /uploads/paia-chat/.*\.php$ { deny all; } (README).
	 */
	public static function htaccess(): string {
		return "Options -Indexes\n"
			. "<FilesMatch \"\\.(php|phtml|phar)$\">\n"
			. "  <IfModule mod_authz_core.c>\n"
			. "    Require all denied\n"
			. "  </IfModule>\n"
			. "  <IfModule !mod_authz_core.c>\n"
			. "    Order allow,deny\n"
			. "    Deny from all\n"
			. "  </IfModule>\n"
			. "</FilesMatch>\n";
	}

	/**
	 * محتوای صوت با پسوندش می‌خواند (۱۶ بایت اول): OggS | EBML | RIFF….WAVE | ID3 یا FF Ex | ….ftyp | FF F1/F9.
	 */
	public static function audio_matches( string $head, string $ext ): bool {
		if ( strlen( $head ) < 12 ) {
			return false;
		}
		switch ( $ext ) {
			case 'ogg':
			case 'oga':
				return 'OggS' === substr( $head, 0, 4 );
			case 'webm':
				return "\x1A\x45\xDF\xA3" === substr( $head, 0, 4 );
			case 'wav':
				return 'RIFF' === substr( $head, 0, 4 ) && 'WAVE' === substr( $head, 8, 4 );
			case 'mp3':
				return 'ID3' === substr( $head, 0, 3 ) || ( "\xFF" === $head[0] && 0xE0 === ( ord( $head[1] ) & 0xE0 ) );
			case 'mp4':
			case 'm4a':
				return 'ftyp' === substr( $head, 4, 4 );
			case 'aac':
				return "\xFF" === $head[0] && in_array( ord( $head[1] ), array( 0xF1, 0xF9 ), true );
		}

		return false;
	}

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
		if ( 'audio' === $kind ) {
			// محتوا باید واقعاً صوت هم‌نوعِ پسوند باشد (مثلاً فایل .php با پسوند .mp3 رد می‌شود).
			$head = '';
			$fh   = @fopen( (string) $file['tmp_name'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( $fh ) {
				$head = (string) fread( $fh, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( ! self::audio_matches( $head, $ext ) ) {
				return new WP_Error( 'paia_file', __( 'محتوای فایل با نوع صوتی آن مطابقت ندارد.', 'persian-ai-assistant' ), array( 'status' => 415 ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file['name'] = 'chat-' . strtolower( wp_generate_password( 16, false, false ) ) . '.' . $ext;

		$filter = array( self::class, 'upload_dir' );
		add_filter( 'upload_dir', $filter );
		$target = wp_upload_dir( null, false ); // با فیلتر بالا: uploads/paia-chat/سال/ماه
		self::ensure_dir( (string) $target['path'] ); // محافظ پوشه (index.php + .htaccess) پیش از نوشتن فایل
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
