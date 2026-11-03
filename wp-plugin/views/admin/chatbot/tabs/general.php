<?php
/**
 * تب «عمومی»: آمار کلی در چهار کارت کنار هم.
 *
 * @var bool                $enabled  وضعیت کل چت‌بات (خاموش = فقط کلید وضعیت نمایش داده می‌شود).
 * @var int                 $unread_messages
 * @var int                 $unread_conversations
 * @var array<string,mixed> $extraction  خروجی Extraction\Overview::build()
 * @var array<string,mixed> $docs        خروجی Documents\Overview::build()
 * @var array<string,int>   $logs        خروجی LogStore::stats()
 * @var array<string,mixed> $subscription خروجی Subscription\Service::present()
 */

use PersianAIAssistant\Support\Icons;

defined( 'ABSPATH' ) || exit;

?>
<div class="paia-status<?php echo $enabled ? '' : ' is-off'; ?>" data-role="status" data-enabled="<?php echo $enabled ? '1' : '0'; ?>">
	<label class="paia-power">
		<input type="checkbox" class="paia-power__input" data-role="status-toggle" <?php checked( $enabled ); ?> aria-describedby="paia-status-hint">
		<span class="paia-power__track" aria-hidden="true"><span class="paia-power__thumb"></span></span>
		<span class="paia-power__text">
			<strong><?php esc_html_e( 'وضعیت چت بات', 'persian-ai-assistant' ); ?></strong>
			<small class="paia-power__state" data-role="status-label" data-on="<?php esc_attr_e( 'فعال', 'persian-ai-assistant' ); ?>" data-off="<?php esc_attr_e( 'غیرفعال', 'persian-ai-assistant' ); ?>"><?php echo $enabled ? esc_html__( 'فعال', 'persian-ai-assistant' ) : esc_html__( 'غیرفعال', 'persian-ai-assistant' ); ?></small>
		</span>
	</label>
	<p class="paia-status__hint" id="paia-status-hint"><?php esc_html_e( 'با غیرفعال کردن، تمام عملکرد چت‌بات متوقف می‌شود و هیچ بخشی از آن روی سایت نمایش داده نمی‌شود.', 'persian-ai-assistant' ); ?></p>
	<p class="paia-status__msg" data-role="status-msg" role="status" aria-live="polite" hidden></p>
</div>

<?php
if ( ! $enabled ) {
	return;
}

$paia_n = static function ( $number ): string {
	return number_format_i18n( (int) $number );
};

$paia_files = (array) ( $docs['files'] ?? array() );
$paia_texts = (array) ( $docs['texts'] ?? array() );

$paia_docs_total   = (int) ( $paia_files['total'] ?? 0 ) + (int) ( $paia_texts['boxes'] ?? 0 );
$paia_docs_trained = (int) ( $paia_files['trained'] ?? 0 ) + (int) ( $paia_texts['trained'] ?? 0 );

$paia_formats = array();
foreach ( (array) ( $paia_files['formats'] ?? array() ) as $paia_format ) {
	if ( (int) $paia_format['count'] > 0 ) {
		$paia_formats[] = $paia_format['label'] . ' ' . $paia_n( $paia_format['count'] );
	}
}

$paia_last = (string) ( $extraction['last_label'] ?? '' );

/**
 * کارت‌ها: href، رنگ، آیکن، عنوان، عدد بزرگ + توضیحش، ردیف‌ها [برچسب، مقدار، کلاس]، هشدار.
 */
