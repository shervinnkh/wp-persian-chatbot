<?php
/**
 * تب مدیریت چت. محتوا را chat-admin.js داخل #pwc-cm می‌سازد.
 */

use PersianWPChatbot\Chat\AdminAssets;

defined( 'ABSPATH' ) || exit;
?>
<div
	class="pwc-chat pwc-cm"
	id="pwc-cm"
	dir="<?php echo esc_attr( is_rtl() ? 'rtl' : 'ltr' ); ?>"
	style="<?php echo esc_attr( AdminAssets::css_vars_inline() ); ?>"
>
	<noscript><p><?php esc_html_e( 'برای استفاده از مدیریت چت، جاوااسکریپت مرورگر باید فعال باشد.', 'persian-wp-chatbot' ); ?></p></noscript>
</div>
