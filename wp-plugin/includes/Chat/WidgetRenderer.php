<?php
/**
 * نمایش ویجت چت در همهٔ صفحه‌های سایت.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Support\Status;

defined( 'ABSPATH' ) || exit;

final class WidgetRenderer {

	private function root_dir(): string {
		return dirname( __DIR__, 2 );
	}

	private function root_url(): string {
		return plugin_dir_url( $this->root_dir() . '/persian-ai-assistant.php' );
	}

	private function version(): string {
		return defined( 'PAIA_VERSION' ) ? (string) PAIA_VERSION : '0.5.0';
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 5 );
	}

	private function should_show(): bool {
		// کلید اصلی چت‌بات (تب «عمومی») خاموش = هیچ چیزی روی سایت نیست.
		if ( ! Status::is_enabled() ) {
			return false;
		}
		if ( is_admin() || is_customize_preview() || is_embed() || ! ChatSettings::get( 'enabled' ) ) {
			return false;
		}
		/** اجازه می‌دهد ویجت در برخی صفحه‌ها مخفی شود. */
		return (bool) apply_filters( 'paia_chat_show_widget', true );
	}

	public function enqueue(): void {
		if ( ! $this->should_show() ) {
			return;
		}
		$url = $this->root_url();
		$ver = $this->version();

		wp_enqueue_style( 'paia-chat-widget', $url . 'assets/css/widget.css', array(), $ver );
		wp_enqueue_script( 'paia-chat-widget', $url . 'assets/js/widget.js', array(), $ver, true );

		$cfg = array(
			'restUrl'  => esc_url_raw( rest_url( 'paia/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'locale'   => str_replace( '_', '-', get_locale() ),
			'maxMb'    => (int) ChatSettings::get( 'max_upload_mb', 5 ),
			'images'   => (bool) ChatSettings::get( 'allow_images' ),
			'voice'    => (bool) ChatSettings::get( 'allow_voice' ),
			'botName'  => (string) ChatSettings::get( 'bot_name' ),
			'botLogo'  => esc_url( (string) ChatSettings::get( 'bot_logo', '' ) ), // لوگوی هدر چت؛ خالی = آیکن پیش‌فرض
			'online'   => ChatSettings::is_online(),
			'dark'     => (bool) ChatSettings::get( 'dark_enabled' ), // تم دارک هوشمند (تشخیص در widget.js)
			'onlineText'  => (string) ChatSettings::get( 'online_text' ),
			'offlineText' => (string) ChatSettings::get( 'offline_text' ),
			'aiDelay'  => ChatSettings::ai_delay(), // ثانیه؛ ۰ = هوش مصنوعی بلافاصله جواب می‌دهد (نقطه‌های تایپ فوراً)
			'loggedIn' => is_user_logged_in(), // کاربر واردشده نیازی به فرم نام ندارد؛ ویجت صفحهٔ چت را فوراً باز می‌کند
			'poll'     => ChatSettings::widget_poll(), // fast: چت باز و تب فعال (۱ثانیه)، slow: چت بسته/تب دیگر (۴ثانیه)
			'teaser'   => array(
				'title' => (string) ChatSettings::get( 'teaser_title' ),
				'text'  => (string) ChatSettings::get( 'teaser_text' ),
			),
			'intro'    => (string) ChatSettings::get( 'profile_intro' ),
			'i18n'     => array(
				'open'         => __( 'باز کردن گفتگو', 'persian-ai-assistant' ),
				'close'        => __( 'بستن', 'persian-ai-assistant' ),
				'supportBy'    => __( 'پاسخ از طرف پشتیبانی:', 'persian-ai-assistant' ),
				'newMessage'   => __( 'پیام جدید', 'persian-ai-assistant' ),
				'placeholder'  => __( 'پیام خود را بنویسید...', 'persian-ai-assistant' ),
				'send'         => __( 'ارسال', 'persian-ai-assistant' ),
				'attach'       => __( 'ارسال عکس', 'persian-ai-assistant' ),
				'record'       => __( 'ضبط ویس', 'persian-ai-assistant' ),
				'cancel'       => __( 'لغو', 'persian-ai-assistant' ),
				'remove'       => __( 'حذف', 'persian-ai-assistant' ),
				'loading'      => __( 'در حال آماده‌سازی...', 'persian-ai-assistant' ),
				'loadError'    => __( 'اتصال برقرار نشد. اینترنت خود را بررسی کنید.', 'persian-ai-assistant' ),
				'retry'        => __( 'تلاش دوباره', 'persian-ai-assistant' ),
				'sendFailed'   => __( 'ارسال نشد', 'persian-ai-assistant' ),
				'suggestions'  => __( 'پیشنهاد پیام', 'persian-ai-assistant' ),
				'nameLabel'    => __( 'نام شما', 'persian-ai-assistant' ),
				'contactLabel' => __( 'ایمیل یا شماره تماس', 'persian-ai-assistant' ),
				'start'        => __( 'شروع گفتگو', 'persian-ai-assistant' ),
				'guest'        => __( 'ادامه به عنوان مهمان', 'persian-ai-assistant' ),
				'micDenied'    => __( 'اجازهٔ دسترسی به میکروفون داده نشد.', 'persian-ai-assistant' ),
				'micUnsupported' => __( 'مرورگر شما از ضبط صدا پشتیبانی نمی‌کند.', 'persian-ai-assistant' ),
				'tooBig'       => __( 'حجم فایل بیش از حد مجاز است.', 'persian-ai-assistant' ),
				'badFile'      => __( 'فقط فایل تصویر قابل ارسال است.', 'persian-ai-assistant' ),
				'recording'    => __( 'در حال ضبط...', 'persian-ai-assistant' ),
				'image'        => __( 'تصویر', 'persian-ai-assistant' ),
				'starting'     => __( 'در حال شروع...', 'persian-ai-assistant' ),
				'loadingMsgs'  => __( 'در حال بارگذاری پیام‌ها', 'persian-ai-assistant' ),
				'loadingOlder' => __( 'در حال بارگذاری پیام‌های قبلی', 'persian-ai-assistant' ),
				'openLink'     => __( 'مشاهده', 'persian-ai-assistant' ),
			),
		);
		wp_add_inline_script( 'paia-chat-widget', 'window.PAIA_CHAT = ' . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}

	public function render(): void {
		if ( ! $this->should_show() ) {
			return;
		}
		$s     = ChatSettings::all();
		// رنگ‌های تم روشن (--pcl-*) و دارک (--pcd-*)؛ widget.css بر اساس data-theme یکی را به --pc-* وصل می‌کند.
		$style = sprintf(
			'--pcl-primary:%1$s;--pcl-accent:%2$s;--pcl-surface:%3$s;--pcl-text:%4$s;--pcl-field:%5$s;',
			$s['color_primary'],
			$s['color_accent'],
			$s['color_surface'],
			$s['color_text'],
			$s['color_field']
		);
		if ( ! empty( $s['dark_enabled'] ) ) {
			foreach ( ChatSettings::dark_vars() as $name => $value ) {
				$style .= $name . ':' . $value . ';';
			}
		}
		printf(
			'<div id="paia-chat-root" class="paia-chat" dir="%1$s" data-position="%2$s" style="%3$s"></div>',
			esc_attr( is_rtl() ? 'rtl' : 'ltr' ),
			esc_attr( 'left' === $s['position'] ? 'left' : 'right' ),
			esc_attr( $style )
		);
	}
}
