<?php
namespace PersianWPChatbot\Apis\Endpoints;

use PersianWPChatbot\Apis\Callback;
use PersianWPChatbot\Apis\Results;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/pwc/v1/ai/ping
 *
 * بررسی سلامت و «دست‌دادن»: سرور با این مسیر مطمئن می‌شود آدرس بازگشتی سایت در دسترس است و کلید/امضا درست است،
 * و همزمان سرعت پاسخ‌گویی سایت را اندازه می‌گیرد (برای تنظیم سرعت ارسال به سایت‌های کند). بدنه ندارد؛ امضا روی بدنهٔ خالی است.
 */
final class PingEndpoint extends AbstractEndpoint {

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/ping',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	public function handle(): \WP_REST_Response {
		return $this->respond(
			array(
				'ok'             => true,
				'api_version'    => Callback::API_VERSION,
				'plugin_version' => PWC_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'site_url'       => site_url(),
				'callback_url'   => Callback::url(),
				'max_results'    => Results::MAX_RESULTS,
				'time'           => time(),
			)
		);
	}
}
