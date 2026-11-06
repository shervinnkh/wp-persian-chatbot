<?php
/**
 * صفحهٔ چت‌بات هوشمند.
 *
 * @var string                                                      $title
 * @var \PersianWPChatbot\Admin\Chatbot\Tabs\TabInterface[]       $tabs
 * @var bool                                                        $enabled  وضعیت کل چت‌بات؛ false = همهٔ تب‌ها به‌جز «عمومی» قفل هستند.
 */

use PersianWPChatbot\Support\Icons;

defined( 'ABSPATH' ) || exit;
?>
<div class="pwc-app<?php echo $enabled ? '' : ' is-off'; ?>" id="pwc-app">

	<div class="pwc-topbar">
		<header class="pwc-header">
			<div class="pwc-brand">
				<span class="pwc-logo"><?php echo Icons::get( 'logo', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h1 class="pwc-title"><?php echo esc_html( $title ); ?></h1>
			</div>
		</header>

		<nav class="pwc-tabs" role="tablist" aria-label="<?php echo esc_attr( $title ); ?>">
			<?php foreach ( $tabs as $index => $tab ) : ?>
				<?php
				$tab_id = $tab->id();
				$active = 0 === $index;
				$locked = ! $enabled && 'general' !== $tab_id;
				?>
				<a
					href="#<?php echo esc_attr( $tab_id ); ?>"
					id="pwc-tab-<?php echo esc_attr( $tab_id ); ?>"
					class="pwc-tab<?php echo $active ? ' is-active' : ''; ?><?php echo $locked ? ' is-locked' : ''; ?>"
					role="tab"
					data-tab="<?php echo esc_attr( $tab_id ); ?>"
					aria-controls="pwc-panel-<?php echo esc_attr( $tab_id ); ?>"
					aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
					tabindex="<?php echo $active ? '0' : '-1'; ?>"
					<?php echo $locked ? 'aria-disabled="true"' : ''; ?>
				><?php if ( $locked ) { echo Icons::get( 'lock', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} ?><?php echo esc_html( $tab->title() ); ?></a>
			<?php endforeach; ?>
		</nav>
	</div>

	<main class="pwc-content">
		<?php foreach ( $tabs as $index => $tab ) : ?>
			<?php
			$tab_id = $tab->id();
			$active = 0 === $index;
			$locked = ! $enabled && 'general' !== $tab_id;
			?>
			<section
				id="pwc-panel-<?php echo esc_attr( $tab_id ); ?>"
				class="pwc-panel"
				role="tabpanel"
				data-panel="<?php echo esc_attr( $tab_id ); ?>"
				aria-labelledby="pwc-tab-<?php echo esc_attr( $tab_id ); ?>"
				<?php echo $active ? '' : 'hidden'; ?>
			>
				<?php
				if ( $locked ) {
					// چت‌بات خاموش: محتوای تب اصلاً ساخته/خوانده نمی‌شود (هیچ کوئری و هیچ رکوردی).
					echo '<p class="pwc-note pwc-locknote">' . esc_html__( 'چت‌بات غیرفعال است. برای استفاده از این بخش، از تب «عمومی» وضعیت چت‌بات را فعال کنید.', 'persian-wp-chatbot' ) . '</p>';
				} else {
					$tab->render();
				}
				?>
			</section>
		<?php endforeach; ?>
	</main>

</div>
