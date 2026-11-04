<?php
namespace PersianAIAssistant\Admin;

use PersianAIAssistant\Admin\Pages\ChatbotPage;
use PersianAIAssistant\Admin\Pages\HomePage;
use PersianAIAssistant\Admin\Pages\MonitoringPage;
use PersianAIAssistant\Admin\Pages\PageInterface;
use PersianAIAssistant\Admin\Pages\SettingsPage;
use PersianAIAssistant\Chat\Unread;
use PersianAIAssistant\Contracts\Module;
use PersianAIAssistant\Database\FileStore;
use PersianAIAssistant\Database\ItemStore;
use PersianAIAssistant\Database\RemovedStore;
use PersianAIAssistant\Database\LiveStore;
use PersianAIAssistant\Database\LogStore;
use PersianAIAssistant\Documents\Overview as DocsOverview;
use PersianAIAssistant\Extraction\Labels;
use PersianAIAssistant\Extraction\Overview;
use PersianAIAssistant\Support\Icons;
use PersianAIAssistant\Extraction\Sources;
use PersianAIAssistant\Support\Capability;
use PersianAIAssistant\Support\Status;

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
		add_filter( 'plugin_action_links_' . PAIA_BASENAME, array( $this, 'add_action_links' ) );
	}

	public function register_menu(): void {
		// ترتیب زیرمنوها: صفحه اصلی، چت‌بات هوشمند، پنل مانیتورینگ، تنظیمات.
		$pages = array( new HomePage(), new ChatbotPage(), new MonitoringPage(), new SettingsPage() );

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
			__( 'دستیار هوشمند', 'persian-ai-assistant' ),
			__( 'دستیار هوشمند', 'persian-ai-assistant' ) . $bubble,
			$capability,
			$home->slug(),
			array( $home, 'render' ),
			PAIA_URL . 'assets/images/menu-icon.png',
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
		echo '<style id="paia-menu-icon">'
			. '#adminmenu #toplevel_page_paia div.wp-menu-image{display:flex;align-items:center;justify-content:center;overflow:hidden}'
			. '#adminmenu #toplevel_page_paia div.wp-menu-image img{width:20px;height:20px;max-width:20px;padding:0;margin:0;opacity:1;object-fit:contain;box-sizing:border-box;border-radius:5px}'
			. '#adminmenu #toplevel_page_paia:hover div.wp-menu-image img,#adminmenu #toplevel_page_paia.current div.wp-menu-image img,#adminmenu #toplevel_page_paia.wp-has-current-submenu div.wp-menu-image img{opacity:1}'
			. '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function enqueue_assets(): void {
		$page = $this->current_page();
		if ( null === $page ) {
			return;
		}

		wp_enqueue_style( 'paia-admin', PAIA_URL . 'assets/css/admin.css', array(), PAIA_VERSION );

		if ( ChatbotPage::SLUG === $page->slug() ) {
			wp_enqueue_media(); // انتخاب لوگوی چت از کتابخانهٔ رسانه (تنظیمات ← مدیریت چت)
		}

		wp_enqueue_script( 'paia-admin', PAIA_URL . 'assets/js/admin.js', array(), PAIA_VERSION, true );
		wp_add_inline_script(
			'paia-admin',
			'window.PAIA_ADMIN = ' . wp_json_encode( $this->script_data() ) . ';',
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

		$classes .= ' paia-admin-page';
		if ( $page->uses_app_shell() ) {
			$classes .= ' paia-app-shell';
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
				esc_html__( 'باز کردن چت‌بات', 'persian-ai-assistant' )
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
		// چت‌بات خاموش: فقط داده‌ٔ لازم برای تب «عمومی» (کلید وضعیت)؛ هیچ کوئری سنگینی اجرا نمی‌شود.
		if ( ! Status::is_enabled() ) {
			return array(
				'restUrl' => esc_url_raw( rest_url( 'paia/v1/' ) ),
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
			'restUrl'  => esc_url_raw( rest_url( 'paia/v1/' ) ),
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
			'logs'     => array_merge( LogStore::stats(), array( 'last_update' => \PersianAIAssistant\Apis\ServerLogs::last_update()['label'] ) ),
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
				'selectedCount'  => __( '%d مورد انتخاب شده', 'persian-ai-assistant' ),
				'noneSelected'   => __( 'هیچ موردی انتخاب نشده است', 'persian-ai-assistant' ),
				'working'        => __( 'در حال استخراج…', 'persian-ai-assistant' ),
				'networkError'   => __( 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'unknownError'   => __( 'خطای ناشناخته رخ داد.', 'persian-ai-assistant' ),
				'progressSite'   => __( 'در حال خواندن اطلاعات کلی سایت…', 'persian-ai-assistant' ),
				/* translators: 1: source name, 2: items loaded so far, 3: total items */
				'progressSource' => __( 'در حال استخراج «%1$s»: %2$s از %3$s', 'persian-ai-assistant' ),
				/* translators: %s: source name */
				'progressStart'  => __( 'در حال شروع استخراج «%s»…', 'persian-ai-assistant' ),
				'finished'       => __( 'استخراج تمام شد.', 'persian-ai-assistant' ),
				'finishedErrors' => __( 'استخراج تمام شد، اما بعضی بخش‌ها خطا داشتند (در خلاصه ببینید).', 'persian-ai-assistant' ),
				/* translators: %s: source name */
				'sourceFailed'   => __( 'استخراج «%s» با خطا متوقف شد: ', 'persian-ai-assistant' ),
				'summaryItems'   => __( 'مورد پیدا شد', 'persian-ai-assistant' ),
				'summaryNone'    => __( 'چیزی پیدا نشد', 'persian-ai-assistant' ),
				'warnings'       => __( 'هشدار', 'persian-ai-assistant' ),
				'warningsTitle'  => __( 'هشدارها', 'persian-ai-assistant' ),
				'seconds'        => __( 'ثانیه', 'persian-ai-assistant' ),
				'siteTitle'      => __( 'اطلاعات سایت و محیط', 'persian-ai-assistant' ),
				/* translators: %s: number of items */
				'showMore'       => __( 'نمایش %s مورد بعدی', 'persian-ai-assistant' ),
				'taxTitle'       => __( 'دسته‌بندی‌ها و طبقه‌بندی‌ها', 'persian-ai-assistant' ),
				'itemsTitle'     => __( 'آیتم‌های استخراج‌شده', 'persian-ai-assistant' ),
				'noItems'        => __( 'هیچ موردی استخراج نشد.', 'persian-ai-assistant' ),
				'yes'            => __( 'بله', 'persian-ai-assistant' ),
				'no'             => __( 'خیر', 'persian-ai-assistant' ),
				'truncated'      => __( 'فقط بخشی از ترم‌ها نمایش داده شده است', 'persian-ai-assistant' ),
				'chars'          => __( 'نویسه', 'persian-ai-assistant' ),
				'emptyContent'   => __( 'بدون متن', 'persian-ai-assistant' ),
				/* translators: %d: number of selected records */
				'treeSelected'   => __( '%d مورد برای آموزش انتخاب شده', 'persian-ai-assistant' ),
				'treeNone'       => __( 'هیچ موردی برای آموزش انتخاب نشده است', 'persian-ai-assistant' ),
				'aiTrained'      => __( 'به هوش مصنوعی آموزش داده شده', 'persian-ai-assistant' ),
				'aiProcessing'   => __( 'در حال پردازش سمت سرور', 'persian-ai-assistant' ),
				'removeAi'       => __( 'حذف از دانش هوش مصنوعی', 'persian-ai-assistant' ),
				'removeAiConfirm' => __( 'با حذف این داده، هوش مصنوعی دیگر به آن دسترسی نخواهد داشت. آیا مطمئن هستید؟', 'persian-ai-assistant' ),
				'removedAi'      => __( 'درخواست حذف به سرور هوش مصنوعی رفت؛ تا پایان پردازش در حال حذف است.', 'persian-ai-assistant' ),
				'untrainFailed'  => __( 'حذف از دانش هوش مصنوعی ناموفق بود. جزئیات در تب «لاگ سرور» است.', 'persian-ai-assistant' ),
				'removeSrcAi'    => __( 'حذف همهٔ آموزش‌دیده‌های این بخش از دانش هوش مصنوعی', 'persian-ai-assistant' ),
				/* translators: %s: number of records */
				'removeSrcAiConfirm' => __( 'آموزش %s مورد از دانش هوش مصنوعی حذف می‌شود (خود اطلاعات در سایت می‌ماند). آیا مطمئن هستید؟', 'persian-ai-assistant' ),
				'badgeNew'       => __( 'جدید', 'persian-ai-assistant' ),
				'summaryNew'     => __( 'مورد جدید پیدا شد', 'persian-ai-assistant' ),
				'summaryNoneNew' => __( 'مورد جدیدی پیدا نشد', 'persian-ai-assistant' ),
				'summarySaved'   => __( 'مورد ثبت شده', 'persian-ai-assistant' ),
				'workingNew'     => __( 'در حال استخراج اطلاعات جدید…', 'persian-ai-assistant' ),
				'progressCheck'  => __( 'در حال بررسی «%1$s»: %2$s از %3$s', 'persian-ai-assistant' ),
				'finishedNew'    => __( 'استخراج اطلاعات جدید تمام شد.', 'persian-ai-assistant' ),
				'noNewFound'     => __( 'اطلاعات جدیدی پیدا نشد؛ همهٔ موارد سایت قبلاً ثبت شده‌اند.', 'persian-ai-assistant' ),
				/* translators: %d: number of new items */
				'newFound'       => __( '%d مورد جدید پیدا و ذخیره شد.', 'persian-ai-assistant' ),
				/* translators: %s: source name */
				'readStart'      => __( 'در حال خواندن «%s» از پایگاه داده…', 'persian-ai-assistant' ),
				/* translators: 1: source name, 2: items read so far, 3: total items */
				'readSource'     => __( 'در حال خواندن «%1$s» از پایگاه داده: %2$s از %3$s', 'persian-ai-assistant' ),
				'loadingSaved'   => __( 'در حال خواندن اطلاعات ثبت‌شده…', 'persian-ai-assistant' ),
				'loadedSaved'    => __( 'اطلاعات ثبت‌شده بارگذاری شد.', 'persian-ai-assistant' ),
				'noSaved'        => __( 'هنوز اطلاعاتی ثبت نشده است.', 'persian-ai-assistant' ),
				'ovItems'        => __( 'مورد ثبت شده', 'persian-ai-assistant' ),
				/* translators: %s: number of trained items */
				'ovTrained'      => __( '%s آموزش‌دیده', 'persian-ai-assistant' ),
				/* translators: %s: number of newly added items */
				'ovAdded'        => __( '+%s جدید', 'persian-ai-assistant' ),
				/* translators: 1: total records, 2: trained records */
				'ovTotal'        => __( 'مجموع: %1$s مورد ثبت شده، %2$s مورد آموزش‌دیده', 'persian-ai-assistant' ),
				'ovLast'         => __( 'آخرین به‌روزرسانی اطلاعات:', 'persian-ai-assistant' ),
				'ovNever'        => __( 'هنوز اطلاعاتی استخراج نشده است. ابتدا استخراج اولیه را انجام دهید.', 'persian-ai-assistant' ),
				/* translators: %s: number of items */
				'anCount'        => __( '%s مورد', 'persian-ai-assistant' ),
				'anLoading'      => __( 'در حال خواندن موارد جدید…', 'persian-ai-assistant' ),
				'anLoadFailed'   => __( 'خواندن موارد جدید ناموفق بود.', 'persian-ai-assistant' ),
				'anSeenFailed'   => __( 'ثبت «دیده شد» ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				/* translators: %s: number of items not shown in the list */
				'anMore'         => __( 'و %s مورد دیگر (بعد از «همه را دیدم» نمایش داده می‌شوند)', 'persian-ai-assistant' ),
				// تب «اسناد اضافه»
				'dcFiles'        => __( 'فایل‌های آپلود شده', 'persian-ai-assistant' ),
				'dcFilesHint'    => __( 'فایل', 'persian-ai-assistant' ),
				'dcTexts'        => __( 'متن‌های اضافه شده', 'persian-ai-assistant' ),
				'dcTextsHint'    => __( 'باکس متن', 'persian-ai-assistant' ),
				/* translators: %s: number of trained items */
				'dcTrained'      => __( '%s آموزش‌دیده', 'persian-ai-assistant' ),
				/* translators: %s: number of chunks */
				'dcChunks'       => __( '%s چانک', 'persian-ai-assistant' ),
				/* translators: %s: number of words */
				'dcWords'        => __( '%s کلمه', 'persian-ai-assistant' ),
				/* translators: %s: number of draft texts */
				'dcDrafts'       => __( '%s پیش‌نویس', 'persian-ai-assistant' ),
				/* translators: %s: number of characters */
				'dcChars'        => __( '%s نویسه', 'persian-ai-assistant' ),
				'dcPlaceholder'  => __( 'متن را اینجا بنویسید… (برای جدا کردن چانک‌ها دو بار Enter بزنید)', 'persian-ai-assistant' ),
				'dcEmpty'        => __( 'هنوز متنی نوشته نشده است', 'persian-ai-assistant' ),
				'dcSaving'       => __( 'در حال ذخیره…', 'persian-ai-assistant' ),
				'dcDraftSaved'   => __( 'پیش‌نویس ذخیره شد.', 'persian-ai-assistant' ),
				'dcTrainSaved'   => __( 'متن در پایگاه داده ذخیره شد و برای آموزش آماده است. ارسال به هوش مصنوعی بعد از اتصال به سرویس انجام می‌شود.', 'persian-ai-assistant' ),
				'dcFailed'       => __( 'ذخیره ناموفق بود. متن شما حفظ شده است؛ دوباره تلاش کنید.', 'persian-ai-assistant' ),
				// لیست مطالب (تب «اسناد اضافه»)
				'dcTabTexts'      => __( 'متن‌ها', 'persian-ai-assistant' ),
				'dcTabFiles'      => __( 'فایل‌ها', 'persian-ai-assistant' ),
				'dcListLoading'   => __( 'در حال خواندن فهرست…', 'persian-ai-assistant' ),
				'dcListFailed'    => __( 'خواندن فهرست ناموفق بود.', 'persian-ai-assistant' ),
				'dcListEmptyText' => __( 'هنوز متنی اضافه نشده است.', 'persian-ai-assistant' ),
				'dcListEmptyFile' => __( 'هنوز فایلی آپلود نشده است.', 'persian-ai-assistant' ),
				'dcUntitled'      => __( '(بدون عنوان)', 'persian-ai-assistant' ),
				'dcDraftChip'     => __( 'پیش‌نویس', 'persian-ai-assistant' ),
				'dcRemovedChip'   => __( 'از هوش مصنوعی حذف شد', 'persian-ai-assistant' ),
				'dcRemoveAi'      => __( 'حذف آموزش', 'persian-ai-assistant' ),
				'dcTrainBtn'      => __( 'آموزش به هوش مصنوعی', 'persian-ai-assistant' ),
				'dcDownload'      => __( 'دانلود فایل', 'persian-ai-assistant' ),
				'dcDeleteFull'    => __( 'حذف کامل', 'persian-ai-assistant' ),
				'dcDelTextAsk'    => __( 'این باکس متن برای همیشه از پایگاه داده حذف می‌شود و قابل بازگشت نیست. آیا مطمئن هستید؟', 'persian-ai-assistant' ),
				'dcDelFileAsk'    => __( 'فایل برای همیشه از سایت پاک می‌شود و رکوردش هم از پایگاه داده حذف می‌شود؛ این کار قابل بازگشت نیست. آیا مطمئن هستید؟', 'persian-ai-assistant' ),
				'dcDeleted'       => __( 'حذف شد.', 'persian-ai-assistant' ),
				'dcDeleteFailed'  => __( 'حذف ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'dcItemTrained'   => __( 'برای پردازش به سرور فرستاده شد؛ بعد از پایان پردازش آیکن هوش مصنوعی نمایش داده می‌شود.', 'persian-ai-assistant' ),
				'dcItemReady'     => __( 'برای آموزش ثبت شد، اما سرور آن را نپذیرفت. بعداً دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'dcItemFailed'    => __( 'آموزش ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'dcDetailFailed'  => __( 'خواندن جزئیات ناموفق بود.', 'persian-ai-assistant' ),
				'dcFTitle'        => __( 'عنوان', 'persian-ai-assistant' ),
				'dcFName'         => __( 'نام فایل', 'persian-ai-assistant' ),
				'dcFFormat'       => __( 'فرمت', 'persian-ai-assistant' ),
				'dcFSize'         => __( 'حجم', 'persian-ai-assistant' ),
				'dcFDate'         => __( 'تاریخ ثبت', 'persian-ai-assistant' ),
				'dcFStatus'       => __( 'وضعیت', 'persian-ai-assistant' ),
				'dcStTrained'     => __( 'به هوش مصنوعی آموزش داده شده', 'persian-ai-assistant' ),
				'dcStProcessing'  => __( 'در حال پردازش سمت سرور', 'persian-ai-assistant' ),
				'dcStNot'         => __( 'هنوز به هوش مصنوعی آموزش داده نشده', 'persian-ai-assistant' ),
				'dcStRemoved'     => __( 'از دانش هوش مصنوعی حذف شد', 'persian-ai-assistant' ),
				// آپلود فایل
				/* translators: %s: maximum file size, e.g. 100 MB */
				'dcUpLimit'       => __( 'حداکثر حجم مجاز: %s', 'persian-ai-assistant' ),
				/* translators: %s: server upload limit, e.g. 2 MB */
				'dcUpServerLimit' => __( 'محدودیت آپلود سرور شما %s است. برای آپلود فایل بزرگ‌تر، مقدار upload_max_filesize و post_max_size را در هاست افزایش دهید.', 'persian-ai-assistant' ),
				'dcUpNone'        => __( 'هنوز فایلی انتخاب نشده است', 'persian-ai-assistant' ),
				'dcUpBadType'     => __( 'فقط فایل‌های PDF، DOC، DOCX و TXT پذیرفته می‌شود.', 'persian-ai-assistant' ),
				/* translators: %s: maximum file size */
				'dcUpTooBig'      => __( 'حجم این فایل از حد مجاز (%s) بیشتر است.', 'persian-ai-assistant' ),
				'dcUpEmpty'       => __( 'فایل انتخاب‌شده خالی است.', 'persian-ai-assistant' ),
				/* translators: %s: upload percentage */
				'dcUploading'     => __( 'در حال آپلود… %s٪', 'persian-ai-assistant' ),
				'dcUpSaving'      => __( 'آپلود تمام شد؛ در حال ثبت در پایگاه داده…', 'persian-ai-assistant' ),
				// لیست «موارد حذف‌شده از سایت»
				/* translators: %s: number of items */
				'rmCount'        => __( '%s مورد', 'persian-ai-assistant' ),
				'rmLoading'      => __( 'در حال خواندن موارد حذف‌شده…', 'persian-ai-assistant' ),
				'rmLoadFailed'   => __( 'خواندن موارد حذف‌شده ناموفق بود.', 'persian-ai-assistant' ),
				'rmDelete'       => __( 'حذف', 'persian-ai-assistant' ),
				'rmDeleteFailed' => __( 'حذف از هوش مصنوعی ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'rmDeleting'     => __( 'در حال حذف از هوش مصنوعی…', 'persian-ai-assistant' ),
				/* translators: %s: number of items not shown in the list */
				'rmMore'         => __( 'و %s مورد دیگر (بعد از حذف موارد بالا نمایش داده می‌شوند)', 'persian-ai-assistant' ),
				'dcUpFailed'      => __( 'آپلود ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'dcUpTooBigSrv'   => __( 'حجم فایل از محدودیت آپلود سرور بیشتر است.', 'persian-ai-assistant' ),
				'dcUpDone'        => __( 'فایل آپلود و در پایگاه داده ثبت شد. برای آموزش به هوش مصنوعی، آن را در تب «فایل‌ها» باز کنید.', 'persian-ai-assistant' ),
				'sizeMB'          => __( 'مگابایت', 'persian-ai-assistant' ),
				'sizeKB'          => __( 'کیلوبایت', 'persian-ai-assistant' ),
				'sizeB'           => __( 'بایت', 'persian-ai-assistant' ),
				// تب «اطلاعات به‌روز»
				'lvProductsTitle' => __( 'اطلاعات به‌روز تمام محصولات', 'persian-ai-assistant' ),
				'lvProductsChip'  => __( 'محصولات', 'persian-ai-assistant' ),
				'lvBlogTitle'     => __( 'اطلاعات به‌روز تمام وبلاگ‌ها', 'persian-ai-assistant' ),
				'lvBlogChip'      => __( 'وبلاگ‌ها', 'persian-ai-assistant' ),
				'lvPagesTitle'    => __( 'اطلاعات به‌روز تمام برگه‌ها', 'persian-ai-assistant' ),
				'lvPagesChip'     => __( 'برگه‌ها', 'persian-ai-assistant' ),
				'lvOff'           => __( 'غیرفعال', 'persian-ai-assistant' ),
				'lvEmpty'         => __( 'هنوز اطلاعات به‌روز دیگری اضافه نشده است.', 'persian-ai-assistant' ),
				/* translators: %s: name of the live data type */
				'lvToggleLabel'   => __( 'فعال یا غیرفعال کردن «%s»', 'persian-ai-assistant' ),
				'lvToggleFailed'  => __( 'تغییر وضعیت ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'lvAddTitle'      => __( 'افزودن اطلاعات به‌روز', 'persian-ai-assistant' ),
				'lvEditTitle'     => __( 'ویرایش اطلاعات به‌روز', 'persian-ai-assistant' ),
				'lvSaveNew'       => __( 'افزودن اطلاعات به‌روز', 'persian-ai-assistant' ),
				'lvSaveEdit'      => __( 'ذخیرهٔ تغییرات', 'persian-ai-assistant' ),
				'lvLoading'       => __( 'در حال خواندن اطلاعات…', 'persian-ai-assistant' ),
				'lvLoadFailed'    => __( 'خواندن اطلاعات این مورد ناموفق بود.', 'persian-ai-assistant' ),
				'lvSaved'         => __( 'ذخیره شد.', 'persian-ai-assistant' ),
				'lvAdded'         => __( 'اطلاعات به‌روز در پایگاه داده ذخیره شد.', 'persian-ai-assistant' ),
				'lvSaveFailed'    => __( 'ذخیره ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'lvRequired'      => __( 'نام نوع داده، توضیح کوتاه و اطلاعات داده را کامل وارد کنید.', 'persian-ai-assistant' ),
				'lvDeleteAsk'     => __( 'این مورد برای همیشه از پایگاه داده حذف می‌شود و قابل بازگشت نیست. آیا مطمئن هستید؟', 'persian-ai-assistant' ),
				'lvDeleteFailed'  => __( 'حذف ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
				// تب «لاگ سرور»
				'lgTotal'         => __( 'تعداد درخواست‌ها', 'persian-ai-assistant' ),
				'lgSuccess'       => __( 'درخواست‌های موفق', 'persian-ai-assistant' ),
				'lgError'         => __( 'درخواست‌های ناموفق', 'persian-ai-assistant' ),
				'lgLastUpdate'    => __( 'آخرین آپدیت:', 'persian-ai-assistant' ),
				'lgNever'         => __( 'هنوز دریافت نشده', 'persian-ai-assistant' ),
				'lgSyncFailed'    => __( 'دریافت لاگ از سرور ناموفق بود:', 'persian-ai-assistant' ),
				'lgSyncMore'      => __( 'لاگ‌های بیشتری هست؛ دوباره به‌روزرسانی را بزنید.', 'persian-ai-assistant' ),
				'lgHint'          => __( 'درخواست', 'persian-ai-assistant' ),
				'lgStSuccess'     => __( 'موفق', 'persian-ai-assistant' ),
				'lgStError'       => __( 'ناموفق', 'persian-ai-assistant' ),
				'lgStInfo'        => __( 'عادی', 'persian-ai-assistant' ),
				'lgEmpty'         => __( 'هنوز لاگی ثبت نشده است.', 'persian-ai-assistant' ),
				'lgLoading'       => __( 'در حال خواندن لاگ‌ها…', 'persian-ai-assistant' ),
				'lgFailed'        => __( 'خواندن لاگ‌ها ناموفق بود.', 'persian-ai-assistant' ),
				'lgDetailFailed'  => __( 'خواندن جزئیات این لاگ ناموفق بود.', 'persian-ai-assistant' ),
				'lgFullText'      => __( 'متن کامل', 'persian-ai-assistant' ),
				'lgTime'          => __( 'زمان', 'persian-ai-assistant' ),
				'lgDetails'       => __( 'جزئیات', 'persian-ai-assistant' ),
				'anView'         => __( 'مشاهده در سایت', 'persian-ai-assistant' ),
				'anDetailFailed' => __( 'خواندن جزئیات این مورد ناموفق بود.', 'persian-ai-assistant' ),
				'subSyncing'     => __( 'در حال اتصال به سرور هوش مصنوعی…', 'persian-ai-assistant' ),
				'subSynced'      => __( 'آخرین همگام‌سازی: %s', 'persian-ai-assistant' ),
				'subFailed'      => __( 'همگام‌سازی ناموفق بود. اتصال به سرور هوش مصنوعی را بررسی کنید و دوباره تلاش کنید.', 'persian-ai-assistant' ),
				'foldShow'       => __( 'نمایش: %s', 'persian-ai-assistant' ),
				'anTrainFailed'  => __( 'آموزش ناموفق بود. موارد به فرم برگشتند؛ دوباره تلاش کنید.', 'persian-ai-assistant' ),
				/* translators: %s: number of items */
				'anTrained'      => __( '%s مورد برای پردازش به سرور فرستاده شد.', 'persian-ai-assistant' ),
				/* translators: %s: number of items */
				'anTrainPending' => __( 'سرور بعضی از موارد را نپذیرفت. آن‌ها در لیست ماندند؛ بعداً دوباره تلاش کنید.', 'persian-ai-assistant' ),
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
			'stOffConfirm' => __( 'در صورت غیرفعال کردن، تمام عملکرد چت‌بات متوقف می‌شود: آیکن چت از سایت برداشته می‌شود، پاسخ‌گویی و ذخیرهٔ خودکار اطلاعات قطع می‌شود و همهٔ تب‌ها تا فعال کردن دوباره قفل می‌شوند. ادامه می‌دهید؟', 'persian-ai-assistant' ),
			'stSaving'     => __( 'در حال ذخیرهٔ وضعیت…', 'persian-ai-assistant' ),
			'stFailed'     => __( 'تغییر وضعیت ناموفق بود. دوباره تلاش کنید.', 'persian-ai-assistant' ),
			'stOnDone'     => __( 'چت‌بات فعال شد. در حال بارگذاری دوبارهٔ صفحه…', 'persian-ai-assistant' ),
			'stOffDone'    => __( 'چت‌بات غیرفعال شد. در حال بارگذاری دوبارهٔ صفحه…', 'persian-ai-assistant' ),
		);
	}

}
