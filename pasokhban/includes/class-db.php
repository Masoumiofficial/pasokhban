<?php
/**
 * لایهٔ دیتابیس گفتگوی آنلاین پاسخ‌بان.
 *
 * دو جدول:
 *  - {prefix}pasokhban_sessions  : هر مکالمه (یک ردیف به‌ازای هر بازدیدکننده)
 *  - {prefix}pasokhban_messages  : پیام‌های هر مکالمه
 *
 * @package Pasokhban
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس دیتابیس.
 */
final class Pasokhban_DB {

	/**
	 * نسخهٔ ساختار جداول. با تغییر آن، dbDelta دوباره اجرا می‌شود.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.8.0';

	/** @var Pasokhban_DB|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* =========================================================
	 * ساختار
	 * =======================================================*/

	/** @var bool|null نتیجهٔ بررسی وجود جدول‌ها (کش در هر درخواست) */
	private static $tables_ok = null;

	/**
	 * آیا جدول‌ها واقعاً در دیتابیس وجود دارند؟
	 *
	 * فقط به گزینهٔ نسخه اتکا نمی‌کنیم: اگر dbDelta بی‌صدا شکست خورده باشد
	 * (مثلاً کاربر MySQL حق CREATE TABLE نداشته باشد)، گزینه ثبت شده ولی
	 * جدول‌ها وجود ندارند.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		if ( null !== self::$tables_ok ) {
			return self::$tables_ok;
		}

		global $wpdb;

		$sess = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::sessions_table() ) ); // phpcs:ignore WordPress.DB
		$msgs = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::messages_table() ) ); // phpcs:ignore WordPress.DB
		$feed = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::feedback_table() ) ); // phpcs:ignore WordPress.DB

		self::$tables_ok = ( $sess && $msgs && $feed );
		return self::$tables_ok;
	}

	/**
	 * کش بررسی جدول‌ها را پاک می‌کند (بعد از install).
	 */
	public static function flush_cache() {
		self::$tables_ok = null;
	}

	/**
	 * آیا پلاگین آمادهٔ کار است؟ هم نسخهٔ ساختار، هم وجود واقعی جدول‌ها.
	 *
	 * @return bool
	 */
	public static function installed() {
		return get_option( 'pasokhban_db_version' ) === self::SCHEMA_VERSION && self::tables_exist();
	}

