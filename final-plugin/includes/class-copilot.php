<?php
/**
 * دستیار اپراتور (Agent Copilot) — پاسخ‌بان.
 *
 * در اینباکس، اپراتور انسانی پیام و فایل بازدیدکننده را می‌بیند ولی باید
 * همهٔ پاسخ را خودش تایپ کند. اینجا هوش مصنوعی یک «پیش‌نویس» می‌سازد که
 * اپراتور ویرایش و ارسال می‌کند.
 *
 * سه اصل که عمداً رعایت شده‌اند:
 *
 * ۱) پیش‌نویس هرگز به‌صورت خودکار ارسال نمی‌شود و در دیتابیس ذخیره
 *    نمی‌شود. فقط داخل کادر ورودی می‌نشیند. اپراتور مسئول نهایی است.
 *
 * ۲) حالت نشست عوض نمی‌شود و اعلانی برای بازدیدکننده نمی‌رود. تا وقتی
 *    اپراتور واقعاً «ارسال» را نزده، از بیرون هیچ‌چیز تغییر نکرده.
 *
 * ۳) در prompt صراحتاً نهی شده که وانمود کند کاری انجام شده («واریز شد»،
 *    «ارسال شد»). پیش‌نویسی که دروغ بگوید از تایپ‌نکردن بدتر است.
 *
 * @package Pasokhban
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس دستیار اپراتور.
 */
final class Pasokhban_Copilot {

	/** حداقل فاصلهٔ دو درخواست پیش‌نویس برای یک نشست (ثانیه). */
	const THROTTLE = 3;

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$routes = array(
			'/admin/suggest'   => 'handle_suggest',
			'/admin/summarize' => 'handle_summarize',
		);

