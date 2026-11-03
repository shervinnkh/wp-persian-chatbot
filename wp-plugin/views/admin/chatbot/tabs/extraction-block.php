<?php
/**
 * یک بلوک کامل استخراج: فرم انتخاب منبع + نتایج.
 *
 * @var string $mode          initial | new
 * @var string $title         عنوان فرم
 * @var string $description   توضیح زیر عنوان (اختیاری)
 * @var string $summary_title
 * @var string $tree_title
 * @var string $tree_note
 * @var bool   $hidden        مخفی تا زمانی که کاربر دکمه را بزند
 * @var array<string, array{label:string, description:string, icon:string, default:bool}> $sources
 */

use PersianAIAssistant\Support\Icons;
use PersianAIAssistant\Support\View;

defined( 'ABSPATH' ) || exit;

$description = isset( $description ) ? (string) $description : '';
?>
<div class="paia-extractor" data-role="extractor" data-mode="<?php echo esc_attr( $mode ); ?>" <?php echo ! empty( $hidden ) ? 'hidden' : ''; ?>>

	<form class="paia-card" data-role="form" novalidate>

		<div class="paia-card__head">
			<h2 class="paia-card__title">
				<?php echo esc_html( $title ); ?>
				<?php if ( 'new' === $mode ) : ?>
					<span class="paia-chip paia-chip--new"><?php esc_html_e( 'جدید', 'persian-ai-assistant' ); ?></span>
				<?php endif; ?>
			</h2>
			<div class="paia-card__tools">
				<button type="button" class="paia-link" data-action="select-all"><?php esc_html_e( 'انتخاب همه', 'persian-ai-assistant' ); ?></button>
				<button type="button" class="paia-link" data-action="select-none"><?php esc_html_e( 'لغو انتخاب همه', 'persian-ai-assistant' ); ?></button>
			</div>
		</div>

		<?php if ( '' !== $description ) : ?>
			<p class="paia-note paia-note--lead"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>

		<div class="paia-options">
			<?php foreach ( $sources as $source_id => $source ) : ?>
				<label class="paia-option">
					<input
						type="checkbox"
						name="sources[]"
						value="<?php echo esc_attr( $source_id ); ?>"
						<?php checked( ! empty( $source['default'] ) ); ?>
					>
					<span class="paia-option__card">
						<span class="paia-option__icon"><?php echo Icons::get( $source['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="paia-option__text">
							<strong><?php echo esc_html( $source['label'] ); ?></strong>
							<small><?php echo esc_html( $source['description'] ); ?></small>
						</span>
						<span class="paia-option__check"><?php echo Icons::get( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="paia-actions">
			<button type="submit" class="paia-btn" data-role="extract-btn">
				<?php echo Icons::get( 'database', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php echo 'new' === $mode ? esc_html__( 'استخراج اطلاعات جدید', 'persian-ai-assistant' ) : esc_html__( 'استخراج اطلاعات', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
			<span class="paia-count" data-role="selected-count" aria-live="polite"></span>
		</div>

		<div class="paia-progress" data-role="progress" hidden>
			<div class="paia-progress__bar"><span data-role="progress-fill"></span></div>
			<p class="paia-progress__text" data-role="progress-text" aria-live="polite"></p>
		</div>

		<div class="paia-notice" data-role="notice" role="status" hidden></div>

	</form>

	<?php
	View::render(
		'admin/chatbot/tabs/extraction-results',
		array(
			'summary_title' => $summary_title,
			'tree_title'    => $tree_title,
			'tree_note'     => isset( $tree_note ) ? $tree_note : '',
			'hidden'        => true,
		)
	);
	?>

</div>
