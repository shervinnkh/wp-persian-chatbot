<?php
namespace PersianWPChatbot\Sync;

use PersianWPChatbot\Apis\Callback;
use PersianWPChatbot\Apis\FileLink;
use PersianWPChatbot\Apis\Client;
use PersianWPChatbot\Apis\Reconciler;
use PersianWPChatbot\Apis\Records;
use PersianWPChatbot\Database\FileStore;
use PersianWPChatbot\Database\ItemStore;
use PersianWPChatbot\Database\LogStore;
use PersianWPChatbot\Database\TextStore;

defined( 'ABSPATH' ) || exit;

/**
 * ارسال رکوردها به سرور هوش مصنوعی (سرویس پایتون) و حذف آن‌ها — جهت A «افزونه ← سرور».
 *
 * قرارداد کامل: «راهنمای ارتباط افزونهٔ وردپرس با سرور هوش مصنوعی» (نسخهٔ سرور ۰.۳.۶). همهٔ درخواست‌ها با Apis\Client امضا می‌شوند.
 *
 * چرخهٔ وضعیت رکورد (ستون ai_status در pwc_items / pwc_texts / pwc_files — Records::STATUS_*):
 *   ۰  هنوز به سرور داده نشده / ارسال ناموفق (خطا در «لاگ سرور»)
 *   ۱  سرور «۲۰۰ + accepted» داد (رکورد در صف سرور است) ← آیکن loading + «در حال پردازش سمت سرور»  (همین کلاس)
 *   ۲  سرور پردازش را تمام کرد و با وب‌هوک خبر داد (POST /pwc/v1/ai/results) ← آیکن هوش مصنوعی  (apis/Results::apply)
 *      اگر وب‌هوک گم شود، کرون/پایش بازبینی وضعیت را از POST /v1/jobs/status می‌پرسد (apis/Reconciler).
 *
 * «accepted» یعنی «معتبر دانستم و در صف گذاشتم»، نه «پردازش شد». فقط وقتی کد دقیقاً ۲۰۰ و accepted = true بود ai_status = ۱ می‌شود.
 * هر چیز دیگر (خطا/timeout) = ارسال ناموفق: ai_status همان ۰ می‌ماند.
 *
 * AutoSync / DocumentsController / AutoSyncController فقط send() / train() / delete() را صدا می‌زنند.
 */
final class AiBackend {

	public const EVENT_CREATE = 'create';
	public const EVENT_UPDATE = 'update';
	public const EVENT_DELETE = 'delete';

	private const RECORDS_PATH = '/v1/knowledge/records';

	/** سقف سرور: ۱ تا ۱۰۰ رکورد در هر درخواست. */
	private const BATCH_MAX = 100;

	/** سقف بدنه سرور ۵MB است؛ دسته‌ها زیر این مقدار بسته می‌شوند. */
	private const BATCH_BYTES = 4000000;

	/** الگوی شناسهٔ رکورد در سرور (در غیر این صورت 422). */
	private const ID_PATTERN = '/^[A-Za-z0-9_]{1,40}:[A-Za-z0-9_.\-]{1,64}$/';

	/** دلیل آخرین ارسال ناموفق train() در همین درخواست (برای نمایش به مدیر)؛ خالی = خطایی نبود. */
	private static $last_error = '';

	public static function is_configured(): bool {
		return Client::is_configured();
	}

	public static function last_error(): string {
		return self::$last_error;
	}

