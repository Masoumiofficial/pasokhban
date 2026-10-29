<?php
/**
 * مدیریت تنظیمات پلاگین گپینه (منو و ذخیره گزینه‌ها).
 *
 * @package Gapineh
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس تنظیمات.
 */
final class Gapineh_Settings {

	/** @var string */
	private $option_key = 'gapineh_options';

	/** @var array */
	private $options = array();

	/** @var Gapineh_Settings|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->options = get_option( $this->option_key, array() );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_menu', array( $this, 'tidy_menu' ), 999 );
		add_action( 'admin_menu', array( $this, 'menu_bubble' ), 99 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_gapineh_export_settings', array( $this, 'export_settings' ) );
		add_action( 'admin_post_gapineh_import_settings', array( $this, 'import_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	/**
	 * مقادیر پیش‌فرض.
	 */
	public function defaults() {
		return array(
			'ai_provider'      => 'gapgpt',     // gapgpt | openai | gemini | anthropic | custom
			'api_key'          => '',
			'base_url'         => 'https://api.gapgpt.app/v1',
			'model'            => 'gpt-4o',
			'system_prompt'    => 'تو گپینه، دستیار هوشمند سایت هستی. به زبان فارسی، دوستانه و حرفه‌ای پاسخ بده. پاسخ‌ها را کوتاه، دقیق و منظم بنویس. اگر اطلاعاتی درباره سؤال کاربر نداشتی، صادقانه بگو.',
			'knowledge'        => 1,   // پاسخ بر اساس محتوای سایت
			'knowledge_posts'  => 5,   // تعداد پست برای زمینه
			'widget_enabled'   => 1,   // ویجت شناور
			'max_tokens'       => 800,
			'temperature'      => 0.7,
			'rate_limit'       => 10,  // درخواست در دقیقه به ازای هر IP
			'lang'             => 'fa', // زبان پیش‌فرض رابط
			'accent'           => '#285d50', // رنگ اصلی (سایان)

			// ---- ۱.۱.۰ : گفتگوی آنلاین ----
			'live_enabled'     => 1,             // فعال‌بودن حالت چت آنلاین
			'live_title'       => 'گفتگوی آنلاین',
			'live_agent_name'  => 'پشتیبانی',
			'live_greeting'    => 'سلام! 👋 چطور می‌تونیم کمکت کنیم؟',
			'live_placeholder' => 'پیامت را بنویس…',
			'live_position'    => 'start',       // start = سمت راست در RTL (چپ در LTR)
			'live_theme'       => 'auto',        // auto | light | dark
			'live_auto_open'   => 0,             // باز شدن خودکار
			'live_auto_delay'  => 10,            // ثانیه
			'live_starters'    => "راهنمایی می‌خواهم\nهزینه‌ها چقدر است؟\nگپ با پشتیبان",
			'live_human_enabled' => 1,           // امکان ورود اپراتور انسانی
			'live_history'     => 20,            // سقف پیام ارسالی به مدل
			'live_sound'       => 0,             // صدای پیام تازه (پیش‌فرض خاموش: autoplay در مرورگرها بلاک است)
			'live_offline_note' => '',           // پیام دلخواه وقتی اپراتور آفلاین است

			// ---- ۱.۲.۰ : ظاهر و جای‌گیری ----
			'live_offset_bottom' => 18,          // فاصله از پایین (px)
			'live_offset_side'   => 18,          // فاصله از کنار (px)
			'live_launcher_size' => 62,          // قطر حباب (px)
			'live_panel_width'   => 384,         // عرض پنل (px)
			'live_panel_height'  => 620,         // ارتفاع پنل (px)
			'live_radius'        => 12,          // گردی پنل (px)
			'live_blur'          => 0,          // شدت شیشه / blur (px)
			'live_glass_opacity' => 100,          // شفافیت پس‌زمینهٔ پنل (٪)
			'live_teaser'        => 1,           // برچسب کنار حباب
			'live_teaser_text'   => 'سؤالی داری؟ همین‌جا بپرس 👋',
			'live_launcher_label' => '',         // متن داخل حباب (خالی = فقط آیکون)
			'live_prechat'       => 1,           // فرم پیش از گفتگو
			'live_prechat_required' => 1,        // اجباری‌بودن فرم
			'live_prechat_note'  => 'برای شروع گفتگو، مشخصاتت را وارد کن تا بهتر کمکت کنیم.',
			'live_mobile_sheet'  => 1,           // ورق تمام‌صفحه در موبایل
			'live_maximize'      => 1,           // دکمهٔ بزرگ‌نمایی پنل
			'live_show_footer'   => 0,           // پابرگ برند (به‌صورت پیش‌فرض حذف‌شده)
			'cdn_font'           => 0,           // بارگذاری وزیرمتن از CDN — پیش‌فرض خاموش تا روی هاست ایران صفحۀ کاربر بلوکه نشود
			'retention_days'     => 365,         // ۰ = نگهداری همیشگی؛ پیش‌فرض یک سال

			// ---- ۱.۵.۰ : جریان، اعلان، رضایت، پاسخ آماده ----
			'streaming'          => 1,           // پاسخ جریان‌دار
			'csat'               => 1,           // دکمهٔ 👍/👎 زیر پاسخ
			'notify_email'       => 0,
			'notify_email_to'    => '',
			'notify_telegram'    => 0,
			'notify_telegram_token' => '',
			'notify_telegram_chat'  => '',
			'notify_telegram_proxy' => '',
			'notify_telegram_base'  => 'https://api.telegram.org',
			'notify_telegram_relay' => '',       // رلهٔ گوگل برای عبور از فیلترینگ
			'notify_telegram_secret' => '',      // کلید مشترک با اسکریپت گوگل
			'notify_min_gap'     => 5,           // دقیقه بین دو اعلان برای یک نشست
			'notify_only_offline' => 1,          // فقط وقتی اپراتور آنلاین نیست
			'canned'             => "سلام! خوش آمدید 👋\nچه کمکی از دستم برمی‌آید؟|سلام و خوش‌آمد\nبله، همکاران ما به‌زودی با شما تماس می‌گیرند.|اطلاع‌رسانی تماس\nمی‌توانید جزئیات بیشتر را در این صفحه ببینید:|ارجاع به صفحه",

			// ---- ۲.۶.۰ : کانال بله ----
			'bale_enabled'         => 0,
			'bale_token'           => '',
			'bale_chat'            => '',
			'bale_notify'          => 1,   // اعلان یک‌طرفه به گروه پشتیبانی
			'bale_twoway'          => 0,   // پاسخ دوطرفه از داخل بله
			'bale_webhook_secret'  => '',

			// ---- ۲.۴.۰ : ایرانی‌سازی ----
			'jalali_dates'       => 1,           // نمایش تاریخ شمسی
			'jalali_digits'      => 1,           // ارقام فارسی
			'locale_format'      => 'auto',      // auto | fa | en — قالب تاریخ و ارقام (auto: بر اساس زبان سایت)
			'sms_enabled'        => 0,
			'sms_provider'       => 'kavenegar',  // kavenegar | melipayamak | smsir
			'sms_api_key'        => '',
			'sms_sender'         => '',           // شمارهٔ فرستنده (line number)
			'sms_admin_phone'    => '',
			'sms_notify_admin'   => 0,
			'sms_notify_visitor' => 0,
			'sms_min_gap'        => 10,           // دقیقه بین دو پیامک برای یک نشست
			'brand_enabled'      => 0,
			'brand_name'         => '',
			'brand_url'          => '',
'brand_hide_credit'       => 1,            // پابرگ «گپینه / etehadwp.com/gapineh / اتحاد وردپرس» حذف شود
		'uninstall_delete_data'   => 0,            // حذف داده‌ها هنگام حذف پلاگین — پیش‌فرض خاموش تا حذف افزونه فاجعه نسازد (مکالمات، مخاطبین، ایندکس، آپلودها)

		// ---- ۲.۳.۰ : تیم و ساعت کاری ----
			'team_auto_assign'   => 1,           // واگذاری خودکار نوبتی
			'team_restrict'      => 0,           // اپراتور فقط مکالمات خودش را ببیند
			'hours_enabled'      => 0,           // ساعت کاری روشن
			'hours_days'         => '0,1,2,3,4,6', // همه جز جمعه (۵)
			'hours_from'         => '09:00',
			'hours_to'           => '18:00',
			'hours_ai_outside'   => 1,           // بیرون ساعت، AI هنوز پاسخ بدهد
			'hours_offline_msg'  => '',

			// ---- ۲.۲.۰ : ووکامرس ----
			'woo_products'       => 1,           // ایندکس محصولات در RAG
			'woo_order_lookup'   => 1,           // استعلام وضعیت سفارش توسط مشتری

			// ---- ۲.۱.۰ : دستیار اپراتور ----
			'copilot_enabled'    => 1,           // پیش‌نویس پاسخ با AI در اینباکس
			'copilot_tone'       => 'friendly',  // friendly | formal | short
			'copilot_max_msgs'   => 12,          // چند پیام آخر به مدل داده شود
			'copilot_summary'    => 1,           // دکمهٔ خلاصهٔ مکالمه

			// ---- ۲.۰.۰ : ارسال فایل در چت ----
			'live_upload'        => 1,           // دکمهٔ گیرهٔ کاغذ
			'live_upload_max'    => 5,           // مگابایت
			'live_upload_types'  => 'jpg,jpeg,png,gif,webp,pdf,txt',

			// ---- ۱.۹.۰ : جست‌وجوی معنایی (RAG برداری) ----
			'rag_vector'        => 0,                          // خاموش = رفتار نسخهٔ ۱.۸
			'rag_model'         => 'text-embedding-3-small',   // مدل embedding
			'rag_dims'          => 512,                        // ۰ = ابعاد پیش‌فرض مدل
			'rag_top_k'         => 4,                          // چند تکه به مدل داده شود
			'rag_min_score'     => 0.30,                       // آستانهٔ شباهت
			'rag_chunk_size'    => 1200,                       // کاراکتر
			'rag_chunk_overlap' => 200,                        // کاراکتر هم‌پوشانی
			'rag_post_types'    => 'post,page',
			'rag_auto_sync'     => 1,                          // کرون ساعتی برای نوشته‌های تازه
		);
	}

	/**
	 * برمی‌گرداند گزینه‌ها با مقادیر پیش‌فرض.
	 */
	public function get_options() {
		return wp_parse_args( $this->options, $this->defaults() );
	}

	/**
	 * hookname واقعی صفحه‌ها — باید از خروجی add_*_page گرفته شود.
	 *
	 * چرا hardcode نمی‌کنیم: وردپرس hookname را با
	 *   $admin_page_hooks[$parent_slug] = sanitize_title( $menu_title )
	 * می‌سازد. عنوان والدِ منو فارسی است و sanitize_title('گپینه') رشتهٔ خالی
	 * می‌دهد، پس hookname زیرمنوها '_page_gapineh-inbox' می‌شود و نه
	 * 'gapineh-inbox_page_gapineh-inbox'. برای همین hookها را از مقدار
	 * بازگشتی add_menu_page/add_submenu_page نگه می‌داریم و hardcode نمی‌کنیم.
	 *
	 * @var array
	 */
	public static $hooks = array();

