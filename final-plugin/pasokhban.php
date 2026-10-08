<?php
/**
 * Plugin Name:       پاسخ‌بان — چت هوشمند و پشتیبانی آنلاین
 * Plugin URI:        https://etehadwp.com/pasokhban
 * Description:       سامانهٔ چت هوشمند و پشتیبانی آنلاین برای وردپرس. چت زندهٔ شیشه‌ای به سبک iOS با ورود اپراتور انسانی و اینباکس پیشخوان، دستیار هوش مصنوعی با جست‌وجوی معنایی برداری (RAG)، پشتیبانی از ChatGPT و Gemini و Claude، آگاهی از سفارش‌های ووکامرس، ارسال فایل و تصویر، کانال بله و پیامک و تلگرام، تقویم شمسی، تیم چند اپراتوره و ساعت کاری.
 * Version:           3.1.2
 * Author:            اتحاد وردپرس | سجاد معصومی
 * Author URI:        https://etehadwp.com
 * Text Domain:       pasokhban
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * @package Pasokhban
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // دسترسی مستقیم ممنوع
}

define( 'PASOKHBAN_VERSION', '3.1.0' );
define( 'PASOKHBAN_FILE', __FILE__ );
define( 'PASOKHBAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PASOKHBAN_URL', plugin_dir_url( __FILE__ ) );

require_once PASOKHBAN_PATH . 'includes/class-settings.php';
require_once PASOKHBAN_PATH . 'includes/class-db.php';
require_once PASOKHBAN_PATH . 'includes/class-providers.php';
require_once PASOKHBAN_PATH . 'includes/class-api.php';
require_once PASOKHBAN_PATH . 'includes/class-rag.php';
require_once PASOKHBAN_PATH . 'includes/class-upload.php';
require_once PASOKHBAN_PATH . 'includes/class-copilot.php';
require_once PASOKHBAN_PATH . 'includes/class-woo.php';
require_once PASOKHBAN_PATH . 'includes/class-team.php';
require_once PASOKHBAN_PATH . 'includes/class-hours.php';
require_once PASOKHBAN_PATH . 'includes/class-jalali.php';
require_once PASOKHBAN_PATH . 'includes/class-sms.php';
require_once PASOKHBAN_PATH . 'includes/class-bale.php';
require_once PASOKHBAN_PATH . 'includes/class-livechat.php';
require_once PASOKHBAN_PATH . 'includes/class-inbox.php';
require_once PASOKHBAN_PATH . 'includes/class-contacts.php';
require_once PASOKHBAN_PATH . 'includes/class-stream.php';
require_once PASOKHBAN_PATH . 'includes/class-notify.php';
require_once PASOKHBAN_PATH . 'includes/class-analytics.php';
require_once PASOKHBAN_PATH . 'includes/class-health.php';

/**
 * کلاس اصلی پلاگین.
 */
final class Pasokhban {

	/** @var Pasokhban|null */
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
		add_shortcode( 'pasokhban', array( $this, 'render_shortcode' ) );