	/**
	 * یک رکورد را برای سرور می‌فرستد (POST /v1/knowledge/records، «شکل A»). اگر سرور ۲۰۰ داد ai_status = ۱ می‌شود.
	 *
	 * @param string               $record_id شناسهٔ رکورد: pwc_items (مثل product:23) یا pwc_texts (text:…) یا pwc_files (file:…).
	 * @param array<string, mixed> $data      همان JSON ذخیره‌شده در رکورد.
	 * @param string               $event     create | update (سرور فقط لاگ می‌کند؛ رفتار هر دو یکی است).
	 * @return bool true = سرور رکورد را پذیرفت (ai_status شد ۱).
	 */
	public static function send( string $record_id, array $data, string $event ): bool {
		$sent = self::dispatch( $record_id, $data, $event );

		LogStore::add(
			$sent['ok'] ? LogStore::SUCCESS : LogStore::ERROR,
			$sent['ok']
				/* translators: %s: record id */
				? sprintf( __( 'رکورد %s برای پردازش به سرور هوش مصنوعی ارسال شد؛ منتظر اعلام نتیجه.', 'persian-wp-chatbot' ), $record_id )
				/* translators: 1: record id, 2: reason */
				: sprintf( __( 'ارسال رکورد %1$s به سرور هوش مصنوعی ناموفق بود: %2$s', 'persian-wp-chatbot' ), $record_id, self::reason( $sent ) ),
			self::log_data( 'POST', $sent, array( 'event' => $event, 'id' => $record_id ) )
		);

		return $sent['ok'];
	}

	/**
	 * دادهٔ یک رکورد فایل برای سرور: فرادادهٔ فایل + «لینک دانلود امضادار و کوتاه‌عمر» (Apis\FileLink) + SHA-256 محتوا.
	 *
	 * سرور هوش مصنوعی خودش فایل را با این لینک برمی‌دارد (فقط GET، فقط همین فایل، تا exp)؛ sha256 هم برای «تغییر نکرده»
	 * (بدون دانلود دوباره) و هم برای بررسی سلامت فایل دانلودشده است. لینک هرگز در لاگ نوشته نمی‌شود.
	 * فرمت DOC پشتیبانی نمی‌شود (سرور فقط pdf / docx / txt را استخراج می‌کند).
	 *
	 * @param array<string, mixed> $row ردیف خام pwc_files (FileStore::get).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function file_payload( array $row ) {
		$item = FileStore::present( $row );

		if ( 'doc' === $item['ext'] ) {
			return new \WP_Error( 'pwc_file_unsupported', __( 'فرمت DOC پشتیبانی نمی‌شود؛ فایل را به DOCX، PDF یا TXT تبدیل کنید.', 'persian-wp-chatbot' ), array( 'status' => 415 ) );
		}

		$abs = FileStore::absolute_path( (string) $row['path'] );
		if ( '' === $abs || ! is_file( $abs ) || ! is_readable( $abs ) ) {
			return new \WP_Error( 'pwc_file_missing', __( 'فایل روی سایت پیدا نشد.', 'persian-wp-chatbot' ), array( 'status' => 404 ) );
		}

		$link = FileLink::make( (string) $row['id'] );
		if ( null === $link ) {
			return new \WP_Error( 'pwc_not_configured', __( 'کلید اتصال (api_key) در افزونه تنظیم نشده است.', 'persian-wp-chatbot' ), array( 'status' => 503 ) );
		}

		$sha = hash_file( 'sha256', $abs );

		return array(
			'type'          => 'file',
			'title'         => $item['title'],
			'original_name' => $item['original_name'],
			'ext'           => $item['ext'],
			'size'          => (int) filesize( $abs ),
			'sha256'        => is_string( $sha ) ? $sha : '',
			'download'      => $link,
		);
	}

	/**
	 * آموزش دستی چند رکوردِ pwc_items (دکمهٔ «آموزش به هوش مصنوعی» و درخت استخراج): «شکل B» (دسته‌ای ۱ تا ۱۰۰ رکورد).
	 * هر دسته یا کاملاً ثبت می‌شود یا هیچ‌کدام؛ رکوردهای دستهٔ پذیرفته‌شده ai_status = ۱ می‌شوند.
	 *
	 * @param string[] $record_ids شناسهٔ رکوردها (مثل product:23).
	 * @return string[] شناسهٔ رکوردهایی که سرور پذیرفت.
	 */
	public static function train( array $record_ids ): array {
		self::$last_error = '';
		$started   = microtime( true );
		$record_ids = array_values( array_unique( array_filter( array_map( 'strval', $record_ids ) ) ) );
		$confirmed = array();
		$last      = null;

		$rows = array();
		foreach ( $record_ids as $id ) {
			$row = ItemStore::get( $id );
			if ( null !== $row && self::valid_id( $id ) ) {
				$rows[ $id ] = $row['data'];
			}
		}

		$revs = $rows ? Records::revisions( Records::ITEM, array_keys( $rows ) ) : array();

		foreach ( self::chunk_records( $rows, $revs ) as $chunk ) {
			$ids      = array_column( $chunk, 'id' );
			$response = Client::request(
				'POST',
				self::RECORDS_PATH,
				array(
					'records'  => $chunk,
					'force'    => false,
					'callback' => Callback::descriptor(),
				),
				30
			);
			$last     = $response;
			$ok       = self::accepted( $response );

			if ( $ok ) {
				$queued = isset( $response['body']['queued_ids'] ) && is_array( $response['body']['queued_ids'] )
					? array_values( array_intersect( $ids, array_map( 'strval', $response['body']['queued_ids'] ) ) )
					: $ids;
				Records::mark_processing( $queued );
				Reconciler::touch();
				$confirmed = array_merge( $confirmed, $queued );
			}

			if ( ! $ok ) {
				self::$last_error = self::reason( $response );
				LogStore::add(
					LogStore::ERROR,
					/* translators: 1: records in the batch, 2: reason */
					sprintf( __( 'ارسال دستهٔ %1$d رکوردی به سرور هوش مصنوعی ناموفق بود: %2$s', 'persian-wp-chatbot' ), count( $ids ), self::reason( $response ) ),
					self::log_data( 'POST', $response, array( 'count' => count( $ids ), 'ids' => implode( ', ', array_slice( $ids, 0, 20 ) ) ) )
				);
			}
		}

		if ( $record_ids ) {
			$ok_all = count( $confirmed ) === count( $record_ids );
			LogStore::add(
				$ok_all ? LogStore::SUCCESS : LogStore::ERROR,
				/* translators: 1: accepted records, 2: sent records */
				sprintf( __( '%1$d از %2$d رکورد برای پردازش به سرور هوش مصنوعی ارسال شد؛ منتظر اعلام نتیجه.', 'persian-wp-chatbot' ), count( $confirmed ), count( $record_ids ) ),
				array(
					'method'      => 'POST',
					'endpoint'    => self::RECORDS_PATH,
					'http_status' => null !== $last ? $last['code'] : 0,
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'request'     => array( 'count' => count( $record_ids ) ),
					'response'    => array( 'accepted' => count( $confirmed ) ),
				)
			);
		}

		return $confirmed;
	}

