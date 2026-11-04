<?php
/**
 * تنظیمات ویجت چت سمت سایت (option مستقل: paia_chat_settings).
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class ChatSettings {

	public const OPTION = 'paia_chat_settings';

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'             => 1,
			'position'            => 'right',
			'bot_name'            => __( 'دستیار هوشمند', 'persian-ai-assistant' ),
			'bot_logo'            => '', // آدرس لوگوی بالای چت سایت (از کتابخانهٔ رسانه)؛ خالی = آیکن پیش‌فرض
			'teaser_title'        => __( 'هر سوالی دارید بپرسید.', 'persian-ai-assistant' ),
			'teaser_text'         => __( 'پاسخگوی شما هستیم.', 'persian-ai-assistant' ),
			'profile_intro'       => __( 'درود، خوش آمدید.', 'persian-ai-assistant' ),
			'welcome_message'     => __( 'درود، خوش آمدید! چطور می‌توانم کمکتان کنم؟', 'persian-ai-assistant' ),
			'default_suggestions' => __( "می‌خواهم محصولات را ببینم\nچطور سفارش بدهم؟\nمی‌خواهم با پشتیبانی صحبت کنم", 'persian-ai-assistant' ),
			'fallback_reply'      => __( 'پیام شما ثبت شد. به‌زودی پاسخ می‌دهیم.', 'persian-ai-assistant' ),
			'online_text'         => __( 'آنلاین و آماده پاسخ‌گویی', 'persian-ai-assistant' ),
			'offline_text'        => __( 'پیام بگذارید، در اولین فرصت پاسخ می‌دهیم', 'persian-ai-assistant' ),
			'ai_delay'            => 3,
			'online_schedule'     => 0,
			'online_from'         => '09:00',
			'online_to'           => '21:00',
			'color_primary'       => '#6D28D9',
			'color_accent'        => '#06B6D4',
			'color_surface'       => '#F8F7FF',
			'color_text'          => '#1E1B3A',
			'color_field'         => '#E9E5FA',
			'allow_images'        => 1,
			'allow_voice'         => 1,
			'max_upload_mb'       => 5,
			'optimize'            => 'advanced',
			// تم دارک چت (از ۰.۵.۱۲): همان ۵ رنگ، نسخهٔ تیره. فقط وقتی dark_enabled روشن باشد و صفحهٔ سایت تیره باشد اعمال می‌شود.
			'dark_enabled'        => 0,
			'dark_primary'        => '#8B5CF6',
			'dark_accent'         => '#22D3EE',
			'dark_surface'        => '#0F172A',
			'dark_text'           => '#E5E7EB',
			'dark_field'          => '#1E293B',
			// اطلاعات کلی سایت و سرویس هوش مصنوعی (تب تنظیمات ← عمومی).
			'site_title'          => '', // خالی = عنوان سایت وردپرس
			'site_desc'           => '',
			'ai_service'          => 'gpt-5-4',
			'tone'                => 'formal', // formal | friendly
			'important_notes'     => '',
			// مارک کردن چت توسط هوش مصنوعی (از ۰.۵.۲۸): روشن = با هر پیام `mark-the-conversation: true` به سرور می‌رود و اگر هوش مصنوعی
			// نتواند جواب بدهد (پاسخ دقیقاً «Mark-Chat») هیچ پیامی نمایش داده نمی‌شود و فقط گفتگو مارک می‌شود.
			'ai_mark'             => 0,
			// قدیمی: فرم دیگر نشانشان نمی‌دهد ولی ReplyEngine هنوز می‌خواندشان؛ مقدارشان هنگام ذخیره حفظ می‌شود.
			'api_url'             => '',
			'api_key'             => '',
		);
	}

	/** حداکثر طول «توضیحات مهم» (نویسه) برای جلوگیری از مصرف زیاد توکن. */
	public const NOTES_MAX = 1500;

	/** حداکثر طول توضیح کوتاه سایت (یک خط). */
	public const DESC_MAX = 160;

	/**
	 * سرویس‌های هوش مصنوعی قابل انتخاب (کلید ← برچسب). فعلاً فقط یکی.
	 *
	 * @return array<string,string>
	 */
	public static function ai_services(): array {
		return array( 'gpt-5-4' => 'Chat-GPT-5.4' );
	}

	/**
	 * لحن‌های گفتگو (کلید ← برچسب).
	 *
	 * @return array<string,string>
	 */
	public static function tones(): array {
		return array(
			'formal'   => __( 'رسمی', 'persian-ai-assistant' ),
			'friendly' => __( 'دوستانه', 'persian-ai-assistant' ),
		);
	}

	/**
	 * عنوان سایت برای هوش مصنوعی: مقدار ذخیره‌شده، وگرنه عنوان سایت وردپرس.
	 */
	public static function site_title(): string {
		$title = trim( (string) self::get( 'site_title', '' ) );

		return '' !== $title ? $title : (string) get_bloginfo( 'name' );
	}

	/**
	 * رنگ‌های تم دارک (همان کلیدهای --pc-*).
	 *
	 * @return array<string,string>
	 */
	public static function dark_vars(): array {
		$s = self::all();

		return array(
			'--pcd-primary' => $s['dark_primary'],
			'--pcd-accent'  => $s['dark_accent'],
			'--pcd-surface' => $s['dark_surface'],
			'--pcd-text'    => $s['dark_text'],
			'--pcd-field'   => $s['dark_field'],
		);
	}

	/**
	 * حالت «بهینه‌شده» برای هاست‌های ضعیف: همهٔ polling و کوئری‌های چت‌بات کندتر می‌شوند.
	 */
	public static function is_optimized(): bool {
		return 'optimized' === self::get( 'optimize', 'advanced' );
	}

	/**
	 * ضریب کندکردن polling (۱ = حالت پیشرفته، ۴ = حالت بهینه‌شده).
	 */
	public static function slow_factor(): int {
		return self::is_optimized() ? 4 : 1;
	}

	/**
	 * تنظیمات polling ویجت سایت (میلی‌ثانیه).
	 * fast: چت باز و تب فعال (۱ ثانیه) — slow: چت بسته یا کاربر در تب دیگر (۴ ثانیه).
	 *
	 * @return array{fast:int,slow:int,typing:int,hidden:bool}
	 */
	public static function widget_poll(): array {
		$f = self::slow_factor();
		return array(
			'fast'   => 1000 * $f,
			'slow'   => 4000 * $f,
			'typing' => self::is_optimized() ? 5000 : 3000,
			'hidden' => ! self::is_optimized(), // در حالت بهینه‌شده تب پس‌زمینه اصلاً polling نمی‌کند.
		);
	}

	/**
	 * تنظیمات polling پنل مدیریت (میلی‌ثانیه).
	 * fast: دقیقاً در تب «مدیریت چت» (۱ ثانیه) — slow: در غیر این صورت (۳ ثانیه).
	 *
	 * @return array{fast:int,slow:int,typing:int}
	 */
	public static function admin_poll(): array {
		$f = self::slow_factor();
		return array(
			'fast'   => 1000 * $f,
			'slow'   => 3000 * $f,
			'typing' => self::is_optimized() ? 5000 : 3000,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * @param string $key     کلید.
	 * @param mixed  $default مقدار پیش‌فرض.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * @return string[] پیشنهادهای پیش‌فرض (هر خط یک پیشنهاد).
	 */
	public static function default_suggestions(): array {
		$lines = preg_split( '/\R/u', (string) self::get( 'default_suggestions', '' ) );
		$lines = array_filter( array_map( 'trim', $lines ) );
		return array_slice( array_values( $lines ), 0, 6 );
	}

	/**
	 * آیا اکنون (به وقت سایت) در بازهٔ «آنلاین» هستیم؟
	 * اگر برنامهٔ ساعتی خاموش باشد همیشه آنلاین است. بازهٔ شبانه‌روزی (مثلاً ۲۲:۰۰ تا ۰۶:۰۰) پشتیبانی می‌شود.
	 */
	public static function is_online(): bool {
		if ( ! self::get( 'online_schedule' ) ) {
			return true;
		}
		$from = self::minutes( (string) self::get( 'online_from', '09:00' ), 9 * 60 );
		$to   = self::minutes( (string) self::get( 'online_to', '21:00' ), 21 * 60 );
		$now  = wp_date( 'H:i' );
		$cur  = self::minutes( $now, 0 );

		if ( $from === $to ) {
			return true;
		}
		if ( $from < $to ) {
			return $cur >= $from && $cur < $to;
		}
		return $cur >= $from || $cur < $to;
	}

	/**
	 * متن زیر نام دستیار با توجه به وضعیت آنلاین.
	 */
	/**
	 * مدت (ثانیه) سکوتِ تایپ کاربر پیش از ارسال پیام‌های جمع‌شده برای هوش مصنوعی. 0 = بلافاصله.
	 */
	public static function ai_delay(): int {
		return max( 0, min( 60, (int) self::get( 'ai_delay', 3 ) ) );
	}

	public static function status_text( bool $online ): string {
		return $online ? (string) self::get( 'online_text' ) : (string) self::get( 'offline_text' );
	}

	private static function cut( string $text, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}

		return substr( $text, 0, $max );
	}

	private static function minutes( string $hhmm, int $fallback ): int {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $hhmm ), $m ) ) {
			return $fallback;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	private static function clean_time( $value, string $fallback ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m ) ) {
			return $fallback;
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	/**
	 * @param mixed $input ورودی فرم.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$in  = is_array( $input ) ? $input : array();
		$def = self::defaults();
		$out = array();

		foreach ( array( 'enabled', 'allow_images', 'allow_voice', 'online_schedule', 'ai_mark' ) as $key ) {
			$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
		}

		$out['position'] = ( isset( $in['position'] ) && 'left' === $in['position'] ) ? 'left' : 'right';

		foreach ( array( 'bot_name', 'teaser_title', 'teaser_text', 'profile_intro', 'online_text', 'offline_text' ) as $key ) {
			$value       = isset( $in[ $key ] ) ? sanitize_text_field( (string) $in[ $key ] ) : '';
			$out[ $key ] = '' !== $value ? $value : $def[ $key ];
		}

		// پاسخ پیش‌فرض و پیشنهادها می‌توانند خالی باشند؛ پاسخ پیش‌فرض خالی یعنی هیچ پیامی ارسال نشود.
		foreach ( array( 'welcome_message', 'default_suggestions', 'fallback_reply' ) as $key ) {
			$value       = isset( $in[ $key ] ) ? sanitize_textarea_field( (string) $in[ $key ] ) : '';
			$out[ $key ] = ( '' !== $value || 'default_suggestions' === $key || 'fallback_reply' === $key ) ? $value : $def[ $key ];
		}

		$out['ai_delay'] = isset( $in['ai_delay'] ) && '' !== $in['ai_delay'] ? max( 0, min( 60, (int) $in['ai_delay'] ) ) : $def['ai_delay'];

		$out['online_from'] = self::clean_time( $in['online_from'] ?? '', $def['online_from'] );
		$out['online_to']   = self::clean_time( $in['online_to'] ?? '', $def['online_to'] );

		foreach ( array( 'color_primary', 'color_accent', 'color_surface', 'color_text', 'color_field' ) as $key ) {
			$color       = isset( $in[ $key ] ) ? sanitize_hex_color( (string) $in[ $key ] ) : '';
			$out[ $key ] = $color ? $color : $def[ $key ];
		}

		// لوگوی بالای چت: فقط آدرس http(s)؛ خالی = آیکن پیش‌فرض.
		$logo            = isset( $in['bot_logo'] ) ? esc_url_raw( trim( (string) $in['bot_logo'] ), array( 'http', 'https' ) ) : '';
		$out['bot_logo'] = $logo;

		$out['max_upload_mb'] = isset( $in['max_upload_mb'] ) ? max( 1, min( 20, (int) $in['max_upload_mb'] ) ) : $def['max_upload_mb'];
		$out['optimize']      = ( isset( $in['optimize'] ) && 'optimized' === $in['optimize'] ) ? 'optimized' : 'advanced';

		// فرم دیگر فیلد آدرس/کلید API ندارد؛ مقدار قبلی را نگه می‌داریم (ReplyEngine هنوز از آن‌ها می‌خواند).
		$prev           = get_option( self::OPTION, array() );
		$prev           = is_array( $prev ) ? $prev : array();
		$out['api_url'] = isset( $in['api_url'] ) ? esc_url_raw( trim( (string) $in['api_url'] ) ) : ( isset( $prev['api_url'] ) ? (string) $prev['api_url'] : '' );
		$out['api_key'] = isset( $in['api_key'] ) ? sanitize_text_field( (string) $in['api_key'] ) : ( isset( $prev['api_key'] ) ? (string) $prev['api_key'] : '' );

		// تم دارک.
		$out['dark_enabled'] = empty( $in['dark_enabled'] ) ? 0 : 1;
		foreach ( array( 'dark_primary', 'dark_accent', 'dark_surface', 'dark_text', 'dark_field' ) as $key ) {
			$color       = isset( $in[ $key ] ) ? sanitize_hex_color( (string) $in[ $key ] ) : '';
			$out[ $key ] = $color ? $color : $def[ $key ];
		}

		// اطلاعات کلی سایت.
		$title             = isset( $in['site_title'] ) ? sanitize_text_field( (string) $in['site_title'] ) : '';
		// اگر مدیر همان عنوان وردپرس را نگه داشته، ذخیره نمی‌کنیم تا با تغییر عنوان سایت هماهنگ بماند.
		$out['site_title'] = ( $title === (string) get_bloginfo( 'name' ) ) ? '' : $title;
		// «یک خط»: sanitize_text_field خط جدید را حذف می‌کند.
		$desc              = isset( $in['site_desc'] ) ? sanitize_text_field( (string) $in['site_desc'] ) : '';
		$out['site_desc']  = self::cut( $desc, self::DESC_MAX );

		// سرویس و لحن: فقط مقدارهای مجاز.
		$service              = isset( $in['ai_service'] ) ? (string) $in['ai_service'] : '';
		$out['ai_service']    = array_key_exists( $service, self::ai_services() ) ? $service : $def['ai_service'];
		$tone                 = isset( $in['tone'] ) ? (string) $in['tone'] : '';
		$out['tone']          = array_key_exists( $tone, self::tones() ) ? $tone : $def['tone'];

		$notes                  = isset( $in['important_notes'] ) ? sanitize_textarea_field( (string) $in['important_notes'] ) : '';
		$out['important_notes'] = self::cut( $notes, self::NOTES_MAX );

		return $out;
	}
}
