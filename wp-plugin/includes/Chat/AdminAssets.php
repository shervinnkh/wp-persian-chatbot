<?php
/**
 * فایل‌های CSS/JS تب «مدیریت چت» (فقط در صفحهٔ چت‌بات پنل مدیریت).
 * ظاهر چت همان ویجت سایت است؛ برای همین widget.css هم اینجا بارگذاری می‌شود.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Admin\Pages\ChatbotPage;

defined( 'ABSPATH' ) || exit;

final class AdminAssets {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * @return array<string,string> متغیرهای CSS رنگ‌ها برای ظرف چت (همان رنگ‌های ویجت سایت).
	 */
	public static function css_vars(): array {
		$s = ChatSettings::all();
		return array(
			'--pc-primary' => $s['color_primary'],
			'--pc-accent'  => $s['color_accent'],
			'--pc-surface' => $s['color_surface'],
			'--pc-text'    => $s['color_text'],
			'--pc-field'   => $s['color_field'],
		);
	}

	public static function css_vars_inline(): string {
		$out = '';
		foreach ( self::css_vars() as $k => $v ) {
			$out .= $k . ':' . $v . ';';
		}
		return $out;
	}

	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ChatbotPage::SLUG !== $page ) {
			return;
		}

		wp_enqueue_style( 'paia-chat-widget', PAIA_URL . 'assets/css/widget.css', array(), PAIA_VERSION );
		wp_enqueue_script( 'paia-chat-admin', PAIA_URL . 'assets/js/chat-admin.js', array(), PAIA_VERSION, true );

		$user = wp_get_current_user();
		$cfg  = array(
			'restUrl'  => esc_url_raw( rest_url( 'paia/v1/chat-admin/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'locale'   => str_replace( '_', '-', get_locale() ),
			'botName'  => (string) ChatSettings::get( 'bot_name' ),
			'me'       => array(
				'id'   => (int) $user->ID,
				'name' => (string) $user->display_name,
			),
			'poll'     => ChatSettings::admin_poll(), // fast: دقیقاً در تب مدیریت چت، slow: در غیر این صورت
			'maxMb'    => (int) ChatSettings::get( 'max_upload_mb', 5 ),
			'mic'      => true,
			'i18n'     => array(
				'all'          => __( 'همه', 'persian-ai-assistant' ),
				'unread'       => __( 'خوانده نشده‌ها', 'persian-ai-assistant' ),
				'marked'       => __( 'مارک شده‌ها', 'persian-ai-assistant' ),
				'listTitle'    => __( 'گفتگوها', 'persian-ai-assistant' ),
				'search'       => __( 'جستجوی نام یا تماس…', 'persian-ai-assistant' ),
				'emptyList'    => __( 'گفتگویی در این دسته وجود ندارد.', 'persian-ai-assistant' ),
				'pickChat'     => __( 'یک گفتگو را از فهرست انتخاب کنید.', 'persian-ai-assistant' ),
				'noMessages'   => __( 'هنوز پیامی در این گفتگو نیست.', 'persian-ai-assistant' ),
				'placeholder'  => __( 'پاسخ پشتیبانی را بنویسید…', 'persian-ai-assistant' ),
				'send'         => __( 'ارسال', 'persian-ai-assistant' ),
				'back'         => __( 'بازگشت به فهرست', 'persian-ai-assistant' ),
				'mark'         => __( 'مارک کردن', 'persian-ai-assistant' ),
				'unmark'       => __( 'برداشتن مارک', 'persian-ai-assistant' ),
				'aiOn'         => __( 'پاسخ هوش مصنوعی: روشن', 'persian-ai-assistant' ),
				'aiOff'        => __( 'پاسخ هوش مصنوعی: خاموش', 'persian-ai-assistant' ),
				'aiMutedNote'  => __( 'هوش مصنوعی در این گفتگو پاسخ نمی‌دهد.', 'persian-ai-assistant' ),
				'userInfo'     => __( 'اطلاعات کاربر', 'persian-ai-assistant' ),
				'clear'        => __( 'حذف تاریخچه', 'persian-ai-assistant' ),
				'clearConfirm' => __( 'کل تاریخچهٔ این گفتگو برای همیشه حذف شود؟ این کار قابل بازگشت نیست.', 'persian-ai-assistant' ),
				'deleteMsg'    => __( 'حذف پیام', 'persian-ai-assistant' ),
				'deleteConfirm' => __( 'این پیام حذف شود؟', 'persian-ai-assistant' ),
				'supportBy'    => __( 'پاسخ از طرف پشتیبانی:', 'persian-ai-assistant' ),
				'aiLabel'      => __( 'هوش مصنوعی', 'persian-ai-assistant' ),
				'image'        => __( 'تصویر', 'persian-ai-assistant' ),
				'voice'        => __( 'ویس', 'persian-ai-assistant' ),
				'youPrefix'    => __( 'شما:', 'persian-ai-assistant' ),
				'registered'   => __( 'کاربر ثبت‌نام‌شده', 'persian-ai-assistant' ),
				'guest'        => __( 'مهمان (ثبت‌نام نکرده)', 'persian-ai-assistant' ),
				'name'         => __( 'نام', 'persian-ai-assistant' ),
				'contact'      => __( 'ایمیل / شماره تماس', 'persian-ai-assistant' ),
				'username'     => __( 'نام کاربری', 'persian-ai-assistant' ),
				'email'        => __( 'ایمیل حساب', 'persian-ai-assistant' ),
				'roles'        => __( 'نقش', 'persian-ai-assistant' ),
				'joined'       => __( 'تاریخ ثبت‌نام', 'persian-ai-assistant' ),
				'firstSeen'    => __( 'اولین بازدید', 'persian-ai-assistant' ),
				'lastSeen'     => __( 'آخرین فعالیت', 'persian-ai-assistant' ),
				'openProfile'  => __( 'مشاهدهٔ پروفایل در وردپرس', 'persian-ai-assistant' ),
				'noProfile'    => __( 'این بازدیدکننده در سایت ثبت‌نام نکرده است.', 'persian-ai-assistant' ),
				'close'        => __( 'بستن', 'persian-ai-assistant' ),
				'error'        => __( 'خطا در ارتباط با سرور. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'sendFailed'   => __( 'ارسال نشد', 'persian-ai-assistant' ),
				'sending'      => __( 'در حال ارسال…', 'persian-ai-assistant' ),
				'retry'        => __( 'ارسال نشد — تلاش دوباره', 'persian-ai-assistant' ),
				'visitorLabel' => __( 'کاربر', 'persian-ai-assistant' ),
				'attach'       => __( 'ارسال عکس', 'persian-ai-assistant' ),
				'record'       => __( 'ضبط ویس', 'persian-ai-assistant' ),
				'cancel'       => __( 'لغو', 'persian-ai-assistant' ),
				'remove'       => __( 'حذف', 'persian-ai-assistant' ),
				'tooBig'       => __( 'حجم فایل بیش از حد مجاز است.', 'persian-ai-assistant' ),
				'badFile'      => __( 'فقط فایل تصویر قابل ارسال است.', 'persian-ai-assistant' ),
				'micDenied'    => __( 'اجازهٔ دسترسی به میکروفون داده نشد.', 'persian-ai-assistant' ),
				'micUnsupported' => __( 'مرورگر شما از ضبط صدا پشتیبانی نمی‌کند.', 'persian-ai-assistant' ),
			),
		);
		wp_add_inline_script( 'paia-chat-admin', 'window.PAIA_CHATADMIN = ' . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}
}
