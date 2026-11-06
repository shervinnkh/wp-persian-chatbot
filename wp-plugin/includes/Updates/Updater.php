<?php
namespace PersianWPChatbot\Updates;

use PersianWPChatbot\Contracts\Module;
use PersianWPChatbot\Database\LogStore;

defined( 'ABSPATH' ) || exit;

/**
 * به‌روزرسانی افزونه از GitHub Releases (S-4).
 *
 * ریپو را صاحب پروژه بعداً می‌سازد؛ آدرسش ثابت قابل‌تنظیم است:
 *   const GITHUB_REPO = 'OWNER/REPO'   (یا ثابت PWC_GITHUB_REPO در wp-config.php؛ الگوی ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$)
 * تا وقتی مقدار همان OWNER/REPO است، به‌روزرسان خاموش است و هیچ درخواستی به شبکه نمی‌زند.
 *
 * انتشار: Action ریپو با تگ plugin-vX.Y.Z دو فایل را در Release می‌گذارد:
 *   persian-wp-chatbot.zip  و  persian-wp-chatbot.zip.sha256   (zip باید پوشهٔ ریشهٔ persian-wp-chatbot/ داشته باشد)
 *
 * جریان:
 *  ۱) GET https://api.github.com/repos/{repo}/releases?per_page=10 ← اولین release که draft و prerelease نیست و
 *     tag_name آن با «plugin-v» شروع می‌شود؛ نسخه = بقیهٔ تگ؛ هر دو asset را باید داشته باشد (وگرنه نادیده گرفته می‌شود).
 *  ۲) download_url فقط اگر با https://github.com/{repo}/releases/download/ شروع شود پذیرفته می‌شود (وگرنه release رد می‌شود).
 *  ۳) کش ۱۲ ساعته با set_site_transient('pwc_update_info'). اگر GitHub خطا داد یا rate-limit کرد (۴۰۳/۴۲۹)، آخرین اطلاعات
 *     سالم (option pwc_update_info_last) استفاده و خطا لاگ می‌شود (حداکثر ساعتی یک‌بار).
 *  ۴) پیش از نصب (فیلتر upgrader_pre_download، فقط برای URLهای همین ریپو): zip دانلود می‌شود، sha256 آن با فایل .sha256 مقایسه
 *     می‌شود (hash_equals)؛ ناهمخوان ← WP_Error و نصب متوقف.
 *  ۵) نصب فقط با تأیید مدیر (دکمهٔ «به‌روزرسانی افزونه» یا دکمهٔ استاندارد وردپرس)؛ به‌روزرسانی خودکار وردپرس برای این افزونه خاموش است.
 *
 * ۱) فیلتر pre_set_site_transient_update_plugins: نسخهٔ جدید در لیست به‌روزرسانی‌های وردپرس (صفحهٔ «افزونه‌ها» و «به‌روزرسانی‌ها») می‌آید.
 * ۲) plugins_api: پنجرهٔ «مشاهدهٔ جزئیات نسخه».
 * ۳) نوار بالای همهٔ صفحه‌های افزونه: «نسخهٔ جدید آمده؛ به‌روزرسانی کنید» (in_admin_header).
 */
final class Updater implements Module {

	/** ⚠️ مالک پروژه بعد از ساختن ریپو «OWNER/REPO» را با آدرس واقعی عوض می‌کند (و Update URI در هدر افزونه را هم). */
	public const GITHUB_REPO = 'OWNER/REPO';

	private const PLACEHOLDER = 'OWNER/REPO';
	private const REPO_REGEX  = '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#';
	private const TAG_PREFIX  = 'plugin-v';
	private const ASSET_ZIP   = 'persian-wp-chatbot.zip';
	private const ASSET_SHA   = 'persian-wp-chatbot.zip.sha256';
	private const VERSION_RE  = '/^\d+(\.\d+){1,3}([-.][0-9A-Za-z.]+)?$/';

