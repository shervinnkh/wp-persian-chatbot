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

use PersianWPChatbot\Support\Icons;
use PersianWPChatbot\Support\View;

defined( 'ABSPATH' ) || exit;

$description = isset( $description ) ? (string) $description : '';
?>
<div class="pwc-extractor" data-role="extractor" data-mode="<?php echo esc_attr( $mode ); ?>" <?php echo ! empty( $hidden ) ? 'hidden' : ''; ?>>

	<form class="pwc-card" data-role="form" novalidate>

		<div class="pwc-card__head">
			<h2 class="pwc-card__title">
				<?php echo esc_html( $title ); ?>
				<?php if ( 'new' === $mode ) : ?>
					<span class="pwc-chip pwc-chip--new"><?php esc_html_e( 'جدید', 'persian-wp-chatbot' ); ?></span>
				<?php endif; ?>
			</h2>
			<div class="pwc-card__tools">
				<button type="button" class="pwc-link" data-action="select-all"><?php esc_html_e( 'انتخاب همه', 'persian-wp-chatbot' ); ?></button>
				<button type="button" class="pwc-link" data-action="select-none"><?php esc_html_e( 'لغو انتخاب همه', 'persian-wp-chatbot' ); ?></button>
			</div>
		</div>

		<?php if ( '' !== $description ) : ?>
			<p class="pwc-note pwc-note--lead"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>

		<div class="pwc-options">
			<?php foreach ( $sources as $source_id => $source ) : ?>
				<label class="pwc-option">
					<input
						type="checkbox"
						name="sources[]"
						value="<?php echo esc_attr( $source_id ); ?>"
						<?php checked( ! empty( $source['default'] ) ); ?>
					>
					<span class="pwc-option__card">
						<span class="pwc-option__icon"><?php echo Icons::get( $source['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="pwc-option__text">
							<strong><?php echo esc_html( $source['label'] ); ?></strong>
							<small><?php echo esc_html( $source['description'] ); ?></small>
						</span>
						<span class="pwc-option__check"><?php echo Icons::get( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="pwc-actions">
			<button type="submit" class="pwc-btn" data-role="extract-btn">
				<?php echo Icons::get( 'database', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php echo 'new' === $mode ? esc_html__( 'استخراج اطلاعات جدید', 'persian-wp-chatbot' ) : esc_html__( 'استخراج اطلاعات', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
			<span class="pwc-count" data-role="selected-count" aria-live="polite"></span>
		</div>

		<div class="pwc-progress" data-role="progress" hidden>
			<div class="pwc-progress__bar"><span data-role="progress-fill"></span></div>
			<p class="pwc-progress__text" data-role="progress-text" aria-live="polite"></p>
		</div>

		<div class="pwc-notice" data-role="notice" role="status" hidden></div>

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
