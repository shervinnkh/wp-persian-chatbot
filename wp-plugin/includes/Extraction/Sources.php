<?php
namespace PersianWPChatbot\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * منابع قابل استخراج از سایت؛ هم برای نمایش فرم و هم اعتبارسنجی REST استفاده می‌شود.
 * منبع جدید فقط با فیلتر pwc_extraction_sources یا افزودن به همین لیست اضافه می‌شود.
 */
final class Sources {

	/**
	 * @return array<string, array{label:string, description:string, record:string, icon:string, default:bool}>
	 */
	public static function all(): array {
		$sources = array(
			'products' => array(
				'label'       => __( 'محصولات', 'persian-wp-chatbot' ),
				'description' => __( 'نام، توضیحات، قیمت و ویژگی‌های محصولات', 'persian-wp-chatbot' ),
				'record'      => 'product',
				'icon'        => 'shopping-bag',
				'default'     => true,
			),
			'blog'     => array(
				'label'       => __( 'وبلاگ', 'persian-wp-chatbot' ),
				'description' => __( 'نوشته‌ها و مقالات منتشرشده', 'persian-wp-chatbot' ),
				'record'      => 'blog',
				'icon'        => 'newspaper',
				'default'     => true,
			),
			'pages'    => array(
				'label'       => __( 'برگه‌های عمومی', 'persian-wp-chatbot' ),
				'description' => __( 'برگه‌های ثابت مثل درباره ما و قوانین', 'persian-wp-chatbot' ),
				'record'      => 'page',
				'icon'        => 'file-text',
				'default'     => false,
			),
		);

		return (array) apply_filters( 'pwc_extraction_sources', $sources );
	}

	/**
	 * پیشوند شناسهٔ رکوردهای ذخیره‌شده در جدول (مثلاً product برای product:23).
	 */
	public static function record_category( string $source ): string {
		$all = self::all();

		return isset( $all[ $source ]['record'] ) ? (string) $all[ $source ]['record'] : sanitize_key( $source );
	}

	/**
	 * @return string[]
	 */
	public static function ids(): array {
		return array_keys( self::all() );
	}
}
