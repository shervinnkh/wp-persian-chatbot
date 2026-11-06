<?php
namespace PersianWPChatbot\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * هر بخش اصلی افزونه (ادمین، REST، چت‌بات سمت سایت، ...) یک ماژول است.
 * ماژول‌ها فقط در متد register هوک‌های خود را ثبت می‌کنند.
 */
interface Module {

	public function register(): void;
}
