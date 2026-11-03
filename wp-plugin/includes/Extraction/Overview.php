<?php
namespace PersianAIAssistant\Extraction;

use PersianAIAssistant\Database\ItemStore;
use PersianAIAssistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * آمار اطلاعات ثبت‌شده در جدول paia_items + زمان آخرین به‌روزرسانی + تنظیم استخراج خودکار.
 * کلیدهای Settings: last_extraction_at (timestamp)، auto_extract_new (bool).
 */
final class Overview {

	public static function touch(): void {
		Settings::update( array( 'last_extraction_at' => time() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$counts  = ItemStore::counts();
		$sources = array();
		$total   = 0;
		$trained = 0;

		foreach ( Sources::all() as $id => $source ) {
			$cat             = Sources::record_category( $id );
			$row             = $counts[ $cat ] ?? array( 'total' => 0, 'trained' => 0 );
			$sources[ $id ]  = array(
				'label'   => $source['label'],
				'total'   => $row['total'],
				'trained' => $row['trained'],
			);
			$total          += $row['total'];
			$trained        += $row['trained'];
		}

		$last = (int) Settings::get( 'last_extraction_at', 0 );

		return array(
			'sources'    => $sources,
			'total'      => $total,
			'trained'    => $trained,
			'site'       => isset( $counts['site'] ),
			'last_at'    => $last,
			'last_label' => $last ? self::label( $last ) : '',
			'auto_new'   => (bool) Settings::get( 'auto_extract_new', false ),
		);
	}

	private static function label( int $timestamp ): string {
		$format = trim( get_option( 'date_format', 'j F Y' ) . ' - ' . get_option( 'time_format', 'H:i' ) );

		/* translators: %s: human readable time difference, e.g. "3 hours" */
		$ago = sprintf( __( '%s پیش', 'persian-ai-assistant' ), human_time_diff( $timestamp, time() ) );

		return wp_date( $format, $timestamp ) . ' (' . $ago . ')';
	}
}
