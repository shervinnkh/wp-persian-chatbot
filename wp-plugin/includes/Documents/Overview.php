<?php
namespace PersianAIAssistant\Documents;

use PersianAIAssistant\Database\FileStore;
use PersianAIAssistant\Database\TextStore;

defined( 'ABSPATH' ) || exit;

/**
 * آمار کارت «مطالب اضافه شده» در تب «اسناد اضافه»: فایل‌های آپلودشده + متن‌های اضافه‌شده.
 */
final class Overview {

	/**
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		return array(
			'files' => self::files(),
			'texts' => TextStore::stats(),
		);
	}

	/**
	 * فایل‌های آپلودشده (از جدول paia_files): مجموع، تعداد هر فرمت و تعداد آموزش‌دیده‌ها.
	 * فرمت‌ها: PDF، Word (doc و docx با هم)، TXT.
	 *
	 * @return array{total:int, trained:int, formats:array<int, array{id:string, label:string, count:int}>}
	 */
	private static function files(): array {
		$stats   = FileStore::stats();
		$formats = array();
		foreach ( array(
			'pdf'  => 'PDF',
			'word' => 'Word',
			'txt'  => 'TXT',
		) as $id => $label ) {
			$formats[] = array(
				'id'    => $id,
				'label' => $label,
				'count' => (int) ( $stats['groups'][ $id ] ?? 0 ),
			);
		}

		return array(
			'total'   => $stats['total'],
			'trained' => $stats['trained'],
			'formats' => $formats,
		);
	}
}
