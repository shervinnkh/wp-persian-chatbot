<?php
/**
 * صفحهٔ پنل مانیتورینگ (بدون تب). همهٔ بخش‌ها را assets/js/monitoring.js با دادهٔ سرور می‌سازد.
 *
 * @var string $title
 */

use PersianWPChatbot\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="pwc-app pwc-mon" id="pwc-monitoring">

	<div class="pwc-topbar">
		<header class="pwc-header">
			<div class="pwc-brand">
				<span class="pwc-logo"><?php echo Icons::get( 'logo', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h1 class="pwc-title"><?php echo esc_html( $title ); ?></h1>
			</div>
		</header>

		<div class="pwc-mon__bar">
			<div class="pwc-mon__seg" role="group" aria-label="<?php esc_attr_e( 'بازهٔ گزارش', 'persian-wp-chatbot' ); ?>" data-role="mon-period">
				<button type="button" class="pwc-mon__segbtn" data-period="day"><?php esc_html_e( 'روزانه', 'persian-wp-chatbot' ); ?></button>
				<button type="button" class="pwc-mon__segbtn" data-period="month"><?php esc_html_e( 'ماهانه', 'persian-wp-chatbot' ); ?></button>
				<button type="button" class="pwc-mon__segbtn" data-period="year"><?php esc_html_e( 'سالانه', 'persian-wp-chatbot' ); ?></button>
			</div>

			<div class="pwc-mon__nav" role="group" aria-label="<?php esc_attr_e( 'جابه‌جایی بین بازه‌ها', 'persian-wp-chatbot' ); ?>">
				<button type="button" class="pwc-mon__navbtn" data-role="mon-prev" aria-label="<?php esc_attr_e( 'بازهٔ قبل', 'persian-wp-chatbot' ); ?>">&rsaquo;</button>
				<strong class="pwc-mon__label" data-role="mon-label">…</strong>
				<button type="button" class="pwc-mon__navbtn" data-role="mon-next" aria-label="<?php esc_attr_e( 'بازهٔ بعد', 'persian-wp-chatbot' ); ?>">&lsaquo;</button>
				<button type="button" class="pwc-btn pwc-btn--ghost pwc-mon__today" data-role="mon-today"><?php esc_html_e( 'اکنون', 'persian-wp-chatbot' ); ?></button>
			</div>

			<div class="pwc-mon__seg" role="group" aria-label="<?php esc_attr_e( 'تقویم', 'persian-wp-chatbot' ); ?>" data-role="mon-calendar">
				<button type="button" class="pwc-mon__segbtn" data-calendar="jalali"><?php esc_html_e( 'شمسی', 'persian-wp-chatbot' ); ?></button>
				<button type="button" class="pwc-mon__segbtn" data-calendar="gregorian"><?php esc_html_e( 'میلادی', 'persian-wp-chatbot' ); ?></button>
			</div>

			<button type="button" class="pwc-btn pwc-btn--ghost pwc-mon__refresh" data-role="mon-refresh">
				<?php echo Icons::get( 'refresh', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'به‌روزرسانی', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
		</div>

		<nav class="pwc-mon__jump" data-role="mon-jump" aria-label="<?php esc_attr_e( 'فهرست بخش‌ها', 'persian-wp-chatbot' ); ?>" hidden></nav>
	</div>

	<main class="pwc-content pwc-content--wide">
		<div class="pwc-notice pwc-notice--error" data-role="mon-error" role="alert" hidden></div>

		<!-- لودینگ: هر بار که صفحه باز یا بازه عوض شود نمایش داده می‌شود -->
		<div class="pwc-mon__loading" data-role="mon-loading" role="status" aria-live="polite">
			<span class="pwc-mon__bigspin" aria-hidden="true"></span>
			<p><?php esc_html_e( 'در حال دریافت اطلاعات مانیتورینگ از سرور…', 'persian-wp-chatbot' ); ?></p>
		</div>

		<div class="pwc-stack pwc-mon__body" data-role="mon-body" hidden></div>
	</main>

</div>
