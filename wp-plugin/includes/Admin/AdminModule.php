<?php
namespace PersianWPChatbot\Admin;

use PersianWPChatbot\Admin\Pages\ChatbotPage;
use PersianWPChatbot\Admin\Pages\HomePage;
use PersianWPChatbot\Admin\Pages\LogsPage;
use PersianWPChatbot\Admin\Pages\MonitoringPage;
use PersianWPChatbot\Admin\Pages\PageInterface;
use PersianWPChatbot\Admin\Pages\ResourcesPage;
use PersianWPChatbot\Admin\Pages\SettingsPage;
use PersianWPChatbot\Chat\Unread;
use PersianWPChatbot\Contracts\Module;
use PersianWPChatbot\Database\FileStore;
use PersianWPChatbot\Database\ItemStore;
use PersianWPChatbot\Database\RemovedStore;
use PersianWPChatbot\Database\LiveStore;
use PersianWPChatbot\Database\LogStore;
use PersianWPChatbot\Documents\Overview as DocsOverview;
use PersianWPChatbot\Extraction\Labels;
use PersianWPChatbot\Extraction\Overview;
use PersianWPChatbot\Support\Icons;
use PersianWPChatbot\Extraction\Sources;
use PersianWPChatbot\Support\Capability;
use PersianWPChatbot\Support\Status;

defined( 'ABSPATH' ) || exit;

/**
 * منوی مدیریت، صفحه‌ها و فایل‌های CSS/JS بخش ادمین.
 */
final class AdminModule implements Module {