	/**
	 * ساخت/به‌روزرسانی جداول (idempotent).
	 *
	 * @return true|WP_Error در صورت موفقیت true، وگرنه خطا با علت دقیق.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$sess    = self::sessions_table();
		$msgs    = self::messages_table();

		$sql = "CREATE TABLE {$sess} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			visitor_name VARCHAR(100) NOT NULL DEFAULT '',
			visitor_family VARCHAR(100) NOT NULL DEFAULT '',
			visitor_phone VARCHAR(40) NOT NULL DEFAULT '',
			visitor_email VARCHAR(190) NOT NULL DEFAULT '',
			ip VARCHAR(100) NOT NULL DEFAULT '',
			lang VARCHAR(8) NOT NULL DEFAULT 'fa',
			entry_url TEXT NULL,
			current_url TEXT NULL,
			mode VARCHAR(10) NOT NULL DEFAULT 'ai',
			status VARCHAR(12) NOT NULL DEFAULT 'open',
			assigned_to BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			unread_agent SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			unread_visitor SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			last_message TEXT NULL,
			last_sender VARCHAR(12) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY session_key (session_key),
			KEY status (status),
			KEY mode (mode),
			KEY assigned_to (assigned_to),
			KEY updated_at (updated_at),
			KEY inbox_list (status, unread_agent, updated_at)
		) {$charset};";

		dbDelta( $sql );

		$sql = "CREATE TABLE {$msgs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT(20) UNSIGNED NOT NULL,
			sender VARCHAR(12) NOT NULL,
			content LONGTEXT NULL,
			meta LONGTEXT NULL,
			response_ms INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );

		$fb = self::feedback_table();
		$sql = "CREATE TABLE {$fb} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT(20) UNSIGNED NOT NULL,
			message_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			rating TINYINT(2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY rating (rating),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );

		$bl = self::bale_links_table();
		$sql = "CREATE TABLE {$bl} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bale_message_id BIGINT(20) NOT NULL DEFAULT 0,
			session_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY bale_message_id (bale_message_id),
			KEY session_id (session_id)
		) {$charset};";

		dbDelta( $sql );

		$ch = self::chunks_table();
		$sql = "CREATE TABLE {$ch} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type VARCHAR(20) NOT NULL DEFAULT 'post',
			source_id VARCHAR(64) NOT NULL DEFAULT '',
			title VARCHAR(191) NOT NULL DEFAULT '',
			url VARCHAR(700) NOT NULL DEFAULT '',
			content LONGTEXT NULL,
			content_hash CHAR(32) NOT NULL DEFAULT '',
			model VARCHAR(100) NOT NULL DEFAULT '',
			dims SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			vector LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY source (source_type, source_id),
			KEY content_hash (content_hash)
		) {$charset};";

		dbDelta( $sql );

		// خطا را همین‌جا بگیر: هر کوئری بعدی last_error را پاک می‌کند.
		$delta_err = (string) $wpdb->last_error;

		self::flush_cache();

		// ---- بررسی واقعی: آیا جدول‌ها ساخته شدند؟ ----
		// dbDelta خطا نمی‌دهد؛ اگر کاربر MySQL حق CREATE TABLE نداشته باشد
		// بی‌صدا رد می‌شود. بدون این بررسی، گزینهٔ نسخه ثبت می‌شد و پلاگین
		// تا ابد فکر می‌کرد نصب شده در حالی که هیچ جدولی وجود نداشت.
		if ( ! self::tables_exist() ) {
			$err = ( '' !== $delta_err )
				? $delta_err
				: __( 'جدول‌ها ساخته نشدند و MySQL هم خطایی گزارش نکرد. احتمالاً کاربر دیتابیس حق CREATE TABLE ندارد.', 'pasokhban' );

			update_option( 'pasokhban_db_error', $err );
			delete_option( 'pasokhban_db_version' );   // نگذار installed() دروغ بگوید

			return new WP_Error( 'pasokhban_db_install', $err );
		}

		delete_option( 'pasokhban_db_error' );
		update_option( 'pasokhban_db_version', self::SCHEMA_VERSION );

		return true;
	}

	/**
	 * حذف جداول (فقط از uninstall.php صدا زده می‌شود).
	 */
	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::bale_links_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::chunks_table() );    // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::feedback_table() );  // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::messages_table() );  // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::sessions_table() );  // phpcs:ignore WordPress.DB
		delete_option( 'pasokhban_db_version' );
	}

	/** @return string */
	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'pasokhban_sessions';
	}

	/** @return string */
	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'pasokhban_messages';
	}

	/**
	 * مهاجرت از نام قدیمی «اتحادیار» به «پاسخ‌بان».
	 *
	 * در نسخهٔ ۳.۰.۰ نام افزونه عوض شد، یعنی نام جدول‌ها، گزینه‌ها و
	 * transientها هم عوض شد. بدون این مهاجرت، کاربری که از نسخهٔ قبل
	 * ارتقا می‌داد همهٔ مکالمات و تنظیماتش را از دست می‌داد.
	 *
	 * فقط یک‌بار اجرا می‌شود (با یک پرچم) و اگر جدول قدیمی وجود نداشته
	 * باشد هیچ کاری نمی‌کند.
	 *
	 * @return bool آیا مهاجرت انجام شد
	 */
	public static function migrate_from_etehadyar() {
		global $wpdb;

		if ( get_option( 'pasokhban_migrated_v3' ) ) {
			return false;
		}

		$old_prefix = $wpdb->prefix . 'etehadyar_';
		$new_prefix = $wpdb->prefix . 'pasokhban_';

		$tables = array( 'sessions', 'messages', 'feedback', 'chunks', 'bale_links' );
		$moved  = 0;

		foreach ( $tables as $t ) {
			$old = $old_prefix . $t;
			$new = $new_prefix . $t;

			$exists_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ); // phpcs:ignore WordPress.DB
			if ( ! $exists_old ) {
				continue;
			}
			// اگر جدول جدید هم هست، دست به قدیمی نمی‌زنیم — دادهٔ کاربر
			// نباید روی هم ریخته شود.
			$exists_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ); // phpcs:ignore WordPress.DB
			if ( $exists_new ) {
				continue;
			}
			if ( false !== $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ) ) { // phpcs:ignore WordPress.DB
				$moved++;
			}
		}

		// گزینه‌ها
		$options = array(
			'etehadyar_options'     => 'pasokhban_options',
			'etehadyar_db_version'  => 'pasokhban_db_version',
			'etehadyar_db_error'    => 'pasokhban_db_error',
			'etehadyar_rag_state'   => 'pasokhban_rag_state',
			'etehadyar_rag_dirty'   => 'pasokhban_rag_dirty',
			'etehadyar_rag_cursor'  => 'pasokhban_rag_cursor',
			'etehadyar_bale_links'  => 'pasokhban_bale_links',
			'etehadyar_updir_hard'  => 'pasokhban_updir_hard',
		);
		foreach ( $options as $old_key => $new_key ) {
			$val = get_option( $old_key, null );
			if ( null !== $val && false === get_option( $new_key, false ) ) {
				update_option( $new_key, $val );
				delete_option( $old_key );
			}
		}

		update_option( 'pasokhban_migrated_v3', 1 );

		return $moved > 0;
	}

	/**
	 * جدول نگاشت پیام بله → مکالمهٔ سایت.
	 *
	 * @return string
	 */
	public static function bale_links_table() {
		global $wpdb;
		return $wpdb->prefix . 'pasokhban_bale_links';
	}

	/**
	 * ثبت نگاشت: شناسهٔ پیام بله → مکالمهٔ سایت.
	 *
	 * اگر همان شناسه قبلاً ثبت شده باشد، به مکالمهٔ تازه منتقل می‌شود
	 * (یک پیام بله فقط به یک مکالمه تعلق دارد).
	 *
	 * @param int $bale_message_id
	 * @param int $session_id
	 * @return bool
	 */
	public static function bale_link( $bale_message_id, $session_id ) {
		global $wpdb;

		$bale_message_id = (int) $bale_message_id;
		$session_id      = (int) $session_id;
		if ( $bale_message_id < 1 || $session_id < 1 ) {
			return false;
		}

		$exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . self::bale_links_table() . ' WHERE bale_message_id = %d LIMIT 1',
			$bale_message_id
		) );

		if ( $exists ) {
			return false !== $wpdb->update(
				self::bale_links_table(),
				array( 'session_id' => $session_id ),
				array( 'bale_message_id' => $bale_message_id )
			);
		}

		return false !== $wpdb->insert(
			self::bale_links_table(),
			array(
				'bale_message_id' => $bale_message_id,
				'session_id'      => $session_id,
				'created_at'      => self::now(),
			),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * پیدا کردن مکالمهٔ سایت از روی شناسهٔ پیام بله.
	 *
	 * @param int $bale_message_id
	 * @return int صفر یعنی پیدا نشد
	 */
	public static function bale_session_for( $bale_message_id ) {
		global $wpdb;
		$bale_message_id = (int) $bale_message_id;
		if ( $bale_message_id < 1 ) {
			return 0;
		}
		$sid = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT session_id FROM ' . self::bale_links_table() . ' WHERE bale_message_id = %d LIMIT 1',
			$bale_message_id
		) );
		return $sid ? (int) $sid : 0;
	}

	/**
	 * جدول تکه‌های برداری (RAG).
	 *
	 * @return string
	 */
	public static function chunks_table() {
		global $wpdb;
		return $wpdb->prefix . 'pasokhban_chunks';
	}

	/** @return string */
	public static function feedback_table() {
		global $wpdb;
		return $wpdb->prefix . 'pasokhban_feedback';
	}

	/* =========================================================
	 * نشست‌ها (Sessions)
	 * =======================================================*/

	/**
	 * پیدا کردن نشست با کلید، یا ساختن یک نشست تازه.
	 *
	 * کلید سمت سرور با wp_generate_uuid4 ساخته می‌شود تا قابل حدس نباشد؛
	 * اگر $key خالی یا نامعتبر باشد نشست جدید ساخته می‌شود.
	 *
	 * @param string $key     کلید ارسالی از مرورگر (ممکن است خالی باشد).
	 * @param array  $context اطلاعات بازدیدکننده.
	 * @return array|null ردیف نشست.
	 */
	public static function get_or_create_session( $key, $context = array() ) {
		global $wpdb;

		$key = self::sanitize_key( $key );

		if ( $key ) {
			$row = self::get_session_by_key( $key );
			if ( $row ) {
				// به‌روزرسانی آدرس صفحهٔ فعلی بازدیدکننده.
				if ( ! empty( $context['current_url'] ) ) {
					$wpdb->update(
						self::sessions_table(),
						array( 'current_url' => esc_url_raw( $context['current_url'] ) ),
						array( 'id' => (int) $row->id )
					);
					// ردیف تازه خوانده شود؛ وگرنه caller مقدار کهنهٔ current_url را می‌گیرد.
					$fresh = self::get_session_by_key( $key );
					if ( $fresh ) {
						return $fresh;
					}
				}
				return $row;
			}
		}

		// کلید ارائه‌شده را به create_session می‌دهیم.
		//
		// قبلاً فقط $context پایین می‌رفت و کلید دور ریخته می‌شد؛ آن‌وقت
		// create_session یک کلید تصادفی تازه می‌ساخت. نتیجه: اگر
		// بازدیدکننده کلیدی معتبر ولی ناموجود می‌فرستاد — دقیقاً همان
		// چیزی که بعد از خالی‌شدن جدول یا مهاجرت یا کپی‌گرفتن از سایت
		// آزمایشی پیش می‌آید — کلید localStorage او بی‌اعتبار می‌شد و
		// درخواست بعدی‌اش یک نشست *دیگر* می‌ساخت.
		//
		// امن است چون create_session کلید را با sanitize_key می‌شورد
		// (فقط هگز، ۱۶ تا ۶۴ نویسه) و ستون session_key هم UNIQUE است.
		if ( '' !== $key ) {
			$context['key'] = $key;
		}

		return self::create_session( $context );
	}

	/**
	 * ساخت نشست تازه.
	 *
	 * @param array $context اطلاعات بازدیدکننده.
	 * @return array|null
	 */
	public static function create_session( $context = array() ) {
		global $wpdb;

		$now = self::now();
		$key = self::sanitize_key( isset( $context['key'] ) ? $context['key'] : '' );

		// اگر کلید معتبری از قبل هست ولی در جدول نیست، همان را نگه می‌داریم
		// (تا رفرش مرورگر باعث ساخت نشست تکراری نشود).
		if ( ! $key ) {
			$key = self::new_key();
		}

		$ok = $wpdb->insert(
			self::sessions_table(),
			array(
				'session_key' => $key,
				'visitor_name'   => isset( $context['name'] ) ? mb_substr( sanitize_text_field( $context['name'] ), 0, 100 ) : '',
				'visitor_family' => isset( $context['family'] ) ? mb_substr( sanitize_text_field( $context['family'] ), 0, 100 ) : '',
				'visitor_phone'  => isset( $context['phone'] ) ? self::sanitize_phone( $context['phone'] ) : '',
				'visitor_email'  => isset( $context['email'] ) ? sanitize_email( $context['email'] ) : '',
				'ip'            => self::client_ip(),
				'lang'          => isset( $context['lang'] ) ? $context['lang'] : 'fa',
				'entry_url'     => isset( $context['entry_url'] ) ? esc_url_raw( $context['entry_url'] ) : '',
				'current_url'   => isset( $context['current_url'] ) ? esc_url_raw( $context['current_url'] ) : '',
				'mode'          => 'ai',
				'status'        => 'open',
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			return null;
		}

		return self::get_session_by_key( $key );
	}

	/**
	 * @param string $key
	 * @return object|null
	 */
	public static function get_session_by_key( $key ) {
		global $wpdb;
		$key = self::sanitize_key( $key );
		if ( ! $key ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::sessions_table() . ' WHERE session_key = %s LIMIT 1', $key ) // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function get_session( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::sessions_table() . ' WHERE id = %d LIMIT 1', (int) $id ) // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * به‌روزرسانی فیلدهای یک نشست.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update_session( $id, array $data ) {
		global $wpdb;
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = self::now();
		return false !== $wpdb->update( self::sessions_table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * واگذاری مکالمه به یک اپراتور.
	 *
	 * @param int $id
	 * @param int $user_id صفر یعنی لغو واگذاری
	 * @return bool
	 */
	public static function assign_session( $id, $user_id ) {
		return self::update_session( $id, array( 'assigned_to' => max( 0, (int) $user_id ) ) );
	}

	/**
	 * آخرین پیام یک نشست را روی خود نشست ذخیره می‌کند (برای لیست اینباکس).
	 *
	 * @param int    $id
	 * @param string $content
	 * @param string $sender
	 */
	public static function touch_session( $id, $content, $sender ) {
		self::update_session(
			$id,
			array(
				'last_message' => mb_substr( $content, 0, 200 ),
				'last_sender'  => $sender,
			)
		);
	}

	/**
	 * لیست نشست‌ها برای اینباکس.
	 *
	 * @param array $args status, mode, search, per_page, page, orderby
	 * @return array { items, total, pages }
	 */
	public static function list_sessions( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => 'open',
			'mode'     => '',
			'search'   => '',
			'per_page' => 30,
			'page'     => 1,
			// 'all' | 'unassigned' | 'me' | <user_id>
			'assigned' => 'all',
			'agent_id' => 0,
		);
		$args  = wp_parse_args( $args, $defaults );
		$table = self::sessions_table();

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] && 'all' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['mode'] ) {
			$where[]  = 'mode = %s';
			$params[] = $args['mode'];
		}
		// فیلتر اپراتور. «me» یعنی هم مکالمات خودم و هم آن‌هایی که هنوز
		// کسی برنداشته — وگرنه اپراتور تازه هیچ کاری برای گرفتن نداشت.
		switch ( (string) $args['assigned'] ) {
			case 'unassigned':
				$where[] = 'assigned_to = 0';
				break;
			case 'me':
				$aid     = (int) $args['agent_id'];
				$where[] = '(assigned_to = %d OR assigned_to = 0)';
				$params[] = $aid;
				break;
			case 'all':
				break;
			default:
				$where[]  = 'assigned_to = %d';
				$params[] = (int) $args['assigned'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(visitor_name LIKE %s OR visitor_email LIKE %s OR last_message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 100, (int) $args['per_page'] ) );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( $params ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY unread_agent DESC, updated_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		$list_sql = $wpdb->prepare( $list_sql, $params ); // phpcs:ignore WordPress.DB

		$items = $wpdb->get_results( $list_sql ); // phpcs:ignore WordPress.DB

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * فهرست مخاطبین با تعداد پیام‌ها — برای صفحهٔ «مخاطبین» و خروجی CSV.
	 *
	 * @param array $args search, per_page, page
	 * @return array { items, total, pages }
	 */
	public static function list_contacts( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'search'   => '',
			'per_page' => 50,
			'page'     => 1,
			'only_with_contact' => false,
		);
		$args  = wp_parse_args( $args, $defaults );
		$sess  = self::sessions_table();
		$msgs  = self::messages_table();

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(s.visitor_name LIKE %s OR s.visitor_family LIKE %s OR s.visitor_phone LIKE %s OR s.visitor_email LIKE %s OR s.last_message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['only_with_contact'] ) ) {
			$where[] = "(s.visitor_phone <> '' OR s.visitor_email <> '' OR s.visitor_name <> '')";
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$sess} AS s WHERE {$where_sql}";
		if ( $params ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB

		$list_sql = "SELECT s.*,
				(SELECT COUNT(*) FROM {$msgs} AS m WHERE m.session_id = s.id) AS msg_count,
				(SELECT COUNT(*) FROM {$msgs} AS m WHERE m.session_id = s.id AND m.sender = 'visitor') AS visitor_msgs
			FROM {$sess} AS s
			WHERE {$where_sql}
			ORDER BY s.updated_at DESC
			LIMIT %d OFFSET %d";

		$params[] = $per_page;
		$params[] = $offset;
		$list_sql = $wpdb->prepare( $list_sql, $params ); // phpcs:ignore WordPress.DB

		$items = $wpdb->get_results( $list_sql ); // phpcs:ignore WordPress.DB

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/* =========================================================
	 * بازخورد (CSAT)
	 * =======================================================*/

	/**
	 * ثبت یا به‌روزرسانی امتیاز یک پیام.
	 *
	 * @param int $session_id
	 * @param int $message_id
	 * @param int $rating  1 یا -1
	 * @return bool
	 */
	public static function save_feedback( $session_id, $message_id, $rating ) {
		global $wpdb;

		$rating = ( 1 === (int) $rating ) ? 1 : -1;
		$table  = self::feedback_table();

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %d AND message_id = %d LIMIT 1", (int) $session_id, (int) $message_id ) // phpcs:ignore WordPress.DB
		);

		if ( $existing ) {
			return false !== $wpdb->update(
				$table,
				array( 'rating' => $rating, 'created_at' => self::now() ),
				array( 'id' => (int) $existing->id )
			);
		}

		return false !== $wpdb->insert(
			$table,
			array(
				'session_id' => (int) $session_id,
				'message_id' => (int) $message_id,
				'rating'     => $rating,
				'created_at' => self::now(),
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * امتیاز ثبت‌شدهٔ کاربر برای یک نشست.
	 *
	 * @param int $session_id
	 * @return array { message_id => rating }
	 */
	public static function get_feedback( $session_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT message_id, rating FROM ' . self::feedback_table() . ' WHERE session_id = %d', (int) $session_id ) // phpcs:ignore WordPress.DB
		);

		$out = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$out[ (int) $r->message_id ] = (int) $r->rating;
			}
		}
		return $out;
	}

	/* =========================================================
	 * آمار
	 * =======================================================*/

	/**
	 * آمار کلی برای داشبورد.
	 *
	 * @param int $days بازهٔ روزهای اخیر
	 * @return array
	 */
	public static function get_stats( $days = 30 ) {
		global $wpdb;

		$days  = max( 1, min( 365, (int) $days ) );
		$sess  = self::sessions_table();
		$msgs  = self::messages_table();
		$fb    = self::feedback_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$total_sessions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess}" ); // phpcs:ignore WordPress.DB
		$new_sessions   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sess} WHERE created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$total_messages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$msgs}" ); // phpcs:ignore WordPress.DB

		$visitor_msgs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$msgs} WHERE sender = 'visitor' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$agent_msgs   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$msgs} WHERE sender = 'agent' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$ai_msgs      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$msgs} WHERE sender = 'agent' AND meta LIKE %s AND created_at >= %s", '%"via":"ai"%', $since ) ); // phpcs:ignore WordPress.DB

		$avg_ms = (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(response_ms) FROM {$msgs} WHERE sender = 'agent' AND response_ms > 0 AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB

		$up   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fb} WHERE rating = 1 AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$down = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fb} WHERE rating = -1 AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$with_contact = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess} WHERE visitor_phone <> ''" ); // phpcs:ignore WordPress.DB

		return array(
			'days'           => $days,
			'total_sessions' => $total_sessions,
			'new_sessions'   => $new_sessions,
			'total_messages' => $total_messages,
			'visitor_msgs'   => $visitor_msgs,
			'agent_msgs'     => $agent_msgs,
			'ai_msgs'        => $ai_msgs,
			'human_msgs'     => max( 0, $agent_msgs - $ai_msgs ),
			'avg_ms'         => round( $avg_ms ),
			'thumbs_up'      => $up,
			'thumbs_down'    => $down,
			'csat'           => ( $up + $down ) > 0 ? (int) round( ( $up / ( $up + $down ) ) * 100 ) : null,
			'with_contact'   => $with_contact,
			'daily'          => self::daily_series( $days ),
			'top_questions'  => self::top_questions( $days ),
		);
	}

	/**
	 * سری روزانه برای نمودار.
	 *
	 * @param int $days
	 * @return array<int, array{date:string, chats:int, messages:int}>
	 */
	public static function daily_series( $days = 30 ) {
		global $wpdb;

		$days  = max( 1, min( 90, (int) $days ) );
		$sess  = self::sessions_table();
		$msgs  = self::messages_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$chat_rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$sess} WHERE created_at >= %s GROUP BY DATE(created_at)", $since ) ); // phpcs:ignore WordPress.DB
		$msg_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$msgs} WHERE created_at >= %s GROUP BY DATE(created_at)", $since ) ); // phpcs:ignore WordPress.DB

		$chats = array();
		$msgs_map = array();
		foreach ( (array) $chat_rows as $r ) {
			$chats[ $r->d ] = (int) $r->c;
		}
		foreach ( (array) $msg_rows as $r ) {
			$msgs_map[ $r->d ] = (int) $r->c;
		}

		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d     = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$out[] = array(
				'date'     => $d,
				'chats'    => isset( $chats[ $d ] ) ? $chats[ $d ] : 0,
				'messages' => isset( $msgs_map[ $d ] ) ? $msgs_map[ $d ] : 0,
			);
		}
		return $out;
	}

	/**
	 * پرتکرارترین سؤال‌ها.
	 *
	 * @param int $days
	 * @return array<int, array{q:string, n:int}>
	 */
	public static function top_questions( $days = 30 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, min( 365, (int) $days ) ) * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT content, COUNT(*) AS n FROM " . self::messages_table() . "
				 WHERE sender = 'visitor' AND created_at >= %s AND content <> ''
				 GROUP BY content ORDER BY n DESC LIMIT 8",
				$since
			) // phpcs:ignore WordPress.DB
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'q' => mb_substr( (string) $r->content, 0, 90 ),
				'n' => (int) $r->n,
			);
		}
		return $out;
	}

	/* =========================================================
	 * نگهداری داده (پاک‌سازی خودکار)
	 * =======================================================*/

	/**
	 * زمان‌بندی پاک‌سازی روزانه.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'pasokhban_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'pasokhban_daily_cleanup' );
		}
	}

	/**
	 * لغو زمان‌بندی.
	 */
	public static function unschedule_cleanup() {
		$ts = wp_next_scheduled( 'pasokhban_daily_cleanup' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'pasokhban_daily_cleanup' );
		}
	}

	/**
	 * پاک‌سازی مکالمات بستهٔ قدیمی.
	 *
	 * مکالماتی که هم بسته‌اند و هم قدیمی، همراه با پیام‌ها و امتیازهایشان
	 * پاک می‌شوند. مکالمات دارای شمارهٔ تماس (سرنخ فروش) دست‌نخورده می‌مانند.
	 *
	 * @param int $days
	 * @return int تعداد نشست‌های حذف‌شده
	 */
	public static function cleanup_old( $days ) {
		global $wpdb;

		$days = (int) $days;
		if ( $days < 1 ) {
			return 0;
		}

		if ( ! self::tables_exist() ) {
			return 0;
		}

		$sess = self::sessions_table();
		$msgs = self::messages_table();
		$fb   = self::feedback_table();

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$sess}
				 WHERE status = 'closed'
				   AND updated_at < %s
				   AND visitor_phone = ''",
				$cutoff
			) // phpcs:ignore WordPress.DB
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids     = array_map( 'intval', $ids );
		$ph      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$removed = 0;

		// تکه‌تکه تا کوئری خیلی بزرگ نشود
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$cph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			/**
			 * قبل از حذف ردیف‌ها: فایل‌های ضمیمهٔ همین نشست‌ها از دیسک
			 * پاک شوند. اگر بعد از حذف ردیف‌ها اجرا شود، دیگر meta در دسترس
			 * نیست و فایل‌ها تا ابد روی دیسک می‌مانند.
			 *
			 * @param array $chunk
			 */
			do_action( 'pasokhban_purge_sessions', $chunk );

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$msgs} WHERE session_id IN ({$cph})", $chunk ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$fb} WHERE session_id IN ({$cph})", $chunk ) );   // phpcs:ignore WordPress.DB
			$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$sess} WHERE id IN ({$cph})", $chunk ) ); // phpcs:ignore WordPress.DB
		}

		return $removed;
	}

	/**
	 * شمار نشست‌های باز با پیام خوانده‌نشده (برای Badge منوی ادمین).
	 *
	 * @return int
	 */
	public static function unread_count() {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . self::sessions_table() . ' WHERE unread_agent > 0';
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
	}

	/* =========================================================
	 * پیام‌ها
	 * =======================================================*/

	/**
	 * ثبت یک پیام.
	 *
	 * @param int    $session_id
	 * @param string $sender visitor|agent|system
	 * @param string $content
	 * @param array  $meta
	 * @param int    $response_ms زمان تولید پاسخ (برای آمار)
	 * @return object|null پیام ذخیره‌شده.
	 */
	public static function add_message( $session_id, $sender, $content, array $meta = array(), $response_ms = 0 ) {
		global $wpdb;

		$sender = in_array( $sender, array( 'visitor', 'agent', 'system' ), true ) ? $sender : 'visitor';
		$now    = self::now();

		$ok = $wpdb->insert(
			self::messages_table(),
			array(
				'session_id' => (int) $session_id,
				'sender'     => $sender,
				'content'    => $content,
				'meta'        => $meta ? wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) : null,
				'response_ms' => max( 0, (int) $response_ms ),
				'created_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $ok ) {
			return null;
		}

		$id = (int) $wpdb->insert_id;

		// همان ساختاری که get_messages برمی‌گرداند — وگرنه پاسخ تازه
		// فیلد time نداشت و در کلاینت بدون ساعت رندر می‌شد.
		return self::hydrate_message(
			(object) array(
				'id'         => $id,
				'session_id' => (int) $session_id,
				'sender'     => $sender,
				'content'     => $content,
				'meta'        => $meta ? wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) : null,
				'response_ms' => max( 0, (int) $response_ms ),
				'created_at'  => $now,
			)
		);
	}

	/**
	 * پیام‌های یک نشست.
	 *
	 * @param int $session_id
	 * @param int $after_id فقط پیام‌های با id بزرگ‌تر از این (برای polling).
	 * @param int $limit
	 * @return array
	 */
	public static function get_messages( $session_id, $after_id = 0, $limit = 200 ) {
		global $wpdb;

		$limit = max( 1, min( 500, (int) $limit ) );

		if ( $after_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::messages_table() . ' WHERE session_id = %d AND id > %d ORDER BY id ASC LIMIT %d', // phpcs:ignore WordPress.DB
					(int) $session_id,
					(int) $after_id,
					$limit
				)
			);
		} else {
			// گرفتن $limit پیام آخر، ولی با ترتیب زمانی درست.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ( SELECT * FROM ' . self::messages_table() . ' WHERE session_id = %d ORDER BY id DESC LIMIT %d ) AS t ORDER BY t.id ASC', // phpcs:ignore WordPress.DB
					(int) $session_id,
					$limit
				)
			);
		}

		return $rows ? array_map( array( __CLASS__, 'hydrate_message' ), $rows ) : array();
	}

	/**
	 * تبدیل ردیف خام به ساختار قابل JSON.
	 *
	 * @param object $row
	 * @return object
	 */
	public static function hydrate_message( $row ) {
		$meta = array();
		if ( ! empty( $row->meta ) ) {
			$decoded = json_decode( $row->meta, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}

		return (object) array(
			'id'      => (int) $row->id,
			'sender'  => $row->sender,
			'content' => (string) $row->content,
			'meta'    => $meta,
			'time'    => mysql2date( 'c', $row->created_at, false ),
			'ago'     => self::human_time( $row->created_at ),
		);
	}

	/**
	 * زمان خوانا به فارسی/انگلیسی.
	 *
	 * @param string $mysql_time
	 * @return string
	 */
	private static function human_time( $mysql_time ) {
		// human_time_diff وردپرس «5 mins» انگلیسی می‌دهد و منطقهٔ زمانی را
		// هم درست لحاظ نمی‌کند. برای بازار ایران واحد فارسی لازم است.
		if ( class_exists( 'Pasokhban_Jalali' ) ) {
			$ago = Pasokhban_Jalali::ago( $mysql_time );
			if ( '' !== $ago ) {
				return $ago;
			}
		}
		$ts = strtotime( $mysql_time . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		/* translators: %s: human-readable time difference */
		return sprintf( __( '%s پیش', 'pasokhban' ), human_time_diff( $ts, time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
	}

	/* =========================================================
	 * ابزارها
	 * =======================================================*/

	/**
	 * پاک‌سازی شمارهٔ تماس — رقم، +، خط تیره و فاصله مجاز است.
	 *
	 * @param string $phone
	 * @return string
	 */
	public static function sanitize_phone( $phone ) {
		$phone = (string) $phone;
		// ارقام فارسی/عربی به لاتین
		$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$phone = str_replace( $fa, $en, $phone );
		$phone = preg_replace( '/[^0-9+\-\s]/', '', $phone );
		$phone = trim( preg_replace( '/\s+/', ' ', $phone ) );
		return substr( $phone, 0, 40 );
	}

	/**
	 * کلید نشست: فقط hex، طول ثابت.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function sanitize_key( $key ) {
		$key = preg_replace( '/[^a-f0-9\-]/i', '', (string) $key );
		$key = strtolower( str_replace( '-', '', $key ) );
		if ( strlen( $key ) < 16 || strlen( $key ) > 64 ) {
			return '';
		}
		return substr( $key, 0, 64 );
	}

	/**
	 * ساخت کلید تصادفی امن.
	 *
	 * @return string
	 */
	public static function new_key() {
		$key = self::sanitize_key( wp_generate_uuid4() );
		return $key ? $key : md5( wp_generate_password( 32, false ) . microtime( true ) );
	}

	/**
	 * IP کلاینت با در نظر گرفتن پروکسی‌های معکوس رایج.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $candidates as $k ) {
			if ( empty( $_SERVER[ $k ] ) ) {
				continue;
			}
			$val   = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			$first = trim( explode( ',', $val )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return substr( $first, 0, 100 );
			}
		}
		return '';
	}

	/**
	 * زمان UTC فعلی با دقت ثانیه.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
