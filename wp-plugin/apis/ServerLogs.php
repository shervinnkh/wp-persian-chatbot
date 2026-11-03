<?php
namespace PersianAIAssistant\Apis;

use PersianAIAssistant\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * دریافت لاگ سرور هوش مصنوعی (POST /v1/get_log) — فقط وقتی مدیر دکمهٔ «به‌روزرسانی» را بزند، نه خودکار.
 *
 * نشانگر: شناسهٔ ۳ لاگِ آخری که گرفته‌ایم (option). سرور فقط خطوط بعد از آن‌ها را می‌فرستد و ما در جدول paia_logs
 * ذخیره می‌کنیم (تب «لاگ سرور» همان جدول را نشان می‌دهد).
 */
final class ServerLogs {

	private const OPTION = 'paia_serverlog_sync'; // array{ids:string[], at:int}
	private const KEEP   = 3;
	private const LIMIT  = 200;

	/**
	 * @return array{at:int, label:string}
	 */
	public static function last_update(): array {
		$saved = get_option( self::OPTION, array() );
		$at    = is_array( $saved ) ? (int) ( $saved['at'] ?? 0 ) : 0;

		return array(
			'at'    => $at,
			'label' => $at > 0 ? (string) wp_date( 'Y/m/d - H:i:s', $at ) : '',
		);
	}

	/**
	 * @return array{ok:bool, added:int, more:bool, error:string}
	 */
	public static function sync(): array {
		if ( ! Client::is_configured() ) {
			return array(
				'ok'    => false,
				'added' => 0,
				'more'  => false,
				'error' => __( 'آدرس یا کلید اتصال به سرور هوش مصنوعی تنظیم نشده است.', 'persian-ai-assistant' ),
			);
		}

		$saved = get_option( self::OPTION, array() );
		$ids   = is_array( $saved ) && isset( $saved['ids'] ) && is_array( $saved['ids'] ) ? array_values( array_map( 'strval', $saved['ids'] ) ) : array();

		$res = Client::request( 'POST', '/v1/get_log', array(
			'after' => $ids,
			'limit' => self::LIMIT,
		), 15, 1 );

		$entries = $res['body']['entries'] ?? null;
		if ( ! $res['ok'] || ! is_array( $entries ) ) {
			return array(
				'ok'    => false,
				'added' => 0,
				'more'  => false,
				'error' => '' !== (string) $res['error'] ? (string) $res['error'] : ( 'HTTP ' . (int) $res['code'] ),
			);
		}

		$added = 0;
		$last  = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			$level   = strtoupper( (string) ( $entry['level'] ?? 'INFO' ) );
			$status  = in_array( $level, array( 'ERROR', 'CRITICAL' ), true ) ? LogStore::ERROR : LogStore::INFO;
			$message = (string) ( $entry['message'] ?? '' );
			if ( '' !== (string) ( $entry['logger'] ?? '' ) ) {
				$message = $entry['logger'] . ': ' . $message;
			}
			LogStore::add(
				$status,
				mb_substr( $message, 0, 5000 ),
				array_filter(
					array(
						'level'       => $level,
						'server_time' => (string) ( $entry['time'] ?? '' ),
						'request_id'  => (string) ( $entry['request_id'] ?? '' ),
						'server_id'   => (string) $entry['id'],
					),
					'strlen'
				)
			);
			$last[] = (string) $entry['id'];
			++$added;
		}

		update_option(
			self::OPTION,
			array(
				'ids' => $last ? array_slice( $last, -self::KEEP ) : $ids,
				'at'  => time(),
			),
			false
		);

		return array(
			'ok'    => true,
			'added' => $added,
			'more'  => $added >= self::LIMIT,
			'error' => '',
		);
	}
}