$paia_cards = array(
	array(
		'href'  => '#chatmanage',
		'color' => '#a78bfa',
		'icon'  => 'message',
		'title' => __( 'پیام‌ها و گفت‌وگوها', 'persian-ai-assistant' ),
		'num'   => $paia_n( $unread_messages ),
		'cap'   => __( 'پیام خوانده نشده', 'persian-ai-assistant' ),
		'alert' => $unread_messages > 0,
		'rows'  => array(
			array( __( 'گفت‌وگوی خوانده نشده', 'persian-ai-assistant' ), $paia_n( $unread_conversations ), $unread_conversations > 0 ? 'is-warn' : '' ),
		),
		'cta'   => __( 'مدیریت چت', 'persian-ai-assistant' ),
	),
	array(
		'href'  => '#extraction',
		'color' => '#22d3ee',
		'icon'  => 'database',
		'title' => __( 'اطلاعات استخراج‌شده از سایت', 'persian-ai-assistant' ),
		'num'   => $paia_n( $extraction['total'] ?? 0 ),
		'cap'   => __( 'مورد ثبت شده', 'persian-ai-assistant' ),
		'alert' => false,
		'rows'  => array(
			array( __( 'آموزش‌دیده به هوش مصنوعی', 'persian-ai-assistant' ), $paia_n( $extraction['trained'] ?? 0 ), '' ),
			array( __( 'آخرین به‌روزرسانی', 'persian-ai-assistant' ), '' !== $paia_last ? $paia_last : __( 'هنوز انجام نشده', 'persian-ai-assistant' ), 'is-text' ),
		),
		'cta'   => __( 'استخراج اطلاعات', 'persian-ai-assistant' ),
	),
	array(
		'href'  => '#documents',
		'color' => '#818cf8',
		'icon'  => 'file-text',
		'title' => __( 'اسناد اضافه شده', 'persian-ai-assistant' ),
		'num'   => $paia_n( $paia_docs_total ),
		'cap'   => __( 'سند (فایل و متن)', 'persian-ai-assistant' ),
		'alert' => false,
		'rows'  => array(
			array( __( 'فایل آپلود شده', 'persian-ai-assistant' ), $paia_n( $paia_files['total'] ?? 0 ), '' ),
			array( __( 'متن اضافه شده', 'persian-ai-assistant' ), $paia_n( $paia_texts['boxes'] ?? 0 ), '' ),
			array( __( 'آموزش‌دیده به هوش مصنوعی', 'persian-ai-assistant' ), $paia_n( $paia_docs_trained ), '' ),
			$paia_formats ? array( __( 'فرمت فایل‌ها', 'persian-ai-assistant' ), implode( '، ', $paia_formats ), 'is-text' ) : null,
		),
		'cta'   => __( 'اسناد اضافه', 'persian-ai-assistant' ),
	),
	array(
		'href'  => '#serverlog',
		'color' => '#f472b6',
		'icon'  => 'list',
		'title' => __( 'لاگ سرور', 'persian-ai-assistant' ),
		'num'   => $paia_n( $logs['total'] ?? 0 ),
		'cap'   => __( 'لاگ ثبت شده', 'persian-ai-assistant' ),
		'alert' => false,
		'rows'  => array(
			array( __( 'خطا', 'persian-ai-assistant' ), $paia_n( $logs['error'] ?? 0 ), ( $logs['error'] ?? 0 ) > 0 ? 'is-bad' : '' ),
			array( __( 'موفق', 'persian-ai-assistant' ), $paia_n( $logs['success'] ?? 0 ), '' ),
		),
		'cta'   => __( 'مشاهدهٔ لاگ‌ها', 'persian-ai-assistant' ),
	),
);
?>
<div class="paia-dash">
	<div class="paia-dash__head">
		<h2 class="paia-dash__title"><?php esc_html_e( 'نمای کلی', 'persian-ai-assistant' ); ?></h2>
		<p class="paia-dash__sub"><?php esc_html_e( 'خلاصهٔ وضعیت دستیار هوشمند سایت شما؛ برای دیدن جزئیات روی هر کارت بزنید.', 'persian-ai-assistant' ); ?></p>
	</div>

	<div class="paia-dash__grid">
		<?php foreach ( $paia_cards as $paia_card ) : ?>
			<a class="paia-dcard<?php echo $paia_card['alert'] ? ' is-alert' : ''; ?>" href="<?php echo esc_attr( $paia_card['href'] ); ?>" style="--dc:<?php echo esc_attr( $paia_card['color'] ); ?>">
				<span class="paia-dcard__top">
					<span class="paia-dcard__icon"><?php echo Icons::get( $paia_card['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<h3 class="paia-dcard__title"><?php echo esc_html( $paia_card['title'] ); ?></h3>
				</span>

				<span class="paia-dcard__big">
					<span class="paia-dcard__num"><?php echo esc_html( $paia_card['num'] ); ?></span>
					<span class="paia-dcard__cap"><?php echo esc_html( $paia_card['cap'] ); ?></span>
				</span>

				<dl class="paia-dcard__rows">
					<?php foreach ( $paia_card['rows'] as $paia_row ) : ?>
						<?php
						if ( ! $paia_row ) {
							continue;
						}
						?>
						<div class="paia-dcard__row <?php echo esc_attr( $paia_row[2] ); ?>">
							<dt><?php echo esc_html( $paia_row[0] ); ?></dt>
							<dd><?php echo esc_html( $paia_row[1] ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>

				<span class="paia-dcard__foot">
					<?php echo esc_html( $paia_card['cta'] ); ?>
					<?php echo Icons::get( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
	$paia_sub    = isset( $subscription ) ? (array) $subscription : array( 'synced' => false );
	$paia_synced = ! empty( $paia_sub['synced'] );
	$paia_dash   = '—';
	$paia_val    = static function ( string $key ) use ( $paia_sub, $paia_dash ): string {
		return isset( $paia_sub[ $key ] ) ? (string) $paia_sub[ $key ] : $paia_dash;
	};
	?>
	<section class="paia-plan<?php echo ! empty( $paia_sub['expired'] ) ? ' is-expired' : ''; ?>" data-role="subscription" aria-labelledby="paia-plan-title">
		<div class="paia-plan__head">
			<h3 class="paia-plan__title" id="paia-plan-title"><?php esc_html_e( 'وضعیت اشتراک', 'persian-ai-assistant' ); ?></h3>
			<span class="paia-chip paia-chip--warn" data-sub="demo" <?php echo ! empty( $paia_sub['demo'] ) ? '' : 'hidden'; ?>><?php esc_html_e( 'نمایشی (تا اتصال سرور)', 'persian-ai-assistant' ); ?></span>
			<button type="button" class="paia-btn paia-btn--ghost paia-plan__sync" data-role="sub-sync">
				<?php echo Icons::get( 'refresh', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="paia-btn__label"><?php esc_html_e( 'همگام‌سازی با سرور', 'persian-ai-assistant' ); ?></span>
				<span class="paia-spinner" aria-hidden="true"></span>
			</button>
		</div>

		<div class="paia-plan__grid">
			<div class="paia-plan__item">
				<span class="paia-plan__label"><?php esc_html_e( 'نوع اشتراک', 'persian-ai-assistant' ); ?></span>
				<strong class="paia-plan__value" data-sub="plan"><?php echo esc_html( $paia_val( 'plan' ) ); ?></strong>
			</div>

			<div class="paia-plan__item">
				<span class="paia-plan__label"><?php esc_html_e( 'مهلت باقی‌مانده (ددلاین)', 'persian-ai-assistant' ); ?></span>
				<strong class="paia-plan__value" data-sub="remaining"><?php echo esc_html( $paia_val( 'remaining' ) ); ?></strong>
				<span class="paia-plan__bar"><span data-sub="time_bar" style="width:<?php echo esc_attr( (string) (int) ( $paia_sub['time_pct'] ?? 0 ) ); ?>%"></span></span>
				<small class="paia-plan__hint"><?php esc_html_e( 'پایان اشتراک:', 'persian-ai-assistant' ); ?> <span data-sub="expires"><?php echo esc_html( $paia_val( 'expires' ) ); ?></span></small>
			</div>

			<div class="paia-plan__item">
				<span class="paia-plan__label"><?php esc_html_e( 'مصرف توکن', 'persian-ai-assistant' ); ?></span>
				<strong class="paia-plan__value"><span data-sub="tokens_used"><?php echo esc_html( $paia_val( 'tokens_used' ) ); ?></span> <small>/ <span data-sub="tokens_total"><?php echo esc_html( $paia_val( 'tokens_total' ) ); ?></span></small></strong>
				<span class="paia-plan__bar paia-plan__bar--tokens"><span data-sub="tokens_bar" style="width:<?php echo esc_attr( (string) (int) ( $paia_sub['tokens_pct'] ?? 0 ) ); ?>%"></span></span>
			</div>

			<div class="paia-plan__item">
				<span class="paia-plan__label"><?php esc_html_e( 'توکن باقی‌مانده', 'persian-ai-assistant' ); ?></span>
				<strong class="paia-plan__value" data-sub="tokens_left"><?php echo esc_html( $paia_val( 'tokens_left' ) ); ?></strong>
			</div>
		</div>

		<p class="paia-plan__foot" data-role="sub-foot" role="status" aria-live="polite">
			<?php
			echo $paia_synced
				? esc_html( sprintf( /* translators: %s: date and time */ __( 'آخرین همگام‌سازی: %s', 'persian-ai-assistant' ), $paia_val( 'synced_label' ) ) )
				: esc_html__( 'هنوز همگام‌سازی نشده؛ برای دیدن وضعیت اشتراک دکمهٔ «همگام‌سازی با سرور» را بزنید.', 'persian-ai-assistant' );
			?>
		</p>
	</section>
</div>
