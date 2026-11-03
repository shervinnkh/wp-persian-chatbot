<?php
/**
 * تب «اطلاعات به‌روز»: لیست (اولین مورد = تمام محصولات) + فرم‌های زیر آن. منطق در initLive() (admin.js).
 *
 * @var array<string, array{title:string, save:string, lead:string, fields:array<string, array{label:string, description:string, icon:string}>, state:array{enabled:bool, fields:array<string,bool>}}> $sources
 */

use PersianAIAssistant\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="paia-stack paia-live" data-role="live">

	<!-- توضیح بالای تب -->
	<section class="paia-card paia-live__intro" aria-labelledby="paia-live-intro-title">
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-live-intro-title">
				<?php echo Icons::get( 'zap', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'اطلاعات به‌روز چیست؟', 'persian-ai-assistant' ); ?>
			</h2>
		</div>
		<p class="paia-live__text">
			<?php esc_html_e( 'بعضی اطلاعات، مثل قیمت محصولات یا تخفیف‌ها، هر روز تغییر می‌کنند. اگر چنین اطلاعاتی در دانش هوش مصنوعی ذخیره شود، خیلی زود قدیمی و نادرست می‌شود. به همین دلیل هوش مصنوعی باید هنگام ساخت پاسخ، این اطلاعات را مستقیماً از سایت استعلام بگیرد و مقدار به‌روز را پیدا کند.', 'persian-ai-assistant' ); ?>
		</p>
		<p class="paia-live__text">
			<?php esc_html_e( 'در این بخش می‌توانید اطلاعاتی را که روزانه تغییر می‌کنند تعریف کنید تا هوش مصنوعی دقیقاً بداند چه چیزهایی را باید از سایت استعلام بگیرد.', 'persian-ai-assistant' ); ?>
		</p>
	</section>

	<!-- لیست اطلاعات به‌روز: سه مورد اول «تمام محصولات»، «تمام وبلاگ‌ها» و «تمام برگه‌ها» هستند؛ با زدن روی هر مورد، فرمش زیر لیست باز می‌شود -->
	<section class="paia-card paia-live__list" aria-labelledby="paia-live-list-title">
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-live-list-title"><?php esc_html_e( 'لیست اطلاعات به‌روز', 'persian-ai-assistant' ); ?></h2>
			<button type="button" class="paia-btn" data-role="live-add">
				<?php echo Icons::get( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'افزودن', 'persian-ai-assistant' ); ?></span>
			</button>
		</div>
		<p class="paia-note paia-live__listmsg" data-role="live-listmsg" role="alert" hidden></p>
		<div class="paia-live__rows" data-role="live-list"></div>
	</section>

	<!-- فرم ۱: تنظیمات اطلاعات به‌روز هر منبع (محصولات، وبلاگ‌ها، برگه‌ها)؛ با زدن روی مورد همان منبع در لیست باز می‌شود -->
	<?php foreach ( $sources as $paia_source => $paia_def ) : ?>
		<form class="paia-card paia-chatset paia-live__form" data-role="live-source" data-source="<?php echo esc_attr( (string) $paia_source ); ?>" novalidate aria-labelledby="paia-live-<?php echo esc_attr( (string) $paia_source ); ?>-title" hidden>
			<div class="paia-card__head">
				<h2 class="paia-card__title" id="paia-live-<?php echo esc_attr( (string) $paia_source ); ?>-title"><?php echo esc_html( $paia_def['title'] ); ?></h2>
			</div>

			<p class="paia-note paia-note--lead"><?php echo esc_html( $paia_def['lead'] ); ?></p>

			<div class="paia-options">
				<?php foreach ( $paia_def['fields'] as $paia_key => $paia_field ) : ?>
					<label class="paia-option">
						<input type="checkbox" name="live_fields[]" value="<?php echo esc_attr( (string) $paia_key ); ?>" <?php checked( ! empty( $paia_def['state']['fields'][ $paia_key ] ) ); ?>>
						<span class="paia-option__card">
							<span class="paia-option__icon"><?php echo Icons::get( $paia_field['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="paia-option__text">
								<strong><?php echo esc_html( $paia_field['label'] ); ?></strong>
								<small><?php echo esc_html( $paia_field['description'] ); ?></small>
							</span>
							<span class="paia-option__check"><?php echo Icons::get( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="paia-actions">
				<button type="submit" class="paia-btn" data-role="live-source-save">
					<?php echo Icons::get( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="paia-btn__label"><?php echo esc_html( $paia_def['save'] ); ?></span>
					<span class="paia-spinner" aria-hidden="true"></span>
				</button>
				<button type="button" class="paia-btn paia-btn--ghost" data-role="live-close">
					<span class="paia-btn__label"><?php esc_html_e( 'بستن', 'persian-ai-assistant' ); ?></span>
				</button>
			</div>
			<p class="paia-note paia-live__msg" data-role="live-source-msg" role="status" aria-live="polite" hidden></p>
		</form>
	<?php endforeach; ?>

	<!-- فرم ۲: افزودن اطلاعات به‌روز (بعداً به‌عنوان ابزار هوش مصنوعی استفاده می‌شود) -->
	<form class="paia-card paia-chatset paia-live__form" data-role="live-custom" novalidate aria-labelledby="paia-live-custom-title" hidden>
		<div class="paia-card__head">
			<h2 class="paia-card__title" id="paia-live-custom-title" data-role="live-title"><?php esc_html_e( 'افزودن اطلاعات به‌روز', 'persian-ai-assistant' ); ?></h2>
		</div>

		<p class="paia-note paia-note--lead">
			<?php esc_html_e( 'هر نوع داده‌ای که می‌خواهید هوش مصنوعی همیشه مقدار به‌روزش را داشته باشد (مثل هزینهٔ خدمات یا ساعت کاری) را اینجا تعریف کنید.', 'persian-ai-assistant' ); ?>
		</p>

		<div class="paia-chatset__grid">
			<label class="paia-chatset__f paia-chatset__wide">
				<span><?php esc_html_e( 'نام نوع داده', 'persian-ai-assistant' ); ?></span>
				<input type="text" name="live_name" maxlength="120" required autocomplete="off" placeholder="<?php esc_attr_e( 'مثلاً: خدمات نصب کولر', 'persian-ai-assistant' ); ?>">
			</label>

			<label class="paia-chatset__f paia-chatset__wide">
				<span>
					<?php esc_html_e( 'توضیح کوتاه درباره نوع داده', 'persian-ai-assistant' ); ?>
					<em class="paia-live__badge"><?php esc_html_e( 'خیلی مهم', 'persian-ai-assistant' ); ?></em>
				</span>
				<textarea name="live_description" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'مثلاً: اگر درباره هزینهٔ خدمات نصب کولر پرسیدند، این جواب را بده', 'persian-ai-assistant' ); ?>"></textarea>
				<small class="paia-chatset__hint"><?php esc_html_e( 'هوش مصنوعی فقط با خواندن همین توضیح تشخیص می‌دهد در چه موقعیتی باید سراغ این اطلاعات برود؛ پس مشخص بنویسید چه پرسشی از کاربر باید این داده را فعال کند.', 'persian-ai-assistant' ); ?></small>
			</label>

			<label class="paia-chatset__f paia-chatset__wide">
				<span><?php esc_html_e( 'اطلاعات داده', 'persian-ai-assistant' ); ?></span>
				<textarea name="live_data" rows="6" maxlength="20000" placeholder="<?php esc_attr_e( 'مثلاً: هزینهٔ نصب کولر آبی ۱٬۲۰۰٬۰۰۰ تومان و هزینهٔ نصب کولر گازی ۲٬۱۰۰٬۰۰۰ تومان می‌باشد.', 'persian-ai-assistant' ); ?>"></textarea>
				<small class="paia-chatset__hint"><?php esc_html_e( 'مقدار فعلی داده را بنویسید. هر زمان تغییر کرد، همین‌جا به‌روزش کنید تا هوش مصنوعی همیشه آخرین مقدار را بداند.', 'persian-ai-assistant' ); ?></small>
			</label>
		</div>

		<div class="paia-actions">
			<button type="submit" class="paia-btn" data-role="live-save">
				<?php echo Icons::get( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'افزودن اطلاعات به‌روز', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
			<button type="button" class="paia-btn paia-btn--ghost" data-role="live-delete" hidden>
				<?php echo Icons::get( 'trash', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'حذف', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
			<button type="button" class="paia-btn paia-btn--ghost" data-role="live-close">
				<span class="paia-btn__label"><?php esc_html_e( 'بستن', 'persian-ai-assistant' ); ?></span>
			</button>
		</div>
		<p class="paia-note paia-live__msg" data-role="live-msg" role="status" aria-live="polite" hidden></p>
	</form>

</div>
