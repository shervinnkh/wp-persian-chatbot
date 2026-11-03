<?php
/**
 * تب «لاگ سرور».
 */

use PersianAIAssistant\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="paia-stack" data-role="slog">

	<!-- آمار کلی (همان طراحی کارت «خلاصهٔ اطلاعات ثبت‌شده») -->
	<section class="paia-card paia-overview" aria-labelledby="paia-slog-title">
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-slog-title"><?php esc_html_e( 'آمار کلی لاگ سرور', 'persian-ai-assistant' ); ?></h2>
		</div>

		<div class="paia-summary" data-role="slog-stats"></div>
		<p class="paia-note paia-slog__updated" data-role="slog-updated"></p>

		<div class="paia-actions">
			<button type="button" class="paia-btn paia-btn--ghost" data-role="slog-refresh">
				<?php echo Icons::get( 'refresh', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'به‌روزرسانی', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
		</div>
	</section>

	<!-- فهرست لاگ‌ها -->
	<section class="paia-card paia-slog" aria-labelledby="paia-slog-list-title">
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-slog-list-title"><?php esc_html_e( 'لاگ‌ها', 'persian-ai-assistant' ); ?></h2>
		</div>

		<div class="paia-notice paia-notice--error" data-role="slog-error" role="alert" hidden></div>
		<div class="paia-slog__list" data-role="slog-list" aria-live="polite"></div>

		<div class="paia-actions">
			<button type="button" class="paia-btn paia-btn--ghost" data-role="slog-more" hidden>
				<span class="paia-btn__label"><?php esc_html_e( 'نمایش لاگ‌های بیشتر', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
		</div>
	</section>

</div>
