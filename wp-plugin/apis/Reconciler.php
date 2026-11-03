<?php
namespace PersianAIAssistant\Apis;

use PersianAIAssistant\Contracts\Module;
use PersianAIAssistant\Database\LogStore;
use PersianAIAssistant\Database\RemovedStore;
use PersianAIAssistant\Sync\AiBackend;

defined( 'ABSPATH' ) || exit;

/**
 * «بازبینی»: وقتی وب‌هوک گم شود یا اصلاً نرسد (WAF، سایت پشت رمز، localhost، سایت کند) رکوردِ ماندگار روی «در حال پردازش» (ai_status = ۱)
 * برای همیشه loading می‌ماند. این کلاس وضعیت آن‌ها را از سرور می‌پرسد (POST /v1/jobs/status) و جواب را دقیقاً مثل وب‌هوک
 * به Apis\Results::apply می‌دهد (ایدمپوتنت؛ همان قانون rev/stale).
 *
 * دو مسیر:
 *  ۱. کرون وردپرس هر ۱۰ دقیقه (paia_ai_reconcile) — فقط وقتی رکوردی ai_status = ۱ دارد به سرور می‌رود؛ وگرنه فقط یک COUNT/SELECT سبک.
 *  ۲. پایش پنل مدیر (Rest\AiStatusController) — وقتی مدیر پنل را باز دارد و رکوردی «در حال پردازش» است، حداکثر دقیقه‌ای یک‌بار
 *     (و نه زودتر از ۴۵ ثانیه بعد از آخرین ارسال) همین بازبینی با timeout کوتاه اجرا می‌شود؛ پس حتی بدون وب‌هوک آیکن loading حل می‌شود.
 *
 * نتیجهٔ هر رکورد:
 *   trained / untrained / failed ← Results::apply (۲ / ۰ / آزادکردن loading + لاگ)
 *   processing                    ← دست نمی‌زند
 *   unknown (سرور هیچ jobای ندارد: هرگز نرسیده یا بیش از ۷ روز گذشته) ← رکورد دوباره فرستاده می‌شود؛ اگر ارسال ناموفق بود ai_status = ۰ می‌شود
 */
final class Reconciler implements Module {

	public const CRON_HOOK = 'paia_ai_reconcile';
	public const INTERVAL  = 'paia_ten_minutes';

	/** transient قفل: بین دو بازبینیِ فرارسیده از پنل فاصله می‌اندازد. */
	private const LOCK = 'paia_reconcile_lock';

	/** transient: لاگ خطای بازبینی حداکثر ۱۰ دقیقه‌ای یک‌بار. */
	private const ERROR_THROTTLE = 'paia_reconcile_err';