	/**
	 * آیکن منوی پیشخوان: نشان برند به‌صورت SVG درون‌خطی.
	 *
	 * وردپرس برای آیکن غیر-dashicon یک تگ <img> می‌سازد؛ با base64 هیچ
	 * درخواست شبکه‌ای اضافه نمی‌شود و آیکن با تغییر رنگ قالب هم نمی‌شکند.
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Itqv2b7bjNmG2YfYjCDar9mB2Krar9mI24wg2KLZhtmE2KfbjNmGIj48dGl0bGU+2q/ZvtuM2YbZh9iMINqv2YHYqtqv2YjbjCDYotmG2YTYp9uM2YY8L3RpdGxlPjxyZWN0IHg9IjIiIHk9IjIiIHdpZHRoPSI2MCIgaGVpZ2h0PSI2MCIgcng9IjE2IiBmaWxsPSIjMjg1ZDUwIi8+PHBhdGggZD0iTTE1IDE5YTcgNyAwIDAgMSA3LTdoMjBhNyA3IDAgMCAxIDcgN3YxN2E3IDcgMCAwIDEtNyA3SDMxbC05IDh2LThhNyA3IDAgMCAxLTctN3oiIGZpbGw9IiNmZmYiLz48Y2lyY2xlIGN4PSIyMy41IiBjeT0iMjgiIHI9IjIuNyIgZmlsbD0iIzI4NWQ1MCIvPjxjaXJjbGUgY3g9IjMyIiBjeT0iMjgiIHI9IjIuNyIgZmlsbD0iIzI4NWQ1MCIvPjxjaXJjbGUgY3g9IjQwLjUiIGN5PSIyOCIgcj0iMi43IiBmaWxsPSIjMjg1ZDUwIi8+PC9zdmc+Cg==';


	/**
	 * ترتیب و تکمیل زیرمنوها.
	 *
	 * «گفتگوهای آنلاین» والدِ منو است؛ چون وردپرس برای صفحهٔ والد ردیف
	 * زیرمنو نمی‌سازد، ردیفش را همین‌جا (فقط برای نمایش) اضافه می‌کنیم تا
	 * فهرست از همان آیتم شروع شود و کلیک روی «گپینه» هم به آن برسد.
	 * ترتیب نهایی: گفتگو → مخاطبین → آمار → تنظیمات → راهنمای سریع.
	 */
	public function tidy_menu() {
		global $submenu;

		if ( empty( $submenu['gapineh-inbox'] ) || ! is_array( $submenu['gapineh-inbox'] ) ) {
			return;
		}

		$order  = array( 'gapineh-inbox', 'gapineh-contacts', 'gapineh-analytics', 'gapineh', 'gapineh-guide' );
		$labels = array( 'gapineh-inbox' => __( 'گفتگو آنلاین', 'gapineh' ) );

		// آخری برای هر اسلاگ می‌ماند (ثبت‌های خود ما دیرتر آمده‌اند).
		$by_slug = array();
		foreach ( $submenu['gapineh-inbox'] as $item ) {
			if ( isset( $item[2] ) ) {
				$by_slug[ $item[2] ] = $item;
			}
		}

		if ( ! isset( $by_slug['gapineh-inbox'] ) ) {
			$by_slug['gapineh-inbox'] = array(
				__( 'گفتگوهای آنلاین', 'gapineh' ),
				'manage_options',
				'gapineh-inbox',
				__( 'گفتگوهای آنلاین', 'gapineh' ),
			);
		}
		foreach ( $labels as $slug => $label ) {
			$by_slug[ $slug ][0] = $label;
		}

		$rank = array_flip( $order );
		$out  = array_values( $by_slug );
		usort(
			$out,
			function ( $a, $b ) use ( $rank ) {
				$ra = isset( $rank[ $a[2] ] ) ? $rank[ $a[2] ] : 99;
				$rb = isset( $rank[ $b[2] ] ) ? $rank[ $b[2] ] : 99;
				return $ra - $rb;
			}
		);

		$submenu['gapineh-inbox'] = $out; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	public function add_menu() {
		// ترتیب منو: گفتگو → مخاطبین → آمار → تنظیمات → راهنمای سریع.
		//
		// صفحهٔ «گفتگوهای آنلاین» والد منو است تا کلیک روی «گپینه» در نوار
		// پیشخوان هم به همان صفحه برسد، نه به تنظیمات. اسلاگ صفحهٔ تنظیمات
		// عمداً 'gapineh' مانده است: همهٔ لینک‌های عمیق موجود در کد
		// (admin.php?page=gapineh#notify و…) و لینک‌های ذخیره‌شدهٔ کاربران
		// دست‌نخورده کار می‌کنند.
		self::$hooks['inbox'] = add_menu_page(
			__( 'گپینه — گفتگوهای آنلاین', 'gapineh' ),
			__( 'گپینه', 'gapineh' ),
			'manage_options',
			'gapineh-inbox',
			array( 'Gapineh_Inbox', 'render' ),
			self::MENU_ICON,
			58
		);

		self::$hooks['contacts'] = add_submenu_page(
			'gapineh-inbox',
			__( 'مخاطبین', 'gapineh' ),
			__( 'مخاطبین', 'gapineh' ),
			'manage_options',
			'gapineh-contacts',
			array( 'Gapineh_Contacts', 'render' )
		);

		self::$hooks['analytics'] = add_submenu_page(
			'gapineh-inbox',
			__( 'آمار', 'gapineh' ),
			__( 'آمار', 'gapineh' ),
			'manage_options',
			'gapineh-analytics',
			array( 'Gapineh_Analytics', 'render' )
		);

		self::$hooks['settings'] = add_submenu_page(
			'gapineh-inbox',
			__( 'تنظیمات گپینه', 'gapineh' ),
			__( 'تنظیمات', 'gapineh' ),
			'manage_options',
			'gapineh',
			array( $this, 'render_page' )
		);

		self::$hooks['guide'] = add_submenu_page(
			'gapineh-inbox',
			__( 'راهنمای سریع گپینه', 'gapineh' ),
			__( 'راهنمای سریع', 'gapineh' ),
			'manage_options',
			'gapineh-guide',
			array( $this, 'render_guide' )
		);
	}

	/**
	 * آیا این hook مربوط به یکی از صفحه‌های ماست؟
	 *
	 * @param string $hook
	 * @param string $which settings|inbox|contacts
	 * @return bool
	 */
	public static function is_page( $hook, $which ) {
		if ( ! empty( self::$hooks[ $which ] ) && self::$hooks[ $which ] === $hook ) {
			return true;
		}
		// پشتیبان برای حالت‌هایی که hookname استاندارد باشد
		$fallback = array(
			// والدِ منو «گفتگوهای آنلاین» است و عنوانش فارسی؛ sanitize_title
			// آن رشتهٔ خالی می‌دهد، پس پیشوندِ hookname زیرمنوها '_page_' است.
			'settings'  => '_page_gapineh',
			'inbox'     => 'toplevel_page_gapineh-inbox',
			'contacts'  => '_page_gapineh-contacts',
			'analytics' => '_page_gapineh-analytics',
			'guide'     => '_page_gapineh-guide',
		);
		return isset( $fallback[ $which ] ) && $fallback[ $which ] === $hook;
	}

	/**
	 * شمار مکالمات خوانده‌نشده روی منوی ادمین.
	 */
	public function menu_bubble() {
		global $menu;

		if ( ! class_exists( 'Gapineh_DB' ) || ! Gapineh_DB::installed() ) {
			return;
		}

		$count = Gapineh_DB::unread_count();
		if ( $count < 1 ) {
			return;
		}

		$bubble = sprintf(
			' <span class="update-plugins gapineh-bubble"><span class="plugin-count">%s</span></span>',
			number_format_i18n( $count )
		);

		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && 'gapineh-inbox' === $item[2] ) {
				$menu[ $i ][0] .= $bubble; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				break;
			}
		}
	}

	public function register_settings() {
		register_setting( 'gapineh_group', $this->option_key, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize' ),
			'default'           => $this->defaults(),
		) );
	}

	/**
	 * پاک‌سازی ورودی.
	 */
	public function sanitize( $input ) {
		$d   = $this->defaults();
		$out = array();

		$out['api_key']       = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : $d['api_key'];
		// سرویس‌دهنده فقط از فهرست شناخته‌شده پذیرفته می‌شود
		$out['ai_provider']   = ( isset( $input['ai_provider'] ) && array_key_exists( (string) $input['ai_provider'], Gapineh_Providers::presets() ) )
			? (string) $input['ai_provider']
			: $d['ai_provider'];
		// اگر esc_url_raw اسکیم ناامن (javascript:، data: و…) را رد کند
		// رشتهٔ خالی می‌دهد. ذخیرهٔ رشتهٔ خالی یعنی همهٔ فراخوانی‌های API
		// می‌شکنند، پس در آن حالت به پیش‌فرض برمی‌گردیم — همان الگویی که
		// برای accent و notify_telegram_base هم به کار رفته.
		$out['base_url']      = $d['base_url'];
		if ( isset( $input['base_url'] ) ) {
			$bu = esc_url_raw( $input['base_url'] );
			if ( '' !== $bu ) {
				$out['base_url'] = $bu;
			}
		}
		$out['model']         = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $d['model'];
		$out['system_prompt'] = isset( $input['system_prompt'] ) ? sanitize_textarea_field( $input['system_prompt'] ) : $d['system_prompt'];
		$out['max_tokens']    = isset( $input['max_tokens'] ) ? max( 100, (int) $input['max_tokens'] ) : $d['max_tokens'];
		// cast صریح به float: بدون آن min(2, 9.0) عدد صحیح 2 برمی‌گرداند
		// و نوع مقدار ذخیره‌شده بین ورودی‌ها عوض می‌شد.
		$out['temperature']   = isset( $input['temperature'] ) ? (float) min( 2, max( 0, (float) $input['temperature'] ) ) : $d['temperature'];
		$out['rate_limit']    = isset( $input['rate_limit'] ) ? max( 0, (int) $input['rate_limit'] ) : $d['rate_limit'];
		$out['knowledge_posts'] = isset( $input['knowledge_posts'] ) ? max( 1, (int) $input['knowledge_posts'] ) : $d['knowledge_posts'];
		$out['lang']          = isset( $input['lang'] ) && in_array( $input['lang'], array( 'fa', 'en' ), true ) ? $input['lang'] : 'fa';
		$out['accent']        = isset( $input['accent'] ) ? sanitize_hex_color( $input['accent'] ) : $d['accent'];
		if ( ! $out['accent'] ) {
			$out['accent'] = $d['accent'];
		}

		// ---- گفتگوی آنلاین ----
		$out['live_title']       = isset( $input['live_title'] ) ? sanitize_text_field( $input['live_title'] ) : $d['live_title'];
		$out['live_agent_name']  = isset( $input['live_agent_name'] ) ? sanitize_text_field( $input['live_agent_name'] ) : $d['live_agent_name'];
		$out['live_greeting']    = isset( $input['live_greeting'] ) ? sanitize_text_field( $input['live_greeting'] ) : $d['live_greeting'];
		$out['live_placeholder'] = isset( $input['live_placeholder'] ) ? sanitize_text_field( $input['live_placeholder'] ) : $d['live_placeholder'];
		$out['live_offline_note'] = isset( $input['live_offline_note'] ) ? sanitize_text_field( $input['live_offline_note'] ) : '';
		$out['live_starters']    = isset( $input['live_starters'] ) ? sanitize_textarea_field( $input['live_starters'] ) : $d['live_starters'];
		$out['live_position']    = isset( $input['live_position'] ) && 'end' === $input['live_position'] ? 'end' : 'start';
		$out['live_theme']       = isset( $input['live_theme'] ) && in_array( $input['live_theme'], array( 'auto', 'light', 'dark' ), true ) ? $input['live_theme'] : 'auto';
		$out['live_auto_delay']  = isset( $input['live_auto_delay'] ) ? max( 0, min( 120, (int) $input['live_auto_delay'] ) ) : $d['live_auto_delay'];
		$out['live_history']     = isset( $input['live_history'] ) ? max( 4, min( 60, (int) $input['live_history'] ) ) : $d['live_history'];

		// ---- ظاهر و جای‌گیری (همه با محدودهٔ امن) ----
		$out['live_offset_bottom'] = isset( $input['live_offset_bottom'] ) ? max( 0, min( 200, (int) $input['live_offset_bottom'] ) ) : $d['live_offset_bottom'];
		$out['live_offset_side']   = isset( $input['live_offset_side'] ) ? max( 0, min( 200, (int) $input['live_offset_side'] ) ) : $d['live_offset_side'];
		$out['live_launcher_size'] = isset( $input['live_launcher_size'] ) ? max( 40, min( 96, (int) $input['live_launcher_size'] ) ) : $d['live_launcher_size'];
		$out['live_panel_width']   = isset( $input['live_panel_width'] ) ? max( 280, min( 560, (int) $input['live_panel_width'] ) ) : $d['live_panel_width'];
		$out['live_panel_height']  = isset( $input['live_panel_height'] ) ? max( 320, min( 900, (int) $input['live_panel_height'] ) ) : $d['live_panel_height'];
		$out['live_radius']        = isset( $input['live_radius'] ) ? max( 0, min( 48, (int) $input['live_radius'] ) ) : $d['live_radius'];
		$out['live_blur']          = isset( $input['live_blur'] ) ? max( 0, min( 80, (int) $input['live_blur'] ) ) : $d['live_blur'];
		$out['live_glass_opacity'] = isset( $input['live_glass_opacity'] ) ? max( 5, min( 100, (int) $input['live_glass_opacity'] ) ) : $d['live_glass_opacity'];
		$out['live_teaser_text']   = isset( $input['live_teaser_text'] ) ? sanitize_text_field( $input['live_teaser_text'] ) : $d['live_teaser_text'];
		$out['live_launcher_label'] = isset( $input['live_launcher_label'] ) ? mb_substr( sanitize_text_field( $input['live_launcher_label'] ), 0, 18 ) : '';
		$out['live_prechat_note'] = isset( $input['live_prechat_note'] ) ? sanitize_text_field( $input['live_prechat_note'] ) : $d['live_prechat_note'];
		$out['retention_days']     = isset( $input['retention_days'] ) ? max( 0, min( 3650, (int) $input['retention_days'] ) ) : $d['retention_days'];

		// ---- جریان، اعلان، رضایت، پاسخ آماده ----
		$out['notify_email_to']       = isset( $input['notify_email_to'] ) ? sanitize_email( $input['notify_email_to'] ) : '';
		$out['notify_telegram_token'] = isset( $input['notify_telegram_token'] ) ? sanitize_text_field( $input['notify_telegram_token'] ) : '';
		$out['notify_telegram_chat']  = isset( $input['notify_telegram_chat'] ) ? sanitize_text_field( $input['notify_telegram_chat'] ) : '';
		$out['notify_telegram_proxy'] = isset( $input['notify_telegram_proxy'] ) ? self::sanitize_proxy( $input['notify_telegram_proxy'] ) : '';
		$out['notify_telegram_base']  = isset( $input['notify_telegram_base'] ) && '' !== trim( (string) $input['notify_telegram_base'] )
			? untrailingslashit( esc_url_raw( $input['notify_telegram_base'] ) )
			: 'https://api.telegram.org';

		$out['notify_telegram_secret'] = isset( $input['notify_telegram_secret'] )
			? substr( sanitize_text_field( $input['notify_telegram_secret'] ), 0, 128 )
			: '';

		// رله فقط از دامنه‌های قابل‌اعتماد پذیرفته می‌شود
		$relay = isset( $input['notify_telegram_relay'] ) ? trim( (string) $input['notify_telegram_relay'] ) : '';
		if ( '' !== $relay ) {
			$relay = esc_url_raw( $relay );
			$host  = wp_parse_url( $relay, PHP_URL_HOST );
			$ok    = is_string( $host ) && (
				'script.google.com' === $host
				|| 'script.googleusercontent.com' === $host
				|| ( is_string( $host ) && preg_match( '/\.google\.com$/', $host ) )
			);
			$out['notify_telegram_relay'] = $ok ? $relay : '';
		} else {
			$out['notify_telegram_relay'] = '';
		}
		$out['notify_min_gap']        = isset( $input['notify_min_gap'] ) ? max( 0, min( 1440, (int) $input['notify_min_gap'] ) ) : $d['notify_min_gap'];
		$out['canned']                = isset( $input['canned'] ) ? sanitize_textarea_field( $input['canned'] ) : $d['canned'];

		// ---- کانال بله ----
		$out['bale_token']          = isset( $input['bale_token'] ) ? substr( sanitize_text_field( $input['bale_token'] ), 0, 200 ) : '';
		// شناسهٔ گفتگوی بله یا عدد است (مثلاً -100999) یا username عمومی
		// (مثلاً @etehad_support). پس رقم، حرف، @، _ و - مجازند و هر چیز
		// دیگر (فاصله، <، >، نقل‌قول، اسلش) حذف می‌شود.
		$out['bale_chat']           = isset( $input['bale_chat'] ) ? substr( preg_replace( '/[^0-9A-Za-z@_\-]/', '', (string) $input['bale_chat'] ), 0, 100 ) : '';
		// رمز webhook: اگر خالی بود خودکار یکی می‌سازیم — چون بدون آن
		// endpoint عمومی است و هر کسی می‌تواند به نام اپراتور پیام بگذارد.
		$out['bale_webhook_secret'] = isset( $input['bale_webhook_secret'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) $input['bale_webhook_secret'] ) : '';
		if ( '' === $out['bale_webhook_secret'] ) {
			$out['bale_webhook_secret'] = substr( md5( wp_generate_password( 32, false ) . microtime( true ) ), 0, 32 );
		}

		// ---- ایرانی‌سازی ----
		$out['sms_provider']      = isset( $input['sms_provider'] ) && in_array( $input['sms_provider'], array( 'kavenegar', 'melipayamak', 'smsir' ), true )
			? $input['sms_provider'] : $d['sms_provider'];
		$out['sms_api_key']       = isset( $input['sms_api_key'] ) ? substr( sanitize_text_field( $input['sms_api_key'] ), 0, 200 ) : '';
		$out['sms_sender']        = isset( $input['sms_sender'] ) ? preg_replace( '/[^0-9+]/', '', (string) $input['sms_sender'] ) : '';
		$out['sms_admin_phone']   = isset( $input['sms_admin_phone'] ) ? Gapineh_DB::sanitize_phone( $input['sms_admin_phone'] ) : '';
		$out['sms_min_gap']       = isset( $input['sms_min_gap'] ) ? max( 0, min( 1440, (int) $input['sms_min_gap'] ) ) : $d['sms_min_gap'];
		$out['brand_name']        = isset( $input['brand_name'] ) ? mb_substr( sanitize_text_field( $input['brand_name'] ), 0, 40 ) : '';
		$out['brand_url']         = isset( $input['brand_url'] ) ? (string) esc_url_raw( $input['brand_url'] ) : '';

		// ---- تیم و ساعت کاری ----
		// روزها از دو جا می‌آیند: چک‌باکس‌ها (hours_days_list) یا فیلد
		// پنهان hours_days. چک‌باکس اولویت دارد، چون اگر کاربر همه را
		// خاموش کند فیلد پنهان مقدار قبلی را نگه می‌داشت.
		if ( isset( $input['hours_days_list'] ) && is_array( $input['hours_days_list'] ) ) {
			$picked = array();
			foreach ( $input['hours_days_list'] as $dv ) {
				$dv = (int) $dv;
				if ( $dv >= 0 && $dv <= 6 ) {
					$picked[] = $dv;
				}
			}
			sort( $picked );
			// هیچ روزی انتخاب نشد → همهٔ روزها (وگرنه پشتیبانی هیچ‌وقت باز نبود)
			$out['hours_days'] = $picked ? implode( ',', array_values( array_unique( $picked ) ) ) : $d['hours_days'];
		} else {
			$out['hours_days'] = isset( $input['hours_days'] )
				? implode( ',', Gapineh_Hours::parse_days( (string) $input['hours_days'] ) )
				: $d['hours_days'];
		}
		foreach ( array( 'hours_from', 'hours_to' ) as $hk ) {
			$v = isset( $input[ $hk ] ) ? trim( (string) $input[ $hk ] ) : '';
			// قالب HH:MM معتبر؛ وگرنه به پیش‌فرض برمی‌گردد
			$out[ $hk ] = ( null !== Gapineh_Hours::to_minutes( $v ) ) ? $v : $d[ $hk ];
		}
		$out['hours_offline_msg'] = isset( $input['hours_offline_msg'] ) ? sanitize_text_field( $input['hours_offline_msg'] ) : $d['hours_offline_msg'];

		// ---- دستیار اپراتور ----
		$out['copilot_tone']      = isset( $input['copilot_tone'] ) && in_array( $input['copilot_tone'], array( 'friendly', 'formal', 'short' ), true )
			? $input['copilot_tone'] : $d['copilot_tone'];
		$out['copilot_max_msgs']  = isset( $input['copilot_max_msgs'] ) ? max( 2, min( 40, (int) $input['copilot_max_msgs'] ) ) : $d['copilot_max_msgs'];

		// ---- ارسال فایل ----
		$out['live_upload_max']   = isset( $input['live_upload_max'] ) ? max( 1, min( 25, (int) $input['live_upload_max'] ) ) : $d['live_upload_max'];
		// فقط پسوندهایی که در فهرست سفید کد هستند؛ تایپ «php» در این فیلد
		// نباید هیچ اثری داشته باشد.
		$out['live_upload_types'] = isset( $input['live_upload_types'] )
			? implode( ',', Gapineh_Upload::allowed_exts( array( 'live_upload_types' => (string) $input['live_upload_types'] ) ) )
			: $d['live_upload_types'];
		if ( '' === $out['live_upload_types'] ) {
			$out['live_upload_types'] = $d['live_upload_types'];
		}

		// ---- جست‌وجوی معنایی (RAG برداری) ----
		$out['rag_model']         = isset( $input['rag_model'] ) && '' !== trim( (string) $input['rag_model'] )
			? substr( sanitize_text_field( $input['rag_model'] ), 0, 100 )
			: $d['rag_model'];
		// ابعاد: فقط مقادیری که مدل‌های embedding-3 پشتیبانی می‌کنند، یا ۰ (پیش‌فرض مدل)
		$rd                       = isset( $input['rag_dims'] ) ? (int) $input['rag_dims'] : $d['rag_dims'];
		$out['rag_dims']          = in_array( $rd, array( 0, 256, 512, 1024, 1536 ), true ) ? $rd : $d['rag_dims'];
		$out['rag_top_k']         = isset( $input['rag_top_k'] ) ? max( 1, min( 8, (int) $input['rag_top_k'] ) ) : $d['rag_top_k'];
		$out['rag_min_score']     = isset( $input['rag_min_score'] ) ? round( min( 0.95, max( 0, (float) $input['rag_min_score'] ) ), 2 ) : $d['rag_min_score'];
		$out['rag_chunk_size']    = isset( $input['rag_chunk_size'] ) ? max( 200, min( 4000, (int) $input['rag_chunk_size'] ) ) : $d['rag_chunk_size'];
		$out['rag_chunk_overlap'] = isset( $input['rag_chunk_overlap'] ) ? max( 0, min( 600, (int) $input['rag_chunk_overlap'] ) ) : $d['rag_chunk_overlap'];
		// هم‌پوشانی نمی‌تواند از اندازهٔ تکه بزرگ‌تر باشد، وگرنه تکه‌ها تکراری می‌شدند
		$out['rag_chunk_overlap'] = min( $out['rag_chunk_overlap'], (int) $out['rag_chunk_size'] - 100 );
		$out['rag_post_types']    = isset( $input['rag_post_types'] )
			? implode( ',', array_slice( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $input['rag_post_types'] ) ) ) ), 0, 6 ) )
			: $d['rag_post_types'];
		if ( '' === $out['rag_post_types'] ) {
			$out['rag_post_types'] = $d['rag_post_types'];
		}

		foreach ( array( 'knowledge', 'widget_enabled', 'live_enabled', 'live_auto_open', 'live_human_enabled', 'live_sound', 'live_teaser', 'live_mobile_sheet', 'live_maximize', 'live_show_footer', 'live_prechat', 'live_prechat_required', 'streaming', 'csat', 'notify_email', 'notify_telegram', 'notify_only_offline', 'cdn_font', 'rag_vector', 'rag_auto_sync', 'live_upload', 'copilot_enabled', 'copilot_summary', 'woo_products', 'woo_order_lookup', 'team_auto_assign', 'team_restrict', 'hours_enabled', 'hours_ai_outside', 'jalali_dates', 'jalali_digits', 'sms_enabled', 'sms_notify_admin', 'sms_notify_visitor', 'brand_enabled', 'brand_hide_credit', 'uninstall_delete_data', 'bale_enabled', 'bale_notify', 'bale_twoway' ) as $b ) {
			$out[ $b ] = ! empty( $input[ $b ] ) ? 1 : 0;
		}

		// قالب تاریخ/ارقام: فقط مقدارهای مجاز پذیرفته می‌شوند
		$lf                   = isset( $input['locale_format'] ) ? (string) $input['locale_format'] : 'auto';
		$out['locale_format'] = in_array( $lf, array( 'auto', 'fa', 'en' ), true ) ? $lf : 'auto';

		return $out;
	}

	public function admin_assets( $hook ) {
		if ( ! self::is_page( $hook, 'settings' ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	public function render_guide() {
		?>
		<div class="wrap psb-admin psb-settings psb-guide-page">
			<div class="psb-set-hero"><div class="psb-set-brand"><img class="psb-set-logo" src="<?php echo esc_url( GAPINEH_URL . 'assets/img/brand-mark.svg?ver=' . GAPINEH_ASSET_VERSION ); ?>" alt="" width="48" height="48" decoding="async"><div><h1>راهنمای سریع گپینه</h1><p>راهنمای مرحله‌به‌مرحلهٔ راه‌اندازی، تنظیم و عیب‌یابی</p></div></div></div>
			<div class="psb-guide-grid">
				<div class="psb-set-card"><h2>۱. شروع سریع</h2><ol><li>در «اتصال»، سرویس AI، کلید API و مدل را انتخاب کنید.</li><li>ذخیرهٔ تنظیمات را بزنید و سپس آزمایش اتصال را اجرا کنید.</li><li>در «دستیار و دانش» ایندکس محتوای سایت را بسازید.</li><li>در «چت آنلاین» پیام خوش‌آمد، عنوان و فرم پیش‌گفتگو را تنظیم کنید.</li><li>در «بررسی سلامت» همهٔ آزمون‌ها را اجرا کنید.</li></ol></div>
				<div class="psb-set-card"><h2>۲. نکته‌های هر بخش</h2><p><b>عمومی:</b> زبان، تقویم، فعال‌سازی و پشتیبان‌گیری تنظیمات.</p><p><b>اتصال:</b> کلید API هرگز به مرورگر ارسال نمی‌شود؛ بعد از تغییر مدل دوباره تست کنید.</p><p><b>دانش:</b> RAG پاسخ‌ها را بر اساس محتوای سایت می‌کند؛ بعد از ویرایش‌های بزرگ ایندکس را به‌روزرسانی کنید.</p><p><b>گفتگوهای آنلاین:</b> مکالمات، اپراتورها، واگذاری و پاسخ دستی در این بخش مدیریت می‌شود.</p></div>
				<div class="psb-set-card"><h2>۳. عیب‌یابی</h2><p>اگر تنظیمات ذخیره نشد، کش وردپرس/CDN را پاک کنید، با مدیرکل وارد شوید و فرم را دوباره ارسال کنید.</p><p>اگر تست AI خطا داد، سرویس، Base URL، مدل و اعتبار کلید را بررسی کنید.</p><p>غیرفعال‌سازی افزونه اطلاعات را حذف نمی‌کند؛ حذف کامل افزونه، پاک‌سازی داده‌ها را انجام می‌دهد.</p></div>
			</div>
		</div>
		<?php
	}

	public function render_page() {
		$opts = $this->get_options();
		$diag = $this->quick_diag();
		?>
		<div class="wrap psb-admin psb-settings">

			<div class="psb-set-hero">
				<div class="psb-set-brand">
					<img class="psb-set-logo" src="<?php echo esc_url( GAPINEH_URL . 'assets/img/brand-mark.svg?ver=' . GAPINEH_ASSET_VERSION ); ?>" alt="" width="48" height="48" decoding="async">
					<div>
						<h1>گپینه</h1>
						<p>دستیار هوشمند و گفتگوی آنلاین — محصول <strong>اتحاد وردپرس</strong></p>
					</div>
				</div>
<div class="psb-set-quick">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gapineh-inbox' ) ); ?>">گفتگوها</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gapineh-contacts' ) ); ?>">مخاطبین</a>
				<a class="button" href="#psb-guide" onclick="var g=document.getElementById('psb-guide'); if (g) { g.open = true; } return false;">راهنما</a>
			</div>
			</div>

			<details id="psb-guide" class="psb-set-guide"><summary>راهنمای سریع گپینه</summary><p>۱) در تب اتصال سرویس و کلید API را وارد کنید و ذخیره بزنید. ۲) تست اتصال را اجرا کنید. ۳) در تب دانش سایت، ایندکس را بسازید. ۴) در تب چت آنلاین ظاهر و پیام خوش‌آمد را تنظیم کنید. ۵) در بررسی سلامت، همه آزمون‌ها را اجرا کنید. راهنمای کامل فارسی در فایل <code>GUIDE-fa.md</code> افزونه موجود است.</p></details>

			<div class="psb-set-status <?php echo $diag['ok'] ? 'ok' : 'bad'; ?>">
				<?php if ( $diag['ok'] ) : ?>
					<span class="dot"></span> اتصال آماده — <?php echo esc_html( $opts['model'] ); ?>
				<?php else : ?>
					<span class="dot"></span> <?php echo esc_html( $diag['msg'] ); ?>
				<?php endif; ?>
			</div>


			<form method="post" action="options.php" id="psb-set-form">
				<?php settings_fields( 'gapineh_group' ); ?>

				<div class="psb-set-layout">

										<nav class="psb-set-tabs" id="psb-set-tabs">
						<div class="psb-set-search"><input type="search" id="psb-set-q" placeholder="جست‌وجو در تنظیمات…" autocomplete="off" /><span class="psb-set-qcount" id="psb-set-qcount"></span></div>
						<button type="button" class="psb-set-tab is-active" data-tab="general"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> عمومی</button>
						<button type="button" class="psb-set-tab" data-tab="conn"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> اتصال</button>
						<button type="button" class="psb-set-tab" data-tab="assistant"><span class="dashicons dashicons-book-alt" aria-hidden="true"></span> دستیار و دانش</button>
						<button type="button" class="psb-set-tab" data-tab="chat"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span> چت آنلاین</button>
						<button type="button" class="psb-set-tab" data-tab="look"><span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span> ظاهر</button>
						<button type="button" class="psb-set-tab" data-tab="notify"><span class="dashicons dashicons-bell" aria-hidden="true"></span> اعلان‌ها</button>
						<button type="button" class="psb-set-tab" data-tab="team"><span class="dashicons dashicons-groups" aria-hidden="true"></span> تیم</button>
						<button type="button" class="psb-set-tab" data-tab="sec"><span class="dashicons dashicons-shield" aria-hidden="true"></span> امنیت و داده</button>
						<button type="button" class="psb-set-tab" data-tab="health"><span class="dashicons dashicons-heart" aria-hidden="true"></span> بررسی سلامت</button>
					</nav>

					<div class="psb-set-panels">

						<!-- ════════ عمومی ════════ -->
						<section class="psb-set-panel is-active" data-panel="general" data-title="عمومی">
							<div class="psb-set-card" id="card-locale">
								<h2>زبان و تقویم</h2>
								<p class="psb-set-hint">این تنظیمات روی کل افزونه اثر دارند، نه فقط یک بخش.</p>

								<div class="psb-set-row">
									<label>زبان رابط</label>
									<div class="psb-set-ctl">
										<select name="gapineh_options[lang]">
											<option value="fa" <?php selected( $opts['lang'], 'fa' ); ?>>فارسی</option>
											<option value="en" <?php selected( $opts['lang'], 'en' ); ?>>English</option>
										</select>
										<p class="psb-set-desc">زبان پیش‌فرض ویجت برای بازدیدکننده‌های تازه. خود کاربر می‌تواند از داخل چت عوضش کند.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>تقویم</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[jalali_dates]" value="1" <?php checked( 1, $opts['jalali_dates'] ); ?> />
											<span></span> تاریخ‌ها شمسی نمایش داده شوند
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[jalali_digits]" value="1" <?php checked( 1, $opts['jalali_digits'] ); ?> />
											<span></span> ارقام فارسی (۱۲۳ به‌جای 123)
										</label>
									<p class="psb-set-desc">روی اینباکس، مخاطبین، آمار، تاریخ سفارش ووکامرس و نام فایل‌های CSV اثر می‌گذارد. تاریخ در دیتابیس میلادی می‌ماند تا مرتب‌سازی خراب نشود.</p>
									<div style="margin-top:10px">
										<label for="psb-locale-format">قالب تاریخ و ارقام</label>
										<select id="psb-locale-format" name="gapineh_options[locale_format]">
											<option value="auto" <?php selected( 'auto', $opts['locale_format'] ?? 'auto' ); ?>>خودکار (بر اساس زبان سایت)</option>
											<option value="fa" <?php selected( 'fa', $opts['locale_format'] ?? 'auto' ); ?>>فارسی: شمسی و ارقام فارسی (طبق تنظیمات بالا)</option>
											<option value="en" <?php selected( 'en', $opts['locale_format'] ?? 'auto' ); ?>>میلادی با قالب و ارقام زبان سایت (انگلیسی/عربی)</option>
										</select>
										<p class="psb-set-desc">در حالت خودکار، سایت فارسی همان رفتار قبلی را دارد و سایت انگلیسی یا عربی تاریخ میلادی با ارقام لاتین نمایش می‌دهد.</p>
									</div>
								</div>
								</div>

								<div class="psb-set-row">
									<label for="eclf-tz">ناحیهٔ زمانی</label>
									<div class="psb-set-ctl">
										<?php $tz_now = Gapineh_Settings::current_timezone_label(); ?>
										<p class="psb-set-desc">
											الان: <strong><?php echo esc_html( $tz_now ); ?></strong>
											— ساعت سرور الان <strong><?php echo esc_html( Gapineh_Jalali::digits( gmdate( 'H:i' ) ) ); ?></strong> است.
											اگر این با ساعت واقعی تو فرق دارد، ساعت کاری و «چقدر پیش»ها جابه‌جا کار می‌کنند.
										</p>
										<p class="psb-set-desc">ناحیهٔ زمانی از <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">تنظیمات ← عمومی</a> وردپرس خوانده می‌شود و جداگانه اینجا تنظیم نمی‌شود، تا دو جای مختلف با هم نجنگند.</p>
									</div>
								</div>
							</div>

						</section>

						<!-- ════════ اتصال ════════ -->
						<section class="psb-set-panel" data-panel="conn" data-title="اتصال">
