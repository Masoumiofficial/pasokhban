<?php
/**
 * بررسی سلامت (Health Check)
 *
 * چرا این فایل وجود دارد:
 * ──────────────────────────
 * گپینه به شش سرویس بیرونی وصل می‌شود (هوش مصنوعی، embedding، تلگرام،
 * رلهٔ گوگل، پیامک، بله) و به سه چیز داخلی (جداول MySQL، پوشهٔ آپلود،
 * ووکامرس). هر کدام از این نُه مسیر می‌تواند روی هاست مشتری بی‌صدا از کار
 * بیفتد: کلید اشتباه، پورت ۸۴۴۳ که بله قبولش نمی‌کند، پوشهٔ آپلود بدون
 * دسترسی نوشتن، جدول ساخته‌نشده بعد از مهاجرت نام.
 *
 * در همهٔ این حالت‌ها کاربر فقط یک نشانه می‌بیند: «پاسخ دریافت نشد».
 * تشخیص علت با پشتیبانی است — یعنی یک تیکت، چند روز رفت‌وبرگشت.
 *
 * این صفحه آن رفت‌وبرگشت را حذف می‌کند: هر مسیر را جدا و با فراخوانی
 * **واقعی** می‌آزماید، زمان پاسخ را اندازه می‌گیرد، و گزارشی متنی می‌سازد
 * که مشتری همان را در تیکت پیست می‌کند.
 *
 * اصل مهم: این صفحه هیچ کاری که هزینه دارد انجام نمی‌دهد.
 *   • پیامک واقعی نمی‌فرستد (پول مشتری).
 *   • پیام تلگرام/بله نمی‌فرستد (گروه پشتیبانی شلوغ می‌شود).
 *   • به‌جای آن از متدهای بی‌ضرر استفاده می‌کند: getMe برای تلگرام و بله،
 *     و برای پیامک فقط کامل‌بودن پیکربندی را می‌سنجد.
 *
 * @package Gapineh
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.1.0
 */
class Gapineh_Health {

	const PASS = 'pass';
	const FAIL = 'fail';
	const WARN = 'warn';
	const INFO = 'info';
	const SKIP = 'skip';

	/** حداقل نسخهٔ وردپرس (همان Requires at least در هدر پلاگین). */
	const MIN_WP = '5.8';

	/** حداقل نسخهٔ PHP (همان Requires PHP در هدر پلاگین). */
	const MIN_PHP = '7.4';

	/** پورت‌هایی که بله برای webhook قبول می‌کند (مستندات docs.bale.ai). */
	const BALE_PORTS = array( 443, 88 );

