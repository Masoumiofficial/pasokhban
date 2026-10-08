<?php
/**
 * آگاهی از ووکامرس — پاسخ‌بان.
 *
 * دو کار می‌کند:
 * ۱) محصولات را با دادهٔ ساخت‌یافته (قیمت، موجودی، SKU، دسته، ویژگی‌ها)
 *    وارد ایندکس RAG می‌کند تا دستیار بتواند به سؤال محصول جواب بدهد.
 * ۲) وضعیت سفارش را به بازدیدکننده نشان می‌دهد — ولی فقط بعد از تأیید هویت.
 *
 * ── چرا بخش سفارش حساس است ──
 * شمارهٔ سفارش در ووکامرس یک عدد ترتیبی ساده است (۱۰۰۱، ۱۰۰۲، …). اگر
 * بدون تأیید هویت وضعیت را برمی‌گرداندیم، هر کسی می‌توانست با یک حلقه
 * ساده سفارش‌های همهٔ مشتری‌ها را بخواند. پس:
 *
 *   • تأیید هویت اجباری است: ایمیل یا شمارهٔ تماس ثبت‌شده روی همان سفارش.
 *   • مقایسه با hash_equals انجام می‌شود (مقاوم در برابر timing attack).
 *   • تلاش‌های ناموفق شمرده می‌شوند؛ بعد از چند بار، همان IP یک ساعت بسته می‌شود.
 *   • خروجی عمداً «سانسور» شده است: آدرس کامل، ایمیل، شمارهٔ تماس، IP و
 *     اطلاعات پرداخت هرگز برنمی‌گردند.
 *   • سقف تلاش مستقل از تنظیم rate_limit پلاگین است، چون آن تنظیم ممکن
 *     است روی صفر (بدون محدودیت) باشد.
 *
 * اگر ووکامرس نصب نباشد، همه‌چیز بی‌صدا غیرفعال می‌شود.
 *
 * @package Pasokhban
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس ووکامرس.
 */
final class Pasokhban_Woo {

	/** سقف تلاش ناموفق تأیید هویت در ساعت، به ازای هر IP. */
	const MAX_FAILED = 6;