	/**
	 * حذف کامل یک رکورد از دانش هوش مصنوعی: DELETE /v1/knowledge/records (چانک‌ها و امبدینگ‌ها پاک می‌شوند).
	 * بعد از انجام، وب‌هوک `untrained` می‌آید؛ چون رکورد دیگر در دیتابیس افزونه نیست `ignored: not_found` می‌شود (خطا نیست).
	 *
	 * @param string $record_id شناسهٔ رکورد (مثل product:23 ، blog:45 ، page:7 ، text:… ، file:…).
	 * @return bool true = سرور حذف را پذیرفت. false = ناموفق (مثلاً سرور در دسترس نیست)؛ فراخوان‌کننده باید رکورد محلی را نگه دارد.
	 */
	public static function delete( string $record_id ): bool {
		if ( ! self::valid_id( $record_id ) ) {
			// شناسه‌ای که سرور اصلاً نمی‌پذیرد، هرگز در سرور نبوده است.
			return true;
		}

		$response = Client::request(
			'DELETE',
			self::RECORDS_PATH,
			array(
				'ids'      => array( $record_id ),
				'callback' => Callback::descriptor(),
			)
		);
		$ok       = self::accepted( $response );

		LogStore::add(
			$ok ? LogStore::SUCCESS : LogStore::ERROR,
			$ok
				/* translators: %s: record id */
				? sprintf( __( 'درخواست حذف رکورد %s از دانش هوش مصنوعی ارسال شد.', 'persian-wp-chatbot' ), $record_id )
				/* translators: 1: record id, 2: reason */
				: sprintf( __( 'حذف رکورد %1$s از دانش هوش مصنوعی ناموفق بود: %2$s', 'persian-wp-chatbot' ), $record_id, self::reason( $response ) ),
			self::log_data( 'DELETE', $response, array( 'event' => self::EVENT_DELETE, 'id' => $record_id ) )
		);

		return $ok;
	}

