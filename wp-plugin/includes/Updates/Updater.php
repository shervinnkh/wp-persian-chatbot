<?php
namespace PersianAIAssistant\Updates;

use PersianAIAssistant\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * به‌روزرسانی افزونه از سایت خودتان (نه wordpress.org).
 *
 * وردپرس از روی خودِ فایل zip نمی‌تواند نسخه را بفهمد؛ پس کنار zip یک فایل کوچک JSON (manifest) می‌گذاریم:
 *   https://nojavankhalagh.ir/wp_plugins/persian_ai_assistant.json
 *   { "version": "0.5.15", "download_url": "https://nojavankhalagh.ir/wp_plugins/persian_ai_assistant.zip",
 *     "requires": "6.0", "requires_php": "7.4", "tested": "6.8", "changelog": "• ...\n• ..." }
 * (download_url اختیاری است؛ پیش‌فرض همان آدرس zip بالاست.)
 *
 * ۱) فیلتر pre_set_site_transient_update_plugins: اگر نسخهٔ manifest بزرگ‌تر بود، افزونه در لیست به‌روزرسانی‌های وردپرس
 *    (صفحهٔ «افزونه‌ها» و «به‌روزرسانی‌ها») با دکمهٔ استاندارد «به‌روزرسانی» ظاهر می‌شود.
 * ۲) plugins_api: پنجرهٔ «مشاهدهٔ جزئیات نسخه».
 * ۳) نوار بالای همهٔ صفحه‌های افزونه: «نسخهٔ جدید آمده؛ به‌روزرسانی کنید» (in_admin_header).
 *
 * ⚠️ فایل zip باید پوشهٔ ریشهٔ `persian-ai-assistant/` داشته باشد (نام فایل zip هر چیزی می‌تواند باشد).
 * آدرس‌ها را می‌شود در wp-config.php عوض کرد: PAIA_UPDATE_INFO_URL و PAIA_UPDATE_ZIP_URL (یا فیلترهای paia_update_info_url / paia_update_zip_url).
 */
final class Updater implements Module {

	public const DEFAULT_INFO_URL = 'https://nojavankhalagh.ir/wp_plugins/persian_ai_assistant.json';
	public const DEFAULT_ZIP_URL  = 'https://nojavankhalagh.ir/wp_plugins/persian_ai_assistant.zip';