	private const PANEL_PAUSE = 60;
	private const SEND_GRACE  = 45;

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * @param array<string, mixed> $schedules
	 * @return array<string, mixed>
	 */
	public function add_schedule( $schedules ): array {
		$schedules                    = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::INTERVAL ] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'هر ۱۰ دقیقه (بازبینی هوش مصنوعی)', 'persian-ai-assistant' ),
		);

		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::INTERVAL, self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function run_cron(): void {
		self::reconcile( array_merge( Records::processing_ids( Results::MAX_RESULTS ), RemovedStore::deleting_ids( null, 100 ) ), 20 );
	}

	/**
	 * بعد از هر ارسال موفق صدا زده می‌شود: بازبینی پنل را چند ثانیه عقب می‌اندازد تا وب‌هوک (مسیر سریع) فرصت داشته باشد.
	 */
	public static function touch(): void {
		set_transient( self::LOCK, 1, self::SEND_GRACE );
	}

	/**
	 * مسیر پنل: فقط اگر قفل آزاد است و حداقل یکی از شناسه‌ها هنوز «در حال پردازش» است.
	 *
	 * @param string[] $ids شناسه‌هایی که پنل دارد پایش می‌کند.
	 * @return bool true اگر بازبینی واقعاً اجرا شد (فراخوان‌کننده وضعیت‌ها را دوباره بخواند).
	 */
	public static function maybe_run_for( array $ids ): bool {
		if ( ! $ids || ! Client::is_configured() || false !== get_transient( self::LOCK ) ) {
			return false;
		}
		set_transient( self::LOCK, 1, self::PANEL_PAUSE );

		$processing = array_keys( array_filter( Records::statuses( $ids ), static function ( $status ) {
			return Records::STATUS_PROCESSING === (int) $status;
		} ) );
		$processing = array_merge( $processing, RemovedStore::deleting_ids( $ids ) ); // ردیف‌های «در حال حذف» لیست موارد حذف‌شده
		if ( ! $processing ) {
			return false;
		}

		self::reconcile( $processing, 6 );

		return true;
	}

	/**
	 * @param string[] $ids رکوردهای «در حال پردازش» (حداکثر ۵۰۰).
	 * @return array{applied:int, resent:int, still:int}
	 */
	public static function reconcile( array $ids, int $timeout = 10 ): array {
		$summary = array(
			'applied' => 0,
			'resent'  => 0,
			'still'   => 0,
		);

		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) ), 0, Results::MAX_RESULTS );
		if ( ! $ids || ! Client::is_configured() ) {
			return $summary;
		}

		$response = Client::request( 'POST', '/v1/jobs/status', array( 'ids' => $ids ), $timeout, 1 );
		$results  = $response['body']['results'] ?? null;

		if ( 200 !== $response['code'] || ! is_array( $results ) ) {
			if ( false === get_transient( self::ERROR_THROTTLE ) ) {
				set_transient( self::ERROR_THROTTLE, 1, 10 * MINUTE_IN_SECONDS );
				LogStore::add(
					LogStore::ERROR,
					__( 'بازبینی وضعیت رکوردها از سرور هوش مصنوعی ناموفق بود.', 'persian-ai-assistant' ),
					array_filter(
						array(
							'method'      => 'POST',
							'endpoint'    => '/v1/jobs/status',
							'http_status' => $response['code'],
							'duration_ms' => $response['duration_ms'],
							'request'     => array( 'count' => count( $ids ) ),
							'error'       => trim( $response['error_code'] . ' ' . $response['error'] ),
							'request_id'  => $response['request_id'],
						),
						static function ( $value ) {
							return '' !== $value && null !== $value;
						}
					)
				);
			}

			return $summary;
		}

		$apply  = array();
		$resend = array();
		foreach ( $results as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['status'] ) ) {
				continue;
			}
			$status = strtolower( (string) $item['status'] );

			if ( in_array( $status, array( Results::TRAINED, Results::UNTRAINED, Results::FAILED ), true ) ) {
				$apply[] = array_filter(
					array(
						'id'     => (string) $item['id'],
						'status' => $status,
						'rev'       => $item['rev'] ?? null,
						'error'     => $item['error'] ?? null,
						'operation' => $item['operation'] ?? null,
					),
					static function ( $value ) {
						return null !== $value && '' !== $value;
					}
				);
			} elseif ( 'unknown' === $status ) {
				$resend[] = (string) $item['id'];
			} else {
				++$summary['still']; // processing: هنوز کار می‌کند.
			}
		}

		$applied = $apply ? Results::apply( $apply ) : array(
			'applied' => array(),
			'ignored' => array(),
			'failed'  => array(),
		);

		$summary['applied'] = count( $applied['applied'] );

		foreach ( $resend as $id ) {
			if ( AiBackend::resend( $id ) ) {
				++$summary['resent'];
			} else {
				Records::release_processing( array( $id ) ); // loading برای همیشه نماند.
				RemovedStore::release_deleting( array( $id ) );
			}
		}

		if ( $summary['applied'] > 0 || $summary['resent'] > 0 || $applied['failed'] ) {
			LogStore::add(
				$applied['failed'] ? LogStore::ERROR : LogStore::SUCCESS,
				sprintf(
					/* translators: 1: applied, 2: failed, 3: resent */
					__( 'بازبینی از سرور هوش مصنوعی: %1$s مورد اعمال شد، %2$s مورد ناموفق بود، %3$s مورد دوباره ارسال شد.', 'persian-ai-assistant' ),
					number_format_i18n( $summary['applied'] ),
					number_format_i18n( count( $applied['failed'] ) ),
					number_format_i18n( $summary['resent'] )
				),
				array_filter(
					array(
						'method'      => 'POST',
						'endpoint'    => '/v1/jobs/status',
						'http_status' => 200,
						'duration_ms' => $response['duration_ms'],
						'request'     => array( 'count' => count( $ids ) ),
						'failed'      => $applied['failed'] ? wp_json_encode( array_slice( $applied['failed'], 0, 20 ), JSON_UNESCAPED_UNICODE ) : '',
					),
					static function ( $value ) {
						return '' !== $value && null !== $value;
					}
				)
			);
		}

		return $summary;
	}
}
