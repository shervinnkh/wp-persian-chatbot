<?php
/**
 * تب مدیریت چت. محتوا را chat-admin.js داخل #paia-cm می‌سازد.
 */

use PersianAIAssistant\Chat\AdminAssets;

defined( 'ABSPATH' ) || exit;
?>
<div
	class="paia-chat paia-cm"
	id="paia-cm"
	dir="<?php echo esc_attr( is_rtl() ? 'rtl' : 'ltr' ); ?>"
	style="<?php echo esc_attr( AdminAssets::css_vars_inline() ); ?>"
>
	<noscript><p><?php esc_html_e( 'برای استفاده از مدیریت چت، جاوااسکریپت مرورگر باید فعال باشد.', 'persian-ai-assistant' ); ?></p></noscript>
</div>