	/* =========================================================
	 * ثبت REST
	 * =======================================================*/

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'gapineh/v1',
			'/admin/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => array(
					'check' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'group' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
				),
			)
		);
	}

	public static function permission() {
		return current_user_can( 'manage_options' );
	}

	/* =========================================================
	 * فهرست گروه‌ها
	 * =======================================================*/

	/**
	 * @return array<string, array{label:string, icon:string}>
	 */
	public static function groups() {
		return array(
			'core'  => array( 'label' => __( 'محیط وردپرس', 'gapineh' ), 'icon' => '🖥' ),
			'db'    => array( 'label' => __( 'پایگاه‌داده', 'gapineh' ), 'icon' => '🗄' ),
			'ai'    => array( 'label' => __( 'هوش مصنوعی', 'gapineh' ), 'icon' => '🧠' ),
			'chat'  => array( 'label' => __( 'چت و آپلود', 'gapineh' ), 'icon' => '💬' ),
			'notif' => array( 'label' => __( 'اعلان‌ها', 'gapineh' ), 'icon' => '🔔' ),
			'shop'  => array( 'label' => __( 'ووکامرس', 'gapineh' ), 'icon' => '🛒' ),
			'bale'  => array( 'label' => __( 'کانال بله', 'gapineh' ), 'icon' => '📮' ),
			'files' => array( 'label' => __( 'فایل‌ها و برند', 'gapineh' ), 'icon' => '📦' ),
		);
	}

	/* =========================================================
	 * ورودی اصلی
	 * =======================================================*/

	/**
	 * اجرای بررسی‌ها.
	 *
	 * بدون پارامتر = همه. با ?group=ai = فقط آن گروه. با ?check=ai_ping
	 * = فقط آن یک مورد. اجرا به‌صورت تک‌موردی به رابط کاربری اجازه می‌دهد
	 * پیشرفت را زنده نشان بدهد و یک بررسی کند، بقیه را بلوکه نکند.
	 *
	 * @param \WP_REST_Request|null $request
	 * @return array
	 */
	public static function handle( $request = null ) {
		$check = $request && isset( $request['check'] ) ? (string) $request['check'] : '';
		$group = $request && isset( $request['group'] ) ? (string) $request['group'] : '';

		$registry = self::registry();

		if ( '' !== $check ) {
			if ( ! isset( $registry[ $check ] ) ) {
				return array(
					'ok'     => false,
					'error'  => __( 'بررسی ناشناخته است.', 'gapineh' ),
					'result' => null,
				);
			}
			return array(
				'ok'     => true,
				'result' => self::run_one( $check ),
			);
		}

		$out = array();
		foreach ( $registry as $id => $item ) {
			if ( '' !== $group && $item['group'] !== $group ) {
				continue;
			}
			$out[] = self::run_one( $id );
		}

		return array(
			'ok'       => true,
			'results'  => $out,
			'summary'  => self::summarize( $out ),
			'groups'   => self::groups(),
			'version'  => defined( 'GAPINEH_VERSION' ) ? GAPINEH_VERSION : '',
			'generated' => time(),
		);
	}

	/**
	 * یک بررسی را اجرا می‌کند و زمانش را اندازه می‌گیرد.
	 *
	 * هر خطای پیش‌بینی‌نشده هم به یک نتیجهٔ fail تبدیل می‌شود، نه به یک
	 * خطای ۵۰۰ — چون هدف صفحه همین است که وقتی چیزی خراب است هم کار کند.
	 *
	 * @param string $id
	 * @return array
	 */
	public static function run_one( $id ) {
		$registry = self::registry();
		if ( ! isset( $registry[ $id ] ) ) {
			return array(
				'id'     => $id,
				'group'  => '',
				'label'  => $id,
				'status' => self::FAIL,
				'msg'    => __( 'بررسی ناشناخته است.', 'gapineh' ),
				'ms'     => 0,
			);
		}

		$item  = $registry[ $id ];
		$start = microtime( true );

		try {
			$res = call_user_func( array( __CLASS__, $item['cb'] ) );
		} catch ( \Throwable $e ) {
			$res = array(
				'status' => self::FAIL,
				'msg'    => $e->getMessage(),
			);
		}

		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		return array(
			'id'     => $id,
			'group'  => $item['group'],
			'label'  => $item['label'],
			'status' => isset( $res['status'] ) ? $res['status'] : self::INFO,
			'msg'    => isset( $res['msg'] ) ? (string) $res['msg'] : '',
			'detail' => isset( $res['detail'] ) ? (string) $res['detail'] : '',
			'fix'    => isset( $res['fix'] ) ? (string) $res['fix'] : '',
			'ms'     => $ms,
		);
	}

	/**
	 * @param array $results
	 * @return array
	 */
	public static function summarize( array $results ) {
		$s = array( 'pass' => 0, 'fail' => 0, 'warn' => 0, 'info' => 0, 'skip' => 0 );
		foreach ( $results as $r ) {
			$k = isset( $r['status'] ) ? $r['status'] : self::INFO;
			if ( isset( $s[ $k ] ) ) {
				$s[ $k ]++;
			} else {
				$s['info']++;
			}
		}
		$s['total'] = count( $results );
		return $s;
	}

	/* =========================================================
	 * ثبت بررسی‌ها
	 *
	 * ترتیب اینجا همان ترتیب نمایش است: از زیرساخت به سمت سرویس‌های
	 * بیرونی. اگر پایگاه‌داده خراب باشد، بررسی‌های بعدی بی‌معنی‌اند.
	 * =======================================================*/

	/**
	 * @return array<string, array{group:string,label:string,cb:string}>
	 */
	public static function registry() {
		static $reg = null;
		if ( null !== $reg ) {
			return $reg;
		}

		$reg = array();
		$add = function ( $group, $id, $label, $cb ) use ( &$reg ) {
			$reg[ $id ] = array(
				'group' => $group,
				'label' => $label,
				'cb'    => $cb,
			);
		};

		// ── محیط وردپرس ────────────────────────────────────
		$add( 'core', 'core_wp', __( 'نسخهٔ وردپرس', 'gapineh' ), 'check_wp_version' );
		$add( 'core', 'core_php', __( 'نسخهٔ PHP', 'gapineh' ), 'check_php_version' );
		$add( 'core', 'core_ext', __( 'افزونه‌های ضروری PHP', 'gapineh' ), 'check_php_ext' );
		$add( 'core', 'core_db', __( 'اتصال به پایگاه‌داده', 'gapineh' ), 'check_db_conn' );
		$add( 'core', 'core_tz', __( 'منطقهٔ زمانی', 'gapineh' ), 'check_timezone' );
		$add( 'core', 'core_cron', __( 'زمان‌بند وردپرس (Cron)', 'gapineh' ), 'check_cron' );

		// ── پایگاه‌داده ────────────────────────────────────
		$add( 'db', 'db_tables', __( 'جدول‌های پلاگین', 'gapineh' ), 'check_tables' );
		$add( 'db', 'db_rw', __( 'آزمون نوشتن/خواندن/حذف', 'gapineh' ), 'check_db_roundtrip' );
		$add( 'db', 'db_ver', __( 'نسخهٔ ساختار جداول', 'gapineh' ), 'check_schema_version' );
		$add( 'db', 'db_legacy', __( 'جدول‌های نام قدیمی', 'gapineh' ), 'check_legacy_tables' );
		$add( 'db', 'db_stats', __( 'حجم داده', 'gapineh' ), 'check_stats' );

		// ── هوش مصنوعی ─────────────────────────────────────
		$add( 'ai', 'ai_key', __( 'کلید API', 'gapineh' ), 'check_api_key' );
		$add( 'ai', 'ai_model', __( 'مدل انتخاب‌شده', 'gapineh' ), 'check_model' );
		$add( 'ai', 'ai_base', __( 'آدرس پایهٔ سرویس', 'gapineh' ), 'check_base_url' );
		$add( 'ai', 'ai_ping', __( 'فراخوانی واقعی سرویس', 'gapineh' ), 'check_ai_ping' );
		$add( 'ai', 'ai_embed', __( 'ساخت بردار (embedding)', 'gapineh' ), 'check_embed' );
		$add( 'ai', 'ai_rag', __( 'فهرست دانش سایت', 'gapineh' ), 'check_rag' );
		$add( 'ai', 'ai_retrieve', __( 'جست‌وجوی معنایی', 'gapineh' ), 'check_retrieve' );

		// ── چت و آپلود ─────────────────────────────────────
		$add( 'chat', 'chat_widget', __( 'ویجت گفتگو', 'gapineh' ), 'check_widget' );
		$add( 'chat', 'chat_routes', __( 'مسیرهای REST', 'gapineh' ), 'check_routes' );
		$add( 'chat', 'chat_session', __( 'ساخت نشست آزمایشی', 'gapineh' ), 'check_session' );
		$add( 'chat', 'chat_upload_dir', __( 'پوشهٔ آپلود', 'gapineh' ), 'check_upload_dir' );
		$add( 'chat', 'chat_upload_types', __( 'پسوندهای مجاز آپلود', 'gapineh' ), 'check_upload_types' );

		// ── اعلان‌ها ───────────────────────────────────────
		$add( 'notif', 'notif_email', __( 'اعلان ایمیلی', 'gapineh' ), 'check_email' );
		$add( 'notif', 'notif_tg', __( 'تلگرام — اعتبار توکن', 'gapineh' ), 'check_telegram' );
		$add( 'notif', 'notif_relay', __( 'رلهٔ گوگل', 'gapineh' ), 'check_relay' );
		$add( 'notif', 'notif_sms', __( 'سرویس پیامک', 'gapineh' ), 'check_sms' );

		// ── ووکامرس ────────────────────────────────────────
		$add( 'shop', 'shop_active', __( 'نصب ووکامرس', 'gapineh' ), 'check_woo_active' );
		$add( 'shop', 'shop_products', __( 'ایندکس محصولات', 'gapineh' ), 'check_woo_products' );
		$add( 'shop', 'shop_lookup', __( 'استعلام سفارش توسط مشتری', 'gapineh' ), 'check_woo_lookup' );

		// ── بله ────────────────────────────────────────────
		$add( 'bale', 'bale_cfg', __( 'پیکربندی کانال بله', 'gapineh' ), 'check_bale_cfg' );
		$add( 'bale', 'bale_ping', __( 'بله — اعتبار توکن', 'gapineh' ), 'check_bale_ping' );
		$add( 'bale', 'bale_wh', __( 'ثبت webhook روی بله', 'gapineh' ), 'check_bale_webhook' );
		$add( 'bale', 'bale_port', __( 'پورت webhook', 'gapineh' ), 'check_bale_port' );

		// ── فایل‌ها و برند ─────────────────────────────────
		$add( 'files', 'files_assets', __( 'فایل‌های CSS و JS', 'gapineh' ), 'check_assets' );
		$add( 'files', 'files_legacy', __( 'باقی‌ماندهٔ نام قدیمی', 'gapineh' ), 'check_legacy_refs' );
		$add( 'files', 'files_logo', __( 'تصویر برند', 'gapineh' ), 'check_logo' );

		return $reg;
	}

	/* =========================================================
	 * ابزار مشترک
	 * =======================================================*/

	/**
	 * @param string      $status
	 * @param string      $msg
	 * @param string      $detail
	 * @param string      $fix راهنمای رفع مشکل — همین را پشتیبانی می‌پرسد
	 * @return array
	 */
	private static function r( $status, $msg, $detail = '', $fix = '' ) {
		return array(
			'status' => $status,
			'msg'    => $msg,
			'detail' => $detail,
			'fix'    => $fix,
		);
	}

	/** @return array تنظیمات پلاگین */
	private static function opts() {
		if ( ! class_exists( 'Gapineh_Settings' ) ) {
			return array();
		}
		return Gapineh_Settings::instance()->get_options();
	}

	/**
	 * زمان‌بندی امن: اگر تابع زمان‌بندی موجود نبود، صفر.
	 *
	 * @return float
	 */
	private static function now() {
		return microtime( true );
	}

	/**
	 * پورت واقعی آدرس سایت.
	 *
	 * از home_url() گرفته می‌شود نه از $_SERVER، چون روی بعضی هاست‌های
	 * پشت پروکسی معکوس $_SERVER['SERVER_PORT'] پورت داخلی است (مثلاً ۸۰۸۰)
	 * در حالی که مرورگر کاربر روی ۴۴۳ است. آنچه بله می‌بیند همان است که
	 * home_url() می‌گوید.
	 *
	 * @return int
	 */
	private static function site_port() {
		$url  = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$port = wp_parse_url( $url, PHP_URL_PORT );
		if ( $port ) {
			return (int) $port;
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return 'https' === $scheme ? 443 : 80;
	}

	/**
	 * آیا پاسخ سرویس HTML است؟ (نشانهٔ صفحهٔ خطای درگاه/پروکسی)
	 *
	 * @param string $body
	 * @return bool
	 */
	private static function is_html( $body ) {
		$head = ltrim( (string) $body );
		return 0 === stripos( $head, '<!doctype' ) || 0 === stripos( $head, '<html' );
	}

	/* =========================================================
	 * ۱) محیط وردپرس
	 * =======================================================*/

	private static function check_wp_version() {
		$v = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '';
		if ( '' === $v ) {
			return self::r( self::WARN, __( 'نسخهٔ وردپرس قابل تشخیص نیست.', 'gapineh' ) );
		}
		if ( version_compare( $v, self::MIN_WP, '<' ) ) {
			return self::r(
				self::FAIL,
				sprintf( /* translators: 1: current, 2: required */ __( 'وردپرس %1$s نصب است؛ گپینه به %2$s یا بالاتر نیاز دارد.', 'gapineh' ), $v, self::MIN_WP ),
				'',
				__( 'وردپرس را از پیشخوان → به‌روزرسانی‌ها ارتقا بدهید.', 'gapineh' )
			);
		}
		return self::r( self::PASS, sprintf( /* translators: %s: version */ __( 'وردپرس %s', 'gapineh' ), $v ) );
	}

	private static function check_php_version() {
		$v = PHP_VERSION;
		if ( version_compare( $v, self::MIN_PHP, '<' ) ) {
			return self::r(
				self::FAIL,
				sprintf( /* translators: 1: current, 2: required */ __( 'PHP %1$s فعال است؛ حداقل %2$s لازم است.', 'gapineh' ), $v, self::MIN_PHP ),
				'',
				__( 'از کنترل پنل هاست، نسخهٔ PHP سایت را تغییر دهید.', 'gapineh' )
			);
		}
		// ۸.۰ و ۸.۱ دیگر امنیت دریافت نمی‌کنند — اخطار، نه خطا.
		if ( version_compare( $v, '8.1', '<' ) ) {
			return self::r(
				self::WARN,
				sprintf( /* translators: %s: version */ __( 'PHP %s کار می‌کند ولی دیگر به‌روزرسانی امنیتی نمی‌گیرد.', 'gapineh' ), $v ),
				'',
				__( 'به PHP 8.2 یا بالاتر ارتقا دهید.', 'gapineh' )
			);
		}
		return self::r( self::PASS, sprintf( /* translators: %s: version */ __( 'PHP %s', 'gapineh' ), $v ) );
	}

	private static function check_php_ext() {
		$need = array(
			'mbstring' => __( 'برش درست متن فارسی و شمارش کاراکتر', 'gapineh' ),
			'json'     => __( 'گفتگو با سرویس‌های هوش مصنوعی', 'gapineh' ),
		);
		$missing = array();
		foreach ( $need as $ext => $why ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext . ' (' . $why . ')';
			}
		}
		if ( $missing ) {
			return self::r(
				self::FAIL,
				__( 'افزونهٔ PHP نصب نیست.', 'gapineh' ),
				implode( '، ', $missing ),
				__( 'در کنترل پنل هاست این افزونه‌ها را فعال کنید.', 'gapineh' )
			);
		}
		$opt = array();
		foreach ( array( 'curl', 'fileinfo', 'gd' ) as $ext ) {
			$opt[] = $ext . ( extension_loaded( $ext ) ? ' ✓' : ' ✗' );
		}
		return self::r( self::PASS, __( 'mbstring و json فعال‌اند.', 'gapineh' ), implode( ' · ', $opt ) );
	}

	private static function check_db_conn() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return self::r( self::FAIL, __( 'شیء $wpdb در دسترس نیست.', 'gapineh' ) );
		}
		$got = @$wpdb->get_var( 'SELECT 1' );
		if ( '1' !== (string) $got && 1 !== $got ) {
			return self::r(
				self::FAIL,
				__( 'پرس‌وجوی آزمایشی به پایگاه‌داده پاسخ نداد.', 'gapineh' ),
				isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '',
				__( 'اطلاعات wp-config.php و دسترسی کاربر MySQL را بررسی کنید.', 'gapineh' )
			);
		}
		$detail = '';
		if ( ! empty( $wpdb->db_server_info ) && is_callable( array( $wpdb, 'db_version' ) ) ) {
			$detail = 'MySQL ' . $wpdb->db_version();
		}
		return self::r( self::PASS, __( 'اتصال برقرار است.', 'gapineh' ), $detail );
	}

	private static function check_timezone() {
		// سه مسیر در کد پلاگین به زمان درست نیاز دارند: ساعت کاری،
		// محدودیت نرخ، و فاصلهٔ بین دو اعلان.
		if ( ! function_exists( 'wp_timezone' ) ) {
			return self::r( self::WARN, __( 'تابع wp_timezone() موجود نیست.', 'gapineh' ), '', __( 'وردپرس قدیمی است؛ آن را ارتقا دهید.', 'gapineh' ) );
		}
		try {
			$tz = wp_timezone();
		} catch ( \Throwable $e ) {
			return self::r( self::FAIL, __( 'منطقهٔ زمانی نامعتبر است.', 'gapineh' ), $e->getMessage() );
		}
		$name = $tz->getName();
		$now  = wp_date( 'Y/m/d H:i', time(), $tz );

		// اگر ساعت محلی بیش از ۳۰ دقیقه با UTC اختلاف غیرمنطقی دارد،
		// یعنی احتمالاً UTC مانده و ساعت کاری اشتباه محاسبه می‌شود.
		if ( 'UTC' === $name || '+00:00' === $name ) {
			return self::r(
				self::WARN,
				__( 'سایت روی UTC است؛ ساعت کاری و زمان پیام‌ها سه‌ونیم ساعت جلو می‌افتد.', 'gapineh' ),
				'UTC — ' . $now,
				__( 'در تنظیمات → عمومی → منطقهٔ زمانی را روی Tehran بگذارید.', 'gapineh' )
			);
		}
		return self::r( self::PASS, sprintf( /* translators: 1: tz, 2: time */ __( '%1$s — ساعت سرور %2$s', 'gapineh' ), $name, $now ) );
	}

	private static function check_cron() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return self::r( self::WARN, __( 'زمان‌بند در دسترس نیست.', 'gapineh' ) );
		}
		$missing = array();
		if ( class_exists( 'Gapineh_DB' ) && ! wp_next_scheduled( 'gapineh_cleanup' ) ) {
			$missing[] = __( 'پاک‌سازی روزانه', 'gapineh' );
		}
		if ( class_exists( 'Gapineh_RAG' ) && ! wp_next_scheduled( Gapineh_RAG::CRON_HOOK ) ) {
			$missing[] = __( 'همگام‌سازی دانش', 'gapineh' );
		}
		if ( $missing ) {
			return self::r(
				self::WARN,
				__( 'زمان‌بندی ثبت نشده است.', 'gapineh' ),
				implode( '، ', $missing ),
				__( 'پلاگین را یک بار غیرفعال و دوباره فعال کنید تا زمان‌بندی‌ها ساخته شوند.', 'gapineh' )
			);
		}
		return self::r( self::PASS, __( 'زمان‌بندی‌ها ثبت شده‌اند.', 'gapineh' ) );
	}

	/* =========================================================
	 * ۲) پایگاه‌داده
	 * =======================================================*/

	private static function check_tables() {
		if ( ! class_exists( 'Gapineh_DB' ) ) {
			return self::r( self::FAIL, __( 'کلاس پایگاه‌داده بارگذاری نشده است.', 'gapineh' ) );
		}
		if ( Gapineh_DB::tables_exist() ) {
			$names = array(
				Gapineh_DB::sessions_table(),
				Gapineh_DB::messages_table(),
				Gapineh_DB::feedback_table(),
				Gapineh_DB::chunks_table(),
				Gapineh_DB::bale_links_table(),
			);
			return self::r( self::PASS, __( 'هر پنج جدول ساخته شده‌اند.', 'gapineh' ), implode( ' · ', $names ) );
		}
		return self::r(
			self::FAIL,
			__( 'جدول‌های پلاگین ساخته نشده‌اند.', 'gapineh' ),
			'',
			__( 'پلاگین را غیرفعال و دوباره فعال کنید تا جداول ساخته شوند.', 'gapineh' )
		);
	}

	/**
	 * نوشتن/خواندن/حذف واقعی.
	 *
	 * چرا لازم است: tables_exist() فقط نام جدول را می‌بیند. اگر dbDelta
	 * نصفه اجرا شده باشد (مثلاً ستون اضافه‌شده در آپدیت)، جدول هست ولی
	 * INSERT می‌شکند. تنها راه فهمیدنش همین است که واقعاً بنویسیم.
	 */
	private static function check_db_roundtrip() {
		if ( ! class_exists( 'Gapineh_DB' ) ) {
			return self::r( self::FAIL, __( 'کلاس پایگاه‌داده بارگذاری نشده است.', 'gapineh' ) );
		}

		// کلید نشست باید از نویسه‌های هگز باشد (sanitize_key بقیه را
		// دور می‌ریزد و زیر ۱۶ نویسه را رد می‌کند) — پس کلید را خودِ
		// پایگاه‌داده می‌سازیم.
		$key  = Gapineh_DB::new_key();
		$text = __( 'پیام آزمایشی بررسی سلامت — این ردیف بلافاصله حذف می‌شود.', 'gapineh' );

		// create_session ردیف جدول را به‌صورت object برمی‌گرداند
		// (خروجی get_session_by_key)، نه آرایه.
		try {
			$session = Gapineh_DB::create_session( array( 'key' => $key ) );
		} catch ( \Throwable $e ) {
			return self::r( self::FAIL, __( 'نوشتن نشست آزمایشی ناموفق بود.', 'gapineh' ), $e->getMessage() );
		}

		if ( empty( $session ) || empty( $session->id ) ) {
			return self::r(
				self::FAIL,
				__( 'نوشتن نشست آزمایشی ناموفق بود.', 'gapineh' ),
				'',
				__( 'آخرین خطای MySQL را در «گفتگوهای آنلاین» ببینید.', 'gapineh' )
			);
		}

		$id = (int) $session->id;

		// خواندن
		$read = Gapineh_DB::get_session( $id );
		if ( empty( $read ) ) {
			self::cleanup_probe( $id );
			return self::r( self::FAIL, __( 'نشست آزمایشی نوشته شد ولی خوانده نشد.', 'gapineh' ) );
		}

		// نوشتن پیام
		$msg = Gapineh_DB::add_message( $id, 'visitor', $text );
		if ( empty( $msg ) || empty( $msg->id ) ) {
			self::cleanup_probe( $id );
			return self::r( self::FAIL, __( 'نوشتن پیام آزمایشی ناموفق بود.', 'gapineh' ) );
		}

		// خواندن پیام
		$msgs = Gapineh_DB::get_messages( $id );
		if ( empty( $msgs ) ) {
			self::cleanup_probe( $id );
			return self::r( self::FAIL, __( 'پیام آزمایشی خوانده نشد.', 'gapineh' ) );
		}

		self::cleanup_probe( $id );

		return self::r( self::PASS, __( 'نوشتن، خواندن و حذف هر سه کار کردند.', 'gapineh' ) );
	}

	/**
	 * پاک‌کردن رد پای آزمون تا در اینباکس کاربر دیده نشود.
	 *
	 * @param int $id
	 */
	private static function cleanup_probe( $id ) {
		global $wpdb;
		if ( ! class_exists( 'Gapineh_DB' ) || empty( $id ) ) {
			return;
		}
		$wpdb->delete( Gapineh_DB::messages_table(), array( 'session_id' => $id ) );
		$wpdb->delete( Gapineh_DB::sessions_table(), array( 'id' => $id ) );
		if ( class_exists( 'Gapineh_DB' ) && method_exists( 'Gapineh_DB', 'flush_cache' ) ) {
			Gapineh_DB::flush_cache();
		}
	}

	private static function check_schema_version() {
		if ( ! class_exists( 'Gapineh_DB' ) ) {
			return self::r( self::FAIL, __( 'کلاس پایگاه‌داده بارگذاری نشده است.', 'gapineh' ) );
		}
		$want = Gapineh_DB::SCHEMA_VERSION;
		$have = (string) get_option( 'gapineh_db_version', '' );

		if ( '' === $have ) {
			return self::r(
				self::WARN,
				__( 'نسخهٔ ساختار ثبت نشده است.', 'gapineh' ),
				__( 'انتظار: ', 'gapineh' ) . $want,
				__( 'پلاگین را یک بار غیرفعال و دوباره فعال کنید.', 'gapineh' )
			);
		}
		if ( $have !== $want ) {
			return self::r(
				self::WARN,
				sprintf( /* translators: 1: have, 2: want */ __( 'ساختار جداول %1$s است ولی کد %2$s می‌خواهد.', 'gapineh' ), $have, $want ),
				'',
				__( 'معمولاً با یک بار غیرفعال/فعال‌کردن پلاگین درست می‌شود.', 'gapineh' )
			);
		}
		return self::r( self::PASS, sprintf( /* translators: %s: version */ __( 'ساختار %s — مطابق کد.', 'gapineh' ), $have ) );
	}

	/**
	 * آیا جدول‌های نام قدیمی (etehadyar_*) هنوز مانده‌اند؟
	 *
	 * این یک خطا نیست — دادهٔ مشتری است و ما هرگز خودسرانه حذفش نمی‌کنیم.
	 * ولی اگر مانده باشد یعنی مهاجرت کامل نشده و ممکن است مکالمات قدیمی
	 * در اینباکس دیده نشوند.
	 */
	private static function check_legacy_tables() {
		global $wpdb;
		$found = array();

		// مسیر اول: فهرست جدول‌های شناخته‌شدهٔ وردپرس (بی‌خطر روی هر سروری).
		if ( ! empty( $wpdb->tables ) && is_array( $wpdb->tables ) ) {
			foreach ( $wpdb->tables as $t ) {
				if ( false !== strpos( (string) $t, 'etehadyar' ) ) {
					$found[] = $wpdb->prefix . $t;
				}
			}
		}

		// مسیر دوم: پرس‌وجوی مستقیم. روی MySQL کار می‌کند؛ اگر سرور
		// پشتیبانی نکرد، خطا را می‌بلعیم چون مسیر اول جواب داده است.
		if ( ! $found ) {
			$like = $wpdb->esc_like( $wpdb->prefix . 'etehadyar_' ) . '%';
			$rows = @$wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			if ( is_array( $rows ) ) {
				$found = $rows;
			}
		}

		if ( $found ) {
			return self::r(
				self::WARN,
				__( 'جدول‌های نام قدیمی هنوز موجودند.', 'gapineh' ),
				implode( '، ', array_slice( $found, 0, 6 ) ),
				__( 'مهاجرت نام را دوباره اجرا کنید یا پس از اطمینان از انتقال داده، آن‌ها را حذف کنید.', 'gapineh' )
			);
		}
		return self::r( self::PASS, __( 'جدول قدیمی باقی نمانده است.', 'gapineh' ) );
	}

	private static function check_stats() {
		if ( ! class_exists( 'Gapineh_DB' ) || ! Gapineh_DB::tables_exist() ) {
			return self::r( self::SKIP, __( 'جدول‌ها ساخته نشده‌اند.', 'gapineh' ) );
		}
		global $wpdb;
		$s = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Gapineh_DB::sessions_table() );
		$m = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Gapineh_DB::messages_table() );
		$c = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Gapineh_DB::chunks_table() );
		$u = Gapineh_DB::unread_count();

		return self::r(
			self::INFO,
			sprintf(
				/* translators: 1: sessions, 2: messages, 3: chunks */
				__( '%1$s نشست · %2$s پیام · %3$s تکهٔ دانش', 'gapineh' ),
				number_format_i18n( $s ),
				number_format_i18n( $m ),
				number_format_i18n( $c )
			),
			$u > 0 ? sprintf( /* translators: %s: count */ __( '%s پیام خوانده‌نشده', 'gapineh' ), number_format_i18n( $u ) ) : ''
		);
	}

	/* =========================================================
	 * ۳) هوش مصنوعی
	 * =======================================================*/

	private static function check_api_key() {
		$opts = self::opts();
		if ( empty( $opts['api_key'] ) ) {
			return self::r(
				self::FAIL,
				__( 'کلید API تنظیم نشده است.', 'gapineh' ),
				'',
				__( 'در تب «اتصال» کلید سرویس هوش مصنوعی را وارد کنید.', 'gapineh' )
			);
		}
		$key = (string) $opts['api_key'];
		return self::r(
			self::PASS,
			__( 'کلید وارد شده است.', 'gapineh' ),
			// فقط سر و ته کلید — کلید کامل هرگز چاپ نمی‌شود.
			self::mask( $key )
		);
	}

	/**
	 * کلید را می‌پوشاند: چهار نویسهٔ اول و آخر.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function mask( $key ) {
		$key = (string) $key;
		$len = mb_strlen( $key );
		if ( $len <= 12 ) {
			return str_repeat( '•', max( 0, $len ) );
		}
		return mb_substr( $key, 0, 6 ) . str_repeat( '•', 8 ) . mb_substr( $key, -4 );
	}

	private static function check_model() {
		$opts  = self::opts();
		$model = isset( $opts['model'] ) ? trim( (string) $opts['model'] ) : '';
		if ( '' === $model ) {
			return self::r(
				self::FAIL,
				__( 'مدلی انتخاب نشده است.', 'gapineh' ),
				'',
				__( 'در تب «اتصال» یک مدل انتخاب کنید.', 'gapineh' )
			);
		}
		// اگر سرویس فعلی فهرست مدل شناخته‌شده دارد و این مدل در آن نیست،
		// اخطار می‌دهیم — معمولاً یعنی کاربر مدل سرویس قبلی را جا گذاشته.
		if ( class_exists( 'Gapineh_Providers' ) ) {
			$prov = Gapineh_Providers::current( $opts );
			$list = Gapineh_Providers::models_for( $opts );
			if ( $list && ! in_array( $model, $list, true ) ) {
				return self::r(
					self::WARN,
					sprintf( /* translators: 1: model, 2: provider */ __( 'مدل %1$s در فهرست مدل‌های %2$s نیست.', 'gapineh' ), $model, $prov['key'] ),
					implode( ' · ', array_slice( $list, 0, 6 ) ),
					__( 'اگر سرویس را عوض کرده‌اید، مدل را هم عوض کنید.', 'gapineh' )
				);
			}
			return self::r( self::PASS, sprintf( /* translators: 1: model, 2: provider */ __( '%1$s روی %2$s', 'gapineh' ), $model, $prov['key'] ) );
		}
		return self::r( self::PASS, $model );
	}

	private static function check_base_url() {
		$opts = self::opts();
		if ( ! class_exists( 'Gapineh_Providers' ) ) {
			return self::r( self::FAIL, __( 'کلاس سرویس‌ها بارگذاری نشده است.', 'gapineh' ) );
		}
		$prov = Gapineh_Providers::current( $opts );
		$base = $prov['base_url'];

		if ( '' === $base ) {
			return self::r(
				self::FAIL,
				__( 'آدرس پایه خالی است.', 'gapineh' ),
				'',
				__( 'برای سرویس دلخواه باید آدرس را خودتان وارد کنید.', 'gapineh' )
			);
		}
		if ( 0 !== strpos( $base, 'https://' ) ) {
			return self::r(
				self::WARN,
				__( 'آدرس پایه HTTPS نیست — کلید API در مسیر روشن ارسال می‌شود.', 'gapineh' ),
				$base
			);
		}
		// اسلش اضافه در انتهای آدرس، مسیر را می‌شکند: //chat/completions
		if ( '/' === substr( $base, -1 ) ) {
			return self::r( self::WARN, __( 'آدرس پایه با / تمام می‌شود.', 'gapineh' ), $base );
		}
		return self::r( self::PASS, $base, __( 'قالب: ', 'gapineh' ) . $prov['api'] );
	}

	/**
	 * فراخوانی **واقعی** سرویس هوش مصنوعی.
	 *
	 * این همان کاری است که چت انجام می‌دهد، با کوچک‌ترین پیام ممکن.
	 * هزینه‌اش یک درخواست تک‌توکنی است.
	 */
	private static function check_ai_ping() {
		$opts = self::opts();
		if ( ! class_exists( 'Gapineh_Providers' ) ) {
			return self::r( self::FAIL, __( 'کلاس سرویس‌ها بارگذاری نشده است.', 'gapineh' ) );
		}
		if ( empty( $opts['api_key'] ) ) {
			return self::r( self::SKIP, __( 'کلید API تنظیم نشده — آزمون انجام نشد.', 'gapineh' ) );
		}

		$t0   = self::now();
		$test = Gapineh_Providers::test_connection( $opts );
		$ms   = (int) round( ( self::now() - $t0 ) * 1000 );

		if ( is_wp_error( $test ) ) {
			$msg = $test->get_error_message();
			$low = function_exists( 'mb_strtolower' ) ? mb_strtolower( $msg ) : strtolower( $msg );
			$fix = '';

			// ترتیب مهم است: اول نشانه‌های خاص، بعد عمومی. پیام خطا از خودِ
			// سرویس می‌آید (پس انگلیسی است) ولی باید راهنمای فارسی بدهیم.
			if ( false !== strpos( $low, 'curl error 28' ) || false !== strpos( $low, 'timed out' ) || false !== strpos( $low, 'timeout' ) ) {
				$fix = __( 'سرویس در زمان مقرر پاسخ نداد. اگر از ایران هستید و OpenAI یا Anthropic را انتخاب کرده‌اید، یا سرویس پروکسی ایرانی (گپ‌جی‌پی‌تی) را انتخاب کنید یا در تب «اعلان‌ها» پروکسی تنظیم کنید.', 'gapineh' );
			} elseif ( false !== strpos( $low, 'curl error 6' ) || false !== strpos( $low, 'resolve host' ) ) {
				$fix = __( 'نام دامنه حل نشد. DNS سرور را بررسی کنید.', 'gapineh' );
			} elseif ( false !== strpos( $low, 'curl error 7' ) || false !== strpos( $low, 'connection refused' ) ) {
				$fix = __( 'اتصال رد شد. فایروال هاست یا پروکسی را بررسی کنید.', 'gapineh' );
			} elseif ( false !== strpos( $low, '429' ) || false !== strpos( $low, 'quota' ) || false !== strpos( $low, 'rate limit' ) ) {
				$fix = __( 'سقف سهمیه یا نرخ درخواست پر شده است.', 'gapineh' );
			} elseif ( false !== strpos( $low, '404' ) || false !== strpos( $low, 'not found' ) || false !== strpos( $low, 'does not exist' ) ) {
				$fix = __( 'مسیر یا مدل پیدا نشد — آدرس پایه (باید به /v1 ختم شود) و نام مدل را بررسی کنید.', 'gapineh' );
			} elseif (
				false !== strpos( $low, '401' ) || false !== strpos( $low, '403' )
				|| false !== strpos( $low, 'key' ) || false !== strpos( $low, 'unauthorized' )
				|| false !== strpos( $low, 'authentication' ) || false !== strpos( $low, 'permission' )
			) {
				$fix = __( 'کلید API رد شد. آن را دوباره از پنل سرویس کپی کنید و مطمئنید سرویس انتخابی با صاحب کلید یکی است.', 'gapineh' );
			} elseif ( self::is_html( $msg ) || false !== strpos( $low, 'json نبود' ) ) {
				$fix = __( 'درگاه به‌جای JSON صفحهٔ HTML فرستاد؛ معمولاً یعنی فیلتر یا پروکسی سر راه است.', 'gapineh' );
			} else {
				$fix = __( 'کلید، مدل و آدرس پایه را در تب «اتصال» بررسی کنید.', 'gapineh' );
			}

			return self::r( self::FAIL, $msg, sprintf( /* translators: %d: ms */ __( '%d میلی‌ثانیه', 'gapineh' ), $ms ), $fix );
		}

		$status = $ms > 8000 ? self::WARN : self::PASS;
		$msg    = sprintf( /* translators: %d: ms */ __( 'سرویس پاسخ داد — %d میلی‌ثانیه', 'gapineh' ), $ms );
		if ( $ms > 8000 ) {
			$msg = sprintf( /* translators: %d: ms */ __( 'سرویس پاسخ داد ولی کند بود — %d میلی‌ثانیه', 'gapineh' ), $ms );
		}
		return self::r( $status, $msg );
	}

	/**
	 * آزمون واقعی ساخت بردار.
	 *
	 * چرا جدا از ai_ping: یک سرویس می‌تواند chat را داشته باشد و
	 * embedding را نداشته باشد. اگر این را جدا نسنجیم، کاربر RAG را
	 * روشن می‌کند و فهرست‌سازی بی‌صدا خالی می‌ماند.
	 */
	private static function check_embed() {
		$opts = self::opts();
		if ( ! class_exists( 'Gapineh_RAG' ) ) {
			return self::r( self::FAIL, __( 'کلاس RAG بارگذاری نشده است.', 'gapineh' ) );
		}
		if ( empty( $opts['rag_vector'] ) ) {
			return self::r( self::SKIP, __( 'جست‌وجوی برداری خاموش است.', 'gapineh' ), '', __( 'در تب «دستیار و دانش» فعالش کنید.', 'gapineh' ) );
		}
		if ( empty( $opts['api_key'] ) ) {
			return self::r( self::SKIP, __( 'کلید API تنظیم نشده — آزمون انجام نشد.', 'gapineh' ) );
		}

		$sample = 'آزمون سلامت گپینه — هزینهٔ ارسال چقدر است؟';
		$t0     = self::now();
		$res    = Gapineh_RAG::instance()->embed( array( $sample ) );
		$ms     = (int) round( ( self::now() - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			$fix = '';
			if ( 'gapineh_dims' === $res->get_error_code() ) {
				$fix = __( 'سرویس پارامتر dimensions را قبول نمی‌کند. در تنظیمات، ابعاد را روی صفر بگذارید.', 'gapineh' );
			} elseif ( false !== stripos( $msg, 'embedding' ) ) {
				$fix = __( 'این درگاه احتمالاً سرویس embedding ندارد. از گپ‌جی‌پی‌تی یا OpenAI استفاده کنید.', 'gapineh' );
			}
			return self::r( self::FAIL, $msg, '', $fix );
		}
		if ( empty( $res ) || empty( $res[0] ) ) {
			return self::r( self::FAIL, __( 'بردار خالی برگشت.', 'gapineh' ) );
		}

		$dims = count( $res[0] );
		$want = (int) $opts['rag_dims'];
		$note = sprintf( /* translators: %d: ms */ __( '%d میلی‌ثانیه', 'gapineh' ), $ms );

		if ( $want > 0 && $dims !== $want ) {
			return self::r(
				self::WARN,
				sprintf( /* translators: 1: got, 2: want */ __( 'بردار %1$s بعدی ساخت، ولی تنظیمات %2$s می‌خواهد.', 'gapineh' ), $dims, $want ),
				$note,
				__( 'اگر فهرست را با ابعاد دیگری ساخته‌اید، دوباره فهرست‌سازی کنید وگرنه جست‌وجو کار نمی‌کند.', 'gapineh' )
			);
		}
		return self::r(
			self::PASS,
			sprintf( /* translators: 1: model, 2: dims */ __( '%1$s — بردار %2$s بعدی', 'gapineh' ), $opts['rag_model'], $dims ),
			$note
		);
	}

	private static function check_rag() {
		$opts = self::opts();
		if ( empty( $opts['rag_vector'] ) ) {
			return self::r(
				self::SKIP,
				__( 'جست‌وجوی برداری خاموش است.', 'gapineh' ),
				'',
				__( 'بدون آن، گپینه فقط از متن ثابت «دانش دستی» استفاده می‌کند.', 'gapineh' )
			);
		}
		if ( ! class_exists( 'Gapineh_RAG' ) ) {
			return self::r( self::FAIL, __( 'کلاس RAG بارگذاری نشده است.', 'gapineh' ) );
		}

		$rag = Gapineh_RAG::instance();

		if ( ! $rag->table_ready() ) {
			return self::r(
				self::FAIL,
				__( 'جدول تکه‌های دانش ساخته نشده است.', 'gapineh' ),
				'',
				__( 'پلاگین را یک بار غیرفعال و دوباره فعال کنید.', 'gapineh' )
			);
		}

		$n = $rag->count_chunks();
		if ( 0 === $n ) {
			return self::r(
				self::WARN,
				__( 'فهرست دانش خالی است.', 'gapineh' ),
				'',
				__( 'در تب «دستیار و دانش» روی «ساخت فهرست» بزنید.', 'gapineh' )
			);
		}

		$state = $rag->state();
		$dirty = ! empty( $state['dirty'] );
		$note  = sprintf(
			/* translators: 1: count, 2: types */
			__( '%1$s تکه از %2$s', 'gapineh' ),
			number_format_i18n( $n ),
			$opts['rag_post_types']
		);

		if ( $dirty ) {
			return self::r( self::WARN, __( 'فهرست ساخته شده ولی محتوای تازه در انتظار همگام‌سازی است.', 'gapineh' ), $note );
		}
		return self::r( self::PASS, __( 'فهرست دانش آماده است.', 'gapineh' ), $note );
	}

	/**
	 * آیا جست‌وجو واقعاً نتیجه برمی‌گرداند؟
	 *
	 * این قوی‌ترین آزمون RAG است چون کل زنجیره را می‌پیماید: متن پرسش →
	 * بردار → مقایسه با تکه‌ها → امتیاز → آستانه.
	 */
	private static function check_retrieve() {
		$opts = self::opts();
		if ( empty( $opts['rag_vector'] ) || ! class_exists( 'Gapineh_RAG' ) ) {
			return self::r( self::SKIP, __( 'جست‌وجوی برداری خاموش است.', 'gapineh' ) );
		}
		$rag = Gapineh_RAG::instance();
		if ( 0 === $rag->count_chunks() ) {
			return self::r( self::SKIP, __( 'فهرست خالی است — چیزی برای جست‌وجو نیست.', 'gapineh' ) );
		}

		$t0  = self::now();
		$res = $rag->retrieve( __( 'هزینهٔ ارسال چقدر است؟', 'gapineh' ) );
		$ms  = (int) round( ( self::now() - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return self::r( self::FAIL, $res->get_error_message() );
		}

		// retrieve() در سه حالت null می‌دهد: خاموش بودن، نبود جدول، و
		// خالی‌بودن نتیجهٔ بالاتر از آستانه. هر سه برای کاربر یک نشانه
		// دارند ولی علتشان فرق می‌کند.
		$hits = is_array( $res ) && isset( $res['hits'] ) ? (array) $res['hits'] : array();
		if ( ! $hits ) {
			return self::r(
				self::WARN,
				__( 'جست‌وجو نتیجه‌ای بالاتر از آستانه برنگرداند.', 'gapineh' ),
				sprintf(
					/* translators: 1: threshold, 2: top_k, 3: ms */
					__( 'آستانهٔ شباهت %1$s · حداکثر %2$s تکه · %3$d میلی‌ثانیه', 'gapineh' ),
					$opts['rag_min_score'],
					$opts['rag_top_k'],
					$ms
				),
				__( 'آستانهٔ شباهت را کمی پایین بیاورید یا محتوای بیشتری فهرست کنید.', 'gapineh' )
			);
		}

		$top   = isset( $hits[0]['score'] ) ? round( (float) $hits[0]['score'], 3 ) : 0;
		$title = isset( $hits[0]['title'] ) ? (string) $hits[0]['title'] : '';
		return self::r(
			self::PASS,
			sprintf(
				/* translators: 1: n, 2: score, 3: ms */
				__( '%1$s تکه یافت شد — بیشترین شباهت %2$s در %3$d میلی‌ثانیه', 'gapineh' ),
				count( $hits ),
				$top,
				$ms
			),
			$title
		);
	}

	/* =========================================================
	 * ۴) چت و آپلود
	 * =======================================================*/

	private static function check_widget() {
		$opts = self::opts();
		$off  = array();
		if ( empty( $opts['widget_enabled'] ) ) {
			$off[] = __( 'ویجت شناور', 'gapineh' );
		}
		if ( empty( $opts['live_enabled'] ) ) {
			$off[] = __( 'گفتگوی آنلاین', 'gapineh' );
		}
		if ( $off ) {
			return self::r(
				self::WARN,
				__( 'خاموش است: ', 'gapineh' ) . implode( '، ', $off ),
				'',
				__( 'در تب «عمومی» و «چت آنلاین» روشنشان کنید.', 'gapineh' )
			);
		}

		return self::r( self::PASS, __( 'ویجت و گفتگوی آنلاین فعال‌اند.', 'gapineh' ), __( 'سبک: چت شیشه‌ای', 'gapineh' ) );
	}

	/**
	 * آیا مسیرهای REST ثبت شده‌اند؟
	 *
	 * روی بعضی هاست‌ها پیوندهای یکتا روی «ساده» است و rest_url() به
	 * ?rest_route= برمی‌گردد — این کار می‌کند، ولی یک افزونهٔ امنیتی می‌تواند
	 * کل REST را بسته باشد. آن‌وقت چت بی‌صدا از کار می‌افتد.
	 */
	private static function check_routes() {
		$must = array(
			'/chat',
			'/live/start',
			'/live/send',
			'/live/poll',
			'/admin/sessions',
		);
		$server = rest_get_server();
		$routes = $server ? $server->get_routes() : array();

		if ( empty( $routes ) ) {
			return self::r(
				self::WARN,
				__( 'فهرست مسیرهای REST در این زمینه خالی است.', 'gapineh' ),
				'',
				__( 'اگر چت کار می‌کند این را نادیده بگیرید.', 'gapineh' )
			);
		}

		$missing = array();
		foreach ( $must as $r ) {
			$full = '/gapineh/v1' . $r;
			if ( ! isset( $routes[ $full ] ) ) {
				$missing[] = $r;
			}
		}
		if ( $missing ) {
			return self::r(
				self::FAIL,
				__( 'بعضی مسیرهای REST ثبت نشده‌اند.', 'gapineh' ),
				implode( ' · ', $missing ),
				__( 'افزونهٔ امنیتی یا کش مسیرها را بسته است. آن را موقتاً غیرفعال کنید.', 'gapineh' )
			);
		}
		return self::r(
			self::PASS,
			sprintf( /* translators: %d: n */ __( '%d مسیر حیاتی ثبت شده است.', 'gapineh' ), count( $must ) ),
			function_exists( 'rest_url' ) ? rest_url( 'gapineh/v1' ) : ''
		);
	}

	private static function check_session() {
		if ( ! class_exists( 'Gapineh_DB' ) || ! Gapineh_DB::tables_exist() ) {
			return self::r( self::SKIP, __( 'جدول‌ها ساخته نشده‌اند.', 'gapineh' ) );
		}
		$key = Gapineh_DB::new_key();
		$s   = Gapineh_DB::get_or_create_session( $key );
		if ( empty( $s ) || empty( $s->id ) ) {
			return self::r( self::FAIL, __( 'ساخت نشست آزمایشی ناموفق بود.', 'gapineh' ) );
		}
		$again = Gapineh_DB::get_or_create_session( $key );
		$same  = ( (int) $again->id === (int) $s->id );
		self::cleanup_probe( (int) $s->id );

		if ( ! $same ) {
			return self::r(
				self::FAIL,
				__( 'نشست تکراری ساخته شد — کلید یکتا کار نمی‌کند.', 'gapineh' ),
				'',
				__( 'ایندکس unique روی ستون session_key وجود ندارد؛ پلاگین را دوباره فعال کنید.', 'gapineh' )
			);
		}
		return self::r( self::PASS, __( 'نشست ساخته و با همان کلید بازیابی شد.', 'gapineh' ) );
	}

	private static function check_upload_dir() {
		$opts = self::opts();
		if ( empty( $opts['live_upload'] ) ) {
			return self::r( self::SKIP, __( 'ارسال فایل خاموش است.', 'gapineh' ) );
		}

		$up = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => '', 'error' => 'no wp_upload_dir' );
		if ( ! empty( $up['error'] ) ) {
			return self::r( self::FAIL, __( 'پوشهٔ آپلود وردپرس در دسترس نیست.', 'gapineh' ), (string) $up['error'] );
		}

		// از خودِ کلاس آپلود استفاده می‌کنیم، نه از یک مسیر موازی:
		// ensure_dir() هم پوشه را می‌سازد و هم فایل‌های قفل را می‌نویسد.
		// اگر اینجا mkdir جداگانه می‌زدیم، پوشه‌ای می‌ساختیم که قفل نیست
		// و گزارش «سالم» می‌داد در حالی که آپلود واقعی بعداً می‌شکست.
		if ( ! class_exists( 'Gapineh_Upload' ) ) {
			return self::r( self::FAIL, __( 'کلاس آپلود بارگذاری نشده است.', 'gapineh' ) );
		}

		$ensured = Gapineh_Upload::ensure_dir();
		if ( is_wp_error( $ensured ) ) {
			return self::r(
				self::FAIL,
				$ensured->get_error_message(),
				Gapineh_Upload::dir(),
				__( 'دسترسی نوشتن روی wp-content/uploads را بررسی کنید (۷۵۵).', 'gapineh' )
			);
		}

		$dir = Gapineh_Upload::dir();

		if ( ! is_writable( $dir ) ) {
			return self::r(
				self::FAIL,
				__( 'پوشهٔ آپلود قابل نوشتن نیست.', 'gapineh' ),
				$dir,
				__( 'دسترسی پوشه را روی ۷۵۵ و مالکش را کاربر وب‌سرور بگذارید.', 'gapineh' )
			);
		}

		$locked  = file_exists( $dir . '/.htaccess' ) || file_exists( $dir . '/web.config' );
		$missing = array();
		if ( ! extension_loaded( 'fileinfo' ) ) {
			$missing[] = 'fileinfo';
		}
		if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
			$missing[] = 'wp_check_filetype_and_ext';
		}

		$detail = $locked
			? __( 'قفل اجرای اسکریپت فعال است.', 'gapineh' )
			: __( 'فایل قفل (.htaccess) نوشته نشد — اگر وب‌سرور nginx است، اجرای PHP در این پوشه را خودتان ببندید.', 'gapineh' );

		if ( $missing ) {
			return self::r(
				self::WARN,
				__( 'لایهٔ اعتبارسنجی محتوا در دسترس نیست.', 'gapineh' ),
				implode( '، ', $missing ) . ' — ' . $detail,
				__( 'افزونهٔ fileinfo را در PHP فعال کنید.', 'gapineh' )
			);
		}
		return self::r( self::PASS, __( 'پوشهٔ آپلود آماده و قفل است.', 'gapineh' ), $detail );
	}

	private static function check_upload_types() {
		$opts  = self::opts();
		$types = isset( $opts['live_upload_types'] ) ? (string) $opts['live_upload_types'] : '';
		$list  = array_filter( array_map( 'trim', explode( ',', strtolower( $types ) ) ) );

		if ( ! $list ) {
			return self::r(
				self::FAIL,
				__( 'هیچ پسوند مجازی تنظیم نشده — هیچ فایلی پذیرفته نمی‌شود.', 'gapineh' ),
				'',
				__( 'در تب «چت آنلاین» پسوندها را وارد کنید.', 'gapineh' )
			);
		}

		// پسوندهایی که هرگز نباید مجاز شوند.
		$danger = array( 'php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'exe', 'htaccess', 'svg', 'html', 'htm', 'js' );
		$bad    = array_intersect( $list, $danger );
		if ( $bad ) {
			return self::r(
				self::FAIL,
				__( 'پسوند خطرناک در فهرست مجاز است.', 'gapineh' ),
				implode( ', ', $bad ),
				__( 'این‌ها را حذف کنید؛ svg و html می‌توانند اسکریپت اجرا کنند.', 'gapineh' )
			);
		}

		$max = (int) ( isset( $opts['live_upload_max'] ) ? $opts['live_upload_max'] : 0 );
		$note = sprintf( /* translators: %d: mb */ __( 'سقف %d مگابایت', 'gapineh' ), $max );

		// سقف پلاگین بزرگ‌تر از سقف PHP = فایل‌های بزرگ بی‌صدا رد می‌شوند.
		$post_max = self::ini_bytes( ini_get( 'post_max_size' ) );
		if ( $max > 0 && $post_max > 0 && ( $max * 1048576 ) > $post_max ) {
			return self::r(
				self::WARN,
				__( 'سقف پلاگین از سقف PHP بزرگ‌تر است.', 'gapineh' ),
				sprintf( /* translators: 1: mb, 2: php */ __( 'پلاگین %1$d مگابایت، ولی post_max_size = %2$s', 'gapineh' ), $max, ini_get( 'post_max_size' ) ) . ' · ' . $note,
				__( 'در php.ini مقدار post_max_size و upload_max_filesize را بالا ببرید.', 'gapineh' )
			);
		}

		return self::r( self::PASS, implode( ', ', $list ), $note );
	}

	/**
	 * تبدیل مقدار php.ini به بایت.
	 *
	 * @param string $v مثل "8M" یا "1G"
	 * @return int
	 */
	private static function ini_bytes( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return 0;
		}
		$n   = (int) $v;
		$last = strtolower( substr( $v, -1 ) );
		switch ( $last ) {
			case 'g':
				$n *= 1024;
				// fall through
			case 'm':
				$n *= 1024;
				// fall through
			case 'k':
				$n *= 1024;
		}
		return $n;
	}

	/* =========================================================
	 * ۵) اعلان‌ها
	 * =======================================================*/

	private static function check_email() {
		$opts = self::opts();
		if ( empty( $opts['notify_email'] ) ) {
			return self::r( self::SKIP, __( 'اعلان ایمیلی خاموش است.', 'gapineh' ) );
		}
		$to = trim( (string) $opts['notify_email_to'] );
		if ( '' === $to ) {
			return self::r(
				self::FAIL,
				__( 'اعلان ایمیلی روشن است ولی گیرنده‌ای وارد نشده.', 'gapineh' ),
				'',
				__( 'در تب «اعلان‌ها» نشانی ایمیل را وارد کنید.', 'gapineh' )
			);
		}
		if ( ! is_email( $to ) ) {
			return self::r( self::FAIL, __( 'نشانی ایمیل نامعتبر است.', 'gapineh' ), $to );
		}
		return self::r( self::PASS, $to, __( 'ایمیل واقعی ارسال نشد تا صندوق شما پر نشود.', 'gapineh' ) );
	}

	/**
	 * تلگرام — با getMe توکن را می‌آزماید.
	 *
	 * چرا getMe و نه sendMessage: sendMessage گروه پشتیبانی مشتری را با
	 * پیام‌های آزمایشی پر می‌کند. getMe همان اعتبارسنجی است، بی‌ضرر.
	 */
	private static function check_telegram() {
		$opts = self::opts();
		if ( empty( $opts['notify_telegram'] ) ) {
			return self::r( self::SKIP, __( 'اعلان تلگرام خاموش است.', 'gapineh' ) );
		}
		$token = trim( (string) $opts['notify_telegram_token'] );
		$chat  = trim( (string) $opts['notify_telegram_chat'] );

		if ( '' === $token ) {
			return self::r( self::FAIL, __( 'توکن ربات وارد نشده است.', 'gapineh' ), '', __( 'از @BotFather توکن بگیرید.', 'gapineh' ) );
		}
		if ( '' === $chat ) {
			return self::r(
				self::FAIL,
				__( 'شناسهٔ گفتگو وارد نشده است.', 'gapineh' ),
				'',
				__( 'ربات را در گروه عضو کنید و شناسهٔ گروه (با - شروع می‌شود) را وارد کنید.', 'gapineh' )
			);
		}

		// اگر رله تنظیم شده، تلگرام مستقیم در دسترس نیست — آن مسیر جدا سنجیده می‌شود.
		$relay = trim( (string) $opts['notify_telegram_relay'] );
		if ( '' !== $relay ) {
			return self::r(
				self::INFO,
				__( 'رلهٔ گوگل فعال است؛ تلگرام مستقیم آزمون نشد.', 'gapineh' ),
				self::mask( $token ) . ' · chat: ' . $chat,
				__( 'نتیجهٔ رله را در بررسی «رلهٔ گوگل» ببینید.', 'gapineh' )
			);
		}

		$base = ! empty( $opts['notify_telegram_base'] ) ? untrailingslashit( (string) $opts['notify_telegram_base'] ) : 'https://api.telegram.org';
		$url  = $base . '/bot' . rawurlencode( $token ) . '/getMe';
		$t0   = self::now();
		$res  = wp_remote_get( $url, array( 'timeout' => 20 ) );
		$ms   = (int) round( ( self::now() - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			$fix = '';
			if ( false !== stripos( $msg, 'cURL error 28' ) || false !== stripos( $msg, 'timed out' ) ) {
				$fix = __( 'تلگرام از این سرور در دسترس نیست (فیلترینگ). رلهٔ گوگل را در تب «اعلان‌ها» تنظیم کنید.', 'gapineh' );
			}
			return self::r( self::FAIL, $msg, '', $fix );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( ! is_array( $data ) ) {
			return self::r( self::FAIL, __( 'پاسخ تلگرام JSON نبود.', 'gapineh' ), sprintf( /* translators: %d: code */ __( 'HTTP %d', 'gapineh' ), $code ) );
		}
		if ( empty( $data['ok'] ) ) {
			return self::r(
				self::FAIL,
				isset( $data['description'] ) ? sanitize_text_field( (string) $data['description'] ) : __( 'تلگرام خطا داد.', 'gapineh' ),
				'',
				__( 'توکن را دوباره از @BotFather کپی کنید.', 'gapineh' )
			);
		}
		$who = isset( $data['result']['username'] ) ? '@' . $data['result']['username'] : '';
		return self::r(
			self::PASS,
			sprintf( /* translators: 1: bot, 2: ms */ __( 'ربات %1$s پاسخ داد — %2$d میلی‌ثانیه', 'gapineh' ), $who, $ms ),
			__( 'عضویت ربات در گروه آزمون نشد؛ برای اطمینان یک پیام آزمایشی از تب «اعلان‌ها» بفرستید.', 'gapineh' )
		);
	}

	/**
	 * رلهٔ گوگل (Apps Script) — برای عبور از فیلترینگ تلگرام.
	 *
	 * فقط در دسترس‌بودن آدرس سنجیده می‌شود. درخواست POST واقعی به رله
	 * یعنی ارسال پیام، و ما پیام آزمایشی نمی‌فرستیم.
	 */
	private static function check_relay() {
		$opts = self::opts();
		$relay = trim( (string) $opts['notify_telegram_relay'] );

		if ( '' === $relay ) {
			if ( empty( $opts['notify_telegram'] ) ) {
				return self::r( self::SKIP, __( 'اعلان تلگرام خاموش است.', 'gapineh' ) );
			}
			return self::r( self::INFO, __( 'رله تنظیم نشده — اتصال مستقیم به تلگرام استفاده می‌شود.', 'gapineh' ) );
		}

		if ( false === strpos( $relay, 'script.google.com' ) && false === strpos( $relay, 'script.googleusercontent.com' ) ) {
			return self::r(
				self::WARN,
				__( 'آدرس رله، آدرس اسکریپت گوگل نیست.', 'gapineh' ),
				$relay,
				__( 'آدرس باید با https://script.google.com/macros/s/ شروع شود.', 'gapineh' )
			);
		}

		$t0  = self::now();
		$res = wp_remote_get( $relay, array( 'timeout' => 20, 'redirection' => 3 ) );
		$ms  = (int) round( ( self::now() - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return self::r( self::FAIL, $res->get_error_message(), '', __( 'آدرس رله را از اسکریپت گوگل دوباره کپی کنید و مطمئن شوید Deploy روی Anyone است.', 'gapineh' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		// اسکریپت گوگل برای GET بدون پارامتر معمولاً ۲۰۰ با JSON خطا
		// می‌دهد («token لازم است») — و همین یعنی زنده است.
		if ( $code >= 500 ) {
			return self::r(
				self::FAIL,
				sprintf( /* translators: %d: code */ __( 'رله خطای سرور %d داد.', 'gapineh' ), $code ),
				'',
				__( 'در اسکریپت گوگل، Execution log را ببینید.', 'gapineh' )
			);
		}
		if ( 401 === $code || 403 === $code ) {
			return self::r(
				self::FAIL,
				sprintf( /* translators: %d: code */ __( 'رله دسترسی نداد (HTTP %d).', 'gapineh' ), $code ),
				'',
				__( 'در اسکریپت گوگل Deploy → Manage deployments → دسترسی را روی Anyone بگذارید.', 'gapineh' )
			);
		}
		return self::r(
			self::PASS,
			sprintf( /* translators: 1: code, 2: ms */ __( 'رله در دسترس است (HTTP %1$d) — %2$d میلی‌ثانیه', 'gapineh' ), $code, $ms ),
			__( 'ارسال واقعی آزمون نشد تا پیامی به گروه نرود.', 'gapineh' )
		);
	}

	/**
	 * سرویس پیامک — فقط پیکربندی.
	 *
	 * عمداً پیامک واقعی نمی‌فرستیم: هر پیامک پول مشتری است و یک صفحهٔ
	 * تشخیص نباید هزینه بتراشد. در عوض کامل‌بودن پیکربندی سنجیده می‌شود
	 * و برای آزمون واقعی، همان دکمهٔ «ارسال پیامک آزمایشی» در تب اعلان‌ها.
	 */
	private static function check_sms() {
		$opts = self::opts();
		if ( empty( $opts['sms_enabled'] ) ) {
			return self::r( self::SKIP, __( 'پیامک خاموش است.', 'gapineh' ) );
		}

		$missing = array();
		if ( '' === trim( (string) $opts['sms_api_key'] ) ) {
			$missing[] = __( 'کلید API', 'gapineh' );
		}
		if ( '' === trim( (string) $opts['sms_sender'] ) ) {
			$missing[] = __( 'شمارهٔ فرستنده', 'gapineh' );
		}
		$providers = array( 'kavenegar', 'melipayamak', 'smsir' );
		if ( ! in_array( (string) $opts['sms_provider'], $providers, true ) ) {
			$missing[] = __( 'سرویس نامعتبر', 'gapineh' );
		}

		if ( $missing ) {
			return self::r(
				self::FAIL,
				__( 'پیکربندی پیامک کامل نیست.', 'gapineh' ),
				implode( '، ', $missing ),
				__( 'در تب «اعلان‌ها» این موارد را پر کنید.', 'gapineh' )
			);
		}

		$note = array();
		if ( empty( $opts['sms_notify_admin'] ) && empty( $opts['sms_notify_visitor'] ) ) {
			$note[] = __( 'هیچ گیرنده‌ای فعال نیست', 'gapineh' );
		}
		if ( ! empty( $opts['sms_notify_admin'] ) && '' === trim( (string) $opts['sms_admin_phone'] ) ) {
			return self::r(
				self::FAIL,
				__( 'اعلان پیامکی به مدیر روشن است ولی شماره‌ای وارد نشده.', 'gapineh' ),
				'',
				__( 'شمارهٔ موبایل مدیر را در تب «اعلان‌ها» وارد کنید.', 'gapineh' )
			);
		}

		return self::r(
			self::PASS,
			sprintf( /* translators: %s: provider */ __( 'سرویس %s پیکربندی شده است.', 'gapineh' ), $opts['sms_provider'] ),
			$note ? implode( ' · ', $note ) : __( 'پیامک واقعی ارسال نشد تا هزینه‌ای نتراشد.', 'gapineh' )
		);
	}

	/* =========================================================
	 * ۶) ووکامرس
	 * =======================================================*/

	private static function check_woo_active() {
		if ( ! class_exists( 'Gapineh_Woo' ) ) {
			return self::r( self::FAIL, __( 'کلاس ووکامرس بارگذاری نشده است.', 'gapineh' ) );
		}
		if ( ! Gapineh_Woo::active() ) {
			return self::r( self::INFO, __( 'ووکامرس فعال نیست — بخش فروشگاه خاموش است.', 'gapineh' ) );
		}
		return self::r( self::PASS, __( 'ووکامرس فعال است.', 'gapineh' ) );
	}

	private static function check_woo_products() {
		$opts = self::opts();
		if ( ! class_exists( 'Gapineh_Woo' ) || ! Gapineh_Woo::active() ) {
			return self::r( self::SKIP, __( 'ووکامرس فعال نیست.', 'gapineh' ) );
		}
		if ( empty( $opts['woo_products'] ) ) {
			return self::r( self::SKIP, __( 'ایندکس محصولات خاموش است.', 'gapineh' ), '', __( 'در تب «دستیار و دانش» روشنش کنید.', 'gapineh' ) );
		}

		// چند محصول واقعاً هست؟
		$n = 0;
		if ( function_exists( 'wc_get_products' ) ) {
			$list = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'return' => 'ids' ) );
			$n    = is_array( $list ) ? count( $list ) : 0;
		}
		if ( 0 === $n ) {
			return self::r(
				self::WARN,
				__( 'محصول منتشرشده‌ای پیدا نشد.', 'gapineh' ),
				'',
				__( 'اگر فروشگاه دارید، وضعیت محصولات را بررسی کنید.', 'gapineh' )
			);
		}

		$in_index = 0;
		if ( class_exists( 'Gapineh_RAG' ) && Gapineh_RAG::instance()->table_ready() ) {
			global $wpdb;
			$in_index = (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . Gapineh_DB::chunks_table() . " WHERE source_type = 'product'"
			);
		}

		$note = sprintf( /* translators: 1: n, 2: indexed */ __( '%1$d محصول منتشرشده · %2$d تکه در فهرست دانش', 'gapineh' ), $n, $in_index );

		if ( 0 === $in_index ) {
			return self::r(
				self::WARN,
				__( 'محصولات در فهرست دانش نیستند.', 'gapineh' ),
				$note,
				__( 'در تب «دستیار و دانش» فهرست را دوباره بسازید تا قیمت و موجودی هم ایندکس شود.', 'gapineh' )
			);
		}
		return self::r( self::PASS, __( 'محصولات ایندکس شده‌اند.', 'gapineh' ), $note );
	}

	private static function check_woo_lookup() {
		$opts = self::opts();
		if ( ! class_exists( 'Gapineh_Woo' ) || ! Gapineh_Woo::active() ) {
			return self::r( self::SKIP, __( 'ووکامرس فعال نیست.', 'gapineh' ) );
		}
		if ( empty( $opts['woo_order_lookup'] ) ) {
			return self::r( self::SKIP, __( 'استعلام سفارش خاموش است.', 'gapineh' ) );
		}
		if ( ! method_exists( 'Gapineh_Woo', 'latest_order_for' ) ) {
			return self::r(
				self::FAIL,
				__( 'تابع پاسخ به «سفارشم کجاست؟» موجود نیست.', 'gapineh' ),
				'',
				__( 'فایل افزونه ناقص است؛ نسخهٔ کامل را دوباره نصب کنید.', 'gapineh' )
			);
		}
		return self::r(
			self::PASS,
			__( 'مشتری می‌تواند با شمارهٔ تماس یا ایمیل، آخرین سفارشش را بپرسد.', 'gapineh' ),
			__( 'بدون نیاز به دانستن شمارهٔ سفارش.', 'gapineh' )
		);
	}

	/* =========================================================
	 * ۷) کانال بله
	 * =======================================================*/

	private static function check_bale_cfg() {
		$opts = self::opts();
		if ( empty( $opts['bale_enabled'] ) ) {
			return self::r( self::SKIP, __( 'کانال بله خاموش است.', 'gapineh' ) );
		}
		$missing = array();
		if ( '' === trim( (string) $opts['bale_token'] ) ) {
			$missing[] = __( 'توکن ربات', 'gapineh' );
		}
		if ( '' === trim( (string) $opts['bale_chat'] ) ) {
			$missing[] = __( 'شناسهٔ گروه', 'gapineh' );
		}
		if ( $missing ) {
			return self::r(
				self::FAIL,
				__( 'پیکربندی بله کامل نیست.', 'gapineh' ),
				implode( '، ', $missing )
			);
		}
		$mode = ! empty( $opts['bale_twoway'] ) ? __( 'دوطرفه (پاسخ از داخل بله)', 'gapineh' ) : __( 'یک‌طرفه (فقط اعلان)', 'gapineh' );
		return self::r( self::PASS, __( 'پیکربندی کامل است.', 'gapineh' ), $mode );
	}

	/**
	 * بله — getMe.
	 *
	 * API بله با API ربات تلگرام سازگار است، پس getMe اینجا هم کار
	 * می‌کند. این تنها راه بی‌ضرر برای فهمیدن این است که توکن زنده است.
	 */
	private static function check_bale_ping() {
		$opts = self::opts();
		if ( empty( $opts['bale_enabled'] ) || '' === trim( (string) $opts['bale_token'] ) ) {
			return self::r( self::SKIP, __( 'کانال بله خاموش است یا توکن ندارد.', 'gapineh' ) );
		}
		if ( ! class_exists( 'Gapineh_Bale' ) ) {
			return self::r( self::FAIL, __( 'کلاس بله بارگذاری نشده است.', 'gapineh' ) );
		}

		$url = Gapineh_Bale::API_BASE . '/bot' . rawurlencode( (string) $opts['bale_token'] ) . '/getMe';
		$t0  = self::now();
		$res = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => '{}' ) );
		$ms  = (int) round( ( self::now() - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return self::r( self::FAIL, $res->get_error_message(), '', __( 'اتصال سرور به bale.ai را بررسی کنید.', 'gapineh' ) );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return self::r( self::FAIL, __( 'پاسخ بله JSON نبود.', 'gapineh' ) );
		}
		if ( empty( $data['ok'] ) ) {
			return self::r(
				self::FAIL,
				isset( $data['description'] ) ? sanitize_text_field( (string) $data['description'] ) : __( 'بله خطا داد.', 'gapineh' ),
				'',
				__( 'توکن را از @botfather در بله دوباره بگیرید.', 'gapineh' )
			);
		}
		$who = isset( $data['result']['username'] ) ? '@' . $data['result']['username'] : '';
		return self::r(
			self::PASS,
			sprintf( /* translators: 1: bot, 2: ms */ __( 'ربات %1$s پاسخ داد — %2$d میلی‌ثانیه', 'gapineh' ), $who, $ms )
		);
	}

	/**
	 * آیا webhook واقعاً روی سرور بله ثبت شده؟
	 *
	 * این پرتکرارترین خرابی بله است: کاربر دکمهٔ ثبت را نزده، یا زده و
	 * شکست خورده و ندیده. تا وقتی webhook ثبت نباشد، پاسخ دوطرفه
	 * بی‌صدا کار نمی‌کند.
	 */
	private static function check_bale_webhook() {
		$opts = self::opts();
		if ( empty( $opts['bale_enabled'] ) ) {
			return self::r( self::SKIP, __( 'کانال بله خاموش است.', 'gapineh' ) );
		}
		if ( empty( $opts['bale_twoway'] ) ) {
			return self::r( self::INFO, __( 'حالت یک‌طرفه است؛ webhook لازم نیست.', 'gapineh' ) );
		}
		if ( '' === trim( (string) $opts['bale_webhook_secret'] ) ) {
			return self::r(
				self::FAIL,
				__( 'کلید webhook تنظیم نشده — نشانی webhook ساخته نمی‌شود.', 'gapineh' ),
				'',
				__( 'در تب «اعلان‌ها» یک کلید تصادفی بگذارید و بعد webhook را ثبت کنید.', 'gapineh' )
			);
		}

		$want = Gapineh_Bale::webhook_url();
		if ( '' === $want ) {
			return self::r( self::FAIL, __( 'نشانی webhook ساخته نشد.', 'gapineh' ) );
		}

		$url = Gapineh_Bale::API_BASE . '/bot' . rawurlencode( (string) $opts['bale_token'] ) . '/getWebhookInfo';
		$res = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => '{}' ) );

		if ( is_wp_error( $res ) ) {
			return self::r( self::FAIL, $res->get_error_message() );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['ok'] ) ) {
			return self::r(
				self::FAIL,
				__( 'بله وضعیت webhook را برنگرداند.', 'gapineh' ),
				is_array( $data ) && isset( $data['description'] ) ? sanitize_text_field( (string) $data['description'] ) : ''
			);
		}

		$have = isset( $data['result']['url'] ) ? (string) $data['result']['url'] : '';
		$errs = isset( $data['result']['last_error_message'] ) ? (string) $data['result']['last_error_message'] : '';

		if ( '' === $have ) {
			return self::r(
				self::FAIL,
				__( 'webhook روی بله ثبت نشده است.', 'gapineh' ),
				__( 'نشانی مورد انتظار: ', 'gapineh' ) . $want,
				__( 'در تب «اعلان‌ها» روی «ثبت webhook» بزنید.', 'gapineh' )
			);
		}
		if ( $have !== $want ) {
			return self::r(
				self::WARN,
				__( 'webhook ثبت شده ولی نشانی‌اش با نشانی فعلی سایت فرق دارد.', 'gapineh' ),
				__( 'ثبت‌شده: ', 'gapineh' ) . $have . ' · ' . __( 'انتظار: ', 'gapineh' ) . $want,
				__( 'اگر دامنه را عوض کرده‌اید، webhook را دوباره ثبت کنید.', 'gapineh' )
			);
		}
		if ( '' !== $errs ) {
			return self::r(
				self::WARN,
				__( 'webhook ثبت است ولی بله در تحویل آخرین پیام خطا داده است.', 'gapineh' ),
				$errs,
				__( 'یعنی سایت شما به درخواست بله پاسخ درست نداده — لاگ خطا را ببینید.', 'gapineh' )
			);
		}
		return self::r( self::PASS, __( 'webhook ثبت و سالم است.', 'gapineh' ), $have );
	}

	/**
	 * پورت webhook.
	 *
	 * مستندات بله فقط ۴۴۳ و ۸۸ را قبول می‌کند. اگر سایت روی
	 * https://site.com:8443 باشد، ثبت webhook رد می‌شود و خطایش هم گنگ است.
	 */
	private static function check_bale_port() {
		$opts = self::opts();
		if ( empty( $opts['bale_enabled'] ) || empty( $opts['bale_twoway'] ) ) {
			return self::r( self::SKIP, __( 'کانال بله یا حالت دوطرفه خاموش است.', 'gapineh' ) );
		}

		$home   = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$scheme = wp_parse_url( $home, PHP_URL_SCHEME );
		$port   = self::site_port();

		if ( 'https' !== $scheme ) {
			return self::r(
				self::FAIL,
				__( 'سایت HTTPS نیست.', 'gapineh' ),
				$home,
				__( 'بله فقط webhook روی HTTPS قبول می‌کند. گواهی SSL نصب کنید.', 'gapineh' )
			);
		}
		if ( ! in_array( $port, self::BALE_PORTS, true ) ) {
			return self::r(
				self::FAIL,
				sprintf( /* translators: 1: port, 2: allowed */ __( 'سایت روی پورت %1$d است؛ بله فقط %2$s را قبول می‌کند.', 'gapineh' ), $port, implode( ' و ', self::BALE_PORTS ) ),
				$home,
				__( 'سایت را روی پورت استاندارد ۴۴۳ ببرید (بدون :پورت در آدرس).', 'gapineh' )
			);
		}
		return self::r( self::PASS, sprintf( /* translators: %d: port */ __( 'پورت %d — مورد قبول بله.', 'gapineh' ), $port ), $home );
	}

	/* =========================================================
	 * ۸) فایل‌ها و برند
	 * =======================================================*/

	private static function check_assets() {
		$files = array(
			'assets/css/gapineh-chat.css',
			'assets/css/gapineh-admin.css',
			'assets/css/gapineh-inbox.css',
			'assets/js/gapineh-chat.js',
			'assets/js/gapineh-settings.js',
			'assets/js/gapineh-inbox.js',
		);
		$missing = array();
		$empty   = array();
		foreach ( $files as $f ) {
			$p = GAPINEH_PATH . $f;
			if ( ! file_exists( $p ) ) {
				$missing[] = $f;
			} elseif ( 0 === (int) filesize( $p ) ) {
				$empty[] = $f;
			}
		}
		if ( $missing ) {
			return self::r(
				self::FAIL,
				__( 'فایل افزونه موجود نیست.', 'gapineh' ),
				implode( '، ', $missing ),
				__( 'نصب ناقص است؛ افزونه را دوباره نصب کنید.', 'gapineh' )
			);
		}
		if ( $empty ) {
			return self::r( self::FAIL, __( 'فایل خالی است.', 'gapineh' ), implode( '، ', $empty ) );
		}
		return self::r(
			self::PASS,
			sprintf( /* translators: %d: n */ __( 'هر %d فایل اصلی موجود است.', 'gapineh' ), count( $files ) ),
			__( 'نسخهٔ فایل‌ها: ', 'gapineh' ) . ( defined( 'GAPINEH_VERSION' ) ? GAPINEH_VERSION : '?' )
		);
	}

	/**
	 * آیا نام‌گذاری پلاگین یکدست است؟
	 *
	 * تاریخچهٔ این بررسی: در رنیم ۳.۰.۰ نام پلاگین از «اتحادیار» به «گپینه» عوض شد و
	 * بخشی از CSS هنوز کلاس‌های ecl-* (پیشوند قدیمی) را داشت — نشانهٔ نصب نصفه‌نیمه.
	 * سبک «مغز کیهانی» (و تزریق window.GAPINEH برای اسکریپتش) حذف شده، پس این بررسی
	 * فقط ماندگاری کلاس‌های قدیمی در CSS‌های فعال را می‌سنجد.
	 */
	private static function check_legacy_refs() {
		$css = array(
			'assets/css/gapineh-chat.css',
			'assets/css/gapineh-inbox.css',
			'assets/css/gapineh-admin.css',
		);

		$problems = array();

		foreach ( $css as $f ) {
			$p = GAPINEH_PATH . $f;
			if ( ! file_exists( $p ) ) {
				continue;
			}
			if ( preg_match( '/\.ecl-[a-z]/', (string) file_get_contents( $p ) ) ) {
				$problems[] = sprintf( /* translators: %s: file */ __( 'کلاس ecl-* در %s', 'gapineh' ), basename( $f ) );
			}
		}

		if ( $problems ) {
			return self::r(
				self::WARN,
				__( 'نام‌گذاری پلاگین یکدست نیست.', 'gapineh' ),
				implode( ' · ', $problems ),
				__( 'نصب نصفه‌نیمه است؛ افزونه را دوباره نصب کنید.', 'gapineh' )
			);
		}
		return self::r( self::PASS, __( 'نام‌گذاری یکدست است و کلاس قدیمی نمانده.', 'gapineh' ) );
	}

	private static function check_logo() {
		$opts = self::opts();
		$candidates = array(
			'assets/img/brand-mark.svg',
		);
		$missing = array();
		foreach ( $candidates as $f ) {
			if ( ! file_exists( GAPINEH_PATH . $f ) ) {
				$missing[] = $f;
			}
		}
		if ( $missing ) {
			return self::r( self::WARN, __( 'تصویر برند موجود نیست.', 'gapineh' ), implode( '، ', $missing ) );
		}
		$note = empty( $opts['brand_hide_credit'] )
			? __( 'پابرگ برند نمایش داده می‌شود.', 'gapineh' )
			: __( 'پابرگ برند پنهان است.', 'gapineh' );

		return self::r( self::PASS, __( 'تصویر برند موجود است.', 'gapineh' ), $note );
	}

	/* =========================================================
	 * گزارش متنی — برای پیست کردن در تیکت پشتیبانی
	 * =======================================================*/

	/**
	 * @param array $results
	 * @return string
	 */
	public static function report( array $results ) {
		$labels = array(
			self::PASS => 'OK  ',
			self::FAIL => 'FAIL',
			self::WARN => 'WARN',
			self::INFO => 'INFO',
			self::SKIP => 'SKIP',
		);
		$groups = self::groups();
		$lines  = array();

		$lines[] = sprintf( 'گپینه %s — گزارش سلامت %s', defined( 'GAPINEH_VERSION' ) ? GAPINEH_VERSION : '?', gmdate( 'Y-m-d H:i' ) );
		$lines[] = sprintf( 'PHP %s · %s', PHP_VERSION, function_exists( 'get_bloginfo' ) ? 'WP ' . get_bloginfo( 'version' ) : '' );
		$lines[] = str_repeat( '─', 46 );

		$by_group = array();
		foreach ( $results as $r ) {
			$by_group[ $r['group'] ][] = $r;
		}
		foreach ( $by_group as $g => $items ) {
			$lines[] = '';
			$lines[] = isset( $groups[ $g ] ) ? $groups[ $g ]['label'] : $g;
			foreach ( $items as $r ) {
				$tag  = isset( $labels[ $r['status'] ] ) ? $labels[ $r['status'] ] : 'INFO';
				$line = sprintf( '  [%s] %s — %s', $tag, $r['label'], $r['msg'] );
				if ( '' !== $r['ms'] && $r['ms'] > 0 && $r['ms'] > 1000 ) {
					$line .= sprintf( ' (%d ms)', $r['ms'] );
				}
				$lines[] = $line;
				if ( self::FAIL === $r['status'] || self::WARN === $r['status'] ) {
					if ( '' !== $r['detail'] ) {
						$lines[] = '         ' . $r['detail'];
					}
					if ( '' !== $r['fix'] ) {
						$lines[] = '         → ' . $r['fix'];
					}
				}
			}
		}

		$s = self::summarize( $results );
		$lines[] = '';
		$lines[] = str_repeat( '─', 46 );
		$lines[] = sprintf(
			'جمع: %d بررسی — %d سالم، %d خطا، %d هشدار',
			$s['total'],
			$s['pass'],
			$s['fail'],
			$s['warn']
		);
		$lines[] = 'گپینه — etehadwp.com';

		return implode( "\n", $lines );
	}
}
