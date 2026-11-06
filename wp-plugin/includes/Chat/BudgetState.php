<?php
namespace PersianWPChatbot\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * وضعیت سقف مصرف دلاری (S-2) که سرور با POST /pwc/v1/ai/budget می‌فرستد. فقط با get_option خوانده می‌شود (بدون درخواست شبکه).
 * option: pwc_budget_state (autoload=no) = {day:{level,used,limit,percent,period_end}, month:{…}, updated_at}
 * ردیفی که period_end آن گذشته نادیده گرفته می‌شود (خودپاک‌شو).
 */
final class BudgetState {

	public const OPTION = 'pwc_budget_state';

	private const PERIODS = array( 'day', 'month' );
	private const LEVELS  = array( 'ok', 'warn80', 'limit100' );

	/** @return array<string,mixed> */
	private static function load(): array {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * @param array<string,mixed> $e بدنهٔ رویداد سرور.
	 */
	public static function apply_event( array $e ): bool {
		$period = isset( $e['period'] ) ? (string) $e['period'] : '';
		$level  = isset( $e['level'] ) ? (string) $e['level'] : '';
		if ( ! in_array( $period, self::PERIODS, true ) || ! in_array( $level, self::LEVELS, true ) ) {
			return false;
		}
		$state            = self::load();
		$state[ $period ] = array(
			'level'      => $level,
			'used'       => max( 0.0, (float) ( $e['used_usd'] ?? 0 ) ),
			'limit'      => max( 0.0, (float) ( $e['limit_usd'] ?? 0 ) ),
			'percent'    => max( 0, (int) ( $e['percent'] ?? 0 ) ),
			'period_end' => max( 0, (int) ( $e['period_end'] ?? 0 ) ),
		);
		$state['updated_at'] = time();
		update_option( self::OPTION, $state, false );
		return true;
	}

	/** سرور budget_exceeded داد (برای وقتی callback گم شده): همان دوره limit100 می‌شود. */
	public static function mark_limit100( string $period, int $period_end = 0 ): void {
		if ( ! in_array( $period, self::PERIODS, true ) ) {
			return;
		}
		$state = self::load();
		$old   = isset( $state[ $period ] ) && is_array( $state[ $period ] ) ? $state[ $period ] : array();
		$limit = (float) ChatSettings::get( 'day' === $period ? 'budget_daily_usd' : 'budget_monthly_usd', 0 );
		$state[ $period ] = array(
			'level'      => 'limit100',
			'used'       => (float) ( $old['used'] ?? $limit ),
			'limit'      => (float) ( $old['limit'] ?? $limit ),
			'percent'    => 100,
			'period_end' => $period_end > time() ? $period_end : time() + HOUR_IN_SECONDS,
		);
		$state['updated_at'] = time();
		update_option( self::OPTION, $state, false );
	}

	/**
	 * خطوط بنر برای بالای تب «عمومی». رنگ بنر = بدترین سطح.
	 *
	 * @return array{level:string,lines:string[]}|null
	 */
	public static function banner(): ?array {
		$state = self::load();
		$lines = array();
		$worst = 'ok';
		foreach ( self::PERIODS as $p ) {
			$e = isset( $state[ $p ] ) && is_array( $state[ $p ] ) ? $state[ $p ] : null;
			if ( ! $e || (int) $e['period_end'] <= time() || 'ok' === $e['level'] ) {
				continue;
			}
			$name   = 'day' === $p ? __( 'روزانه', 'persian-wp-chatbot' ) : __( 'ماهانه', 'persian-wp-chatbot' );
			$amount = sprintf( '%s / %s $', number_format_i18n( (float) $e['used'], 2 ), number_format_i18n( (float) $e['limit'], 2 ) );
			if ( 'limit100' === $e['level'] ) {
				$worst   = 'limit100';
				$lines[] = sprintf( /* translators: 1: daily/monthly 2: amount */ __( 'سقف مصرف %1$s تمام شد؛ هوش مصنوعی تا شروع دورهٔ بعد پاسخ نمی‌دهد. (%2$s)', 'persian-wp-chatbot' ), $name, $amount );
			} else {
				$worst   = 'limit100' === $worst ? $worst : 'warn80';
				$lines[] = sprintf( /* translators: 1: daily/monthly 2: amount */ __( '۸۰٪ از سقف مصرف %1$s به پایان رسیده است. (%2$s)', 'persian-wp-chatbot' ), $name, $amount );
			}
		}
		return $lines ? array( 'level' => $worst, 'lines' => $lines ) : null;
	}
}
