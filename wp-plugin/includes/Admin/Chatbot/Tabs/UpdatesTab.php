<?php
namespace PersianWPChatbot\Admin\Chatbot\Tabs;

use PersianWPChatbot\Database\LiveStore;

defined( 'ABSPATH' ) || exit;

/**
 * تب «اطلاعات به‌روز»: اطلاعاتی که روزانه تغییر می‌کنند و هوش مصنوعی باید موقع پاسخ‌دادن از سایت استعلام بگیرد.
 * فرم‌ها: «تنظیمات اطلاعات به‌روز» برای هر منبع (تمام محصولات، تمام وبلاگ‌ها، تمام برگه‌ها) و «افزودن اطلاعات به‌روز».
 * لیست بالا (سه مورد ثابت اول: محصولات، وبلاگ‌ها، برگه‌ها) و فرم زیر آن با JS (`initLive`) کار می‌کند؛ ذخیره در جدول‌های `pwc_live_*` (LiveStore).
 * فیلدی که اینجا تیک بخورد از اطلاعات استخراج‌شدهٔ سایت حذف می‌شود (Extraction\LiveFields).
 * اتصال به ابزارهای هوش مصنوعی (tools) در مرحلهٔ بعد اضافه می‌شود.
 */
final class UpdatesTab extends AbstractTab {

	public function id(): string {
		return 'updates';
	}

	public function title(): string {
		return __( 'اطلاعات به‌روز', 'persian-wp-chatbot' );
	}

	/**
	 * منبع‌هایی که فرم «اطلاعات به‌روز» دارند. فیلدها باید با LiveStore::FIELDS یکی باشند. پیش‌فرض همه تیک‌خورده است.
	 *
	 * @return array<string, array{title:string, save:string, lead:string, fields:array<string, array{label:string, description:string, icon:string}>}>
	 */
	private function source_defs(): array {
		$lead = __( 'مواردی را تیک بزنید که اطلاعاتشان روزانه تغییر می‌کند؛ مقدار آن‌ها هنگام پاسخ‌دادن، زنده از سایت گرفته می‌شود و دیگر جزو اطلاعات استخراج‌شده‌ای که به هوش مصنوعی آموزش داده می‌شود نیست. مواردی که تغییر نمی‌کنند را بدون تیک بگذارید.', 'persian-wp-chatbot' );

		$modified = array(
			'label'       => __( 'تاریخ آخرین ویرایش', 'persian-wp-chatbot' ),
			'description' => __( 'زمان آخرین به‌روزرسانی محتوا', 'persian-wp-chatbot' ),
			'icon'        => 'clock',
		);

		return array(
			'products' => array(
				'title'  => __( 'تنظیمات اطلاعات به‌روز محصولات', 'persian-wp-chatbot' ),
				'save'   => __( 'ذخیره تنظیمات محصولات', 'persian-wp-chatbot' ),
				'lead'   => $lead,
				'fields' => array(
					'price'         => array(
						'label'       => __( 'قیمت', 'persian-wp-chatbot' ),
						'description' => __( 'قیمت فعلی فروش محصول', 'persian-wp-chatbot' ),
						'icon'        => 'tag',
					),
					'regular_price' => array(
						'label'       => __( 'قیمت اصلی', 'persian-wp-chatbot' ),
						'description' => __( 'قیمت محصول پیش از اعمال تخفیف', 'persian-wp-chatbot' ),
						'icon'        => 'wallet',
					),
					'on_sale'       => array(
						'label'       => __( 'در حراج است', 'persian-wp-chatbot' ),
						'description' => __( 'اینکه محصول همین الان تخفیف دارد یا نه', 'persian-wp-chatbot' ),
						'icon'        => 'percent',
					),
					'stock_status'  => array(
						'label'       => __( 'وضعیت موجودی', 'persian-wp-chatbot' ),
						'description' => __( 'موجود، ناموجود یا در انتظار رسیدن', 'persian-wp-chatbot' ),
						'icon'        => 'package',
					),
					'stock_qty'     => array(
						'label'       => __( 'تعداد موجودی', 'persian-wp-chatbot' ),
						'description' => __( 'تعداد باقی‌ماندهٔ محصول در انبار', 'persian-wp-chatbot' ),
						'icon'        => 'hash',
					),
					'backorder'     => array(
						'label'       => __( 'پیش‌سفارش', 'persian-wp-chatbot' ),
						'description' => __( 'اینکه امکان پیش‌سفارش محصول فعال است یا نه', 'persian-wp-chatbot' ),
						'icon'        => 'clock',
					),
				),
			),
			'blog'     => array(
				'title'  => __( 'تنظیمات اطلاعات به‌روز وبلاگ‌ها', 'persian-wp-chatbot' ),
				'save'   => __( 'ذخیره تنظیمات وبلاگ‌ها', 'persian-wp-chatbot' ),
				'lead'   => $lead,
				'fields' => array(
					'comment_count' => array(
						'label'       => __( 'تعداد دیدگاه‌ها', 'persian-wp-chatbot' ),
						'description' => __( 'تعداد دیدگاه‌های تأییدشدهٔ زیر نوشته', 'persian-wp-chatbot' ),
						'icon'        => 'hash',
					),
					'modified'      => $modified,
				),
			),
			'pages'    => array(
				'title'  => __( 'تنظیمات اطلاعات به‌روز برگه‌ها', 'persian-wp-chatbot' ),
				'save'   => __( 'ذخیره تنظیمات برگه‌ها', 'persian-wp-chatbot' ),
				'lead'   => $lead,
				'fields' => array(
					'modified' => $modified,
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function data(): array {
		$sources = array();
		foreach ( $this->source_defs() as $id => $def ) {
			if ( LiveStore::is_hidden( (string) $id ) ) {
				continue; // وبلاگ‌ها و برگه‌ها فعلاً نمایش داده نمی‌شوند (کدشان نگه داشته شده است).
			}
			$sources[ $id ] = $def + array( 'state' => LiveStore::source( $id ) );
		}

		return array( 'sources' => $sources );
	}
}
