<?php
/**
 * آپلود فایل و تصویر در گفتگوی آنلاین — پاسخ‌بان.
 *
 * چرا این فایل حساس است:
 * آپلود فایل کاربر، پرتکرارترین راه نفوذ به وردپرس است. پس اینجا اصل
 * «شک کن و رد کن» حاکم است، نه «اجازه بده مگر اینکه…»
 *
 * چهار لایهٔ محافظت که همه با هم لازم‌اند:
 *
 * ۱) فهرست سفید پسوند — نه فهرست سیاه. فهرست سیاه همیشه یک مورد جا
 *    می‌اندازد (.phtml، .php7، .pht، …).
 *
 * ۲) بررسی MIME واقعی محتوای فایل با finfo، و مطابقتش با پسوند. فایلی
 *    که پسوند .jpg دارد ولی محتوایش PHP است، رد می‌شود.
 *
 * ۳) نام فایل هرگز از کاربر گرفته نمی‌شود. یک نام تصادفی ۲۴ کاراکتری
 *    ساخته می‌شود، پس مسیرها قابل حدس و شمارش نیستند.
 *
 * ۴) پوشهٔ آپلود با .htaccess و web.config قفل می‌شود تا حتی اگر فایلی
 *    با پسوند خطرناک رد شد، اجرا نشود.
 *
 * یک نکتهٔ طراحی که امنیت را واقعاً بهتر می‌کند:
 * کلاینت هرگز URL فایل را به /live/send نمی‌فرستد. سرور برای هر آپلود
 * یک «توکن» می‌دهد و کلاینت فقط توکن را برمی‌گرداند. وگرنه کاربر
 * می‌توانست هر URL دلخواهی (مثلاً یک اسکریپت روی همان هاست) را به‌عنوان
 * ضمیمهٔ پیام جا بزند و اپراتور رویش کلیک کند.
 *
 * @package Pasokhban
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس آپلود.
 */
final class Pasokhban_Upload {

	/** پوشهٔ آپلود داخل wp-content/uploads. */
	const SUBDIR = 'pasokhban';

	/** سقف ضمیمه در هر پیام. */
	const MAX_PER_MESSAGE = 4;

	/** تعداد توکن معتبری که برای هر نشست نگه داشته می‌شود. */
	const MAX_PENDING = 12;

	/** عمر توکن (ثانیه) — اگر کاربر آپلود کرد و پیام را نفرستاد. */
	const TOKEN_TTL = 3600;

