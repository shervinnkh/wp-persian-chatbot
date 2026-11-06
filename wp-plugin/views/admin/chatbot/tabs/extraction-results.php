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

use PersianWPChatbot\Support\Icons;

defined( 'ABSPATH' ) || exit;

$tree_note = isset( $tree_note ) ? (string) $tree_note : '';
$hidden    = ! isset( $hidden ) || $hidden;
?>
<div class="pwc-results" data-role="results" <?php echo $hidden ? 'hidden' : ''; ?>>

	<section class="pwc-card">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title"><?php echo esc_html( $summary_title ); ?></h2>
		</div>
		<div data-role="summary"></div>
	</section>

	<section class="pwc-card">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title"><?php echo esc_html( $tree_title ); ?></h2>
		</div>
		<?php if ( '' !== $tree_note ) : ?>
			<p class="pwc-note pwc-note--lead"><?php echo esc_html( $tree_note ); ?></p>
		<?php endif; ?>

		<div class="pwc-tree" data-role="tree"></div>

		<div class="pwc-actions">
			<button type="button" class="pwc-btn" data-role="train" disabled aria-disabled="true">
				<?php echo Icons::get( 'logo', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'آموزش هوش مصنوعی', 'persian-wp-chatbot' ); ?></span>
			</button>
			<span class="pwc-count" data-role="tree-count" aria-live="polite"></span>
		</div>
	</section>

</div>
