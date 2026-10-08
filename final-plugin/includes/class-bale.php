<?php
/**
 * کانال بله — پاسخ‌بان.
 *
 * چرا بله: تلگرام در ایران فیلتر است و واتساپ هم ناپایدار. از پیام‌رسان‌های
 * داخلی، طبق مستندات رسمی (docs.bale.ai) فقط بله API کامل دارد — ایتا سه
 * متد ارسال دارد و هیچ راهی برای دریافت پیام ندارد، و روبیکا API رسمی ندارد.
 *
 * خوشبختانه API بله «بر پایهٔ API بات تلگرام با تغییرات جزئی» است، پس
 * شکل درخواست‌ها (`/bot<token>/sendMessage`) همان تلگرام است.
 *
 * ── دو حالت ──
 * ۱) اعلان یک‌طرفه: پیام بازدیدکننده به گروه پشتیبانی در بله می‌رود.
 * ۲) پاسخ دوطرفه: اپراتور از داخل بله جواب می‌دهد و پاسخ در چت سایت
 *    می‌نشیند. برای همین یک webhook لازم است.
 *
 * ── مسئلهٔ نگاشت (مهم‌ترین بخش امنیتی) ──
 * همهٔ مکالمات سایت به یک گروه پشتیبانی در بله می‌روند. پس باید بدانیم
 * پاسخ اپراتور مربوط به کدام مکالمهٔ سایت است. راه‌حل: وقتی اعلان را
 * می‌فرستیم، `message_id` برگشتی بله را با `session_id` ذخیره می‌کنیم.
 * وقتی اپراتور **روی همان پیام reply می‌زند**، بله در آپدیت
 * `reply_to_message.message_id` را می‌دهد و ما مکالمه را پیدا می‌کنیم.
 *
 * اگر اپراتور بدون reply پیام بدهد، نگاشتی وجود ندارد و پیام نادیده
 * گرفته می‌شود — این عمدی است. وگرنه هر پیامی در گروه، به یک مکالمهٔ
 * تصادفی تزریق می‌شد.
 *
 * @package Pasokhban
 * @since   2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس کانال بله.
 */
final class Pasokhban_Bale {

	const API_BASE = 'https://tapi.bale.ai';

