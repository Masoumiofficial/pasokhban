<?php
/**
 * اعلان به مدیر وقتی بازدیدکننده پیام می‌دهد.
 *
 * دو کانال: ایمیل و تلگرام. هر دو اختیاری‌اند و هر دو throttle دارند
 * تا برای هر پیام یک اعلان نرود.
 *
 * @package Pasokhban
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس اعلان‌ها.
 */
final class Pasokhban_Notify {

	const PREFIX = 'pasokhban_notified_';

	/** @var array نتیجهٔ آخرین ارسال (برای تست و نوار وضعیت) */
	public static $last_result = array();

	public static function hooks() {
		add_action( 'pasokhban_visitor_message', array( __CLASS__, 'on_visitor_message' ), 10, 2 );
		add_action( 'admin_post_pasokhban_test_notify', array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * وقتی بازدیدکننده پیام می‌دهد.
	 *
	 * @param object $session
	 * @param object $message
	 */
	public static function on_visitor_message( $session, $message ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['notify_email'] ) && empty( $opts['notify_telegram'] ) ) {
			return;
		}

		// اگر اپراتور همین حالا آنلاین است، اعلان نفرست
		if ( ! empty( $opts['notify_only_offline'] ) && get_transient( Pasokhban_LiveChat::HEARTBEAT ) ) {
			return;
		}

		if ( ! self::should_notify( (int) $session->id, (int) $opts['notify_min_gap'] ) ) {
			return;
		}

		$name = trim( $session->visitor_name . ' ' . ( isset( $session->visitor_family ) ? $session->visitor_family : '' ) );
		if ( '' === $name ) {
			$name = __( 'بازدیدکننده', 'pasokhban' );
		}

		$body = sprintf(
			/* translators: 1: visitor name, 2: message */
			"%s\n\n— %s",
			$message->content,
			$name
		);
		if ( ! empty( $session->visitor_phone ) ) {
			$body .= "\n📞 " . $session->visitor_phone;
		}

		$title = sprintf(
			/* translators: %s: visitor name */
			__( 'پیام تازه از %s', 'pasokhban' ),
			$name
		);

		if ( ! empty( $opts['notify_email'] ) ) {
			self::send_email( $opts, $title, $body, $session );
		}
		if ( ! empty( $opts['notify_telegram'] ) ) {
			self::send_telegram( $opts, $title, $body, $session );
		}
	}

	/**
	 * آیا برای این نشست اجازهٔ اعلان داریم؟ (throttle)
	 *
	 * @param int $session_id
	 * @param int $min_gap_minutes
	 * @return bool
	 */
	public static function should_notify( $session_id, $min_gap_minutes ) {
		$key = self::PREFIX . (int) $session_id;

		if ( get_transient( $key ) ) {
			return false;
		}

		$gap = max( 0, (int) $min_gap_minutes );
		if ( $gap > 0 ) {
			set_transient( $key, 1, $gap * MINUTE_IN_SECONDS );
		}

		return true;
	}

	/**
	 * ارسال ایمیل.
	 *
	 * @param array  $opts
	 * @param string $title
	 * @param string $body
	 * @param object $session
	 * @return bool
	 */
	private static function send_email( $opts, $title, $body, $session ) {
		$to = $opts['notify_email_to'] ? $opts['notify_email_to'] : get_option( 'admin_email' );

		$inbox = admin_url( 'admin.php?page=pasokhban-inbox' );
		$body .= "\n\n" . __( 'باز کردن گفتگو:', 'pasokhban' ) . "\n" . $inbox;

		if ( ! empty( $session->current_url ) ) {
			$body .= "\n" . __( 'صفحه:', 'pasokhban' ) . ' ' . $session->current_url;
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $to, $title, $body, $headers );

		self::$last_result['email'] = $sent ? 'ok' : 'fail';
		return (bool) $sent;
	}

