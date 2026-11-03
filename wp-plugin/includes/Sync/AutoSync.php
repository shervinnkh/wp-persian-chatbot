<?php
namespace PersianAIAssistant\Sync;

use PersianAIAssistant\Contracts\Module;
use PersianAIAssistant\Database\ItemStore;
use PersianAIAssistant\Database\RemovedStore;
use PersianAIAssistant\Extraction\Extractors\AbstractPostExtractor;
use PersianAIAssistant\Extraction\Overview;
use PersianAIAssistant\Extraction\PostTypes;
use PersianAIAssistant\Extraction\Registry;
use PersianAIAssistant\Extraction\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * ذخیرهٔ خودکار اطلاعات سایت هنگام انتشار و ویرایش (تنظیمات: SyncSettings، تب تنظیمات ← «استخراج اطلاعات سایت»).
 *
 * جریان:
 *  1. transition_post_status با وضعیت جدید publish (هم انتشار تازه، هم ذخیرهٔ دوبارهٔ نوشتهٔ منتشرشده، هم انتشار زمان‌بندی‌شده)
 *     فقط شناسهٔ نوشته را در صف می‌گذارد، اگر نقش نوع‌نوشته (محصول/وبلاگ/برگه) تیک «افزودن» دارد.
 *  2. در shutdown (وقتی متا، ترم‌ها و دادهٔ ووکامرس هم ذخیره شده‌اند) هر نوشته با همان استخراج‌کنندهٔ دستی خوانده و
 *     در جدول paia_items upsert می‌شود: رکورد تازه با seen = 0 (برای فرم «اطلاعات جدید ثبت شده به صورت خودکار»)،
 *     رکورد موجود فقط JSON همان رکورد به‌روز می‌شود (seen تغییر نمی‌کند).
 *  3. ارسال به بک‌اند هوش مصنوعی (AiBackend، فعلاً کامنت):
 *     - رکورد تازه ← فقط اگر تیک «آموزش» روشن است؛
 *     - رکورد ویرایش‌شده ← فقط اگر «آنچه برای هوش مصنوعی مهم است» تغییر کرده (ai_changed): برای محصول فقط عنوان یا
 *       توضیحات (نه قیمت، موجودی و اطلاعات به‌روز)، برای وبلاگ/برگه تغییر داده‌ٔ ثابت؛ و تیک «آموزش» روشن است یا
 *       این رکورد پیش‌تر آموزش دیده بود (تا هوش مصنوعی با داده‌ٔ کهنه پاسخ ندهد).
 *  4. حذف نوشته (انتقال به زباله‌دان یا حذف دائمی): رکورد از paia_items برداشته و در لیست «حذف‌شده‌ها» ثبت می‌شود
 *     (RemovedStore) تا مدیر در تب «استخراج اطلاعات سایت» تصمیم بگیرد از هوش مصنوعی هم حذف شود یا نه.
 *
 * ایمنی: خطای استخراج هرگز نباید ذخیرهٔ نوشته را بشکند (همه‌چیز در try/catch و پس از پاسخ، در shutdown).
 * ذخیره‌های دسته‌ای (ویرایش گروهی، وارد کردن) فقط چند مورد اول را همان لحظه انجام می‌دهند و بقیه با WP-Cron.
 */
final class AutoSync implements Module {

	public const CRON_HOOK    = 'paia_autosync_process';
	private const INLINE_LIMIT = 15;

	/** @var array<int, true> */
	private $queue = array();