	/** آخرین خطا — برای نمایش در صفحهٔ تنظیمات. */
	public static $last_error = '';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'pasokhban_visitor_message', array( __CLASS__, 'on_visitor_message' ), 25, 2 );
		add_action( 'pasokhban_handoff_requested', array( __CLASS__, 'on_handoff' ), 25 );
		add_action( 'admin_post_pasokhban_bale_webhook', array( __CLASS__, 'handle_webhook_action' ) );
		add_action( 'admin_post_pasokhban_bale_test', array( __CLASS__, 'handle_test' ) );
	}

	public static function register_routes() {
		// webhook عمومی است — احراز هویتش از راه secret در مسیر URL است.
		register_rest_route( 'pasokhban/v1', '/bale/webhook/(?P<secret>[a-zA-Z0-9]+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		if ( ! class_exists( 'Pasokhban_Settings' ) ) {
			return false;
		}
		$opts = Pasokhban_Settings::instance()->get_options();
		return ! empty( $opts['bale_enabled'] ) && '' !== trim( (string) $opts['bale_token'] );
	}

	/* =========================================================
	 * ارسال
	 * =======================================================*/

	/**
	 * ارسال پیام به بله.
	 *
	 * @param int    $chat_id
	 * @param string $text
	 * @param int    $reply_to اگر بزرگ‌تر از صفر باشد، به آن پیام reply می‌زند
	 * @return int|false شناسهٔ پیام ارسال‌شده، یا false
	 */
	public static function send( $chat_id, $text, $reply_to = 0 ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( ! self::enabled() ) {
			self::$last_error = __( 'کانال بله فعال نیست یا توکن تنظیم نشده.', 'pasokhban' );
			return false;
		}

		$text = trim( (string) $text );
		if ( '' === $text ) {
			self::$last_error = __( 'متن پیام خالی است.', 'pasokhban' );
			return false;
		}

		$payload = array(
			'chat_id'    => $chat_id,
			'text'       => mb_substr( $text, 0, 3500 ),
			'parse_mode' => 'Markdown',
		);
		if ( $reply_to > 0 ) {
			$payload['reply_to_message_id'] = (int) $reply_to;
		}

		$url = self::API_BASE . '/bot' . rawurlencode( (string) $opts['bale_token'] ) . '/sendMessage';

		$response = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			self::$last_error = $response->get_error_message();
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			self::$last_error = sprintf(
				/* translators: 1: HTTP code, 2: body */
				__( 'پاسخ بله JSON نبود (http %1$s): %2$s', 'pasokhban' ),
				$code,
				mb_substr( $raw, 0, 120 )
			);
			return false;
		}

		if ( empty( $data['ok'] ) ) {
			self::$last_error = isset( $data['description'] )
				? sanitize_text_field( (string) $data['description'] )
				: sprintf( __( 'بله خطا داد (http %d).', 'pasokhban' ), $code );
			return false;
		}

		self::$last_error = '';
		return isset( $data['result']['message_id'] ) ? (int) $data['result']['message_id'] : 0;
	}

	/* =========================================================
	 * اعلان‌ها
	 * =======================================================*/

	/**
	 * وقتی بازدیدکننده پیام می‌دهد.
	 *
	 * @param object $session
	 * @param object $message
	 */
	public static function on_visitor_message( $session, $message ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( ! self::enabled() || empty( $opts['bale_notify'] ) || empty( $opts['bale_chat'] ) ) {
			return false;
		}

		// فقط وقتی مکالمه در حالت اپراتور است اعلان بده؛ وگرنه برای هر
		// پیامی که AI جواب می‌دهد هم به گروه پیام می‌رفت.
		if ( 'agent' !== ( isset( $session->mode ) ? $session->mode : '' ) ) {
			return false;
		}

		$name = trim( (string) $session->visitor_name . ' ' . ( isset( $session->visitor_family ) ? (string) $session->visitor_family : '' ) );
		if ( '' === trim( $name ) ) {
			$name = __( 'بازدیدکننده', 'pasokhban' );
		}

		$text = sprintf(
			/* translators: 1: visitor name, 2: session id, 3: message */
			"💬 *%1\$s* (مکالمهٔ #%2\$d)\n\n%3\$s\n\n_برای پاسخ، روی همین پیام reply بزن._",
			$name,
			(int) $session->id,
			(string) $message->content
		);

		if ( ! empty( $session->visitor_phone ) ) {
			$text .= "\n📞 " . $session->visitor_phone;
		}

		$mid = self::send( $opts['bale_chat'], $text );
		if ( false === $mid || $mid <= 0 ) {
			return false;
		}

		// ★ نگاشت: message_id بله → session_id سایت
		Pasokhban_DB::bale_link( $mid, (int) $session->id );

		return true;
	}

	/**
	 * وقتی مشتری «گپ با پشتیبان» می‌زند.
	 *
	 * @param object $session
	 */
	public static function on_handoff( $session ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( ! self::enabled() || empty( $opts['bale_notify'] ) || empty( $opts['bale_chat'] ) ) {
			return false;
		}

		$name = trim( (string) $session->visitor_name . ' ' . ( isset( $session->visitor_family ) ? (string) $session->visitor_family : '' ) );
		if ( '' === trim( $name ) ) {
			$name = __( 'بازدیدکننده', 'pasokhban' );
		}

		$text = sprintf(
			/* translators: 1: visitor name, 2: session id */
			"🔔 *%1\$s* درخواست پشتیبان انسانی دارد (مکالمهٔ #%2\$d)\n\n_برای پاسخ، روی همین پیام reply بزن._",
			$name,
			(int) $session->id
		);

		$mid = self::send( $opts['bale_chat'], $text );
		if ( false === $mid || $mid <= 0 ) {
			return false;
		}

		Pasokhban_DB::bale_link( $mid, (int) $session->id );

		return true;
	}

	/* =========================================================
	 * دریافت پاسخ اپراتور (webhook)
	 * =======================================================*/

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		// ── ۱) احراز هویت با secret در مسیر ──
		// webhook عمداً عمومی است (بله باید بتواند صدا بزند)، پس رمز باید
		// در خود آدرس باشد. بدون این، هر کسی می‌توانست آپدیت جعلی بفرستد
		// و به نام اپراتور در چت سایت پیام بگذارد.
		$want = (string) $opts['bale_webhook_secret'];
		$got  = (string) $request->get_param( 'secret' );

		if ( '' === $want || ! hash_equals( $want, $got ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'forbidden' ) );
		}

		if ( ! self::enabled() || empty( $opts['bale_twoway'] ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'disabled' ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$raw  = (string) $request->get_body();
			$body = json_decode( $raw, true );
		}
		if ( ! is_array( $body ) || empty( $body['message'] ) || ! is_array( $body['message'] ) ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'not-a-message' ) );
		}

		$msg  = $body['message'];
		$text = isset( $msg['text'] ) ? trim( (string) $msg['text'] ) : '';

		if ( '' === $text ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'no-text' ) );
		}

		// ── ۲) نگاشت از راه reply_to_message ──
		$reply_to = 0;
		if ( ! empty( $msg['reply_to_message']['message_id'] ) ) {
			$reply_to = (int) $msg['reply_to_message']['message_id'];
		}

		if ( $reply_to < 1 ) {
			// بدون reply نمی‌دانیم مربوط به کدام مکالمه است. عمداً نادیده
			// می‌گیریم؛ وگرنه هر پیامی در گروه به یک مکالمهٔ تصادفی می‌رفت.
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'no-reply-target' ) );
		}

		$session_id = Pasokhban_DB::bale_session_for( $reply_to );
		if ( ! $session_id ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'unknown-target' ) );
		}

		$session = Pasokhban_DB::get_session( $session_id );
		if ( ! $session ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'no-session' ) );
		}

		// ── ۳) ثبت به‌عنوان پیام اپراتور ──
		$from   = '';
		if ( ! empty( $msg['from']['first_name'] ) ) {
			$from = sanitize_text_field( (string) $msg['from']['first_name'] );
		}

		$saved = Pasokhban_DB::add_message(
			$session_id,
			'agent',
			mb_substr( $text, 0, 4000 ),
			array(
				'via'      => 'bale',
				'bale_from' => $from,
				'bale_mid'  => isset( $msg['message_id'] ) ? (int) $msg['message_id'] : 0,
			)
		);

		if ( ! $saved ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'db' ) );
		}

		Pasokhban_DB::update_session( $session_id, array(
			'mode'           => 'agent',
			'unread_visitor' => (int) $session->unread_visitor + 1,
			'unread_agent'   => 0,
		) );
		Pasokhban_DB::touch_session( $session_id, mb_substr( $text, 0, 200 ), 'agent' );

		return rest_ensure_response( array(
			'ok'      => true,
			'delivered' => true,
			'session' => (int) $session_id,
		) );
	}

	/* =========================================================
	 * ثبت / لغو webhook
	 * =======================================================*/

	/**
	 * آدرس webhook — شامل secret در مسیر.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		$opts   = Pasokhban_Settings::instance()->get_options();
		$secret = (string) $opts['bale_webhook_secret'];
		if ( '' === $secret ) {
			return '';
		}
		return rest_url( 'pasokhban/v1/bale/webhook/' . rawurlencode( $secret ) );
	}

	/**
	 * @param string $method setWebhook | deleteWebhook | getWebhookInfo
	 * @param array  $args
	 * @return array|WP_Error
	 */
	private static function call_api( $method, array $args = array() ) {
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( ! self::enabled() ) {
			return new WP_Error( 'pasokhban_bale_off', __( 'کانال بله فعال نیست.', 'pasokhban' ) );
		}

		$url      = self::API_BASE . '/bot' . rawurlencode( (string) $opts['bale_token'] ) . '/' . $method;
		$response = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $args ? wp_json_encode( $args ) : '{}',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pasokhban_bale_bad', __( 'پاسخ بله JSON نبود.', 'pasokhban' ) );
		}
		if ( empty( $data['ok'] ) ) {
			return new WP_Error(
				'pasokhban_bale_api',
				isset( $data['description'] ) ? sanitize_text_field( (string) $data['description'] ) : __( 'بله خطا داد.', 'pasokhban' )
			);
		}

		return isset( $data['result'] ) ? $data['result'] : true;
	}

	/**
	 * اکشن ثبت / لغو / آزمایش webhook از صفحهٔ تنظیمات.
	 */
	public static function handle_webhook_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_bale_webhook' );

		$do  = isset( $_POST['do'] ) ? sanitize_key( (string) wp_unslash( $_POST['do'] ) ) : '';
		$res = array( 'ok' => false, 'text' => '' );

		if ( 'set' === $do ) {
			$url = self::webhook_url();
			if ( '' === $url ) {
				$res['text'] = __( 'رمز webhook ساخته نشده. یک‌بار تنظیمات را ذخیره کن تا ساخته شود.', 'pasokhban' );
			} else {
				// بله فقط پورت‌های 443 و 88 را برای webhook می‌پذیرد
				$port = wp_parse_url( $url, PHP_URL_PORT );
				if ( $port && ! in_array( (int) $port, array( 443, 88 ), true ) ) {
					$res['text'] = sprintf(
						/* translators: %s: port */
						__( 'بله فقط پورت ۴۴۳ و ۸۸ را برای webhook می‌پذیرد، ولی آدرس سایت تو پورت %s دارد.', 'pasokhban' ),
						$port
					);
				} else {
					$out = self::call_api( 'setWebhook', array( 'url' => $url ) );
					if ( is_wp_error( $out ) ) {
						$res['text'] = $out->get_error_message();
					} else {
						$res['ok']   = true;
						$res['text'] = __( 'webhook ثبت شد. حالا اپراتور می‌تواند از داخل بله reply بزند.', 'pasokhban' );
					}
				}
			}
		} elseif ( 'delete' === $do ) {
			$out = self::call_api( 'deleteWebhook' );
			if ( is_wp_error( $out ) ) {
				$res['text'] = $out->get_error_message();
			} else {
				$res['ok']   = true;
				$res['text'] = __( 'webhook لغو شد.', 'pasokhban' );
			}
		} elseif ( 'info' === $do ) {
			$out = self::call_api( 'getWebhookInfo' );
			if ( is_wp_error( $out ) ) {
				$res['text'] = $out->get_error_message();
			} else {
				$cur = is_array( $out ) && ! empty( $out['url'] ) ? (string) $out['url'] : '';
				$res['ok']   = true;
				$res['text'] = '' === $cur
					? __( 'webhook ثبت نشده است.', 'pasokhban' )
					: __( 'webhook فعلی: ', 'pasokhban' ) . $cur;
			}
		} else {
			$res['text'] = __( 'عملیات نامعتبر.', 'pasokhban' );
		}

		set_transient( 'pasokhban_bale_notice', $res, 2 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=pasokhban-settings#notify' ) );
		exit;
	}

	/**
	 * ارسال پیام آزمایشی.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_bale_test' );

		$opts  = Pasokhban_Settings::instance()->get_options();
		$chat  = trim( (string) $opts['bale_chat'] );
		$res   = array( 'ok' => false, 'text' => '' );

		if ( '' === $chat ) {
			$res['text'] = __( 'شناسهٔ گفتگو/گروه بله تنظیم نشده.', 'pasokhban' );
		} else {
			$mid = self::send( $chat, __( '🧪 این یک پیام آزمایشی از پاسخ‌بان است.', 'pasokhban' ) );
			if ( false === $mid ) {
				$res['text'] = self::$last_error;
			} else {
				$res['ok']   = true;
				$res['text'] = sprintf(
					/* translators: %d: bale message id */
					__( 'ارسال شد (شناسهٔ پیام: %d). گروه بله را چک کن.', 'pasokhban' ),
					$mid
				);
			}
		}

		set_transient( 'pasokhban_bale_notice', $res, 2 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=pasokhban-settings#notify' ) );
		exit;
	}
}
