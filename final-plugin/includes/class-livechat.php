<?php
/**
 * گفتگوی آنلاین پاسخ‌بان — اندپوینت‌های REST.
 *
 * دو مسیر پاسخ‌گویی:
 *   mode = 'ai'    → هوش مصنوعی پاسخ می‌دهد (پیش‌فرض)
 *   mode = 'agent' → اپراتور انسانی از اینباکس پیشخوان پاسخ می‌دهد
 *
 * سمت کاربر با کلید نشست (که در localStorage نگه داشته می‌شود) کار می‌کند و
 * هرگز شناسهٔ عددی نشست به او داده نمی‌شود.
 *
 * @package Pasokhban
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس گفتگوی زنده.
 */
final class Pasokhban_LiveChat {

	/** کلید transient برای «اپراتور در حال نوشتن». */
	const TYPING_PREFIX = 'pasokhban_typing_';

	/** کلید transient برای «اپراتور آنلاین است». */
	const HEARTBEAT = 'pasokhban_agent_online';

	/** @var Pasokhban_LiveChat|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/* =========================================================
	 * مسیرها
	 * =======================================================*/

	public function register_routes() {

		// ---- عمومی (بازدیدکننده) ----
		register_rest_route(
			'pasokhban/v1',
			'/live/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_start' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'pasokhban/v1',
			'/live/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_send' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'pasokhban/v1',
			'/live/contact',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_contact' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'pasokhban/v1',
			'/live/handoff',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_handoff' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'pasokhban/v1',
			'/live/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_feedback' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'pasokhban/v1',
			'/live/answer',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_answer' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'pasokhban/v1',
			'/live/poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_poll' ),
				'permission_callback' => '__return_true',
			)
		);

		// ---- اختصاصی مدیر ----
		$admin = array(
			'/admin/sessions' => array( 'GET', 'admin_sessions' ),
			'/admin/session'  => array( 'GET', 'admin_session' ),
			'/admin/reply'    => array( 'POST', 'admin_reply' ),
			'/admin/mode'     => array( 'POST', 'admin_mode' ),
			'/admin/status'   => array( 'POST', 'admin_status' ),
			'/admin/read'     => array( 'POST', 'admin_read' ),
			'/admin/typing'   => array( 'POST', 'admin_typing' ),
			'/admin/heartbeat' => array( 'POST', 'admin_heartbeat' ),
		);

		foreach ( $admin as $route => $spec ) {
			register_rest_route(
				'pasokhban/v1',
				$route,
				array(
					'methods'             => $spec[0],
					'callback'            => array( $this, $spec[1] ),
					'permission_callback' => array( $this, 'admin_permission' ),
				)
			);
		}

		// حذف مکالمه برگشت‌ناپذیر است؛ فقط مدیر.
		register_rest_route( 'pasokhban/v1', '/admin/delete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'admin_delete' ),
			'permission_callback' => array( $this, 'admin_only_permission' ),
		) );
	}

	/**
	 * فقط مدیر سایت.
	 *
	 * @return bool
	 */
	/**
	 * دسترسی اینباکس.
	 *
	 * تا ۲.۲ فقط manage_options بود، یعنی هر کسی که باید پاسخ می‌داد
	 * دسترسی کامل مدیر سایت را داشت. حالا نقش اختصاصی «اپراتور پاسخ‌بان»
	 * هم کافی است.
	 *
	 * @return bool
	 */
	public function admin_permission() {
		return Pasokhban_Team::can_access();
	}

	/**
	 * فقط مدیر — برای کارهایی که اپراتور نباید بتواند (حذف مکالمه).
	 *
	 * @return bool
	 */
	public function admin_only_permission() {
		return Pasokhban_Team::is_admin();
	}

	/* =========================================================
	 * عمومی
	 * =======================================================*/

	/**
	 * شروع یا ادامهٔ یک مکالمه.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_start( $request ) {
		if ( ! $this->chat_enabled() ) {
			return $this->disabled_error();
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$lang = $this->clean_lang( isset( $params['lang'] ) ? $params['lang'] : '' );

		$contact = array(
			'name'        => isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '',
			'family'      => isset( $params['family'] ) ? sanitize_text_field( (string) $params['family'] ) : '',
			'phone'       => isset( $params['phone'] ) ? $params['phone'] : '',
			'email'       => isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '',
			'lang'        => $lang,
			'entry_url'   => isset( $params['entry_url'] ) ? $params['entry_url'] : '',
			'current_url' => isset( $params['current_url'] ) ? $params['current_url'] : '',
		);

		$session = Pasokhban_DB::get_or_create_session(
			isset( $params['key'] ) ? $params['key'] : '',
			$contact
		);

		if ( ! $session ) {
			return new WP_Error( 'pasokhban_db', __( 'ثبت مکالمه ممکن نشد.', 'pasokhban' ), array( 'status' => 500 ) );
		}

		// اگر نشست از قبل وجود داشت، اطلاعات تماس تازه را رویش به‌روز کن
		$upd = array();
		if ( '' !== $contact['name'] && $contact['name'] !== $session->visitor_name ) {
			$upd['visitor_name'] = mb_substr( $contact['name'], 0, 100 );
		}
		if ( '' !== $contact['family'] && $contact['family'] !== $session->visitor_family ) {
			$upd['visitor_family'] = mb_substr( $contact['family'], 0, 100 );
		}
		if ( '' !== $contact['phone'] ) {
			$phone = Pasokhban_DB::sanitize_phone( $contact['phone'] );
			if ( '' !== $phone && $phone !== $session->visitor_phone ) {
				$upd['visitor_phone'] = $phone;
			}
		}
		if ( $upd ) {
			Pasokhban_DB::update_session( (int) $session->id, $upd );
			$session = Pasokhban_DB::get_session( (int) $session->id );
		}

		$messages = Pasokhban_DB::get_messages( (int) $session->id, 0, 60 );

		return rest_ensure_response(
			array(
				'key'         => $session->session_key,
				'mode'        => $session->mode,
				'status'      => $session->status,
				'messages'    => $messages,
				'agentOnline' => $this->agent_online(),
				'agentName'   => $this->agent_name(),
				'settings'    => $this->public_settings( $lang ),
			)
		);
	}

	/**
	 * دریافت پیام از بازدیدکننده.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_send( $request ) {
		if ( ! $this->chat_enabled() ) {
			return $this->disabled_error();
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$content = isset( $params['content'] ) ? trim( (string) $params['content'] ) : '';
		$content = sanitize_textarea_field( $content );

		if ( '' === $content ) {
			return new WP_Error( 'pasokhban_empty', __( 'پیام خالی است.', 'pasokhban' ), array( 'status' => 400 ) );
		}

		if ( mb_strlen( $content, 'UTF-8' ) > 4000 ) {
			$content = mb_substr( $content, 0, 4000, 'UTF-8' );
		}

		$session = Pasokhban_DB::get_session_by_key( isset( $params['key'] ) ? $params['key'] : '' );
		if ( ! $session ) {
			return new WP_Error( 'pasokhban_nosession', __( 'نشست پیدا نشد. صفحه را تازه کنید.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		// محدودسازی نرخ — همان شمارندهٔ ویجت مغز.
		if ( Pasokhban_API::instance()->is_rate_limited( 'live' ) ) {
			return new WP_Error(
				'pasokhban_rate',
				__( 'درخواست‌های زیادی ارسال کردید. کمی صبر کنید.', 'pasokhban' ),
				array( 'status' => 429 )
			);
		}

		$lang = $this->clean_lang( isset( $params['lang'] ) ? $params['lang'] : $session->lang );

		// ضمیمه‌ها: کلاینت فقط «توکن» می‌فرستد، نه URL. توکن‌های ناشناخته
		// بی‌صدا حذف می‌شوند تا کاربر نتواند آدرس دلخواه را جا بزند.
		$attachments = self::attachments_from_request( $session, $params );

		$meta = array();
		if ( ! empty( $attachments ) ) {
			$meta['attachments'] = $attachments;
		}

		// ثبت پیام بازدیدکننده.
		$visitor_msg = Pasokhban_DB::add_message( (int) $session->id, 'visitor', $content, $meta );
		if ( ! $visitor_msg ) {
			return new WP_Error( 'pasokhban_db', __( 'ذخیرهٔ پیام ممکن نشد.', 'pasokhban' ), array( 'status' => 500 ) );
		}

		Pasokhban_DB::update_session(
			(int) $session->id,
			array(
				'lang'         => $lang,
				'current_url'  => isset( $params['current_url'] ) ? esc_url_raw( $params['current_url'] ) : $session->current_url,
				'unread_agent' => (int) $session->unread_agent + 1,
			)
		);
		Pasokhban_DB::touch_session( (int) $session->id, $content, 'visitor' );

		/**
		 * قلاب: پیام تازه از بازدیدکننده (برای اعلان ایمیلی/تلگرامی و غیره).
		 *
		 * @param object $session
		 * @param object $visitor_msg
		 */
		do_action( 'pasokhban_visitor_message', $session, $visitor_msg );

		$response = array(
			'message'     => $visitor_msg,
			'mode'        => $session->mode,
			'agentOnline' => $this->agent_online(),
		);

		// اگر اپراتور انسانی متولی این مکالمه است، هوش مصنوعی دخالت نمی‌کند.
		if ( 'agent' === $session->mode ) {
			$response['queued'] = true;
			return rest_ensure_response( $response );
		}

		// ---- پاسخ هوش مصنوعی ----
		$ai = $this->ai_reply( $session, $lang );

		if ( is_wp_error( $ai ) ) {
			$response['error'] = $ai->get_error_message();
			return rest_ensure_response( $response );
		}

		$response['reply'] = $ai['message'];

		return rest_ensure_response( $response );
	}

	/**
	 * پاسخ به پیامی که قبلاً ذخیره شده ولی پاسخی نگرفته.
	 *
	 * این مسیر برای زمانی است که جریان (SSE) بعد از ذخیرهٔ پیام کاربر
	 * شکست می‌خورد. بدون آن، پیام کاربر در دیتابیس می‌ماند ولی هیچ‌کس
	 * پاسخ تولید نمی‌کند و کاربر تا ابد منتظر می‌ماند.
	 *
	 * پیام را دوباره POST نمی‌کنیم تا رکورد تکراری ساخته نشود.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_answer( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['live_enabled'] ) ) {
			return $this->disabled_error();
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$session = Pasokhban_DB::get_session_by_key( isset( $params['key'] ) ? $params['key'] : '' );
		if ( ! $session ) {
			return new WP_Error( 'pasokhban_nosession', __( 'نشست پیدا نشد. صفحه را تازه کن.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		if ( Pasokhban_API::instance()->is_rate_limited( 'live' ) ) {
			return new WP_Error( 'pasokhban_rate', __( 'درخواست‌های زیادی ارسال کردی. کمی صبر کن.', 'pasokhban' ), array( 'status' => 429 ) );
		}

		$messages = Pasokhban_DB::get_messages( (int) $session->id, 0, 200 );

		// آخرین پیام باید از کاربر باشد و بعد از آن پاسخی نباشد
		$last = empty( $messages ) ? null : $messages[ count( $messages ) - 1 ];
		if ( ! $last || 'visitor' !== $last->sender ) {
			return new WP_Error(
				'pasokhban_no_pending',
				__( 'پیام بی‌پاسخی در انتظار نیست.', 'pasokhban' ),
				array( 'status' => 409 )
			);
		}

		// اگر حالت اپراتور انسانی است، AI پاسخ نمی‌دهد
		if ( 'agent' === $session->mode ) {
			return rest_ensure_response( array( 'queued' => true, 'mode' => 'agent' ) );
		}

		$history = array();
		foreach ( $messages as $m ) {
			if ( 'visitor' === $m->sender ) {
				$history[] = array( 'role' => 'user', 'content' => $m->content );
			} elseif ( 'agent' === $m->sender ) {
				$history[] = array( 'role' => 'assistant', 'content' => $m->content );
			}
		}

		$started = microtime( true );

		$result = Pasokhban_API::instance()->generate_reply(
			$history,
			$session->lang ? $session->lang : 'fa',
			array(
				'mode' => 'live',
				'page' => $session->current_url ? $session->current_url : $session->entry_url,
				'name' => $session->visitor_name,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		$saved = Pasokhban_DB::add_message(
			(int) $session->id,
			'agent',
			$result['content'],
			array(
				'via'      => 'ai',
				'sources'  => isset( $result['sources'] ) ? $result['sources'] : array(),
				'recovered' => true,   // از راه بازیابی آمده، نه جریان
			),
			$ms
		);

		if ( ! $saved ) {
			return new WP_Error( 'pasokhban_db', __( 'ذخیرهٔ پاسخ ممکن نشد.', 'pasokhban' ), array( 'status' => 500 ) );
		}

		Pasokhban_DB::update_session( (int) $session->id, array( 'unread_visitor' => (int) $session->unread_visitor + 1 ) );
		Pasokhban_DB::touch_session( (int) $session->id, $result['content'], 'agent' );

		return rest_ensure_response(
			array(
				'id'      => (int) $saved->id,
				'content' => $result['content'],
				'sources' => isset( $result['sources'] ) ? $result['sources'] : array(),
				'ms'      => $ms,
			)
		);
	}

	/**
	 * انتقال مکالمه به اپراتور انسانی.
	 *
	 * قبلاً دکمهٔ «گپ با پشتیبان» فقط یک پیام متنی به AI می‌فرستاد، پس
	 * هوش مصنوعی هرچه دلش خواست جواب می‌داد (مثلاً «برو صفحهٔ تماس با ما»)
	 * و هیچ اپراتوری هم خبردار نمی‌شد.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_handoff( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['live_enabled'] ) ) {
			return $this->disabled_error();
		}
		if ( empty( $opts['live_human_enabled'] ) ) {
			return new WP_Error(
				'pasokhban_no_human',
				__( 'پشتیبانی انسانی فعال نیست.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		$params  = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$session = Pasokhban_DB::get_session_by_key( isset( $params['key'] ) ? $params['key'] : '' );
		if ( ! $session ) {
			return new WP_Error( 'pasokhban_nosession', __( 'نشست پیدا نشد. صفحه را تازه کن.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		if ( Pasokhban_API::instance()->is_rate_limited( 'live' ) ) {
			return new WP_Error( 'pasokhban_rate', __( 'درخواست‌های زیادی ارسال کردی. کمی صبر کن.', 'pasokhban' ), array( 'status' => 429 ) );
		}

		$lang = isset( $params['lang'] ) ? sanitize_key( (string) $params['lang'] ) : ( $session->lang ? $session->lang : 'fa' );
		$lang = in_array( $lang, array( 'fa', 'en' ), true ) ? $lang : 'fa';

		// اگر قبلاً منتقل شده، دوباره پیام تکراری نساز
		if ( 'agent' === $session->mode ) {
			return rest_ensure_response(
				array(
					'mode'        => 'agent',
					'already'     => true,
					'agentOnline' => $this->agent_online(),
				)
			);
		}

		Pasokhban_DB::update_session( (int) $session->id, array( 'mode' => 'agent' ) );

		// واگذاری خودکار — قبل از ساختن پیام، تا در اینباکس بی‌صاحب نباشد.
		$assigned_to = Pasokhban_Team::auto_assign( (int) $session->id );

		// بیرون از ساعت کاری، وعدهٔ «کمی صبر کن» دروغ است. به‌جایش پیام
		// مناسب و زمان بازگشایی را نشان می‌دهیم.
		if ( ! Pasokhban_Hours::is_open() ) {
			$note = 'en' === $lang
				? 'We are outside business hours right now. Your message is saved and we will reply as soon as we are back.'
				: Pasokhban_Hours::offline_message();
		} else {
			$note = 'en' === $lang
				? 'You have been transferred to a human agent. Please wait a moment.'
				: 'درخواست شما به پشتیبان انسانی منتقل شد. لطفاً کمی صبر کنید.';
		}

		$handoff_meta = array( 'note' => 'handoff' );
		if ( $assigned_to > 0 ) {
			$handoff_meta['assigned_to'] = $assigned_to;
		}

		$msg = Pasokhban_DB::add_message( (int) $session->id, 'system', $note, $handoff_meta );
		Pasokhban_DB::touch_session( (int) $session->id, $note, 'system' );

		/**
		 * قلاب: درخواست پشتیبان انسانی — برای اعلان به مدیر.
		 *
		 * @param object $session
		 */
		do_action( 'pasokhban_handoff_requested', $session );

		// اعلان مستقیم به مدیر
		$this->notify_handoff( $session, $lang );

		return rest_ensure_response(
			array(
				'mode'        => 'agent',
				'message'    => $msg,
				'agentOnline' => $this->agent_online(),
				'note'        => $note,
			)
		);
	}

	/**
	 * اعلان درخواست پشتیبان انسانی به مدیر.
	 *
	 * @param object $session
	 * @param string $lang
	 */
	private function notify_handoff( $session, $lang ) {
		if ( ! class_exists( 'Pasokhban_Notify' ) ) {
			return;
		}

		$name = trim( $session->visitor_name . ' ' . ( isset( $session->visitor_family ) ? $session->visitor_family : '' ) );
		if ( '' === trim( $name ) ) {
			$name = 'en' === $lang ? 'Visitor' : 'بازدیدکننده';
		}

		$body = 'en' === $lang
			? "{$name} has requested a human agent."
			: "{$name} درخواست پشتیبان انسانی داد.";

		if ( ! empty( $session->visitor_phone ) ) {
			$body .= "\n📞 " . $session->visitor_phone;
		}
		if ( ! empty( $session->current_url ) ) {
			$body .= "\n🔗 " . $session->current_url;
		}

		$title = 'en' === $lang ? 'Human agent requested' : '🙋 درخواست پشتیبان انسانی';

		Pasokhban_Notify::notify_now( $title, $body );
	}

	/**
	 * ثبت امتیاز رضایت (CSAT) برای یک پاسخ.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_feedback( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['csat'] ) ) {
			return new WP_Error( 'pasokhban_disabled', __( 'امتیازدهی فعال نیست.', 'pasokhban' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$session = Pasokhban_DB::get_session_by_key( isset( $params['key'] ) ? $params['key'] : '' );
		if ( ! $session ) {
			return new WP_Error( 'pasokhban_nosession', __( 'نشست پیدا نشد.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		$rating = isset( $params['rating'] ) ? (int) $params['rating'] : 0;
		if ( ! in_array( $rating, array( 1, -1 ), true ) ) {
			return new WP_Error( 'pasokhban_bad_rating', __( 'امتیاز نامعتبر است.', 'pasokhban' ), array( 'status' => 400 ) );
		}

		$message_id = isset( $params['message_id'] ) ? (int) $params['message_id'] : 0;
		if ( $message_id < 1 ) {
			return new WP_Error( 'pasokhban_bad_message', __( 'شناسهٔ پیام نامعتبر است.', 'pasokhban' ), array( 'status' => 400 ) );
		}

		// پیام باید واقعاً مال این نشست باشد، وگرنه هر کسی می‌تواند
		// با حدس‌زدن id به مکالمهٔ دیگری امتیاز بدهد.
		global $wpdb;
		$belongs = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Pasokhban_DB::messages_table() . " WHERE id = %d AND session_id = %d AND sender = 'agent'",
				$message_id,
				(int) $session->id
			) // phpcs:ignore WordPress.DB
		);

		if ( ! $belongs ) {
			return new WP_Error( 'pasokhban_forbidden', __( 'این پیام به این مکالمه تعلق ندارد.', 'pasokhban' ), array( 'status' => 403 ) );
		}

		$ok = Pasokhban_DB::save_feedback( (int) $session->id, $message_id, $rating );

		/**
		 * قلاب: ثبت امتیاز رضایت.
		 *
		 * @param object $session
		 * @param int    $message_id
		 * @param int    $rating 1 یا -1
		 */
		do_action( 'pasokhban_feedback', $session, $message_id, $rating );

		return rest_ensure_response( array( 'ok' => (bool) $ok ) );
	}

	/**
	 * ثبت اطلاعات تماس بازدیدکننده (فرم پیش از گفتگو).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_contact( $request ) {
		if ( ! $this->chat_enabled() ) {
			return $this->disabled_error();
		}

		$params  = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$session = Pasokhban_DB::get_session_by_key( isset( $params['key'] ) ? $params['key'] : '' );
		if ( ! $session ) {
			return new WP_Error( 'pasokhban_nosession', __( 'نشست پیدا نشد.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		$name   = isset( $params['name'] ) ? mb_substr( sanitize_text_field( (string) $params['name'] ), 0, 100 ) : '';
		$family = isset( $params['family'] ) ? mb_substr( sanitize_text_field( (string) $params['family'] ), 0, 100 ) : '';
		$phone  = isset( $params['phone'] ) ? Pasokhban_DB::sanitize_phone( $params['phone'] ) : '';
		$email  = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';

		// اگر فرم اجباری باشد، هر سه فیلد لازم است
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( ! empty( $opts['live_prechat_required'] ) && ( '' === $name || '' === $family || '' === $phone ) ) {
			return new WP_Error(
				'pasokhban_incomplete',
				__( 'لطفاً نام، نام خانوادگی و شمارهٔ تماس را کامل وارد کن.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		$upd = array();
		if ( '' !== $name ) {
			$upd['visitor_name'] = $name;
		}
		if ( '' !== $family ) {
			$upd['visitor_family'] = $family;
		}
		if ( '' !== $phone ) {
			$upd['visitor_phone'] = $phone;
		}
		if ( '' !== $email ) {
			$upd['visitor_email'] = $email;
		}

		if ( $upd ) {
			Pasokhban_DB::update_session( (int) $session->id, $upd );
		}

		/**
		 * قلاب: اطلاعات تماس تازهٔ بازدیدکننده.
		 *
		 * @param object $session
		 * @param array  $upd فیلدهای به‌روزشده
		 */
		do_action( 'pasokhban_visitor_contact', $session, $upd );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * دریافت پیام‌های تازه از سمت اپراتور (polling).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_poll( $request ) {
		if ( ! $this->chat_enabled() ) {
			return $this->disabled_error();
		}

		$session = Pasokhban_DB::get_session_by_key( (string) $request->get_param( 'key' ) );
		if ( ! $session ) {
			return rest_ensure_response( array( 'expired' => true ) );
		}

		$after    = (int) $request->get_param( 'after' );
		$messages = Pasokhban_DB::get_messages( (int) $session->id, $after, 50 );

		// بازدیدکننده پیام‌ها را دید → شمارندهٔ خوانده‌نشده صفر شود.
		if ( $messages && (int) $session->unread_visitor > 0 ) {
			Pasokhban_DB::update_session( (int) $session->id, array( 'unread_visitor' => 0 ) );
		}

		$fresh = (int) $request->get_param( 'status' );

		return rest_ensure_response(
			array(
				'messages'    => $messages,
				'mode'        => $session->mode,
				'status'      => $fresh ? $session->status : null,
				'typing'      => $this->is_typing( (int) $session->id ),
				'agentOnline' => $this->agent_online(),
				'agentName'   => $this->agent_name(),
			)
		);
	}

	/* =========================================================
	 * پاسخ هوش مصنوعی
	 * =======================================================*/

	/**
	 * ساخت پاسخ AI و ذخیرهٔ آن به‌عنوان پیام اپراتور.
	 *
	 * @param object $session
	 * @param string $lang
	 * @return array|WP_Error
	 */
	private function ai_reply( $session, $lang ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['api_key'] ) ) {
			return new WP_Error(
				'pasokhban_no_key',
				__( 'دستیار هوشمند هنوز پیکربندی نشده است.', 'pasokhban' )
			);
		}

		// تاریخچهٔ این مکالمه از دیتابیس — با سقف مشخص تا prompt بی‌نهایت بزرگ نشود.
		$history = Pasokhban_DB::get_messages( (int) $session->id, 0, (int) $opts['live_history'] );

		$messages = array();
		foreach ( $history as $m ) {
			if ( 'visitor' === $m->sender ) {
				// اگر کاربر فایل فرستاده، مدل باید بداند؛ وگرنه فکر
				// می‌کند پیام خالی است و پاسخ بی‌ربط می‌دهد.
				$messages[] = array(
					'role'    => 'user',
					'content' => $m->content . Pasokhban_Upload::describe( $m ),
				);
			} elseif ( 'agent' === $m->sender ) {
				$messages[] = array(
					'role'    => 'assistant',
					'content' => $m->content,
				);
			}
		}

		if ( empty( $messages ) ) {
			return new WP_Error( 'pasokhban_empty', __( 'پیامی برای پاسخ دادن نیست.', 'pasokhban' ) );
		}

		$context = array(
			'page'  => $session->current_url ? $session->current_url : $session->entry_url,
			'name'  => $session->visitor_name,
			'email' => $session->visitor_email,
		);

		// اگر مشتری در همین مکالمه سفارشی را استعلام کرده، مدل باید
		// وضعیتش را بداند — وگرنه می‌گوید «به پشتیبانی بگو» در حالی که
		// اطلاعات همین‌جا هست.
		$order = Pasokhban_Woo::latest_from_messages( $history );

		// اگر استعلامی انجام نشده ولی شمارهٔ تماس یا ایمیل مشتری را
		// داریم، آخرین سفارشش را از ووکامرس پیدا کن. این همان چیزی است
		// که «سفارشم کجاست؟» را بدون پرسیدن شمارهٔ سفارش جواب می‌دهد.
		if ( ! $order && class_exists( 'Pasokhban_Woo' ) ) {
			$woo_opts = Pasokhban_Settings::instance()->get_options();
			if ( ! empty( $woo_opts['woo_products'] ) || ! empty( $woo_opts['woo_order_lookup'] ) ) {
				$order = Pasokhban_Woo::latest_order_for(
					isset( $session->visitor_phone ) ? $session->visitor_phone : '',
					isset( $session->visitor_email ) ? $session->visitor_email : ''
				);
			}
		}

		if ( $order ) {
			$context['order'] = $order;
		}

		$result = Pasokhban_API::instance()->generate_reply( $messages, $lang, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$saved = Pasokhban_DB::add_message(
			(int) $session->id,
			'agent',
			$result['content'],
			array(
				'via'     => 'ai',
				'model'   => isset( $result['model'] ) ? $result['model'] : '',
				'sources' => isset( $result['sources'] ) ? $result['sources'] : array(),
			)
		);

		if ( ! $saved ) {
			return new WP_Error( 'pasokhban_db', __( 'ذخیرهٔ پاسخ ممکن نشد.', 'pasokhban' ) );
		}

		Pasokhban_DB::update_session(
			(int) $session->id,
			array( 'unread_visitor' => (int) $session->unread_visitor + 1 )
		);
		Pasokhban_DB::touch_session( (int) $session->id, $result['content'], 'agent' );

		return array( 'message' => $saved );
	}

	/* =========================================================
	 * مدیر — اینباکس
	 * =======================================================*/

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function admin_sessions( $request ) {
		$args = array(
			'status'   => (string) $request->get_param( 'status' ),
			'mode'     => (string) $request->get_param( 'mode' ),
			'search'   => (string) $request->get_param( 'search' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'page'     => (int) $request->get_param( 'page' ),
		);

		// فیلتر اپراتور از UI
		$assigned = (string) $request->get_param( 'assigned' );
		if ( in_array( $assigned, array( 'me', 'unassigned', 'all' ), true ) ) {
			$args['assigned'] = $assigned;
		} elseif ( ctype_digit( $assigned ) && (int) $assigned > 0 ) {
			$args['assigned'] = (int) $assigned;
		}

		// و بعد، محدودسازی دسترسی روی آن اعمال می‌شود — حتی اگر کاربر
		// «all» خواسته باشد. وگرنه یک اپراتور با دستکاری پارامتر همهٔ
		// مکالمات را می‌دید.
		$args = Pasokhban_Team::scope_list_args( $args );

		$result = Pasokhban_DB::list_sessions( $args );

		$items = array();
		foreach ( $result['items'] as $s ) {
			$items[] = $this->shape_session( $s );
		}

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => $result['total'],
				'pages' => $result['pages'],
				'hoursOpen' => Pasokhban_Hours::is_open(),
				'online'    => array_values( array_map( array( 'Pasokhban_Team', 'agent_name' ), array_keys( Pasokhban_Team::online_ids() ) ) ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_session( $request ) {
		$peek = Pasokhban_DB::get_session( (int) $request->get_param( 'id' ) );
		if ( $peek && ! Pasokhban_Team::can_touch( $peek ) ) {
			return new WP_Error(
				'pasokhban_forbidden',
				__( 'این مکالمه به اپراتور دیگری واگذار شده است.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		$session = Pasokhban_DB::get_session( (int) $request->get_param( 'id' ) );
		if ( ! $session ) {
			return new WP_Error( 'pasokhban_notfound', __( 'مکالمه پیدا نشد.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'session'  => $this->shape_session( $session ),
				'messages' => Pasokhban_DB::get_messages( (int) $session->id, 0, 300 ),
			)
		);
	}

	/**
	 * پاسخ دستی اپراتور.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_reply( $request ) {
		$id = (int) $request->get_param( 'id' );

		$session = Pasokhban_DB::get_session( $id );
		if ( ! $session ) {
			return new WP_Error( 'pasokhban_notfound', __( 'مکالمه پیدا نشد.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		$content = sanitize_textarea_field( (string) $request->get_param( 'content' ) );
		if ( '' === $content ) {
			return new WP_Error( 'pasokhban_empty', __( 'پیام خالی است.', 'pasokhban' ), array( 'status' => 400 ) );
		}

		$message = Pasokhban_DB::add_message(
			$id,
			'agent',
			$content,
			array(
				'via'  => 'human',
				'name' => wp_get_current_user()->display_name,
			)
		);

		if ( ! $message ) {
			return new WP_Error( 'pasokhban_db', __( 'ارسال پیام ممکن نشد.', 'pasokhban' ), array( 'status' => 500 ) );
		}

		// پاسخ دستی یعنی اپراتور متولی مکالمه شده است.
		Pasokhban_DB::update_session(
			$id,
			array(
				'mode'             => 'agent',
				'unread_agent'     => 0,
				'unread_visitor'   => (int) $session->unread_visitor + 1,
			)
		);
		Pasokhban_DB::touch_session( $id, $content, 'agent' );
		delete_transient( self::TYPING_PREFIX . $id );

		// پیامک به بازدیدکننده — فقط اگر شماره‌اش را در فرم پیش‌گفتگو
		// داده باشد و قابلیت روشن باشد. throttle داخل خودش است.
		if ( class_exists( 'Pasokhban_SMS' ) ) {
			Pasokhban_SMS::notify_visitor( $session, $content );
		}

		return rest_ensure_response( array( 'message' => $message ) );
	}

	/**
	 * تغییر حالت پاسخ‌گویی (ai | agent).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_mode( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$mode = 'agent' === $request->get_param( 'mode' ) ? 'agent' : 'ai';

		if ( ! Pasokhban_DB::get_session( $id ) ) {
			return new WP_Error( 'pasokhban_notfound', __( 'مکالمه پیدا نشد.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		Pasokhban_DB::update_session( $id, array( 'mode' => $mode ) );

		$note = 'ai' === $mode
			? __( 'پاسخ‌گویی به دستیار هوشمند سپرده شد.', 'pasokhban' )
			: __( 'یک اپراتور انسانی پاسخ‌گوی شماست.', 'pasokhban' );

		Pasokhban_DB::add_message( $id, 'system', $note, array( 'note' => 'mode_' . $mode ) );

		return rest_ensure_response( array( 'mode' => $mode ) );
	}

	/**
	 * بستن/بازکردن مکالمه.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_status( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$status = 'closed' === $request->get_param( 'status' ) ? 'closed' : 'open';

		if ( ! Pasokhban_DB::get_session( $id ) ) {
			return new WP_Error( 'pasokhban_notfound', __( 'مکالمه پیدا نشد.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		Pasokhban_DB::update_session( $id, array( 'status' => $status ) );

		if ( 'closed' === $status ) {
			Pasokhban_DB::add_message( $id, 'system', __( 'گفتگو بسته شد.', 'pasokhban' ), array( 'note' => 'closed' ) );
		}

		return rest_ensure_response( array( 'status' => $status ) );
	}

	/**
	 * علامت‌گذاری به‌عنوان خوانده‌شده.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function admin_read( $request ) {
		Pasokhban_DB::update_session( (int) $request->get_param( 'id' ), array( 'unread_agent' => 0 ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * «اپراتور در حال نوشتن…» — ۶ ثانیه اعتبار دارد.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function admin_typing( $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( $request->get_param( 'typing' ) ) {
			set_transient( self::TYPING_PREFIX . $id, 1, 6 );
		} else {
			delete_transient( self::TYPING_PREFIX . $id );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * ضربان قلب اینباکس → «اپراتور آنلاین است» برای بازدیدکننده.
	 *
	 * @return WP_REST_Response
	 */
	public function admin_heartbeat() {
		set_transient( self::HEARTBEAT, 1, 25 );

		// حضور به ازای هر اپراتور — برای «چه کسانی آنلاین‌اند» و
		// واگذاری خودکار نوبتی لازم است.
		$online = Pasokhban_Team::heartbeat();

		return rest_ensure_response( array(
			'ok'          => true,
			'onlineCount' => count( $online ),
			'hoursOpen'   => Pasokhban_Hours::is_open(),
		) );
	}

	/**
	 * حذف کامل یک مکالمه.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete( $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$session = Pasokhban_DB::get_session( $id );

		if ( ! $session ) {
			return new WP_Error( 'pasokhban_notfound', __( 'مکالمه پیدا نشد.', 'pasokhban' ), array( 'status' => 404 ) );
		}

		// اول فایل‌های ضمیمه را از دیسک پاک کن، بعد ردیف‌ها را؛ اگر برعکس
		// شود، meta از بین می‌رود و فایل‌ها برای همیشه یتیم می‌مانند.
		Pasokhban_Upload::purge_sessions( array( $id ) );
		delete_transient( 'pasokhban_upl_' . $id );

		if ( ! Pasokhban_Team::is_admin() ) {
			return new WP_Error(
				'pasokhban_forbidden',
				__( 'حذف مکالمه فقط برای مدیر ممکن است.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		$wpdb->delete( Pasokhban_DB::messages_table(), array( 'session_id' => $id ) );
		$wpdb->delete( Pasokhban_DB::sessions_table(), array( 'id' => $id ) );
		delete_transient( self::TYPING_PREFIX . $id );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* =========================================================
	 * ابزارها
	 * =======================================================*/

	/**
	 * تبدیل توکن‌های ضمیمهٔ درخواست به متادیتای واقعی.
	 *
	 * مشترک بین /live/send و /live/stream تا هر دو دقیقاً یک رفتار داشته
	 * باشند. اگر قابلیت آپلود خاموش باشد، ضمیمه‌ها نادیده گرفته می‌شوند
	 * (نه اینکه پیام رد شود — کاربر نباید بخاطر یک ضمیمهٔ گیرکرده،
	 * کل پیامش را از دست بدهد).
	 *
	 * @param object $session
	 * @param array  $params
	 * @return array
	 */
	private static function attachments_from_request( $session, array $params ) {
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( empty( $opts['live_upload'] ) || empty( $params['attachments'] ) ) {
			return array();
		}
		$tokens = $params['attachments'];
		if ( is_string( $tokens ) ) {
			$tokens = explode( ',', $tokens );
		}
		if ( ! is_array( $tokens ) ) {
			return array();
		}
		return Pasokhban_Upload::resolve_tokens( (int) $session->id, $tokens );
	}

	/**
	 * آیا حالت چت آنلاین فعال است؟
	 *
	 * @return bool
	 */
	private function chat_enabled() {
		$opts = Pasokhban_Settings::instance()->get_options();
		return ! empty( $opts['live_enabled'] );
	}

	/**
	 * @return WP_Error
	 */
	private function disabled_error() {
		return new WP_Error(
			'pasokhban_disabled',
			__( 'گفتگوی آنلاین فعال نیست.', 'pasokhban' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param string $id
	 * @return bool
	 */
	private function is_typing( $id ) {
		return (bool) get_transient( self::TYPING_PREFIX . (int) $id );
	}

	/**
	 * @return bool
	 */
	/**
	 * آیا اپراتور انسانی الان در دسترس است؟
	 *
	 * ترکیب دو شرط: بیرون از ساعت کاری نباشیم، و واقعاً کسی آنلاین باشد.
	 *
	 * @return bool
	 */
	private function agent_online() {
		if ( ! Pasokhban_Hours::is_open() ) {
			return false;
		}
		return (bool) get_transient( self::HEARTBEAT );
	}

	/**
	 * نام اپراتور برای نمایش در هدر چت.
	 *
	 * @return string
	 */
	/**
	 * نام برند برای نمایش به کاربر.
	 *
	 * @return string
	 */
	private function brand_name() {
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( empty( $opts['brand_enabled'] ) || '' === trim( (string) $opts['brand_name'] ) ) {
			return '';
		}
		return (string) $opts['brand_name'];
	}

	private function agent_name() {
		$opts = Pasokhban_Settings::instance()->get_options();
		return $opts['live_agent_name'] ? $opts['live_agent_name'] : __( 'پشتیبانی', 'pasokhban' );
	}

	/**
	 * تنظیماتی که به اسکریپت کاربر داده می‌شود.
	 *
	 * public است تا در enqueue هم استفاده شود (بدون نیاز به درخواست اضافه).
	 *
	 * @param string $lang
	 * @return array
	 */
	public function public_settings( $lang ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		return array(
			'title'      => $opts['live_title'] ? $opts['live_title'] : __( 'گفتگوی آنلاین', 'pasokhban' ),
			'agentName'  => $this->agent_name(),
			'greeting'   => $this->live_greeting( $lang ),
			'placeholder' => $opts['live_placeholder'] ? $opts['live_placeholder'] : __( 'پیامت را بنویس…', 'pasokhban' ),
			'accent'     => $opts['accent'],
			'position'   => in_array( $opts['live_position'], array( 'start', 'end' ), true ) ? $opts['live_position'] : 'start',
			'autoOpen'   => (bool) $opts['live_auto_open'],
			'autoDelay'  => max( 0, (int) $opts['live_auto_delay'] ),
			'starter'    => $this->starter_questions( $lang ),
			'humanHandoff' => (bool) $opts['live_human_enabled'],
			'streaming'    => (bool) $opts['streaming'],
			'csat'         => (bool) $opts['csat'],
			'streamUrl'    => esc_url_raw( rest_url( 'pasokhban/v1/live/stream' ) ),
			'prechat'      => (bool) $opts['live_prechat'],
			'prechatRequired' => (bool) $opts['live_prechat_required'],
			'prechatNote'  => (string) $opts['live_prechat_note'],
			'sound'      => (bool) $opts['live_sound'],
			'offlineNote' => $opts['live_offline_note'] ? $opts['live_offline_note'] : '',

			// ارسال فایل (۲.۰.۰)
			'wooOrder'      => class_exists( 'Pasokhban_Woo' ) && Pasokhban_Woo::enabled( 'woo_order_lookup' ),

			// برندسازی سفید (۲.۴.۰)
			'brand'         => $this->brand_name(),
			'brandUrl'      => $this->brand_name() ? (string) $opts['brand_url'] : '',
			'hideCredit'    => (bool) $opts['brand_enabled'] && (bool) $opts['brand_hide_credit'],

			// ساعت کاری (۲.۳.۰)
			'hoursEnabled'  => (bool) $opts['hours_enabled'],
			'hoursOpen'     => Pasokhban_Hours::is_open(),
			'hoursMsg'      => $opts['hours_enabled'] ? Pasokhban_Hours::offline_message() : '',
			'upload'        => (bool) $opts['live_upload'],
			'uploadMax'     => max( 1, (int) $opts['live_upload_max'] ),
			'uploadTypes'   => Pasokhban_Upload::allowed_exts( $opts ),
			'uploadMaxPer'  => Pasokhban_Upload::MAX_PER_MESSAGE,

			// ظاهر و جای‌گیری (۱.۲.۰)
			'offsetBottom'  => (int) $opts['live_offset_bottom'],
			'offsetSide'    => (int) $opts['live_offset_side'],
			'launcherSize'  => (int) $opts['live_launcher_size'],
			'panelWidth'    => (int) $opts['live_panel_width'],
			'panelHeight'   => (int) $opts['live_panel_height'],
			'radius'        => (int) $opts['live_radius'],
			'blur'          => (int) $opts['live_blur'],
			'glassOpacity'  => (int) $opts['live_glass_opacity'],
			'launcherLabel' => (string) $opts['live_launcher_label'],
			'teaser'        => (bool) $opts['live_teaser'],
			'teaserText'    => (string) $opts['live_teaser_text'],
			'mobileSheet'   => (bool) $opts['live_mobile_sheet'],
			'maximize'      => (bool) $opts['live_maximize'],
			'showFooter'    => (bool) $opts['live_show_footer'],
			'theme'         => in_array( $opts['live_theme'], array( 'auto', 'light', 'dark' ), true ) ? $opts['live_theme'] : 'auto',
		);
	}

	/**
	 * پاسخ‌های آمادهٔ اپراتور.
	 *
	 * قالب هر خط: «متن پاسخ|عنوان دکمه»
	 *
	 * @return array
	 */
	public function canned_responses() {
		$opts  = Pasokhban_Settings::instance()->get_options();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $opts['canned'] );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = explode( '|', $line );
			$text  = trim( (string) array_shift( $parts ) );
			$label = $parts ? trim( implode( '|', $parts ) ) : '';

			if ( '' === $text ) {
				continue;
			}
			if ( '' === $label ) {
				$label = mb_substr( $text, 0, 28 );
			}

			$out[] = array(
				'text'  => $text,
				'label' => $label,
			);

			if ( count( $out ) >= 12 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param string $lang
	 * @return string
	 */
	private function live_greeting( $lang ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( $opts['live_greeting'] ) {
			return $opts['live_greeting'];
		}

		return 'en' === $lang
			? 'Hi! How can we help you today?'
			: 'سلام! چطور می‌تونیم کمکت کنیم؟';
	}

	/**
	 * دکمه‌های پیشنهادی شروع گفتگو.
	 *
	 * @param string $lang
	 * @return array
	 */
	private function starter_questions( $lang ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( '' !== trim( (string) $opts['live_starters'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $opts['live_starters'] );
			$out   = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$out[] = $line;
				}
			}
			return array_slice( $out, 0, 6 );
		}

		if ( 'en' === $lang ) {
			return array( 'I need help', 'What are your prices?', 'Talk to a human' );
		}

		return array( 'راهنمایی می‌خواهم', 'هزینه‌ها چقدر است؟', 'گپ با پشتیبان' );
	}

	/**
	 * @param string $lang
	 * @return string
	 */
	private function clean_lang( $lang ) {
		$lang = sanitize_key( (string) $lang );
		return in_array( $lang, array( 'fa', 'en' ), true ) ? $lang : 'fa';
	}

	/**
	 * شکل‌دهی ردیف نشست برای JSON (بدون افشای کلید نشست به مدیر هم لازم نیست،
	 * ولی برای لینک اشتراک‌گذاری نگهش می‌داریم).
	 *
	 * @param object $s
	 * @return array
	 */
	private function shape_session( $s ) {
		$name = $s->visitor_name ? $s->visitor_name : __( 'بازدیدکننده', 'pasokhban' );

		$assigned = isset( $s->assigned_to ) ? (int) $s->assigned_to : 0;

		return array(
			'assignedTo'    => $assigned,
			'assignedName'  => $assigned > 0 ? Pasokhban_Team::agent_name( $assigned ) : '',
			'id'            => (int) $s->id,
			'key'           => $s->session_key,
			'name'          => $name,
			'family'        => isset( $s->visitor_family ) ? $s->visitor_family : '',
			'phone'         => isset( $s->visitor_phone ) ? $s->visitor_phone : '',
			'email'         => $s->visitor_email,
			'ip'            => $s->ip,
			'lang'          => $s->lang,
			'mode'          => $s->mode,
			'status'        => $s->status,
			'unreadAgent'   => (int) $s->unread_agent,
			'unreadVisitor' => (int) $s->unread_visitor,
			'lastMessage'   => $s->last_message,
			'lastSender'    => $s->last_sender,
			'entryUrl'      => $s->entry_url,
			'currentUrl'    => $s->current_url,
			'createdAt'     => Pasokhban_Jalali::format_mysql( $s->created_at ),
			'updatedAt'     => Pasokhban_Jalali::format_mysql( $s->updated_at ),
			'createdAtRaw'  => $s->created_at,
			'updatedAtRaw'  => $s->updated_at,
		);
	}
}