	/**
	 * ارسال تلگرام.
	 *
	 * @param array  $opts
	 * @param string $title
	 * @param string $body
	 * @param object $session
	 * @return bool
	 */
	private static function send_telegram( $opts, $title, $body, $session ) {
		$token   = trim( (string) $opts['notify_telegram_token'] );
		$chat_id = trim( (string) $opts['notify_telegram_chat'] );

		if ( '' === $token || '' === $chat_id ) {
			self::$last_result['telegram'] = 'unconfigured';
			return false;
		}

		$inbox = admin_url( 'admin.php?page=pasokhban-inbox' );

		$text = "🔔 *{$title}*\n\n" . $body . "\n\n[➡ " . __( 'باز کردن اینباکس', 'pasokhban' ) . "]({$inbox})";

		$fields = array(
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'parse_mode'               => 'Markdown',
			'disable_web_page_preview' => true,
		);

		// ── رلهٔ گوگل ──
		// سرورهای ایران به api.telegram.org دسترسی ندارند ولی گوگل در دسترس است
		// و سرور گوگل می‌تواند به تلگرام برسد. اگر رله تنظیم شده باشد،
		// پروکسی و آدرس پایه نادیده گرفته می‌شوند.
		$relay = ! empty( $opts['notify_telegram_relay'] ) ? (string) $opts['notify_telegram_relay'] : '';
		if ( '' !== $relay ) {
			$secret = ! empty( $opts['notify_telegram_secret'] ) ? (string) $opts['notify_telegram_secret'] : '';
			return self::send_via_relay( $relay, $token, $fields, $secret );
		}

		$base = ! empty( $opts['notify_telegram_base'] )
			? untrailingslashit( (string) $opts['notify_telegram_base'] )
			: 'https://api.telegram.org';

		$url = $base . '/bot' . rawurlencode( $token ) . '/sendMessage';

		$proxy = ! empty( $opts['notify_telegram_proxy'] ) ? (string) $opts['notify_telegram_proxy'] : '';

		// اگر پروکسی تنظیم شده باشد از cURL استفاده می‌کنیم، چون wp_remote_post
		// پروکسی در هر درخواست را پشتیبانی نمی‌کند (فقط ثابت‌های سراسری WP_PROXY_*).
		// این برای سرورهای ایران که به api.telegram.org دسترسی ندارند لازم است.
		if ( '' !== $proxy && function_exists( 'curl_init' ) ) {
			return self::send_via_curl( $url, $fields, $proxy );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'body'    => $fields,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::$last_result['telegram'] = 'network: ' . $response->get_error_message();
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && ! empty( $data['ok'] ) ) {
			self::$last_result['telegram'] = 'ok';
			return true;
		}

		self::$last_result['telegram'] = isset( $data['description'] )
			? sanitize_text_field( $data['description'] )
			: 'http ' . $code;

		return false;
	}