<div class="psb-set-card">
								<h2>اتصال به هوش مصنوعی</h2>
								<p class="psb-set-hint">سرویس هوش مصنوعی را انتخاب کن و کلید API همان سرویس را وارد کن. کلید <strong>فقط روی سرور</strong> ذخیره می‌شود و هرگز به مرورگر کاربران نمی‌رود.</p>

								<div class="psb-set-row">
									<label for="eclf-ai_provider">سرویس هوش مصنوعی</label>
									<div class="psb-set-ctl">
										<select id="eclf-ai_provider" name="gapineh_options[ai_provider]">
											<?php foreach ( Gapineh_Providers::presets() as $pk => $pv ) : ?>
												<option value="<?php echo esc_attr( $pk ); ?>"
													data-base="<?php echo esc_attr( $pv['base_url'] ); ?>"
													data-models="<?php echo esc_attr( implode( ',', $pv['models'] ) ); ?>"
													data-note="<?php echo esc_attr( $pv['note'] ); ?>"
													<?php selected( $opts['ai_provider'], $pk ); ?>>
													<?php echo esc_html( $pv['label'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="psb-set-desc" id="psb-prov-note">
											<?php
											$cur = Gapineh_Providers::current( $opts );
											$ps  = Gapineh_Providers::presets();
											echo esc_html( isset( $ps[ $cur['key'] ]['note'] ) ? $ps[ $cur['key'] ]['note'] : '' );
											?>
										</p>
										<p class="psb-set-desc">
											⚠️ <strong>مهم:</strong> وقتی سرویس را عوض می‌کنی، «آدرس پایهٔ API» را هم به آدرس پیشنهادی همان سرویس تغییر بده — وگرنه درخواست‌ها به سرویس قبلی می‌روند.
										</p>
									</div>
								</div>

								<?php
								$cur_prov = Gapineh_Providers::current( $opts );
								$prov_list = Gapineh_Providers::presets();
								$this->field(
									'کلید API',
									'api_key',
									'password',
									$opts['api_key'],
									'کلید همان سرویسی که بالا انتخاب کردی. مثال: <code>sk-…</code>'
								);
								$this->field(
									'آدرس پایهٔ API',
									'base_url',
									'url',
									$opts['base_url'],
									sprintf(
										/* translators: %s: suggested base url for the selected provider */
										'پیشنهادی برای سرویس انتخابی: <code>%s</code>',
										isset( $prov_list[ $cur_prov['key'] ]['base_url'] ) ? $prov_list[ $cur_prov['key'] ]['base_url'] : '—'
									)
								);
								?>

								<?php $prov_note = get_transient( 'gapineh_provider_test' ); ?>
								<?php if ( is_array( $prov_note ) && ! empty( $prov_note['text'] ) ) : ?>
									<?php delete_transient( 'gapineh_provider_test' ); ?>
									<div class="psb-set-testres">
										<div class="<?php echo empty( $prov_note['ok'] ) ? 'bad' : 'ok'; ?>">
											<strong>🤖 <?php esc_html_e( 'اتصال هوش مصنوعی', 'gapineh' ); ?>:</strong>
											<?php echo empty( $prov_note['ok'] ) ? '❌ ' : '✅ '; ?><?php echo esc_html( $prov_note['text'] ); ?>
										</div>
									</div>
								<?php endif; ?>

<div class="psb-set-row">
				<label>آزمایش</label>
				<div class="psb-set-ctl">
					<p class="psb-set-desc">اول تنظیمات را ذخیره کن، بعد این دکمه را بزن تا یک درخواست واقعی به سرویس زده شود.</p>
					<button type="button" id="psb-test-connection-btn" class="button" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">🧪 <?php esc_html_e( 'آزمایش اتصال', 'gapineh' ); ?></button>
					<span class="psb-test-connection-spinner" style="display:none;margin-right:10px;"></span>
				</div>
			</div>

								<div class="psb-set-row">
									<label for="model">مدل</label>
									<div class="psb-set-ctl">
										<input type="text" class="regular-text" id="model" name="gapineh_options[model]" value="<?php echo esc_attr( $opts['model'] ); ?>" list="gapineh-models" />
										<datalist id="gapineh-models">
											<?php
											// مدل‌های پیشنهادی همهٔ سرویس‌ها، تا با عوض‌کردن سرویس
											// لازم نباشد کاربر نام مدل را حفظ باشد.
											$seen_models = array();
											foreach ( $prov_list as $pv ) {
												foreach ( $pv['models'] as $mv ) {
													if ( isset( $seen_models[ $mv ] ) ) {
														continue;
													}
													$seen_models[ $mv ] = true;
													echo '<option value="' . esc_attr( $mv ) . '"></option>';
												}
											}
											?>
										</datalist>
										<p class="psb-set-desc">لیست کامل: <a href="https://gapgpt.app/platform-v2/pricing" target="_blank" rel="noopener">صفحهٔ قیمت‌ها</a></p>
									</div>
								</div>

								<div class="psb-set-row">
									<label for="system_prompt">دستور سیستم</label>
									<div class="psb-set-ctl">
										<textarea class="large-text code" id="system_prompt" rows="5" name="gapineh_options[system_prompt]"><?php echo esc_textarea( $opts['system_prompt'] ); ?></textarea>
										<p class="psb-set-desc">شخصیت و لحن دستیار را اینجا تعریف کن.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>پارامترهای مدل</label>
									<div class="psb-set-ctl psb-set-inline">
										<label class="psb-set-mini">حداکثر توکن خروجی
											<input type="number" min="100" max="8000" step="100" name="gapineh_options[max_tokens]" value="<?php echo (int) $opts['max_tokens']; ?>" />
										</label>
										<label class="psb-set-mini">خلاقیت (temperature)
											<input type="number" min="0" max="2" step="0.1" name="gapineh_options[temperature]" value="<?php echo esc_attr( (string) $opts['temperature'] ); ?>" />
										</label>
										<p class="psb-set-desc">توکن بیشتر = پاسخ طولانی‌تر و هزینهٔ بیشتر. خلاقیت ۰ = دقیق و تکرارپذیر، ۲ = خیلی آزاد.</p>
									</div>
								</div>
							</div>						</section>

						<!-- ════════ دستیار و دانش ════════ -->
						<section class="psb-set-panel" data-panel="assistant" data-title="دستیار و دانش">
<div class="psb-set-card">
								<h2>دستیار و دانش سایت</h2>
								<p class="psb-set-hint">اینکه دستیار چقدر از محتوای سایتت بداند.</p>

								<div class="psb-set-row">
									<label>نمایش ویجت</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[widget_enabled]" value="1" <?php checked( 1, $opts['widget_enabled'] ); ?> />
											<span></span> ویجت در همهٔ صفحات سایت نمایش داده شود
										</label>
										<p class="psb-set-desc">برای جاسازی داخل یک صفحهٔ خاص، شورت‌کد <code>[gapineh]</code> را بگذار.</p>
									</div>
								</div>
							</div><div class="psb-set-card">
								<h2>پاسخ بر اساس محتوای سایت (RAG)</h2>
								<p class="psb-set-hint">دستیار قبل از پاسخ، محتوای مرتبط را از پست‌ها و صفحه‌هایت جستجو می‌کند.</p>

								<div class="psb-set-row">
									<label>فعال‌سازی</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[knowledge]" value="1" <?php checked( 1, $opts['knowledge'] ); ?> />
											<span></span> محتوای مرتبط از سایت به مدل داده شود
										</label>
									</div>
								</div>

								<div class="psb-set-row">
									<label>عمق جستجو</label>
									<div class="psb-set-ctl psb-set-inline">
										<label class="psb-set-mini">تعداد پست برای زمینه
											<input type="number" min="1" max="15" name="gapineh_options[knowledge_posts]" value="<?php echo (int) $opts['knowledge_posts']; ?>" />
										</label>
										<label class="psb-set-mini">حافظهٔ مکالمه (پیام)
											<input type="number" min="4" max="60" name="gapineh_options[live_history]" value="<?php echo (int) $opts['live_history']; ?>" />
										</label>
										<p class="psb-set-desc">«حافظهٔ مکالمه» سقف پیامی است که هر بار به مدل فرستاده می‌شود — مستقیماً روی هزینه اثر دارد.</p>
									</div>
								</div>
							</div><div class="psb-set-card" id="gapineh-rag">
								<?php
								// ★ این بلوک باید داخل خود کارت باشد، نه بیرونش.
								// قبلاً بیرون <div> بود و وقتی ساختار صفحه
								// بازچینی شد، بی‌صدا حذف شد و نوار نتیجهٔ
								// ساخت ایندکس از کار افتاد — بدون هیچ خطایی.
								$rag       = Gapineh_RAG::instance();
								$rag_state = $rag->state();
								$rag_count = $rag->count_chunks();
								$rag_note  = $rag->take_notice();
								?>
								<h2>جست‌وجوی معنایی (بردار)</h2>
								<p class="psb-set-hint">
									بدون این گزینه، دستیار فقط نوشته‌هایی را پیدا می‌کند که <strong>کلمهٔ مشترک</strong> با سؤال داشته باشند.
									با روشن‌کردنش، محتوا یک‌بار به بردار عددی تبدیل می‌شود و سؤال‌های هم‌معنی هم پیدا می‌شوند
									(مثلاً «شرایط بازگشت وجه» به نوشتهٔ «قوانین بازگشت کالا» می‌رسد).
								</p>

								<div class="psb-set-row">
									<label>فعال‌سازی</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[rag_vector]" value="1" <?php checked( 1, $opts['rag_vector'] ); ?> />
											<span></span> جست‌وجوی معنایی فعال باشد
										</label>
										<p class="psb-set-desc">
											برای ساخت ایندکس یک‌بار هزینهٔ embedding می‌دهی (با <code>text-embedding-3-small</code> تقریباً ناچیز است).
											اگر درگاه API تو از <code>/embeddings</code> پشتیبانی نکند، دکمهٔ «آزمایش اتصال» همان اول خطا را نشان می‌دهد و چت مثل قبل با جست‌وجوی کلیدواژه‌ای کار می‌کند.
										</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label for="eclf-rag_model">مدل embedding</label>
									<div class="psb-set-ctl">
										<input type="text" class="regular-text" id="eclf-rag_model" name="gapineh_options[rag_model]" value="<?php echo esc_attr( $opts['rag_model'] ); ?>" />
										<p class="psb-set-desc">باید از همان درگاه <code>base_url</code> در تب «اتصال» قابل استفاده باشد.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label for="eclf-rag_dims">ابعاد بردار</label>
									<div class="psb-set-ctl">
										<select id="eclf-rag_dims" name="gapineh_options[rag_dims]">
											<?php
											foreach ( array(
												0    => 'پیش‌فرض خود مدل',
												256  => '۲۵۶ — سبک‌ترین، سریع‌ترین جست‌وجو',
												512  => '۵۱۲ — پیشنهادی (تعادل دقت و سرعت)',
												1024 => '۱۰۲۴ — دقیق‌تر، سنگین‌تر',
												1536 => '۱۵۳۶ — کامل',
											) as $dv => $dl ) :
												?>
												<option value="<?php echo (int) $dv; ?>" <?php selected( (int) $opts['rag_dims'], $dv ); ?>><?php echo esc_html( $dl ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="psb-set-desc">ابعاد کمتر = ایندکس کوچک‌تر و جست‌وجوی سریع‌تر، با کمی افت دقت. بعد از تغییرش ایندکس باید دوباره ساخته شود.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>دقت و تکه‌بندی</label>
									<div class="psb-set-ctl psb-set-inline">
										<label class="psb-set-mini">تکه در هر پاسخ
											<input type="number" min="1" max="8" name="gapineh_options[rag_top_k]" value="<?php echo (int) $opts['rag_top_k']; ?>" />
										</label>
										<label class="psb-set-mini">اندازهٔ تکه
											<input type="number" min="200" max="4000" step="100" name="gapineh_options[rag_chunk_size]" value="<?php echo (int) $opts['rag_chunk_size']; ?>" />
											<span>کاراکتر</span>
										</label>
										<label class="psb-set-mini">هم‌پوشانی
											<input type="number" min="0" max="600" step="50" name="gapineh_options[rag_chunk_overlap]" value="<?php echo (int) $opts['rag_chunk_overlap']; ?>" />
											<span>کاراکتر</span>
										</label>
										<label class="psb-set-mini">آستانهٔ شباهت
											<input type="number" min="0" max="0.95" step="0.05" name="gapineh_options[rag_min_score]" value="<?php echo esc_attr( (string) $opts['rag_min_score'] ); ?>" />
										</label>
										<p class="psb-set-desc">
											اگر پاسخ‌ها نامرتبط‌اند «آستانهٔ شباهت» را بالا ببر (مثلاً ۰٫۴۵) و اگر دستیار هیچ منبعی پیدا نمی‌کند پایین بیاور (مثلاً ۰٫۲۵).
										</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label for="eclf-rag_post_types">چه محتواهایی ایندکس شوند</label>
									<div class="psb-set-ctl">
										<input type="text" class="regular-text" id="eclf-rag_post_types" name="gapineh_options[rag_post_types]" value="<?php echo esc_attr( $opts['rag_post_types'] ); ?>" />
										<p class="psb-set-desc">نام نوع محتواها با کاما: <code>post,page,product</code></p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>به‌روزرسانی خودکار</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[rag_auto_sync]" value="1" <?php checked( 1, $opts['rag_auto_sync'] ); ?> />
											<span></span> نوشته‌های تازه/ویرایش‌شده با کرون ساعتی خودکار ایندکس شوند
										</label>
										<p class="psb-set-desc">
											عمداً موقع «انتشار» ایندکس نمی‌گیریم؛ وگرنه دکمهٔ انتشار چند ثانیه هنگ می‌کرد و اگر API خطا می‌داد، ذخیرهٔ نوشته هم با خطا روبه‌رو می‌شد.
										</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>وضعیت ایندکس</label>
									<div class="psb-set-ctl">
										<?php if ( $rag_note ) : ?>
											<p class="psb-set-desc psb-rag-note psb-rag-note--<?php echo esc_attr( $rag_note['type'] ); ?>">
												<?php echo nl2br( esc_html( $rag_note['text'] ) ); ?>
											</p>
										<?php endif; ?>

										<p class="psb-set-desc">
											<strong><?php echo (int) $rag_count; ?></strong> تکه ·
											<strong><?php echo (int) $rag_state['sources']; ?></strong> منبع ·
											ابعاد <strong><?php echo (int) $rag_state['dims']; ?></strong> ·
											مدل <code><?php echo esc_html( $rag_state['model'] ? $rag_state['model'] : '—' ); ?></code>
											<?php if ( $rag_state['built_at'] ) : ?>
												· آخرین ساخت <?php echo esc_html( human_time_diff( (int) $rag_state['built_at'], time() ) ); ?> پیش
											<?php else : ?>
												· هنوز ایندکس ساخته نشده
											<?php endif; ?>
										</p>

										<?php if ( ! empty( $rag_state['error'] ) ) : ?>
											<p class="psb-set-desc psb-rag-note psb-rag-note--error"><?php echo esc_html( $rag_state['error'] ); ?></p>
										<?php endif; ?>

										<?php if ( $rag_count > Gapineh_RAG::SOFT_CHUNKS ) : ?>
											<p class="psb-set-desc psb-rag-note psb-rag-note--warning">
												ایندکس بزرگ شده (<?php echo (int) $rag_count; ?> تکه). جست‌وجو خطی است؛ ابعاد بردار را روی ۲۵۶ بگذار تا سریع بماند.
											</p>
										<?php endif; ?>

										<p class="psb-set-desc">اول تنظیمات را ذخیره کن، بعد دکمه‌ها را بزن.</p>
										<button type="button" data-psb-form="psb-rag-build-form" class="button button-primary">🧬 ساخت / به‌روزرسانی ایندکس</button>
										<button type="button" data-psb-form="psb-rag-test-form" class="button">🧪 آزمایش اتصال embedding</button>
										<button type="button" data-psb-form="psb-rag-clear-form" data-psb-confirm="کل ایندکس برداری پاک شود؟ این کار برگشت‌پذیر نیست." class="button psb-set-danger">🗑 پاک کردن ایندکس</button>
									</div>
								</div>
							</div>						</section>

						<!-- ════════ چت آنلاین ════════ -->
						<section class="psb-set-panel" data-panel="chat" data-title="چت آنلاین">
<div class="psb-set-card">
								<h2>چت آنلاین</h2>
								<p class="psb-set-hint">پاسخ‌ها به‌صورت پیش‌فرض توسط هوش مصنوعی داده می‌شوند. با نوشتن پاسخ دستی در <a href="<?php echo esc_url( admin_url( 'admin.php?page=gapineh-inbox' ) ); ?>">اینباکس</a>، آن مکالمه به حالت «اپراتور» می‌رود.</p>

								<div class="psb-set-row">
									<label>روشن/خاموش</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[live_enabled]" value="1" <?php checked( 1, $opts['live_enabled'] ); ?> />
											<span></span> چت آنلاین فعال باشد
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[live_human_enabled]" value="1" <?php checked( 1, $opts['live_human_enabled'] ); ?> />
											<span></span> امکان ورود اپراتور انسانی از اینباکس
										</label>
									</div>
								</div>

								<div class="psb-set-row">
									<label>ارسال فایل</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[live_upload]" value="1" <?php checked( 1, $opts['live_upload'] ); ?> />
											<span></span> بازدیدکننده بتواند تصویر و فایل بفرستد
										</label>
										<div class="psb-set-inline" style="margin-top:10px">
											<label class="psb-set-mini">حداکثر حجم
												<input type="number" min="1" max="25" name="gapineh_options[live_upload_max]" value="<?php echo (int) $opts['live_upload_max']; ?>" />
												<span>مگابایت</span>
											</label>
											<label class="psb-set-mini">پسوندهای مجاز
												<input type="text" style="min-width:220px" name="gapineh_options[live_upload_types]" value="<?php echo esc_attr( $opts['live_upload_types'] ); ?>" />
											</label>
										</div>
										<p class="psb-set-desc">
											فقط این پسوندها پذیرفته می‌شوند: <code>jpg, jpeg, png, gif, webp, pdf, txt</code>.
											پسوند دیگری اینجا بنویسی نادیده گرفته می‌شود.
											<strong>SVG عمداً مجاز نیست</strong> چون می‌تواند JavaScript داشته باشد و در مرورگر اپراتور اجرا شود.
											فایل‌ها در <code>wp-content/uploads/gapineh/</code> با نام تصادفی ذخیره می‌شوند و پوشه با <code>.htaccess</code> قفل است.
										</p>
									</div>
								</div>

								<?php
								$woo_on = class_exists( 'Gapineh_Woo' ) && Gapineh_Woo::active();
								?>
								<div class="psb-set-row">
									<label>ووکامرس</label>
									<div class="psb-set-ctl">
										<?php if ( ! $woo_on ) : ?>
											<p class="psb-set-desc psb-rag-note psb-rag-note--warning">
												ووکامرس روی این سایت فعال نیست. این گزینه‌ها تا فعال‌شدنش بی‌اثرند.
											</p>
										<?php endif; ?>

										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[woo_products]" value="1" <?php checked( 1, $opts['woo_products'] ); ?> />
											<span></span> محصولات ووکامرس در ایندکس دانش قرار بگیرند
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[woo_order_lookup]" value="1" <?php checked( 1, $opts['woo_order_lookup'] ); ?> />
											<span></span> بازدیدکننده بتواند وضعیت سفارش را استعلام بگیرد
										</label>

										<p class="psb-set-desc">
											برای محصولات، علاوه بر توضیحات، <strong>قیمت، موجودی، SKU، دسته‌بندی و گزینه‌ها</strong> هم ایندکس می‌شود.
											بعد از تغییر قیمت یا موجودی، «ساخت ایندکس» را در تب «دستیار و دانش سایت» بزن.
										</p>
										<p class="psb-set-desc">
											<strong>استعلام سفارش امن است:</strong> مشتری باید ایمیل یا شمارهٔ تماسی را بزند که موقع خرید وارد کرده.
											پاسخ فقط شامل شماره، وضعیت، تاریخ، مبلغ، اقلام و شهر مقصد است — <strong>آدرس کامل، ایمیل، شمارهٔ تماس و اطلاعات پرداخت هرگز نشان داده نمی‌شود</strong>.
											بعد از ۶ تلاش ناموفق، آن IP یک ساعت بسته می‌شود.
										</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>دستیار اپراتور</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[copilot_enabled]" value="1" <?php checked( 1, $opts['copilot_enabled'] ); ?> />
											<span></span> در اینباکس دکمهٔ «پیشنهاد پاسخ» با هوش مصنوعی نشان داده شود
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[copilot_summary]" value="1" <?php checked( 1, $opts['copilot_summary'] ); ?> />
											<span></span> دکمهٔ «خلاصهٔ مکالمه» هم نشان داده شود
										</label>

										<div class="psb-set-inline" style="margin-top:10px">
											<label class="psb-set-mini">لحن پیش‌فرض
												<select name="gapineh_options[copilot_tone]">
													<option value="friendly" <?php selected( $opts['copilot_tone'], 'friendly' ); ?>>دوستانه</option>
													<option value="formal" <?php selected( $opts['copilot_tone'], 'formal' ); ?>>رسمی</option>
													<option value="short" <?php selected( $opts['copilot_tone'], 'short' ); ?>>خیلی کوتاه</option>
												</select>
											</label>
											<label class="psb-set-mini">پیام‌های زمینه
												<input type="number" min="2" max="40" name="gapineh_options[copilot_max_msgs]" value="<?php echo (int) $opts['copilot_max_msgs']; ?>" />
											</label>
										</div>

										<p class="psb-set-desc">
											پیش‌نویس <strong>هرگز خودکار ارسال نمی‌شود</strong>؛ فقط داخل کادر ورودی می‌نشیند تا اپراتور ویرایش و ارسال کند.
											حالت مکالمه هم عوض نمی‌شود.
											<br />در prompt صراحتاً نهی شده که وانمود کند کاری انجام شده («واریز شد»، «ارسال شد») یا قیمت و تعهد جدید بسازد.
											<br />هر کلیک یک فراخوانی کامل مدل است؛ برای جلوگیری از هزینهٔ اتفاقی بین دو پیش‌نویس <?php echo (int) Gapineh_Copilot::THROTTLE; ?> ثانیه فاصله لازم است.
										</p>
									</div>
								</div>

								<?php
								$this->field( 'عنوان پنل چت', 'live_title', 'text', $opts['live_title'], '' );
								$this->field( 'نام اپراتور', 'live_agent_name', 'text', $opts['live_agent_name'], 'زیر عنوان پنل نمایش داده می‌شود (مثلاً «پشتیبانی اتحاد»).' );
								$this->field( 'پیام خوش‌آمد', 'live_greeting', 'text', $opts['live_greeting'], '', 'large-text' );
								$this->field( 'متن باکس ورودی', 'live_placeholder', 'text', $opts['live_placeholder'], '' );
								?>

								<div class="psb-set-row">
									<label for="live_starters">دکمه‌های شروع</label>
									<div class="psb-set-ctl">
										<textarea class="large-text code" id="live_starters" rows="3" name="gapineh_options[live_starters]"><?php echo esc_textarea( $opts['live_starters'] ); ?></textarea>
										<p class="psb-set-desc">هر خط یک دکمه. خالی بگذاری حذف می‌شوند.</p>
									</div>
								</div>
							</div><div class="psb-set-card">
								<h2>فرم پیش از گفتگو</h2>
								<p class="psb-set-hint">مشخصات بازدیدکننده در <a href="<?php echo esc_url( admin_url( 'admin.php?page=gapineh-contacts' ) ); ?>">مخاطبین</a> ذخیره می‌شود و خروجی CSV هم دارد.</p>

								<div class="psb-set-row">
									<label>گرفتن مشخصات</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[live_prechat]" value="1" <?php checked( 1, $opts['live_prechat'] ); ?> />
											<span></span> قبل از چت، نام و نام خانوادگی و شمارهٔ تماس بگیر
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[live_prechat_required]" value="1" <?php checked( 1, $opts['live_prechat_required'] ); ?> />
											<span></span> پر کردن هر سه فیلد اجباری باشد
										</label>
									</div>
								</div>

								<?php $this->field( 'توضیح بالای فرم', 'live_prechat_note', 'text', $opts['live_prechat_note'], '', 'large-text' ); ?>
							</div><div class="psb-set-card">
								<h2>رفتار</h2>

								<div class="psb-set-row">
									<label>باز شدن خودکار</label>
									<div class="psb-set-ctl psb-set-inline">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[live_auto_open]" value="1" <?php checked( 1, $opts['live_auto_open'] ); ?> />
											<span></span> پنل خودکار باز شود
										</label>
										<label class="psb-set-mini">پس از
											<input type="number" min="0" max="120" name="gapineh_options[live_auto_delay]" value="<?php echo (int) $opts['live_auto_delay']; ?>" /> ثانیه
										</label>
										<p class="psb-set-desc">فقط یک بار برای هر بازدیدکننده اتفاق می‌افتد.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>متفرقه</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[live_sound]" value="1" <?php checked( 1, $opts['live_sound'] ); ?> />
											<span></span> صدای ملایم هنگام رسیدن پیام (پیش‌فرض خاموش)
										</label>
									</div>
								</div>

								<?php $this->field( 'پیام آفلاین', 'live_offline_note', 'text', $opts['live_offline_note'], 'مثلاً: الان آفلاین هستیم، پیامت ثبت شد.' ); ?>
							</div><div class="psb-set-card" id="streaming">
								<h2>پاسخ و امتیاز رضایت</h2>
								<p class="psb-set-hint">برای جلوگیری از ارسال تکراری به سرویس، پاسخ‌ها فعلاً یک‌جا نمایش داده می‌شوند.</p>

								<div class="psb-set-row">
									<label>فعال‌سازی</label>
									<div class="psb-set-ctl">
										<?php
										echo '<input type="hidden" name="gapineh_options[streaming]" value="' . ( empty( $opts['streaming'] ) ? '0' : '1' ) . '">';
										$this->toggle( 'csat', $opts['csat'], 'دکمهٔ 👍/👎 زیر هر پاسخ (امتیاز رضایت)' );
										?>
										<p class="psb-set-desc">نمایش تدریجی پاسخ تا اصلاح مسیر جریان‌دار موقتاً غیرفعال است؛ تنظیم قبلی حفظ می‌شود.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label for="canned">پاسخ‌های آمادهٔ اپراتور</label>
									<div class="psb-set-ctl">
										<textarea class="large-text code" id="canned" rows="5" name="gapineh_options[canned]"><?php echo esc_textarea( $opts['canned'] ); ?></textarea>
										<p class="psb-set-desc">هر خط یک پاسخ، با قالب <code>متن پاسخ|عنوان دکمه</code>. در اینباکس به‌صورت دکمه ظاهر می‌شوند.</p>
									</div>
								</div>
							</div>						</section>

						<!-- ════════ ظاهر ════════ -->
						<section class="psb-set-panel" data-panel="look" data-title="ظاهر">
<div class="psb-set-card">
								<h2>رنگ و حالت</h2>

								<div class="psb-set-row">
									<label for="accent">رنگ اصلی</label>
									<div class="psb-set-ctl">
										<input type="text" class="gapineh-color" id="accent" name="gapineh_options[accent]" value="<?php echo esc_attr( $opts['accent'] ); ?>" data-default-color="#285d50" />
										<p class="psb-set-desc">روی حباب، دکمه‌ها، حباب پیام کاربر و هالهٔ پشت پنل اثر می‌گذارد.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label for="live_theme">حالت رنگ</label>
									<div class="psb-set-ctl">
										<select id="live_theme" name="gapineh_options[live_theme]">
											<option value="auto" <?php selected( 'auto', $opts['live_theme'] ); ?>>خودکار (طبق تنظیم دستگاه کاربر)</option>
											<option value="light" <?php selected( 'light', $opts['live_theme'] ); ?>>همیشه روشن</option>
											<option value="dark" <?php selected( 'dark', $opts['live_theme'] ); ?>>همیشه تاریک</option>
										</select>
									</div>
								</div>
							</div><div class="psb-set-card">
								<h2>جای‌گیری</h2>
								<p class="psb-set-hint">همهٔ مقادیر به پیکسل هستند.</p>

								<div class="psb-set-row">
									<label for="live_position">گوشهٔ نمایش</label>
									<div class="psb-set-ctl">
										<select id="live_position" name="gapineh_options[live_position]">
											<option value="start" <?php selected( 'start', $opts['live_position'] ); ?>>ابتدای متن (پایین-چپ در سایت فارسی)</option>
											<option value="end" <?php selected( 'end', $opts['live_position'] ); ?>>انتهای متن (پایین-راست در سایت فارسی)</option>
										</select>
									</div>
								</div>

								<div class="psb-set-row">
									<label>فاصله از لبه‌ها</label>
									<div class="psb-set-ctl psb-set-inline">
										<?php
										$this->mini( 'از پایین', 'live_offset_bottom', $opts['live_offset_bottom'], 0, 200, 'px' );
										$this->mini( 'از کنار', 'live_offset_side', $opts['live_offset_side'], 0, 200, 'px' );
										?>
									</div>
								</div>

								<div class="psb-set-row">
									<label>اندازه‌ها</label>
									<div class="psb-set-ctl psb-set-inline">
										<?php
										$this->mini( 'قطر حباب', 'live_launcher_size', $opts['live_launcher_size'], 40, 96, 'px' );
										$this->mini( 'عرض پنل', 'live_panel_width', $opts['live_panel_width'], 280, 560, 'px' );
										$this->mini( 'ارتفاع پنل', 'live_panel_height', $opts['live_panel_height'], 320, 900, 'px' );
										?>
										<p class="psb-set-desc">ارتفاع پنل هرگز از ارتفاع صفحه بیشتر نمی‌شود.</p>
									</div>
								</div>
							</div><div class="psb-set-card">
								<h2>شیشه</h2>
								<p class="psb-set-hint">هرچه شفافیت کمتر، شیشه شفاف‌تر و محتوای پشت بیشتر دیده می‌شود.</p>

								<div class="psb-set-row">
									<label>پارامترهای شیشه</label>
									<div class="psb-set-ctl psb-set-inline">
										<?php
										$this->mini( 'گردی پنل', 'live_radius', $opts['live_radius'], 0, 48, 'px' );
										$this->mini( 'شدت blur', 'live_blur', $opts['live_blur'], 0, 80, 'px' );
										$this->mini( 'شفافیت پنل', 'live_glass_opacity', $opts['live_glass_opacity'], 5, 100, '٪' );
										?>
									</div>
								</div>
							</div><div class="psb-set-card">
								<h2>اجزا</h2>

								<?php $this->field( 'متن داخل حباب', 'live_launcher_label', 'text', $opts['live_launcher_label'], 'حداکثر ۱۸ نویسه. پر شود، حباب به قرص کشیده تبدیل می‌شود.' ); ?>

								<div class="psb-set-row">
									<label>روشن/خاموش</label>
									<div class="psb-set-ctl">
										<?php
										$this->toggle( 'live_teaser', $opts['live_teaser'], 'برچسب دعوت کنار حباب (یک‌بار برای هر بازدیدکننده)' );
										$this->toggle( 'live_maximize', $opts['live_maximize'], 'دکمهٔ بزرگ‌نمایی پنل' );
										$this->toggle( 'live_mobile_sheet', $opts['live_mobile_sheet'], 'در موبایل به‌صورت ورق تمام‌صفحه باز شود' );
										$this->toggle( 'live_show_footer', $opts['live_show_footer'], 'پابرگ برند (نمایش نام برند و لینک در پایین پنل)' );
										?>
									</div>
								</div>

								<?php $this->field( 'متن برچسب دعوت', 'live_teaser_text', 'text', $opts['live_teaser_text'], '' ); ?>
							</div>

						<div class="psb-set-card" id="card-brand">
							<h2>برندسازی سفید (White‑Label)</h2>
							<p class="psb-set-hint">اگر این پلاگین را برای مشتری نصب می‌کنید، می‌توانید نام و برند خودتان را به‌جای «گپینه» نمایش دهید. تمام تغییرات فقط در سمت کاربر (فرانت‌اند) اعمال می‌شود.</p>

							<div class="psb-set-row">
								<label>وضعیت</label>
								<div class="psb-set-ctl">
									<label class="psb-set-switch">
									<input type="checkbox" name="gapineh_options[brand_enabled]" value="1" <?php checked( 1, $opts['brand_enabled'] ); ?> />
									<span></span> <strong>برندسازی سفید فعال باشد</strong> — نام، لینک و لوگوی سفارشی در ویجت نمایش داده شود
									</label>
									<br />
									<label class="psb-set-switch">
									<input type="checkbox" name="gapineh_options[brand_hide_credit]" value="1" <?php checked( 1, $opts['brand_hide_credit'] ); ?> />
									<span></span> <strong>پابرگ پیش‌فرض مخفی شود</strong> — عبارت «گپینه / etehadwp.com/gapineh / اتحاد وردپرس» از پایین پنل چت حذف می‌شود (تنها زمانی که برندسازی غیرفعال است)
									</label>
									<p class="psb-set-desc psb-set-tip">
									💡 <strong>نکته:</strong> وقتی برندسازی فعال باشد، آیکون پیش‌فرض مغز/چت همچنان نمایش داده می‌شود. برای تغییر آیکون، از فیلتر <code>gapineh_brand_logo</code> در کد سفارشی استفاده کنید.
									</p>
								</div>
							</div>

								<?php
							$this->field(
								'نام برند',
								'brand_name',
								'text',
							$opts['brand_name'],
								'نامی که در هدر پنل چت، پیام خوش‌آمد و اعلان‌ها نمایش داده می‌شود. مثال: «پشتیبانی فروشگاه من»، «تیم فنی آکادمی». حداکثر ۴۰ کاراکتر.',
								'regular-text'
								);
							$this->field(
								'آدرس برند (لینک پابرگ)',
								'brand_url',
								'url',
							$opts['brand_url'],
								'لینکی که در پابرگ پنل چت (وقتی پابرگ فعال است) و در منوی برند نمایش داده می‌شود. مثال: <code>https://mysite.com/support</code>. باید با http:// یا https:// شروع شود.',
								'regular-text'
								);
								?>

							<div class="psb-set-row">
								<label>پیش‌نمایش زنده</label>
								<div class="psb-set-ctl">
									<div id="psb-brand-preview" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;direction:rtl;">
										<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
											<div style="width:40px;height:40px;border-radius:12px;overflow:hidden;flex:none;box-shadow:0 6px 16px -8px rgba(34,211,238,.8);"><img src="<?php echo esc_url( GAPINEH_URL . 'assets/img/brand-mark.svg?ver=' . GAPINEH_ASSET_VERSION ); ?>" alt="" width="40" height="40" style="display:block;width:40px;height:40px;"></div>
											<div>
												<div style="font-weight:700;font-size:15px;color:#0f172a;" id="psb-brand-preview-name"><?php echo esc_html( $opts['brand_name'] ? $opts['brand_name'] : 'گپینه' ); ?></div>
												<div style="font-size:12px;color:#64748b;" id="psb-brand-preview-sub">دستیار هوشمند شما</div>
											</div>
										</div>
										<div style="padding:12px;background:#fff;border-radius:8px;border:1px solid #e2e8f0;font-size:13px;color:#334155;line-height:1.8;" id="psb-brand-preview-greeting">
												<?php echo esc_html( $opts['live_greeting'] ? $opts['live_greeting'] : 'سلام! 👋 چطور می‌تونیم کمکت کنیم؟' ); ?>
										</div>
										<div style="margin-top:10px;padding-top:10px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#94a3b8;">
											<span id="psb-brand-preview-footer"><?php echo $opts['brand_enabled'] && $opts['brand_hide_credit'] ? 'پابرگ مخفی است' : ($opts['brand_enabled'] ? ($opts['brand_name'] . ' — ' . $opts['brand_url']) : 'گپینه — etehadwp.com/gapineh'); ?></span>
												<?php if ( $opts['brand_enabled'] && $opts['brand_url'] ) : ?>
											<a href="<?php echo esc_url( $opts['brand_url'] ); ?>" target="_blank" rel="noopener" style="color:#22d3ee;text-decoration:none;font-weight:600;" id="psb-brand-preview-link"><?php echo esc_html( wp_parse_url( $opts['brand_url'], PHP_URL_HOST ) ); ?></a>
												<?php endif; ?>
										</div>
									</div>
									<p class="psb-set-desc">این پیش‌نمایش تنظیمات فعلی را نشان می‌دهد. پس از تغییر فیلدها، دکمهٔ «ذخیرهٔ تنظیمات» را بزنید تا به‌روزرسانی شود.</p>
								</div>
							</div>
						</div>
						</section>

						<!-- ════════ اعلان‌ها ════════ -->
						<section class="psb-set-panel" data-panel="notify" data-title="اعلان‌ها">
<div class="psb-set-card">
								<h2>اعلان به مدیر</h2>
								<p class="psb-set-hint">وقتی بازدیدکننده پیام می‌دهد، از راه ایمیل یا تلگرام باخبر شو.</p>

								<div class="psb-set-row">
									<label>کانال‌ها</label>
									<div class="psb-set-ctl">
										<?php
										$this->toggle( 'notify_email', $opts['notify_email'], 'اعلان با ایمیل' );
										$this->toggle( 'notify_telegram', $opts['notify_telegram'], 'اعلان با تلگرام' );
										$this->toggle( 'notify_only_offline', $opts['notify_only_offline'], 'فقط وقتی اپراتور آنلاین نیست اعلان بفرست' );
										?>
									</div>
								</div>

								<?php $this->field( 'ایمیل گیرنده', 'notify_email_to', 'email', $opts['notify_email_to'], 'خالی بگذاری به ایمیل مدیر سایت می‌رود.' ); ?>

								<div class="psb-set-row">
									<label>کنترل ارسال</label>
									<div class="psb-set-ctl psb-set-inline">
										<label class="psb-set-mini">حداقل فاصله بین دو اعلان برای یک مکالمه
											<input type="number" min="0" max="1440" name="gapineh_options[notify_min_gap]" value="<?php echo (int) $opts['notify_min_gap']; ?>" /> دقیقه
										</label>
										<p class="psb-set-desc">صفر = برای هر پیام یک اعلان. عدد ۵ برای بیشتر سایت‌ها مناسب است.</p>
									</div>
								</div>
							</div><div class="psb-set-card">
								<h2>ربات تلگرام</h2>
								<p class="psb-set-hint">
									۱) در تلگرام به <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> برو و با <code>/newbot</code> یک ربات بساز.<br />
									۲) توکن را اینجا بگذار.<br />
									۳) ربات را به گروه یا چت خودت اضافه کن، یک پیام بفرست، بعد <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> را باز کن و <code>chat.id</code> را بردار.
								</p>

								<?php
								$this->field( 'توکن ربات', 'notify_telegram_token', 'password', $opts['notify_telegram_token'], 'مثال: <code>123456789:AA...</code>' );
								$this->field( 'شناسهٔ چت', 'notify_telegram_chat', 'text', $opts['notify_telegram_chat'], 'مثال: <code>-1001234567890</code> برای گروه یا <code>123456789</code> برای چت شخصی.' );
								?>

								<div class="psb-set-row">
									<label for="eclf-notify_telegram_proxy">پروکسی</label>
									<div class="psb-set-ctl">
										<input type="text" class="regular-text" id="eclf-notify_telegram_proxy"
											name="gapineh_options[notify_telegram_proxy]"
											value="<?php echo esc_attr( $opts['notify_telegram_proxy'] ); ?>"
											placeholder="socks5://127.0.0.1:9050" autocomplete="off" dir="ltr" />
										<p class="psb-set-desc">
											اگر سرورت به <code>api.telegram.org</code> دسترسی ندارد، پروکسی را اینجا وارد کن.
											قالب‌های مجاز: <code>http://host:port</code> · <code>https://host:port</code> ·
											<code>socks5://host:port</code> · <code>socks5h://host:port</code><br />
											با کاربر و رمز: <code>http://user:pass@host:port</code><br />
											<code>socks5h</code> یعنی DNS هم از پروکسی رد می‌شود — برای سرورهای ایران معمولاً این بهتر است.
										</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label for="eclf-notify_telegram_relay">رلهٔ گوگل (عبور از فیلترینگ)</label>
									<div class="psb-set-ctl">
										<input type="url" class="large-text" id="eclf-notify_telegram_relay" dir="ltr"
											name="gapineh_options[notify_telegram_relay]"
											value="<?php echo esc_attr( $opts['notify_telegram_relay'] ); ?>"
											placeholder="https://script.google.com/macros/s/…/exec" autocomplete="off" />
										<p class="psb-set-desc">
											اگر سرور سایت ایران است و به <code>api.telegram.org</code> دسترسی ندارد،
											آدرس Web App اسکریپت گوگل را اینجا بگذار. پلاگین پیام را به گوگل می‌فرستد
											و گوگل آن را به تلگرام می‌رساند.<br />
											فقط دامنه‌های <code>script.google.com</code> و <code>*.google.com</code> پذیرفته می‌شوند.<br />
											<strong>اگر این فیلد پر باشد، پروکسی و آدرس پایه نادیده گرفته می‌شوند.</strong>
										</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label for="eclf-notify_telegram_secret">کلید مشترک رله</label>
									<div class="psb-set-ctl">
										<input type="password" class="regular-text" id="eclf-notify_telegram_secret" dir="ltr"
											name="gapineh_options[notify_telegram_secret]"
											value="<?php echo esc_attr( $opts['notify_telegram_secret'] ); ?>" autocomplete="off" />
										<p class="psb-set-desc">
											همان رشته‌ای که در <code>SECRET</code> اسکریپت گوگل گذاشتی. بدون آن،
											اسکریپت درخواست‌های ناشناس را رد می‌کند. اگر اسکریپت تو کلید ندارد، خالی بگذار.
										</p>
									</div>
								</div>

								<?php
								$this->field(
									'آدرس پایهٔ API تلگرام',
									'notify_telegram_base',
									'url',
									$opts['notify_telegram_base'],
									'اگر از یک mirror یا رلهٔ شخصی استفاده می‌کنی اینجا بگذار. پیش‌فرض: <code>https://api.telegram.org</code>'
								);
								?>

								<div class="psb-set-row">
									<label>آزمایش</label>
									<div class="psb-set-ctl">
										<?php
										// نتیجهٔ آخرین آزمون — از transient یک‌بارمصرف.
										$res = get_transient( 'gapineh_notify_test' );
										if ( is_array( $res ) && $res ) {
											delete_transient( 'gapineh_notify_test' );
										} else {
											// سازگاری با آدرس‌های قدیمی که نتیجه در query string بود
											// phpcs:disable WordPress.Security.NonceVerification
											$res = array();
											foreach ( array( 'email', 'telegram' ) as $ch ) {
												if ( isset( $_GET[ 'notify_' . $ch ] ) ) {
													$res[ $ch ] = sanitize_text_field( wp_unslash( $_GET[ 'notify_' . $ch ] ) );
												}
											}
											// phpcs:enable WordPress.Security.NonceVerification
										}
										if ( $res ) :
											?>
											<div class="psb-set-testres">
												<?php
												foreach ( $res as $ch => $v ) :
													// «ok»، «ok (relay)» و «ok (proxy)» همه موفق‌اند.
													// قبلاً فقط رشتهٔ دقیق 'ok' سبز می‌شد، پس ارسال موفق
													// از راه رله با ❌ نمایش داده می‌شد.
													$is_ok = ( 0 === strpos( (string) $v, 'ok' ) );
													?>
													<div class="<?php echo $is_ok ? 'ok' : 'bad'; ?>">
														<strong><?php echo 'email' === $ch ? '📧 ایمیل' : '🤖 تلگرام'; ?>:</strong>
														<?php echo $is_ok ? '✅ ارسال شد' : '❌ ' . esc_html( $v ); ?>
													</div>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>

										<p class="psb-set-desc">اول تنظیمات را ذخیره کن، بعد دکمهٔ آزمایش را بزن.</p>
										<button type="button" data-psb-form="psb-test-notify-form" class="button">🧪 ارسال پیام آزمایشی</button>
									</div>
								</div>
							</div><div class="psb-set-card">
								<h2>اعلان پیامکی</h2>
								<p class="psb-set-hint">سه سرویس رایج ایرانی پشتیبانی می‌شود. کلید API را از پنل همان سرویس بگیر.</p>

								<div class="psb-set-row">
									<label>فعال‌سازی</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[sms_enabled]" value="1" <?php checked( 1, $opts['sms_enabled'] ); ?> />
											<span></span> اعلان پیامکی فعال باشد
										</label>
									</div>
								</div>

								<?php $sms_res = get_transient( 'gapineh_sms_test' ); ?>
								<?php if ( is_array( $sms_res ) && ! empty( $sms_res['message'] ) ) : ?>
									<?php delete_transient( 'gapineh_sms_test' ); ?>
									<div class="psb-set-testres">
										<div class="<?php echo empty( $sms_res['ok'] ) ? 'bad' : 'ok'; ?>">
											<strong>📱 پیامک:</strong>
											<?php echo empty( $sms_res['ok'] ) ? '❌ ' : '✅ '; ?><?php echo esc_html( $sms_res['message'] ); ?>
										</div>
									</div>
								<?php endif; ?>

								<div class="psb-set-row">
									<label>سرویس</label>
									<div class="psb-set-ctl">
										<select name="gapineh_options[sms_provider]">
											<option value="kavenegar" <?php selected( $opts['sms_provider'], 'kavenegar' ); ?>>کاوه‌نگار</option>
											<option value="melipayamak" <?php selected( $opts['sms_provider'], 'melipayamak' ); ?>>ملی‌پیامک</option>
											<option value="smsir" <?php selected( $opts['sms_provider'], 'smsir' ); ?>>SMS.ir</option>
										</select>
									</div>
								</div>

								<?php
								$this->field( 'کلید API', 'sms_api_key', 'password', $opts['sms_api_key'], 'از پنل سرویس پیامک کپی کن. فقط روی سرور ذخیره می‌شود.' );
								$this->field( 'شمارهٔ فرستنده', 'sms_sender', 'text', $opts['sms_sender'], 'همان line number که در پنل سرویس ساختی.' );
								$this->field( 'موبایل مدیر', 'sms_admin_phone', 'text', $opts['sms_admin_phone'], 'باید با <code>09</code> شروع شود.' );
								?>

								<div class="psb-set-row">
									<label>چه زمانی پیامک برود</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[sms_notify_admin]" value="1" <?php checked( 1, $opts['sms_notify_admin'] ); ?> />
											<span></span> وقتی بازدیدکننده پیام داد، به مدیر پیامک برود
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[sms_notify_visitor]" value="1" <?php checked( 1, $opts['sms_notify_visitor'] ); ?> />
											<span></span> وقتی اپراتور پاسخ داد، به بازدیدکننده پیامک برود
										</label>
										<label class="psb-set-mini" style="margin-top:10px">حداقل فاصلهٔ دو پیامک
											<input type="number" min="0" max="1440" name="gapineh_options[sms_min_gap]" value="<?php echo (int) $opts['sms_min_gap']; ?>" />
											<span>دقیقه</span>
										</label>
										<p class="psb-set-desc">
											<strong>پیامک پول نقد است.</strong> برای جلوگیری از هزینهٔ اتفاقی، علاوه بر این فاصله،
											سقف <?php echo class_exists( 'Gapineh_SMS' ) ? (int) Gapineh_SMS::MAX_PER_SESSION : 3; ?> پیامک در ساعت برای هر مکالمه
											و <?php echo class_exists( 'Gapineh_SMS' ) ? (int) Gapineh_SMS::MAX_PER_HOUR : 30; ?> پیامک در ساعت برای کل سایت
											به‌صورت سخت‌کد اعمال می‌شود. این سقف‌ها با <code>rate_limit</code> چت فرق دارند.
											<br />پیامک به بازدیدکننده فقط وقتی می‌رود که شماره‌اش را در فرم پیش‌گفتگو داده باشد.
										</p>
										<button type="button" data-psb-form="psb-sms-test-form" class="button">🧪 ارسال پیامک آزمایشی</button>
									</div>
								</div>
							<div class="psb-set-card" id="card-bale">
								<?php $bale_note = get_transient( 'gapineh_bale_notice' ); ?>
								<?php if ( is_array( $bale_note ) && ! empty( $bale_note['text'] ) ) : ?>
									<?php delete_transient( 'gapineh_bale_notice' ); ?>
									<div class="psb-set-testres">
										<div class="<?php echo empty( $bale_note['ok'] ) ? 'bad' : 'ok'; ?>">
											<strong>💚 بله:</strong>
											<?php echo empty( $bale_note['ok'] ) ? '❌ ' : '✅ '; ?><?php echo esc_html( $bale_note['text'] ); ?>
										</div>
									</div>
								<?php endif; ?>

								<h2>کانال بله</h2>
								<p class="psb-set-hint">
									تلگرام فیلتر است و واتساپ ناپایدار. از پیام‌رسان‌های داخلی فقط <strong>بله</strong> API کامل دارد
									(ایتا فقط ارسال دارد و راهی برای دریافت پیام ندارد، روبیکا API رسمی ندارد).
									API بله بر پایهٔ همان Bot API تلگرام است.
								</p>

								<div class="psb-set-row">
									<label>فعال‌سازی</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[bale_enabled]" value="1" <?php checked( 1, $opts['bale_enabled'] ); ?> />
											<span></span> کانال بله فعال باشد
										</label>
									</div>
								</div>

								<?php
								$this->field( 'توکن بازو', 'bale_token', 'password', $opts['bale_token'], 'از <code>@botfather</code> در بله (<code>ble.ir/botfather</code>) بگیر. فرمت: <code>123456789:abcd…</code>' );
								$this->field( 'شناسهٔ گفتگو/گروه', 'bale_chat', 'text', $opts['bale_chat'], 'شناسهٔ عددی گروه پشتیبانی، یا <code>@username</code> آن.' );
								?>

								<div class="psb-set-row">
									<label>حالت کار</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[bale_notify]" value="1" <?php checked( 1, $opts['bale_notify'] ); ?> />
											<span></span> اعلان یک‌طرفه: پیام بازدیدکننده به گروه برود
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[bale_twoway]" value="1" <?php checked( 1, $opts['bale_twoway'] ); ?> />
											<span></span> پاسخ دوطرفه: اپراتور از داخل بله reply بزند و پاسخ در چت سایت بنشیند
										</label>
										<p class="psb-set-desc">
											اعلان فقط وقتی می‌رود که مکالمه در حالت <strong>اپراتور</strong> باشد — وگرنه برای هر پیامی که
											هوش مصنوعی جواب می‌دهد هم به گروه پیام می‌رفت.
										</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>webhook</label>
									<div class="psb-set-ctl">
										<?php $wh = class_exists( 'Gapineh_Bale' ) ? Gapineh_Bale::webhook_url() : ''; ?>
										<?php if ( '' !== $wh ) : ?>
											<p class="psb-set-desc">
												آدرس: <code><?php echo esc_html( $wh ); ?></code>
											</p>
										<?php endif; ?>
										<p class="psb-set-desc">
											بله فقط پورت‌های <strong>۴۴۳</strong> و <strong>۸۸</strong> را برای webhook می‌پذیرد.
											رمز webhook خودکار ساخته شده و در خود آدرس است — اگر لو رفت، تنظیمات را ذخیره کن تا عوض شود.
										</p>
										<button type="button" data-psb-form="psb-bale-set-form" class="button button-primary">ثبت webhook</button>
										<button type="button" data-psb-form="psb-bale-info-form" class="button">وضعیت فعلی</button>
										<button type="button" data-psb-form="psb-bale-del-form" class="button">لغو webhook</button>
										<button type="button" data-psb-form="psb-bale-test-form" class="button">🧪 ارسال پیام آزمایشی</button>
									</div>
								</div>
							</div>
							</div>						</section>

						<!-- ════════ تیم ════════ -->
						<section class="psb-set-panel" data-panel="team" data-title="تیم">
