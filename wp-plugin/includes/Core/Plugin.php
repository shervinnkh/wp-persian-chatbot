<?php
namespace PersianWPChatbot\Core;

use PersianWPChatbot\Admin\AdminModule;
use PersianWPChatbot\Apis\ApisModule;
use PersianWPChatbot\Chat\ChatModule;
use PersianWPChatbot\Contracts\Module;
use PersianWPChatbot\Rest\RestModule;
use PersianWPChatbot\Support\Status;
use PersianWPChatbot\Sync\AutoSync;
use PersianWPChatbot\Updates\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * هستهٔ افزونه؛ ماژول‌ها را بارگذاری و ثبت می‌کند.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	/** @var Module[] */
	private $modules = array();

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		( new Upgrader() )->maybe_upgrade();

		foreach ( $this->module_classes() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$module = new $class();
			if ( $module instanceof Module ) {
				$module->register();
				$this->modules[ $class ] = $module;
			}
		}

		/**
		 * پس از بارگذاری کامل افزونه.
		 *
		 * @param Plugin $plugin نمونهٔ هستهٔ افزونه.
		 */
		do_action( 'pwc_loaded', $this );
	}

	/**
	 * فهرست ماژول‌ها. ماژول جدید فقط کافی است اینجا (یا از طریق فیلتر) اضافه شود.
	 *
	 * @return string[]
	 */
	private function module_classes(): array {
		$modules = array(
			I18n::class,
			AdminModule::class,
			RestModule::class,
			ChatModule::class,
			Updater::class, // به‌روزرسانی از سایت خودمان؛ مستقل از وضعیت چت‌بات
		);

		// ذخیرهٔ خودکار هنگام انتشار فقط وقتی چت‌بات روشن است (در حالت خاموش هیچ رکوردی ساخته نمی‌شود).
		if ( Status::is_enabled() ) {
			$modules[] = AutoSync::class;
			$modules[] = ApisModule::class; // API‌های ورودی از سرور هوش مصنوعی (apis/)
		}

		return (array) apply_filters( 'pwc_modules', $modules );
	}

	public function module( string $class ): ?Module {
		return $this->modules[ $class ] ?? null;
	}
}