	/**
	 * «حذف آموزش» چند رکوردی که هنوز در سایت هستند (درخت استخراج، اسناد): DELETE /v1/knowledge/records (دسته‌ای تا ۱۰۰ تایی).
	 * رکوردهایی که سرور پذیرفت (۲۰۰) ai_status = ۱ می‌شوند (آیکن loading)؛ وقتی سرور حذف را تمام کرد وب‌هوک `untrained` می‌آید و
	 * Apis\Results آن‌ها را ۰ می‌کند (رکورد در سایت می‌ماند و دوباره قابل آموزش است).
	 *
	 * @param string[] $record_ids
	 * @return string[] شناسهٔ رکوردهایی که سرور حذفشان را پذیرفت.
	 */
	public static function untrain( array $record_ids ): array {
		self::$last_error = '';
		$started   = microtime( true );
		$ids       = array_values( array_unique( array_filter( array_map( 'strval', $record_ids ), static function ( $id ) {
			return 1 === preg_match( self::ID_PATTERN, $id );
		} ) ) );
		$confirmed = array();
		$last      = null;

		foreach ( array_chunk( $ids, self::BATCH_MAX ) as $chunk ) {
			$response = Client::request(
				'DELETE',
				self::RECORDS_PATH,
				array(
					'ids'      => $chunk,
					'callback' => Callback::descriptor(),
				),
				30
			);
			$last     = $response;

			if ( self::accepted( $response ) ) {
				$queued = isset( $response['body']['queued_ids'] ) && is_array( $response['body']['queued_ids'] )
					? array_values( array_intersect( $chunk, array_map( 'strval', $response['body']['queued_ids'] ) ) )
					: $chunk;
				Records::mark_processing( $queued );
				Reconciler::touch();
				$confirmed = array_merge( $confirmed, $queued );
			} else {
				self::$last_error = self::reason( $response );
				LogStore::add(
					LogStore::ERROR,
					/* translators: 1: records in the batch, 2: reason */
					sprintf( __( 'درخواست حذف آموزش %1$d رکورد از هوش مصنوعی ناموفق بود: %2$s', 'persian-wp-chatbot' ), count( $chunk ), self::$last_error ),
					self::log_data( 'DELETE', $response, array( 'count' => count( $chunk ), 'ids' => implode( ', ', array_slice( $chunk, 0, 20 ) ) ) )
				);
			}
		}

		if ( $ids && $confirmed ) {
			LogStore::add(
				count( $confirmed ) === count( $ids ) ? LogStore::SUCCESS : LogStore::ERROR,
				/* translators: 1: accepted records, 2: sent records */
				sprintf( __( 'درخواست حذف آموزش %1$d از %2$d رکورد به سرور هوش مصنوعی ارسال شد؛ منتظر اعلام نتیجه.', 'persian-wp-chatbot' ), count( $confirmed ), count( $ids ) ),
				array(
					'method'      => 'DELETE',
					'endpoint'    => self::RECORDS_PATH,
					'http_status' => null !== $last ? $last['code'] : 0,
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'request'     => array( 'count' => count( $ids ) ),
					'response'    => array( 'accepted' => count( $confirmed ) ),
				)
			);
		}

		return $confirmed;
	}

	/**
	 * رکوردی را که سرور «unknown» اعلام کرده (هیچ jobای ندارد) دوباره از دیتابیس افزونه می‌خواند و می‌فرستد (کرون بازبینی).
	 *
	 * @return bool true = سرور پذیرفت. false = رکورد نیست یا ارسال ناموفق بود.
	 */
	public static function resend( string $record_id ): bool {
		$data = self::stored_data( $record_id );
		if ( null === $data ) {
			return false;
		}

		return self::send( $record_id, $data, self::EVENT_UPDATE );
	}

