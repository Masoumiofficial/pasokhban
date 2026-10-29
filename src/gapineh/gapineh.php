<?php
/**
 * Plugin Name:       گپینه — چت هوشمند و پشتیبانی آنلاین
 * Plugin URI:        https://etehadwp.com/gapineh
 * Description:       سامانهٔ چت هوشمند و پشتیبانی آنلاین برای وردپرس. چت زندهٔ قابل تنظیم با ورود اپراتور انسانی و اینباکس پیشخوان، دستیار هوش مصنوعی با جست‌وجوی معنایی برداری (RAG)، پشتیبانی از ChatGPT و Gemini و Claude، آگاهی از سفارش‌های ووکامرس، ارسال فایل و تصویر، کانال بله و پیامک و تلگرام، تقویم شمسی، تیم چند اپراتوره و ساعت کاری.
 * Version:           1.0.0
 * Author:            اتحاد وردپرس
 * Author URI:        https://etehadwp.com
 * Text Domain:       gapineh
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * @package Gapineh
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // دسترسی مستقیم ممنوع
}

define( 'GAPINEH_VERSION', '1.0.0' );
// نسخهٔ دارایی‌ها جداست تا اصلاحات ۱.۰.۰ پشت کش نسخهٔ قبلی نمانند.
define( 'GAPINEH_ASSET_VERSION', '1.0.0.20261029' );
define( 'GAPINEH_FILE', __FILE__ );
define( 'GAPINEH_PATH', plugin_dir_path( __FILE__ ) );
define( 'GAPINEH_URL', plugin_dir_url( __FILE__ ) );

require_once GAPINEH_PATH . 'includes/class-settings.php';
require_once GAPINEH_PATH . 'includes/class-db.php';
require_once GAPINEH_PATH . 'includes/class-providers.php';
require_once GAPINEH_PATH . 'includes/class-api.php';
require_once GAPINEH_PATH . 'includes/class-rag.php';
require_once GAPINEH_PATH . 'includes/class-upload.php';
require_once GAPINEH_PATH . 'includes/class-copilot.php';
require_once GAPINEH_PATH . 'includes/class-woo.php';
require_once GAPINEH_PATH . 'includes/class-team.php';
require_once GAPINEH_PATH . 'includes/class-hours.php';
require_once GAPINEH_PATH . 'includes/class-jalali.php';
require_once GAPINEH_PATH . 'includes/class-sms.php';
require_once GAPINEH_PATH . 'includes/class-bale.php';
require_once GAPINEH_PATH . 'includes/class-livechat.php';
require_once GAPINEH_PATH . 'includes/class-inbox.php';
require_once GAPINEH_PATH . 'includes/class-contacts.php';
require_once GAPINEH_PATH . 'includes/class-stream.php';
require_once GAPINEH_PATH . 'includes/class-notify.php';
require_once GAPINEH_PATH . 'includes/class-analytics.php';
require_once GAPINEH_PATH . 'includes/class-health.php';

/**
 * کلاس اصلی پلاگین.
 */
final class Gapineh {

