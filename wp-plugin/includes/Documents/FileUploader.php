<?php
namespace PersianWPChatbot\Documents;

use PersianWPChatbot\Database\FileStore;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * آپلود فایل در تب «اسناد اضافه»: اعتبارسنجی، ذخیرهٔ فایل در uploads/pwc-docs و ثبت رکورد در pwc_files.
 *
 * اعتبارسنجی: لیست سفید پسوند (pdf, doc, docx, txt)، سقف حجم، و بررسی امضای واقعی محتوای فایل
 * (نه فقط پسوند) تا فایل دیگری با پسوند جعلی ذخیره نشود. TXT باید UTF-8 باشد.
 * تشخیص «اسکن/تصویری بودن» خودکار نیست؛ مدیر در دیالوگ آپلود آن را تأیید می‌کند.
 */
final class FileUploader {

	/**
	 * @param array<string, mixed> $file  عضوی از $_FILES.
	 * @return array<string, mixed>|WP_Error رکورد ثبت‌شده (مثل FileStore::present)
	 */
	public static function handle( array $file, string $title ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
				return self::too_large();
			}

			return new WP_Error( 'pwc_upload_failed', __( 'آپلود فایل ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
		}

		$tmp  = (string) ( $file['tmp_name'] ?? '' );
		$size = (int) ( $file['size'] ?? 0 );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'pwc_upload_failed', __( 'آپلود فایل ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
		}
		if ( $size <= 0 ) {
			return new WP_Error( 'pwc_upload_empty', __( 'فایل خالی است.', 'persian-wp-chatbot' ), array( 'status' => 400 ) );
		}
		if ( $size > FileStore::max_bytes() ) {
			return self::too_large();
		}

		$original = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$ext      = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, FileStore::EXTENSIONS, true ) ) {
			return new WP_Error( 'pwc_upload_type', __( 'فقط فایل‌های PDF، DOC، DOCX و TXT پذیرفته می‌شود.', 'persian-wp-chatbot' ), array( 'status' => 415 ) );
		}

		$problem = self::content_problem( $tmp, $ext );
		if ( '' !== $problem ) {
			return new WP_Error( 'pwc_upload_content', $problem, array( 'status' => 415 ) );
		}

		$sub = gmdate( 'Y/m' );
		$dir = FileStore::base_dir() . '/' . $sub;
		if ( ! wp_mkdir_p( $dir ) || ! self::protect_dir( FileStore::base_dir() ) ) {
			return new WP_Error( 'pwc_upload_dir', __( 'ساخت پوشهٔ ذخیرهٔ فایل‌ها ناموفق بود. دسترسی نوشتن پوشهٔ uploads را بررسی کنید.', 'persian-wp-chatbot' ), array( 'status' => 500 ) );
		}

		$stored = strtolower( wp_generate_password( 24, false, false ) ) . '.' . $ext;
		$target = $dir . '/' . $stored;
		if ( ! move_uploaded_file( $tmp, $target ) ) { // phpcs:ignore Generic.PHP.ForbiddenFunctions
			return new WP_Error( 'pwc_upload_move', __( 'ذخیرهٔ فایل روی سرور ناموفق بود.', 'persian-wp-chatbot' ), array( 'status' => 500 ) );
		}
		@chmod( $target, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		$title = trim( sanitize_text_field( $title ) );
		if ( '' === $title ) {
			$title = (string) pathinfo( $original, PATHINFO_FILENAME );
		}
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200, 'UTF-8' ) : substr( $title, 0, 200 );

		$id  = 'file:' . (string) (int) round( microtime( true ) * 1000 );
		$row = array(
			'id'            => $id,
			'title'         => $title,
			'original_name' => $original,
			'path'          => $sub . '/' . $stored,
			'ext'           => $ext,
			'size'          => $size,
		);

		// اول ذخیره در دیتابیس؛ اگر شکست خورد فایل هم پاک می‌شود تا فایل یتیم نماند.
		if ( ! FileStore::insert( $row ) ) {
			wp_delete_file( $target );

			return new WP_Error( 'pwc_save_failed', __( 'ثبت فایل در پایگاه داده ناموفق بود.', 'persian-wp-chatbot' ), array( 'status' => 500 ) );
		}

		$saved = FileStore::get( $id );

		return null !== $saved ? FileStore::present( $saved ) : FileStore::present(
			array_merge(
				$row,
				array(
					'ai_status'  => 0,
					'created_at' => time(),
				)
			)
		);
	}

	private static function too_large(): WP_Error {
		return new WP_Error( 'pwc_upload_size', __( 'حجم فایل بیش از حد مجاز است.', 'persian-wp-chatbot' ), array( 'status' => 413 ) );
	}

	/**
	 * بررسی امضای محتوا. رشتهٔ خالی = سالم؛ وگرنه پیام خطا.
	 */
	private static function content_problem( string $path, string $ext ): string {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return __( 'خواندن فایل ممکن نبود.', 'persian-wp-chatbot' );
		}
		$head = (string) fread( $handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$bad = __( 'محتوای فایل با فرمت آن هم‌خوانی ندارد یا فایل آسیب دیده است.', 'persian-wp-chatbot' );

		switch ( $ext ) {
			case 'pdf':
				return 0 === strpos( ltrim( substr( $head, 0, 1024 ) ), '%PDF-' ) ? '' : $bad;
			case 'docx':
				return 0 === strpos( $head, "PK\x03\x04" ) ? '' : $bad;
			case 'doc':
				return 0 === strpos( $head, "\xD0\xCF\x11\xE0" ) ? '' : $bad;
			case 'txt':
				$utf8 = __( 'فایل TXT باید با کدگذاری UTF-8 ذخیره شده باشد (در برنامهٔ ویرایشگر، «Save as» و انتخاب UTF-8).', 'persian-wp-chatbot' );
				if ( false !== strpos( $head, "\0" ) ) {
					return $utf8;
				}
				// ممکن است نمونه وسط یک نویسه بریده شده باشد؛ ۳ بایت انتها کنار گذاشته می‌شود.
				$sample = strlen( $head ) >= 65536 ? substr( $head, 0, -3 ) : $head;
				if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $sample, 'UTF-8' ) ) {
					return $utf8;
				}
				return '';
		}

		return $bad;
	}

	/**
	 * جلوگیری از فهرست‌شدن پوشه و اجرای فایل‌ها (روی Apache با .htaccess؛ روی nginx با تنظیم سرور).
	 */
	private static function protect_dir( string $base ): bool {
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return false;
		}

		if ( ! file_exists( $base . '/index.php' ) ) {
			@file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			@file_put_contents( $base . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return true;
	}
}