	/**
	 * بدنهٔ POST /v1/knowledge/records (شکل A) را می‌سازد، می‌فرستد و در صورت موفقیت ai_status را ۱ می‌کند. لاگ نمی‌نویسد.
	 *
	 * @param array<string, mixed> $data
	 * @return array{ok:bool, code:int, body:array<string, mixed>, error_code:string, error:string, request_id:string, duration_ms:int, attempts:int}
	 */
	private static function dispatch( string $record_id, array $data, string $event ): array {
		if ( ! self::valid_id( $record_id ) ) {
			return self::local_failure( 'invalid_id', __( 'شناسهٔ رکورد با الگوی سرور نمی‌خواند.', 'persian-wp-chatbot' ) );
		}

		$payload = array(
			'event'    => self::EVENT_CREATE === $event ? self::EVENT_CREATE : self::EVENT_UPDATE,
			'id'       => $record_id,
			'data'     => self::data_object( $data ),
			'callback' => Callback::descriptor(),
		);

		// «نسخه»: سرور عیناً در وب‌هوک پس می‌فرستد؛ اگر رکورد بین ارسال و نتیجه ویرایش شود نتیجهٔ کهنه نادیده گرفته می‌شود. حداکثر ۱۲۸ نویسه.
		$rev = Records::revision( $record_id );
		if ( '' !== $rev ) {
			$payload['rev'] = substr( $rev, 0, 128 );
		}

		$response = Client::request( 'POST', self::RECORDS_PATH, $payload );

		if ( self::accepted( $response ) ) {
			Records::mark_processing( array( $record_id ) );
			Reconciler::touch();
		}

		return $response;
	}

	/**
	 * پاسخ موفق = دقیقاً HTTP 200 و accepted = true.
	 *
	 * @param array<string, mixed> $response
	 */
	private static function accepted( array $response ): bool {
		return 200 === $response['code'] && ! empty( $response['body']['accepted'] );
	}

	private static function valid_id( string $id ): bool {
		return 1 === preg_match( self::ID_PATTERN, $id );
	}

	/**
	 * `data` در سرور باید شیء JSON باشد؛ آرایهٔ خالی PHP به [] تبدیل می‌شد.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|object
	 */
	private static function data_object( array $data ) {
		return $data ? $data : new \stdClass();
	}

	/**
	 * رکوردها را به دسته‌های حداکثر ۱۰۰ تایی و حداکثر حدود ۴MB تقسیم می‌کند.
	 *
	 * @param array<string, array<string, mixed>> $rows شناسه => data
	 * @param array<string, string>               $revs شناسه => rev
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private static function chunk_records( array $rows, array $revs ): array {
		$chunks = array();
		$cur    = array();
		$bytes  = 0;

		foreach ( $rows as $id => $data ) {
			$record = array(
				'id'   => (string) $id,
				'data' => self::data_object( $data ),
			);
			if ( ! empty( $revs[ $id ] ) ) {
				$record['rev'] = substr( (string) $revs[ $id ], 0, 128 );
			}

			$json = wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) ) {
				continue;
			}
			$size = strlen( $json );

			if ( $cur && ( count( $cur ) >= self::BATCH_MAX || $bytes + $size > self::BATCH_BYTES ) ) {
				$chunks[] = $cur;
				$cur      = array();
				$bytes    = 0;
			}
			$cur[]  = $record;
			$bytes += $size;
		}

		if ( $cur ) {
			$chunks[] = $cur;
		}

		return $chunks;
	}

	/**
	 * داده‌ای که از دیتابیس افزونه برای ارسال دوبارهٔ یک رکورد لازم است (همان شکل controllerها).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function stored_data( string $record_id ): ?array {
		switch ( Records::kind_of( $record_id ) ) {
			case Records::TEXT:
				$item = TextStore::get( $record_id );
				if ( null === $item || ! $item['chunks'] ) {
					return null;
				}
				$chunks = TextStore::normalize( $item['chunks'] );

				return array(
					'type'   => 'text',
					'chunks' => $chunks,
					'text'   => implode( "\n\n", $chunks ),
				);

			case Records::FILE:
				$row = FileStore::get( $record_id );
				if ( null === $row ) {
					return null;
				}
				$payload = self::file_payload( $row ); // لینک دانلودِ تازه (کوتاه‌عمر) برای هر ارسال دوباره.

				return is_wp_error( $payload ) ? null : $payload;

			default:
				$row = ItemStore::get( $record_id );

				return null !== $row ? $row['data'] : null;
		}
	}

	/**
	 * @param array<string, mixed> $response
	 * @return array<string, mixed>
	 */
	private static function local_failure( string $code, string $message ): array {
		return array(
			'ok'          => false,
			'code'        => 0,
			'body'        => array(),
			'error_code'  => $code,
			'error'       => $message,
			'request_id'  => '',
			'duration_ms' => 0,
			'attempts'    => 0,
		);
	}

