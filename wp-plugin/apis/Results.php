<?php
namespace PersianAIAssistant\Apis;

use PersianAIAssistant\Database\RemovedStore;

defined( 'ABSPATH' ) || exit;

/**
 * اعمال «نتیجهٔ پردازش» سرور هوش مصنوعی روی رکوردها — هستهٔ مشترک.
 *
 * هم وب‌هوک (ResultsEndpoint) و هم هر مسیر دیگری (مثلاً کرونِ «بازبینی» که از سرور وضعیت می‌پرسد) باید فقط همین را صدا بزنند
 * تا قانون‌ها یکسان بماند. عملیات ایدمپوتنت است؛ تکرار یک نتیجه ضرری ندارد.
 *
 * هر نتیجه:
 *   id     (الزامی) شناسهٔ رکورد: product:23 ، blog:5 ، page:7 ، site:1 ، text:1700… ، file:1700…
 *   status (الزامی) trained | untrained | failed
 *   operation (اختیاری) فقط با failed: «delete» یعنی حذفِ رکورد در سرور شکست خورد و رکورد هنوز آموزش‌دیده است ← ai_status دوباره ۲.
 *           trained ← ai_status = 2 (پردازش‌شده) · untrained ← ۰ · failed ← اگر «در حال پردازش» (۱) بود به ۰ برمی‌گردد + لاگ
 *   rev    (اختیاری) نسخه‌ای که افزونه موقع ارسال داده بود؛ برای trained اگر با نسخهٔ فعلی نخواند، نتیجه «کهنه» است و نادیده می‌ماند.
 *   error  (اختیاری، برای failed) متن خطا.
 */
final class Results {

	public const TRAINED   = 'trained';
	public const UNTRAINED = 'untrained';
	public const FAILED    = 'failed';

	/** سقف تعداد نتیجه در هر درخواست (سرور باید دسته‌دسته بفرستد). */
	public const MAX_RESULTS = 500;

	/**
	 * @param array<int, mixed> $results
	 * @return array{applied:string[], ignored:array<int, array{id:string, reason:string}>, failed:array<int, array{id:string, error:string}>}
	 */
	public static function apply( array $results ): array {
		$applied = array();
		$ignored = array();
		$failed  = array();
		$released = array();
		$restore  = array(); // kind => ids؛ حذفِ شکست‌خورده ← ai_status دوباره ۲

		// نرمال‌سازی + حذف تکراری (آخرین مورد برای هر شناسه معتبر است).
		$clean = array();
		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id     = isset( $row['id'] ) && is_scalar( $row['id'] ) ? trim( (string) $row['id'] ) : '';
			$status = isset( $row['status'] ) && is_scalar( $row['status'] ) ? strtolower( trim( (string) $row['status'] ) ) : '';

			if ( '' === $id || strlen( $id ) > 191 ) {
				$ignored[] = array(
					'id'     => substr( $id, 0, 40 ),
					'reason' => 'invalid_id',
				);
				continue;
			}
			if ( ! in_array( $status, array( self::TRAINED, self::UNTRAINED, self::FAILED ), true ) ) {
				$ignored[] = array(
					'id'     => $id,
					'reason' => 'invalid_status',
				);
				continue;
			}

			$clean[ $id ] = array(
				'status' => $status,
				'rev'    => isset( $row['rev'] ) && is_scalar( $row['rev'] ) ? (string) $row['rev'] : '',
				'error'  => isset( $row['error'] ) && is_scalar( $row['error'] ) ? mb_substr( sanitize_text_field( (string) $row['error'] ), 0, 300 ) : '',
				'delete' => isset( $row['operation'] ) && is_scalar( $row['operation'] ) && 'delete' === strtolower( trim( (string) $row['operation'] ) ),
			);
		}

		// گروه‌بندی بر اساس نوع رکورد و وضعیت تا هر گروه با یک یا چند کوئری دسته‌ای انجام شود.
		$groups = array();
		foreach ( $clean as $id => $row ) {
			if ( self::FAILED === $row['status'] ) {
				$failed[] = array(
					'id'    => $id,
					'error' => $row['error'],
				);
				if ( $row['delete'] ) {
					$restore[ Records::kind_of( $id ) ][] = $id; // حذف شکست خورد: رکورد هنوز آموزش‌دیده است
				} else {
					$released[] = $id;
				}
				continue;
			}
			$groups[ Records::kind_of( $id ) ][ $row['status'] ][] = $id;
		}

		// حذف در سرور شکست خورد (operation = delete): ai_status دوباره ۲ و ردیف «در حال حذف» لیست حذف‌شده‌ها آزاد می‌شود.
		foreach ( $restore as $kind => $ids ) {
			Records::set_status( $kind, $ids, Records::STATUS_DONE );
			RemovedStore::release_deleting( $ids );
		}

		// پردازش در سرور شکست خورده: آیکن «در حال پردازش» نباید برای همیشه بماند (فقط رکوردهای ۱ → ۰).
		if ( $released ) {
			Records::release_processing( $released );
			RemovedStore::release_deleting( $released ); // حذفِ ردیفِ «موارد حذف‌شده» در سرور شکست خورد: دوباره قابل حذف.
		}

		$unknown = array(); // «untrained» برای شناسه‌ای که در جدول‌های رکورد نیست: شاید ردیف «در حال حذف» لیست موارد حذف‌شده باشد.

		foreach ( $groups as $kind => $by_status ) {
			foreach ( $by_status as $status => $ids ) {
				$known = Records::revisions( $kind, $ids );
				$apply = array();

				foreach ( $ids as $id ) {
					if ( ! isset( $known[ $id ] ) ) {
						if ( self::UNTRAINED === $status ) {
							$unknown[] = $id;
						}
						$ignored[] = array(
							'id'     => $id,
							'reason' => 'not_found',
						);
						continue;
					}

					// فقط برای «آموزش‌دیده»: اگر رکورد بعد از ارسال ویرایش شده، نتیجه مال نسخهٔ قبلی است.
					$sent = $clean[ $id ]['rev'];
					if ( self::TRAINED === $status && '' !== $sent && '' !== $known[ $id ] && ! hash_equals( $known[ $id ], $sent ) ) {
						$ignored[] = array(
							'id'     => $id,
							'reason' => 'stale',
						);
						continue;
					}

					$apply[] = $id;
				}

				if ( $apply ) {
					Records::set_status( $kind, $apply, self::TRAINED === $status ? Records::STATUS_DONE : Records::STATUS_NONE );
					$applied = array_merge( $applied, $apply );
				}
			}
		}

		if ( $unknown ) {
			$gone = RemovedStore::finish( $unknown );
			if ( $gone ) {
				$applied = array_merge( $applied, $gone );
				$ignored = array_values(
					array_filter(
						$ignored,
						static function ( $row ) use ( $gone ) {
							return ! in_array( $row['id'], $gone, true );
						}
					)
				);
			}
		}

		$summary = array(
			'applied' => $applied,
			'ignored' => $ignored,
			'failed'  => $failed,
		);

		/**
		 * پس از اعمال نتیجهٔ سرور.
		 *
		 * @param array<string, mixed> $summary applied / ignored / failed
		 */
		do_action( 'paia_ai_results_applied', $summary );

		return $summary;
	}
}