	private const CACHE_KEY    = 'pwc_update_info';
	private const LAST_GOOD    = 'pwc_update_info_last';
	private const ERR_THROTTLE = 'pwc_update_errlog';
	private const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
	private const FAIL_TTL     = HOUR_IN_SECONDS;
	private const SLUG         = 'persian-wp-chatbot';

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_cached' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( $this, 'no_auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( 'in_admin_header', array( $this, 'render_notice' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_recheck' ) );
	}

	/**
	 * «owner/repo» معتبر (ثابت PWC_GITHUB_REPO در wp-config.php یا GITHUB_REPO)؛ نامعتبر = ''.
	 */
	public static function repo(): string {
		$repo = defined( 'PWC_GITHUB_REPO' ) ? (string) PWC_GITHUB_REPO : self::GITHUB_REPO;
		$repo = trim( $repo );

		return 1 === preg_match( self::REPO_REGEX, $repo ) ? $repo : '';
	}

	/** به‌روزرسان فقط وقتی روشن است که ریپوی واقعی تنظیم شده باشد (نه OWNER/REPO). */
	public static function is_configured(): bool {
		$repo = self::repo();

		return '' !== $repo && 0 !== strcasecmp( $repo, self::PLACEHOLDER );
	}

	/** پیشوند مجاز آدرس دانلود asset این ریپو. */
	private static function download_prefix(): string {
		return 'https://github.com/' . self::repo() . '/releases/download/';
	}

	private static function is_repo_url( string $url ): bool {
		return self::is_configured() && 0 === stripos( $url, self::download_prefix() );
	}

	/**
	 * اطلاعات آخرین نسخه (کش‌شده). $fetch=false یعنی هرگز درخواست شبکه نزن (برای خواندن‌های پرتکرار).
	 *
	 * @return array<string, string>|null null = نامعلوم، خطا یا ریپو تنظیم نشده
	 */
	public static function latest( bool $fetch = true, bool $force = false ) {
		if ( ! self::is_configured() ) {
			return null;
		}

		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && false !== $cached ) {
			return self::valid_info( $cached ) ? $cached : null;
		}
		if ( ! $fetch ) {
			return null;
		}

		$result = self::fetch();
		if ( null !== $result['info'] ) {
			set_site_transient( self::CACHE_KEY, $result['info'], self::CACHE_TTL );
			update_option( self::LAST_GOOD, $result['info'], false );

			return $result['info'];
		}

		if ( '' !== $result['error'] ) {
			self::log_error( $result['error'] );
		}

		// GitHub خطا داد / rate-limit شد: از آخرین اطلاعات سالم استفاده می‌کنیم و یک ساعت دیگر دوباره می‌سنجیم.
		$last = get_option( self::LAST_GOOD, null );
		if ( '' !== $result['error'] && self::valid_info( $last ) ) {
			set_site_transient( self::CACHE_KEY, $last, self::FAIL_TTL );

			return $last;
		}

		set_site_transient( self::CACHE_KEY, 'fail', self::FAIL_TTL );

		return null;
	}

	/**
	 * ساختار و آدرس‌های اطلاعات (کش‌شده یا ذخیره‌شده) هنوز به همین ریپو می‌خورد؟
	 *
	 * @param mixed $info
	 */
	private static function valid_info( $info ): bool {
		return is_array( $info )
			&& isset( $info['version'], $info['download_url'], $info['sha256_url'] )
			&& is_string( $info['version'] )
			&& 1 === preg_match( self::VERSION_RE, $info['version'] )
			&& is_string( $info['download_url'] ) && self::is_repo_url( $info['download_url'] )
			&& is_string( $info['sha256_url'] ) && self::is_repo_url( $info['sha256_url'] );
	}

