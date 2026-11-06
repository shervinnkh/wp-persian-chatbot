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
 */

use PersianWPChatbot\Admin\Pages\ChatbotPage;
use PersianWPChatbot\Admin\Pages\LogsPage;
use PersianWPChatbot\Admin\Pages\ResourcesPage;
use PersianWPChatbot\Support\Icons;

defined( 'ABSPATH' ) || exit;

?>
<div class="pwc-status<?php echo $enabled ? '' : ' is-off'; ?>" data-role="status" data-enabled="<?php echo $enabled ? '1' : '0'; ?>">
	<label class="pwc-power">
		<input type="checkbox" class="pwc-power__input" data-role="status-toggle" <?php checked( $enabled ); ?> aria-describedby="pwc-status-hint">
		<span class="pwc-power__track" aria-hidden="true"><span class="pwc-power__thumb"></span></span>
		<span class="pwc-power__text">
			<strong><?php esc_html_e( 'وضعیت چت بات', 'persian-wp-chatbot' ); ?></strong>
			<small class="pwc-power__state" data-role="status-label" data-on="<?php esc_attr_e( 'فعال', 'persian-wp-chatbot' ); ?>" data-off="<?php esc_attr_e( 'غیرفعال', 'persian-wp-chatbot' ); ?>"><?php echo $enabled ? esc_html__( 'فعال', 'persian-wp-chatbot' ) : esc_html__( 'غیرفعال', 'persian-wp-chatbot' ); ?></small>
		</span>
	</label>
	<p class="pwc-status__hint" id="pwc-status-hint"><?php esc_html_e( 'با غیرفعال کردن، تمام عملکرد چت‌بات متوقف می‌شود و هیچ بخشی از آن روی سایت نمایش داده نمی‌شود.', 'persian-wp-chatbot' ); ?></p>
	<p class="pwc-status__msg" data-role="status-msg" role="status" aria-live="polite" hidden></p>
</div>

<?php
if ( ! $enabled ) {
	return;
}