	/** @var Gapineh|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ) );
		add_shortcode( 'gapineh', array( $this, 'render_shortcode' ) );

		register_activation_hook( GAPINEH_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( GAPINEH_FILE, array( __CLASS__, 'deactivate' ) );
		add_action( 'gapineh_daily_cleanup', array( __CLASS__, 'run_cleanup' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_enqueue_scripts', array( 'Gapineh_Inbox', 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( 'Gapineh_Contacts', 'assets' ) );
		Gapineh_Inbox::hooks();
		Gapineh_Contacts::hooks();
		Gapineh_Notify::hooks();
		Gapineh_Analytics::hooks();
		Gapineh_Upload::hooks();
		Gapineh_Copilot::hooks();
		Gapineh_Woo::hooks();
		Gapineh_Team::hooks();
		Gapineh_SMS::hooks();
		Gapineh_Bale::hooks();
		Gapineh_Providers::hooks();
		Gapineh_Health::hooks();
		add_action( 'admin_init', array( 'Gapineh_RAG', 'bootstrap' ), 5 );

		Gapineh_Settings::instance();
		Gapineh_API::instance();
		Gapineh_RAG::instance();
		Gapineh_LiveChat::instance();
		Gapineh_Stream::instance();
	}

	/**
	 * هنگام فعال‌سازی پلاگین.
	 */
	public static function activate() {
		// اول مهاجرت از نام قدیمی، بعد ساخت جدول‌ها
		Gapineh_DB::migrate_from_etehadyar();
		Gapineh_DB::migrate_from_brand();
		Gapineh_DB::install();
		Gapineh_Team::install();
		Gapineh_DB::schedule_cleanup();
		Gapineh_RAG::instance()->maybe_schedule();

		// فقط برای نصب تازه (نه آپدیت)، سبک پیش‌فرض را روی چت شیشه‌ای بگذار.
		// اگر تنظیمات از قبل وجود دارد، دست نزنیم.
		$opts = get_option( 'gapineh_options', false );
		if ( false === $opts ) {
			// نصب تازه - پیش‌فرض‌ها را ست کن
			// توجه: defaults() متد نمونه است؛ فراخوانی استاتیک آن در PHP ۷ و ۸ خطای
			// «Non-static method cannot be called statically» می‌دهد و همین، فعال‌سازی
			// نصب تازه را می‌کشت. پس از instance() می‌رویم.
			$seed = Gapineh_Settings::instance()->defaults();
			if ( ! update_option( 'gapineh_options', $seed ) ) {
				// فعال‌سازی را نمی‌شکنیم؛ فقط علت را در لاگ می‌گذاریم تا مشخص شود
				// پیش‌فرض‌ها نشسته‌اند (مثلاً کش شی یا دیتابیس read-only).
				error_log( 'Gapineh: نشاندن تنظیمات اولیه در فعال‌سازی ناموفق بود.' );
			}
		}
	}

	/**
	 * هنگام غیرفعال‌سازی.
	 */
	public static function deactivate() {
		Gapineh_DB::unschedule_cleanup();
		if ( wp_next_scheduled( Gapineh_RAG::CRON_HOOK ) ) {
			wp_unschedule_event( time(), Gapineh_RAG::CRON_HOOK );
		}
	}

	/**
	 * پاک‌سازی روزانهٔ مکالمات قدیمی.
	 */
	public static function run_cleanup() {
		$opts = Gapineh_Settings::instance()->get_options();
		if ( empty( $opts['retention_days'] ) ) {
			return;
		}
		Gapineh_DB::cleanup_old( (int) $opts['retention_days'] );
	}

