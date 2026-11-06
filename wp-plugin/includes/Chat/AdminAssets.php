<?php
/**
 * فایل‌های CSS/JS تب «مدیریت چت» (فقط در صفحهٔ چت‌بات پنل مدیریت).
 * ظاهر چت همان ویجت سایت است؛ برای همین widget.css هم اینجا بارگذاری می‌شود.
 *
 * @package PersianWPChatbot
 */

namespace PersianWPChatbot\Chat;

use PersianWPChatbot\Admin\Pages\ChatbotPage;

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

		wp_enqueue_style( 'pwc-chat-widget', PWC_URL . 'assets/css/widget.css', array(), PWC_VERSION );
		wp_enqueue_script( 'pwc-chat-admin', PWC_URL . 'assets/js/chat-admin.js', array(), PWC_VERSION, true );

		$user = wp_get_current_user();
		$cfg  = array(
			'restUrl'  => esc_url_raw( rest_url( 'pwc/v1/chat-admin/' ) ),
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
				'all'          => __( 'همه', 'persian-wp-chatbot' ),
				'unread'       => __( 'خوانده نشده‌ها', 'persian-wp-chatbot' ),
				'marked'       => __( 'مارک شده‌ها', 'persian-wp-chatbot' ),
				'listTitle'    => __( 'گفتگوها', 'persian-wp-chatbot' ),
				'search'       => __( 'جستجوی نام یا تماس…', 'persian-wp-chatbot' ),
				'emptyList'    => __( 'گفتگویی در این دسته وجود ندارد.', 'persian-wp-chatbot' ),
				'pickChat'     => __( 'یک گفتگو را از فهرست انتخاب کنید.', 'persian-wp-chatbot' ),
				'noMessages'   => __( 'هنوز پیامی در این گفتگو نیست.', 'persian-wp-chatbot' ),
				'placeholder'  => __( 'پاسخ پشتیبانی را بنویسید…', 'persian-wp-chatbot' ),
				'send'         => __( 'ارسال', 'persian-wp-chatbot' ),
				'back'         => __( 'بازگشت به فهرست', 'persian-wp-chatbot' ),
				'mark'         => __( 'مارک کردن', 'persian-wp-chatbot' ),
				'unmark'       => __( 'برداشتن مارک', 'persian-wp-chatbot' ),
				'aiOn'         => __( 'پاسخ هوش مصنوعی: روشن', 'persian-wp-chatbot' ),
				'aiOff'        => __( 'پاسخ هوش مصنوعی: خاموش', 'persian-wp-chatbot' ),
				'aiMutedNote'  => __( 'هوش مصنوعی در این گفتگو پاسخ نمی‌دهد.', 'persian-wp-chatbot' ),
				'userInfo'     => __( 'اطلاعات کاربر', 'persian-wp-chatbot' ),
				'clear'        => __( 'حذف تاریخچه', 'persian-wp-chatbot' ),
				'clearConfirm' => __( 'کل تاریخچهٔ این گفتگو برای همیشه حذف شود؟ این کار قابل بازگشت نیست.', 'persian-wp-chatbot' ),
				'deleteMsg'    => __( 'حذف پیام', 'persian-wp-chatbot' ),
				'deleteConfirm' => __( 'این پیام حذف شود؟', 'persian-wp-chatbot' ),
				'supportBy'    => __( 'پاسخ از طرف پشتیبانی:', 'persian-wp-chatbot' ),
				'aiLabel'      => __( 'هوش مصنوعی', 'persian-wp-chatbot' ),
				'image'        => __( 'تصویر', 'persian-wp-chatbot' ),
				'voice'        => __( 'ویس', 'persian-wp-chatbot' ),
				'youPrefix'    => __( 'شما:', 'persian-wp-chatbot' ),
				'registered'   => __( 'کاربر ثبت‌نام‌شده', 'persian-wp-chatbot' ),
				'guest'        => __( 'مهمان (ثبت‌نام نکرده)', 'persian-wp-chatbot' ),
				'name'         => __( 'نام', 'persian-wp-chatbot' ),
				'contact'      => __( 'ایمیل / شماره تماس', 'persian-wp-chatbot' ),
				'username'     => __( 'نام کاربری', 'persian-wp-chatbot' ),
				'email'        => __( 'ایمیل حساب', 'persian-wp-chatbot' ),
				'roles'        => __( 'نقش', 'persian-wp-chatbot' ),
				'joined'       => __( 'تاریخ ثبت‌نام', 'persian-wp-chatbot' ),
				'firstSeen'    => __( 'اولین بازدید', 'persian-wp-chatbot' ),
				'lastSeen'     => __( 'آخرین فعالیت', 'persian-wp-chatbot' ),
				'openProfile'  => __( 'مشاهدهٔ پروفایل در وردپرس', 'persian-wp-chatbot' ),
				'noProfile'    => __( 'این بازدیدکننده در سایت ثبت‌نام نکرده است.', 'persian-wp-chatbot' ),
				'close'        => __( 'بستن', 'persian-wp-chatbot' ),
				'error'        => __( 'خطا در ارتباط با سرور. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'sendFailed'   => __( 'ارسال نشد', 'persian-wp-chatbot' ),
				'sending'      => __( 'در حال ارسال…', 'persian-wp-chatbot' ),
				'retry'        => __( 'ارسال نشد — تلاش دوباره', 'persian-wp-chatbot' ),
				'visitorLabel' => __( 'کاربر', 'persian-wp-chatbot' ),
				'attach'       => __( 'ارسال عکس', 'persian-wp-chatbot' ),
				'record'       => __( 'ضبط ویس', 'persian-wp-chatbot' ),
				'cancel'       => __( 'لغو', 'persian-wp-chatbot' ),
				'remove'       => __( 'حذف', 'persian-wp-chatbot' ),
				'tooBig'       => __( 'حجم فایل بیش از حد مجاز است.', 'persian-wp-chatbot' ),
				'badFile'      => __( 'فقط فایل تصویر قابل ارسال است.', 'persian-wp-chatbot' ),
				'micDenied'    => __( 'اجازهٔ دسترسی به میکروفون داده نشد.', 'persian-wp-chatbot' ),
				'micUnsupported' => __( 'مرورگر شما از ضبط صدا پشتیبانی نمی‌کند.', 'persian-wp-chatbot' ),
			),
		);
		wp_add_inline_script( 'pwc-chat-admin', 'window.PWC_CHATADMIN = ' . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}
}
