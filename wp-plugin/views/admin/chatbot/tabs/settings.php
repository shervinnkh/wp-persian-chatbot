<?php
defined( 'ABSPATH' ) || exit;

use PersianAIAssistant\Apis\Client;
use PersianAIAssistant\Chat\ChatSettings;
use PersianAIAssistant\Extraction\Sources;
use PersianAIAssistant\Sync\SyncSettings;

$paia_s    = ChatSettings::all();
$paia_name = static function ( $key ) {
	return ChatSettings::OPTION . '[' . $key . ']';
};
$paia_sync    = SyncSettings::all();
$paia_sources = Sources::all();
$paia_back = admin_url( 'admin.php?page=paia-chatbot' ) . '#settings';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paia_saved = isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'];
?>
<form class="paia-chatset" novalidate method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
	<?php settings_fields( 'paia_chat' ); ?>
	<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $paia_back ); ?>">

	<?php if ( $paia_saved ) : ?>
		<p class="paia-chatset__ok" role="status"><?php esc_html_e( 'تنظیمات ذخیره شد.', 'persian-ai-assistant' ); ?></p>
	<?php endif; ?>

	<div class="paia-setlayout">
		<nav class="paia-setnav" aria-label="<?php esc_attr_e( 'دسته‌بندی تنظیمات', 'persian-ai-assistant' ); ?>">
			<button type="button" class="paia-setnav__item is-active" id="paia-setnav-general" data-set="general" aria-controls="paia-setpane-general" aria-pressed="true"><?php esc_html_e( 'عمومی', 'persian-ai-assistant' ); ?></button>
			<button type="button" class="paia-setnav__item" id="paia-setnav-extraction" data-set="extraction" aria-controls="paia-setpane-extraction" aria-pressed="false"><?php esc_html_e( 'استخراج اطلاعات سایت', 'persian-ai-assistant' ); ?></button>
			<button type="button" class="paia-setnav__item" id="paia-setnav-chat" data-set="chat" aria-controls="paia-setpane-chat" aria-pressed="false"><?php esc_html_e( 'مدیریت چت', 'persian-ai-assistant' ); ?></button>
		</nav>

		<div class="paia-setbody">
			<div class="paia-setpane paia-stack" id="paia-setpane-general" data-setpane="general">
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'اطلاعات کلی سایت', 'persian-ai-assistant' ); ?></h2></div>
				<div class="paia-chatset__grid">
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'عنوان سایت', 'persian-ai-assistant' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $paia_name( 'site_title' ) ); ?>" value="<?php echo esc_attr( ChatSettings::site_title() ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						<small class="paia-chatset__hint paia-chatset__hint--under"><?php esc_html_e( 'به‌صورت پیش‌فرض از عنوان سایت وردپرس گرفته می‌شود؛ اگر خالی بگذارید هم همان استفاده می‌شود.', 'persian-ai-assistant' ); ?></small>
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'توضیح کوتاه درباره عملکرد سایت', 'persian-ai-assistant' ); ?></span>
						<input type="text" maxlength="<?php echo esc_attr( (string) ChatSettings::DESC_MAX ); ?>" name="<?php echo esc_attr( $paia_name( 'site_desc' ) ); ?>" value="<?php echo esc_attr( $paia_s['site_desc'] ); ?>" placeholder="<?php esc_attr_e( 'مثلاً: فروشگاه اینترنتی لوازم خانگی با ارسال به سراسر کشور', 'persian-ai-assistant' ); ?>">
						<small class="paia-chatset__hint paia-chatset__hint--under"><?php esc_html_e( 'فقط یک خط.', 'persian-ai-assistant' ); ?></small>
					</label>
				</div>
			</section>
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'سرویس هوش مصنوعی', 'persian-ai-assistant' ); ?></h2></div>
				<div class="paia-chatset__grid paia-chatset__grid--select">
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'انتخاب نوع سرویس', 'persian-ai-assistant' ); ?></span>
						<select name="<?php echo esc_attr( $paia_name( 'ai_service' ) ); ?>">
							<?php foreach ( ChatSettings::ai_services() as $paia_key => $paia_label ) : ?>
								<option value="<?php echo esc_attr( (string) $paia_key ); ?>" <?php selected( $paia_s['ai_service'], $paia_key ); ?>><?php echo esc_html( $paia_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'انتخاب لحن صحبت', 'persian-ai-assistant' ); ?></span>
						<select name="<?php echo esc_attr( $paia_name( 'tone' ) ); ?>">
							<?php foreach ( array( 'friendly', 'formal' ) as $paia_key ) : ?>
								<option value="<?php echo esc_attr( $paia_key ); ?>" <?php selected( $paia_s['tone'], $paia_key ); ?>><?php echo esc_html( ChatSettings::tones()[ $paia_key ] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'سقف مصرف روزانه (دلار)', 'persian-ai-assistant' ); ?></span>
						<input type="number" min="0" max="100000" step="0.01" name="<?php echo esc_attr( $paia_name( 'budget_daily_usd' ) ); ?>" value="<?php echo esc_attr( (string) $paia_s['budget_daily_usd'] ); ?>">
						<small class="paia-chatset__hint paia-chatset__hint--under"><?php esc_html_e( '۰ = بدون محدودیت. مصرف به دلار حساب می‌شود.', 'persian-ai-assistant' ); ?></small>
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'سقف مصرف ماهانه (دلار)', 'persian-ai-assistant' ); ?></span>
						<input type="number" min="0" max="100000" step="0.01" name="<?php echo esc_attr( $paia_name( 'budget_monthly_usd' ) ); ?>" value="<?php echo esc_attr( (string) $paia_s['budget_monthly_usd'] ); ?>">
						<small class="paia-chatset__hint paia-chatset__hint--under"><?php esc_html_e( '۰ = بدون محدودیت. مصرف به دلار حساب می‌شود.', 'persian-ai-assistant' ); ?></small>
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'توضیحات مهم', 'persian-ai-assistant' ); ?></span>
						<textarea class="paia-chatset__notes" rows="6" maxlength="<?php echo esc_attr( (string) ChatSettings::NOTES_MAX ); ?>" name="<?php echo esc_attr( $paia_name( 'important_notes' ) ); ?>"><?php echo esc_textarea( $paia_s['important_notes'] ); ?></textarea>
						<small class="paia-chatset__hint paia-chatset__hint--under"><?php esc_html_e( 'فقط توضیحاتی که خیلی مهم هست و هوش مصنوعی در هر بار پاسخگویی به مشتریان باید آن را بداند بنویسید. برای بالا نرفتن مصرف توکن حتماً جملات کوتاه و محدود را وارد کنید.', 'persian-ai-assistant' ); ?></small>
					</label>
					<div class="paia-chatset__f paia-chatset__wide">
						<label class="paia-check">
							<input type="checkbox" class="paia-tick" name="<?php echo esc_attr( $paia_name( 'ai_mark' ) ); ?>" value="1" <?php checked( ! empty( $paia_s['ai_mark'] ) ); ?>>
							<span><?php esc_html_e( 'مارک کردن چت توسط هوش مصنوعی', 'persian-ai-assistant' ); ?></span>
						</label>
						<small class="paia-chatset__hint paia-chatset__hint--under"><?php esc_html_e( 'اگه فعال باشه اگه هوش مصنوعی نتونه جوابی درباره پرسش کاربر بده هیچ جوابی نده و فقط صفحه چت رو مارک کنه تا پشتیبانان اون رو جواب بدن', 'persian-ai-assistant' ); ?></small>
					</div>
				</div>
			</section>
			<?php
			// اتصال به سرور هوش مصنوعی (S-5): روش اصلی ثابت‌های wp-config.php است؛ با تعریف PAIA_API_SECRET هیچ مقداری از کلید
			// در دیتابیس ذخیره یا در این صفحه چاپ نمی‌شود. وگرنه فقط شکل ماسک‌شده (•••• + ۴ نویسهٔ آخر) دیده می‌شود.
			$paia_key_const = Client::secret_from_constant();
			$paia_url_const = defined( 'PAIA_API_URL' ) && '' !== (string) PAIA_API_URL;
			$paia_key_mask  = ChatSettings::masked_key();
			?>
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'اتصال به سرور هوش مصنوعی', 'persian-ai-assistant' ); ?></h2></div>
				<div class="paia-chatset__grid">
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'آدرس سرور', 'persian-ai-assistant' ); ?></span>
						<?php if ( $paia_url_const ) : ?>
							<input type="text" dir="ltr" value="<?php esc_attr_e( 'از wp-config.php خوانده می‌شود', 'persian-ai-assistant' ); ?>" disabled>
						<?php else : ?>
							<input type="url" dir="ltr" autocomplete="off" name="<?php echo esc_attr( $paia_name( 'api_url' ) ); ?>" value="<?php echo esc_attr( (string) $paia_s['api_url'] ); ?>" placeholder="https://ai.example.com">
						<?php endif; ?>
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'کلید اتصال', 'persian-ai-assistant' ); ?></span>
						<?php if ( $paia_key_const ) : ?>
							<input type="text" dir="ltr" value="<?php esc_attr_e( 'از wp-config.php خوانده می‌شود', 'persian-ai-assistant' ); ?>" disabled>
							<small class="paia-chatset__hint paia-chatset__hint--under"><?php esc_html_e( 'کلید با ثابت PAIA_API_SECRET در wp-config.php تعریف شده است؛ در دیتابیس ذخیره و در این صفحه نمایش داده نمی‌شود.', 'persian-ai-assistant' ); ?></small>
						<?php else : ?>
							<input type="password" dir="ltr" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr( $paia_name( 'api_key' ) ); ?>" value="" placeholder="<?php echo esc_attr( '' !== $paia_key_mask ? $paia_key_mask : __( 'کلید را وارد کنید', 'persian-ai-assistant' ) ); ?>">
							<small class="paia-chatset__hint paia-chatset__hint--under"><?php esc_html_e( 'خالی گذاشتن یعنی کلید ذخیره‌شده تغییر نمی‌کند. روش امن‌تر: تعریف ثابت PAIA_API_SECRET در wp-config.php.', 'persian-ai-assistant' ); ?></small>
						<?php endif; ?>
					</label>
					<?php if ( ! $paia_key_const && '' !== $paia_key_mask ) : ?>
						<div class="paia-chatset__f paia-chatset__wide">
							<label class="paia-check">
								<input type="checkbox" class="paia-tick" name="<?php echo esc_attr( $paia_name( 'api_key_clear' ) ); ?>" value="1">
								<span><?php esc_html_e( 'کلید ذخیره‌شده حذف شود', 'persian-ai-assistant' ); ?></span>
							</label>
						</div>
					<?php endif; ?>
				</div>
			</section>
			</div>

			<div class="paia-setpane paia-stack" id="paia-setpane-extraction" data-setpane="extraction" hidden>
			<section class="paia-card paia-autosync" data-role="autosync">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'استخراج خودکار اطلاعات سایت', 'persian-ai-assistant' ); ?></h2></div>
				<p class="paia-note"><?php esc_html_e( 'برای هر دسته مشخص کنید وقتی مطلبی در سایت اضافه و منتشر شد (یا ویرایش شد) چه اتفاقی بیفتد. هر تغییری که بدهید با زدن دکمهٔ «ذخیرهٔ تغییرات» (پایین صفحه) ذخیره می‌شود.', 'persian-ai-assistant' ); ?></p>
				<input type="hidden" name="<?php echo esc_attr( SyncSettings::FORM_MARKER ); ?>" value="1">
				<?php foreach ( $paia_sources as $paia_id => $paia_source ) : ?>
					<?php $paia_row = $paia_sync[ $paia_id ] ?? array( 'add' => false, 'train' => false ); ?>
					<fieldset class="paia-autosync__row" data-role="sync-row" data-source="<?php echo esc_attr( (string) $paia_id ); ?>">
						<legend class="paia-autosync__name"><?php echo esc_html( $paia_source['label'] ); ?></legend>
						<label class="paia-check">
							<input type="checkbox" class="paia-tick" data-role="sync-add" name="<?php echo esc_attr( SyncSettings::FORM_FIELD . '[' . $paia_id . '][add]' ); ?>" value="1" <?php checked( ! empty( $paia_row['add'] ) ); ?>>
							<span><?php esc_html_e( 'به‌محض افزودن و ویرایش کردن در سایت، به لیست مطالب افزونهٔ هوش مصنوعی اضافه (و به‌روز) شود', 'persian-ai-assistant' ); ?></span>
						</label>
						<label class="paia-check">
							<input type="checkbox" class="paia-tick" data-role="sync-train" name="<?php echo esc_attr( SyncSettings::FORM_FIELD . '[' . $paia_id . '][train]' ); ?>" value="1" <?php checked( ! empty( $paia_row['train'] ) ); ?> <?php disabled( empty( $paia_row['add'] ) ); ?>>
							<span><?php esc_html_e( 'به‌محض افزودن و ویرایش کردن در سایت، بلافاصله به هوش مصنوعی آموزش داده شود', 'persian-ai-assistant' ); ?></span>
						</label>
						<small class="paia-autosync__hint"><?php esc_html_e( 'برای فعال شدن گزینهٔ دوم، اول باید گزینهٔ اول تیک خورده باشد.', 'persian-ai-assistant' ); ?></small>
					</fieldset>
				<?php endforeach; ?>
			</section>
			</div>

			<div class="paia-setpane paia-stack" id="paia-setpane-chat" data-setpane="chat" hidden>
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'مدیریت چت', 'persian-ai-assistant' ); ?></h2></div>
				<div class="paia-chatset__grid">
					<label class="paia-check paia-chatset__wide">
						<input type="checkbox" class="paia-tick" name="<?php echo esc_attr( $paia_name( 'enabled' ) ); ?>" value="1" <?php checked( ! empty( $paia_s['enabled'] ) ); ?>>
						<span><?php esc_html_e( 'نمایش آیکن چت در همهٔ صفحه‌های سایت', 'persian-ai-assistant' ); ?></span>
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'محل آیکن', 'persian-ai-assistant' ); ?></span>
						<select name="<?php echo esc_attr( $paia_name( 'position' ) ); ?>">
							<option value="right" <?php selected( $paia_s['position'], 'right' ); ?>><?php esc_html_e( 'پایین سمت راست', 'persian-ai-assistant' ); ?></option>
							<option value="left" <?php selected( $paia_s['position'], 'left' ); ?>><?php esc_html_e( 'پایین سمت چپ', 'persian-ai-assistant' ); ?></option>
						</select>
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'نام دستیار', 'persian-ai-assistant' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $paia_name( 'bot_name' ) ); ?>" value="<?php echo esc_attr( $paia_s['bot_name'] ); ?>">
					</label>
					<div class="paia-chatset__f paia-chatset__wide paia-botlogo" data-paia-logo data-title="<?php esc_attr_e( 'انتخاب لوگوی چت', 'persian-ai-assistant' ); ?>" data-button="<?php esc_attr_e( 'استفاده به عنوان لوگو', 'persian-ai-assistant' ); ?>">
						<span><?php esc_html_e( 'لوگوی بالای چت', 'persian-ai-assistant' ); ?></span>
						<div class="paia-botlogo__row">
							<div class="paia-botlogo__preview">
								<img class="paia-botlogo__img" alt="" <?php echo ! empty( $paia_s['bot_logo'] ) ? 'src="' . esc_url( $paia_s['bot_logo'] ) . '"' : 'hidden'; ?>>
								<em class="paia-botlogo__empty" <?php echo ! empty( $paia_s['bot_logo'] ) ? 'hidden' : ''; ?>><?php esc_html_e( 'پیش‌فرض', 'persian-ai-assistant' ); ?></em>
							</div>
							<button type="button" class="paia-btn paia-btn--ghost" data-logo-pick><span class="paia-btn__label"><?php esc_html_e( 'انتخاب / تغییر لوگو', 'persian-ai-assistant' ); ?></span></button>
							<button type="button" class="paia-btn paia-btn--ghost" data-logo-clear <?php echo empty( $paia_s['bot_logo'] ) ? 'hidden' : ''; ?>><span class="paia-btn__label"><?php esc_html_e( 'حذف لوگو', 'persian-ai-assistant' ); ?></span></button>
						</div>
						<input type="hidden" name="<?php echo esc_attr( $paia_name( 'bot_logo' ) ); ?>" value="<?php echo esc_attr( (string) $paia_s['bot_logo'] ); ?>">
						<small class="paia-chatset__hint"><?php esc_html_e( 'تصویر کوچکِ بالای پنجرهٔ چت در صفحه‌های سایت. اگر لوگویی انتخاب نشود، آیکن پیش‌فرض نمایش داده می‌شود. با دکمهٔ «ذخیرهٔ تغییرات» اعمال می‌شود.', 'persian-ai-assistant' ); ?></small>
					</div>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'متن بالای آیکن (تیتر)', 'persian-ai-assistant' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $paia_name( 'teaser_title' ) ); ?>" value="<?php echo esc_attr( $paia_s['teaser_title'] ); ?>">
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'متن بالای آیکن (توضیح)', 'persian-ai-assistant' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $paia_name( 'teaser_text' ) ); ?>" value="<?php echo esc_attr( $paia_s['teaser_text'] ); ?>">
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'پیام اولِ فرم نام و تماس', 'persian-ai-assistant' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $paia_name( 'profile_intro' ) ); ?>" value="<?php echo esc_attr( $paia_s['profile_intro'] ); ?>">
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'پیام خوش‌آمدگویی', 'persian-ai-assistant' ); ?></span>
						<textarea rows="2" name="<?php echo esc_attr( $paia_name( 'welcome_message' ) ); ?>"><?php echo esc_textarea( $paia_s['welcome_message'] ); ?></textarea>
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'پیشنهادهای پیش‌فرض پیام (هر خط یک پیشنهاد)', 'persian-ai-assistant' ); ?></span>
						<textarea rows="3" name="<?php echo esc_attr( $paia_name( 'default_suggestions' ) ); ?>"><?php echo esc_textarea( $paia_s['default_suggestions'] ); ?></textarea>
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'پاسخ پیش‌فرض به پیام اول', 'persian-ai-assistant' ); ?></span>
						<textarea rows="2" name="<?php echo esc_attr( $paia_name( 'fallback_reply' ) ); ?>"><?php echo esc_textarea( $paia_s['fallback_reply'] ); ?></textarea>
						<small class="paia-chatset__hint"><?php esc_html_e( 'فقط وقتی کاربر برای اولین بار پیام می‌دهد (بعد از تمام شدن تایپش) این متن، مثلاً «ثبت شد»، ارسال می‌شود. برای پیام‌های بعدی ارسال نمی‌شود. اگر خالی بگذارید هیچ پیامی ارسال نمی‌شود.', 'persian-ai-assistant' ); ?></small>
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'تأخیر هوش مصنوعی (ثانیه)', 'persian-ai-assistant' ); ?></span>
						<input type="number" min="0" max="60" name="<?php echo esc_attr( $paia_name( 'ai_delay' ) ); ?>" value="<?php echo esc_attr( (string) $paia_s['ai_delay'] ); ?>">
						<small class="paia-chatset__hint"><?php esc_html_e( 'اگر کاربر تا این مدت تایپ نکند، یعنی پیام‌هایش تمام شده؛ آن‌وقت همهٔ پیام‌های جمع‌شده یک‌جا برای هوش مصنوعی فرستاده می‌شود. ۰ = بلافاصله پاسخ داده شود.', 'persian-ai-assistant' ); ?></small>
					</label>
				</div>
			</section>
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'وضعیت آنلاین', 'persian-ai-assistant' ); ?></h2></div>
				<div class="paia-chatset__grid">
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'متن زیر نام دستیار (وقتی آنلاین است)', 'persian-ai-assistant' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $paia_name( 'online_text' ) ); ?>" value="<?php echo esc_attr( $paia_s['online_text'] ); ?>">
					</label>
					<label class="paia-check paia-chatset__wide">
						<input type="checkbox" class="paia-tick" name="<?php echo esc_attr( $paia_name( 'online_schedule' ) ); ?>" value="1" <?php checked( ! empty( $paia_s['online_schedule'] ) ); ?>>
						<span><?php esc_html_e( 'آیکن آنلاین فقط در ساعت‌های مشخص‌شده نمایش داده شود', 'persian-ai-assistant' ); ?></span>
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'از ساعت', 'persian-ai-assistant' ); ?></span>
						<input type="time" dir="ltr" name="<?php echo esc_attr( $paia_name( 'online_from' ) ); ?>" value="<?php echo esc_attr( $paia_s['online_from'] ); ?>">
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'تا ساعت', 'persian-ai-assistant' ); ?></span>
						<input type="time" dir="ltr" name="<?php echo esc_attr( $paia_name( 'online_to' ) ); ?>" value="<?php echo esc_attr( $paia_s['online_to'] ); ?>">
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'متن زیر نام دستیار (خارج از ساعت آنلاین)', 'persian-ai-assistant' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $paia_name( 'offline_text' ) ); ?>" value="<?php echo esc_attr( $paia_s['offline_text'] ); ?>">
						<small class="paia-chatset__hint"><?php esc_html_e( 'ساعت‌ها بر اساس منطقهٔ زمانی سایت (تنظیمات عمومی وردپرس) حساب می‌شود. بازهٔ شبانه‌روزی مثل ۲۲:۰۰ تا ۰۶:۰۰ هم پشتیبانی می‌شود.', 'persian-ai-assistant' ); ?></small>
					</label>
				</div>
			</section>
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'رنگ‌بندی چت', 'persian-ai-assistant' ); ?></h2></div>

				<div class="paia-colorgroup">
					<div class="paia-colorgroup__head"><h3 class="paia-colorgroup__title"><?php esc_html_e( 'تم روشن', 'persian-ai-assistant' ); ?></h3></div>
					<div class="paia-chatset__colors">
						<?php
						$paia_colors = array(
							'color_primary' => __( 'رنگ اصلی', 'persian-ai-assistant' ),
							'color_accent'  => __( 'رنگ تأکیدی', 'persian-ai-assistant' ),
							'color_surface' => __( 'پس‌زمینه (روشن)', 'persian-ai-assistant' ),
							'color_text'    => __( 'متن (تیره)', 'persian-ai-assistant' ),
							'color_field'   => __( 'کادر نوشتن پیام (تکست‌باکس)', 'persian-ai-assistant' ),
						);
						foreach ( $paia_colors as $paia_key => $paia_label ) :
							?>
							<label class="paia-chatset__color">
								<input type="color" name="<?php echo esc_attr( $paia_name( $paia_key ) ); ?>" value="<?php echo esc_attr( $paia_s[ $paia_key ] ); ?>">
								<span><?php echo esc_html( $paia_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="paia-colorgroup<?php echo empty( $paia_s['dark_enabled'] ) ? ' is-disabled' : ''; ?>" data-role="dark-group">
					<div class="paia-colorgroup__head">
						<h3 class="paia-colorgroup__title"><?php esc_html_e( 'تم دارک', 'persian-ai-assistant' ); ?></h3>
						<label class="paia-check">
							<input type="checkbox" class="paia-tick" data-role="dark-toggle" name="<?php echo esc_attr( $paia_name( 'dark_enabled' ) ); ?>" value="1" <?php checked( ! empty( $paia_s['dark_enabled'] ) ); ?>>
							<span><?php esc_html_e( 'فعال‌سازی تم دارک', 'persian-ai-assistant' ); ?></span>
						</label>
					</div>
					<p class="paia-colorgroup__desc"><?php esc_html_e( 'وقتی فعال باشد، هر زمان صفحهٔ سایت برای بازدیدکننده تیره باشد (قالب تیرهٔ سایت یا حالت تیرهٔ دستگاه کاربر)، چت به‌صورت خودکار با این رنگ‌ها نمایش داده می‌شود؛ در غیر این صورت رنگ‌های «تم روشن» استفاده می‌شود.', 'persian-ai-assistant' ); ?></p>
					<div class="paia-chatset__colors">
						<?php
						$paia_dark_colors = array(
							'dark_primary' => __( 'رنگ اصلی', 'persian-ai-assistant' ),
							'dark_accent'  => __( 'رنگ تأکیدی', 'persian-ai-assistant' ),
							'dark_surface' => __( 'پس‌زمینه (تیره)', 'persian-ai-assistant' ),
							'dark_text'    => __( 'متن (روشن)', 'persian-ai-assistant' ),
							'dark_field'   => __( 'کادر نوشتن پیام (تکست‌باکس)', 'persian-ai-assistant' ),
						);
						foreach ( $paia_dark_colors as $paia_key => $paia_label ) :
							?>
							<label class="paia-chatset__color">
								<input type="color" name="<?php echo esc_attr( $paia_name( $paia_key ) ); ?>" value="<?php echo esc_attr( $paia_s[ $paia_key ] ); ?>">
								<span><?php echo esc_html( $paia_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'ارسال فایل', 'persian-ai-assistant' ); ?></h2></div>
				<div class="paia-chatset__grid">
					<label class="paia-check">
						<input type="checkbox" class="paia-tick" name="<?php echo esc_attr( $paia_name( 'allow_images' ) ); ?>" value="1" <?php checked( ! empty( $paia_s['allow_images'] ) ); ?>>
						<span><?php esc_html_e( 'ارسال عکس', 'persian-ai-assistant' ); ?></span>
					</label>
					<label class="paia-check">
						<input type="checkbox" class="paia-tick" name="<?php echo esc_attr( $paia_name( 'allow_voice' ) ); ?>" value="1" <?php checked( ! empty( $paia_s['allow_voice'] ) ); ?>>
						<span><?php esc_html_e( 'ارسال ویس (نیاز به HTTPS)', 'persian-ai-assistant' ); ?></span>
					</label>
					<label class="paia-chatset__f">
						<span><?php esc_html_e( 'حداکثر حجم فایل (مگابایت)', 'persian-ai-assistant' ); ?></span>
						<input type="number" min="1" max="20" name="<?php echo esc_attr( $paia_name( 'max_upload_mb' ) ); ?>" value="<?php echo esc_attr( (string) $paia_s['max_upload_mb'] ); ?>">
					</label>
				</div>
			</section>
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'بهینه‌سازی چت‌بات', 'persian-ai-assistant' ); ?></h2></div>
				<div class="paia-chatset__opts" role="radiogroup" aria-label="<?php esc_attr_e( 'بهینه‌سازی چت‌بات', 'persian-ai-assistant' ); ?>">
					<label class="paia-opt">
						<input type="radio" name="<?php echo esc_attr( $paia_name( 'optimize' ) ); ?>" value="advanced" <?php checked( 'optimized' !== $paia_s['optimize'] ); ?>>
						<span class="paia-opt__card">
							<span class="paia-opt__radio" aria-hidden="true"></span>
							<span class="paia-opt__text">
								<strong><?php esc_html_e( 'پیشرفته', 'persian-ai-assistant' ); ?></strong>
								<small><?php esc_html_e( 'به‌روزرسانی لحظه‌ای و سریع‌ترین پاسخ‌گویی. مناسب هاست‌ها و سرورهای قوی.', 'persian-ai-assistant' ); ?></small>
							</span>
						</span>
					</label>
					<label class="paia-opt">
						<input type="radio" name="<?php echo esc_attr( $paia_name( 'optimize' ) ); ?>" value="optimized" <?php checked( 'optimized', $paia_s['optimize'] ); ?>>
						<span class="paia-opt__card">
							<span class="paia-opt__radio" aria-hidden="true"></span>
							<span class="paia-opt__text">
								<strong><?php esc_html_e( 'بهینه شده', 'persian-ai-assistant' ); ?></strong>
								<small><?php esc_html_e( 'مخصوص هاست‌های ضعیف: فشار روی سرور کمتر می‌شود.', 'persian-ai-assistant' ); ?></small>
							</span>
						</span>
					</label>
				</div>
				<p class="paia-chatset__hint paia-chatset__hint--block"><?php esc_html_e( 'در حالت «بهینه شده» تمام polling‌ها و به‌طور کلی کوئری‌های مربوط به چت‌بات حدود ۳ تا ۴ برابر کندتر انجام می‌شوند (پیام‌ها کمی دیرتر نمایش داده می‌شوند)، پنل و ویجت در تب پس‌زمینه درخواست نمی‌فرستند و شمارندهٔ پیام‌های نخوانده کش می‌شود تا فشار کمتری به سرور بیاید.', 'persian-ai-assistant' ); ?></p>
			</section>
			<section class="paia-card">
				<div class="paia-card__head"><h2 class="paia-card__title"><?php esc_html_e( 'پیام‌رسانی لحظه‌ای (WebSocket)', 'persian-ai-assistant' ); ?></h2></div>
				<div class="paia-chatset__grid">
					<label class="paia-check paia-chatset__wide">
						<input type="checkbox" class="paia-tick" name="<?php echo esc_attr( $paia_name( 'realtime_enabled' ) ); ?>" value="1" <?php checked( ! empty( $paia_s['realtime_enabled'] ) ); ?>>
						<span><?php esc_html_e( 'فعال (اگر خاموش باشد ویجت فقط با polling کار می‌کند)', 'persian-ai-assistant' ); ?></span>
					</label>
					<label class="paia-chatset__f paia-chatset__wide">
						<span><?php esc_html_e( 'آدرس WebSocket (اختیاری؛ خالی = wss://آدرس-سرور/v1/ws)', 'persian-ai-assistant' ); ?></span>
						<input type="text" dir="ltr" name="<?php echo esc_attr( $paia_name( 'ws_url' ) ); ?>" value="<?php echo esc_attr( (string) $paia_s['ws_url'] ); ?>" placeholder="wss://ai.example.com/v1/ws">
					</label>
					<?php
					$paia_num = array(
						'poll_closed' => __( 'polling چت بسته (ثانیه)', 'persian-ai-assistant' ),
						'poll_fast'   => __( 'polling چت باز (ثانیه)', 'persian-ai-assistant' ),
						'poll_mid'    => __( 'بعد از بی‌حرکتی (ثانیه)', 'persian-ai-assistant' ),
						'poll_max'    => __( 'سقف backoff (ثانیه)', 'persian-ai-assistant' ),
						'poll_idle'   => __( 'مدت بی‌حرکتی تا backoff (ثانیه)', 'persian-ai-assistant' ),
						'ws_retry'    => __( 'تلاش مجدد WebSocket در fallback (ثانیه)', 'persian-ai-assistant' ),
						'rl_poll_per_min'    => __( 'سقف poll در دقیقه (هر بازدیدکننده)', 'persian-ai-assistant' ),
						'rl_token_per_min'   => __( 'سقف توکن WebSocket در دقیقه', 'persian-ai-assistant' ),
						'rl_create_per_hour' => __( 'سقف گفتگوی جدید در ساعت (هر IP)', 'persian-ai-assistant' ),
					);
					foreach ( $paia_num as $paia_k => $paia_label ) :
						?>
						<label class="paia-chatset__f">
							<span><?php echo esc_html( $paia_label ); ?></span>
							<input type="number" min="1" name="<?php echo esc_attr( $paia_name( $paia_k ) ); ?>" value="<?php echo esc_attr( (string) $paia_s[ $paia_k ] ); ?>">
						</label>
					<?php endforeach; ?>
				</div>
				<p class="paia-chatset__hint paia-chatset__hint--block"><?php esc_html_e( 'WebSocket فقط «اعلان» می‌فرستد؛ پیام‌ها همچنان از وردپرس خوانده می‌شوند. اگر سرور در دسترس نباشد ویجت خودکار به polling برمی‌گردد.', 'persian-ai-assistant' ); ?></p>
			</section>
			</div>

		<div class="paia-actions">
			<button type="submit" class="paia-btn"><span class="paia-btn__label"><?php esc_html_e( 'ذخیرهٔ تغییرات', 'persian-ai-assistant' ); ?></span></button>
		</div>

		</div>
	</div>
</form>