	/**
	 * فهرست سفید: پسوند → MIMEهای قابل قبول.
	 *
	 * عمداً zip/rar/svg/exe و هر چیز اجرایی یا اسکریپت‌پذیر اینجا نیست.
	 * SVG عمداً رد می‌شود: یک فرمت «تصویر» است که می‌تواند JavaScript
	 * داشته باشد و در مرورگر اپراتور اجرا شود.
	 */
	private static $allowed = array(
		'jpg'  => array( 'image/jpeg', 'image/jpg' ),
		'jpeg' => array( 'image/jpeg', 'image/jpg' ),
		'png'  => array( 'image/png' ),
		'gif'  => array( 'image/gif' ),
		'webp' => array( 'image/webp' ),
		'pdf'  => array( 'application/pdf' ),
		'txt'  => array( 'text/plain' ),
	);

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'pasokhban_purge_sessions', array( __CLASS__, 'purge_sessions' ) );
	}

	public static function register_routes() {
		register_rest_route( 'pasokhban/v1', '/live/upload', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_upload' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* =========================================================
	 * مسیرها
	 * =======================================================*/

	/** @return string مسیر فیزیکی پوشهٔ آپلود */
	public static function dir() {
		$up = wp_upload_dir();
		return trailingslashit( isset( $up['basedir'] ) ? $up['basedir'] : '' ) . self::SUBDIR;
	}

	/** @return string آدرس عمومی پوشهٔ آپلود */
	public static function url() {
		$up = wp_upload_dir();
		return trailingslashit( isset( $up['baseurl'] ) ? $up['baseurl'] : '' ) . self::SUBDIR;
	}

	/**
	 * پوشه را می‌سازد و قفل می‌کند.
	 *
	 * @return bool|WP_Error
	 */
	public static function ensure_dir() {
		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'pasokhban_updir',
					__( 'ساخت پوشهٔ آپلود ممکن نشد. دسترسی نوشتن روی wp-content/uploads را بررسی کنید.', 'pasokhban' )
				);
			}
		}

		// قفل‌کردن پوشه: فقط یک‌بار، و اگر خودمان نوشته باشیم.
		if ( ! get_option( 'pasokhban_updir_hard' ) ) {
			self::harden( $dir );
			update_option( 'pasokhban_updir_hard', 1 );
		}

		return true;
	}

	/**
	 * نوشتن فایل‌های محافظ در پوشهٔ آپلود.
	 *
	 * @param string $dir
	 */
	private static function harden( $dir ) {
		// آپاچی/لایت‌اسپید: موتور PHP خاموش + اجرای CGI ممنوع.
		@file_put_contents( $dir . '/.htaccess', "# پاسخ‌بان — اجرای اسکریپت در این پوشه ممنوع\nphp_flag engine off\nOptions -ExecCGI -Indexes\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py\nRemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n" );

		// IIS
		@file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <handlers accessPolicy=\"Read\" />\n    <staticContent>\n      <remove fileExtension=\".php\" />\n    </staticContent>\n  </system.webServer>\n</configuration>\n" );

		// اگر .htaccess خوانده نشود (nginx)، حداقل فهرست‌گیری مستقیم بسته باشد.
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}

	/* =========================================================
	 * اندپوینت آپلود
	 * =======================================================*/

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_upload( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['live_enabled'] ) || empty( $opts['live_upload'] ) ) {
			return new WP_Error(
				'pasokhban_noupload',
				__( 'ارسال فایل غیرفعال است.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		$session = Pasokhban_DB::get_session_by_key( (string) $request->get_param( 'key' ) );
		if ( ! $session ) {
			return new WP_Error(
				'pasokhban_nosession',
				__( 'نشست پیدا نشد. صفحه را تازه کنید.', 'pasokhban' ),
				array( 'status' => 404 )
			);
		}

		if ( Pasokhban_API::instance()->is_rate_limited( 'upload' ) ) {
			return new WP_Error(
				'pasokhban_rate',
				__( 'درخواست‌های زیادی ارسال کردید. کمی صبر کنید.', 'pasokhban' ),
				array( 'status' => 429 )
			);
		}

		if ( ! isset( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			return new WP_Error(
				'pasokhban_nofile',
				__( 'فایلی دریافت نشد.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		$file = $_FILES['file'];
		$err  = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
			return new WP_Error(
				'pasokhban_toobig',
				sprintf(
					/* translators: %d: max megabytes */
					__( 'فایل بزرگ‌تر از حد مجاز است (حداکثر %d مگابایت).', 'pasokhban' ),
					(int) $opts['live_upload_max']
				),
				array( 'status' => 413 )
			);
		}

		if ( UPLOAD_ERR_OK !== $err ) {
			return new WP_Error(
				'pasokhban_upfail',
				__( 'آپلود ناموفق بود. دوباره تلاش کنید.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;

		// اگر سرور فایل را به‌عنوان آپلود HTTP نفرستاده باشد، ادامه نده.
		// فیلتر pre_ به همان الگوی وردپرس است و این نقطه را قابل آزمون
		// می‌کند؛ is_uploaded_file تابع داخلی PHP است و نمی‌شود در تست
		// جایگزینش کرد.
		$is_up = apply_filters( 'pasokhban_pre_is_upload', null, $tmp );
		if ( null === $is_up ) {
			$is_up = is_uploaded_file( $tmp );
		}
		if ( ! $is_up ) {
			return new WP_Error(
				'pasokhban_badup',
				__( 'فایل معتبر نیست.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		$max_bytes = max( 1, (int) $opts['live_upload_max'] ) * 1024 * 1024;
		if ( $size < 1 || $size > $max_bytes ) {
			return new WP_Error(
				'pasokhban_toobig',
				sprintf(
					/* translators: %d: max megabytes */
					__( 'فایل بزرگ‌تر از حد مجاز است (حداکثر %d مگابایت).', 'pasokhban' ),
					(int) $opts['live_upload_max']
				),
				array( 'status' => 413 )
			);
		}

		// ── بررسی نوع فایل ──
		$check = self::check_file( $tmp, $name, $opts );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// ── ساخت پوشه و انتقال ──
		$ok = self::ensure_dir();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		// نام تصادفی — هرگز نام کاربر. مسیر بی‌نظم یعنی غیرقابل حدس.
		$final_name = wp_generate_password( 24, false, false ) . '.' . $check['ext'];
		$dest       = self::dir() . '/' . $final_name;

		$moved = apply_filters( 'pasokhban_pre_move_upload', null, $tmp, $dest );
		if ( null === $moved ) {
			$moved = move_uploaded_file( $tmp, $dest );
		}

		if ( ! $moved ) {
			return new WP_Error(
				'pasokhban_upfail',
				__( 'ذخیرهٔ فایل ممکن نشد.', 'pasokhban' ),
				array( 'status' => 500 )
			);
		}

		@chmod( $dest, 0644 );

		$meta = array(
			'name' => self::safe_name( $name ),
			'url'  => self::url() . '/' . $final_name,
			'file' => $final_name,
			'mime' => $check['mime'],
			'size' => $size,
		);

		if ( 0 === strpos( $check['mime'], 'image/' ) ) {
			$dim = @getimagesize( $dest );
			if ( is_array( $dim ) && ! empty( $dim[0] ) ) {
				$meta['w'] = (int) $dim[0];
				$meta['h'] = (int) $dim[1];
			}
		}

		$token = self::issue_token( (int) $session->id, $meta );

		return rest_ensure_response( array(
			'ok'          => true,
			'token'       => $token,
			'attachment'  => array_merge( $meta, array( 'token' => $token ) ),
		) );
	}

	/**
	 * بررسی پسوند + MIME واقعی فایل.
	 *
	 * @param string $tmp
	 * @param string $name
	 * @param array  $opts
	 * @return array|WP_Error { ext:string, mime:string }
	 */
	public static function check_file( $tmp, $name, $opts ) {
		$allowed_exts = self::allowed_exts( $opts );

		// پسوند از روی نام کاربر — فقط برای انتخاب قاعده، نه برای ذخیره.
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		// پسوند دوگانه مثل shell.php.jpg هم رد می‌شود: آخرین بخش را
		// می‌گیریم و کل نام را هم برای پسوندهای خطرناک جارو می‌کنیم.
		if ( '' === $ext || ! isset( self::$allowed[ $ext ] ) || ! in_array( $ext, $allowed_exts, true ) ) {
			return new WP_Error(
				'pasokhban_badtype',
				sprintf(
					/* translators: %s: allowed extensions */
					__( 'این نوع فایل مجاز نیست. پسوندهای مجاز: %s', 'pasokhban' ),
					implode( ', ', $allowed_exts )
				),
				array( 'status' => 415 )
			);
		}

		// پسوند خطرناک در هر کجای نام رد می‌شود، نه فقط در انتها.
		// الگوی قبلی به $ ختم می‌شد، پس shell.php.jpg رد نمی‌شد: رشته با
		// «.jpg» تمام می‌شود. مسیر ذخیرهٔ ما پسوند را خودش می‌سازد و این
		// فایل عملاً قابل اجرا نبود، ولی ردکردن الگوی کلاسیک حمله هزینه‌ای
		// ندارد و یک لایهٔ دفاعی اضافه است.
		if ( preg_match( '/\.(php[0-9]?|phtml|phar|cgi|pl|py|sh|exe|svg|html?|js|htaccess)\b/i', $name ) ) {
			return new WP_Error(
				'pasokhban_badtype',
				__( 'این نوع فایل مجاز نیست.', 'pasokhban' ),
				array( 'status' => 415 )
			);
		}

		// ── لایهٔ چهارم: نظر خود وردپرس ──
		// wp_check_filetype_and_ext علاوه بر پسوند، وقتی پسوند با فهرست
		// مجاز وردپرس نخواند `ext => false` می‌دهد و وقتی نام فایل پسوند
		// تودرتو دارد (مثلاً shell.php.jpg) مقدار proper_filename را پر
		// می‌کند. این لایه مستقل از finfo است، پس اگر finfo روی هاستی
		// نصب نبود هم یک محافظ باقی می‌ماند.
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$wp_check = wp_check_filetype_and_ext( $tmp, $name );
			if ( is_array( $wp_check ) && array_key_exists( 'ext', $wp_check ) && false === $wp_check['ext'] ) {
				return new WP_Error(
					'pasokhban_badext',
					__( 'پسوند فایل با نوع واقعی‌اش نمی‌خواند.', 'pasokhban' ),
					array( 'status' => 415 )
				);
			}
			if ( is_array( $wp_check ) && ! empty( $wp_check['proper_filename'] )
				&& (string) $wp_check['proper_filename'] !== (string) $name ) {
				return new WP_Error(
					'pasokhban_badname',
					__( 'نام فایل مشکوک است (پسوند تودرتو).', 'pasokhban' ),
					array( 'status' => 415 )
				);
			}
		}

		$mime = self::detect_mime( $tmp );

		// اگر هیچ ابزار تشخیص MIME در دسترس نبود، فایل را رد می‌کنیم.
		// پذیرفتن فایل ناشناخته به‌خاطر یک extension ناقص، ریسکش بیشتر
		// از دردسر کاربر است.
		if ( '' === $mime ) {
			return new WP_Error(
				'pasokhban_nomime',
				__( 'نوع فایل قابل تشخیص نبود، پس پذیرفته نشد.', 'pasokhban' ),
				array( 'status' => 415 )
			);
		}

		// image/jpg یک نام‌گذاری غیررسمی ولی رایج است
		$accept = self::$allowed[ $ext ];
		if ( ! in_array( $mime, $accept, true ) ) {
			return new WP_Error(
				'pasokhban_mismatch',
				__( 'محتوای فایل با پسوندش نمی‌خواند. اگر فایل سالم است، دوباره ذخیره‌اش کنید.', 'pasokhban' ),
				array( 'status' => 415 )
			);
		}

		// برای تصویر، یک تأییدیهٔ اضافه: واقعاً باید تصویر باز شدنی باشد.
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$dim = @getimagesize( $tmp );
			if ( ! is_array( $dim ) || empty( $dim[0] ) || empty( $dim[1] ) ) {
				return new WP_Error(
					'pasokhban_badimage',
					__( 'فایل تصویر معتبر نیست.', 'pasokhban' ),
					array( 'status' => 415 )
				);
			}
		}

		return array( 'ext' => $ext, 'mime' => $mime );
	}

	/**
	 * تشخیص MIME واقعی محتوا.
	 *
	 * @param string $path
	 * @return string رشتهٔ خالی یعنی «نتوانستم تشخیص بدهم»
	 */
	public static function detect_mime( $path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = @finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = @finfo_file( $finfo, $path );
				finfo_close( $finfo );
				if ( is_string( $mime ) && '' !== $mime ) {
					return strtolower( trim( $mime ) );
				}
			}
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$mime = @mime_content_type( $path );
			if ( is_string( $mime ) && '' !== $mime ) {
				return strtolower( trim( $mime ) );
			}
		}

		// آخرین راه برای تصویرها
		$dim = @getimagesize( $path );
		if ( is_array( $dim ) && ! empty( $dim['mime'] ) ) {
			return strtolower( (string) $dim['mime'] );
		}

		return '';
	}

	/**
	 * پسوندهای مجاز از تنظیمات، ولی فقط آن‌هایی که در فهرست سفید کد هستند.
	 *
	 * چرا این intersect لازم است: اگر مدیر بتواند با تایپ «php» در فیلد
	 * تنظیمات، PHP را مجاز کند، کل محافظت بی‌معنی می‌شود.
	 *
	 * @param array $opts
	 * @return array<int, string>
	 */
	public static function allowed_exts( $opts ) {
		$raw = isset( $opts['live_upload_types'] ) ? (string) $opts['live_upload_types'] : '';
		$list = array_values( array_filter( array_map( function ( $e ) {
			return strtolower( trim( (string) $e, " \t\n\r\0\x0B." ) );
		}, explode( ',', $raw ) ) ) );

		$out = array();
		foreach ( $list as $e ) {
			if ( isset( self::$allowed[ $e ] ) && ! in_array( $e, $out, true ) ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * نام فایل برای نمایش — بدون مسیر، بدون کاراکتر خطرناک.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function safe_name( $name ) {
		$name = (string) $name;
		// فقط بخش آخر مسیر (جلوی ../../ و C:\fakepath\)
		$name = preg_replace( '#^.*[\\\\/]#', '', $name );
		$name = str_replace( array( "\0", '<', '>', '"', "'", '&', "\n", "\r" ), '', $name );
		$name = trim( (string) $name );
		if ( '' === $name ) {
			$name = 'file';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 );
	}

	/* =========================================================
	 * توکن‌ها
	 * =======================================================*/

	/**
	 * ثبت ضمیمهٔ آپلودشده و دادن توکن به کلاینت.
	 *
	 * @param int   $session_id
	 * @param array $meta
	 * @return string
	 */
	public static function issue_token( $session_id, array $meta ) {
		$token = wp_generate_password( 20, false, false );
		$key   = 'pasokhban_upl_' . (int) $session_id;
		$pending = get_transient( $key );
		$pending = is_array( $pending ) ? $pending : array();

		$pending[ $token ] = $meta;

		// قدیمی‌ترین‌ها را دور بریز تا transient بی‌نهایت بزرگ نشود
		if ( count( $pending ) > self::MAX_PENDING ) {
			$pending = array_slice( $pending, -self::MAX_PENDING, null, true );
		}

		set_transient( $key, $pending, self::TOKEN_TTL );
		return $token;
	}

	/**
	 * تبدیل توکن‌های کلاینت به متادیتای واقعی ضمیمه.
	 *
	 * توکن‌های ناشناخته بی‌صدا حذف می‌شوند: کاربر نباید بتواند URL
	 * دلخواه را به‌عنوان ضمیمه جا بزند.
	 *
	 * @param int   $session_id
	 * @param array $tokens
	 * @return array
	 */
	public static function resolve_tokens( $session_id, array $tokens ) {
		$tokens = array_slice( array_values( array_filter( array_map( 'strval', $tokens ) ) ), 0, self::MAX_PER_MESSAGE );
		if ( empty( $tokens ) ) {
			return array();
		}

		$key     = 'pasokhban_upl_' . (int) $session_id;
		$pending = get_transient( $key );
		$pending = is_array( $pending ) ? $pending : array();

		$out  = array();
		$used = array();

		foreach ( $tokens as $t ) {
			if ( isset( $pending[ $t ] ) && is_array( $pending[ $t ] ) && ! isset( $used[ $t ] ) ) {
				$used[ $t ] = true;
				$out[]      = $pending[ $t ];
				unset( $pending[ $t ] );   // یک توکن فقط یک‌بار مصرف می‌شود
			}
		}

		set_transient( $key, $pending, self::TOKEN_TTL );
		return $out;
	}

	/**
	 * توضیح ضمیمه‌ها برای مدل زبانی.
	 *
	 * چرا این لازم است: مدل متن پیام را می‌بیند ولی فایل را نه. اگر
	 * کاربر فقط یک اسکرین‌شات بفرستد و پیامش خالی باشد، مدل فکر می‌کند
	 * پیام بی‌محتواست. مهم‌تر اینکه باید صراحتاً بداند تصویر را
	 * نمی‌بیند، وگرنه شروع می‌کند به توصیف خیالی محتوای آن.
	 *
	 * @param object|array $message پیام hydrate‌شده
	 * @return string
	 */
	public static function describe( $message ) {
		$meta = null;
		if ( is_object( $message ) && isset( $message->meta ) ) {
			$meta = $message->meta;
		} elseif ( is_array( $message ) && isset( $message['meta'] ) ) {
			$meta = $message['meta'];
		}

		if ( ! is_array( $meta ) || empty( $meta['attachments'] ) || ! is_array( $meta['attachments'] ) ) {
			return '';
		}

		$names = array();
		foreach ( $meta['attachments'] as $a ) {
			if ( is_array( $a ) && ! empty( $a['name'] ) ) {
				$names[] = sanitize_text_field( (string) $a['name'] );
			}
		}
		if ( empty( $names ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: file names */
			"\n\n(کاربر این فایل‌ها را هم ارسال کرده است: %s. توجه: تو محتوای این فایل‌ها را نمی‌بینی؛ هرگز حدس نزن داخلشان چیست. اگر برای پاسخ به محتوایشان نیاز داری، از کاربر بخواه توضیح دهد.)",
			implode( '، ', $names )
		);
	}

	/* =========================================================
	 * پاک‌سازی
	 * =======================================================*/

	/**
	 * حذف فایل‌های یک یا چند نشست.
	 *
	 * @param array $session_ids
	 * @return int تعداد فایل پاک‌شده
	 */
	public static function purge_sessions( array $session_ids ) {
		global $wpdb;

		$session_ids = array_values( array_filter( array_map( 'intval', $session_ids ) ) );
		if ( empty( $session_ids ) ) {
			return 0;
		}

		$table = Pasokhban_DB::messages_table();
		$ph    = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT meta FROM {$table} WHERE session_id IN ({$ph}) AND meta LIKE '%attachments%'",
			$session_ids
		) );

		$n = 0;
		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) $row->meta, true );
			if ( ! is_array( $meta ) || empty( $meta['attachments'] ) || ! is_array( $meta['attachments'] ) ) {
				continue;
			}
			foreach ( $meta['attachments'] as $att ) {
				if ( self::delete_file( isset( $att['file'] ) ? $att['file'] : '' ) ) {
					$n++;
				}
			}
		}
		return $n;
	}

	/**
	 * حذف یک فایل از پوشهٔ آپلود — فقط اگر واقعاً داخل همان پوشه باشد.
	 *
	 * @param string $filename
	 * @return bool
	 */
	public static function delete_file( $filename ) {
		$filename = (string) $filename;
		if ( '' === $filename || false !== strpos( $filename, '/' ) || false !== strpos( $filename, '\\' ) || false !== strpos( $filename, '..' ) ) {
			return false;
		}
		$path = self::dir() . '/' . $filename;
		if ( is_file( $path ) ) {
			return @unlink( $path );
		}
		return false;
	}
}
