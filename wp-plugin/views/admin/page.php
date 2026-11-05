<?php
/**
 * قالب مشترک صفحه‌های افزونه (پوستهٔ تیره): سربرگ + (اگر بیش از یک تب باشد) نوار تب + محتوای تب‌ها.
 *
 * @var string                                                $title
 * @var \PersianAIAssistant\Admin\Chatbot\Tabs\TabInterface[] $tabs
 * @var bool                                                  $enabled   وضعیت کل چت‌بات.
 * @var bool                                                  $lockable  true = با چت‌بات خاموش، محتوای این صفحه قفل است.
 */

use PersianAIAssistant\Admin\Pages\HomePage;
use PersianAIAssistant\Support\Icons;

defined( 'ABSPATH' ) || exit;

$paia_locked = $lockable && ! $enabled;
$paia_multi  = count( $tabs ) > 1;
?>
<div class="paia-app<?php echo $enabled ? '' : ' is-off'; ?><?php echo $paia_multi ? '' : ' paia-app--single'; ?>" id="paia-app">

	<div class="paia-topbar">
		<header class="paia-header">
			<div class="paia-brand">
				<span class="paia-logo"><?php echo Icons::get( 'logo', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h1 class="paia-title"><?php echo esc_html( $title ); ?></h1>
			</div>
		</header>

		<?php if ( $paia_multi ) : ?>
			<nav class="paia-tabs" role="tablist" aria-label="<?php echo esc_attr( $title ); ?>">
				<?php foreach ( $tabs as $index => $tab ) : ?>
					<?php
					$tab_id = $tab->id();
					$active = 0 === $index;
					?>
					<a
						href="#<?php echo esc_attr( $tab_id ); ?>"
						id="paia-tab-<?php echo esc_attr( $tab_id ); ?>"
						class="paia-tab<?php echo $active ? ' is-active' : ''; ?><?php echo $paia_locked ? ' is-locked' : ''; ?>"
						role="tab"
						data-tab="<?php echo esc_attr( $tab_id ); ?>"
						aria-controls="paia-panel-<?php echo esc_attr( $tab_id ); ?>"
						aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
						tabindex="<?php echo $active ? '0' : '-1'; ?>"
						<?php echo $paia_locked ? 'aria-disabled="true"' : ''; ?>
					><?php if ( $paia_locked ) { echo Icons::get( 'lock', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} ?><?php echo esc_html( $tab->title() ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</div>

	<main class="paia-content">
		<?php foreach ( $tabs as $index => $tab ) : ?>
			<?php
			$tab_id = $tab->id();
			$active = 0 === $index;
			?>
			<section
				id="paia-panel-<?php echo esc_attr( $tab_id ); ?>"
				class="paia-panel"
				data-panel="<?php echo esc_attr( $tab_id ); ?>"
				<?php if ( $paia_multi ) : ?>
					role="tabpanel"
					aria-labelledby="paia-tab-<?php echo esc_attr( $tab_id ); ?>"
					<?php echo $active ? '' : 'hidden'; ?>
				<?php endif; ?>
			>
				<?php
				if ( $paia_locked ) {
					// چت‌بات خاموش: محتوای صفحه اصلاً ساخته/خوانده نمی‌شود (هیچ کوئری و هیچ رکوردی).
					printf(
						'<p class="paia-note paia-locknote">%s <a href="%s">%s</a></p>',
						esc_html__( 'چت‌بات غیرفعال است. برای استفاده از این بخش، وضعیت چت‌بات را فعال کنید.', 'persian-ai-assistant' ),
						esc_url( admin_url( 'admin.php?page=' . HomePage::SLUG ) ),
						esc_html__( 'رفتن به صفحه اصلی', 'persian-ai-assistant' )
					);
				} else {
					$tab->render();
				}
				?>
			</section>
		<?php endforeach; ?>
	</main>

</div>