	/** @var PageInterface[] کلید: slug صفحه */
	private $pages = array();

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_css' ) );
		add_filter( 'admin_body_class', array( $this, 'filter_body_class' ) );
		add_filter( 'plugin_action_links_' . PWC_BASENAME, array( $this, 'add_action_links' ) );
	}

	public function register_menu(): void {
		// ترتیب زیرمنوها: صفحه اصلی، چت بات، منابع AI، لاگ، پنل مانیتورینگ، تنظیمات.
		$pages = array( new HomePage(), new ChatbotPage(), new ResourcesPage(), new LogsPage(), new MonitoringPage(), new SettingsPage() );

		foreach ( $pages as $page ) {
			$this->pages[ $page->slug() ] = $page;
		}

		$capability = Capability::required();
		$home       = $pages[0];

		// یک کوئری خیلی سبک در هر بارگذاری صفحهٔ مدیریت: تعداد پیام‌های خوانده‌نشدهٔ چت‌بات برای نوتیف منو.
		// وقتی چت‌بات خاموش است هیچ کوئری‌ای به جدول‌های چت زده نمی‌شود.
		$unread = ( Status::is_enabled() && current_user_can( $capability ) ) ? Unread::count() : 0;
		$bubble = Unread::bubble( $unread );

		add_menu_page(
			__( 'چت بات هوشمند', 'persian-wp-chatbot' ),
			__( 'چت بات هوشمند', 'persian-wp-chatbot' ) . $bubble,
			$capability,
			$home->slug(),
			array( $home, 'render' ),
			PWC_URL . 'assets/images/menu-icon.png',
			58
		);

		foreach ( $pages as $page ) {
			add_submenu_page(
				$home->slug(),
				$page->page_title(),
				$page->menu_title() . ( ChatbotPage::SLUG === $page->slug() ? $bubble : '' ), // نوتیف روی زیرمنوی «چت‌بات هوشمند» (با رفتن موس روی منو دیده می‌شود)
				$capability,
				$page->slug(),
				array( $page, 'render' )
			);
		}
	}

	/**
	 * آیکن منو: تصویر ۴۰×۴۰ است ولی وردپرس برای آیکن‌های تصویری پدینگ/اندازهٔ خودش را می‌دهد و
	 * (به‌ویژه در RTL) آیکن بزرگ و جابه‌جا دیده می‌شد. اینجا اندازه، وسط‌چینی و شفافیت ثابت می‌شود.
	 * روی همهٔ صفحه‌های مدیریت لازم است چون منو همه‌جا هست (admin.css فقط در صفحه‌های افزونه لود می‌شود).
	 */
	public function print_menu_icon_css(): void {
		echo '<style id="pwc-menu-icon">'
			. '#adminmenu #toplevel_page_pwc div.wp-menu-image{display:flex;align-items:center;justify-content:center;overflow:hidden}'
			. '#adminmenu #toplevel_page_pwc div.wp-menu-image img{width:20px;height:20px;max-width:20px;padding:0;margin:0;opacity:1;object-fit:contain;box-sizing:border-box;border-radius:5px}'
			. '#adminmenu #toplevel_page_pwc:hover div.wp-menu-image img,#adminmenu #toplevel_page_pwc.current div.wp-menu-image img,#adminmenu #toplevel_page_pwc.wp-has-current-submenu div.wp-menu-image img{opacity:1}'
			. '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function enqueue_assets(): void {
		$page = $this->current_page();
		if ( null === $page ) {
			return;
		}

		wp_enqueue_style( 'pwc-admin', PWC_URL . 'assets/css/admin.css', array(), PWC_VERSION );


		// پنل مانیتورینگ: اسکریپت و داده‌ٔ جدا (admin.js و کوئری‌های سنگین تب‌های چت‌بات لازم نیست).
		if ( MonitoringPage::SLUG === $page->slug() ) {
			wp_enqueue_script( 'pwc-monitoring', PWC_URL . 'assets/js/monitoring.js', array(), PWC_VERSION, true );
			wp_add_inline_script(
				'pwc-monitoring',
				'window.PWC_MONITOR = ' . wp_json_encode(
					array(
						'restUrl'    => esc_url_raw( rest_url( 'pwc/v1/' ) ),
						'nonce'      => wp_create_nonce( 'wp_rest' ),
						'configured' => \PersianWPChatbot\Apis\Client::is_configured(),
					)
				) . ';',
				'before'
			);

			return;
		}

		if ( SettingsPage::SLUG === $page->slug() ) {
			wp_enqueue_media(); // انتخاب لوگوی چت از کتابخانهٔ رسانه (تنظیمات ← مدیریت چت)
		}

		wp_enqueue_script( 'pwc-admin', PWC_URL . 'assets/js/admin.js', array(), PWC_VERSION, true );
		wp_add_inline_script(
			'pwc-admin',
			'window.PWC_ADMIN = ' . wp_json_encode( $this->script_data() ) . ';',
			'before'
		);
	}

	/**
	 * @param string $classes
	 */
	public function filter_body_class( $classes ): string {
		$classes = (string) $classes;
		$page    = $this->current_page();

		if ( null === $page ) {
			return $classes;
		}

		$classes .= ' pwc-admin-page';
		if ( $page->uses_app_shell() ) {
			$classes .= ' pwc-app-shell';
		}

		return $classes;
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public function add_action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=' . ChatbotPage::SLUG );

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'باز کردن چت‌بات', 'persian-wp-chatbot' )
			)
		);

		return $links;
	}

	private function current_page(): ?PageInterface {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $this->pages[ $slug ] ?? null;
	}

	/**
	 * داده و متن‌های قابل ترجمه که به جاوااسکریپت پاس داده می‌شود.
	 *
	 * @return array<string, mixed>
	 */
	private function script_data(): array {
		// چت‌بات خاموش: فقط داده‌ٔ لازم برای صفحهٔ اصلی (کلید وضعیت)؛ هیچ کوئری سنگینی اجرا نمی‌شود.
		if ( ! Status::is_enabled() ) {
			return array(
				'restUrl' => esc_url_raw( rest_url( 'pwc/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'enabled' => false,
				'i18n'    => $this->status_i18n(),
			);
		}

		$sources  = array();
		$defaults = array();
		foreach ( Sources::all() as $id => $source ) {
			$sources[ $id ]  = $source['label'];
			$defaults[ $id ] = ! empty( $source['default'] );
		}

		return array(
			'restUrl'  => esc_url_raw( rest_url( 'pwc/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'enabled'  => true,
			'sources'  => $sources,
			'defaults' => $defaults,
			'fields'   => Labels::fields(),
			'stats'    => Labels::stats(),
			'overview' => Overview::build(),
			'docs'     => DocsOverview::build(),
			// تب «اطلاعات به‌روز»: تنظیمات محصولات + فهرست اطلاعات تعریف‌شده (متن کامل هر مورد با REST خوانده می‌شود).
			'live'     => LiveStore::overview(),
			// آمار اولیهٔ تب «لاگ سرور» (فهرست لاگ‌ها را JS با REST می‌خواند).
			'logs'     => array_merge( LogStore::stats(), array( 'last_update' => \PersianWPChatbot\Apis\ServerLogs::last_update()['label'] ) ),
			// آپلود فایل در «اسناد اضافه»: سقف مؤثر (کمترِ ۱۰۰ مگابایت و محدودیت سرور) و پسوندهای مجاز.
			'upload'   => array(
				'max'       => FileStore::max_bytes(),
				'limit'     => FileStore::MAX_BYTES,
				'serverMax' => FileStore::server_limit(),
				'exts'      => FileStore::EXTENSIONS,
			),
			// فقط شمارنده (یک COUNT سبک)؛ فهرست کامل را خود JS با REST می‌خواند.
			'autoNew'  => array( 'count' => ItemStore::unseen_count() ),
			// موارد حذف‌شده از سایت (فقط شمارنده؛ فهرست را JS با REST می‌خواند).
			'removed'  => array( 'count' => RemovedStore::count() ),
			'icons'    => array(
				'ai'    => Icons::get( 'sparkles', 16 ),
				'trash' => Icons::get( 'trash', 16 ),
			),
			'i18n'     => array_merge( $this->status_i18n(), array(
				/* translators: %d: number of selected items */
				'selectedCount'  => __( '%d مورد انتخاب شده', 'persian-wp-chatbot' ),
				'noneSelected'   => __( 'هیچ موردی انتخاب نشده است', 'persian-wp-chatbot' ),
				'working'        => __( 'در حال استخراج…', 'persian-wp-chatbot' ),
				'networkError'   => __( 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'unknownError'   => __( 'خطای ناشناخته رخ داد.', 'persian-wp-chatbot' ),
				'progressSite'   => __( 'در حال خواندن اطلاعات کلی سایت…', 'persian-wp-chatbot' ),
				/* translators: 1: source name, 2: items loaded so far, 3: total items */
				'progressSource' => __( 'در حال استخراج «%1$s»: %2$s از %3$s', 'persian-wp-chatbot' ),
				/* translators: %s: source name */
				'progressStart'  => __( 'در حال شروع استخراج «%s»…', 'persian-wp-chatbot' ),
				'finished'       => __( 'استخراج تمام شد.', 'persian-wp-chatbot' ),
				'finishedErrors' => __( 'استخراج تمام شد، اما بعضی بخش‌ها خطا داشتند (در خلاصه ببینید).', 'persian-wp-chatbot' ),
				/* translators: %s: source name */
				'sourceFailed'   => __( 'استخراج «%s» با خطا متوقف شد: ', 'persian-wp-chatbot' ),
				'summaryItems'   => __( 'مورد پیدا شد', 'persian-wp-chatbot' ),
				'summaryNone'    => __( 'چیزی پیدا نشد', 'persian-wp-chatbot' ),
				'warnings'       => __( 'هشدار', 'persian-wp-chatbot' ),
				'warningsTitle'  => __( 'هشدارها', 'persian-wp-chatbot' ),
				'seconds'        => __( 'ثانیه', 'persian-wp-chatbot' ),
				'siteTitle'      => __( 'اطلاعات سایت و محیط', 'persian-wp-chatbot' ),
				/* translators: %s: number of items */
				'showMore'       => __( 'نمایش %s مورد بعدی', 'persian-wp-chatbot' ),
				'taxTitle'       => __( 'دسته‌بندی‌ها و طبقه‌بندی‌ها', 'persian-wp-chatbot' ),
				'itemsTitle'     => __( 'آیتم‌های استخراج‌شده', 'persian-wp-chatbot' ),
				'noItems'        => __( 'هیچ موردی استخراج نشد.', 'persian-wp-chatbot' ),
				'yes'            => __( 'بله', 'persian-wp-chatbot' ),
				'no'             => __( 'خیر', 'persian-wp-chatbot' ),
				'truncated'      => __( 'فقط بخشی از ترم‌ها نمایش داده شده است', 'persian-wp-chatbot' ),
				'chars'          => __( 'نویسه', 'persian-wp-chatbot' ),
				'emptyContent'   => __( 'بدون متن', 'persian-wp-chatbot' ),
				/* translators: %d: number of selected records */
				'treeSelected'   => __( '%d مورد برای آموزش انتخاب شده', 'persian-wp-chatbot' ),
				'treeNone'       => __( 'هیچ موردی برای آموزش انتخاب نشده است', 'persian-wp-chatbot' ),
				'aiTrained'      => __( 'به هوش مصنوعی آموزش داده شده', 'persian-wp-chatbot' ),
				'aiProcessing'   => __( 'در حال پردازش سمت سرور', 'persian-wp-chatbot' ),
				'removeAi'       => __( 'حذف از دانش هوش مصنوعی', 'persian-wp-chatbot' ),
				'removeAiConfirm' => __( 'با حذف این داده، هوش مصنوعی دیگر به آن دسترسی نخواهد داشت. آیا مطمئن هستید؟', 'persian-wp-chatbot' ),
				'removedAi'      => __( 'درخواست حذف به سرور هوش مصنوعی رفت؛ تا پایان پردازش در حال حذف است.', 'persian-wp-chatbot' ),
				'untrainFailed'  => __( 'حذف از دانش هوش مصنوعی ناموفق بود. جزئیات در صفحهٔ «لاگ» است.', 'persian-wp-chatbot' ),
				'removeSrcAi'    => __( 'حذف همهٔ آموزش‌دیده‌های این بخش از دانش هوش مصنوعی', 'persian-wp-chatbot' ),
				/* translators: %s: number of records */
				'removeSrcAiConfirm' => __( 'آموزش %s مورد از دانش هوش مصنوعی حذف می‌شود (خود اطلاعات در سایت می‌ماند). آیا مطمئن هستید؟', 'persian-wp-chatbot' ),
				'badgeNew'       => __( 'جدید', 'persian-wp-chatbot' ),
				'summaryNew'     => __( 'مورد جدید پیدا شد', 'persian-wp-chatbot' ),
				'summaryNoneNew' => __( 'مورد جدیدی پیدا نشد', 'persian-wp-chatbot' ),
				'summarySaved'   => __( 'مورد ثبت شده', 'persian-wp-chatbot' ),
				'workingNew'     => __( 'در حال استخراج اطلاعات جدید…', 'persian-wp-chatbot' ),
				'progressCheck'  => __( 'در حال بررسی «%1$s»: %2$s از %3$s', 'persian-wp-chatbot' ),
				'finishedNew'    => __( 'استخراج اطلاعات جدید تمام شد.', 'persian-wp-chatbot' ),
				'noNewFound'     => __( 'اطلاعات جدیدی پیدا نشد؛ همهٔ موارد سایت قبلاً ثبت شده‌اند.', 'persian-wp-chatbot' ),
				/* translators: %d: number of new items */
				'newFound'       => __( '%d مورد جدید پیدا و ذخیره شد.', 'persian-wp-chatbot' ),
				/* translators: %s: source name */
				'readStart'      => __( 'در حال خواندن «%s» از پایگاه داده…', 'persian-wp-chatbot' ),
				/* translators: 1: source name, 2: items read so far, 3: total items */
				'readSource'     => __( 'در حال خواندن «%1$s» از پایگاه داده: %2$s از %3$s', 'persian-wp-chatbot' ),
				'loadingSaved'   => __( 'در حال خواندن اطلاعات ثبت‌شده…', 'persian-wp-chatbot' ),
				'loadedSaved'    => __( 'اطلاعات ثبت‌شده بارگذاری شد.', 'persian-wp-chatbot' ),
				'noSaved'        => __( 'هنوز اطلاعاتی ثبت نشده است.', 'persian-wp-chatbot' ),
				'ovItems'        => __( 'مورد ثبت شده', 'persian-wp-chatbot' ),
				/* translators: %s: number of trained items */
				'ovTrained'      => __( '%s آموزش‌دیده', 'persian-wp-chatbot' ),
				/* translators: %s: number of newly added items */
				'ovAdded'        => __( '+%s جدید', 'persian-wp-chatbot' ),
				/* translators: 1: total records, 2: trained records */
				'ovTotal'        => __( 'مجموع: %1$s مورد ثبت شده، %2$s مورد آموزش‌دیده', 'persian-wp-chatbot' ),
				'ovLast'         => __( 'آخرین به‌روزرسانی اطلاعات:', 'persian-wp-chatbot' ),
				'ovNever'        => __( 'هنوز اطلاعاتی استخراج نشده است. ابتدا استخراج اولیه را انجام دهید.', 'persian-wp-chatbot' ),
				/* translators: %s: number of items */
				'anCount'        => __( '%s مورد', 'persian-wp-chatbot' ),
				'anLoading'      => __( 'در حال خواندن موارد جدید…', 'persian-wp-chatbot' ),
				'anLoadFailed'   => __( 'خواندن موارد جدید ناموفق بود.', 'persian-wp-chatbot' ),
				'anSeenFailed'   => __( 'ثبت «دیده شد» ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				/* translators: %s: number of items not shown in the list */
				'anMore'         => __( 'و %s مورد دیگر (بعد از «همه را دیدم» نمایش داده می‌شوند)', 'persian-wp-chatbot' ),
				// تب «اسناد اضافه»
				'dcFiles'        => __( 'فایل‌های آپلود شده', 'persian-wp-chatbot' ),
				'dcFilesHint'    => __( 'فایل', 'persian-wp-chatbot' ),
				'dcTexts'        => __( 'متن‌های اضافه شده', 'persian-wp-chatbot' ),
				'dcTextsHint'    => __( 'باکس متن', 'persian-wp-chatbot' ),
				/* translators: %s: number of trained items */
				'dcTrained'      => __( '%s آموزش‌دیده', 'persian-wp-chatbot' ),
				/* translators: %s: number of chunks */
				'dcChunks'       => __( '%s چانک', 'persian-wp-chatbot' ),
				/* translators: %s: number of words */
				'dcWords'        => __( '%s کلمه', 'persian-wp-chatbot' ),
				/* translators: %s: number of draft texts */
				'dcDrafts'       => __( '%s پیش‌نویس', 'persian-wp-chatbot' ),
				/* translators: %s: number of characters */
				'dcChars'        => __( '%s نویسه', 'persian-wp-chatbot' ),
				'dcPlaceholder'  => __( 'متن را اینجا بنویسید… (برای جدا کردن چانک‌ها دو بار Enter بزنید)', 'persian-wp-chatbot' ),
				'dcEmpty'        => __( 'هنوز متنی نوشته نشده است', 'persian-wp-chatbot' ),
				'dcSaving'       => __( 'در حال ذخیره…', 'persian-wp-chatbot' ),
				'dcDraftSaved'   => __( 'پیش‌نویس ذخیره شد.', 'persian-wp-chatbot' ),
				'dcTrainSaved'   => __( 'متن در پایگاه داده ذخیره شد و برای آموزش آماده است. ارسال به هوش مصنوعی بعد از اتصال به سرویس انجام می‌شود.', 'persian-wp-chatbot' ),
				'dcFailed'       => __( 'ذخیره ناموفق بود. متن شما حفظ شده است؛ دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				// لیست مطالب (تب «اسناد اضافه»)
				'dcTabTexts'      => __( 'متن‌ها', 'persian-wp-chatbot' ),
				'dcTabFiles'      => __( 'فایل‌ها', 'persian-wp-chatbot' ),
				'dcListLoading'   => __( 'در حال خواندن فهرست…', 'persian-wp-chatbot' ),
				'dcListFailed'    => __( 'خواندن فهرست ناموفق بود.', 'persian-wp-chatbot' ),
				'dcListEmptyText' => __( 'هنوز متنی اضافه نشده است.', 'persian-wp-chatbot' ),
				'dcListEmptyFile' => __( 'هنوز فایلی آپلود نشده است.', 'persian-wp-chatbot' ),
				'dcUntitled'      => __( '(بدون عنوان)', 'persian-wp-chatbot' ),
				'dcDraftChip'     => __( 'پیش‌نویس', 'persian-wp-chatbot' ),
				'dcRemovedChip'   => __( 'از هوش مصنوعی حذف شد', 'persian-wp-chatbot' ),
				'dcRemoveAi'      => __( 'حذف آموزش', 'persian-wp-chatbot' ),
				'dcTrainBtn'      => __( 'آموزش به هوش مصنوعی', 'persian-wp-chatbot' ),
				'dcDownload'      => __( 'دانلود فایل', 'persian-wp-chatbot' ),
				'dcDeleteFull'    => __( 'حذف کامل', 'persian-wp-chatbot' ),
				'dcDelTextAsk'    => __( 'این باکس متن برای همیشه از پایگاه داده حذف می‌شود و قابل بازگشت نیست. آیا مطمئن هستید؟', 'persian-wp-chatbot' ),
				'dcDelFileAsk'    => __( 'فایل برای همیشه از سایت پاک می‌شود و رکوردش هم از پایگاه داده حذف می‌شود؛ این کار قابل بازگشت نیست. آیا مطمئن هستید؟', 'persian-wp-chatbot' ),
				'dcDeleted'       => __( 'حذف شد.', 'persian-wp-chatbot' ),
				'dcDeleteFailed'  => __( 'حذف ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'dcItemTrained'   => __( 'برای پردازش به سرور فرستاده شد؛ بعد از پایان پردازش آیکن هوش مصنوعی نمایش داده می‌شود.', 'persian-wp-chatbot' ),
				'dcItemReady'     => __( 'برای آموزش ثبت شد، اما سرور آن را نپذیرفت. بعداً دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'dcItemFailed'    => __( 'آموزش ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'dcDetailFailed'  => __( 'خواندن جزئیات ناموفق بود.', 'persian-wp-chatbot' ),
				'dcFTitle'        => __( 'عنوان', 'persian-wp-chatbot' ),
				'dcFName'         => __( 'نام فایل', 'persian-wp-chatbot' ),
				'dcFFormat'       => __( 'فرمت', 'persian-wp-chatbot' ),
				'dcFSize'         => __( 'حجم', 'persian-wp-chatbot' ),
				'dcFDate'         => __( 'تاریخ ثبت', 'persian-wp-chatbot' ),
				'dcFStatus'       => __( 'وضعیت', 'persian-wp-chatbot' ),
				'dcStTrained'     => __( 'به هوش مصنوعی آموزش داده شده', 'persian-wp-chatbot' ),
				'dcStProcessing'  => __( 'در حال پردازش سمت سرور', 'persian-wp-chatbot' ),
				'dcStNot'         => __( 'هنوز به هوش مصنوعی آموزش داده نشده', 'persian-wp-chatbot' ),
				'dcStRemoved'     => __( 'از دانش هوش مصنوعی حذف شد', 'persian-wp-chatbot' ),
				// آپلود فایل
				/* translators: %s: maximum file size, e.g. 100 MB */
				'dcUpLimit'       => __( 'حداکثر حجم مجاز: %s', 'persian-wp-chatbot' ),
				/* translators: %s: server upload limit, e.g. 2 MB */
				'dcUpServerLimit' => __( 'محدودیت آپلود سرور شما %s است. برای آپلود فایل بزرگ‌تر، مقدار upload_max_filesize و post_max_size را در هاست افزایش دهید.', 'persian-wp-chatbot' ),
				'dcUpNone'        => __( 'هنوز فایلی انتخاب نشده است', 'persian-wp-chatbot' ),
				'dcUpBadType'     => __( 'فقط فایل‌های PDF، DOC، DOCX و TXT پذیرفته می‌شود.', 'persian-wp-chatbot' ),
				/* translators: %s: maximum file size */
				'dcUpTooBig'      => __( 'حجم این فایل از حد مجاز (%s) بیشتر است.', 'persian-wp-chatbot' ),
				'dcUpEmpty'       => __( 'فایل انتخاب‌شده خالی است.', 'persian-wp-chatbot' ),
				/* translators: %s: upload percentage */
				'dcUploading'     => __( 'در حال آپلود… %s٪', 'persian-wp-chatbot' ),
				'dcUpSaving'      => __( 'آپلود تمام شد؛ در حال ثبت در پایگاه داده…', 'persian-wp-chatbot' ),
				// لیست «موارد حذف‌شده از سایت»
				/* translators: %s: number of items */
				'rmCount'        => __( '%s مورد', 'persian-wp-chatbot' ),
				'rmLoading'      => __( 'در حال خواندن موارد حذف‌شده…', 'persian-wp-chatbot' ),
				'rmLoadFailed'   => __( 'خواندن موارد حذف‌شده ناموفق بود.', 'persian-wp-chatbot' ),
				'rmDelete'       => __( 'حذف', 'persian-wp-chatbot' ),
				'rmDeleteFailed' => __( 'حذف از هوش مصنوعی ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'rmDeleting'     => __( 'در حال حذف از هوش مصنوعی…', 'persian-wp-chatbot' ),
				/* translators: %s: number of items not shown in the list */
				'rmMore'         => __( 'و %s مورد دیگر (بعد از حذف موارد بالا نمایش داده می‌شوند)', 'persian-wp-chatbot' ),
				'dcUpFailed'      => __( 'آپلود ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'dcUpTooBigSrv'   => __( 'حجم فایل از محدودیت آپلود سرور بیشتر است.', 'persian-wp-chatbot' ),
				'dcUpDone'        => __( 'فایل آپلود و در پایگاه داده ثبت شد. برای آموزش به هوش مصنوعی، آن را در تب «فایل‌ها» باز کنید.', 'persian-wp-chatbot' ),
				'sizeMB'          => __( 'مگابایت', 'persian-wp-chatbot' ),
				'sizeKB'          => __( 'کیلوبایت', 'persian-wp-chatbot' ),
				'sizeB'           => __( 'بایت', 'persian-wp-chatbot' ),
				// تب «اطلاعات به‌روز»
				'lvProductsTitle' => __( 'اطلاعات به‌روز تمام محصولات', 'persian-wp-chatbot' ),
				'lvProductsChip'  => __( 'محصولات', 'persian-wp-chatbot' ),
				'lvBlogTitle'     => __( 'اطلاعات به‌روز تمام وبلاگ‌ها', 'persian-wp-chatbot' ),
				'lvBlogChip'      => __( 'وبلاگ‌ها', 'persian-wp-chatbot' ),
				'lvPagesTitle'    => __( 'اطلاعات به‌روز تمام برگه‌ها', 'persian-wp-chatbot' ),
				'lvPagesChip'     => __( 'برگه‌ها', 'persian-wp-chatbot' ),
				'lvOff'           => __( 'غیرفعال', 'persian-wp-chatbot' ),
				'lvEmpty'         => __( 'هنوز اطلاعات به‌روز دیگری اضافه نشده است.', 'persian-wp-chatbot' ),
				/* translators: %s: name of the live data type */
				'lvToggleLabel'   => __( 'فعال یا غیرفعال کردن «%s»', 'persian-wp-chatbot' ),
				'lvToggleFailed'  => __( 'تغییر وضعیت ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'lvAddTitle'      => __( 'افزودن اطلاعات به‌روز', 'persian-wp-chatbot' ),
				'lvEditTitle'     => __( 'ویرایش اطلاعات به‌روز', 'persian-wp-chatbot' ),
				'lvSaveNew'       => __( 'افزودن اطلاعات به‌روز', 'persian-wp-chatbot' ),
				'lvSaveEdit'      => __( 'ذخیرهٔ تغییرات', 'persian-wp-chatbot' ),
				'lvLoading'       => __( 'در حال خواندن اطلاعات…', 'persian-wp-chatbot' ),
				'lvLoadFailed'    => __( 'خواندن اطلاعات این مورد ناموفق بود.', 'persian-wp-chatbot' ),
				'lvSaved'         => __( 'ذخیره شد.', 'persian-wp-chatbot' ),
				'lvAdded'         => __( 'اطلاعات به‌روز در پایگاه داده ذخیره شد.', 'persian-wp-chatbot' ),
				'lvSaveFailed'    => __( 'ذخیره ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				'lvRequired'      => __( 'نام نوع داده، توضیح کوتاه و اطلاعات داده را کامل وارد کنید.', 'persian-wp-chatbot' ),
				'lvDeleteAsk'     => __( 'این مورد برای همیشه از پایگاه داده حذف می‌شود و قابل بازگشت نیست. آیا مطمئن هستید؟', 'persian-wp-chatbot' ),
				'lvDeleteFailed'  => __( 'حذف ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				// تب «لاگ سرور»
				'lgTotal'         => __( 'تعداد درخواست‌ها', 'persian-wp-chatbot' ),
				'lgSuccess'       => __( 'درخواست‌های موفق', 'persian-wp-chatbot' ),
				'lgError'         => __( 'درخواست‌های ناموفق', 'persian-wp-chatbot' ),
				'lgLastUpdate'    => __( 'آخرین آپدیت:', 'persian-wp-chatbot' ),
				'lgNever'         => __( 'هنوز دریافت نشده', 'persian-wp-chatbot' ),
				'lgSyncFailed'    => __( 'دریافت لاگ از سرور ناموفق بود:', 'persian-wp-chatbot' ),
				'lgSyncMore'      => __( 'لاگ‌های بیشتری هست؛ دوباره به‌روزرسانی را بزنید.', 'persian-wp-chatbot' ),
				'lgHint'          => __( 'درخواست', 'persian-wp-chatbot' ),
				'lgStSuccess'     => __( 'موفق', 'persian-wp-chatbot' ),
				'lgStError'       => __( 'ناموفق', 'persian-wp-chatbot' ),
				'lgStInfo'        => __( 'عادی', 'persian-wp-chatbot' ),
				'lgEmpty'         => __( 'هنوز لاگی ثبت نشده است.', 'persian-wp-chatbot' ),
				'lgLoading'       => __( 'در حال خواندن لاگ‌ها…', 'persian-wp-chatbot' ),
				'lgFailed'        => __( 'خواندن لاگ‌ها ناموفق بود.', 'persian-wp-chatbot' ),
				'lgDetailFailed'  => __( 'خواندن جزئیات این لاگ ناموفق بود.', 'persian-wp-chatbot' ),
				'lgFullText'      => __( 'متن کامل', 'persian-wp-chatbot' ),
				'lgTime'          => __( 'زمان', 'persian-wp-chatbot' ),
				'lgDetails'       => __( 'جزئیات', 'persian-wp-chatbot' ),
				'anView'         => __( 'مشاهده در سایت', 'persian-wp-chatbot' ),
				'anDetailFailed' => __( 'خواندن جزئیات این مورد ناموفق بود.', 'persian-wp-chatbot' ),
				'foldShow'       => __( 'نمایش: %s', 'persian-wp-chatbot' ),
				'anTrainFailed'  => __( 'آموزش ناموفق بود. موارد به فرم برگشتند؛ دوباره تلاش کنید.', 'persian-wp-chatbot' ),
				/* translators: %s: number of items */
				'anTrained'      => __( '%s مورد برای پردازش به سرور فرستاده شد.', 'persian-wp-chatbot' ),
				/* translators: %s: number of items */
				'anTrainPending' => __( 'سرور بعضی از موارد را نپذیرفت. آن‌ها در لیست ماندند؛ بعداً دوباره تلاش کنید.', 'persian-wp-chatbot' ),
			) ),
		);
	}

	/**
	 * متن‌های کلید «وضعیت چت‌بات» (تب عمومی).
	 *
	 * @return array<string,string>
	 */
	private function status_i18n(): array {
		return array(
			'stOffConfirm' => __( 'در صورت غیرفعال کردن، تمام عملکرد چت‌بات متوقف می‌شود: آیکن چت از سایت برداشته می‌شود، پاسخ‌گویی و ذخیرهٔ خودکار اطلاعات قطع می‌شود و همهٔ بخش‌ها تا فعال کردن دوباره قفل می‌شوند. ادامه می‌دهید؟', 'persian-wp-chatbot' ),
			'stSaving'     => __( 'در حال ذخیرهٔ وضعیت…', 'persian-wp-chatbot' ),
			'stFailed'     => __( 'تغییر وضعیت ناموفق بود. دوباره تلاش کنید.', 'persian-wp-chatbot' ),
			'stOnDone'     => __( 'چت‌بات فعال شد. در حال بارگذاری دوبارهٔ صفحه…', 'persian-wp-chatbot' ),
			'stOffDone'    => __( 'چت‌بات غیرفعال شد. در حال بارگذاری دوبارهٔ صفحه…', 'persian-wp-chatbot' ),
		);
	}

}