<div class="psb-set-card">
								<h2>تیم پشتیبانی</h2>
								<p class="psb-set-hint">اپراتورها کاربر وردپرس‌اند با نقش اختصاصی «اپراتور گپینه» — نه یک سیستم کاربری جدا.</p>

								<div class="psb-set-row">
									<label>افزودن اپراتور</label>
									<div class="psb-set-ctl">
										<p class="psb-set-desc">
											به <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>">کاربران ← افزودن</a> برو و نقش
											«<strong><?php esc_html_e( 'اپراتور گپینه', 'gapineh' ); ?></strong>» را انتخاب کن.
											آن نقش فقط به اینباکس چت دسترسی دارد، نه به تنظیمات سایت.
										</p>
										<p class="psb-set-desc">
											اپراتورهای فعلی:
											<?php
											$ags = Gapineh_Team::agents();
											if ( empty( $ags ) ) {
												esc_html_e( 'هنوز اپراتوری ثبت نشده.', 'gapineh' );
											} else {
												echo esc_html( implode( '، ', wp_list_pluck( $ags, 'name' ) ) );
											}
											?>
										</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>واگذاری</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[team_auto_assign]" value="1" <?php checked( 1, $opts['team_auto_assign'] ); ?> />
											<span></span> وقتی مشتری «گپ با پشتیبان» می‌زند، مکالمه به‌صورت نوبتی به یک اپراتور واگذار شود
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[team_restrict]" value="1" <?php checked( 1, $opts['team_restrict'] ); ?> />
											<span></span> اپراتور فقط مکالمات خودش و واگذارنشده‌ها را ببیند
										</label>
										<p class="psb-set-desc">
											مدیر همیشه همهٔ مکالمات را می‌بیند. این محدودیت <strong>سمت سرور</strong> اعمال می‌شود،
											پس با دستکاری پارامتر درخواست قابل دور زدن نیست.
										</p>
									</div>
								</div>
							</div><div class="psb-set-card">
								<h2>ساعت کاری</h2>
								<p class="psb-set-hint">بیرون از این ساعت، اپراتور «آفلاین» نشان داده می‌شود و درخواست پشتیبان پیام مناسب می‌گیرد.</p>

								<div class="psb-set-row">
									<label>فعال‌سازی</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[hours_enabled]" value="1" <?php checked( 1, $opts['hours_enabled'] ); ?> />
											<span></span> ساعت کاری تعیین شده باشد
										</label>
										<br />
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[hours_ai_outside]" value="1" <?php checked( 1, $opts['hours_ai_outside'] ); ?> />
											<span></span> بیرون از ساعت کاری هم هوش مصنوعی پاسخ بدهد
										</label>
									</div>
								</div>

								<div class="psb-set-row">
									<label>روزها</label>
									<div class="psb-set-ctl">
										<?php $days_on = Gapineh_Hours::parse_days( $opts['hours_days'] ); ?>
										<?php foreach ( Gapineh_Hours::day_names() as $di => $dn ) : ?>
											<label class="psb-day">
												<input type="checkbox" name="gapineh_options[hours_days_list][]" value="<?php echo (int) $di; ?>" <?php checked( true, in_array( $di, $days_on, true ) ); ?> />
												<span><?php echo esc_html( $dn ); ?></span>
											</label>
										<?php endforeach; ?>
										<input type="hidden" name="gapineh_options[hours_days]" id="eclf-hours-days" value="<?php echo esc_attr( $opts['hours_days'] ); ?>" />
										<p class="psb-set-desc">منطقهٔ زمانی از تنظیمات وردپرس خوانده می‌شود، نه از ساعت سرور.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>بازه</label>
									<div class="psb-set-ctl psb-set-inline">
										<label class="psb-set-mini">از
											<input type="time" name="gapineh_options[hours_from]" value="<?php echo esc_attr( $opts['hours_from'] ); ?>" />
										</label>
										<label class="psb-set-mini">تا
											<input type="time" name="gapineh_options[hours_to]" value="<?php echo esc_attr( $opts['hours_to'] ); ?>" />
										</label>
										<p class="psb-set-desc">بازهٔ شبانه هم مجاز است (مثلاً ۲۲:۰۰ تا ۰۲:۰۰).</p>
									</div>
								</div>

								<?php $this->field( 'پیام بیرون از ساعت کاری', 'hours_offline_msg', 'text', $opts['hours_offline_msg'], 'خالی بگذاری پیام پیش‌فرض به‌همراه زمان بازگشایی نشان داده می‌شود.', 'large-text' ); ?>
							</div>						</section>

						<!-- ════════ امنیت و داده ════════ -->
						<section class="psb-set-panel" data-panel="sec" data-title="امنیت و داده">
