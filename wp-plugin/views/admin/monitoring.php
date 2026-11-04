<?php
/**
 * صفحهٔ پنل مانیتورینگ (بدون تب). همهٔ بخش‌ها را assets/js/monitoring.js با دادهٔ سرور می‌سازد.
 *
 * @var string $title
 */

use PersianAIAssistant\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="paia-app paia-mon" id="paia-monitoring">

	<div class="paia-topbar">
		<header class="paia-header">
			<div class="paia-brand">
				<span class="paia-logo"><?php echo Icons::get( 'logo', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h1 class="paia-title"><?php echo esc_html( $title ); ?></h1>
			</div>
		</header>

		<div class="paia-mon__bar">
			<div class="paia-mon__seg" role="group" aria-label="<?php esc_attr_e( 'بازهٔ گزارش', 'persian-ai-assistant' ); ?>" data-role="mon-period">
				<button type="button" class="paia-mon__segbtn" data-period="day"><?php esc_html_e( 'روزانه', 'persian-ai-assistant' ); ?></button>
				<button type="button" class="paia-mon__segbtn" data-period="month"><?php esc_html_e( 'ماهانه', 'persian-ai-assistant' ); ?></button>
				<button type="button" class="paia-mon__segbtn" data-period="year"><?php esc_html_e( 'سالانه', 'persian-ai-assistant' ); ?></button>
			</div>

			<div class="paia-mon__nav" role="group" aria-label="<?php esc_attr_e( 'جابه‌جایی بین بازه‌ها', 'persian-ai-assistant' ); ?>">
				<button type="button" class="paia-mon__navbtn" data-role="mon-prev" aria-label="<?php esc_attr_e( 'بازهٔ قبل', 'persian-ai-assistant' ); ?>">&rsaquo;</button>
				<strong class="paia-mon__label" data-role="mon-label">…</strong>
				<button type="button" class="paia-mon__navbtn" data-role="mon-next" aria-label="<?php esc_attr_e( 'بازهٔ بعد', 'persian-ai-assistant' ); ?>">&lsaquo;</button>
				<button type="button" class="paia-btn paia-btn--ghost paia-mon__today" data-role="mon-today"><?php esc_html_e( 'اکنون', 'persian-ai-assistant' ); ?></button>
			</div>

			<div class="paia-mon__seg" role="group" aria-label="<?php esc_attr_e( 'تقویم', 'persian-ai-assistant' ); ?>" data-role="mon-calendar">
				<button type="button" class="paia-mon__segbtn" data-calendar="jalali"><?php esc_html_e( 'شمسی', 'persian-ai-assistant' ); ?></button>
				<button type="button" class="paia-mon__segbtn" data-calendar="gregorian"><?php esc_html_e( 'میلادی', 'persian-ai-assistant' ); ?></button>
			</div>

			<button type="button" class="paia-btn paia-btn--ghost paia-mon__refresh" data-role="mon-refresh">
				<?php echo Icons::get( 'refresh', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'به‌روزرسانی', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
		</div>

		<nav class="paia-mon__jump" data-role="mon-jump" aria-label="<?php esc_attr_e( 'فهرست بخش‌ها', 'persian-ai-assistant' ); ?>" hidden></nav>
	</div>

	<main class="paia-content paia-content--wide">
		<div class="paia-notice paia-notice--error" data-role="mon-error" role="alert" hidden></div>

		<!-- لودینگ: هر بار که صفحه باز یا بازه عوض شود نمایش داده می‌شود -->
		<div class="paia-mon__loading" data-role="mon-loading" role="status" aria-live="polite">
			<span class="paia-mon__bigspin" aria-hidden="true"></span>
			<p><?php esc_html_e( 'در حال دریافت اطلاعات مانیتورینگ از سرور…', 'persian-ai-assistant' ); ?></p>
		</div>

		<div class="paia-stack paia-mon__body" data-role="mon-body" hidden></div>
	</main>

</div>
