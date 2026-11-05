<?php
/**
 * ماژول ویجت چت سمت سایت (در Plugin::module_classes() ثبت شده).
 * تنظیمات در تب «تنظیمات» صفحهٔ چت‌بات نمایش داده می‌شود (views/admin/chatbot/tabs/settings.php).
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

use PersianAIAssistant\Contracts\Module;
use PersianAIAssistant\Support\Status;
use PersianAIAssistant\Sync\SyncSettings;

defined( 'ABSPATH' ) || exit;

final class ChatModule implements Module {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'option_page_capability_paia_chat', array( $this, 'capability' ) );

		// هندلر پاک‌سازی روزانه (P-2) همیشه بسته می‌شود تا رویداد زمان‌بندی‌شده‌ای که مانده بی‌صدا از بین نرود؛
		// خودِ زمان‌بندی فقط وقتی چت‌بات روشن است (پایین) یا در فعال‌سازی انجام می‌شود.
		( new Cleanup() )->register();

		// چت‌بات خاموش: هیچ جدول/مسیر/کرون/ویجتی ثبت نمی‌شود و هیچ رکوردی ساخته نمی‌شود.
		if ( ! Status::is_enabled() ) {
			return;
		}

		add_action( 'init', array( ChatSchema::class, 'maybe_install' ), 5 );
		add_action( 'init', array( Cleanup::class, 'schedule' ), 20 );

		( new WidgetRenderer() )->register();
		( new ChatController() )->register();
		( new Responder() )->register();
		( new AdminChatController() )->register();
		( new AdminAssets() )->register();
	}

	public function capability(): string {
		return (string) apply_filters( 'paia_required_capability', 'manage_options' );
	}

	public function register_setting(): void {
		register_setting(
			'paia_chat',
			ChatSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => ChatSettings::defaults(),
			)
		);
	}

	/**
	 * فرم تب «تنظیمات» یک فرم واحد با یک دکمهٔ «ذخیرهٔ تغییرات» است (options.php؛ nonce و دسترسی را خود وردپرس
	 * پیش از رسیدن به این‌جا بررسی کرده). علاوه بر تنظیمات چت، تیک‌های «استخراج خودکار اطلاعات سایت» هم
	 * (که در option دیگری ذخیره می‌شوند) با همین دکمه ذخیره می‌شوند.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( isset( $_POST[ SyncSettings::FORM_MARKER ] ) && current_user_can( $this->capability() ) ) {
			SyncSettings::save_from_form( isset( $_POST[ SyncSettings::FORM_FIELD ] ) ? wp_unslash( $_POST[ SyncSettings::FORM_FIELD ] ) : array() );
		}
		// phpcs:enable

		return ChatSettings::sanitize( $input );
	}
}
