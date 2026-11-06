<?php
namespace PersianWPChatbot\Admin\Pages;

defined( 'ABSPATH' ) || exit;

interface PageInterface {

	public function slug(): string;

	public function page_title(): string;

	public function menu_title(): string;

	/**
	 * آیا صفحه از پوستهٔ تیره‌ی افزونه (سربرگ + تب‌ها) استفاده می‌کند؟
	 * منوی اصلی وردپرس در همهٔ حالت‌ها نمایش داده می‌شود.
	 */
	public function uses_app_shell(): bool;

	public function render(): void;
}