	/**
	 * دلیل فارسی شکست برای «لاگ سرور» (طبق جدول خطاهای راهنمای ارتباط).
	 *
	 * @param array<string, mixed> $response
	 */
	private static function reason( array $response ): string {
		$code = (int) $response['code'];
		$err  = (string) $response['error_code'];

		if ( Client::ERR_NOT_CONFIGURED === $err || Client::ERR_HTTPS_REQUIRED === $err || Client::ERR_ENCODE_FAILED === $err || Client::ERR_TOO_LARGE === $err || 'invalid_id' === $err ) {
			return (string) $response['error'];
		}

		switch ( true ) {
			case 0 === $code:
				return __( 'پاسخی از سرور نیامد (قطعی شبکه یا timeout).', 'persian-wp-chatbot' ) . ( '' !== (string) $response['error'] ? ' ' . $response['error'] : '' );
			case 400 === $code:
				return __( 'درخواست با HTTP رفته است؛ آدرس سرور باید https:// باشد.', 'persian-wp-chatbot' );
			case 401 === $code:
				return __( 'امضا یا زمان نامعتبر است؛ کلید اتصال و ساعت سایت/سرور (NTP) را بررسی کنید.', 'persian-wp-chatbot' );
			case 403 === $code:
				return __( 'IP این هاست در سرور هوش مصنوعی مجاز نیست.', 'persian-wp-chatbot' );
			case 413 === $code:
				return __( 'حجم درخواست از سقف سرور بیشتر است.', 'persian-wp-chatbot' );
			case 422 === $code && 'invalid_callback' === $err:
				return __( 'آدرس بازگشتی (callback) سایت توسط سرور رد شد؛ سایت باید روی https و همان دامنهٔ ثبت‌شده باشد.', 'persian-wp-chatbot' );
			case 422 === $code:
				return __( 'سرور بدنهٔ درخواست را نامعتبر دانست.', 'persian-wp-chatbot' ) . ( '' !== (string) $response['error'] ? ' ' . $response['error'] : '' );
			case 503 === $code:
				return __( 'سرویس هوش مصنوعی موقتاً در دسترس نیست (صف/پایگاه داده).', 'persian-wp-chatbot' );
			default:
				return sprintf(
					/* translators: %d: HTTP status */
					__( 'پاسخ ناموفق سرور (HTTP %d).', 'persian-wp-chatbot' ),
					$code
				);
		}
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $request   خلاصهٔ درخواست
	 * @return array<string, mixed>
	 */
	private static function log_data( string $method, array $response, array $request ): array {
		$data = array(
			'method'      => $method,
			'endpoint'    => self::RECORDS_PATH,
			'http_status' => $response['code'],
			'duration_ms' => $response['duration_ms'],
			'request'     => $request,
			'response'    => $response['body'],
		);

		if ( '' !== (string) $response['error_code'] ) {
			$data['error'] = $response['error_code'] . ( '' !== (string) $response['error'] ? ': ' . $response['error'] : '' );
		}
		if ( '' !== (string) $response['request_id'] ) {
			$data['request_id'] = $response['request_id']; // برای پیدا کردن همان خط در لاگ سرور AI.
		}
		if ( ! empty( $response['body']['job_id'] ) ) {
			$data['job_id'] = (string) $response['body']['job_id'];
		}
		if ( $response['attempts'] > 1 ) {
			$data['attempts'] = $response['attempts'];
		}

		return $data;
	}
}
