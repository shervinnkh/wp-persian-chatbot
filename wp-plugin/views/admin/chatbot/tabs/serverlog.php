<?php
/**
 * تب «لاگ سرور».
 */

use PersianWPChatbot\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="pwc-stack" data-role="slog">

	<!-- آمار کلی (همان طراحی کارت «خلاصهٔ اطلاعات ثبت‌شده») -->
	<section class="pwc-card pwc-overview" aria-labelledby="pwc-slog-title">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-slog-title"><?php esc_html_e( 'آمار کلی لاگ سرور', 'persian-wp-chatbot' ); ?></h2>
		</div>

		<div class="pwc-summary" data-role="slog-stats"></div>
		<p class="pwc-note pwc-slog__updated" data-role="slog-updated"></p>

		<div class="pwc-actions">
			<button type="button" class="pwc-btn pwc-btn--ghost" data-role="slog-refresh">
				<?php echo Icons::get( 'refresh', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'به‌روزرسانی', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
		</div>
	</section>

	<!-- فهرست لاگ‌ها -->
	<section class="pwc-card pwc-slog" aria-labelledby="pwc-slog-list-title">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-slog-list-title"><?php esc_html_e( 'لاگ‌ها', 'persian-wp-chatbot' ); ?></h2>
		</div>

		<div class="pwc-notice pwc-notice--error" data-role="slog-error" role="alert" hidden></div>
		<div class="pwc-slog__list" data-role="slog-list" aria-live="polite"></div>

		<div class="pwc-actions">
			<button type="button" class="pwc-btn pwc-btn--ghost" data-role="slog-more" hidden>
				<span class="pwc-btn__label"><?php esc_html_e( 'نمایش لاگ‌های بیشتر', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
		</div>
	</section>

</div>