	/**
	 * ساخت جداول در صورت نبود (نصب دستی/آپدیت).
	 * تنظیمات را دست نمی‌زند - فقط ساختار دیتابیس را چک/ساخت می‌کند.
	 */
	public static function maybe_upgrade() {
		Gapineh_DB::migrate_from_etehadyar();
		Gapineh_DB::migrate_from_brand();
		if ( ! Gapineh_DB::installed() ) {
			Gapineh_DB::install();
		}
		// نکته: این تابع فقط جداول را چک می‌کند. تنظیمات (gapineh_options) در آپدیت حفظ می‌شوند.
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'gapineh', false, dirname( plugin_basename( GAPINEH_FILE ) ) . '/languages' );
	}

	/**
	 * نشانی فایل استاتیک.
	 *
	 * در حالت عادی اگر نسخۀ فشرده (.min.js / .min.css) کنار فایل باشد، همان بارگذاری
	 * می‌شود؛ با SCRIPT_DEBUG=true نسخهٔ خوانا انتخاب می‌شود تا اشکال‌زدایی ممکن بماند.
	 * فایل‌های .min با tools/build-assets.sh ساخته می‌شوند (terser + csso).
	 *
	 * @param string $rel مسیر نسبی، مثلاً assets/js/gapineh-chat.js
	 * @return string
	 */
	public static function asset_url( $rel ) {
		$rel = ltrim( (string) $rel, '/' );
		if ( ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG ) {
			$dot = strrpos( $rel, '.' );
			if ( false !== $dot ) {
				$min = substr( $rel, 0, $dot ) . '.min' . substr( $rel, $dot );
				if ( file_exists( GAPINEH_PATH . $min ) ) {
					return GAPINEH_URL . $min;
				}
			}
		}
		return GAPINEH_URL . $rel;
	}

	/**
	 * بارگذاری CSS/JS در قسمت فرانت‌اند سایت.
	 */
	public function enqueue_assets() {
		$opts  = Gapineh_Settings::instance()->get_options();

		if ( empty( $opts['widget_enabled'] ) ) {
			return;
		}

		$chat_lang = ( isset( $opts['lang'] ) && 'en' === $opts['lang'] ) ? 'en' : 'fa';

		// فونت وزیرمتن — اختیاری. بعضی بازدیدکننده‌های ایرانی به CDN دسترسی
		// کند دارند، پس می‌شود خاموشش کرد تا فونت سیستم استفاده شود.
		$font_dep = array();
		if ( ! empty( $opts['cdn_font'] ) ) {
			wp_enqueue_style(
				'vazirmatn',
				'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css',
				array(),
				'33.003'
			);
			$font_dep = array( 'vazirmatn' );
		}

		wp_enqueue_style(
			'gapineh-chat',
			self::asset_url( 'assets/css/gapineh-chat.css' ),
			$font_dep,
			GAPINEH_ASSET_VERSION
		);

		wp_enqueue_script(
			'gapineh-chat',
			self::asset_url( 'assets/js/gapineh-chat.js' ),
			array(),
			GAPINEH_ASSET_VERSION,
			true,
			// اجرا با defer — DOM کامل ساخته شود و بعد ویجت (از وردپرس ۶.۳؛ نسخه‌های قدیمی این آرگومان را نادیده می‌گیرند)
			array( 'strategy' => 'defer' )
		);

		wp_localize_script(
			'gapineh-chat',
			'GAPINEH_CHAT',
			array(
				'restUrl' => esc_url_raw( rest_url( 'gapineh/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'lang'    => $chat_lang,
			)
		);

		// تنظیمات ظاهری را همان اول تزریق می‌کنیم تا پنل بدون چشمک رندر شود
		// (بدون این، عنوان/رنگ/دکمه‌ها تا رسیدن پاسخ /live/start خالی می‌ماندند).
		wp_add_inline_script(
			'gapineh-chat',
			'window.GAPINEH_CHAT_SETTINGS=' . wp_json_encode(
				Gapineh_LiveChat::instance()->public_settings( $chat_lang ),
				JSON_UNESCAPED_UNICODE
			) . ';',
			'before'
		);
	}

	/**
	 * رندر ویجت شناور در پایین سایت.
	 */
	public function render_widget() {
		$opts = Gapineh_Settings::instance()->get_options();
		if ( empty( $opts['widget_enabled'] ) ) {
			return;
		}

		// اگر شورت‌کد [gapineh] در همین صفحه هست، ویجت شناور را چاپ نکن
		// تا دو المان با یک id در DOM نداشته باشیم.
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( (string) $post->post_content, 'gapineh' ) ) {
				return;
			}
		}

		echo '<div id="gapineh-live" data-mode="widget" aria-live="polite"></div>';
	}

	/**
	 * شورت‌کد برای جاسازی داخلی صفحه: [gapineh]
	 *
	 * @param array $atts
	 * @return string
	 */
	public function render_shortcode( $atts = array() ) {
		return '<div id="gapineh-live" data-mode="inline" aria-live="polite"></div>';
	}
}

Gapineh::instance();