if ( ! empty( $budget_banner ) ) :
	$pwc_bb = 'limit100' === $budget_banner['level'] ? array( '#fee2e2', '#b91c1c', '🔴' ) : array( '#fef9c3', '#a16207', '🟡' );
	?>
	<div class="pwc-budget-banner" role="alert" style="margin:12px 0;padding:12px 16px;border-radius:10px;background:<?php echo esc_attr( $pwc_bb[0] ); ?>;color:<?php echo esc_attr( $pwc_bb[1] ); ?>;font-weight:600">
		<?php foreach ( $budget_banner['lines'] as $pwc_line ) : ?>
			<div><?php echo esc_html( $pwc_bb[2] . ' ' . $pwc_line ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
<?php

$pwc_n = static function ( $number ): string {
	return number_format_i18n( (int) $number );
};

$pwc_files = (array) ( $docs['files'] ?? array() );
$pwc_texts = (array) ( $docs['texts'] ?? array() );

$pwc_docs_total   = (int) ( $pwc_files['total'] ?? 0 ) + (int) ( $pwc_texts['boxes'] ?? 0 );
$pwc_docs_trained = (int) ( $pwc_files['trained'] ?? 0 ) + (int) ( $pwc_texts['trained'] ?? 0 );

$pwc_formats = array();
foreach ( (array) ( $pwc_files['formats'] ?? array() ) as $pwc_format ) {
	if ( (int) $pwc_format['count'] > 0 ) {
		$pwc_formats[] = $pwc_format['label'] . ' ' . $pwc_n( $pwc_format['count'] );
	}
}

$pwc_last = (string) ( $extraction['last_label'] ?? '' );

/**
 * کارت‌ها: href، رنگ، آیکن، عنوان، عدد بزرگ + توضیحش، ردیف‌ها [برچسب، مقدار، کلاس]، هشدار.
 */
$pwc_cards = array(
	array(
		'href'  => admin_url( 'admin.php?page=' . ChatbotPage::SLUG ),
		'color' => '#a78bfa',
		'icon'  => 'message',
		'title' => __( 'پیام‌ها و گفت‌وگوها', 'persian-wp-chatbot' ),
		'num'   => $pwc_n( $unread_messages ),
		'cap'   => __( 'پیام خوانده نشده', 'persian-wp-chatbot' ),
		'alert' => $unread_messages > 0,
		'rows'  => array(
			array( __( 'گفت‌وگوی خوانده نشده', 'persian-wp-chatbot' ), $pwc_n( $unread_conversations ), $unread_conversations > 0 ? 'is-warn' : '' ),
		),
		'cta'   => __( 'مدیریت چت', 'persian-wp-chatbot' ),
	),
	array(
		'href'  => admin_url( 'admin.php?page=' . ResourcesPage::SLUG ) . '#extraction',
		'color' => '#22d3ee',
		'icon'  => 'database',
		'title' => __( 'اطلاعات استخراج‌شده از سایت', 'persian-wp-chatbot' ),
		'num'   => $pwc_n( $extraction['total'] ?? 0 ),
		'cap'   => __( 'مورد ثبت شده', 'persian-wp-chatbot' ),
		'alert' => false,
		'rows'  => array(
			array( __( 'آموزش‌دیده به هوش مصنوعی', 'persian-wp-chatbot' ), $pwc_n( $extraction['trained'] ?? 0 ), '' ),
			array( __( 'آخرین به‌روزرسانی', 'persian-wp-chatbot' ), '' !== $pwc_last ? $pwc_last : __( 'هنوز انجام نشده', 'persian-wp-chatbot' ), 'is-text' ),
		),
		'cta'   => __( 'استخراج اطلاعات', 'persian-wp-chatbot' ),
	),
	array(
		'href'  => admin_url( 'admin.php?page=' . ResourcesPage::SLUG ) . '#documents',
		'color' => '#818cf8',
		'icon'  => 'file-text',
		'title' => __( 'اسناد اضافه شده', 'persian-wp-chatbot' ),
		'num'   => $pwc_n( $pwc_docs_total ),
		'cap'   => __( 'سند (فایل و متن)', 'persian-wp-chatbot' ),
		'alert' => false,
		'rows'  => array(
			array( __( 'فایل آپلود شده', 'persian-wp-chatbot' ), $pwc_n( $pwc_files['total'] ?? 0 ), '' ),
			array( __( 'متن اضافه شده', 'persian-wp-chatbot' ), $pwc_n( $pwc_texts['boxes'] ?? 0 ), '' ),
			array( __( 'آموزش‌دیده به هوش مصنوعی', 'persian-wp-chatbot' ), $pwc_n( $pwc_docs_trained ), '' ),
			$pwc_formats ? array( __( 'فرمت فایل‌ها', 'persian-wp-chatbot' ), implode( '، ', $pwc_formats ), 'is-text' ) : null,
		),
		'cta'   => __( 'اسناد اضافه', 'persian-wp-chatbot' ),
	),
	array(
		'href'  => admin_url( 'admin.php?page=' . LogsPage::SLUG ) . '#serverlog',
		'color' => '#f472b6',
		'icon'  => 'list',
		'title' => __( 'لاگ سرور', 'persian-wp-chatbot' ),
		'num'   => $pwc_n( $logs['total'] ?? 0 ),
		'cap'   => __( 'لاگ ثبت شده', 'persian-wp-chatbot' ),
		'alert' => false,
		'rows'  => array(
			array( __( 'خطا', 'persian-wp-chatbot' ), $pwc_n( $logs['error'] ?? 0 ), ( $logs['error'] ?? 0 ) > 0 ? 'is-bad' : '' ),
			array( __( 'موفق', 'persian-wp-chatbot' ), $pwc_n( $logs['success'] ?? 0 ), '' ),
		),
		'cta'   => __( 'مشاهدهٔ لاگ‌ها', 'persian-wp-chatbot' ),
	),
);
?>
<div class="pwc-dash">
	<div class="pwc-dash__head">
		<h2 class="pwc-dash__title"><?php esc_html_e( 'نمای کلی', 'persian-wp-chatbot' ); ?></h2>
		<p class="pwc-dash__sub"><?php esc_html_e( 'خلاصهٔ وضعیت چت بات هوشمند سایت شما؛ برای دیدن جزئیات روی هر کارت بزنید.', 'persian-wp-chatbot' ); ?></p>
	</div>

	<div class="pwc-dash__grid">
		<?php foreach ( $pwc_cards as $pwc_card ) : ?>
			<a class="pwc-dcard<?php echo $pwc_card['alert'] ? ' is-alert' : ''; ?>" href="<?php echo esc_url( $pwc_card['href'] ); ?>" style="--dc:<?php echo esc_attr( $pwc_card['color'] ); ?>">
				<span class="pwc-dcard__top">
					<span class="pwc-dcard__icon"><?php echo Icons::get( $pwc_card['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<h3 class="pwc-dcard__title"><?php echo esc_html( $pwc_card['title'] ); ?></h3>
				</span>

				<span class="pwc-dcard__big">
					<span class="pwc-dcard__num"><?php echo esc_html( $pwc_card['num'] ); ?></span>
					<span class="pwc-dcard__cap"><?php echo esc_html( $pwc_card['cap'] ); ?></span>
				</span>

				<dl class="pwc-dcard__rows">
					<?php foreach ( $pwc_card['rows'] as $pwc_row ) : ?>
						<?php
						if ( ! $pwc_row ) {
							continue;
						}
						?>
						<div class="pwc-dcard__row <?php echo esc_attr( $pwc_row[2] ); ?>">
							<dt><?php echo esc_html( $pwc_row[0] ); ?></dt>
							<dd><?php echo esc_html( $pwc_row[1] ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>

				<span class="pwc-dcard__foot">
					<?php echo esc_html( $pwc_card['cta'] ); ?>
					<?php echo Icons::get( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</div>
