<?php
/**
 * دسترسی به داده‌های چت (بازدیدکننده + پیام).
 *
 * هویت فرستنده:
 *  - کاربر ثبت‌نام‌شده: wp_user_id
 *  - مهمان: uid (UUID که سرور می‌سازد و مرورگر در localStorage نگه می‌دارد)
 * وقتی مهمان در وردپرس ثبت‌نام/ورود کند، همان uid به کاربر وصل می‌شود (wp_user_id پر می‌شود)
 * و هیچ پیامی پاک نمی‌شود.
 *
 * @package PersianAIAssistant
 */

namespace PersianAIAssistant\Chat;

defined( 'ABSPATH' ) || exit;

final class ChatStore {

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public function visitor( string $uid ): ?object {
		global $wpdb;
		if ( '' === $uid ) {
			return null;
		}
		$table = ChatSchema::visitors_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE uid = %s", $uid ) );
		return $row ? $row : null;
	}

	/**
	 * بازدیدکنندهٔ فعلی را پیدا/ایجاد/به کاربر وردپرس وصل می‌کند.
	 * ممکن است uid جدید برگرداند (مثلاً وقتی uid قبلی متعلق به کاربر دیگری است).
	 *
	 * @param bool $create false = فقط بازدیدکنندهٔ موجود (هرگز ردیف جدید نمی‌سازد؛ برای مسیرهای GET/poll — رفع S-1)؛
	 *                     اگر پیدا نشود null برمی‌گرداند. ساخت ردیف فقط هنگام ارسال پیام یا ذخیرهٔ پروفایل (create = true).
	 */
	public function ensure_visitor( string $uid, int $user_id, bool $create = true ): ?object {
		global $wpdb;
		$table = ChatSchema::visitors_table();
		$row   = $this->visitor( $uid );

		// uid به یک کاربر وصل است ولی درخواست از کاربر دیگر/مهمان می‌آید: هویت جدید.
		if ( $row && (int) $row->wp_user_id > 0 && (int) $row->wp_user_id !== $user_id ) {
			$row = null;
		}

		if ( ! $row && $user_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d ORDER BY last_seen DESC LIMIT 1", $user_id ) );
		}

		if ( ! $row && ! $create ) {
			return null;
		}

		if ( ! $row ) {
			$user = $user_id > 0 ? get_userdata( $user_id ) : false;
			$new  = array(
				'uid'          => wp_generate_uuid4(),
				'wp_user_id'   => $user_id,
				'name'         => $user ? (string) $user->display_name : '',
				'contact'      => $user ? (string) $user->user_email : '',
				'profile_done' => $user ? 1 : 0,
				'created_at'   => self::now(),
				'last_seen'    => self::now(),
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $new );
			return $this->visitor( $new['uid'] );
		}

		// مهمان قبلی حالا وارد شده: به حساب وردپرس وصل می‌شود، پیام‌ها می‌مانند.
		if ( $user_id > 0 && 0 === (int) $row->wp_user_id ) {
			$user = get_userdata( $user_id );
			$data = array(
				'wp_user_id'   => $user_id,
				'profile_done' => 1,
			);
			if ( $user ) {
				$data['name']    = '' !== $row->name ? $row->name : (string) $user->display_name;
				$data['contact'] = '' !== $row->contact ? $row->contact : (string) $user->user_email;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'uid' => $row->uid ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . ChatSchema::messages_table() . ' SET wp_user_id = %d WHERE visitor_uid = %s AND wp_user_id = 0', $user_id, $row->uid ) );
			$row = $this->visitor( (string) $row->uid );
		}