	/**
	 * یک بار از GitHub می‌پرسد.
	 *
	 * @return array{info: array<string,string>|null, error: string} error='' و info=null یعنی «release مناسبی نیست» (خطا نیست).
	 */
	private static function fetch(): array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases?per_page=10',
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'headers'     => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
				'user-agent'  => 'PersianWPChatbot/' . PWC_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'info'  => null,
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'info'  => null,
				'error' => ( 403 === $code || 429 === $code ? 'GitHub rate limit / HTTP ' : 'GitHub HTTP ' ) . $code,
			);
		}

		$releases = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			return array(
				'info'  => null,
				'error' => 'GitHub: invalid JSON',
			);
		}

		foreach ( $releases as $release ) {
			$info = self::info_from_release( $release );
			if ( null !== $info ) {
				return array(
					'info'  => $info,
					'error' => '',
				);
			}
		}

		return array(
			'info'  => null,
			'error' => '',
		);
	}

	/**
	 * @param mixed $release یک عضو آرایهٔ releases
	 * @return array<string,string>|null
	 */
	private static function info_from_release( $release ) {
		if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}
		$tag = (string) ( $release['tag_name'] ?? '' );
		if ( 0 !== strpos( $tag, self::TAG_PREFIX ) ) {
			return null;
		}
		$version = substr( $tag, strlen( self::TAG_PREFIX ) );
		if ( 1 !== preg_match( self::VERSION_RE, $version ) ) {
			return null;
		}

		$zip = '';
		$sha = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = (string) ( $asset['name'] ?? '' );
			$url  = (string) ( $asset['browser_download_url'] ?? '' );
			if ( ! self::is_repo_url( $url ) ) {
				continue; // فقط آدرس دانلود همین ریپو.
			}
			if ( self::ASSET_ZIP === $name ) {
				$zip = esc_url_raw( $url, array( 'https' ) );
			} elseif ( self::ASSET_SHA === $name ) {
				$sha = esc_url_raw( $url, array( 'https' ) );
			}
		}
		if ( '' === $zip || '' === $sha ) {
			return null; // release ناقص (هنوز asset ها بارگذاری نشده) پیشنهاد نمی‌شود.
		}

		return array(
			'version'      => $version,
			'download_url' => $zip,
			'sha256_url'   => $sha,
			'requires'     => '',
			'requires_php' => '',
			'tested'       => '',
			'changelog'    => sanitize_textarea_field( substr( (string) ( $release['body'] ?? '' ), 0, 4000 ) ),
		);
	}

	/** خطای بررسی نسخه را (حداکثر ساعتی یک‌بار) در «لاگ سایت» ثبت می‌کند. */
	private static function log_error( string $error ): void {
		if ( false !== get_transient( self::ERR_THROTTLE ) ) {
			return;
		}
		set_transient( self::ERR_THROTTLE, 1, HOUR_IN_SECONDS );
		LogStore::add(
			LogStore::ERROR,
			__( 'بررسی نسخهٔ جدید افزونه از GitHub ناموفق بود؛ از آخرین اطلاعات ذخیره‌شده استفاده می‌شود.', 'persian-wp-chatbot' ),
			array(
				'endpoint' => 'github:releases',
				'error'    => $error,
			)
		);
	}

	/**
	 * نسخهٔ جدید اگر هست (آرایهٔ اطلاعات)، وگرنه null.
	 *
	 * @return array<string, string>|null
	 */
	public static function available( bool $fetch = false ) {
		$info = self::latest( $fetch );

		return ( is_array( $info ) && version_compare( $info['version'], PWC_VERSION, '>' ) ) ? $info : null;
	}

	/**
	 * @param object|mixed $transient
	 * @return object|mixed
	 */
	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		return $this->apply( $transient, self::latest( true ) );
	}

	/**
	 * خواندن transient: فقط از کش (بدون شبکه) تا نسخهٔ جدید بلافاصله در لیست افزونه‌ها دیده شود.
	 *
	 * @param object|mixed $transient
	 * @return object|mixed
	 */
	public function inject_cached( $transient ) {
		if ( ! is_object( $transient ) || ! is_admin() ) {
			return $transient;
		}

		return $this->apply( $transient, self::latest( false ) );
	}

	/**
	 * @param object                    $transient
	 * @param array<string, string>|null $info
	 * @return object
	 */
	private function apply( $transient, $info ) {
		if ( ! is_array( $info ) || ! version_compare( $info['version'], PWC_VERSION, '>' ) ) {
			return $transient;
		}

		$transient->response[ PWC_BASENAME ] = (object) array(
			'id'           => 'pwc/' . self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => PWC_BASENAME,
			'new_version'  => $info['version'],
			'url'          => 'https://github.com/' . self::repo(),
			'package'      => $info['download_url'],
			'requires'     => $info['requires'],
			'requires_php' => $info['requires_php'],
			'tested'       => $info['tested'],
		);
		unset( $transient->no_update[ PWC_BASENAME ] );

		return $transient;
	}

	/**
	 * پیش از نصب: بستهٔ این ریپو را خودمان دانلود و با فایل .sha256 تطبیق می‌دهیم (S-4).
	 * sha256 فقط خرابی/دست‌کاری مسیر را می‌گیرد، نه هک حساب GitHub (ریشهٔ اعتماد = حساب GitHub؛ 2FA و protected tags).
	 *
	 * @param bool|\WP_Error|string $reply      false = دانلود عادی وردپرس.
	 * @param string                $package    آدرس بسته.
	 * @param object|null           $upgrader
	 * @param array<mixed>          $hook_extra
	 * @return bool|\WP_Error|string مسیر فایل موقت تأییدشده، یا WP_Error
	 */
	public function verify_package( $reply, $package = '', $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader, $hook_extra );
		if ( false !== $reply || ! is_string( $package ) || ! self::is_repo_url( $package ) ) {
			return $reply;
		}

		return self::download_verified( $package );
	}

	/**
	 * @return string|\WP_Error مسیر فایل موقت
	 */
	public static function download_verified( string $package ) {
		$bad = static function ( string $code, string $message ) {
			return new \WP_Error( $code, $message );
		};

		$path = (string) wp_parse_url( $package, PHP_URL_PATH );
		if ( self::ASSET_ZIP !== basename( $path ) ) {
			return $bad( 'pwc_update_package', __( 'آدرس بستهٔ به‌روزرسانی معتبر نیست؛ نصب متوقف شد.', 'persian-wp-chatbot' ) );
		}

		// ۱) مقدار مورد انتظار از فایل .sha256 کنار همان release.
		$sha = wp_remote_get(
			$package . '.sha256',
			array(
				'timeout'    => 15,
				'user-agent' => 'PersianWPChatbot/' . PWC_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $sha ) || 200 !== (int) wp_remote_retrieve_response_code( $sha ) ) {
			return $bad( 'pwc_update_checksum', __( 'فایل sha256 بستهٔ به‌روزرسانی دریافت نشد؛ نصب متوقف شد.', 'persian-wp-chatbot' ) );
		}
		$body = trim( (string) wp_remote_retrieve_body( $sha ) );
		if ( strlen( $body ) > 1024 || 1 !== preg_match( '/^([0-9a-fA-F]{64})(?:\s|$)/', $body, $m ) ) {
			return $bad( 'pwc_update_checksum', __( 'فایل sha256 بستهٔ به‌روزرسانی معتبر نیست؛ نصب متوقف شد.', 'persian-wp-chatbot' ) );
		}
		$expected = strtolower( $m[1] );

		// ۲) خود بسته.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// ۳) مقایسه.
		$actual = hash_file( 'sha256', $tmp );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			wp_delete_file( $tmp );
			LogStore::add(
				LogStore::ERROR,
				__( 'sha256 بستهٔ به‌روزرسانی با فایل .sha256 همخوان نیست؛ نصب متوقف شد.', 'persian-wp-chatbot' ),
				array(
					'endpoint' => 'github:package',
					'error'    => 'checksum_mismatch',
				)
			);

			return $bad( 'pwc_update_checksum_mismatch', __( 'بستهٔ دانلودشده با فایل sha256 همخوان نیست (فایل خراب یا دست‌کاری شده)؛ نصب متوقف شد.', 'persian-wp-chatbot' ) );
		}

		return $tmp;
	}

	/**
	 * نصب خودکار وردپرس برای این افزونه همیشه خاموش است: فقط با تأیید مدیر.
	 *
	 * @param bool|null    $update
	 * @param object|mixed $item
	 * @return bool|null
	 */
	public function no_auto_update( $update, $item ) {
		if ( is_object( $item ) && isset( $item->plugin ) && PWC_BASENAME === $item->plugin ) {
			return false;
		}

		return $update;
	}

	/**
	 * @param false|object|array<mixed> $result
	 * @param string                    $action
	 * @param object                    $args
	 * @return false|object|array<mixed>
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}

		$info = self::latest( true );
		if ( ! is_array( $info ) ) {
			return $result;
		}

		return (object) array(
			'name'          => __( 'چت بات هوشمند', 'persian-wp-chatbot' ),
			'slug'          => self::SLUG,
			'version'       => $info['version'],
			'author'        => 'شروین نخعی‌نیا',
			'requires'      => $info['requires'],
			'requires_php'  => $info['requires_php'],
			'tested'        => $info['tested'],
			'download_link' => $info['download_url'],
			'sections'      => array(
				'description' => esc_html__( 'چت‌بات هوشمند فارسی برای وردپرس؛ پاسخ‌گویی خودکار به بازدیدکنندگان با هوش مصنوعی.', 'persian-wp-chatbot' ),
				'changelog'   => '' !== $info['changelog'] ? nl2br( esc_html( $info['changelog'] ) ) : esc_html__( 'توضیحی ثبت نشده است.', 'persian-wp-chatbot' ),
			),
		);
	}

	/**
	 * @param \WP_Upgrader $upgrader
	 * @param array<mixed> $options
	 */
	public function after_upgrade( $upgrader, $options ): void {
		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) && in_array( PWC_BASENAME, (array) ( $options['plugins'] ?? array() ), true ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/**
	 * لینک «بررسی دوباره»: ?pwc_recheck=1&_wpnonce=… — کش را پاک و دوباره از GitHub می‌خواند.
	 */
	public function maybe_recheck(): void {
		if ( empty( $_GET['pwc_recheck'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'pwc_recheck' );

		self::latest( true, true );
		delete_site_transient( 'update_plugins' ); // وردپرس دوباره می‌سنجد و نسخهٔ جدید را در لیست می‌گذارد.

		wp_safe_redirect( remove_query_arg( array( 'pwc_recheck', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * نوار بالای همهٔ صفحه‌های افزونه (slug ها با pwc شروع می‌شوند).
	 */
	public function render_notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'pwc' !== $page && 0 !== strpos( $page, 'pwc-' ) ) {
			return;
		}

		$info = self::available( true );
		if ( ! $info ) {
			return;
		}

		$update_url = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( PWC_BASENAME ) ), 'upgrade-plugin_' . PWC_BASENAME );
		$recheck    = wp_nonce_url( add_query_arg( 'pwc_recheck', '1' ), 'pwc_recheck' );
		?>
		<style>
			.pwc-update-bar{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0;padding:12px 20px;background:#6d28d9;color:#fff;font-size:14px;line-height:1.7;position:relative;z-index:5}
			.pwc-update-bar strong{font-weight:700}
			.pwc-update-bar__actions{display:flex;gap:8px;margin-inline-start:auto;align-items:center}
			.pwc-update-bar a.pwc-update-bar__btn{background:#fff;color:#4c1d95;text-decoration:none;font-weight:700;padding:6px 16px;border-radius:8px}
			.pwc-update-bar a.pwc-update-bar__btn:hover,.pwc-update-bar a.pwc-update-bar__btn:focus{background:#ede9fe}
			.pwc-update-bar a.pwc-update-bar__link{color:#fff;text-decoration:underline}
		</style>
		<div class="pwc-update-bar" role="alert">
			<span>
				<?php
				printf(
					/* translators: 1: new version, 2: installed version */
					esc_html__( 'نسخهٔ جدید «چت بات هوشمند» (%1$s) منتشر شده است؛ نسخهٔ نصب‌شدهٔ شما %2$s است. لطفاً افزونه را به‌روزرسانی کنید.', 'persian-wp-chatbot' ),
					'<strong dir="ltr">' . esc_html( $info['version'] ) . '</strong>',
					'<strong dir="ltr">' . esc_html( PWC_VERSION ) . '</strong>'
				);
				?>
			</span>
			<?php if ( current_user_can( 'update_plugins' ) ) : ?>
				<span class="pwc-update-bar__actions">
					<a class="pwc-update-bar__link" href="<?php echo esc_url( $recheck ); ?>"><?php esc_html_e( 'بررسی دوباره', 'persian-wp-chatbot' ); ?></a>
					<a class="pwc-update-bar__btn" href="<?php echo esc_url( $update_url ); ?>"><?php esc_html_e( 'به‌روزرسانی افزونه', 'persian-wp-chatbot' ); ?></a>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}
}