		register_activation_hook( PASOKHBAN_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( PASOKHBAN_FILE, array( __CLASS__, 'deactivate' ) );
		add_action( 'pasokhban_daily_cleanup', array( __CLASS__, 'run_cleanup' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_enqueue_scripts', array( 'Pasokhban_Inbox', 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( 'Pasokhban_Contacts', 'assets' ) );
		Pasokhban_Inbox::hooks();
		Pasokhban_Contacts::hooks();
		Pasokhban_Notify::hooks();
		Pasokhban_Analytics::hooks();
		Pasokhban_Upload::hooks();
		Pasokhban_Copilot::hooks();
		Pasokhban_Woo::hooks();
		Pasokhban_Team::hooks();
		Pasokhban_SMS::hooks();
		Pasokhban_Bale::hooks();
		Pasokhban_Providers::hooks();
		Pasokhban_Health::hooks();
		add_action( 'admin_init', array( 'Pasokhban_RAG', 'bootstrap' ), 5 );

		Pasokhban_Settings::instance();
		Pasokhban_API::instance();
		Pasokhban_RAG::instance();
		Pasokhban_LiveChat::instance();
		Pasokhban_Stream::instance();
	}

	/**
	 * هنگام فعال‌سازی پلاگین.
	 */
	public static function activate() {
		// اول مهاجرت از نام قدیمی، بعد ساخت جدول‌ها
		Pasokhban_DB::migrate_from_etehadyar();
		Pasokhban_DB::install();
		Pasokhban_Team::install();
		Pasokhban_DB::schedule_cleanup();
		Pasokhban_RAG::instance()->maybe_schedule();

		// فقط برای نصب تازه، سبک پیش‌فرض را روی چت شیشه‌ای بگذار.
		$opts = get_option( 'pasokhban_options', array() );
		if ( is_array( $opts ) && empty( $opts['widget_style'] ) ) {
			$opts['widget_style'] = 'chat';
			update_option( 'pasokhban_options', $opts );
		}
	}

	/**
	 * هنگام غیرفعال‌سازی.
	 */
	public static function deactivate() {
		Pasokhban_DB::unschedule_cleanup();
		if ( wp_next_scheduled( Pasokhban_RAG::CRON_HOOK ) ) {
			wp_unschedule_event( time(), Pasokhban_RAG::CRON_HOOK );
		}
	}

	/**
	 * پاک‌سازی روزانهٔ مکالمات قدیمی.
	 */
	public static function run_cleanup() {
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( empty( $opts['retention_days'] ) ) {
			return;
		}
		Pasokhban_DB::cleanup_old( (int) $opts['retention_days'] );
	}

	/**
	 * ساخت جداول در صورت نبود (نصب دستی/آپدیت).
	 */
	public static function maybe_upgrade() {
		Pasokhban_DB::migrate_from_etehadyar();
		if ( ! Pasokhban_DB::installed() ) {
			Pasokhban_DB::install();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'pasokhban', false, dirname( plugin_basename( PASOKHBAN_FILE ) ) . '/languages' );
	}

	/**
	 * سبک فعال ویجت.
	 *
	 * @return string chat|brain
	 */
	public static function current_style() {
		$opts = Pasokhban_Settings::instance()->get_options();
		return 'brain' === $opts['widget_style'] ? 'brain' : 'chat';
	}

	/**
	 * بارگذاری CSS/JS در قسمت فرانت‌اند سایت.
	 */
	public function enqueue_assets() {
		$opts  = Pasokhban_Settings::instance()->get_options();
		$style = self::current_style();

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

		if ( 'chat' === $style ) {
			wp_enqueue_style(
				'pasokhban-chat',
				PASOKHBAN_URL . 'assets/css/pasokhban-chat.css',
				$font_dep,
				PASOKHBAN_VERSION
			);

			wp_enqueue_script(
				'pasokhban-chat',
				PASOKHBAN_URL . 'assets/js/pasokhban-chat.js',
				array(),
				PASOKHBAN_VERSION,
				true
			);

			wp_localize_script(
				'pasokhban-chat',
				'PASOKHBAN_CHAT',
				array(
					'restUrl' => esc_url_raw( rest_url( 'pasokhban/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'lang'    => $chat_lang,
				)
			);

			// تنظیمات ظاهری را همان اول تزریق می‌کنیم تا پنل بدون چشمک رندر شود
			// (بدون این، عنوان/رنگ/دکمه‌ها تا رسیدن پاسخ /live/start خالی می‌ماندند).
			wp_add_inline_script(
				'pasokhban-chat',
				'window.PASOKHBAN_CHAT_SETTINGS=' . wp_json_encode(
					Pasokhban_LiveChat::instance()->public_settings( $chat_lang ),
					JSON_UNESCAPED_UNICODE
				) . ';',
				'before'
			);

			return;
		}

		// ---- حالت مغز کیهانی ----
		wp_enqueue_style(
			'pasokhban',
			PASOKHBAN_URL . 'assets/css/pasokhban.css',
			$font_dep,
			PASOKHBAN_VERSION
		);

		wp_enqueue_script(
			'pasokhban',
			PASOKHBAN_URL . 'assets/js/pasokhban.js',
			array(),
			PASOKHBAN_VERSION,
			true
		);

		$config = array(
			'ajaxUrl'      => esc_url_raw( rest_url( 'pasokhban/v1/chat' ) ),
			'configUrl'    => esc_url_raw( rest_url( 'pasokhban/v1/config' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'lang'         => isset( $opts['lang'] ) && in_array( $opts['lang'], array( 'fa', 'en' ), true ) ? $opts['lang'] : 'fa',
			'greeting'     => $opts['greeting'],
			'placeholder'  => $opts['placeholder'],
			'launcherText' => $opts['launcher_text'],
			'ttsEnabled'   => (bool) $opts['tts'],
			'sttEnabled'   => (bool) $opts['stt'],
			'accent'       => $opts['accent'],
			'siteName'     => get_bloginfo( 'name' ),
			'brainLabel'   => __( 'پاسخ‌بان — مغز هوشمند', 'pasokhban' ),
			'brandUrl'     => 'https://etehadwp.com/pasokhban',
			'brandBy'      => 'محصول اتحاد وردپرس',
			'brandByUrl'   => 'https://etehadwp.com',
			'credit'       => 'مدیر پروژه: سجاد معصومی',
			'logoUrl'      => PASOKHBAN_URL . 'assets/img/etehad-logo.png',
			'logoNeonUrl'  => PASOKHBAN_URL . 'assets/img/etehad-logo-neon.png',
		);

		// نام متغیر سراسری در رنیم ۳.۰.۰ جا مانده بود: اسکریپت مغز کیهانی
		// window.ETEHADYAR را می‌خواند. هر دو نام تزریق می‌شوند تا هم برند
		// درست باشد و هم اگر کش مرورگر نسخهٔ قدیمی JS را نگه داشته، ویجت
		// از کار نیفتد.
		wp_localize_script( 'pasokhban', 'PASOKHBAN', $config );
		wp_localize_script( 'pasokhban', 'ETEHADYAR', $config );
	}

	/**
	 * رندر ویجت شناور در پایین سایت.
	 */
	public function render_widget() {
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( empty( $opts['widget_enabled'] ) ) {
			return;
		}

		// اگر شورت‌کد [pasokhban] در همین صفحه هست، ویجت شناور را چاپ نکن
		// تا دو المان با یک id در DOM نداشته باشیم.
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( (string) $post->post_content, 'pasokhban' ) ) {
				return;
			}
		}

		if ( 'chat' === self::current_style() ) {
			echo '<div id="pasokhban-live" data-mode="widget" aria-live="polite"></div>';
			return;
		}

		echo '<div id="pasokhban-root" data-mode="widget" aria-live="polite"></div>';
	}

	/**
	 * شورت‌کد برای جاسازی داخلی صفحه: [pasokhban]
	 *
	 * @param array $atts
	 * @return string
	 */
	public function render_shortcode( $atts = array() ) {
		if ( 'chat' === self::current_style() ) {
			return '<div id="pasokhban-live" data-mode="inline" aria-live="polite"></div>';
		}
		return '<div id="pasokhban-root" data-mode="inline" aria-live="polite"></div>';
	}
}

Pasokhban::instance();
