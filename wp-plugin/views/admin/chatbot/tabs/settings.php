<?php
defined( 'ABSPATH' ) || exit;

use PersianWPChatbot\Apis\Client;
use PersianWPChatbot\Chat\ChatSettings;
use PersianWPChatbot\Extraction\Sources;
use PersianWPChatbot\Sync\SyncSettings;

$pwc_s    = ChatSettings::all();
$pwc_name = static function ( $key ) {
	return ChatSettings::OPTION . '[' . $key . ']';
};
$pwc_sync    = SyncSettings::all();
$pwc_sources = Sources::all();
$pwc_back = admin_url( 'admin.php?page=pwc-settings' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$pwc_saved = isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'];
?>
<form class="pwc-chatset" novalidate method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
	<?php settings_fields( 'pwc_chat' ); ?>
	<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $pwc_back ); ?>">

	<?php if ( $pwc_saved ) : ?>
		<p class="pwc-chatset__ok" role="status"><?php esc_html_e( 'تنظیمات ذخیره شد.', 'persian-wp-chatbot' ); ?></p>
	<?php endif; ?>

	<div class="pwc-setlayout">
		<nav class="pwc-setnav" aria-label="<?php esc_attr_e( 'دسته‌بندی تنظیمات', 'persian-wp-chatbot' ); ?>">
			<button type="button" class="pwc-setnav__item is-active" id="pwc-setnav-general" data-set="general" aria-controls="pwc-setpane-general" aria-pressed="true"><?php esc_html_e( 'عمومی', 'persian-wp-chatbot' ); ?></button>
			<button type="button" class="pwc-setnav__item" id="pwc-setnav-extraction" data-set="extraction" aria-controls="pwc-setpane-extraction" aria-pressed="false"><?php esc_html_e( 'استخراج اطلاعات سایت', 'persian-wp-chatbot' ); ?></button>
			<button type="button" class="pwc-setnav__item" id="pwc-setnav-chat" data-set="chat" aria-controls="pwc-setpane-chat" aria-pressed="false"><?php esc_html_e( 'مدیریت چت', 'persian-wp-chatbot' ); ?></button>
		</nav>

		<div class="pwc-setbody">
			<div class="pwc-setpane pwc-stack" id="pwc-setpane-general" data-setpane="general">
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'اطلاعات کلی سایت', 'persian-wp-chatbot' ); ?></h2></div>
				<div class="pwc-chatset__grid">
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'عنوان سایت', 'persian-wp-chatbot' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $pwc_name( 'site_title' ) ); ?>" value="<?php echo esc_attr( ChatSettings::site_title() ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						<small class="pwc-chatset__hint pwc-chatset__hint--under"><?php esc_html_e( 'به‌صورت پیش‌فرض از عنوان سایت وردپرس گرفته می‌شود؛ اگر خالی بگذارید هم همان استفاده می‌شود.', 'persian-wp-chatbot' ); ?></small>
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'توضیح کوتاه درباره عملکرد سایت', 'persian-wp-chatbot' ); ?></span>
						<input type="text" maxlength="<?php echo esc_attr( (string) ChatSettings::DESC_MAX ); ?>" name="<?php echo esc_attr( $pwc_name( 'site_desc' ) ); ?>" value="<?php echo esc_attr( $pwc_s['site_desc'] ); ?>" placeholder="<?php esc_attr_e( 'مثلاً: فروشگاه اینترنتی لوازم خانگی با ارسال به سراسر کشور', 'persian-wp-chatbot' ); ?>">
						<small class="pwc-chatset__hint pwc-chatset__hint--under"><?php esc_html_e( 'فقط یک خط.', 'persian-wp-chatbot' ); ?></small>
					</label>
				</div>
			</section>
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'سرویس هوش مصنوعی', 'persian-wp-chatbot' ); ?></h2></div>
				<div class="pwc-chatset__grid pwc-chatset__grid--select">
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'انتخاب نوع سرویس', 'persian-wp-chatbot' ); ?></span>
						<select name="<?php echo esc_attr( $pwc_name( 'ai_service' ) ); ?>">
							<?php foreach ( ChatSettings::ai_services() as $pwc_key => $pwc_label ) : ?>
								<option value="<?php echo esc_attr( (string) $pwc_key ); ?>" <?php selected( $pwc_s['ai_service'], $pwc_key ); ?>><?php echo esc_html( $pwc_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'انتخاب لحن صحبت', 'persian-wp-chatbot' ); ?></span>
						<select name="<?php echo esc_attr( $pwc_name( 'tone' ) ); ?>">
							<?php foreach ( array( 'friendly', 'formal' ) as $pwc_key ) : ?>
								<option value="<?php echo esc_attr( $pwc_key ); ?>" <?php selected( $pwc_s['tone'], $pwc_key ); ?>><?php echo esc_html( ChatSettings::tones()[ $pwc_key ] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'سقف مصرف روزانه (دلار)', 'persian-wp-chatbot' ); ?></span>
						<input type="number" min="0" max="100000" step="0.01" name="<?php echo esc_attr( $pwc_name( 'budget_daily_usd' ) ); ?>" value="<?php echo esc_attr( (string) $pwc_s['budget_daily_usd'] ); ?>">
						<small class="pwc-chatset__hint pwc-chatset__hint--under"><?php esc_html_e( '۰ = بدون محدودیت. مصرف به دلار حساب می‌شود.', 'persian-wp-chatbot' ); ?></small>
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'سقف مصرف ماهانه (دلار)', 'persian-wp-chatbot' ); ?></span>
						<input type="number" min="0" max="100000" step="0.01" name="<?php echo esc_attr( $pwc_name( 'budget_monthly_usd' ) ); ?>" value="<?php echo esc_attr( (string) $pwc_s['budget_monthly_usd'] ); ?>">
						<small class="pwc-chatset__hint pwc-chatset__hint--under"><?php esc_html_e( '۰ = بدون محدودیت. مصرف به دلار حساب می‌شود.', 'persian-wp-chatbot' ); ?></small>
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'توضیحات مهم', 'persian-wp-chatbot' ); ?></span>
						<textarea class="pwc-chatset__notes" rows="6" maxlength="<?php echo esc_attr( (string) ChatSettings::NOTES_MAX ); ?>" name="<?php echo esc_attr( $pwc_name( 'important_notes' ) ); ?>"><?php echo esc_textarea( $pwc_s['important_notes'] ); ?></textarea>
						<small class="pwc-chatset__hint pwc-chatset__hint--under"><?php esc_html_e( 'فقط توضیحاتی که خیلی مهم هست و هوش مصنوعی در هر بار پاسخگویی به مشتریان باید آن را بداند بنویسید. برای بالا نرفتن مصرف توکن حتماً جملات کوتاه و محدود را وارد کنید.', 'persian-wp-chatbot' ); ?></small>
					</label>
					<div class="pwc-chatset__f pwc-chatset__wide">
						<label class="pwc-check">
							<input type="checkbox" class="pwc-tick" name="<?php echo esc_attr( $pwc_name( 'ai_mark' ) ); ?>" value="1" <?php checked( ! empty( $pwc_s['ai_mark'] ) ); ?>>
							<span><?php esc_html_e( 'مارک کردن چت توسط هوش مصنوعی', 'persian-wp-chatbot' ); ?></span>
						</label>
						<small class="pwc-chatset__hint pwc-chatset__hint--under"><?php esc_html_e( 'اگه فعال باشه اگه هوش مصنوعی نتونه جوابی درباره پرسش کاربر بده هیچ جوابی نده و فقط صفحه چت رو مارک کنه تا پشتیبانان اون رو جواب بدن', 'persian-wp-chatbot' ); ?></small>
					</div>
				</div>
			</section>
			<?php
			// اتصال به سرور هوش مصنوعی (S-5): روش اصلی ثابت‌های wp-config.php است؛ با تعریف PWC_API_SECRET هیچ مقداری از کلید
			// در دیتابیس ذخیره یا در این صفحه چاپ نمی‌شود. وگرنه فقط شکل ماسک‌شده (•••• + ۴ نویسهٔ آخر) دیده می‌شود.
			$pwc_key_const = Client::secret_from_constant();
			$pwc_url_const = defined( 'PWC_API_URL' ) && '' !== (string) PWC_API_URL;
			$pwc_key_mask  = ChatSettings::masked_key();
			?>
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'اتصال به سرور هوش مصنوعی', 'persian-wp-chatbot' ); ?></h2></div>
				<div class="pwc-chatset__grid">
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'آدرس سرور', 'persian-wp-chatbot' ); ?></span>
						<?php if ( $pwc_url_const ) : ?>
							<input type="text" dir="ltr" value="<?php esc_attr_e( 'از wp-config.php خوانده می‌شود', 'persian-wp-chatbot' ); ?>" disabled>
						<?php else : ?>
							<input type="url" dir="ltr" autocomplete="off" name="<?php echo esc_attr( $pwc_name( 'api_url' ) ); ?>" value="<?php echo esc_attr( (string) $pwc_s['api_url'] ); ?>" placeholder="https://ai.example.com">
						<?php endif; ?>
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'کلید اتصال', 'persian-wp-chatbot' ); ?></span>
						<?php if ( $pwc_key_const ) : ?>
							<input type="text" dir="ltr" value="<?php esc_attr_e( 'از wp-config.php خوانده می‌شود', 'persian-wp-chatbot' ); ?>" disabled>
							<small class="pwc-chatset__hint pwc-chatset__hint--under"><?php esc_html_e( 'کلید با ثابت PWC_API_SECRET در wp-config.php تعریف شده است؛ در دیتابیس ذخیره و در این صفحه نمایش داده نمی‌شود.', 'persian-wp-chatbot' ); ?></small>
						<?php else : ?>
							<input type="password" dir="ltr" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr( $pwc_name( 'api_key' ) ); ?>" value="" placeholder="<?php echo esc_attr( '' !== $pwc_key_mask ? $pwc_key_mask : __( 'کلید را وارد کنید', 'persian-wp-chatbot' ) ); ?>">
							<small class="pwc-chatset__hint pwc-chatset__hint--under"><?php esc_html_e( 'خالی گذاشتن یعنی کلید ذخیره‌شده تغییر نمی‌کند. روش امن‌تر: تعریف ثابت PWC_API_SECRET در wp-config.php.', 'persian-wp-chatbot' ); ?></small>
						<?php endif; ?>
					</label>
					<?php if ( ! $pwc_key_const && '' !== $pwc_key_mask ) : ?>
						<div class="pwc-chatset__f pwc-chatset__wide">
							<label class="pwc-check">
								<input type="checkbox" class="pwc-tick" name="<?php echo esc_attr( $pwc_name( 'api_key_clear' ) ); ?>" value="1">
								<span><?php esc_html_e( 'کلید ذخیره‌شده حذف شود', 'persian-wp-chatbot' ); ?></span>
							</label>
						</div>
					<?php endif; ?>
				</div>
			</section>
			</div>

			<div class="pwc-setpane pwc-stack" id="pwc-setpane-extraction" data-setpane="extraction" hidden>
			<section class="pwc-card pwc-autosync" data-role="autosync">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'استخراج خودکار اطلاعات سایت', 'persian-wp-chatbot' ); ?></h2></div>
				<p class="pwc-note"><?php esc_html_e( 'برای هر دسته مشخص کنید وقتی مطلبی در سایت اضافه و منتشر شد (یا ویرایش شد) چه اتفاقی بیفتد. هر تغییری که بدهید با زدن دکمهٔ «ذخیرهٔ تغییرات» (پایین صفحه) ذخیره می‌شود.', 'persian-wp-chatbot' ); ?></p>
				<input type="hidden" name="<?php echo esc_attr( SyncSettings::FORM_MARKER ); ?>" value="1">
				<?php foreach ( $pwc_sources as $pwc_id => $pwc_source ) : ?>
					<?php $pwc_row = $pwc_sync[ $pwc_id ] ?? array( 'add' => false, 'train' => false ); ?>
					<fieldset class="pwc-autosync__row" data-role="sync-row" data-source="<?php echo esc_attr( (string) $pwc_id ); ?>">
						<legend class="pwc-autosync__name"><?php echo esc_html( $pwc_source['label'] ); ?></legend>
						<label class="pwc-check">
							<input type="checkbox" class="pwc-tick" data-role="sync-add" name="<?php echo esc_attr( SyncSettings::FORM_FIELD . '[' . $pwc_id . '][add]' ); ?>" value="1" <?php checked( ! empty( $pwc_row['add'] ) ); ?>>
							<span><?php esc_html_e( 'به‌محض افزودن و ویرایش کردن در سایت، به لیست مطالب افزونهٔ هوش مصنوعی اضافه (و به‌روز) شود', 'persian-wp-chatbot' ); ?></span>
						</label>
						<label class="pwc-check">
							<input type="checkbox" class="pwc-tick" data-role="sync-train" name="<?php echo esc_attr( SyncSettings::FORM_FIELD . '[' . $pwc_id . '][train]' ); ?>" value="1" <?php checked( ! empty( $pwc_row['train'] ) ); ?> <?php disabled( empty( $pwc_row['add'] ) ); ?>>
							<span><?php esc_html_e( 'به‌محض افزودن و ویرایش کردن در سایت، بلافاصله به هوش مصنوعی آموزش داده شود', 'persian-wp-chatbot' ); ?></span>
						</label>
						<small class="pwc-autosync__hint"><?php esc_html_e( 'برای فعال شدن گزینهٔ دوم، اول باید گزینهٔ اول تیک خورده باشد.', 'persian-wp-chatbot' ); ?></small>
					</fieldset>
				<?php endforeach; ?>
			</section>
			</div>

			<div class="pwc-setpane pwc-stack" id="pwc-setpane-chat" data-setpane="chat" hidden>
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'مدیریت چت', 'persian-wp-chatbot' ); ?></h2></div>
				<div class="pwc-chatset__grid">
					<label class="pwc-check pwc-chatset__wide">
						<input type="checkbox" class="pwc-tick" name="<?php echo esc_attr( $pwc_name( 'enabled' ) ); ?>" value="1" <?php checked( ! empty( $pwc_s['enabled'] ) ); ?>>
						<span><?php esc_html_e( 'نمایش آیکن چت در همهٔ صفحه‌های سایت', 'persian-wp-chatbot' ); ?></span>
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'محل آیکن', 'persian-wp-chatbot' ); ?></span>
						<select name="<?php echo esc_attr( $pwc_name( 'position' ) ); ?>">
							<option value="right" <?php selected( $pwc_s['position'], 'right' ); ?>><?php esc_html_e( 'پایین سمت راست', 'persian-wp-chatbot' ); ?></option>
							<option value="left" <?php selected( $pwc_s['position'], 'left' ); ?>><?php esc_html_e( 'پایین سمت چپ', 'persian-wp-chatbot' ); ?></option>
						</select>
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'نام دستیار', 'persian-wp-chatbot' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $pwc_name( 'bot_name' ) ); ?>" value="<?php echo esc_attr( $pwc_s['bot_name'] ); ?>">
					</label>
					<div class="pwc-chatset__f pwc-chatset__wide pwc-botlogo" data-pwc-logo data-title="<?php esc_attr_e( 'انتخاب لوگوی چت', 'persian-wp-chatbot' ); ?>" data-button="<?php esc_attr_e( 'استفاده به عنوان لوگو', 'persian-wp-chatbot' ); ?>">
						<span><?php esc_html_e( 'لوگوی بالای چت', 'persian-wp-chatbot' ); ?></span>
						<div class="pwc-botlogo__row">
							<div class="pwc-botlogo__preview">
								<img class="pwc-botlogo__img" alt="" <?php echo ! empty( $pwc_s['bot_logo'] ) ? 'src="' . esc_url( $pwc_s['bot_logo'] ) . '"' : 'hidden'; ?>>
								<em class="pwc-botlogo__empty" <?php echo ! empty( $pwc_s['bot_logo'] ) ? 'hidden' : ''; ?>><?php esc_html_e( 'پیش‌فرض', 'persian-wp-chatbot' ); ?></em>
							</div>
							<button type="button" class="pwc-btn pwc-btn--ghost" data-logo-pick><span class="pwc-btn__label"><?php esc_html_e( 'انتخاب / تغییر لوگو', 'persian-wp-chatbot' ); ?></span></button>
							<button type="button" class="pwc-btn pwc-btn--ghost" data-logo-clear <?php echo empty( $pwc_s['bot_logo'] ) ? 'hidden' : ''; ?>><span class="pwc-btn__label"><?php esc_html_e( 'حذف لوگو', 'persian-wp-chatbot' ); ?></span></button>
						</div>
						<input type="hidden" name="<?php echo esc_attr( $pwc_name( 'bot_logo' ) ); ?>" value="<?php echo esc_attr( (string) $pwc_s['bot_logo'] ); ?>">
						<small class="pwc-chatset__hint"><?php esc_html_e( 'تصویر کوچکِ بالای پنجرهٔ چت در صفحه‌های سایت. اگر لوگویی انتخاب نشود، آیکن پیش‌فرض نمایش داده می‌شود. با دکمهٔ «ذخیرهٔ تغییرات» اعمال می‌شود.', 'persian-wp-chatbot' ); ?></small>
					</div>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'متن بالای آیکن (تیتر)', 'persian-wp-chatbot' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $pwc_name( 'teaser_title' ) ); ?>" value="<?php echo esc_attr( $pwc_s['teaser_title'] ); ?>">
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'متن بالای آیکن (توضیح)', 'persian-wp-chatbot' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $pwc_name( 'teaser_text' ) ); ?>" value="<?php echo esc_attr( $pwc_s['teaser_text'] ); ?>">
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'پیام اولِ فرم نام و تماس', 'persian-wp-chatbot' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $pwc_name( 'profile_intro' ) ); ?>" value="<?php echo esc_attr( $pwc_s['profile_intro'] ); ?>">
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'پیام خوش‌آمدگویی', 'persian-wp-chatbot' ); ?></span>
						<textarea rows="2" name="<?php echo esc_attr( $pwc_name( 'welcome_message' ) ); ?>"><?php echo esc_textarea( $pwc_s['welcome_message'] ); ?></textarea>
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'پیشنهادهای پیش‌فرض پیام (هر خط یک پیشنهاد)', 'persian-wp-chatbot' ); ?></span>
						<textarea rows="3" name="<?php echo esc_attr( $pwc_name( 'default_suggestions' ) ); ?>"><?php echo esc_textarea( $pwc_s['default_suggestions'] ); ?></textarea>
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'پاسخ پیش‌فرض به پیام اول', 'persian-wp-chatbot' ); ?></span>
						<textarea rows="2" name="<?php echo esc_attr( $pwc_name( 'fallback_reply' ) ); ?>"><?php echo esc_textarea( $pwc_s['fallback_reply'] ); ?></textarea>
						<small class="pwc-chatset__hint"><?php esc_html_e( 'فقط وقتی کاربر برای اولین بار پیام می‌دهد (بعد از تمام شدن تایپش) این متن، مثلاً «ثبت شد»، ارسال می‌شود. برای پیام‌های بعدی ارسال نمی‌شود. اگر خالی بگذارید هیچ پیامی ارسال نمی‌شود.', 'persian-wp-chatbot' ); ?></small>
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'تأخیر هوش مصنوعی (ثانیه)', 'persian-wp-chatbot' ); ?></span>
						<input type="number" min="0" max="60" name="<?php echo esc_attr( $pwc_name( 'ai_delay' ) ); ?>" value="<?php echo esc_attr( (string) $pwc_s['ai_delay'] ); ?>">
						<small class="pwc-chatset__hint"><?php esc_html_e( 'اگر کاربر تا این مدت تایپ نکند، یعنی پیام‌هایش تمام شده؛ آن‌وقت همهٔ پیام‌های جمع‌شده یک‌جا برای هوش مصنوعی فرستاده می‌شود. ۰ = بلافاصله پاسخ داده شود.', 'persian-wp-chatbot' ); ?></small>
					</label>
				</div>
			</section>
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'وضعیت آنلاین', 'persian-wp-chatbot' ); ?></h2></div>
				<div class="pwc-chatset__grid">
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'متن زیر نام دستیار (وقتی آنلاین است)', 'persian-wp-chatbot' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $pwc_name( 'online_text' ) ); ?>" value="<?php echo esc_attr( $pwc_s['online_text'] ); ?>">
					</label>
					<label class="pwc-check pwc-chatset__wide">
						<input type="checkbox" class="pwc-tick" name="<?php echo esc_attr( $pwc_name( 'online_schedule' ) ); ?>" value="1" <?php checked( ! empty( $pwc_s['online_schedule'] ) ); ?>>
						<span><?php esc_html_e( 'آیکن آنلاین فقط در ساعت‌های مشخص‌شده نمایش داده شود', 'persian-wp-chatbot' ); ?></span>
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'از ساعت', 'persian-wp-chatbot' ); ?></span>
						<input type="time" dir="ltr" name="<?php echo esc_attr( $pwc_name( 'online_from' ) ); ?>" value="<?php echo esc_attr( $pwc_s['online_from'] ); ?>">
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'تا ساعت', 'persian-wp-chatbot' ); ?></span>
						<input type="time" dir="ltr" name="<?php echo esc_attr( $pwc_name( 'online_to' ) ); ?>" value="<?php echo esc_attr( $pwc_s['online_to'] ); ?>">
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'متن زیر نام دستیار (خارج از ساعت آنلاین)', 'persian-wp-chatbot' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $pwc_name( 'offline_text' ) ); ?>" value="<?php echo esc_attr( $pwc_s['offline_text'] ); ?>">
						<small class="pwc-chatset__hint"><?php esc_html_e( 'ساعت‌ها بر اساس منطقهٔ زمانی سایت (تنظیمات عمومی وردپرس) حساب می‌شود. بازهٔ شبانه‌روزی مثل ۲۲:۰۰ تا ۰۶:۰۰ هم پشتیبانی می‌شود.', 'persian-wp-chatbot' ); ?></small>
					</label>
				</div>
			</section>
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'رنگ‌بندی چت', 'persian-wp-chatbot' ); ?></h2></div>

				<div class="pwc-colorgroup">
					<div class="pwc-colorgroup__head"><h3 class="pwc-colorgroup__title"><?php esc_html_e( 'تم روشن', 'persian-wp-chatbot' ); ?></h3></div>
					<div class="pwc-chatset__colors">
						<?php
						$pwc_colors = array(
							'color_primary' => __( 'رنگ اصلی', 'persian-wp-chatbot' ),
							'color_accent'  => __( 'رنگ تأکیدی', 'persian-wp-chatbot' ),
							'color_surface' => __( 'پس‌زمینه (روشن)', 'persian-wp-chatbot' ),
							'color_text'    => __( 'متن (تیره)', 'persian-wp-chatbot' ),
							'color_field'   => __( 'کادر نوشتن پیام (تکست‌باکس)', 'persian-wp-chatbot' ),
						);
						foreach ( $pwc_colors as $pwc_key => $pwc_label ) :
							?>
							<label class="pwc-chatset__color">
								<input type="color" name="<?php echo esc_attr( $pwc_name( $pwc_key ) ); ?>" value="<?php echo esc_attr( $pwc_s[ $pwc_key ] ); ?>">
								<span><?php echo esc_html( $pwc_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="pwc-colorgroup<?php echo empty( $pwc_s['dark_enabled'] ) ? ' is-disabled' : ''; ?>" data-role="dark-group">
					<div class="pwc-colorgroup__head">
						<h3 class="pwc-colorgroup__title"><?php esc_html_e( 'تم دارک', 'persian-wp-chatbot' ); ?></h3>
						<label class="pwc-check">
							<input type="checkbox" class="pwc-tick" data-role="dark-toggle" name="<?php echo esc_attr( $pwc_name( 'dark_enabled' ) ); ?>" value="1" <?php checked( ! empty( $pwc_s['dark_enabled'] ) ); ?>>
							<span><?php esc_html_e( 'فعال‌سازی تم دارک', 'persian-wp-chatbot' ); ?></span>
						</label>
					</div>
					<p class="pwc-colorgroup__desc"><?php esc_html_e( 'وقتی فعال باشد، هر زمان صفحهٔ سایت برای بازدیدکننده تیره باشد (قالب تیرهٔ سایت یا حالت تیرهٔ دستگاه کاربر)، چت به‌صورت خودکار با این رنگ‌ها نمایش داده می‌شود؛ در غیر این صورت رنگ‌های «تم روشن» استفاده می‌شود.', 'persian-wp-chatbot' ); ?></p>
					<div class="pwc-chatset__colors">
						<?php
						$pwc_dark_colors = array(
							'dark_primary' => __( 'رنگ اصلی', 'persian-wp-chatbot' ),
							'dark_accent'  => __( 'رنگ تأکیدی', 'persian-wp-chatbot' ),
							'dark_surface' => __( 'پس‌زمینه (تیره)', 'persian-wp-chatbot' ),
							'dark_text'    => __( 'متن (روشن)', 'persian-wp-chatbot' ),
							'dark_field'   => __( 'کادر نوشتن پیام (تکست‌باکس)', 'persian-wp-chatbot' ),
						);
						foreach ( $pwc_dark_colors as $pwc_key => $pwc_label ) :
							?>
							<label class="pwc-chatset__color">
								<input type="color" name="<?php echo esc_attr( $pwc_name( $pwc_key ) ); ?>" value="<?php echo esc_attr( $pwc_s[ $pwc_key ] ); ?>">
								<span><?php echo esc_html( $pwc_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'ارسال فایل', 'persian-wp-chatbot' ); ?></h2></div>
				<div class="pwc-chatset__grid">
					<label class="pwc-check">
						<input type="checkbox" class="pwc-tick" name="<?php echo esc_attr( $pwc_name( 'allow_images' ) ); ?>" value="1" <?php checked( ! empty( $pwc_s['allow_images'] ) ); ?>>
						<span><?php esc_html_e( 'ارسال عکس', 'persian-wp-chatbot' ); ?></span>
					</label>
					<label class="pwc-check">
						<input type="checkbox" class="pwc-tick" name="<?php echo esc_attr( $pwc_name( 'allow_voice' ) ); ?>" value="1" <?php checked( ! empty( $pwc_s['allow_voice'] ) ); ?>>
						<span><?php esc_html_e( 'ارسال ویس (نیاز به HTTPS)', 'persian-wp-chatbot' ); ?></span>
					</label>
					<label class="pwc-chatset__f">
						<span><?php esc_html_e( 'حداکثر حجم فایل (مگابایت)', 'persian-wp-chatbot' ); ?></span>
						<input type="number" min="1" max="20" name="<?php echo esc_attr( $pwc_name( 'max_upload_mb' ) ); ?>" value="<?php echo esc_attr( (string) $pwc_s['max_upload_mb'] ); ?>">
					</label>
				</div>
			</section>
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'بهینه‌سازی چت‌بات', 'persian-wp-chatbot' ); ?></h2></div>
				<div class="pwc-chatset__opts" role="radiogroup" aria-label="<?php esc_attr_e( 'بهینه‌سازی چت‌بات', 'persian-wp-chatbot' ); ?>">
					<label class="pwc-opt">
						<input type="radio" name="<?php echo esc_attr( $pwc_name( 'optimize' ) ); ?>" value="advanced" <?php checked( 'optimized' !== $pwc_s['optimize'] ); ?>>
						<span class="pwc-opt__card">
							<span class="pwc-opt__radio" aria-hidden="true"></span>
							<span class="pwc-opt__text">
								<strong><?php esc_html_e( 'پیشرفته', 'persian-wp-chatbot' ); ?></strong>
								<small><?php esc_html_e( 'به‌روزرسانی لحظه‌ای و سریع‌ترین پاسخ‌گویی. مناسب هاست‌ها و سرورهای قوی.', 'persian-wp-chatbot' ); ?></small>
							</span>
						</span>
					</label>
					<label class="pwc-opt">
						<input type="radio" name="<?php echo esc_attr( $pwc_name( 'optimize' ) ); ?>" value="optimized" <?php checked( 'optimized', $pwc_s['optimize'] ); ?>>
						<span class="pwc-opt__card">
							<span class="pwc-opt__radio" aria-hidden="true"></span>
							<span class="pwc-opt__text">
								<strong><?php esc_html_e( 'بهینه شده', 'persian-wp-chatbot' ); ?></strong>
								<small><?php esc_html_e( 'مخصوص هاست‌های ضعیف: فشار روی سرور کمتر می‌شود.', 'persian-wp-chatbot' ); ?></small>
							</span>
						</span>
					</label>
				</div>
				<p class="pwc-chatset__hint pwc-chatset__hint--block"><?php esc_html_e( 'در حالت «بهینه شده» تمام polling‌ها و به‌طور کلی کوئری‌های مربوط به چت‌بات حدود ۳ تا ۴ برابر کندتر انجام می‌شوند (پیام‌ها کمی دیرتر نمایش داده می‌شوند)، پنل و ویجت در تب پس‌زمینه درخواست نمی‌فرستند و شمارندهٔ پیام‌های نخوانده کش می‌شود تا فشار کمتری به سرور بیاید.', 'persian-wp-chatbot' ); ?></p>
			</section>
			<section class="pwc-card">
				<div class="pwc-card__head"><h2 class="pwc-card__title"><?php esc_html_e( 'پیام‌رسانی لحظه‌ای (WebSocket)', 'persian-wp-chatbot' ); ?></h2></div>
				<div class="pwc-chatset__grid">
					<label class="pwc-check pwc-chatset__wide">
						<input type="checkbox" class="pwc-tick" name="<?php echo esc_attr( $pwc_name( 'realtime_enabled' ) ); ?>" value="1" <?php checked( ! empty( $pwc_s['realtime_enabled'] ) ); ?>>
						<span><?php esc_html_e( 'فعال (اگر خاموش باشد ویجت فقط با polling کار می‌کند)', 'persian-wp-chatbot' ); ?></span>
					</label>
					<label class="pwc-chatset__f pwc-chatset__wide">
						<span><?php esc_html_e( 'آدرس WebSocket (اختیاری؛ خالی = wss://آدرس-سرور/v1/ws)', 'persian-wp-chatbot' ); ?></span>
						<input type="text" dir="ltr" name="<?php echo esc_attr( $pwc_name( 'ws_url' ) ); ?>" value="<?php echo esc_attr( (string) $pwc_s['ws_url'] ); ?>" placeholder="wss://ai.example.com/v1/ws">
					</label>
					<?php
					$pwc_num = array(
						'poll_closed' => __( 'polling چت بسته (ثانیه)', 'persian-wp-chatbot' ),
						'poll_fast'   => __( 'polling چت باز (ثانیه)', 'persian-wp-chatbot' ),
						'poll_mid'    => __( 'بعد از بی‌حرکتی (ثانیه)', 'persian-wp-chatbot' ),
						'poll_max'    => __( 'سقف backoff (ثانیه)', 'persian-wp-chatbot' ),
						'poll_idle'   => __( 'مدت بی‌حرکتی تا backoff (ثانیه)', 'persian-wp-chatbot' ),
						'ws_retry'    => __( 'تلاش مجدد WebSocket در fallback (ثانیه)', 'persian-wp-chatbot' ),
						'rl_poll_per_min'    => __( 'سقف poll در دقیقه (هر بازدیدکننده)', 'persian-wp-chatbot' ),
						'rl_token_per_min'   => __( 'سقف توکن WebSocket در دقیقه', 'persian-wp-chatbot' ),
						'rl_create_per_hour' => __( 'سقف گفتگوی جدید در ساعت (هر IP)', 'persian-wp-chatbot' ),
					);
					foreach ( $pwc_num as $pwc_k => $pwc_label ) :
						?>
						<label class="pwc-chatset__f">
							<span><?php echo esc_html( $pwc_label ); ?></span>
							<input type="number" min="1" name="<?php echo esc_attr( $pwc_name( $pwc_k ) ); ?>" value="<?php echo esc_attr( (string) $pwc_s[ $pwc_k ] ); ?>">
						</label>
					<?php endforeach; ?>
				</div>
				<p class="pwc-chatset__hint pwc-chatset__hint--block"><?php esc_html_e( 'WebSocket فقط «اعلان» می‌فرستد؛ پیام‌ها همچنان از وردپرس خوانده می‌شوند. اگر سرور در دسترس نباشد ویجت خودکار به polling برمی‌گردد.', 'persian-wp-chatbot' ); ?></p>
			</section>
			</div>

		<div class="pwc-actions">
			<button type="submit" class="pwc-btn"><span class="pwc-btn__label"><?php esc_html_e( 'ذخیرهٔ تغییرات', 'persian-wp-chatbot' ); ?></span></button>
		</div>

		</div>
	</div>
</form>
