<?php
/**
 * نمایش ویجت چت در همهٔ صفحه‌های سایت.
 *
 * @package PersianWPChatbot
 */

namespace PersianWPChatbot\Chat;

use PersianWPChatbot\Support\Status;

defined( 'ABSPATH' ) || exit;

final class WidgetRenderer {

	private function root_dir(): string {
		return dirname( __DIR__, 2 );
	}

	private function root_url(): string {
		return plugin_dir_url( $this->root_dir() . '/persian-wp-chatbot.php' );
	}

	private function version(): string {
		return defined( 'PWC_VERSION' ) ? (string) PWC_VERSION : '1.0.0';
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
		return (bool) apply_filters( 'pwc_chat_show_widget', true );
	}

	public function enqueue(): void {
		if ( ! $this->should_show() ) {
			return;
		}
		$url = $this->root_url();
		$ver = $this->version();

		wp_enqueue_style( 'pwc-chat-widget', $url . 'assets/css/widget.css', array(), $ver );
		wp_enqueue_script( 'pwc-chat-widget', $url . 'assets/js/widget.js', array(), $ver, true );

		$cfg = array(
			'restUrl'  => esc_url_raw( rest_url( 'pwc/v1/' ) ),
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
			'poll'     => ChatSettings::widget_poll(), // closed/fast/mid/max/idle/retry (میلی‌ثانیه)
			'realtime' => Realtime::enabled(), // kill switch + اتصال تنظیم‌شده؛ false = فقط polling
			'teaser'   => array(
				'title' => (string) ChatSettings::get( 'teaser_title' ),
				'text'  => (string) ChatSettings::get( 'teaser_text' ),
			),
			'intro'    => (string) ChatSettings::get( 'profile_intro' ),
			'i18n'     => array(
				'open'         => __( 'باز کردن گفتگو', 'persian-wp-chatbot' ),
				'close'        => __( 'بستن', 'persian-wp-chatbot' ),
				'supportBy'    => __( 'پاسخ از طرف پشتیبانی:', 'persian-wp-chatbot' ),
				'newMessage'   => __( 'پیام جدید', 'persian-wp-chatbot' ),
				'placeholder'  => __( 'پیام خود را بنویسید...', 'persian-wp-chatbot' ),
				'send'         => __( 'ارسال', 'persian-wp-chatbot' ),
				'attach'       => __( 'ارسال عکس', 'persian-wp-chatbot' ),
				'record'       => __( 'ضبط ویس', 'persian-wp-chatbot' ),
				'cancel'       => __( 'لغو', 'persian-wp-chatbot' ),
				'remove'       => __( 'حذف', 'persian-wp-chatbot' ),
				'loading'      => __( 'در حال آماده‌سازی...', 'persian-wp-chatbot' ),
				'loadError'    => __( 'اتصال برقرار نشد. اینترنت خود را بررسی کنید.', 'persian-wp-chatbot' ),
				'retry'        => __( 'تلاش دوباره', 'persian-wp-chatbot' ),
				'sendFailed'   => __( 'ارسال نشد', 'persian-wp-chatbot' ),
				'suggestions'  => __( 'پیشنهاد پیام', 'persian-wp-chatbot' ),
				'nameLabel'    => __( 'نام شما', 'persian-wp-chatbot' ),
				'contactLabel' => __( 'ایمیل یا شماره تماس', 'persian-wp-chatbot' ),
				'nameRequired' => __( 'نام خود را وارد کنید.', 'persian-wp-chatbot' ),
				'contactInvalid' => __( 'ایمیل یا شماره تماس معتبر وارد کنید.', 'persian-wp-chatbot' ),
				'start'        => __( 'شروع گفتگو', 'persian-wp-chatbot' ),
				'guest'        => __( 'ادامه به عنوان مهمان', 'persian-wp-chatbot' ),
				'micDenied'    => __( 'اجازهٔ دسترسی به میکروفون داده نشد.', 'persian-wp-chatbot' ),
				'micUnsupported' => __( 'مرورگر شما از ضبط صدا پشتیبانی نمی‌کند.', 'persian-wp-chatbot' ),
				'tooBig'       => __( 'حجم فایل بیش از حد مجاز است.', 'persian-wp-chatbot' ),
				'badFile'      => __( 'فقط فایل تصویر قابل ارسال است.', 'persian-wp-chatbot' ),
				'recording'    => __( 'در حال ضبط...', 'persian-wp-chatbot' ),
				'image'        => __( 'تصویر', 'persian-wp-chatbot' ),
				'starting'     => __( 'در حال شروع...', 'persian-wp-chatbot' ),
				'loadingMsgs'  => __( 'در حال بارگذاری پیام‌ها', 'persian-wp-chatbot' ),
				'loadingOlder' => __( 'در حال بارگذاری پیام‌های قبلی', 'persian-wp-chatbot' ),
				'openLink'     => __( 'مشاهده', 'persian-wp-chatbot' ),
			),
		);
		wp_add_inline_script( 'pwc-chat-widget', 'window.PWC_CHAT = ' . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE ) . ';', 'before' );
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
			'<div id="pwc-chat-root" class="pwc-chat" dir="%1$s" data-position="%2$s" style="%3$s"></div>',
			esc_attr( is_rtl() ? 'rtl' : 'ltr' ),
			esc_attr( 'left' === $s['position'] ? 'left' : 'right' ),
			esc_attr( $style )
		);
	}
}
