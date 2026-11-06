<?php
/**
 * تب «اطلاعات به‌روز»: لیست (اولین مورد = تمام محصولات) + فرم‌های زیر آن. منطق در initLive() (admin.js).
 *
 * @var array<string, array{title:string, save:string, lead:string, fields:array<string, array{label:string, description:string, icon:string}>, state:array{enabled:bool, fields:array<string,bool>}}> $sources
 */

use PersianWPChatbot\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="pwc-stack pwc-live" data-role="live">

	<!-- توضیح بالای تب -->
	<section class="pwc-card pwc-live__intro" aria-labelledby="pwc-live-intro-title">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-live-intro-title">
				<?php echo Icons::get( 'zap', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'اطلاعات به‌روز چیست؟', 'persian-wp-chatbot' ); ?>
			</h2>
		</div>
		<p class="pwc-live__text">
			<?php esc_html_e( 'بعضی اطلاعات، مثل قیمت محصولات یا تخفیف‌ها، هر روز تغییر می‌کنند. اگر چنین اطلاعاتی در دانش هوش مصنوعی ذخیره شود، خیلی زود قدیمی و نادرست می‌شود. به همین دلیل هوش مصنوعی باید هنگام ساخت پاسخ، این اطلاعات را مستقیماً از سایت استعلام بگیرد و مقدار به‌روز را پیدا کند.', 'persian-wp-chatbot' ); ?>
		</p>
		<p class="pwc-live__text">
			<?php esc_html_e( 'در این بخش می‌توانید اطلاعاتی را که روزانه تغییر می‌کنند تعریف کنید تا هوش مصنوعی دقیقاً بداند چه چیزهایی را باید از سایت استعلام بگیرد.', 'persian-wp-chatbot' ); ?>
		</p>
	</section>

	<!-- لیست اطلاعات به‌روز: سه مورد اول «تمام محصولات»، «تمام وبلاگ‌ها» و «تمام برگه‌ها» هستند؛ با زدن روی هر مورد، فرمش زیر لیست باز می‌شود -->
	<section class="pwc-card pwc-live__list" aria-labelledby="pwc-live-list-title">
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-live-list-title"><?php esc_html_e( 'لیست اطلاعات به‌روز', 'persian-wp-chatbot' ); ?></h2>
			<button type="button" class="pwc-btn" data-role="live-add">
				<?php echo Icons::get( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'افزودن', 'persian-wp-chatbot' ); ?></span>
			</button>
		</div>
		<p class="pwc-note pwc-live__listmsg" data-role="live-listmsg" role="alert" hidden></p>
		<div class="pwc-live__rows" data-role="live-list"></div>
	</section>

	<!-- فرم ۱: تنظیمات اطلاعات به‌روز هر منبع (محصولات، وبلاگ‌ها، برگه‌ها)؛ با زدن روی مورد همان منبع در لیست باز می‌شود -->
	<?php foreach ( $sources as $pwc_source => $pwc_def ) : ?>
		<form class="pwc-card pwc-chatset pwc-live__form" data-role="live-source" data-source="<?php echo esc_attr( (string) $pwc_source ); ?>" novalidate aria-labelledby="pwc-live-<?php echo esc_attr( (string) $pwc_source ); ?>-title" hidden>
			<div class="pwc-card__head">
				<h2 class="pwc-card__title" id="pwc-live-<?php echo esc_attr( (string) $pwc_source ); ?>-title"><?php echo esc_html( $pwc_def['title'] ); ?></h2>
			</div>

			<p class="pwc-note pwc-note--lead"><?php echo esc_html( $pwc_def['lead'] ); ?></p>

			<div class="pwc-options">
				<?php foreach ( $pwc_def['fields'] as $pwc_key => $pwc_field ) : ?>
					<label class="pwc-option">
						<input type="checkbox" name="live_fields[]" value="<?php echo esc_attr( (string) $pwc_key ); ?>" <?php checked( ! empty( $pwc_def['state']['fields'][ $pwc_key ] ) ); ?>>
						<span class="pwc-option__card">
							<span class="pwc-option__icon"><?php echo Icons::get( $pwc_field['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="pwc-option__text">
								<strong><?php echo esc_html( $pwc_field['label'] ); ?></strong>
								<small><?php echo esc_html( $pwc_field['description'] ); ?></small>
							</span>
							<span class="pwc-option__check"><?php echo Icons::get( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="pwc-actions">
				<button type="submit" class="pwc-btn" data-role="live-source-save">
					<?php echo Icons::get( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="pwc-btn__label"><?php echo esc_html( $pwc_def['save'] ); ?></span>
					<span class="pwc-spinner" aria-hidden="true"></span>
				</button>
				<button type="button" class="pwc-btn pwc-btn--ghost" data-role="live-close">
					<span class="pwc-btn__label"><?php esc_html_e( 'بستن', 'persian-wp-chatbot' ); ?></span>
				</button>
			</div>
			<p class="pwc-note pwc-live__msg" data-role="live-source-msg" role="status" aria-live="polite" hidden></p>
		</form>
	<?php endforeach; ?>

	<!-- فرم ۲: افزودن اطلاعات به‌روز (بعداً به‌عنوان ابزار هوش مصنوعی استفاده می‌شود) -->
	<form class="pwc-card pwc-chatset pwc-live__form" data-role="live-custom" novalidate aria-labelledby="pwc-live-custom-title" hidden>
		<div class="pwc-card__head">
			<h2 class="pwc-card__title" id="pwc-live-custom-title" data-role="live-title"><?php esc_html_e( 'افزودن اطلاعات به‌روز', 'persian-wp-chatbot' ); ?></h2>
		</div>

		<p class="pwc-note pwc-note--lead">
			<?php esc_html_e( 'هر نوع داده‌ای که می‌خواهید هوش مصنوعی همیشه مقدار به‌روزش را داشته باشد (مثل هزینهٔ خدمات یا ساعت کاری) را اینجا تعریف کنید.', 'persian-wp-chatbot' ); ?>
		</p>

		<div class="pwc-chatset__grid">
			<label class="pwc-chatset__f pwc-chatset__wide">
				<span><?php esc_html_e( 'نام نوع داده', 'persian-wp-chatbot' ); ?></span>
				<input type="text" name="live_name" maxlength="120" required autocomplete="off" placeholder="<?php esc_attr_e( 'مثلاً: خدمات نصب کولر', 'persian-wp-chatbot' ); ?>">
			</label>

			<label class="pwc-chatset__f pwc-chatset__wide">
				<span>
					<?php esc_html_e( 'توضیح کوتاه درباره نوع داده', 'persian-wp-chatbot' ); ?>
					<em class="pwc-live__badge"><?php esc_html_e( 'خیلی مهم', 'persian-wp-chatbot' ); ?></em>
				</span>
				<textarea name="live_description" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'مثلاً: اگر درباره هزینهٔ خدمات نصب کولر پرسیدند، این جواب را بده', 'persian-wp-chatbot' ); ?>"></textarea>
				<small class="pwc-chatset__hint"><?php esc_html_e( 'هوش مصنوعی فقط با خواندن همین توضیح تشخیص می‌دهد در چه موقعیتی باید سراغ این اطلاعات برود؛ پس مشخص بنویسید چه پرسشی از کاربر باید این داده را فعال کند.', 'persian-wp-chatbot' ); ?></small>
			</label>

			<label class="pwc-chatset__f pwc-chatset__wide">
				<span><?php esc_html_e( 'اطلاعات داده', 'persian-wp-chatbot' ); ?></span>
				<textarea name="live_data" rows="6" maxlength="20000" placeholder="<?php esc_attr_e( 'مثلاً: هزینهٔ نصب کولر آبی ۱٬۲۰۰٬۰۰۰ تومان و هزینهٔ نصب کولر گازی ۲٬۱۰۰٬۰۰۰ تومان می‌باشد.', 'persian-wp-chatbot' ); ?>"></textarea>
				<small class="pwc-chatset__hint"><?php esc_html_e( 'مقدار فعلی داده را بنویسید. هر زمان تغییر کرد، همین‌جا به‌روزش کنید تا هوش مصنوعی همیشه آخرین مقدار را بداند.', 'persian-wp-chatbot' ); ?></small>
			</label>
		</div>

		<div class="pwc-actions">
			<button type="submit" class="pwc-btn" data-role="live-save">
				<?php echo Icons::get( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'افزودن اطلاعات به‌روز', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
			<button type="button" class="pwc-btn pwc-btn--ghost" data-role="live-delete" hidden>
				<?php echo Icons::get( 'trash', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="pwc-btn__label"><?php esc_html_e( 'حذف', 'persian-wp-chatbot' ); ?></span>
				<span class="pwc-spinner" aria-hidden="true"></span>
			</button>
			<button type="button" class="pwc-btn pwc-btn--ghost" data-role="live-close">
				<span class="pwc-btn__label"><?php esc_html_e( 'بستن', 'persian-wp-chatbot' ); ?></span>
			</button>
		</div>
		<p class="pwc-note pwc-live__msg" data-role="live-msg" role="status" aria-live="polite" hidden></p>
	</form>

</div>
