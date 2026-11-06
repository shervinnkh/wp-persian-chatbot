<?php
/**
 * تب «اسناد اضافه».
 */

use PersianWPChatbot\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="pwc-stack" data-role="docs">

	<!-- مطالب اضافه شده: آمار فایل‌ها و متن‌ها (با JS از window.PWC_ADMIN.docs پر می‌شود) + دکمه‌های افزودن در پایین -->
	<section class="pwc-card pwc-overview pwc-docsum" data-role="docs-overview" aria-labelledby="pwc-docs-title">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-docs-title"><?php esc_html_e( 'مطالب اضافه شده', 'persian-wp-chatbot' ); ?></h2>
		</div>

		<div class="pwc-summary" data-role="docs-stats">
			<!-- فایل‌های آپلود شده -->
			<div class="pwc-stat" data-role="docs-files"></div>

			<!-- متن‌های اضافه شده -->
			<div class="pwc-stat" data-role="docs-texts"></div>
		</div>

		<p class="pwc-note pwc-docsum__status" data-role="doc-status" role="status" aria-live="polite" hidden></p>

		<div class="pwc-actions pwc-docsum__actions">
			<button type="button" class="pwc-btn" data-role="doc-add" aria-expanded="false" aria-controls="pwc-doc-form">
				<?php echo Icons::get( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'افزودن متن', 'persian-wp-chatbot' ); ?></span>
			</button>
			<button type="button" class="pwc-btn pwc-btn--ghost" data-role="file-add">
				<?php echo Icons::get( 'upload', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'آپلود فایل', 'persian-wp-chatbot' ); ?></span>
			</button>
		</div>
	</section>

	<!-- فرم «اضافه کردن متن جدید»: تا زدن «افزودن متن» مخفی است -->
	<section class="pwc-card pwc-docform" id="pwc-doc-form" data-role="doc-form" aria-labelledby="pwc-docform-title" hidden>
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-docform-title"><?php esc_html_e( 'اضافه کردن متن جدید', 'persian-wp-chatbot' ); ?></h2>
		</div>

		<p class="pwc-note pwc-note--lead pwc-docform__help">
			<?php esc_html_e( 'برای کمک به هوش مصنوعی، مطالبی را که از نظر موضوعی با هم متفاوت هستند با زدن دو بار Enter (اینتر) از هم جدا کنید تا بین آن‌ها یک خط کشیده شود. هر بخشِ بین دو خط یک «چانک» است؛ یعنی یک تکهٔ مستقل از دانش که هوش مصنوعی هنگام جواب دادن، فقط مرتبط‌ترین تکه‌ها را پیدا و استفاده می‌کند. برای پاک کردن یک خط جداکننده، مکان‌نما را ابتدای متنِ بعد از خط ببرید و Backspace بزنید.', 'persian-wp-chatbot' ); ?>
		</p>

		<div class="pwc-notice" data-role="doc-notice" role="alert" hidden></div>

		<div class="pwc-rtb" data-role="doc-editor" role="group" aria-label="<?php esc_attr_e( 'متن جدید', 'persian-wp-chatbot' ); ?>"></div>
		<p class="pwc-count pwc-docform__count" data-role="doc-count" aria-live="polite"></p>

		<div class="pwc-actions">
			<button type="button" class="pwc-btn" data-role="doc-train" disabled>
				<?php echo Icons::get( 'sparkles', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'آموزش به هوش مصنوعی', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
			<button type="button" class="pwc-btn pwc-btn--ghost" data-role="doc-draft" disabled>
				<?php echo Icons::get( 'file-text', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'ذخیره پیش‌نویس', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
		</div>
	</section>

	<!-- لیست مطالب: تب «متن‌ها» و تب «فایل‌ها» -->
	<section class="pwc-card pwc-doclist" data-role="doc-list" aria-labelledby="pwc-doclist-title">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-doclist-title"><?php esc_html_e( 'لیست مطالب', 'persian-wp-chatbot' ); ?></h2>
		</div>

		<?php // نقش tab/tabpanel گذاشته نمی‌شود چون initTabs() تب‌های اصلی را با همین نقش پیدا می‌کند. ?>
		<div class="pwc-subtabs" role="group" aria-label="<?php esc_attr_e( 'لیست مطالب', 'persian-wp-chatbot' ); ?>">
			<button type="button" class="pwc-subtab is-active" data-doclist-tab="texts" aria-pressed="true"></button>
			<button type="button" class="pwc-subtab" data-doclist-tab="files" aria-pressed="false"></button>
		</div>

		<div class="pwc-notice pwc-notice--error" data-role="doclist-error" role="alert" hidden></div>
		<div class="pwc-doclist__pane" data-doclist-pane="texts"></div>
		<div class="pwc-doclist__pane" data-doclist-pane="files" hidden></div>
	</section>

	<!-- دیالوگ آپلود فایل -->
	<dialog class="pwc-dialog pwc-dialog--upload" data-role="file-dialog" aria-labelledby="pwc-upload-title">
		<div class="pwc-dialog__head">
			<h2 class="pwc-dialog__title" id="pwc-upload-title"><?php esc_html_e( 'آپلود فایل', 'persian-wp-chatbot' ); ?></h2>
			<button type="button" class="pwc-dialog__close" data-role="file-close" aria-label="<?php esc_attr_e( 'بستن', 'persian-wp-chatbot' ); ?>">
				<?php echo Icons::get( 'x', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>

		<div class="pwc-dialog__body">
			<p class="pwc-note pwc-note--lead"><?php esc_html_e( 'پیش از آپلود، موارد زیر را رعایت کنید تا هوش مصنوعی بتواند محتوای فایل را درست بخواند:', 'persian-wp-chatbot' ); ?></p>

			<ul class="pwc-rules">
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><strong><?php esc_html_e( 'متن‌ها باید قابل انتخاب باشند.', 'persian-wp-chatbot' ); ?></strong> <?php esc_html_e( 'فایل نباید اسکن‌شده یا تصویری (عکسِ صفحه‌ها) باشد؛ هوش مصنوعی متنِ داخل عکس را نمی‌خواند. برای آزمایش، داخل فایل چند کلمه را با ماوس انتخاب (هایلایت) کنید؛ اگر انتخاب شد، فایل مناسب است.', 'persian-wp-chatbot' ); ?></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'فقط فرمت‌های PDF، DOC، DOCX و TXT پذیرفته می‌شود. فایل TXT باید با کدگذاری UTF-8 ذخیره شده باشد.', 'persian-wp-chatbot' ); ?></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span data-role="file-limit"></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'فایل نباید رمزدار یا قفل‌شده باشد.', 'persian-wp-chatbot' ); ?></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'متن فارسی باید خوانا و درست باشد؛ اگر هنگام کپی کردن از داخل فایل، حروف به‌هم‌ریخته یا بی‌معنی می‌شوند، فایل مناسب نیست.', 'persian-wp-chatbot' ); ?></span>
				</li>
				<li>
					<?php echo Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'تصویرها، نمودارها و جدول‌هایی که به‌صورت عکس در فایل هستند خوانده نمی‌شوند؛ اطلاعات مهم را به‌صورت متن بنویسید.', 'persian-wp-chatbot' ); ?></span>
				</li>
			</ul>

			<p class="pwc-note pwc-upload__server" data-role="file-server-note" hidden></p>

			<label class="pwc-drop" data-role="file-drop">
				<input type="file" class="pwc-drop__input" data-role="file-input" accept=".pdf,.doc,.docx,.txt,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
				<span class="pwc-drop__icon"><?php echo Icons::get( 'upload', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="pwc-drop__title"><?php esc_html_e( 'انتخاب فایل', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-drop__name" data-role="file-name"></span>
			</label>

			<div class="pwc-field">
				<label for="pwc-file-title"><?php esc_html_e( 'عنوان فایل (اختیاری)', 'persian-wp-chatbot' ); ?></label>
				<input type="text" id="pwc-file-title" class="pwc-input" data-role="file-title" maxlength="200" placeholder="<?php esc_attr_e( 'اگر خالی بماند، نام فایل عنوان می‌شود', 'persian-wp-chatbot' ); ?>">
			</div>

			<label class="pwc-check pwc-upload__confirm">
				<input type="checkbox" class="pwc-tick" data-role="file-confirm">
				<span><?php esc_html_e( 'تأیید می‌کنم که متن‌های این فایل قابل انتخاب هستند و فایل اسکن‌شده یا تصویری نیست.', 'persian-wp-chatbot' ); ?></span>
			</label>

			<div class="pwc-notice pwc-notice--error" data-role="file-error" role="alert" hidden></div>

			<div class="pwc-progress" data-role="file-progress" hidden>
				<div class="pwc-progress__bar"><span data-role="file-progress-fill"></span></div>
				<p class="pwc-progress__text" data-role="file-progress-text" aria-live="polite"></p>
			</div>

			<div class="pwc-actions">
				<button type="button" class="pwc-btn" data-role="file-submit" disabled>
					<?php echo Icons::get( 'upload', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="pwc-btn__label"><?php esc_html_e( 'آپلود و ثبت', 'persian-wp-chatbot' ); ?></span>
					<span class="pwc-spinner" aria-hidden="true"></span>
				</button>
				<button type="button" class="pwc-btn pwc-btn--ghost" data-role="file-cancel"><?php esc_html_e( 'انصراف', 'persian-wp-chatbot' ); ?></button>
			</div>
		</div>
	</dialog>

</div>