		// برای کم‌کردن بار پایگاه‌داده (polling) فقط هر ۳۰ ثانیه (در حالت بهینه‌شده ۱۲۰ ثانیه) یک‌بار ثبت می‌شود.
		if ( time() - (int) strtotime( $row->last_seen . ' UTC' ) > ( ChatSettings::is_optimized() ? 120 : 30 ) ) {
			$row->last_seen = self::now();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'last_seen' => self::now() ), array( 'uid' => $row->uid ) );
		}
		return $row;
	}

	public function save_profile( string $uid, string $name, string $contact, bool $done = true ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			ChatSchema::visitors_table(),
			array(
				'name'         => $name,
				'contact'      => $contact,
				'profile_done' => $done ? 1 : 0,
			),
			array( 'uid' => $uid )
		);
	}

	/**
	 * @param array<string,mixed> $d role, content, attachment{type,url,path}, suggestions[], references[{type,id,url}].
	 */
	public function add_message( string $uid, int $user_id, array $d ): ?array {
		global $wpdb;
		$attach = isset( $d['attachment'] ) && is_array( $d['attachment'] ) ? $d['attachment'] : array();
		$sugg   = isset( $d['suggestions'] ) && is_array( $d['suggestions'] ) ? $d['suggestions'] : array();
		$refs   = isset( $d['references'] ) && is_array( $d['references'] ) ? MessageLinks::clean_references( $d['references'] ) : array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			ChatSchema::messages_table(),
			array(
				'visitor_uid'     => $uid,
				'wp_user_id'      => $user_id,
				'role'            => in_array( $d['role'] ?? '', array( 'user', 'assistant', 'admin' ), true ) ? $d['role'] : 'user',
				'content'         => (string) ( $d['content'] ?? '' ),
				'attachment_type' => (string) ( $attach['type'] ?? '' ),
				'attachment_url'  => (string) ( $attach['url'] ?? '' ),
				'attachment_path' => (string) ( $attach['path'] ?? '' ),
				'suggestions'     => $sugg ? wp_json_encode( array_values( $sugg ), JSON_UNESCAPED_UNICODE ) : '',
				'refs'            => $refs ? wp_json_encode( array_values( $refs ), JSON_UNESCAPED_UNICODE ) : '',
				'admin_user_id'   => (int) ( $d['admin_user_id'] ?? 0 ),
				'admin_name'      => (string) ( $d['admin_name'] ?? '' ),
				// ai_sent: ۰ = هنوز به سرور هوش مصنوعی نرفته. پیام‌های assistant یا از خود سرور آمده‌اند یا پاسخ پیش‌فرض محلی‌اند
				// (به سرور نمی‌روند) پس از همان ابتدا ۱ هستند؛ پیام کاربر و پشتیبان با ۰ شروع می‌شوند.
				'ai_sent'         => isset( $d['ai_sent'] ) ? ( $d['ai_sent'] ? 1 : 0 ) : ( 'assistant' === ( $d['role'] ?? 'user' ) ? 1 : 0 ),
				'created_at'      => self::now(),
			)
		);
		if ( ! $ok ) {
			return null;
		}
		if ( 'user' === ( $d['role'] ?? 'user' ) ) {
			Unread::flush();
		}
		$table = ChatSchema::messages_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ) );
		return $row ? $this->format( $row ) : null;
	}

	/**
	 * @return array<int,array<string,mixed>> قدیمی‌ترین ← جدیدترین
	 */
	public function history( string $uid, int $user_id, int $limit = 100 ): array {
		global $wpdb;
		$table = ChatSchema::messages_table();
		if ( $user_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE visitor_uid = %s OR wp_user_id = %d ORDER BY id DESC LIMIT %d", $uid, $user_id, $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE visitor_uid = %s ORDER BY id DESC LIMIT %d", $uid, $limit ) );
		}
		return array_map( array( $this, 'format' ), array_reverse( (array) $rows ) );
	}

	/**
	 * یک صفحه از پیام‌های گفتگو (قدیمی‌ترین ← جدیدترین) برای بارگذاری تدریجی ویجت.
	 *
	 * @param int $limit     تعداد پیام در هر صفحه.
	 * @param int $before_id 0 = جدیدترین‌ها؛ در غیر این صورت فقط پیام‌هایی با شناسهٔ کوچک‌تر از این.
	 * @return array{messages:array<int,array<string,mixed>>, has_more:bool}
	 */
	public function page( string $uid, int $user_id, int $limit = 10, int $before_id = 0 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		$table = ChatSchema::messages_table();
		$where = self::thread_where( 'm', $uid, $user_id );
		if ( $before_id > 0 ) {
			$where .= $wpdb->prepare( ' AND m.id < %d', $before_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		// یکی بیشتر می‌خوانیم تا بدانیم پیام قدیمی‌تری هم مانده یا نه.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM {$table} m WHERE {$where} ORDER BY m.id DESC LIMIT %d", $limit + 1 ) );

		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		return array(
			'messages' => array_map( array( $this, 'format' ), array_reverse( $rows ) ),
			'has_more' => $has_more,
		);
	}

	/**
	 * @param bool $resolve_links false = نشانگرهای [product-link:…] فقط از متن برداشته می‌شوند و کارتی ساخته نمی‌شود
	 *                            (برای فهرست‌هایی که فقط خلاصهٔ متن لازم دارند و نباید پرس‌وجوی اضافه بزنند).
	 * @return array<string,mixed>
	 */
	public function format( object $row, bool $resolve_links = true ): array {
		$text  = (string) $row->content;
		$links = array();
		// قدیمی (تا ۰.۵.۲۷): نشانگرهای [product-link:…] داخل متن. هنوز برای پیام‌های قبلی کار می‌کنند.
		if ( 'assistant' === $row->role && MessageLinks::has_markers( $text ) ) {
			if ( $resolve_links ) {
				$parsed = MessageLinks::parse( $text );
				$text   = $parsed['text'];
				$links  = $parsed['links'];
			} else {
				$stripped = MessageLinks::strip( $text );
				$text     = '' !== $stripped['text'] ? $stripped['text'] : '🔗';
			}
		}
		// جدید: لیست ارجاع‌ها که هوش مصنوعی بیرون از متن می‌دهد؛ هر کدام با شناسه (یا آدرس) از دیتابیس سایت خوانده و کارت می‌شود.
		if ( $resolve_links && 'assistant' === $row->role && ! empty( $row->refs ) ) {
			$decoded = json_decode( (string) $row->refs, true );
			if ( is_array( $decoded ) && $decoded ) {
				$links = MessageLinks::merge( $links, MessageLinks::from_references( $decoded ) );
			}
		}

		$sugg = array();
		if ( ! empty( $row->suggestions ) ) {
			$decoded = json_decode( (string) $row->suggestions, true );
			$sugg    = is_array( $decoded ) ? $decoded : array();
		}
		return array(
			'id'          => (int) $row->id,
			'role'        => 'user' === $row->role ? 'user' : ( 'admin' === $row->role ? 'admin' : 'assistant' ),
			'admin_name'  => 'admin' === $row->role ? (string) $row->admin_name : '',
			'admin_id'    => 'admin' === $row->role ? (int) $row->admin_user_id : 0,
			'text'        => $text,
			'links'       => $links, // کارت‌های لینک (محصول/مقاله/صفحه) که زیر پیام نمایش داده می‌شوند
			'attachment'  => '' !== (string) $row->attachment_type ? array(
				'type' => (string) $row->attachment_type,
				'url'  => (string) $row->attachment_url,
			) : null,
			'suggestions' => $sugg,
			'time'        => gmdate( 'c', (int) strtotime( $row->created_at . ' UTC' ) ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* گفتگو (thread) و وضعیت لحظه‌ای                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * شرط SQL پیام‌های یک گفتگو: پیام‌های همین uid + (اگر ثبت‌نام کرده) پیام‌های همان کاربر وردپرس.
	 */
	public static function thread_where( string $alias, string $uid, int $user_id ): string {
		global $wpdb;
		$where = $wpdb->prepare( "{$alias}.visitor_uid = %s", $uid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $user_id > 0 ) {
			$where = '(' . $where . $wpdb->prepare( " OR {$alias}.wp_user_id = %d", $user_id ) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $where;
	}

	/**
	 * امضای گفتگو: «آخرین شناسه:تعداد». اگر تغییر نکرده باشد نیازی به ارسال دوبارهٔ پیام‌ها نیست.
	 */
	public function sig( string $uid, int $user_id ): string {
		global $wpdb;
		$table = ChatSchema::messages_table();
		$where = self::thread_where( 'm', $uid, $user_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT MAX(m.id) AS last_id, COUNT(*) AS total FROM {$table} m WHERE {$where}" );
		return ( $row ? (int) $row->last_id : 0 ) . ':' . ( $row ? (int) $row->total : 0 );
	}

	/**
	 * پیام‌های گفتگو (قدیمی‌ترین ← جدیدترین).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function thread( string $uid, int $user_id, int $limit = 100 ): array {
		global $wpdb;
		$table = ChatSchema::messages_table();
		$where = self::thread_where( 'm', $uid, $user_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM {$table} m WHERE {$where} ORDER BY m.id DESC LIMIT %d", $limit ) );
		return array_map( array( $this, 'format' ), array_reverse( (array) $rows ) );
	}

	/**
	 * تنظیمات «هوش مصنوعی خاموش است» برای این بازدیدکننده.
	 */
	public function is_ai_muted( string $uid ): bool {
		$v = $this->visitor( $uid );
		return $v && (int) $v->ai_muted > 0;
	}

	/**
	 * تایپینگ: کاربر در حال نوشتن است (حداکثر هر ۲ ثانیه یک‌بار نوشته می‌شود).
	 */
	public function touch_user_typing( string $uid ): void {
		$this->touch_status( $uid, array( 'user_typing_at' => time() ) );
	}

	public function touch_admin_typing( string $uid, string $name ): void {
		$this->touch_status( $uid, array( 'admin_typing_at' => time(), 'admin_typing_by' => $name ) );
	}

	public function clear_user_typing( string $uid ): void {
		$this->touch_status( $uid, array( 'user_typing_at' => 0 ), true );
	}

	public function clear_admin_typing( string $uid ): void {
		$this->touch_status( $uid, array( 'admin_typing_at' => 0, 'admin_typing_by' => '' ), true );
	}

	/**
	 * @param array<string,mixed> $data ستون‌ها.
	 */
	private function touch_status( string $uid, array $data, bool $force = false ): void {
		global $wpdb;
		if ( '' === $uid ) {
			return;
		}
		$table = ChatSchema::status_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE visitor_uid = %s", $uid ) );
		if ( ! $row ) {
			$data['visitor_uid'] = $uid;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data );
			return;
		}
		if ( ! $force ) {
			foreach ( array( 'user_typing_at', 'admin_typing_at' ) as $col ) {
				if ( isset( $data[ $col ] ) && time() - (int) $row->$col < 2 ) {
					unset( $data[ $col ], $data['admin_typing_by'] );
				}
			}
		}
		if ( $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'visitor_uid' => $uid ) );
		}
	}

	/** مدت (ثانیه) که «در حال تایپ» بعد از آخرین اعلام معتبر می‌ماند. */
	private const TYPING_TTL = 6;

	/**
	 * @return array{user:bool,admin:string} admin = نام پشتیبانِ در حال تایپ (یا رشتهٔ خالی)
	 */
	public function typing( string $uid ): array {
		global $wpdb;
		$table = ChatSchema::status_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE visitor_uid = %s", $uid ) );
		$now = time();
		return array(
			'user'  => $row && ( $now - (int) $row->user_typing_at ) <= self::TYPING_TTL,
			'admin' => ( $row && ( $now - (int) $row->admin_typing_at ) <= self::TYPING_TTL ) ? (string) $row->admin_typing_by : '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* صف پاسخ هوش مصنوعی (تأخیر + جمع‌کردن پیام‌ها)                          */
	/* ------------------------------------------------------------------ */

	/**
	 * پیام‌های کاربر که هنوز به هوش مصنوعی داده نشده‌اند (قدیمی‌ترین ← جدیدترین).
	 *
	 * @return object[]
	 */
	public function pending_rows( string $uid, int $user_id, int $done_id ): array {
		global $wpdb;
		$table = ChatSchema::messages_table();
		$where = self::thread_where( 'm', $uid, $user_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM {$table} m WHERE {$where} AND m.role = 'user' AND m.id > %d ORDER BY m.id ASC", $done_id ) );
	}

	/**
	 * «این پیام‌ها تا شناسهٔ X رسیدگی شد» را اتمی ثبت می‌کند؛ فقط یک درخواست برنده می‌شود (جلوگیری از پاسخ دوبار).
	 */
	public function claim( string $uid, int $upto_id ): bool {
		global $wpdb;
		$table = ChatSchema::visitors_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET ai_done_id = %d WHERE uid = %s AND ai_done_id < %d", $upto_id, $uid, $upto_id ) );
		return (int) $n > 0;
	}

	/**
	 * پیام‌های کاربر/پشتیبان که هنوز به سرور هوش مصنوعی فرستاده نشده‌اند (ai_sent = 0)، قدیمی‌ترین ← جدیدترین.
	 * ترتیب ارسال همین ترتیب شناسه است (زمان سایت/سرور مهم نیست).
	 *
	 * @param int $upto_id 0 = بدون سقف؛ در غیر این صورت فقط پیام‌هایی با شناسهٔ کوچک‌تر یا مساوی.
	 * @return object[]
	 */
	public function unsent_rows( string $uid, int $user_id, int $upto_id = 0 ): array {
		global $wpdb;
		$table = ChatSchema::messages_table();
		$where = self::thread_where( 'm', $uid, $user_id );
		if ( $upto_id > 0 ) {
			$where .= $wpdb->prepare( ' AND m.id <= %d', $upto_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( "SELECT m.* FROM {$table} m WHERE {$where} AND m.role IN ('user','admin') AND m.ai_sent = 0 ORDER BY m.id ASC" );
	}

	/**
	 * پیام‌هایی که سرور هوش مصنوعی «دریافت شد» گفته است را ai_sent = 1 می‌کند.
	 *
	 * @param int[] $ids شناسهٔ پیام‌ها.
	 */
	public function mark_sent( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$table = ChatSchema::messages_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "UPDATE {$table} SET ai_sent = 1 WHERE id IN (" . implode( ',', $ids ) . ')' );
	}

	/** آخرین زمان (unix) که کاربر «در حال تایپ» اعلام شده است. */
	public function user_typing_at( string $uid ): int {
		global $wpdb;
		$table = ChatSchema::status_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_typing_at FROM {$table} WHERE visitor_uid = %s", $uid ) );
	}

	/** آیا پیش از این شناسه، پیام دیگری از کاربر در گفتگو بوده است؟ */
	public function has_earlier_user_message( string $uid, int $user_id, int $before_id ): bool {
		global $wpdb;
		$table = ChatSchema::messages_table();
		$where = self::thread_where( 'm', $uid, $user_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} m WHERE {$where} AND m.role = 'user' AND m.id < %d", $before_id ) ) > 0;
	}

	/** آیا پشتیبان انسانی بعد از این شناسه پیام داده (یعنی هوش مصنوعی نباید جواب بدهد)؟ */
	public function has_admin_message_after( string $uid, int $user_id, int $after_id ): bool {
		global $wpdb;
		$table = ChatSchema::messages_table();
		$where = self::thread_where( 'm', $uid, $user_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} m WHERE {$where} AND m.role = 'admin' AND m.id > %d", $after_id ) ) > 0;
	}
}