	/** @var bool */
	private $flush_hooked = false;

	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'trashed_post', array( $this, 'on_remove' ) );
		add_action( 'before_delete_post', array( $this, 'on_remove' ) );
		add_action( self::CRON_HOOK, array( $this, 'process' ) );
	}

	/**
	 * حذف نوشته از سایت (زباله‌دان یا حذف دائمی). فقط اگر رکوردش در paia_items بود، به لیست «حذف‌شده‌ها» می‌رود.
	 * حذف دائمیِ نوشته‌ای که پیش‌تر در زباله‌دان بود دوباره چیزی ثبت نمی‌کند (رکوردش همان موقع برداشته شد).
	 *
	 * @param int|mixed $post_id شناسهٔ نوشته.
	 */
	public function on_remove( $post_id ): void {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return;
		}

		try {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) ) {
				return;
			}

			$source = PostTypes::role_of( $post->post_type );
			if ( '' === $source ) {
				return;
			}

			if ( RemovedStore::add_from_record( ItemStore::record_id( Sources::record_category( $source ), (int) $post->ID ) ) ) {
				Overview::touch();
			}
		} catch ( \Throwable $e ) {
			// حذف نوشته نباید هیچ‌وقت به‌خاطر خطای افزونه متوقف شود.
			return;
		}
	}

	/**
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$source = PostTypes::role_of( $post->post_type );
		if ( '' === $source ) {
			return;
		}
		$config = SyncSettings::get( $source );
		if ( empty( $config['add'] ) ) {
			// ذخیرهٔ خودکار خاموش است؛ ولی نوشتهٔ دوباره‌منتشرشده نباید در لیست «حذف‌شده‌ها» بماند.
			$record_id = ItemStore::record_id( Sources::record_category( $source ), (int) $post->ID );
			RemovedStore::forget( $record_id );
			return;
		}

		/**
		 * امکان خارج‌کردن یک نوشته از ذخیرهٔ خودکار.
		 *
		 * @param bool     $handle  پیش‌فرض true.
		 * @param \WP_Post $post    نوشته.
		 * @param string   $source  شناسهٔ منبع (products | blog | pages).
		 */
		if ( ! apply_filters( 'paia_autosync_handle_post', true, $post, $source ) ) {
			return;
		}

		$this->queue[ (int) $post->ID ] = true;

		if ( ! $this->flush_hooked ) {
			$this->flush_hooked = true;
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
	}

	public function flush(): void {
		$ids         = array_keys( $this->queue );
		$this->queue = array();
		if ( ! $ids ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$this->process( array_slice( $ids, 0, self::INLINE_LIMIT ) );

		$later = array_slice( $ids, self::INLINE_LIMIT );
		foreach ( array_chunk( $later, self::INLINE_LIMIT ) as $i => $chunk ) {
			wp_schedule_single_event( time() + 20 + ( $i * 10 ), self::CRON_HOOK, array( $chunk ) );
		}
	}

	/**
	 * @param int[]|mixed $ids شناسهٔ نوشته‌ها.
	 */
	public function process( $ids ): void {
		foreach ( (array) $ids as $id ) {
			try {
				$this->sync_post( (int) $id );
			} catch ( \Throwable $e ) {
				// ذخیرهٔ خودکار نباید هیچ‌وقت سایت یا ذخیرهٔ نوشته را خراب کند؛ نوشتهٔ بعدی ادامه پیدا می‌کند.
				continue;
			}
		}
	}

	private function sync_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$source = PostTypes::role_of( $post->post_type );
		if ( '' === $source ) {
			return;
		}
		$config = SyncSettings::get( $source );
		if ( empty( $config['add'] ) ) {
			return;
		}

		$extractor = Registry::make( $source );
		if ( ! $extractor instanceof AbstractPostExtractor || ! $extractor->accepts( $post ) ) {
			return;
		}

		$item = $extractor->item_for( $post );
		if ( empty( $item['id'] ) ) {
			return;
		}

		$record_id   = ItemStore::record_id( Sources::record_category( $source ), $item['id'] );
		$was_removed = RemovedStore::exists( $record_id ); // پیش از upsert (ItemStore::save ردیف حذف‌شده را پاک می‌کند)
		$result      = ItemStore::upsert( $record_id, $item, 0 ); // رکورد تازه: seen = 0
		if ( ! $result['ok'] ) {
			return;
		}

		// نوشته‌ای که قبلاً حذف شده بود و دوباره منتشر شد: از لیست «حذف‌شده‌ها» خارج شده (ItemStore::save).

		if ( $result['changed'] ) {
			Overview::touch();
		}

		if ( $result['created'] ) {
			if ( $config['train'] ) {
				// اگر هوش مصنوعی هنوز نسخهٔ قبلیِ حذف‌شده را دارد، بک‌اند باید آن را جایگزین کند (update).
				AiBackend::send( $record_id, $item, $was_removed ? AiBackend::EVENT_UPDATE : AiBackend::EVENT_CREATE );
			}
			return;
		}

		// ویرایش فقط وقتی به هوش مصنوعی می‌رود که «آموزش خودکار» تیک خورده و داده‌های ثابت (نه لایو) تغییر کرده باشد.
		if ( $result['ai_changed'] && $config['train'] ) {
			AiBackend::send( $record_id, $item, AiBackend::EVENT_UPDATE );
		}
	}
}
