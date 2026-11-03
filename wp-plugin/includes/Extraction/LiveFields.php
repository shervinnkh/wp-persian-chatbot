<?php
namespace PersianAIAssistant\Extraction;

use PersianAIAssistant\Database\LiveStore;

defined( 'ABSPATH' ) || exit;

/**
 * اطلاعات «به‌روز» (قیمت، موجودی، تعداد دیدگاه، تاریخ ویرایش ...) نباید جزو اطلاعات استخراج‌شده باشند:
 * هوش مصنوعی آن‌ها را هنگام پاسخ، زنده از سایت می‌گیرد (تب «اطلاعات به‌روز»). این کلاس هنگام ساخت هر آیتم
 * (استخراج دستی و ذخیرهٔ خودکار هر دو)، کلیدهای زندهٔ آن منبع را از آیتم برمی‌دارد؛ پس در جدول paia_items ذخیره،
 * در «جزئیات» نمایش و برای هوش مصنوعی ارسال نمی‌شوند.
 *
 * فیلد «زنده» است اگر در تب «اطلاعات به‌روز» ردیف آن منبع فعال و تیک فیلد زده شده باشد (LiveStore::live_keys).
 * اگر مدیر تیک فیلدی را بردارد، همان فیلد دوباره جزو اطلاعات ثابت استخراج‌شده می‌شود.
 */
final class LiveFields {

	/**
	 * نگاشت «فیلد زنده» → مسیرهای داخل آیتم.
	 * هر مسیر: [کلید سطح اول، کلید زیرمجموعه] یا [کلید سطح اول]؛ کلید زیرمجموعهٔ '*' یعنی «برای هر عنصر آرایه» (مثل variations).
	 */
	private const MAP = array(
		'products' => array(
			'price'         => array( array( 'product', 'price' ), array( 'product', 'price_min' ), array( 'product', 'price_max' ), array( 'product', 'sale_price' ), array( 'product', 'edd_price' ), array( 'product', 'edd_variable_prices' ), array( 'variations', '*', 'price' ), array( 'variations', '*', 'sale_price' ) ),
			'regular_price' => array( array( 'product', 'regular_price' ), array( 'variations', '*', 'regular_price' ) ),
			'on_sale'       => array( array( 'product', 'on_sale' ), array( 'product', 'sale_from' ), array( 'product', 'sale_to' ) ),
			'stock_status'  => array( array( 'product', 'stock_status' ), array( 'variations', '*', 'stock_status' ) ),
			'stock_qty'     => array( array( 'product', 'stock_quantity' ), array( 'product', 'manage_stock' ), array( 'product', 'stock' ), array( 'variations', '*', 'stock_quantity' ) ),
			'backorder'     => array( array( 'product', 'backorders' ) ),
		),
		'blog'     => array(
			'comment_count' => array( array( 'comment_count' ) ),
			'modified'      => array( array( 'modified' ) ),
		),
		'pages'    => array(
			'modified' => array( array( 'modified' ) ),
		),
	);

	/**
	 * @param string               $source شناسهٔ منبع: products | blog | pages
	 * @param array<string, mixed> $item   آیتم ساخته‌شده توسط استخراج‌کننده
	 * @return array<string, mixed>
	 */
	public static function strip( string $source, array $item ): array {
		if ( ! isset( self::MAP[ $source ] ) ) {
			return $item;
		}

		foreach ( LiveStore::live_keys( $source ) as $field ) {
			foreach ( self::MAP[ $source ][ $field ] ?? array() as $path ) {
				self::remove( $item, $path );
			}
		}

		// ساختارهای خالی‌شده (مثلاً تنوعی که فقط قیمت داشت) برداشته می‌شوند.
		foreach ( array( 'product', 'variations' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_array( $item[ $key ] ) ) {
				$item[ $key ] = self::compact( $item[ $key ] );
				if ( array() === $item[ $key ] ) {
					unset( $item[ $key ] );
				}
			}
		}

		return $item;
	}

	/**
	 * @param array<string, mixed> $node
	 * @param string[]             $path
	 */
	private static function remove( array &$node, array $path ): void {
		$key = array_shift( $path );
		if ( null === $key ) {
			return;
		}

		if ( '*' === $key ) {
			foreach ( $node as &$child ) {
				if ( is_array( $child ) ) {
					self::remove( $child, $path );
				}
			}
			unset( $child );

			return;
		}

		if ( ! array_key_exists( $key, $node ) ) {
			return;
		}
		if ( ! $path ) {
			unset( $node[ $key ] );

			return;
		}
		if ( is_array( $node[ $key ] ) ) {
			self::remove( $node[ $key ], $path );
		}
	}

	/**
	 * مقدارهای خالی (null، رشتهٔ خالی، آرایهٔ خالی) را به‌صورت بازگشتی برمی‌دارد؛ false و 0 می‌مانند.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	private static function compact( array $data ): array {
		$out     = array();
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::compact( $value );
			}
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			if ( $is_list ) {
				$out[] = $value;
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}
}