		foreach ( $routes as $route => $cb ) {
			register_rest_route(
				'pasokhban/v1',
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $cb ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				)
			);
		}
	}

	public static function permission() {
		// اپراتور هم باید بتواند از پیش‌نویس استفاده کند؛ وگرنه کل
		// فایدهٔ این قابلیت برای تیم‌های پشتیبانی از بین می‌رفت.
		return Pasokhban_Team::can_access();
	}

	/* =========================================================
	 * اندپوینت‌ها
	 * =======================================================*/

	/**
	 * ساخت پیش‌نویس پاسخ.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_suggest( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['copilot_enabled'] ) ) {
			return new WP_Error(
				'pasokhban_copilot_off',
				__( 'دستیار اپراتور غیرفعال است.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		$loaded = self::load( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$session  = $loaded['session'];
		$messages = $loaded['messages'];

		$tone  = self::clean_tone( (string) $request->get_param( 'tone' ), $opts );
		$limit = max( 2, min( 40, (int) $opts['copilot_max_msgs'] ) );

		$transcript = self::transcript( $messages, $limit );
		if ( '' === $transcript['text'] ) {
			return new WP_Error(
				'pasokhban_empty',
				__( 'پیامی برای پاسخ دادن در این مکالمه نیست.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		// محدودسازی نرخ — اپراتور ممکن است پشت‌سرهم کلیک کند و هر کلیک
		// یک فراخوانی کامل مدل است.
		$tkey = 'pasokhban_copilot_' . (int) $session->id;
		if ( get_transient( $tkey ) ) {
			return new WP_Error(
				'pasokhban_throttle',
				sprintf(
					/* translators: %d: seconds */
					__( 'کمی صبر کن (هر %d ثانیه یک پیش‌نویس).', 'pasokhban' ),
					self::THROTTLE
				),
				array( 'status' => 429 )
			);
		}

		// محتوای مرتبط از سایت — همان موتور RAG که چت استفاده می‌کند،
		// پس پیش‌نویس می‌تواند به صفحه‌های واقعی سایت ارجاع بدهد.
		$context = '';
		$sources = array();
		if ( ! empty( $opts['knowledge'] ) && '' !== $transcript['last_visitor'] ) {
			$docs = Pasokhban_API::instance()->get_site_docs( $transcript['last_visitor'] );
			if ( ! empty( $docs['context'] ) ) {
				$context = $docs['context'];
				$sources = isset( $docs['sources'] ) ? $docs['sources'] : array();
			}
		}

		$system = self::system_prompt( $tone );
		$user   = self::user_prompt( $session, $transcript, $context );

		set_transient( $tkey, 1, self::THROTTLE );

		$text = Pasokhban_API::instance()->raw_complete( $system, $user, 400 );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text = self::clean_draft( $text );
		if ( '' === $text ) {
			return new WP_Error(
				'pasokhban_empty_draft',
				__( 'مدل پاسخی تولید نکرد. دوباره تلاش کن.', 'pasokhban' ),
				array( 'status' => 502 )
			);
		}

		return rest_ensure_response( array(
			'ok'      => true,
			'text'    => $text,
			'tone'    => $tone,
			'sources' => $sources,
			// صریح به کلاینت می‌گوییم که این فقط پیش‌نویس است
			'draft'   => true,
		) );
	}

	/**
	 * خلاصهٔ مکالمه — برای وقتی اپراتور تازه یک رشتهٔ طولانی را باز می‌کند.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_summarize( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['copilot_enabled'] ) ) {
			return new WP_Error(
				'pasokhban_copilot_off',
				__( 'دستیار اپراتور غیرفعال است.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		$loaded = self::load( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$limit      = max( 2, min( 40, (int) $opts['copilot_max_msgs'] ) );
		$transcript = self::transcript( $loaded['messages'], $limit );

		if ( '' === $transcript['text'] ) {
			return new WP_Error(
				'pasokhban_empty',
				__( 'پیامی برای خلاصه‌کردن نیست.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		$system = 'مکالمهٔ پشتیبانی زیر را خلاصه کن. خروجی را دقیقاً با این سه سرفصل بنویس و هیچ چیز دیگری اضافه نکن:'
			. "\n۱) درخواست مشتری:"
			. "\n۲) آنچه تا الان پاسخ داده شده:"
			. "\n۳) کار باقی‌مانده:"
			. "\nهر بخش حداکثر یک جمله. فقط خلاصه را بنویس، بدون مقدمه.";

		$text = Pasokhban_API::instance()->raw_complete( $system, $transcript['text'], 300 );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return rest_ensure_response( array(
			'ok'   => true,
			'text' => trim( (string) $text ),
		) );
	}

	/* =========================================================
	 * ابزارها
	 * =======================================================*/

	/**
	 * بارگذاری نشست و پیام‌ها با همهٔ بررسی‌ها.
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error { session:object, messages:array }
	 */
	private static function load( $request ) {
		if ( empty( Pasokhban_Settings::instance()->get_options()['api_key'] ) ) {
			return new WP_Error(
				'pasokhban_no_key',
				__( 'کلید API تنظیم نشده است.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		$id      = (int) $request->get_param( 'id' );
		$session = Pasokhban_DB::get_session( $id );

		if ( ! $session ) {
			return new WP_Error(
				'pasokhban_notfound',
				__( 'مکالمه پیدا نشد.', 'pasokhban' ),
				array( 'status' => 404 )
			);
		}

		$messages = Pasokhban_DB::get_messages( $id, 0, 100 );

		return array( 'session' => $session, 'messages' => $messages );
	}

	/**
	 * ساخت متن رونوشت مکالمه.
	 *
	 * @param array $messages
	 * @param int   $limit
	 * @return array { text:string, last_visitor:string }
	 */
	private static function transcript( array $messages, $limit = 12 ) {
		$messages = array_values( array_filter( $messages, function ( $m ) {
			// پیام‌های سیستمی (handoff، تغییر حالت) برای مدل نویز هستند
			return isset( $m->sender ) && 'system' !== $m->sender;
		} ) );

		if ( empty( $messages ) ) {
			return array( 'text' => '', 'last_visitor' => '' );
		}

		$tail = array_slice( $messages, -$limit );

		$lines = array();
		$last_visitor = '';

		foreach ( $tail as $m ) {
			$who  = 'visitor' === $m->sender ? 'بازدیدکننده' : ( 'agent' === $m->sender ? 'اپراتور' : 'هوش مصنوعی' );
			$body = trim( (string) $m->content );
			if ( '' === $body ) {
				continue;
			}

			$lines[] = '[' . $who . '] ' . $body;

			if ( 'visitor' === $m->sender ) {
				$last_visitor = $body;
				// اگر بازدیدکننده فایل فرستاده، مدل باید بداند
				$note = Pasokhban_Upload::describe( $m );
				if ( '' !== $note ) {
					$lines[ count( $lines ) - 1 ] .= trim( $note );
				}
			}
		}

		return array(
			'text'         => implode( "\n", $lines ),
			'last_visitor' => $last_visitor,
		);
	}

	/**
	 * prompt سیستمی پیش‌نویس.
	 *
	 * @param string $tone
	 * @return string
	 */
	private static function system_prompt( $tone ) {
		$tones = array(
			'friendly' => 'لحن: دوستانه، گرم و صمیمی — مثل یک همکار خوش‌برخورد.',
			'formal'   => 'لحن: رسمی و اداری، محترمانه و بدون صمیمیت اضافه.',
			'short'    => 'لحن: خیلی کوتاه و مستقیم. حداکثر دو جمله.',
		);

		$t = isset( $tones[ $tone ] ) ? $tones[ $tone ] : $tones['friendly'];

		return 'تو دستیارِ اپراتور پشتیبانی یک سایت وردپرسی هستی. برای آخرین پیام بازدیدکننده یک پیش‌نویس پاسخ بنویس که اپراتورِ انسان ویرایش و ارسال کند.'
			. "\n\n" . $t
			. "\n\nقواعد سخت:"
			. "\n- فقط متن پاسخ را بنویس. هیچ مقدمه، توضیح، برچسب یا علامت نقل‌قول اضافه نکن."
			. "\n- به زبان فارسی بنویس."
			. "\n- اگر اطلاعات کافی نداری، به‌جای حدس زدن، بپرس چه چیزی لازم داری."
			. "\n- هرگز وانمود نکن کاری انجام شده است (مثل «واریز شد»، «ارسال شد»، «حل شد») مگر اینکه در مکالمه صراحتاً آمده باشد."
			. "\n- هرگز قیمت، زمان تحویل یا تعهد جدیدی از خودت نساز."
			. "\n- اگر محتوایی از سایت در ادامه آمده و به سؤال مربوط است، فقط بر اساس همان بنویس و به لینکش اشاره کن."
			. "\n- اگر کاربر فایل فرستاده و محتوایش لازم است، بگو که نمی‌توانی فایل را ببینی و از او بخواه توضیح دهد.";
	}

	/**
	 * prompt کاربر.
	 *
	 * @param object $session
	 * @param array  $transcript
	 * @param string $context
	 * @return string
	 */
	private static function user_prompt( $session, array $transcript, $context = '' ) {
		$parts = array();

		$name = trim( (string) $session->visitor_name . ' ' . ( isset( $session->visitor_family ) ? (string) $session->visitor_family : '' ) );
		if ( '' !== $name ) {
			$parts[] = 'نام بازدیدکننده: ' . $name;
		}
		if ( ! empty( $session->current_url ) ) {
			$parts[] = 'صفحه‌ای که در آن است: ' . $session->current_url;
		}
		if ( ! empty( $session->mode ) ) {
			$parts[] = 'حالت فعلی مکالمه: ' . ( 'agent' === $session->mode ? 'اپراتور انسانی' : 'هوش مصنوعی' );
		}

		$parts[] = "\nمکالمه تا این لحظه:\n" . $transcript['text'];

		if ( '' !== $context ) {
			$parts[] = "\n" . $context;
		}

		$parts[] = "\nحالا پیش‌نویس پاسخ به آخرین پیام بازدیدکننده را بنویس.";

		return implode( "\n", $parts );
	}

	/**
	 * پاک‌سازی پیش‌نویس.
	 *
	 * مدل‌ها گاهی پاسخ را داخل نقل‌قول یا با پیشوندهایی مثل «پاسخ:»
	 * می‌دهند. اپراتور نباید مجبور باشد آن‌ها را دستی پاک کند.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function clean_draft( $text ) {
		$t = trim( (string) $text );
		if ( '' === $t ) {
			return '';
		}

		// پیشوندهای رایج
		$t = preg_replace( '/^(پاسخ|پاسخ پیشنهادی|پیش‌نویس|پیشنهاد|draft|response|reply)\s*[:：]\s*/iu', '', $t );

		// نقل‌قول کامل
		$t = trim( (string) $t );
		if ( preg_match( '/^["“«\'"](.*)["”»\'"]$/us', $t, $m ) ) {
			$t = trim( $m[1] );
		}

		// بلوک کد
		$t = preg_replace( '/^```[a-z]*\s*/i', '', $t );
		$t = preg_replace( '/```\s*$/', '', $t );

		// سقف طول — یک پیش‌نویس ۲۰۰۰ کلمه‌ای به درد اپراتور نمی‌خورد
		if ( function_exists( 'mb_substr' ) && mb_strlen( $t ) > 1500 ) {
			$t = mb_substr( $t, 0, 1500 );
		}

		return trim( (string) $t );
	}

	/**
	 * @param string $tone
	 * @param array  $opts
	 * @return string
	 */
	private static function clean_tone( $tone, array $opts ) {
		$allowed = array( 'friendly', 'formal', 'short' );
		$tone    = strtolower( trim( (string) $tone ) );
		if ( in_array( $tone, $allowed, true ) ) {
			return $tone;
		}
		$def = isset( $opts['copilot_tone'] ) ? $opts['copilot_tone'] : 'friendly';
		return in_array( $def, $allowed, true ) ? $def : 'friendly';
	}
}
