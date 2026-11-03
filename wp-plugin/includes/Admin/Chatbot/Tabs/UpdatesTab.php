<?php
namespace PersianAIAssistant\Admin\Chatbot\Tabs;

use PersianAIAssistant\Database\LiveStore;

defined( 'ABSPATH' ) || exit;

/**
 * تب «اطلاعات به‌روز»: اطلاعاتی که روزانه تغییر می‌کنند و هوش مصنوعی باید موقع پاسخ‌دادن از سایت استعلام بگیرد.
 * فرم‌ها: «تنظیمات اطلاعات به‌روز» برای هر منبع (تمام محصولات، تمام وبلاگ‌ها، تمام برگه‌ها) و «افزودن اطلاعات به‌روز».
 * لیست بالا (سه مورد ثابت اول: محصولات، وبلاگ‌ها، برگه‌ها) و فرم زیر آن با JS (`initLive`) کار می‌کند؛ ذخیره در جدول‌های `paia_live_*` (LiveStore).
 * فیلدی که اینجا تیک بخورد از اطلاعات استخراج‌شدهٔ سایت حذف می‌شود (Extraction\LiveFields).
 * اتصال به ابزارهای هوش مصنوعی (tools) در مرحلهٔ بعد اضافه می‌شود.
 */
final class UpdatesTab extends AbstractTab {

	public function id(): string {
		return 'updates';
	}

	public function title(): string {
		return __( 'اطلاعات به‌روز', 'persian-ai-assistant' );
	}

	/**
	 * منبع‌هایی که فرم «اطلاعات به‌روز» دارند. فیلدها باید با LiveStore::FIELDS یکی باشند. پیش‌فرض همه تیک‌خورده است.
	 *
	 * @return array<string, array{title:string, save:string, lead:string, fields:array<string, array{label:string, description:string, icon:string}>}>
	 */
	private function source_defs(): array {
		$lead = __( 'مواردی را تیک بزنید که اطلاعاتشان روزانه تغییر می‌کند؛ مقدار آن‌ها هنگام پاسخ‌دادن، زنده از سایت گرفته می‌شود و دیگر جزو اطلاعات استخراج‌شده‌ای که به هوش مصنوعی آموزش داده می‌شود نیست. مواردی که تغییر نمی‌کنند را بدون تیک بگذارید.', 'persian-ai-assistant' );

		$modified = array(
			'label'       => __( 'تاریخ آخرین ویرایش', 'persian-ai-assistant' ),
			'description' => __( 'زمان آخرین به‌روزرسانی محتوا', 'persian-ai-assistant' ),
			'icon'        => 'clock',
		);

		return array(
			'products' => array(
				'title'  => __( 'تنظیمات اطلاعات به‌روز محصولات', 'persian-ai-assistant' ),
				'save'   => __( 'ذخیره تنظیمات محصولات', 'persian-ai-assistant' ),
				'lead'   => $lead,
				'fields' => array(
					'price'         => array(
						'label'       => __( 'قیمت', 'persian-ai-assistant' ),
						'description' => __( 'قیمت فعلی فروش محصول', 'persian-ai-assistant' ),
						'icon'        => 'tag',
					),
					'regular_price' => array(
						'label'       => __( 'قیمت اصلی', 'persian-ai-assistant' ),
						'description' => __( 'قیمت محصول پیش از اعمال تخفیف', 'persian-ai-assistant' ),
						'icon'        => 'wallet',
					),
					'on_sale'       => array(
						'label'       => __( 'در حراج است', 'persian-ai-assistant' ),
						'description' => __( 'اینکه محصول همین الان تخفیف دارد یا نه', 'persian-ai-assistant' ),
						'icon'        => 'percent',
					),
					'stock_status'  => array(
						'label'       => __( 'وضعیت موجودی', 'persian-ai-assistant' ),
						'description' => __( 'موجود، ناموجود یا در انتظار رسیدن', 'persian-ai-assistant' ),
						'icon'        => 'package',
					),
					'stock_qty'     => array(
						'label'       => __( 'تعداد موجودی', 'persian-ai-assistant' ),
						'description' => __( 'تعداد باقی‌ماندهٔ محصول در انبار', 'persian-ai-assistant' ),
						'icon'        => 'hash',
					),
					'backorder'     => array(
						'label'       => __( 'پیش‌سفارش', 'persian-ai-assistant' ),
						'description' => __( 'اینکه امکان پیش‌سفارش محصول فعال است یا نه', 'persian-ai-assistant' ),
						'icon'        => 'clock',
					),
				),
			),
			'blog'     => array(
				'title'  => __( 'تنظیمات اطلاعات به‌روز وبلاگ‌ها', 'persian-ai-assistant' ),
				'save'   => __( 'ذخیره تنظیمات وبلاگ‌ها', 'persian-ai-assistant' ),
				'lead'   => $lead,
				'fields' => array(
					'comment_count' => array(
						'label'       => __( 'تعداد دیدگاه‌ها', 'persian-ai-assistant' ),
						'description' => __( 'تعداد دیدگاه‌های تأییدشدهٔ زیر نوشته', 'persian-ai-assistant' ),
						'icon'        => 'hash',
					),
					'modified'      => $modified,
				),
			),
			'pages'    => array(
				'title'  => __( 'تنظیمات اطلاعات به‌روز برگه‌ها', 'persian-ai-assistant' ),
				'save'   => __( 'ذخیره تنظیمات برگه‌ها', 'persian-ai-assistant' ),
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
			$sources[ $id ] = $def + array( 'state' => LiveStore::source( $id ) );
		}

		return array( 'sources' => $sources );
	}
}
