<?php
/**
 * قالب مشترک صفحه‌های افزونه (پوستهٔ تیره): سربرگ + (اگر بیش از یک تب باشد) نوار تب + محتوای تب‌ها.
 *
 * @var string                                                $title
 * @var \PersianWPChatbot\Admin\Chatbot\Tabs\TabInterface[] $tabs
 * @var bool                                                  $enabled   وضعیت کل چت‌بات.
 * @var bool                                                  $lockable  true = با چت‌بات خاموش، محتوای این صفحه قفل است.
 */

use PersianWPChatbot\Admin\Pages\HomePage;
use PersianWPChatbot\Support\Icons;

defined( 'ABSPATH' ) || exit;

$pwc_locked = $lockable && ! $enabled;
$pwc_multi  = count( $tabs ) > 1;
?>
<div class="pwc-app<?php echo $enabled ? '' : ' is-off'; ?><?php echo $pwc_multi ? '' : ' pwc-app--single'; ?>" id="pwc-app">

	<div class="pwc-topbar">
		<header class="pwc-header">
			<div class="pwc-brand">
				<span class="pwc-logo"><?php echo Icons::get( 'logo', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h1 class="pwc-title"><?php echo esc_html( $title ); ?></h1>
			</div>
		</header>

		<?php if ( $pwc_multi ) : ?>
			<nav class="pwc-tabs" role="tablist" aria-label="<?php echo esc_attr( $title ); ?>">
				<?php foreach ( $tabs as $index => $tab ) : ?>
					<?php
					$tab_id = $tab->id();
					$active = 0 === $index;
					?>
					<a
						href="#<?php echo esc_attr( $tab_id ); ?>"
						id="pwc-tab-<?php echo esc_attr( $tab_id ); ?>"
						class="pwc-tab<?php echo $active ? ' is-active' : ''; ?><?php echo $pwc_locked ? ' is-locked' : ''; ?>"
						role="tab"
						data-tab="<?php echo esc_attr( $tab_id ); ?>"
						aria-controls="pwc-panel-<?php echo esc_attr( $tab_id ); ?>"
						aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
						tabindex="<?php echo $active ? '0' : '-1'; ?>"
						<?php echo $pwc_locked ? 'aria-disabled="true"' : ''; ?>
					><?php if ( $pwc_locked ) { echo Icons::get( 'lock', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} ?><?php echo esc_html( $tab->title() ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</div>

	<main class="pwc-content">
		<?php foreach ( $tabs as $index => $tab ) : ?>
			<?php
			$tab_id = $tab->id();
			$active = 0 === $index;
			?>
			<section
				id="pwc-panel-<?php echo esc_attr( $tab_id ); ?>"
				class="pwc-panel"
				data-panel="<?php echo esc_attr( $tab_id ); ?>"
				<?php if ( $pwc_multi ) : ?>
					role="tabpanel"
					aria-labelledby="pwc-tab-<?php echo esc_attr( $tab_id ); ?>"
					<?php echo $active ? '' : 'hidden'; ?>
				<?php endif; ?>
			>
				<?php
				if ( $pwc_locked ) {
					// چت‌بات خاموش: محتوای صفحه اصلاً ساخته/خوانده نمی‌شود (هیچ کوئری و هیچ رکوردی).
					printf(
						'<p class="pwc-note pwc-locknote">%s <a href="%s">%s</a></p>',
						esc_html__( 'چت‌بات غیرفعال است. برای استفاده از این بخش، وضعیت چت‌بات را فعال کنید.', 'persian-wp-chatbot' ),
						esc_url( admin_url( 'admin.php?page=' . HomePage::SLUG ) ),
						esc_html__( 'رفتن به صفحه اصلی', 'persian-wp-chatbot' )
					);
				} else {
					$tab->render();
				}
				?>
			</section>
		<?php endforeach; ?>
	</main>

</div>
