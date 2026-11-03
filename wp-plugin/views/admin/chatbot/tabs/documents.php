<?php
/**
 * تب «اسناد اضافه».
 */

use PersianAIAssistant\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="paia-stack" data-role="docs">

	<!-- مطالب اضافه شده: آمار فایل‌ها و متن‌ها (با JS از window.PAIA_ADMIN.docs پر می‌شود) + دکمه‌های افزودن در پایین -->
	<section class="paia-card paia-overview paia-docsum" data-role="docs-overview" aria-labelledby="paia-docs-title">
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-docs-title"><?php esc_html_e( 'مطالب اضافه شده', 'persian-ai-assistant' ); ?></h2>
		</div>

		<div class="paia-summary" data-role="docs-stats">
			<!-- فایل‌های آپلود شده -->
			<div class="paia-stat" data-role="docs-files"></div>

			<!-- متن‌های اضافه شده -->
			<div class="paia-stat" data-role="docs-texts"></div>
		</div>

		<p class="paia-note paia-docsum__status" data-role="doc-status" role="status" aria-live="polite" hidden></p>

		<div class="paia-actions paia-docsum__actions">
			<button type="button" class="paia-btn" data-role="doc-add" aria-expanded="false" aria-controls="paia-doc-form">
				<?php echo Icons::get( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'افزودن متن', 'persian-ai-assistant' ); ?></span>
			</button>
			<button type="button" class="paia-btn paia-btn--ghost" data-role="file-add">
				<?php echo Icons::get( 'upload', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'آپلود فایل', 'persian-ai-assistant' ); ?></span>
			</button>
		</div>
	</section>

	<!-- فرم «اضافه کردن متن جدید»: تا زدن «افزودن متن» مخفی است -->
	<section class="paia-card paia-docform" id="paia-doc-form" data-role="doc-form" aria-labelledby="paia-docform-title" hidden>
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-docform-title"><?php esc_html_e( 'اضافه کردن متن جدید', 'persian-ai-assistant' ); ?></h2>
		</div>

		<p class="paia-note paia-note--lead paia-docform__help">
			<?php esc_html_e( 'برای کمک به هوش مصنوعی، مطالبی را که از نظر موضوعی با هم متفاوت هستند با زدن دو بار Enter (اینتر) از هم جدا کنید تا بین آن‌ها یک خط کشیده شود. هر بخشِ بین دو خط یک «چانک» است؛ یعنی یک تکهٔ مستقل از دانش که هوش مصنوعی هنگام جواب دادن، فقط مرتبط‌ترین تکه‌ها را پیدا و استفاده می‌کند. برای پاک کردن یک خط جداکننده، مکان‌نما را ابتدای متنِ بعد از خط ببرید و Backspace بزنید.', 'persian-ai-assistant' ); ?>
		</p>

		<div class="paia-notice" data-role="doc-notice" role="alert" hidden></div>

		<div class="paia-rtb" data-role="doc-editor" role="group" aria-label="<?php esc_attr_e( 'متن جدید', 'persian-ai-assistant' ); ?>"></div>
		<p class="paia-count paia-docform__count" data-role="doc-count" aria-live="polite"></p>

		<div class="paia-actions">
			<button type="button" class="paia-btn" data-role="doc-train" disabled>
				<?php echo Icons::get( 'sparkles', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'آموزش به هوش مصنوعی', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
			<button type="button" class="paia-btn paia-btn--ghost" data-role="doc-draft" disabled>
				<?php echo Icons::get( 'file-text', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'ذخیره پیش‌نویس', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
		</div>
	</section>

	<!-- لیست مطالب: تب «متن‌ها» و تب «فایل‌ها» -->
	<section class="paia-card paia-doclist" data-role="doc-list" aria-labelledby="paia-doclist-title">
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-doclist-title"><?php esc_html_e( 'لیست مطالب', 'persian-ai-assistant' ); ?></h2>
		</div>

		<?php // نقش tab/tabpanel گذاشته نمی‌شود چون initTabs() تب‌های اصلی را با همین نقش پیدا می‌کند. ?>
		<div class="paia-subtabs" role="group" aria-label="<?php esc_attr_e( 'لیست مطالب', 'persian-ai-assistant' ); ?>">
			<button type="button" class="paia-subtab is-active" data-doclist-tab="texts" aria-pressed="true"></button>
			<button type="button" class="paia-subtab" data-doclist-tab="files" aria-pressed="false"></button>
		</div>

		<div class="paia-notice paia-notice--error" data-role="doclist-error" role="alert" hidden></div>
		<div class="paia-doclist__pane" data-doclist-pane="texts"></div>
		<div class="paia-doclist__pane" data-doclist-pane="files" hidden></div>
	</section>

	<!-- دیالوگ آپلود فایل -->
	<dialog class="paia-dialog paia-dialog--upload" data-role="file-dialog" aria-labelledby="paia-upload-title">
		<div class="paia-dialog__head">
			<h2 class="paia-dialog__title" id="paia-upload-title"><?php esc_html_e( 'آپلود فایل', 'persian-ai-assistant' ); ?></h2>
			<button type="button" class="paia-dialog__close" data-role="file-close" aria-label="<?php esc_attr_e( 'بستن', 'persian-ai-assistant' ); ?>">
				<?php echo Icons::get( 'x', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>

		<div class="paia-dialog__body">
			<p class="paia-note paia-note--lead"><?php esc_html_e( 'پیش از آپلود، موارد زیر را رعایت کنید تا هوش مصنوعی بتواند محتوای فایل را درست بخواند:', 'persian-ai-assistant' ); ?></p>

			<ul class="paia-rules">
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><strong><?php esc_html_e( 'متن‌ها باید قابل انتخاب باشند.', 'persian-ai-assistant' ); ?></strong> <?php esc_html_e( 'فایل نباید اسکن‌شده یا تصویری (عکسِ صفحه‌ها) باشد؛ هوش مصنوعی متنِ داخل عکس را نمی‌خواند. برای آزمایش، داخل فایل چند کلمه را با ماوس انتخاب (هایلایت) کنید؛ اگر انتخاب شد، فایل مناسب است.', 'persian-ai-assistant' ); ?></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'فقط فرمت‌های PDF، DOC، DOCX و TXT پذیرفته می‌شود. فایل TXT باید با کدگذاری UTF-8 ذخیره شده باشد.', 'persian-ai-assistant' ); ?></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span data-role="file-limit"></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'فایل نباید رمزدار یا قفل‌شده باشد.', 'persian-ai-assistant' ); ?></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'متن فارسی باید خوانا و درست باشد؛ اگر هنگام کپی کردن از داخل فایل، حروف به‌هم‌ریخته یا بی‌معنی می‌شوند، فایل مناسب نیست.', 'persian-ai-assistant' ); ?></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'تصویرها، نمودارها و جدول‌هایی که به‌صورت عکس در فایل هستند خوانده نمی‌شوند؛ اطلاعات مهم را به‌صورت متن بنویسید.', 'persian-ai-assistant' ); ?></span>
				</li>
			</ul>

			<p class="paia-note paia-upload__server" data-role="file-server-note" hidden></p>

			<label class="paia-drop" data-role="file-drop">
				<input type="file" class="paia-drop__input" data-role="file-input" accept=".pdf,.doc,.docx,.txt,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
				<span class="paia-drop__icon"><?php echo Icons::get( 'upload', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="paia-drop__title"><?php esc_html_e( 'انتخاب فایل', 'persian-ai-assistant' ); ?></span>
				<span class="paia-drop__name" data-role="file-name"></span>
			</label>

			<div class="paia-field">
				<label for="paia-file-title"><?php esc_html_e( 'عنوان فایل (اختیاری)', 'persian-ai-assistant' ); ?></label>
				<input type="text" id="paia-file-title" class="paia-input" data-role="file-title" maxlength="200" placeholder="<?php esc_attr_e( 'اگر خالی بماند، نام فایل عنوان می‌شود', 'persian-ai-assistant' ); ?>">
			</div>

			<label class="paia-check paia-upload__confirm">
				<input type="checkbox" class="paia-tick" data-role="file-confirm">
				<span><?php esc_html_e( 'تأیید می‌کنم که متن‌های این فایل قابل انتخاب هستند و فایل اسکن‌شده یا تصویری نیست.', 'persian-ai-assistant' ); ?></span>
			</label>

			<div class="paia-notice paia-notice--error" data-role="file-error" role="alert" hidden></div>

			<div class="paia-progress" data-role="file-progress" hidden>
				<div class="paia-progress__bar"><span data-role="file-progress-fill"></span></div>
				<p class="paia-progress__text" data-role="file-progress-text" aria-live="polite"></p>
			</div>

			<div class="paia-actions">
				<button type="button" class="paia-btn" data-role="file-submit" disabled>
					<?php echo Icons::get( 'upload', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="paia-btn__label"><?php esc_html_e( 'آپلود و ثبت', 'persian-ai-assistant' ); ?></span>
					<span class="paia-spinner" aria-hidden="true"></span>
				</button>
				<button type="button" class="paia-btn paia-btn--ghost" data-role="file-cancel"><?php esc_html_e( 'انصراف', 'persian-ai-assistant' ); ?></button>
			</div>
		</div>
	</dialog>

</div>
