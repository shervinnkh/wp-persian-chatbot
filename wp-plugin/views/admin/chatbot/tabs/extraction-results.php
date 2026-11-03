<?php
/**
 * بلوک نتیجهٔ استخراج (خلاصه + درخت تیک‌دار + دکمهٔ آموزش).
 * هم برای استخراج اولیه، هم استخراج اطلاعات جدید و هم دیالوگ «نمایش جزئیات» استفاده می‌شود.
 *
 * @var string $summary_title
 * @var string $tree_title
 * @var string $tree_note  توضیح زیر عنوان درخت (اختیاری)
 * @var bool   $hidden
 */

use PersianAIAssistant\Support\Icons;

defined( 'ABSPATH' ) || exit;

$tree_note = isset( $tree_note ) ? (string) $tree_note : '';
$hidden    = ! isset( $hidden ) || $hidden;
?>
<div class="paia-results" data-role="results" <?php echo $hidden ? 'hidden' : ''; ?>>

	<section class="paia-card">
		<div class="paia-card__head">
			<h2 class="paia-card__title"><?php echo esc_html( $summary_title ); ?></h2>
		</div>
		<div data-role="summary"></div>
	</section>

	<section class="paia-card">
		<div class="paia-card__head">
			<h2 class="paia-card__title"><?php echo esc_html( $tree_title ); ?></h2>
		</div>
		<?php if ( '' !== $tree_note ) : ?>
			<p class="paia-note paia-note--lead"><?php echo esc_html( $tree_note ); ?></p>
		<?php endif; ?>

		<div class="paia-tree" data-role="tree"></div>

		<div class="paia-actions">
			<button type="button" class="paia-btn" data-role="train" disabled aria-disabled="true">
				<?php echo Icons::get( 'logo', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'آموزش هوش مصنوعی', 'persian-ai-assistant' ); ?></span>
			</button>
			<span class="paia-count" data-role="tree-count" aria-live="polite"></span>
		</div>
	</section>

</div>
