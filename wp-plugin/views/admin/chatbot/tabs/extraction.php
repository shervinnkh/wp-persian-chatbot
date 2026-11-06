<?php
/**
 * تب «استخراج اطلاعات سایت».
 *
 * @var array<string, array{label:string, description:string, icon:string, default:bool}> $sources
 * @var array<string, mixed>                                                               $overview
 */

use PersianWPChatbot\Support\Icons;
use PersianWPChatbot\Support\View;

defined( 'ABSPATH' ) || exit;
?>
<div class="pwc-stack">

	<!-- خلاصهٔ اطلاعات ثبت‌شده (با JS از window.PWC_ADMIN.overview پر می‌شود) -->
	<section class="pwc-card pwc-overview" data-role="overview" aria-labelledby="pwc-overview-title">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-overview-title"><?php esc_html_e( 'خلاصهٔ اطلاعات ثبت‌شده', 'persian-wp-chatbot' ); ?></h2>
		</div>

		<div data-role="overview-stats"></div>

		<p class="pwc-overview__last" data-role="overview-last"></p>

		<div class="pwc-actions">
			<button type="button" class="pwc-btn pwc-btn--ghost" data-role="details-btn" disabled>
				<?php echo Icons::get( 'list', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'نمایش جزئیات', 'persian-wp-chatbot' ); ?></span>
			</button>
			<button type="button" class="pwc-btn" data-role="new-btn" aria-expanded="false" disabled>
				<?php echo Icons::get( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'استخراج اطلاعات جدید', 'persian-wp-chatbot' ); ?></span>
			</button>
		</div>
	</section>

	<!-- اطلاعات جدیدی که خودکار (هنگام انتشار) ثبت شده‌اند؛ هر مورد تیک و جزئیات دارد. تا «دیده شدن» نمایش داده می‌شود -->
	<section class="pwc-card pwc-autonew" data-role="autonew" aria-labelledby="pwc-autonew-title" hidden>
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-autonew-title"><?php esc_html_e( 'اطلاعات جدید ثبت شده به صورت خودکار', 'persian-wp-chatbot' ); ?></h2>
			<span class="pwc-chip pwc-chip--new" data-role="autonew-count"></span>
		</div>
		<p class="pwc-note pwc-note--lead"><?php esc_html_e( 'این موارد بعد از انتشار در سایت، خودکار در لیست مطالب افزونه ثبت شده‌اند. روی هر مورد بزنید تا جزئیاتش باز شود؛ مواردی را که تیک زده‌اید می‌توانید به هوش مصنوعی آموزش دهید.', 'persian-wp-chatbot' ); ?></p>
		<div class="pwc-notice pwc-notice--error" data-role="autonew-error" role="alert" hidden></div>
		<div class="pwc-notice pwc-notice--info" data-role="autonew-info" role="status" hidden></div>
		<label class="pwc-check pwc-autonew__all">
			<input type="checkbox" class="pwc-tick" data-role="autonew-all" aria-label="<?php esc_attr_e( 'انتخاب همهٔ موارد', 'persian-wp-chatbot' ); ?>">
			<span><?php esc_html_e( 'انتخاب همه', 'persian-wp-chatbot' ); ?></span>
		</label>
		<div class="pwc-autonew__list" data-role="autonew-list"></div>
		<div class="pwc-actions">
			<button type="button" class="pwc-btn" data-role="autonew-seen" disabled>
				<?php echo Icons::get( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'همه را دیدم', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
			<button type="button" class="pwc-btn pwc-btn--ghost" data-role="autonew-train" disabled>
				<?php echo Icons::get( 'sparkles', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'آموزش به هوش مصنوعی', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
			<span class="pwc-count" data-role="autonew-selected" aria-live="polite"></span>
		</div>
	</section>

	<!-- موارد حذف‌شده از سایت: برای هر کدام دکمهٔ «حذف از هوش مصنوعی» دارد؛ تا خالی نشدن لیست نمایش داده می‌شود -->
	<section class="pwc-card pwc-removed" data-role="removed" aria-labelledby="pwc-removed-title" hidden>
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-removed-title"><?php esc_html_e( 'موارد حذف‌شده از سایت', 'persian-wp-chatbot' ); ?></h2>
			<span class="pwc-chip pwc-chip--warn" data-role="removed-count"></span>
		</div>
		<p class="pwc-note pwc-note--lead"><?php esc_html_e( 'این موارد از سایت حذف شده‌اند ولی ممکن است هنوز در آموزش‌های هوش مصنوعی باشند. آیا از آموزش‌های هوش مصنوعی هم حذف شوند؟ با زدن «حذف»، مورد از این لیست خارج و درخواست حذف برای هوش مصنوعی ارسال می‌شود.', 'persian-wp-chatbot' ); ?></p>
		<div class="pwc-notice pwc-notice--error" data-role="removed-error" role="alert" hidden></div>
		<div class="pwc-removed__list" data-role="removed-list"></div>
	</section>

	<!-- فرم استخراج اطلاعات جدید: تا زدن دکمهٔ بالا مخفی است -->
	<?php
	View::render(
		'admin/chatbot/tabs/extraction-block',
		array(
			'mode'          => 'new',
			'title'         => __( 'استخراج اطلاعات جدید', 'persian-wp-chatbot' ),
			'description'   => __( 'فقط مواردی که در استخراج‌های قبلی ثبت نشده‌اند پیدا، ذخیره و نمایش داده می‌شوند.', 'persian-wp-chatbot' ),
			'summary_title' => __( 'خلاصهٔ اطلاعات جدید', 'persian-wp-chatbot' ),
			'tree_title'    => __( 'اطلاعات جدید', 'persian-wp-chatbot' ),
			'tree_note'     => __( 'این موارد جدید هستند و در جستجوهای قبلی نبودند.', 'persian-wp-chatbot' ),
			'hidden'        => true,
			'sources'       => $sources,
		)
	);

	View::render(
		'admin/chatbot/tabs/extraction-block',
		array(
			'mode'          => 'initial',
			'title'         => __( 'استخراج اولیه اطلاعات سایت', 'persian-wp-chatbot' ),
			'summary_title' => __( 'خلاصهٔ اطلاعات استخراج‌شده', 'persian-wp-chatbot' ),
			'tree_title'    => __( 'اطلاعات استخراج‌شده', 'persian-wp-chatbot' ),
			// استخراج اولیه فقط وقتی نمایش داده می‌شود که هنوز هیچ اطلاعاتی در جدول ثبت نشده باشد.
			'hidden'        => ! empty( $overview['total'] ),
			'sources'       => $sources,
		)
	);
	?>

	<!-- دیالوگ «نمایش جزئیات»: همان فرم اطلاعات استخراج‌شده با عنوان دیگر -->
	<dialog class="pwc-dialog" data-role="dialog" aria-labelledby="pwc-dialog-title">
		<div class="pwc-dialog__head">
			<h2 class="pwc-dialog__title" id="pwc-dialog-title"><?php esc_html_e( 'جزئیات اطلاعات ثبت‌شده', 'persian-wp-chatbot' ); ?></h2>
			<button type="button" class="pwc-dialog__close" data-role="dialog-close" aria-label="<?php esc_attr_e( 'بستن', 'persian-wp-chatbot' ); ?>">
				<?php echo Icons::get( 'x', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>
		<div class="pwc-dialog__body" data-role="dialog-body">
			<div class="pwc-progress" data-role="progress" hidden>
				<div class="pwc-progress__bar"><span data-role="progress-fill"></span></div>
				<p class="pwc-progress__text" data-role="progress-text" aria-live="polite"></p>
			</div>
			<div class="pwc-notice" data-role="notice" role="status" hidden></div>
			<?php
			View::render(
				'admin/chatbot/tabs/extraction-results',
				array(
					'summary_title' => __( 'خلاصهٔ اطلاعات ثبت‌شده', 'persian-wp-chatbot' ),
					'tree_title'    => __( 'اطلاعات ثبت‌شده', 'persian-wp-chatbot' ),
					'hidden'        => true,
				)
			);
			?>
		</div>
	</dialog>

</div>