	/**
	 * ارسال از راه رلهٔ گوگل (Google Apps Script Web App).
	 *
	 * پلاگین پارامترها را به اسکریپت گوگل می‌فرستد و آن اسکریپت
	 * درخواست sendMessage را به تلگرام می‌زند.
	 *
	 * @param string $relay
	 * @param string $token
	 * @param array  $fields
	 * @param string $secret کلید مشترک با اسکریپت گوگل
	 * @return bool
	 */
	private static function send_via_relay( $relay, $token, array $fields, $secret = '' ) {
		$payload = array(
			'token'      => $token,
			'chat_id'    => $fields['chat_id'],
			'text'       => $fields['text'],
			'parse_mode' => isset( $fields['parse_mode'] ) ? $fields['parse_mode'] : '',
			'secret'     => $secret,
		);

		// ── گام ۱: POST به /exec، بدون دنبال‌کردن ریدایرکت ──
		//
		// چرا redirection=0: وب‌اپ گوگل پاسخ POST را با 302 به
		// script.googleusercontent.com/macros/echo می‌دهد و خروجی اسکریپت
		// آنجا است. آن endpoint فقط GET قبول می‌کند. اگر کلاینت HTTP
		// ریدایرکت را با همان POST دنبال کند (که روی بعضی هاست‌ها و بعضی
		// نسخه‌های cURL همین‌طور است)، گوگل 400/405 با یک صفحهٔ HTML
		// شامل window['ppConfig'] برمی‌گرداند.
		//
		// نکتهٔ فریب‌کارانه: اسکریپت گوگل قبل از برگرداندن 302 اجرا شده و
		// پیام را به تلگرام فرستاده است. یعنی پیام می‌رسد ولی پلاگین
		// «شکست» گزارش می‌کند. ریدایرکت را خودمان و با GET دنبال می‌کنیم
		// تا رفتار روی همهٔ هاست‌ها یکی شود.
		$response = wp_remote_post(
			$relay,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'        => http_build_query( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::$last_result['telegram'] = 'relay: ' . $response->get_error_message();
			return false;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = '';
		if ( function_exists( 'wp_remote_retrieve_header' ) ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
		}

		// ── گام ۲: خروجی واقعی اسکریپت را با GET بگیر ──
		if ( $code >= 300 && $code < 400 && '' !== $location ) {
			$final = wp_remote_get(
				$location,
				array( 'timeout' => 30, 'redirection' => 5 )
			);

			if ( is_wp_error( $final ) ) {
				self::$last_result['telegram'] = 'relay: ' . $final->get_error_message();
				return false;
			}

			$code = (int) wp_remote_retrieve_response_code( $final );
			$response = $final;
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		// ── گام ۳: تفسیر پاسخ ──
		// بعضی اسکریپت‌های رله JSON برمی‌گردانند و بعضی متن ساده. هر دو
		// را می‌فهمیم؛ وگرنه یک رلهٔ سالم که متن «OK» می‌دهد همیشه
		// «شکست» گزارش می‌شد.
		if ( is_array( $data ) ) {
			if ( 200 === $code && ! empty( $data['ok'] ) ) {
				self::$last_result['telegram'] = 'ok (relay)';
				return true;
			}
			if ( isset( $data['error'] ) ) {
				self::$last_result['telegram'] = 'relay: ' . sanitize_text_field( $data['error'] );
				return false;
			}
			if ( isset( $data['description'] ) ) {
				self::$last_result['telegram'] = 'relay: ' . sanitize_text_field( $data['description'] );
				return false;
			}
		}

		$text = trim( wp_strip_all_tags( $raw ) );

		if ( self::looks_like_html( $raw ) ) {
			self::$last_result['telegram'] = sprintf(
				/* translators: %d: HTTP status code */
				__( 'رله صفحهٔ HTML برگرداند (http %d). اگر پیام آزمایشی به تلگرامت رسید، یعنی ارسال موفق بوده و فقط خواندن پاسخ مشکل دارد؛ پلاگین را به‌روز کن. اگر نرسید، بررسی کن آدرس به /exec ختم شود و در Deploy دسترسی روی Anyone باشد.', 'pasokhban' ),
				$code
			);
			return false;
		}

		// پاسخ متنی: اگر 200 است و بوی خطا نمی‌دهد، موفق حساب کن.
		if ( 200 === $code && '' !== $text && ! self::looks_like_error_text( $text ) ) {
			self::$last_result['telegram'] = 'ok (relay)';
			return true;
		}

		self::$last_result['telegram'] = 'relay: http ' . $code . ' ' . sanitize_text_field( mb_substr( $text, 0, 160 ) );
		return false;
	}

	/**
	 * آیا یک پاسخ متنی، بوی خطا می‌دهد؟
	 *
	 * بعضی اسکریپت‌های رله به‌جای JSON متن ساده برمی‌گردانند. بدون این
	 * بررسی، «Error! Bot token not provided» با کد 200 به‌عنوان موفقیت
	 * ثبت می‌شد.
	 *
	 * @param string $text
	 * @return bool
	 */
	private static function looks_like_error_text( $text ) {
		$t = strtolower( (string) $text );
		return (bool) preg_match( '/^(error|exception|fail|failed|denied|unauthorized|invalid|not authorized|script function)/', $t )
			|| false !== strpos( $t, 'not provided' )
			|| false !== strpos( $t, 'mismatch' )
			|| false !== strpos( $t, 'bad request' );
	}

	/**
	 * آیا پاسخ یک صفحهٔ HTML است (نه JSON)؟
	 *
	 * وقتی Google Apps Script درست دیپلوی نشده باشد، به‌جای اجرای اسکریپت
	 * صفحهٔ لاگین/خطای HTML برمی‌گرداند (با متغیرهایی مثل window['ppConfig']).
	 *
	 * @param string $raw
	 * @return bool
	 */
	private static function looks_like_html( $raw ) {
		$head = ltrim( mb_substr( (string) $raw, 0, 400 ) );
		if ( '' === $head ) {
			return false;
		}
		if ( 0 === stripos( $head, '<!doctype' ) || 0 === stripos( $head, '<html' ) ) {
			return true;
		}
		if ( false !== strpos( $head, 'ppConfig' ) || false !== strpos( $head, '<script' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * ارسال با cURL از راه پروکسی (HTTP/HTTPS/SOCKS5/SOCKS5H).
	 *
	 * @param string $url
	 * @param array  $fields
	 * @param string $proxy
	 * @return bool
	 */
	private static function send_via_curl( $url, array $fields, $proxy ) {
		$ch = curl_init();

		$parts  = wp_parse_url( $proxy );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';

		// آدرس پروکسی بدون scheme — cURL خودش نوع را از CURLOPT_PROXYTYPE می‌گیرد
		$proxy_host = isset( $parts['host'] ) ? $parts['host'] : '';
		if ( ! empty( $parts['port'] ) ) {
			$proxy_host .= ':' . (int) $parts['port'];
		}

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $fields ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 25 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
		curl_setopt( $ch, CURLOPT_PROXY, $proxy_host );

		// نوع پروکسی
		if ( 'socks5h' === $scheme && defined( 'CURLPROXY_SOCKS5_HOSTNAME' ) ) {
			curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME );
		} elseif ( 'socks5' === $scheme && defined( 'CURLPROXY_SOCKS5' ) ) {
			curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5 );
		} else {
			curl_setopt( $ch, CURLOPT_PROXYTYPE, defined( 'CURLPROXY_HTTP' ) ? CURLPROXY_HTTP : 0 );
		}

		if ( ! empty( $parts['user'] ) ) {
			$auth = $parts['user'];
			if ( ! empty( $parts['pass'] ) ) {
				$auth .= ':' . $parts['pass'];
			}
			curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $auth );
		}

		$body   = curl_exec( $ch );
		$code   = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err    = curl_error( $ch );
		$errno  = curl_errno( $ch );
		curl_close( $ch );

		if ( false === $body || 0 === $code ) {
			self::$last_result['telegram'] = sprintf( 'proxy: %s (%d)', $err ? $err : 'no response', $errno );
			return false;
		}

		$data = json_decode( (string) $body, true );

		if ( 200 === $code && ! empty( $data['ok'] ) ) {
			self::$last_result['telegram'] = 'ok';
			return true;
		}

		self::$last_result['telegram'] = isset( $data['description'] )
			? sanitize_text_field( $data['description'] )
			: 'http ' . $code;

		return false;
	}

	/**
	 * دکمهٔ «تست اعلان» در تنظیمات.
	 */
	/**
	 * اعلان فوری و بدون throttle.
	 *
	 * برای رویدادهای مهم مثل «درخواست پشتیبان انسانی» که نباید
	 * با throttle معمولی خفه شوند.
	 *
	 * @param string $title
	 * @param string $body
	 * @return bool
	 */
	public static function notify_now( $title, $body ) {
		$opts = Pasokhban_Settings::instance()->get_options();
		$sent = false;

		$fake = (object) array(
			'id'            => 0,
			'visitor_name'  => '',
			'visitor_phone' => '',
			'current_url'   => '',
		);

		if ( ! empty( $opts['notify_email'] ) ) {
			$ok   = self::send_email( $opts, $title, $body, $fake );
			$sent = $sent || $ok;
		}

		if ( ! empty( $opts['notify_telegram'] )
			&& ! empty( $opts['notify_telegram_token'] )
			&& ! empty( $opts['notify_telegram_chat'] ) ) {

			$has_route = ! empty( $opts['notify_telegram_relay'] )
				|| ! empty( $opts['notify_telegram_proxy'] )
				|| ! empty( $opts['notify_telegram_base'] );

			if ( $has_route ) {
				$ok   = self::send_telegram( $opts, $title, $body, $fake );
				$sent = $sent || $ok;
			}
		}

		return $sent;
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_test_notify' );

		$opts    = Pasokhban_Settings::instance()->get_options();
		$results = array();

		$fake = (object) array(
			'id'            => 0,
			'visitor_name'  => __( 'تست', 'pasokhban' ),
			'visitor_family' => '',
			'visitor_phone' => '09120000000',
			'current_url'   => home_url( '/' ),
		);

		$msg = (object) array( 'content' => __( 'این یک پیام آزمایشی از پاسخ‌بان است.', 'pasokhban' ) );

		if ( ! empty( $opts['notify_email'] ) ) {
			$ok                 = self::send_email( $opts, __( '[پاسخ‌بان] پیام آزمایشی', 'pasokhban' ), $msg->content, $fake );
			$results['email']   = $ok ? 'ok' : 'fail';
		}

		if ( ! empty( $opts['notify_telegram'] ) ) {
			if ( empty( $opts['notify_telegram_token'] ) || empty( $opts['notify_telegram_chat'] ) ) {
				$results['telegram'] = 'unconfigured';
			} elseif ( empty( $opts['notify_telegram_relay'] ) && empty( $opts['notify_telegram_proxy'] ) && empty( $opts['notify_telegram_base'] ) ) {
				// هیچ مسیری برای عبور از فیلترینگ تنظیم نشده
				$results['telegram'] = 'no-route';
			} else {
				self::send_telegram( $opts, __( 'پیام آزمایشی پاسخ‌بان', 'pasokhban' ), $msg->content, $fake );
				$results['telegram'] = isset( self::$last_result['telegram'] ) ? self::$last_result['telegram'] : 'fail';
			}
		}

		// نتیجه را در transient می‌گذاریم، نه در آدرس URL.
		//
		// قبلاً نتیجه با query string برمی‌گشت. دو مشکل داشت:
		// ۱) آن پارامتر تا ابد در آدرس می‌ماند، پس یک خطای قدیمی هر بار
		//    که صفحه باز می‌شد دوباره نمایش داده می‌شد — حتی بعد از اینکه
		//    مشکل حل شده بود.
		// ۲) rawurlencode به‌علاوهٔ add_query_arg یعنی دوبار encode شدن و متن
		//    فارسی خراب می‌شد.
		// transient یک‌بارمصرف است: صفحه آن را می‌خواند و پاک می‌کند.
		set_transient( 'pasokhban_notify_test', $results, 2 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=pasokhban#notify' ) );
		exit;
	}
}