<div class="psb-set-card">
								<h2>امنیت و کارایی</h2>
								<p class="psb-set-hint">اندپوینت‌های چت برای عموم باز هستند؛ تنها محافظ، محدودسازی نرخ درخواست بر اساس IP است.</p>

								<div class="psb-set-row">
									<label>فونت وزیرمتن</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[cdn_font]" value="1" <?php checked( 1, $opts['cdn_font'] ); ?> />
											<span></span> بارگذاری وزیرمتن از CDN (jsdelivr)
										</label>
										<p class="psb-set-desc">اگر خاموش باشد از فونت سیستم کاربر استفاده می‌شود (پیش‌فرض همین). برای بازدیدکنندۀ ایرانی که به <code>cdn.jsdelivr.net</code> دسترسی کند دارد، خاموش بودن سریع‌تر است و رندر را بلوکه نمی‌کند.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>نگهداری داده</label>
									<div class="psb-set-ctl psb-set-inline">
										<label class="psb-set-mini">حذف خودکار مکالمات بستهٔ قدیمی‌تر از
											<input type="number" min="0" max="3650" name="gapineh_options[retention_days]" value="<?php echo (int) $opts['retention_days']; ?>" /> روز
										</label>
										<p class="psb-set-desc">پیش‌فرض ۳۶۵ روز. صفر = نگهداری همیشگی. پاک‌سازی روزانه با WP-Cron انجام می‌شود و مخاطبین دارای شمارهٔ تماس، هرگز خودکار پاک نمی‌شوند.</p>
									</div>
								</div>

								<div class="psb-set-row">
									<label>محدودیت فراخوانی</label>
									<div class="psb-set-ctl psb-set-inline">
										<label class="psb-set-mini">درخواست در دقیقه به‌ازای هر IP
											<input type="number" min="0" max="200" name="gapineh_options[rate_limit]" value="<?php echo (int) $opts['rate_limit']; ?>" />
										</label>
										<p class="psb-set-desc">صفر = بدون محدودیت. برای سایت شلوغ عدد ۱۰ تا ۲۰ منطقی است.</p>
									</div>
								</div>
								<div class="psb-set-row">
									<label>حذف داده</label>
									<div class="psb-set-ctl">
										<label class="psb-set-switch">
											<input type="checkbox" name="gapineh_options[uninstall_delete_data]" value="1" <?php checked( 1, $opts['uninstall_delete_data'] ); ?> />
											<span></span> <strong>حذف داده‌ها هنگام حذف پلاگین</strong>
										</label>
										<ul class="psb-set-modes">
											<li><span class="is-on">روشن</span> با «حذف» افزونه، مکالمات، پیام‌ها، مخاطبین، ایندکس دانش و فایل‌های آپلودشده پاک می‌شوند.</li>
											<li><span class="is-off">خاموش</span> هیچ داده‌ای پاک نمی‌شود؛ با نصب دوباره، همه‌چیز سرِ جایش برمی‌گردد.</li>
										</ul>
										<p class="psb-set-desc">پیش‌فرض این گزینه <strong>خاموش</strong> است؛ یعنی حذف افزونه مکالمات، مخاطبین، ایندکس دانش و تنظیمات را پاک نمی‌کند و با نصب دوباره همه‌چیز سرِ جایش برمی‌گردد. روشن‌کردنش برای سایتی که می‌خواهی کلاً پاک‌سازی شود درست است؛ اگر روشنش کنی، اول خروجی JSON بگیر.</p>
										<p class="psb-set-warn">⚠️ پیش از حذف افزونه بکاپ بگیر: «امنیت و داده ← صادر کردن تنظیمات» و یک پشتیبان معمولی از پایگاه‌داده. این کار برگشت‌پذیر نیست.</p>
									</div>
								</div>

							</div><div class="psb-set-card">
								<h2>وضعیت سیستم</h2>
								<table class="psb-set-diag">
									<tr><td>جدول نشست‌ها</td><td><code><?php echo esc_html( $diag['sessions_table'] ); ?></code></td><td><?php echo $diag['tables'] ? '✅' : '❌'; ?></td></tr>
									<?php
									// این مقایسه قبلاً روی رشتهٔ ثابت '1.3.0' بود؛ از وقتی
									// ساختار جداول به ۱.۸.۰ رسیده، این سطر برای همهٔ
									// کاربران «⚠» نشان می‌داد — حتی وقتی همه‌چیز سالم بود.
									$schema_ok = class_exists( 'Gapineh_DB' )
										&& (string) $diag['db_version'] === Gapineh_DB::SCHEMA_VERSION;
									?>
									<tr><td>نسخهٔ ساختار</td><td><code><?php echo esc_html( $diag['db_version'] ); ?></code></td><td><?php echo $schema_ok ? '✅' : '⚠'; ?></td></tr>
									<tr><td>نسخهٔ پلاگین</td><td><code><?php echo esc_html( GAPINEH_VERSION ); ?></code></td><td>✅</td></tr>
									<tr><td>کلید API</td><td><code><?php echo $opts['api_key'] ? 'تنظیم شده' : 'تنظیم نشده'; ?></code></td><td><?php echo $opts['api_key'] ? '✅' : '⚠'; ?></td></tr>
									<tr><td>مسیرهای REST</td><td colspan="2"><?php echo $diag['routes'] ? '✅ ثبت شده' : '⚠ در این زمینه قابل بررسی نیست'; ?></td></tr>
								</table>
								<p class="psb-set-desc">تشخیص کامل‌تر (آزمون نوشتن/خواندن و آخرین خطای MySQL) در صفحهٔ <a href="<?php echo esc_url( admin_url( 'admin.php?page=gapineh-inbox' ) ); ?>">گفتگوهای آنلاین</a> هست.</p>
							</div>

						<div class="psb-set-card" id="card-backup">
							<h2>صادر و وارد کردن تنظیمات</h2>
							<?php
							$tools_note = get_transient( 'gapineh_tools_notice' );
							if ( is_array( $tools_note ) && ! empty( $tools_note['text'] ) ) {
								delete_transient( 'gapineh_tools_notice' );
								echo '<p class="psb-set-desc psb-rag-note psb-rag-note--' . esc_attr( empty( $tools_note['ok'] ) ? 'error' : 'success' ) . '">'
									. ( empty( $tools_note['ok'] ) ? '❌ ' : '✅ ' ) . esc_html( $tools_note['text'] ) . '</p>';
							}
							?>
							<p class="psb-set-hint">اگر همین پلاگین را روی چند سایت نصب می‌کنی، تنظیمات را یک‌بار صادر کن و در سایت دیگر وارد کن.</p>
							<p class="psb-set-desc"><strong>کلید API هم در فایل هست</strong> — فایل را جای امن نگه دار.</p>
							<div class="psb-set-row">
								<label>پشتیبان‌گیری</label>
								<div class="psb-set-ctl">
									<button type="button" data-psb-form="psb-export-form" class="button">⬇️ صادر کردن تنظیمات</button>
									<label class="button" style="display:inline-block;margin-inline-start:6px">
									⬆️ وارد کردن
									<input type="file" id="psb-import-file" accept="application/json,.json" style="display:none" />
									</label>
								</div>
							</div>
						</div>
						</section>

						<!-- ════════ بررسی سلامت ════════ -->
						<section class="psb-set-panel" data-panel="health" data-title="بررسی سلامت">
							<div class="psb-set-card">
								<h2>بررسی سلامت</h2>
								<p class="psb-set-hint">
									گپینه به شش سرویس بیرونی وصل می‌شود. وقتی یکی از کار بیفتد،
									تنها نشانه‌اش «پاسخ دریافت نشد» است. این صفحه هر مسیر را
									<strong>جدا و با فراخوانی واقعی</strong> می‌آزماید و می‌گوید علت کجاست.
								</p>

								<div class="psb-hc-bar">
									<button type="button" class="button button-primary" id="psb-hc-run">▶ اجرای همهٔ بررسی‌ها</button>
									<button type="button" class="button" id="psb-hc-copy" disabled>📋 کپی گزارش</button>
									<span class="psb-hc-count" id="psb-hc-count"></span>
								</div>

								<p class="psb-set-desc psb-hc-note">
									این صفحه هیچ هزینه‌ای برای شما نمی‌تراشد: پیامک واقعی نمی‌فرستد،
									پیامی به گروه تلگرام یا بله نمی‌دهد، و ایمیل آزمایشی ارسال نمی‌کند.
									تنها هزینهٔ واقعی، یک درخواست تک‌توکنی به سرویس هوش مصنوعی و یک
									بردار آزمایشی است.
								</p>

								<div id="psb-hc-groups">
									<?php
									$hc_groups = Gapineh_Health::groups();
									$hc_reg    = Gapineh_Health::registry();
									foreach ( $hc_groups as $gid => $g ) :
										?>
										<div class="psb-hc-group" data-group="<?php echo esc_attr( $gid ); ?>">
											<div class="psb-hc-ghead">
												<span class="psb-hc-gicon"><?php echo esc_html( $g['icon'] ); ?></span>
												<span class="psb-hc-glabel"><?php echo esc_html( $g['label'] ); ?></span>
												<button type="button" class="psb-hc-grun" data-group="<?php echo esc_attr( $gid ); ?>">اجرای این بخش</button>
											</div>
											<ul class="psb-hc-list">
												<?php
												foreach ( $hc_reg as $hid => $item ) :
													if ( $item['group'] !== $gid ) {
														continue;
													}
													?>
													<li class="psb-hc-item" data-check="<?php echo esc_attr( $hid ); ?>">
														<span class="psb-hc-dot" aria-hidden="true"></span>
														<span class="psb-hc-label"><?php echo esc_html( $item['label'] ); ?></span>
														<span class="psb-hc-msg"><?php esc_html_e( 'هنوز اجرا نشده', 'gapineh' ); ?></span>
														<span class="psb-hc-ms"></span>
													</li>
													<?php
												endforeach;
												?>
											</ul>
										</div>
									<?php endforeach; ?>
								</div>

								<h2>گزارش متنی</h2>
								<p class="psb-set-desc">
									اگر مشکلی پیدا شد، این گزارش را کپی کنید و در تیکت پشتیبانی بفرستید —
									کلیدهای API در آن پوشانده شده‌اند.
								</p>
								<textarea id="psb-hc-report" class="psb-hc-report" rows="14" readonly
									placeholder="<?php esc_attr_e( 'پس از اجرای بررسی‌ها، گزارش اینجا ساخته می‌شود.', 'gapineh' ); ?>"></textarea>
							</div>
						</section>


					</div>
				</div>

