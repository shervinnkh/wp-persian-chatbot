<?php
namespace PersianAIAssistant\Subscription;

use PersianAIAssistant\Chat\ChatSettings;
use PersianAIAssistant\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * وضعیت اشتراک سرویس هوش مصنوعی (کارت «وضعیت اشتراک» در تب «عمومی»).
 *
 * آخرین وضعیت همگام‌شده در option `paia_subscription` نگه داشته می‌شود (autoload=false).
 * sync() به سرور هوش مصنوعی وصل می‌شود: GET {api_url}/subscription با هدر Authorization: Bearer {api_key}
 * (آدرس با فیلتر paia_subscription_url قابل تغییر است) و پاسخ JSON زیر را انتظار دارد:
 *   { "plan": "طلایی", "started_at": "2026-10-01T00:00:00Z" (اختیاری), "expires_at": "2026-11-30T00:00:00Z" (یا unix),
 *     "tokens_total": 1000000, "tokens_used": 245000 }
 *
 * ⚠️ سرور هنوز نیست: اگر api_url خالی باشد sync() دادهٔ «نمایشی» می‌سازد (demo = true و برچسب «نمایشی» در رابط).
 * بعد از اتصال سرور، تابع demo() را حذف کنید و در sync() مقدار خالی بودن api_url را خطا بدهید.
 */
final class Service {

	private const OPTION = 'paia_subscription';

	/**
	 * @return array<string, mixed>|null
	 */
	public static function stored(): ?array {
		$data = get_option( self::OPTION, null );

		return is_array( $data ) && isset( $data['synced_at'] ) ? $data : null;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function sync() {
		$url = (string) ChatSettings::get( 'api_url', '' );

		if ( '' === $url ) {
			$state = self::demo();
			LogStore::add( 'info', __( 'همگام‌سازی اشتراک: آدرس سرویس تنظیم نشده؛ داده‌ی نمایشی نشان داده شد.', 'persian-ai-assistant' ), array( 'endpoint' => 'subscription' ) );
		} else {
			$endpoint = (string) apply_filters( 'paia_subscription_url', untrailingslashit( $url ) . '/subscription' );
			$key      = (string) ChatSettings::get( 'api_key', '' );
			$started  = microtime( true );

			$response = wp_remote_get(
				$endpoint,
				array(
					'timeout' => 15,
					'headers' => array_filter(
						array(
							'Accept'        => 'application/json',
							'Authorization' => '' !== $key ? 'Bearer ' . $key : '',
						)
					),
				)
			);

			$ms   = (int) round( ( microtime( true ) - $started ) * 1000 );
			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$json = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( is_wp_error( $response ) || 200 !== $code || ! is_array( $json ) ) {
				$error = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
				LogStore::add(
					'error',
					__( 'همگام‌سازی اشتراک ناموفق بود.', 'persian-ai-assistant' ),
					array(
						'method'      => 'GET',
						'endpoint'    => $endpoint,
						'http_status' => $code,
						'duration_ms' => $ms,
						'error'       => $error,
					)
				);

				return new \WP_Error( 'paia_sync_failed', __( 'اتصال به سرور هوش مصنوعی برقرار نشد یا پاسخ نامعتبر بود.', 'persian-ai-assistant' ), array( 'status' => 502 ) );
			}

			$state = self::normalize( $json );
			LogStore::add(
				'success',
				__( 'وضعیت اشتراک با سرور هوش مصنوعی همگام شد.', 'persian-ai-assistant' ),
				array(
					'method'      => 'GET',
					'endpoint'    => $endpoint,
					'http_status' => $code,
					'duration_ms' => $ms,
				)
			);
		}

		update_option( self::OPTION, $state, false );

		return $state;
	}

	/**
	 * @param array<string, mixed> $json
	 * @return array<string, mixed>
	 */
	private static function normalize( array $json ): array {
		$ts = static function ( $value ): int {
			if ( is_numeric( $value ) ) {
				return (int) $value;
			}
			$time = is_string( $value ) ? strtotime( $value ) : false;

			return false === $time ? 0 : (int) $time;
		};

		return array(
			'plan'         => sanitize_text_field( (string) ( $json['plan'] ?? '' ) ),
			'started_at'   => $ts( $json['started_at'] ?? 0 ),
			'expires_at'   => $ts( $json['expires_at'] ?? 0 ),
			'tokens_total' => max( 0, (int) ( $json['tokens_total'] ?? 0 ) ),
			'tokens_used'  => max( 0, (int) ( $json['tokens_used'] ?? 0 ) ),
			'synced_at'    => time(),
			'demo'         => false,
		);
	}

	/**
	 * دادهٔ نمایشی تا وقتی سرور هوش مصنوعی وصل نشده.
	 *
	 * @return array<string, mixed>
	 */
	private static function demo(): array {
		return array(
			'plan'         => __( 'آزمایشی', 'persian-ai-assistant' ),
			'started_at'   => time() - 10 * DAY_IN_SECONDS,
			'expires_at'   => time() + 20 * DAY_IN_SECONDS + 5 * HOUR_IN_SECONDS,
			'tokens_total' => 1000000,
			'tokens_used'  => 245000,
			'synced_at'    => time(),
			'demo'         => true,
		);
	}

	/**
	 * خروجی آمادهٔ نمایش (متن‌های فارسی، درصدها) برای view و JS.
	 *
	 * @param array<string, mixed>|null $state
	 * @return array<string, mixed>
	 */
	public static function present( ?array $state ): array {
		if ( null === $state ) {
			return array( 'synced' => false );
		}

		$n       = static function ( $number ): string {
			return number_format_i18n( (int) $number );
		};
		$now     = time();
		$expires = (int) $state['expires_at'];
		$started = (int) $state['started_at'];
		$left    = max( 0, $expires - $now );
		$expired = $expires > 0 && $expires <= $now;

		if ( $expires <= 0 ) {
			$remaining = '—';
		} elseif ( $expired ) {
			$remaining = __( 'منقضی شده', 'persian-ai-assistant' );
		} else {
			/* translators: 1: days, 2: hours */
			$remaining = sprintf( __( '%1$s روز و %2$s ساعت', 'persian-ai-assistant' ), $n( intdiv( $left, DAY_IN_SECONDS ) ), $n( intdiv( $left % DAY_IN_SECONDS, HOUR_IN_SECONDS ) ) );
		}

		$time_pct = ( $started > 0 && $expires > $started ) ? (int) round( 100 * $left / ( $expires - $started ) ) : ( $expired ? 0 : 100 );

		$total = (int) $state['tokens_total'];
		$used  = (int) $state['tokens_used'];
		$rest  = max( 0, $total - $used );

		return array(
			'synced'       => true,
			'demo'         => ! empty( $state['demo'] ),
			'plan'         => '' !== (string) $state['plan'] ? (string) $state['plan'] : '—',
			'expires'      => $expires > 0 ? wp_date( 'j F Y', $expires ) : '—',
			'remaining'    => $remaining,
			'expired'      => $expired,
			'time_pct'     => max( 0, min( 100, $time_pct ) ),
			'tokens_used'  => $n( $used ),
			'tokens_total' => $total > 0 ? $n( $total ) : '—',
			'tokens_left'  => $total > 0 ? $n( $rest ) : '—',
			'tokens_pct'   => $total > 0 ? max( 0, min( 100, (int) round( 100 * $used / $total ) ) ) : 0,
			'synced_label' => wp_date( 'j F Y - H:i', (int) $state['synced_at'] ),
		);
	}
}