	/** مدت بستن بعد از رد شدن از سقف (ثانیه). */
	const LOCKOUT = 3600;

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'pasokhban_rag_sources', array( __CLASS__, 'add_product_sources' ), 10, 2 );
	}

	public static function register_routes() {
		register_rest_route( 'pasokhban/v1', '/live/order', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_order' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'pasokhban/v1', '/admin/order', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_admin_order' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
	}

	public static function admin_permission() {
		return Pasokhban_Team::can_access();
	}

	/**
	 * آیا ووکامرس فعال و قابل استفاده است؟
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' )
			&& function_exists( 'wc_get_order' )
			&& function_exists( 'wc_get_product' );
	}

	/**
	 * آیا یک قابلیت خاص روشن است؟
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function enabled( $key ) {
		if ( ! self::active() ) {
			return false;
		}
		$opts = Pasokhban_Settings::instance()->get_options();
		return ! empty( $opts[ $key ] );
	}

	/* =========================================================
	 * ۱) محصولات در ایندکس RAG
	 * =======================================================*/

	/**
	 * افزودن محصولات به فهرست منابع RAG.
	 *
	 * چرا جدا از rag_post_types: توضیح یک محصول به‌تنهایی اطلاعات کافی
	 * نمی‌دهد. مشتری می‌پرسد «این موجود است؟» یا «قیمتش چقدر است؟» و
	 * جواب در post_content نیست، در متادیتاست. پس قیمت، موجودی، SKU،
	 * دسته و ویژگی‌ها را صریح به متن تکه اضافه می‌کنیم.
	 *
	 * @param array $sources
	 * @param array $opts
	 * @return array
	 */
	public static function add_product_sources( $sources, $opts = array() ) {
		if ( ! self::enabled( 'woo_products' ) ) {
			return $sources;
		}

		$sig    = Pasokhban_RAG::instance()->signature();
		$limit  = apply_filters( 'pasokhban_woo_product_limit', 800 );
		$added  = 0;

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$posts = get_posts( $args );

		foreach ( (array) $posts as $post ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
			if ( ! $product ) {
				continue;
			}

			// نوع‌های متغیر بدون قیمت مشخص، اطلاعات گیج‌کننده می‌دهند
			$body = self::product_body( $product );
			if ( '' === trim( $body ) ) {
				continue;
			}

			$id = (string) (int) $post->ID;

			$sources[] = array(
				'type'  => 'product',
				'id'    => $id,
				'title' => self::product_title( $product ),
				'url'   => get_permalink( $post ),
				'body'  => $body,
				// hash شامل دادهٔ ساخت‌یافته هم هست: با عوض‌شدن قیمت یا
				// موجودی، تکه دوباره ایندکس می‌شود.
				'hash'  => md5( $sig . '|product|' . $id . '|' . $body ),
			);
			$added++;
		}

		return $sources;
	}

	/**
	 * @param object $product
	 * @return string
	 */
	public static function product_title( $product ) {
		$t = method_exists( $product, 'get_name' ) ? $product->get_name() : '';
		return wp_strip_all_tags( (string) $t );
	}

	/**
	 * متن تکهٔ یک محصول — شامل دادهٔ ساخت‌یافته.
	 *
	 * @param object $product
	 * @return string
	 */
	public static function product_body( $product ) {
		$lines = array();

		$name = self::product_title( $product );
		if ( '' !== $name ) {
			$lines[] = 'محصول: ' . $name;
		}

		// قیمت
		$price = method_exists( $product, 'get_price_html' ) ? $product->get_price_html() : '';
		$price = wp_strip_all_tags( (string) $price );
		if ( '' !== trim( $price ) ) {
			$lines[] = 'قیمت: ' . trim( $price );
		}

		// موجودی
		if ( method_exists( $product, 'is_in_stock' ) ) {
			$stock = $product->is_in_stock()
				? ( method_exists( $product, 'get_stock_quantity' ) && null !== $product->get_stock_quantity()
					? 'موجود (' . (int) $product->get_stock_quantity() . ' عدد)'
					: 'موجود' )
				: 'ناموجود';
			$lines[] = 'موجودی: ' . $stock;
		}

		// SKU
		if ( method_exists( $product, 'get_sku' ) ) {
			$sku = (string) $product->get_sku();
			if ( '' !== trim( $sku ) ) {
				$lines[] = 'کد محصول (SKU): ' . trim( $sku );
			}
		}

		// دسته‌بندی‌ها
		if ( method_exists( $product, 'get_category_ids' ) ) {
			$names = array();
			foreach ( (array) $product->get_category_ids() as $tid ) {
				$term = get_term( $tid, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) && ! empty( $term->name ) ) {
					$names[] = $term->name;
				}
			}
			if ( $names ) {
				$lines[] = 'دسته‌بندی: ' . implode( '، ', array_slice( $names, 0, 5 ) );
			}
		}

		// ویژگی‌ها (رنگ، اندازه، …)
		if ( method_exists( $product, 'get_attributes' ) ) {
			$atts = $product->get_attributes();
			if ( is_array( $atts ) && $atts ) {
				$parts = array();
				foreach ( $atts as $attr ) {
					$att_name = '';
					$att_vals = array();

					if ( is_object( $attr ) && method_exists( $attr, 'get_name' ) ) {
						$att_name = $attr->get_name();
						$opts_raw = method_exists( $attr, 'get_options' ) ? (array) $attr->get_options() : array();
						foreach ( $opts_raw as $o ) {
							$term = is_numeric( $o ) ? get_term( (int) $o ) : null;
							$att_vals[] = ( $term && ! is_wp_error( $term ) ) ? $term->name : (string) $o;
						}
					} elseif ( is_array( $attr ) ) {
						$att_name = isset( $attr['name'] ) ? (string) $attr['name'] : '';
						$att_vals = isset( $attr['options'] ) ? (array) $attr['options'] : array();
					}

					if ( '' !== $att_name && $att_vals ) {
						$parts[] = $att_name . ': ' . implode( '، ', array_slice( $att_vals, 0, 8 ) );
					}
				}
				if ( $parts ) {
					$lines[] = 'گزینه‌ها: ' . implode( ' | ', array_slice( $parts, 0, 6 ) );
				}
			}
		}

		// توضیح کوتاه اول، بعد توضیح کامل — چون توضیح کوتاه معمولاً
		// همان چیزی است که مشتری می‌خواهد.
		$short = method_exists( $product, 'get_short_description' ) ? (string) $product->get_short_description() : '';
		$short = wp_strip_all_tags( strip_shortcodes( $short ) );
		if ( '' !== trim( $short ) ) {
			$lines[] = 'توضیح کوتاه: ' . trim( $short );
		}

		$desc = method_exists( $product, 'get_description' ) ? (string) $product->get_description() : '';
		$desc = wp_strip_all_tags( strip_shortcodes( $desc ) );
		if ( '' !== trim( $desc ) ) {
			$lines[] = 'توضیحات: ' . trim( $desc );
		}

		return implode( "\n", $lines );
	}

	/* =========================================================
	 * ۲) وضعیت سفارش — بازدیدکننده
	 * =======================================================*/

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_order( $request ) {
		if ( ! self::enabled( 'woo_order_lookup' ) ) {
			return new WP_Error(
				'pasokhban_woo_off',
				__( 'استعلام سفارش غیرفعال است.', 'pasokhban' ),
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

		// ── قفل ضد شمارش ──
		// مستقل از تنظیم rate_limit پلاگین است، چون آن تنظیم ممکن است
		// روی صفر (بدون محدودیت) باشد و اینجا محدودیت حیاتی است.
		//
		// دو شمارندهٔ جدا نگه می‌داریم:
		//   • به ازای IP   — جلوی کسی که نشست‌های تازه می‌سازد را می‌گیرد
		//   • به ازای نشست — همیشه هست، حتی وقتی IP در دسترس نیست
		//
		// چرا هر دو: client_ip() وقتی هیچ هدر IP در دسترس نباشد رشتهٔ
		// خالی برمی‌گرداند. اگر فقط یک شمارندهٔ IP داشتیم، در آن حالت
		// همهٔ بازدیدکننده‌های سایت یک شمارندهٔ مشترک داشتند و ۶ تلاش
		// ناموفقِ یک نفر، استعلام سفارش را برای کل سایت یک ساعت می‌بست.
		$ip       = (string) Pasokhban_DB::client_ip();
		$ip_key   = ( '' !== $ip ) ? 'pasokhban_woo_lock_i_' . md5( $ip ) : '';
		$sess_key = 'pasokhban_woo_lock_s_' . (int) $session->id;

		$failed_ip   = ( '' !== $ip_key ) ? (int) get_transient( $ip_key ) : 0;
		$failed_sess = (int) get_transient( $sess_key );

		if ( $failed_ip >= self::MAX_FAILED || $failed_sess >= self::MAX_FAILED ) {
			return new WP_Error(
				'pasokhban_locked',
				__( 'تلاش‌های ناموفق زیاد بود. یک ساعت دیگر دوباره امتحان کن یا با پشتیبانی تماس بگیر.', 'pasokhban' ),
				array( 'status' => 429 )
			);
		}

		$order_id = self::parse_order_id( $request->get_param( 'order_id' ) );
		$verify   = trim( (string) $request->get_param( 'verify' ) );

		if ( $order_id < 1 ) {
			return new WP_Error(
				'pasokhban_badorder',
				__( 'شمارهٔ سفارش درست نیست.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $verify ) {
			return new WP_Error(
				'pasokhban_needverify',
				__( 'برای دیدن وضعیت سفارش، ایمیل یا شمارهٔ تماسی که موقع خرید وارد کردی لازم است.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		// ★ ترتیب مهم: پیام خطا برای «سفارش پیدا نشد» و «تأیید هویت نشد»
		// یکی است. وگرنه مهاجم با شمارش خطاها می‌فهمید کدام شمارهٔ سفارش
		// وجود دارد و بعد فقط روی همان‌ها تمرکز می‌کرد.
		if ( ! $order || ! self::verify_order( $order, $verify ) ) {
			if ( '' !== $ip_key ) {
				set_transient( $ip_key, $failed_ip + 1, self::LOCKOUT );
			}
			set_transient( $sess_key, $failed_sess + 1, self::LOCKOUT );

			return new WP_Error(
				'pasokhban_mismatch',
				__( 'سفارشی با این شماره و مشخصات پیدا نشد. شمارهٔ سفارش و ایمیل یا شمارهٔ تماسی را بزن که موقع خرید وارد کرده بودی.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		// موفق — هر دو شمارنده صفر می‌شوند
		if ( '' !== $ip_key ) {
			delete_transient( $ip_key );
		}
		delete_transient( $sess_key );

		$summary = self::safe_order_summary( $order );

		// در مکالمه هم ثبت می‌شود تا اپراتور و هوش مصنوعی ببینند مشتری
		// چه چیزی را دیده است. بدون این، اپراتور نمی‌دانست مشتری چه می‌بیند.
		Pasokhban_DB::add_message(
			(int) $session->id,
			'system',
			sprintf(
				/* translators: 1: order number, 2: status */
				__( 'مشتری وضعیت سفارش #%1$s را دید — وضعیت: %2$s', 'pasokhban' ),
				$summary['number'],
				$summary['status']
			),
			array( 'note' => 'order', 'order' => $summary )
		);

		return rest_ensure_response( array( 'ok' => true, 'order' => $summary ) );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_admin_order( $request ) {
		if ( ! self::active() ) {
			return new WP_Error(
				'pasokhban_woo_off',
				__( 'ووکامرس فعال نیست.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		$order_id = self::parse_order_id( $request->get_param( 'order_id' ) );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return new WP_Error(
				'pasokhban_notfound',
				__( 'سفارش پیدا نشد.', 'pasokhban' ),
				array( 'status' => 404 )
			);
		}

		// مدیر تأیید هویت لازم ندارد، ولی باز هم اطلاعات پرداخت و آدرس
		// کامل را برنمی‌گردانیم — اینباکس جای آن نیست.
		return rest_ensure_response( array( 'ok' => true, 'order' => self::safe_order_summary( $order ) ) );
	}

	/* =========================================================
	 * ابزارهای سفارش
	 * =======================================================*/

	/**
	 * استخراج شمارهٔ سفارش از ورودی — حتی از متن کامل.
	 *
	 * کاربر ممکن است بنویسد «سفارش ۱۲۳۴» یا «#۱۲۳۴». ارقام فارسی هم
	 * باید پذیرفته شوند.
	 *
	 * @param mixed $raw
	 * @return int
	 */
	public static function parse_order_id( $raw ) {
		$s = (string) $raw;

		// ارقام فارسی/عربی → لاتین
		$s = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			$s
		);

		if ( preg_match( '/\d{1,10}/', $s, $m ) ) {
			return (int) $m[0];
		}
		return 0;
	}

	/**
	 * آخرین سفارش یک مشتری بر اساس شمارهٔ تماس یا ایمیل.
	 *
	 * چرا لازم است: وقتی مشتری در چت می‌پرسد «سفارشم کجاست؟»، اگر شمارهٔ
	 * تماسش را در فرم پیش‌گفتگو داده باشد، هوش مصنوعی باید بتواند بدون
	 * پرسیدن شمارهٔ سفارش جواب بدهد.
	 *
	 * فقط «آخرین» سفارش خوانده می‌شود، نه همه — وگرنه برای مشتری‌های قدیمی
	 * یک کوئری سنگین روی هر پیام اجرا می‌شد.
	 *
	 * @param string $phone
	 * @param string $email
	 * @return array|null خلاصهٔ امن سفارش، یا null
	 */
	public static function latest_order_for( $phone, $email ) {
		if ( ! self::active() || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$phone = self::normalize_phone( $phone );
		$email = strtolower( trim( (string) $email ) );

		if ( '' === $phone && '' === $email ) {
			return null;
		}

		$args = array(
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
		);

		if ( '' !== $phone ) {
			$args['billing_phone'] = $phone;
		} else {
			$args['billing_email'] = $email;
		}

		$orders = wc_get_orders( $args );
		if ( empty( $orders ) || ! is_array( $orders ) || ! isset( $orders[0] ) ) {
			return null;
		}

		return self::safe_order_summary( $orders[0] );
	}

	/**
	 * نرمال‌سازی شمارهٔ تلفن ایران برای مقایسه.
	 *
	 * ۰۹۱۲۱۲۳۴۵۶۷ / +۹۸۹۱۲… / ۹۸۹۱۲… / ۹۱۲۱۲۳۴۵۶۷ همه یکی‌اند.
	 *
	 * @param string $phone
	 * @return string فقط رقم، یا رشتهٔ خالی
	 */
	public static function normalize_phone( $phone ) {
		$s = (string) $phone;
		$s = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			$s
		);
		$s = preg_replace( '/\D+/', '', $s );
		if ( '' === $s ) {
			return '';
		}
		$s = preg_replace( '/^00/', '', $s );
		$s = preg_replace( '/^98/', '', $s );
		$s = preg_replace( '/^0/', '', $s );
		return $s;
	}

	/**
	 * تطبیق ایمیل با سفارش (case-insensitive و با مقایسهٔ زمان‌ثابت).
	 *
	 * @param string $email
	 * @param string $expected
	 * @return bool
	 */
	public static function match_email( $email, $expected ) {
		$a = strtolower( trim( (string) $email ) );
		$b = strtolower( trim( (string) $expected ) );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( ! function_exists( 'hash_equals' ) ) {
			return $a === $b;
		}
		return hash_equals( $b, $a );
	}

	/**
	 * تطبیق شمارهٔ تماس با سفارش.
	 *
	 * @param string $phone
	 * @param string $expected
	 * @return bool
	 */
	public static function match_phone( $phone, $expected ) {
		$a = self::normalize_phone( $phone );
		$b = self::normalize_phone( $expected );
		if ( '' === $a || '' === $b || strlen( $a ) < 7 ) {
			return false;
		}
		if ( ! function_exists( 'hash_equals' ) ) {
			return $a === $b;
		}
		return hash_equals( $b, $a );
	}

	/**
	 * تأیید هویت: ورودی باید با ایمیل یا شمارهٔ ثبت‌شده روی سفارش بخواند.
	 *
	 * @param object $order
	 * @param string $verify
	 * @return bool
	 */
	public static function verify_order( $order, $verify ) {
		$verify = trim( (string) $verify );
		if ( '' === $verify ) {
			return false;
		}

		$ok = false;

		// اگر ورودی شبیه ایمیل است، فقط با ایمیل مقایسه کن
		if ( false !== strpos( $verify, '@' ) ) {
			$billing = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
			$ok      = self::match_email( $verify, $billing );
		} else {
			$phone = method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';
			$ok    = self::match_phone( $verify, $phone );

			// بعضی فروشگاه‌ها شماره را در ایمیل ثبت می‌کنند یا برعکس
			if ( ! $ok ) {
				$billing = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
				$ok      = self::match_phone( $verify, $billing );
			}
		}

		return (bool) $ok;
	}

	/**
	 * خلاصهٔ امن سفارش — چیزی که به بازدیدکننده نشان داده می‌شود.
	 *
	 * عمداً شامل این‌ها نیست: آدرس کامل، ایمیل، شمارهٔ تماس، IP،
	 * اطلاعات پرداخت، یادداشت‌های داخلی فروشنده.
	 *
	 * @param object $order
	 * @return array
	 */
	public static function safe_order_summary( $order ) {
		$items = array();

		if ( method_exists( $order, 'get_items' ) ) {
			foreach ( (array) $order->get_items() as $item ) {
				$name = is_object( $item ) && method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
				$qty  = is_object( $item ) && method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
				if ( '' !== trim( $name ) ) {
					$items[] = array(
						'name' => wp_strip_all_tags( trim( $name ) ),
						'qty'  => max( 1, $qty ),
					);
				}
			}
		}

		$total = method_exists( $order, 'get_total' ) ? $order->get_total() : 0;

		$summary = array(
			'number' => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : '',
			'status' => self::status_label( $order ),
			'date'   => self::order_date( $order ),
			'total'  => self::money( $total ),
			'items'  => array_slice( $items, 0, 20 ),
		);

		$ship = method_exists( $order, 'get_shipping_method' ) ? (string) $order->get_shipping_method() : '';
		if ( '' !== trim( $ship ) ) {
			$summary['shipping'] = wp_strip_all_tags( trim( $ship ) );
		}

		// فقط شهر، نه آدرس کامل
		$city = method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '';
		if ( '' === trim( $city ) && method_exists( $order, 'get_billing_city' ) ) {
			$city = (string) $order->get_billing_city();
		}
		if ( '' !== trim( $city ) ) {
			$summary['city'] = wp_strip_all_tags( trim( $city ) );
		}

		return $summary;
	}

	/**
	 * برچسب خوانای وضعیت سفارش.
	 *
	 * @param object $order
	 * @return string
	 */
	public static function status_label( $order ) {
		if ( ! method_exists( $order, 'get_status' ) ) {
			return '';
		}
		$raw = (string) $order->get_status();

		// اول از ترجمه‌های خود ووکامرس استفاده کن
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$all = (array) wc_get_order_statuses();
			$key = 'wc-' . $raw;
			if ( isset( $all[ $key ] ) ) {
				return (string) $all[ $key ];
			}
		}

		$map = array(
			'pending'    => __( 'در انتظار پرداخت', 'pasokhban' ),
			'processing' => __( 'در حال پردازش', 'pasokhban' ),
			'on-hold'    => __( 'در انتظار بررسی', 'pasokhban' ),
			'completed'  => __( 'تکمیل شده', 'pasokhban' ),
			'cancelled'  => __( 'لغو شده', 'pasokhban' ),
			'refunded'   => __( 'مسترد شده', 'pasokhban' ),
			'failed'     => __( 'ناموفق', 'pasokhban' ),
		);

		return isset( $map[ $raw ] ) ? $map[ $raw ] : $raw;
	}

	/**
	 * @param object $order
	 * @return string
	 */
	private static function order_date( $order ) {
		if ( ! method_exists( $order, 'get_date_created' ) ) {
			return '';
		}
		$d = $order->get_date_created();
		if ( ! $d ) {
			return '';
		}
		$ts = is_object( $d ) && method_exists( $d, 'getTimestamp' ) ? (int) $d->getTimestamp() : (int) $d;
		// تاریخ سفارش همان چیزی است که مشتری در چت می‌بیند، پس باید
		// با تقویم خودش باشد نه میلادی.
		return $ts > 0 ? Pasokhban_Jalali::format( $ts, 'long' ) : '';
	}

	/**
	 * @param mixed $amount
	 * @return string
	 */
	public static function money( $amount ) {
		$n = (float) $amount;
		if ( function_exists( 'wc_price' ) ) {
			$html = wp_strip_all_tags( (string) wc_price( $n ) );
			if ( '' !== trim( $html ) ) {
				return trim( $html );
			}
		}
		return number_format_i18n( $n );
	}

	/**
	 * آخرین استعلام سفارش از میان پیام‌های یک مکالمه.
	 *
	 * @param array $messages پیام‌های hydrate‌شده
	 * @return array|null
	 */
	public static function latest_from_messages( array $messages ) {
		$found = null;
		foreach ( $messages as $m ) {
			$meta = is_object( $m ) && isset( $m->meta ) ? $m->meta : ( is_array( $m ) && isset( $m['meta'] ) ? $m['meta'] : array() );
			if ( ! is_array( $meta ) || empty( $meta['note'] ) || 'order' !== $meta['note'] ) {
				continue;
			}
			if ( ! empty( $meta['order'] ) && is_array( $meta['order'] ) && ! empty( $meta['order']['number'] ) ) {
				$found = $meta['order'];
			}
		}
		return $found;
	}

	/**
	 * متن آماده برای دادن به مدل — تا AI بتواند دربارهٔ سفارش حرف بزند.
	 *
	 * @param array $summary
	 * @return string
	 */
	public static function describe( array $summary ) {
		$lines = array(
			'شمارهٔ سفارش: ' . $summary['number'],
			'وضعیت: ' . $summary['status'],
		);
		if ( ! empty( $summary['date'] ) ) {
			$lines[] = 'تاریخ ثبت: ' . $summary['date'];
		}
		if ( ! empty( $summary['total'] ) ) {
			$lines[] = 'مبلغ کل: ' . $summary['total'];
		}
		if ( ! empty( $summary['shipping'] ) ) {
			$lines[] = 'روش ارسال: ' . $summary['shipping'];
		}
		if ( ! empty( $summary['city'] ) ) {
			$lines[] = 'شهر مقصد: ' . $summary['city'];
		}
		if ( ! empty( $summary['items'] ) ) {
			$parts = array();
			foreach ( $summary['items'] as $it ) {
				$parts[] = $it['name'] . ' ×' . $it['qty'];
			}
			$lines[] = 'اقلام: ' . implode( '، ', $parts );
		}

		return "\n\n(وضعیت سفارش مشتری که از ووکامرس خوانده شد:\n" . implode( "\n", $lines ) . "\nفقط بر اساس همین اطلاعات دربارهٔ سفارش حرف بزن و هیچ جزئیات دیگری از خودت نساز.)";
	}
}
