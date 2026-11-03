<?php
/**
 * تب «استخراج اطلاعات سایت».
 *
 * @var array<string, array{label:string, description:string, icon:string, default:bool}> $sources
 * @var array<string, mixed>                                                               $overview
 */

use PersianAIAssistant\Support\Icons;
use PersianAIAssistant\Support\View;

defined( 'ABSPATH' ) || exit;
?>
<div class="paia-stack">

	<!-- خلاصهٔ اطلاعات ثبت‌شده (با JS از window.PAIA_ADMIN.overview پر می‌شود) -->
	<section class="paia-card paia-overview" data-role="overview" aria-labelledby="paia-overview-title">
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-overview-title"><?php esc_html_e( 'خلاصهٔ اطلاعات ثبت‌شده', 'persian-ai-assistant' ); ?></h2>
		</div>

		<div data-role="overview-stats"></div>

		<p class="paia-overview__last" data-role="overview-last"></p>

		<div class="paia-actions">
			<button type="button" class="paia-btn paia-btn--ghost" data-role="details-btn" disabled>
				<?php echo Icons::get( 'list', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'نمایش جزئیات', 'persian-ai-assistant' ); ?></span>
			</button>
			<button type="button" class="paia-btn" data-role="new-btn" aria-expanded="false" disabled>
				<?php echo Icons::get( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'استخراج اطلاعات جدید', 'persian-ai-assistant' ); ?></span>
			</button>
		</div>
	</section>

	<!-- اطلاعات جدیدی که خودکار (هنگام انتشار) ثبت شده‌اند؛ هر مورد تیک و جزئیات دارد. تا «دیده شدن» نمایش داده می‌شود -->
	<section class="paia-card paia-autonew" data-role="autonew" aria-labelledby="paia-autonew-title" hidden>
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-autonew-title"><?php esc_html_e( 'اطلاعات جدید ثبت شده به صورت خودکار', 'persian-ai-assistant' ); ?></h2>
			<span class="paia-chip paia-chip--new" data-role="autonew-count"></span>
		</div>
		<p class="paia-note paia-note--lead"><?php esc_html_e( 'این موارد بعد از انتشار در سایت، خودکار در لیست مطالب افزونه ثبت شده‌اند. روی هر مورد بزنید تا جزئیاتش باز شود؛ مواردی را که تیک زده‌اید می‌توانید به هوش مصنوعی آموزش دهید.', 'persian-ai-assistant' ); ?></p>
		<div class="paia-notice paia-notice--error" data-role="autonew-error" role="alert" hidden></div>
		<div class="paia-notice paia-notice--info" data-role="autonew-info" role="status" hidden></div>
		<label class="paia-check paia-autonew__all">
			<input type="checkbox" class="paia-tick" data-role="autonew-all" aria-label="<?php esc_attr_e( 'انتخاب همهٔ موارد', 'persian-ai-assistant' ); ?>">
			<span><?php esc_html_e( 'انتخاب همه', 'persian-ai-assistant' ); ?></span>
		</label>
		<div class="paia-autonew__list" data-role="autonew-list"></div>
		<div class="paia-actions">
			<button type="button" class="paia-btn" data-role="autonew-seen" disabled>
				<?php echo Icons::get( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'همه را دیدم', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
			<button type="button" class="paia-btn paia-btn--ghost" data-role="autonew-train" disabled>
				<?php echo Icons::get( 'sparkles', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'آموزش به هوش مصنوعی', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
			<span class="paia-count" data-role="autonew-selected" aria-live="polite"></span>
		</div>
	</section>

	<!-- موارد حذف‌شده از سایت: برای هر کدام دکمهٔ «حذف از هوش مصنوعی» دارد؛ تا خالی نشدن لیست نمایش داده می‌شود -->
	<section class="paia-card paia-removed" data-role="removed" aria-labelledby="paia-removed-title" hidden>
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-removed-title"><?php esc_html_e( 'موارد حذف‌شده از سایت', 'persian-ai-assistant' ); ?></h2>
			<span class="paia-chip paia-chip--warn" data-role="removed-count"></span>
		</div>
		<p class="paia-note paia-note--lead"><?php esc_html_e( 'این موارد از سایت حذف شده‌اند ولی ممکن است هنوز در آموزش‌های هوش مصنوعی باشند. آیا از آموزش‌های هوش مصنوعی هم حذف شوند؟ با زدن «حذف»، مورد از این لیست خارج و درخواست حذف برای هوش مصنوعی ارسال می‌شود.', 'persian-ai-assistant' ); ?></p>
		<div class="paia-notice paia-notice--error" data-role="removed-error" role="alert" hidden></div>
		<div class="paia-removed__list" data-role="removed-list"></div>
	</section>

	<!-- فرم استخراج اطلاعات جدید: تا زدن دکمهٔ بالا مخفی است -->
	<?php
	View::render(
		'admin/chatbot/tabs/extraction-block',
		array(
			'mode'          => 'new',
			'title'         => __( 'استخراج اطلاعات جدید', 'persian-ai-assistant' ),
			'description'   => __( 'فقط مواردی که در استخراج‌های قبلی ثبت نشده‌اند پیدا، ذخیره و نمایش داده می‌شوند.', 'persian-ai-assistant' ),
			'summary_title' => __( 'خلاصهٔ اطلاعات جدید', 'persian-ai-assistant' ),
			'tree_title'    => __( 'اطلاعات جدید', 'persian-ai-assistant' ),
			'tree_note'     => __( 'این موارد جدید هستند و در جستجوهای قبلی نبودند.', 'persian-ai-assistant' ),
			'hidden'        => true,
			'sources'       => $sources,
		)
	);

	View::render(
		'admin/chatbot/tabs/extraction-block',
		array(
			'mode'          => 'initial',
			'title'         => __( 'استخراج اولیه اطلاعات سایت', 'persian-ai-assistant' ),
			'summary_title' => __( 'خلاصهٔ اطلاعات استخراج‌شده', 'persian-ai-assistant' ),
			'tree_title'    => __( 'اطلاعات استخراج‌شده', 'persian-ai-assistant' ),
			// استخراج اولیه فقط وقتی نمایش داده می‌شود که هنوز هیچ اطلاعاتی در جدول ثبت نشده باشد.
			'hidden'        => ! empty( $overview['total'] ),
			'sources'       => $sources,
		)
	);
	?>

	<!-- دیالوگ «نمایش جزئیات»: همان فرم اطلاعات استخراج‌شده با عنوان دیگر -->
	<dialog class="paia-dialog" data-role="dialog" aria-labelledby="paia-dialog-title">
		<div class="paia-dialog__head">
			<h2 class="paia-dialog__title" id="paia-dialog-title"><?php esc_html_e( 'جزئیات اطلاعات ثبت‌شده', 'persian-ai-assistant' ); ?></h2>
			<button type="button" class="paia-dialog__close" data-role="dialog-close" aria-label="<?php esc_attr_e( 'بستن', 'persian-ai-assistant' ); ?>">
				<?php echo Icons::get( 'x', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>
		<div class="paia-dialog__body" data-role="dialog-body">
			<div class="paia-progress" data-role="progress" hidden>
				<div class="paia-progress__bar"><span data-role="progress-fill"></span></div>
				<p class="paia-progress__text" data-role="progress-text" aria-live="polite"></p>
			</div>
			<div class="paia-notice" data-role="notice" role="status" hidden></div>
			<?php
			View::render(
				'admin/chatbot/tabs/extraction-results',
				array(
					'summary_title' => __( 'خلاصهٔ اطلاعات ثبت‌شده', 'persian-ai-assistant' ),
					'tree_title'    => __( 'اطلاعات ثبت‌شده', 'persian-ai-assistant' ),
					'hidden'        => true,
				)
			);
			?>
		</div>
	</dialog>

</div>
