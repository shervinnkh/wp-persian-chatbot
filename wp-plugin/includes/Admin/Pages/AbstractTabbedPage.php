<?php
namespace PersianWPChatbot\Admin\Pages;

use PersianWPChatbot\Admin\Chatbot\Tabs\TabInterface;
use PersianWPChatbot\Support\Status;
use PersianWPChatbot\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * پایهٔ صفحه‌های افزونه که از پوستهٔ تیره (سربرگ + تب‌های اختیاری) استفاده می‌کنند.
 *
 * - هر صفحه فهرست تب‌هایش را در build_tabs() می‌دهد؛ با یک تب، نوار تب نمایش داده نمی‌شود و محتوا مستقیم می‌آید.
 * - با فیلتر pwc_page_tabs (آرایهٔ تب‌ها، slug صفحه) می‌توان تب اضافه/حذف کرد.
 * - وقتی چت‌بات خاموش است و صفحه «قفل‌شونده» باشد، محتوا ساخته نمی‌شود (هیچ کوئری‌ای اجرا نمی‌شود).
 */
abstract class AbstractTabbedPage implements PageInterface {

	/**
	 * @return TabInterface[]
	 */
	abstract protected function build_tabs(): array;

	/** آیا با خاموش‌بودن چت‌بات، محتوای صفحه قفل می‌شود؟ (صفحهٔ اصلی نه چون کلید وضعیت آنجاست.) */
	protected function lock_when_disabled(): bool {
		return true;
	}

	public function uses_app_shell(): bool {
		return true;
	}

	public function render(): void {
		View::render(
			'admin/page',
			array(
				'title'    => $this->page_title(),
				'tabs'     => $this->tabs(),
				'enabled'  => Status::is_enabled(),
				'lockable' => $this->lock_when_disabled(),
			)
		);
	}

	/**
	 * @return TabInterface[]
	 */
	private function tabs(): array {
		$tabs = (array) apply_filters( 'pwc_page_tabs', $this->build_tabs(), $this->slug() );

		return array_values(
			array_filter(
				$tabs,
				static function ( $tab ) {
					return $tab instanceof TabInterface;
				}
			)
		);
	}
}