<div class="psb-set-footer" id="psb-set-submit">
		<span class="psb-set-credit">گپینه — محصول <a href="https://etehadwp.com" target="_blank" rel="noopener">اتحاد وردپرس</a> · <a href="https://etehadwp.com/gapineh" target="_blank" rel="noopener">etehadwp.com/gapineh</a></span>
	</div>
			</form>

			<!--
				فرم آزمایش اعلان عمداً بیرون از فرم تنظیمات است.
				HTML فرم تو‌در‌تو را قبول ندارد: اگر این فرم‌ها داخل فرم تنظیمات بمانند،
				برچسب بسته‌شدنِ فرمِ مقصد، فرم تنظیمات را زود می‌بندد و دکمهٔ
				«ذخیرهٔ تنظیمات» بیرون فرم می‌افتد و دیگر کار نمی‌کند.
				به همین دلیل دکمه‌ها هم type=button‌اند و JS فرم مقصد را submit می‌کند.
			-->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-test-notify-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_test_notify" />
				<?php wp_nonce_field( 'gapineh_test_notify' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-rag-build-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_rag_build" />
				<?php wp_nonce_field( 'gapineh_rag_build' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-rag-test-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_rag_test" />
				<?php wp_nonce_field( 'gapineh_rag_test' ); ?>
			</form>
			<div class="psb-set-savebar" id="psb-set-savebar">
				<span class="psb-set-savebar-msg" id="psb-set-savebar-msg">
					<span class="dot-clean">✓</span> <?php esc_html_e( 'همه‌چیز ذخیره شده', 'gapineh' ); ?>
					<span class="dot-dirty">● <?php esc_html_e( 'تغییرات ذخیره‌نشده داری', 'gapineh' ); ?></span>
					<span class="psb-set-savebar-hint"><?php esc_html_e( 'این دکمه تنظیماتِ ذخیره‌شده را به کار می‌گیرد؛ اول «ذخیرهٔ تنظیمات» را بزن.', 'gapineh' ); ?></span>
				</span>
				<button type="button" class="button button-primary" id="psb-set-save-bar"><?php esc_html_e( 'ذخیرهٔ تنظیمات', 'gapineh' ); ?></button>
			</div>


			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-bale-set-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_bale_webhook" />
				<input type="hidden" name="do" value="set" />
				<?php wp_nonce_field( 'gapineh_bale_webhook' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-bale-info-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_bale_webhook" />
				<input type="hidden" name="do" value="info" />
				<?php wp_nonce_field( 'gapineh_bale_webhook' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-bale-del-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_bale_webhook" />
				<input type="hidden" name="do" value="delete" />
				<?php wp_nonce_field( 'gapineh_bale_webhook' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-bale-test-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_bale_test" />
				<?php wp_nonce_field( 'gapineh_bale_test' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-export-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_export_settings" />
				<?php wp_nonce_field( 'gapineh_export_settings' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-import-form" class="psb-hidden-form" enctype="multipart/form-data">
				<input type="hidden" name="action" value="gapineh_import_settings" />
				<input type="file" name="settings_file" id="psb-import-input" accept="application/json,.json" />
				<?php wp_nonce_field( 'gapineh_import_settings' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-sms-test-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_test_sms" />
				<?php wp_nonce_field( 'gapineh_test_sms' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psb-rag-clear-form" class="psb-hidden-form">
				<input type="hidden" name="action" value="gapineh_rag_clear" />
				<?php wp_nonce_field( 'gapineh_rag_clear' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * پاک‌سازی و اعتبارسنجی آدرس پروکسی.
	 *
	 * قالب‌های مجاز:
	 *   http://host:port
	 *   https://host:port
	 *   socks5://host:port
	 *   socks5h://host:port   (DNS هم از پروکسی رد می‌شود)
	 * به‌همراه کاربر/رمز اختیاری: http://user:pass@host:port
	 *
	 * @param string $proxy
	 * @return string رشتهٔ پاک‌شده یا رشتهٔ خالی اگر نامعتبر بود
	 */
	/* =========================================================
	 * ابزارها: صادر / وارد کردن تنظیمات
	 * =======================================================*/

	/**
	 * کلیدهایی که در خروجی JSON نمی‌آیند.
	 *
	 * notify_telegram_secret عمداً هست (برای انتقال بین سایت لازم است)
	 * ولی در پیام هشدار به کاربر گفته می‌شود که فایل را امن نگه دارد.
	 *
	 * @return array
	 */
	private static function export_keys() {
		return array_keys( self::instance()->defaults() );
	}

	/**
	 * صادر کردن تنظیمات به JSON.
	 */
	public function export_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'gapineh' ) );
		}
		check_admin_referer( 'gapineh_export_settings' );

		$opts = $this->get_options();
		$out  = array(
			'_meta' => array(
				'plugin'      => 'gapineh',
				'version'     => defined( 'GAPINEH_VERSION' ) ? GAPINEH_VERSION : '',
				'exported_at' => gmdate( 'Y-m-d H:i:s' ),
				'site'        => function_exists( 'home_url' ) ? home_url( '/' ) : '',
				'note'        => 'این فایل شامل کلید API است. جای امن نگهش دار.',
			),
		);

		foreach ( self::export_keys() as $k ) {
			if ( array_key_exists( $k, $opts ) ) {
				$out[ $k ] = $opts[ $k ];
			}
		}

		$json = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gapineh-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * وارد کردن تنظیمات از JSON.
	 *
	 * از همان sanitize خود پلاگین رد می‌شود، پس یک فایل دست‌کاری‌شده
	 * نمی‌تواند مقدار نامعتبر (مثلاً آدرس رلهٔ ناشناس) تزریق کند.
	 */
	public function import_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'gapineh' ) );
		}
		check_admin_referer( 'gapineh_import_settings' );

		$msg = array( 'ok' => false, 'text' => '' );

		if ( empty( $_FILES['settings_file']['tmp_name'] ) ) {
			$msg['text'] = __( 'فایلی انتخاب نشد.', 'gapineh' );
		} else {
			$raw = (string) file_get_contents( $_FILES['settings_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$data = json_decode( $raw, true );

			if ( ! is_array( $data ) ) {
				$msg['text'] = __( 'فایل JSON معتبر نیست.', 'gapineh' );
			} elseif ( empty( $data['_meta']['plugin'] ) || 'gapineh' !== $data['_meta']['plugin'] ) {
				$msg['text'] = __( 'این فایل تنظیمات گپینه نیست.', 'gapineh' );
			} else {
				unset( $data['_meta'] );

				// فقط کلیدهایی که خودمان می‌شناسیم — کلید ناشناخته دور ریخته می‌شود
				$known = array();
				foreach ( self::export_keys() as $k ) {
					if ( array_key_exists( $k, $data ) ) {
						$known[ $k ] = $data[ $k ];
					}
				}

				// ★ از sanitize خود پلاگین رد می‌شود، نه ذخیرهٔ خام
				$clean = $this->sanitize( $known );
				update_option( $this->option_key, $clean );

				$msg['ok']   = true;
				$msg['text'] = sprintf(
					/* translators: %d: number of imported settings */
					__( '%d تنظیم با موفقیت وارد شد.', 'gapineh' ),
					count( $clean )
				);
			}
		}

		set_transient( 'gapineh_tools_notice', $msg, 2 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=gapineh#sec' ) );
		exit;
	}

	/**
	 * برچسب خوانای ناحیهٔ زمانی فعلی سایت.
	 *
	 * چرا نمایشش می‌دهیم: ساعت کاری و «چقدر پیش»ها هر دو به این وابسته‌اند.
	 * اگر سرور روی UTC باشد و صاحب سایت در تهران، ساعت کاری هشت ساعت
	 * جابه‌جا کار می‌کند و هیچ خطایی هم نشان داده نمی‌شود. پس بهتر است
	 * همین‌جا جلوی چشم باشد.
	 *
	 * @return string
	 */
	public static function current_timezone_label() {
		if ( function_exists( 'wp_timezone_string' ) ) {
			$tz = (string) wp_timezone_string();
			if ( '' !== $tz ) {
				return $tz;
			}
		}
		$off = (float) get_option( 'gmt_offset', 0 );
		if ( 0.0 === $off ) {
			return 'UTC';
		}
		$sign = $off > 0 ? '+' : '-';
		$abs  = abs( $off );
		$h    = (int) $abs;
		$m    = (int) round( ( $abs - $h ) * 60 );
		return sprintf( 'UTC%s%d:%02d', $sign, $h, $m );
	}

	public static function sanitize_proxy( $proxy ) {
		$proxy = trim( (string) $proxy );
		if ( '' === $proxy ) {
			return '';
		}

		$parts = wp_parse_url( $proxy );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return '';
		}

		$allowed = array( 'http', 'https', 'socks5', 'socks5h' );
		$scheme  = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		if ( ! in_array( $scheme, $allowed, true ) ) {
			return '';
		}

		// parse_url چیزهایی مثل <script> را هم به‌عنوان host می‌پذیرد،
		// پس نام میزبان را جداگانه اعتبارسنجی می‌کنیم.
		if ( 1 !== preg_match( '/^[a-zA-Z0-9._\-]+$/', $parts['host'] ) ) {
			return '';
		}

		$out = $scheme . '://';
		if ( ! empty( $parts['user'] ) ) {
			$out .= rawurlencode( $parts['user'] );
			if ( ! empty( $parts['pass'] ) ) {
				$out .= ':' . rawurlencode( $parts['pass'] );
			}
			$out .= '@';
		}
		$out .= $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( $port > 0 && $port < 65536 ) {
				$out .= ':' . $port;
			}
		}

		return $out;
	}

	/**
	 * یک ردیف فیلد ساده.
	 *
	 * @param string $label
	 * @param string $key
	 * @param string $type
	 * @param string $value
	 * @param string $desc
	 * @param string $class
	 */
	private function field( $label, $key, $type, $value, $desc = '', $class = 'regular-text' ) {
		?>
		<div class="psb-set-row">
			<label for="eclf-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="psb-set-ctl">
				<input type="<?php echo esc_attr( $type ); ?>" class="<?php echo esc_attr( $class ); ?>" id="eclf-<?php echo esc_attr( $key ); ?>"
					name="gapineh_options[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"
					<?php echo 'password' === $type ? 'autocomplete="off"' : ''; ?> />
				<?php if ( $desc ) : ?><p class="psb-set-desc"><?php echo wp_kses_post( $desc ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * فیلد عددی کوچک.
	 *
	 * @param string $label
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $min
	 * @param int    $max
	 * @param string $unit
	 */
	private function mini( $label, $key, $value, $min, $max, $unit = '' ) {
		?>
		<label class="psb-set-mini"><?php echo esc_html( $label ); ?>
			<input type="number" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>"
				name="gapineh_options[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php if ( $unit ) : ?><span><?php echo esc_html( $unit ); ?></span><?php endif; ?>
		</label>
		<?php
	}

	/**
	 * کلید روشن/خاموش.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param string $label
	 */
	private function toggle( $key, $value, $label ) {
		?>
		<label class="psb-set-switch">
			<input type="checkbox" name="gapineh_options[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( 1, $value ); ?> />
			<span></span> <?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	/**
	 * تشخیص سریع برای نوار وضعیت صفحهٔ تنظیمات.
	 *
	 * @return array
	 */
	private function quick_diag() {
		$opts   = $this->get_options();
		$tables = class_exists( 'Gapineh_DB' ) ? Gapineh_DB::tables_exist() : false;
		$routes = function_exists( 'rest_get_server' )
			? (bool) rest_get_server()->get_routes()
			: false;

		$msg = '';
		if ( empty( $opts['api_key'] ) ) {
			$msg = __( 'کلید API تنظیم نشده — دستیار پاسخ نمی‌دهد.', 'gapineh' );
		} elseif ( ! $tables ) {
			$msg = __( 'جدول‌های دیتابیس ساخته نشده‌اند — گفتگوها ذخیره نمی‌شوند.', 'gapineh' );
		}

		return array(
			'ok'             => ( '' === $msg ),
			'msg'            => $msg,
			'tables'         => $tables,
			'routes'         => $routes,
			'sessions_table' => class_exists( 'Gapineh_DB' ) ? Gapineh_DB::sessions_table() : '—',
			'db_version'     => (string) get_option( 'gapineh_db_version', '—' ),
		);
	}
}
