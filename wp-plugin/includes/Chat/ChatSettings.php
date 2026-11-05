<?php
/**
 * تنظیمات ویجت چت سمت سایت (option مستقل: paia_chat_settings).
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Apis\Client;

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
			// پیام‌رسانی لحظه‌ای (WebSocket، فقط اعلان؛ نسخهٔ ۰.۵.۲۸). realtime_enabled = kill switch: خاموش = ویجت اصلاً WebSocket نمی‌زند و فقط polling.
			'realtime_enabled'    => 1,
			'ws_url'              => '', // خالی = wss://<آدرس سرور هوش مصنوعی>/v1/ws
			// polling (ثانیه): چت بسته / چت باز (سریع) / پس از بی‌حرکتی اول / سقف backoff / مدت بی‌حرکتی تا شروع backoff.
			// همین مقدارها هنگام fallback از WebSocket هم استفاده می‌شوند.
			'poll_closed'         => 60,
			'poll_fast'           => 2,
			'poll_mid'            => 5,
			'poll_max'            => 10,
			'poll_idle'           => 30,
			'ws_retry'            => 60, // fallback: هر چند ثانیه یک‌بار در پس‌زمینه دوباره WebSocket امتحان شود
			// سقف نرخ (برای هر بازدیدکننده/IP).
			'rl_poll_per_min'     => 60,
			'rl_token_per_min'    => 10,
			'rl_create_per_hour'  => 20,
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
			// سقف مصرف دلاری هوش مصنوعی (S-2). 0 = بدون سقف از سمت افزونه.
			'budget_daily_usd'    => 0,
			'budget_monthly_usd'  => 0,
			// قدیمی: فرم دیگر نشانشان نمی‌دهد ولی ReplyEngine هنوز می‌خواندشان؛ مقدارشان هنگام ذخیره حفظ می‌شود.
			// آدرس و کلید اتصال به سرور هوش مصنوعی (S-5). روش اصلی: ثابت‌های PAIA_API_URL و PAIA_API_SECRET در wp-config.php؛
			// با تعریف PAIA_API_SECRET مقدار api_key هرگز در دیتابیس ذخیره نمی‌شود (sanitize آن را خالی می‌کند).
			'api_url'             => '',
			'api_key'             => '',
		);
	}

	/** سقف دلاری هر دوره ('daily'|'monthly'). */
	public static function budget( string $which ): float {
		return (float) self::get( 'daily' === $which ? 'budget_daily_usd' : 'budget_monthly_usd', 0 );
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
	 * عددی صحیح از تنظیمات با حد پایین/بالا.
	 */
	private static function int_setting( string $key, int $min, int $max ): int {
		return max( $min, min( $max, (int) self::get( $key, 0 ) ) );
	}

	/**
	 * آیا پیام‌رسانی لحظه‌ای (WebSocket) اجازه دارد؟ kill switch سمت وردپرس.
	 * فقط وقتی اتصال به سرور هوش مصنوعی تنظیم شده باشد معنا دارد (کلید امضا + آدرس).
	 */
	public static function realtime_enabled(): bool {
		return (bool) apply_filters( 'paia_realtime_enabled', ! empty( self::get( 'realtime_enabled' ) ) );
	}

	/**
	 * تنظیمات polling ویجت سایت (میلی‌ثانیه).
	 *
	 *  closed : چت بسته (و پیامی در انتظار نیست) — پیش‌فرض ۶۰ ثانیه
	 *  fast   : چت باز و فعال — پیش‌فرض ۲ ثانیه
	 *  mid    : پس از idle میلی‌ثانیه بی‌حرکتی — پیش‌فرض ۵ ثانیه
	 *  max    : سقف backoff — پیش‌فرض ۱۰ ثانیه
	 *  idle   : مدت بی‌حرکتی تا شروع backoff — پیش‌فرض ۳۰ ثانیه
	 *  retry  : فاصلهٔ تلاش مجدد WebSocket در حالت fallback — پیش‌فرض ۶۰ ثانیه
	 *
	 * تب پنهان همیشه متوقف است (در هر دو حالت). حالت «بهینه‌شده» همهٔ فاصله‌ها را دو برابر می‌کند.
	 *
	 * @return array{closed:int,fast:int,mid:int,max:int,idle:int,retry:int,typing:int}
	 */
	public static function widget_poll(): array {
		$k    = self::is_optimized() ? 2 : 1;
		$fast = self::int_setting( 'poll_fast', 1, 30 );
		$max  = max( $fast, self::int_setting( 'poll_max', 2, 120 ) );

		return array(
			'closed' => 1000 * $k * self::int_setting( 'poll_closed', 15, 900 ),
			'fast'   => 1000 * $k * $fast,
			'mid'    => 1000 * $k * min( $max, max( $fast, self::int_setting( 'poll_mid', 2, 60 ) ) ),
			'max'    => 1000 * $k * $max,
			'idle'   => 1000 * self::int_setting( 'poll_idle', 5, 600 ),
			'retry'  => 1000 * self::int_setting( 'ws_retry', 15, 900 ),
			'typing' => self::is_optimized() ? 5000 : 3000,
		);
	}

	/**
	 * سقف نرخ‌ها (هر مقدار قابل‌تنظیم از تنظیمات؛ فیلتر paia_rate_limits برای override در کد).
	 *
	 * @return array{poll_per_min:int,token_per_min:int,create_per_hour:int}
	 */
	public static function rate_limits(): array {
		return (array) apply_filters(
			'paia_rate_limits',
			array(
				'poll_per_min'    => self::int_setting( 'rl_poll_per_min', 10, 600 ),
				'token_per_min'   => self::int_setting( 'rl_token_per_min', 3, 120 ),
				'create_per_hour' => self::int_setting( 'rl_create_per_hour', 3, 500 ),
			)
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
		$saved  = get_option( self::OPTION, array() );
		$merged = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		if ( Client::secret_from_constant() ) {
			$merged['api_key'] = ''; // کلید فقط از wp-config.php خوانده می‌شود؛ مقدار قدیمیِ option به هیچ‌جا نمی‌رسد.
		}

		return $merged;
	}

	/**
	 * اگر PAIA_API_SECRET در wp-config.php تعریف شده، کلیدِ احتمالاً ذخیره‌شدهٔ قبلی را از دیتابیس پاک می‌کند (S-5).
	 * باید پیش از admin_init (ثبت sanitize_callback) صدا زده شود تا update_option وارد sanitize نشود.
	 */
	public static function scrub_stored_key(): void {
		if ( ! Client::secret_from_constant() ) {
			return;
		}
		$saved = get_option( self::OPTION, null );
		if ( is_array( $saved ) && '' !== (string) ( $saved['api_key'] ?? '' ) ) {
			$saved['api_key'] = '';
			update_option( self::OPTION, $saved );
		}
	}

	/**
	 * کلید اتصال به‌صورت ماسک‌شده برای نمایش (•••••••• + ۴ نویسهٔ آخر)؛ خالی اگر کلیدی نیست یا از ثابت wp-config.php می‌آید.
	 */
	public static function masked_key(): string {
		if ( Client::secret_from_constant() ) {
			return '';
		}

		return Client::mask_secret( (string) self::get( 'api_key', '' ) );
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

		// پیام‌رسانی لحظه‌ای + polling + سقف نرخ.
		$out['realtime_enabled'] = empty( $in['realtime_enabled'] ) ? 0 : 1;
		$ws_url                  = isset( $in['ws_url'] ) ? esc_url_raw( trim( (string) $in['ws_url'] ), array( 'wss', 'ws' ) ) : '';
		$out['ws_url']           = $ws_url;
		foreach (
			array(
				'poll_closed'        => array( 15, 900 ),
				'poll_fast'          => array( 1, 30 ),
				'poll_mid'           => array( 2, 60 ),
				'poll_max'           => array( 2, 120 ),
				'poll_idle'          => array( 5, 600 ),
				'ws_retry'           => array( 15, 900 ),
				'rl_poll_per_min'    => array( 10, 600 ),
				'rl_token_per_min'   => array( 3, 120 ),
				'rl_create_per_hour' => array( 3, 500 ),
			) as $key => $range
		) {
			$out[ $key ] = ( isset( $in[ $key ] ) && '' !== $in[ $key ] ) ? max( $range[0], min( $range[1], (int) $in[ $key ] ) ) : $def[ $key ];
		}

		// فرم دیگر فیلد آدرس/کلید API ندارد؛ مقدار قبلی را نگه می‌داریم (ReplyEngine هنوز از آن‌ها می‌خواند).
		$prev           = get_option( self::OPTION, array() );
		$prev           = is_array( $prev ) ? $prev : array();
		$out['api_url'] = isset( $in['api_url'] ) ? esc_url_raw( trim( (string) $in['api_url'] ) ) : ( isset( $prev['api_url'] ) ? (string) $prev['api_url'] : '' );
		$out['api_key'] = isset( $prev['api_key'] ) ? (string) $prev['api_key'] : '';
		if ( Client::secret_from_constant() ) {
			$out['api_key'] = ''; // S-5: کلید از ثابت wp-config.php می‌آید؛ هیچ مقداری در option نگه داشته نمی‌شود.
		} elseif ( ! empty( $in['api_key_clear'] ) ) {
			$out['api_key'] = ''; // تیک «حذف کلید ذخیره‌شده».
		} elseif ( isset( $in['api_key'] ) ) {
			$new = trim( sanitize_text_field( (string) $in['api_key'] ) );
			// فیلد خالی = حفظ مقدار قبلی؛ مقدار ماسک‌شده (••••) هم هرگز به‌عنوان کلید جدید ذخیره نمی‌شود.
			if ( '' !== $new && false === strpos( $new, '•' ) ) {
				$out['api_key'] = $new;
			}
		}

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

		foreach ( array( 'budget_daily_usd', 'budget_monthly_usd' ) as $key ) {
			$v           = isset( $in[ $key ] ) ? (float) str_replace( ',', '.', (string) $in[ $key ] ) : 0.0;
			$out[ $key ] = round( max( 0.0, min( 100000.0, $v ) ), 2 );
		}

		$notes                  = isset( $in['important_notes'] ) ? sanitize_textarea_field( (string) $in['important_notes'] ) : '';
		$out['important_notes'] = self::cut( $notes, self::NOTES_MAX );

		return $out;
	}
}