	private const CACHE_KEY = 'paia_update_info';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	private const FAIL_TTL  = HOUR_IN_SECONDS;
	private const SLUG      = 'persian-ai-assistant';

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_cached' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( 'in_admin_header', array( $this, 'render_notice' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_recheck' ) );
	}

	public static function info_url(): string {
		$url = defined( 'PAIA_UPDATE_INFO_URL' ) ? (string) PAIA_UPDATE_INFO_URL : self::DEFAULT_INFO_URL;

		return (string) apply_filters( 'paia_update_info_url', $url );
	}

	public static function zip_url(): string {
		$url = defined( 'PAIA_UPDATE_ZIP_URL' ) ? (string) PAIA_UPDATE_ZIP_URL : self::DEFAULT_ZIP_URL;

		return (string) apply_filters( 'paia_update_zip_url', $url );
	}

	/**
	 * اطلاعات آخرین نسخه (کش‌شده). $fetch=false یعنی هرگز درخواست شبکه نزن (برای خواندن‌های پرتکرار).
	 *
	 * @return array<string, string>|null null = نامعلوم یا خطا
	 */
	public static function latest( bool $fetch = true, bool $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}
		if ( ! $fetch ) {
			return null;
		}

		$response = wp_remote_get(
			self::info_url(),
			array(
				'timeout'    => 8,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => 'PersianAIAssistant/' . PAIA_VERSION . '; ' . home_url( '/' ),
			)
		);

		$info = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $json ) && isset( $json['version'] ) && 1 === preg_match( '/^\d+(\.\d+){1,3}([-.][0-9A-Za-z.]+)?$/', trim( (string) $json['version'] ) ) ) {
				$zip  = isset( $json['download_url'] ) ? esc_url_raw( (string) $json['download_url'] ) : '';
				$info = array(
					'version'      => trim( (string) $json['version'] ),
					'download_url' => '' !== $zip ? $zip : self::zip_url(),
					'requires'     => isset( $json['requires'] ) ? sanitize_text_field( (string) $json['requires'] ) : '',
					'requires_php' => isset( $json['requires_php'] ) ? sanitize_text_field( (string) $json['requires_php'] ) : '',
					'tested'       => isset( $json['tested'] ) ? sanitize_text_field( (string) $json['tested'] ) : '',
					'changelog'    => isset( $json['changelog'] ) ? sanitize_textarea_field( (string) $json['changelog'] ) : '',
				);
			}
		}

		set_site_transient( self::CACHE_KEY, null === $info ? 'fail' : $info, null === $info ? self::FAIL_TTL : self::CACHE_TTL );

		return $info;
	}

	/**
	 * نسخهٔ جدید اگر هست (آرایهٔ اطلاعات)، وگرنه null.
	 *
	 * @return array<string, string>|null
	 */
	public static function available( bool $fetch = false ) {
		$info = self::latest( $fetch );

		return ( is_array( $info ) && version_compare( $info['version'], PAIA_VERSION, '>' ) ) ? $info : null;
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
		if ( ! is_array( $info ) || ! version_compare( $info['version'], PAIA_VERSION, '>' ) ) {
			return $transient;
		}

		$transient->response[ PAIA_BASENAME ] = (object) array(
			'id'           => 'paia/' . self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => PAIA_BASENAME,
			'new_version'  => $info['version'],
			'url'          => home_url( '/' ),
			'package'      => $info['download_url'],
			'requires'     => $info['requires'],
			'requires_php' => $info['requires_php'],
			'tested'       => $info['tested'],
		);
		unset( $transient->no_update[ PAIA_BASENAME ] );

		return $transient;
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
			'name'          => __( 'دستیار هوشمند', 'persian-ai-assistant' ),
			'slug'          => self::SLUG,
			'version'       => $info['version'],
			'author'        => 'شروین نخعی‌نیا',
			'requires'      => $info['requires'],
			'requires_php'  => $info['requires_php'],
			'tested'        => $info['tested'],
			'download_link' => $info['download_url'],
			'sections'      => array(
				'description' => esc_html__( 'سیستم مدیریت هوشمند وب‌سایت با هوش مصنوعی.', 'persian-ai-assistant' ),
				'changelog'   => '' !== $info['changelog'] ? nl2br( esc_html( $info['changelog'] ) ) : esc_html__( 'توضیحی ثبت نشده است.', 'persian-ai-assistant' ),
			),
		);
	}

	/**
	 * @param \WP_Upgrader $upgrader
	 * @param array<mixed> $options
	 */
	public function after_upgrade( $upgrader, $options ): void {
		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) && in_array( PAIA_BASENAME, (array) ( $options['plugins'] ?? array() ), true ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/**
	 * لینک «بررسی دوباره»: ?paia_recheck=1&_wpnonce=… — کش را پاک و دوباره از سایت می‌خواند.
	 */
	public function maybe_recheck(): void {
		if ( empty( $_GET['paia_recheck'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'paia_recheck' );

		self::latest( true, true );
		delete_site_transient( 'update_plugins' ); // وردپرس دوباره می‌سنجد و نسخهٔ جدید را در لیست می‌گذارد.

		wp_safe_redirect( remove_query_arg( array( 'paia_recheck', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * نوار بالای همهٔ صفحه‌های افزونه (slug ها با paia شروع می‌شوند).
	 */
	public function render_notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'paia' !== $page && 0 !== strpos( $page, 'paia-' ) ) {
			return;
		}

		$info = self::available( true );
		if ( ! $info ) {
			return;
		}

		$update_url = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( PAIA_BASENAME ) ), 'upgrade-plugin_' . PAIA_BASENAME );
		$recheck    = wp_nonce_url( add_query_arg( 'paia_recheck', '1' ), 'paia_recheck' );
		?>
		<style>
			.paia-update-bar{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0;padding:12px 20px;background:#6d28d9;color:#fff;font-size:14px;line-height:1.7;position:relative;z-index:5}
			.paia-update-bar strong{font-weight:700}
			.paia-update-bar__actions{display:flex;gap:8px;margin-inline-start:auto;align-items:center}
			.paia-update-bar a.paia-update-bar__btn{background:#fff;color:#4c1d95;text-decoration:none;font-weight:700;padding:6px 16px;border-radius:8px}
			.paia-update-bar a.paia-update-bar__btn:hover,.paia-update-bar a.paia-update-bar__btn:focus{background:#ede9fe}
			.paia-update-bar a.paia-update-bar__link{color:#fff;text-decoration:underline}
		</style>
		<div class="paia-update-bar" role="alert">
			<span>
				<?php
				printf(
					/* translators: 1: new version, 2: installed version */
					esc_html__( 'نسخهٔ جدید «دستیار هوشمند» (%1$s) منتشر شده است؛ نسخهٔ نصب‌شدهٔ شما %2$s است. لطفاً افزونه را به‌روزرسانی کنید.', 'persian-ai-assistant' ),
					'<strong dir="ltr">' . esc_html( $info['version'] ) . '</strong>',
					'<strong dir="ltr">' . esc_html( PAIA_VERSION ) . '</strong>'
				);
				?>
			</span>
			<?php if ( current_user_can( 'update_plugins' ) ) : ?>
				<span class="paia-update-bar__actions">
					<a class="paia-update-bar__link" href="<?php echo esc_url( $recheck ); ?>"><?php esc_html_e( 'بررسی دوباره', 'persian-ai-assistant' ); ?></a>
					<a class="paia-update-bar__btn" href="<?php echo esc_url( $update_url ); ?>"><?php esc_html_e( 'به‌روزرسانی افزونه', 'persian-ai-assistant' ); ?></a>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}
}
