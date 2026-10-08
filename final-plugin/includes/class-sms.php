<?php
/**
 * اعلان پیامکی — پاسخ‌بان.
 *
 * سه سرویس رایج ایرانی پشتیبانی می‌شود. هر سه API متفاوتی دارند، پس هر
 * کدام سازندهٔ درخواست خودش را دارد ولی یک مسیر ارسال مشترک.
 *
 * ── چرا محدودیت نرخ اینجا حیاتی است ──
 * پیامک پول نقد است. بدون throttle، یک اسپم‌بات که پشت‌سرهم پیام بفرستد
 * می‌تواند در چند دقیقه چند صد هزار تومان هزینه روی دست صاحب سایت بگذارد.
 * پس سقف پیامک به ازای هر نشست اجباری است و مستقل از rate_limit چت.
 *
 * @package Pasokhban
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس پیامک.
 */
final class Pasokhban_SMS {

	/** سقف پیامک به ازای هر نشست در ساعت. */
	const MAX_PER_SESSION = 3;

	/** سقف کلی پیامک در ساعت (برای همهٔ نشست‌ها). */
	const MAX_PER_HOUR = 30;

	public static function hooks() {
		add_action( 'pasokhban_visitor_message', array( __CLASS__, 'on_visitor_message' ), 20, 2 );
		add_action( 'admin_post_pasokhban_test_sms', array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		if ( ! class_exists( 'Pasokhban_Settings' ) ) {
			return false;
		}
		$opts = Pasokhban_Settings::instance()->get_options();
		return ! empty( $opts['sms_enabled'] )
			&& '' !== trim( (string) $opts['sms_api_key'] )
			&& in_array( $opts['sms_provider'], array( 'kavenegar', 'melipayamak', 'smsir' ), true );
	}

	/**
	 * وقتی بازدیدکننده پیام می‌دهد → پیامک به مدیر.
	 *
	 * @param object $session
	 * @param object $message
	 */
	public static function on_visitor_message( $session, $message ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( ! self::enabled() || empty( $opts['sms_notify_admin'] ) ) {
			return false;
		}

		$phone = self::normalize_phone( $opts['sms_admin_phone'] );
		if ( '' === $phone ) {
			return false;
		}

		if ( ! self::allow( (int) $session->id ) ) {
			return false;
		}

		$name = trim( (string) $session->visitor_name . ' ' . ( isset( $session->visitor_family ) ? (string) $session->visitor_family : '' ) );
		if ( '' === trim( $name ) ) {
			$name = __( 'بازدیدکننده', 'pasokhban' );
		}

		$text = sprintf(
			/* translators: 1: visitor name, 2: message */
			"پیام تازه از %s:\n%s\n(پیشخوان وردپرس ← پاسخ‌بان)",
			$name,
			self::clip( (string) $message->content, 140 )
		);

		return self::send( $phone, $text );
	}

	/**
	 * وقتی اپراتور پاسخ می‌دهد → پیامک به بازدیدکننده.
	 *
	 * فقط وقتی که شمارهٔ تماس از فرم پیش‌گفتگو گرفته شده باشد. بدون
	 * شماره هیچ پیامکی نمی‌رود.
	 *
	 * @param object $session
	 * @param string $text
	 * @return bool
	 */
	public static function notify_visitor( $session, $text ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( ! self::enabled() || empty( $opts['sms_notify_visitor'] ) ) {
			return false;
		}

		$phone = self::normalize_phone( isset( $session->visitor_phone ) ? $session->visitor_phone : '' );
		if ( '' === $phone ) {
			return false;
		}

		if ( ! self::allow( (int) $session->id, 'v' ) ) {
			return false;
		}

		return self::send( $phone, self::clip( (string) $text, 200 ) );
	}

	/* =========================================================
	 * محدودیت نرخ
	 * =======================================================*/

	/**
	 * آیا مجاز به ارسال هستیم؟
	 *
	 * @param int    $session_id
	 * @param string $tag تفکیک پیامک مدیر از مشتری
	 * @return bool
	 */
	public static function allow( $session_id, $tag = 'a' ) {
		$opts = Pasokhban_Settings::instance()->get_options();
		$gap  = max( 0, (int) $opts['sms_min_gap'] );

		// ۱) فاصلهٔ زمانی برای همین نشست
		if ( $gap > 0 ) {
			$gkey = 'pasokhban_sms_gap_' . $tag . '_' . (int) $session_id;
			if ( get_transient( $gkey ) ) {
				return false;
			}
			set_transient( $gkey, 1, $gap * MINUTE_IN_SECONDS );
		}

		// ۲) سقف به ازای هر نشست در ساعت
		$skey = 'pasokhban_sms_n_' . $tag . '_' . (int) $session_id;
		$sn   = (int) get_transient( $skey );
		if ( $sn >= self::MAX_PER_SESSION ) {
			return false;
		}
		set_transient( $skey, $sn + 1, HOUR_IN_SECONDS );

		// ۳) سقف کلی در ساعت — حتی اگر یک نشست اسپم نشود، ممکن است
		//    نشست‌های زیادی هم‌زمان ساخته شوند.
		$hkey = 'pasokhban_sms_hour';
		$hn   = (int) get_transient( $hkey );
		if ( $hn >= self::MAX_PER_HOUR ) {
			return false;
		}
		set_transient( $hkey, $hn + 1, HOUR_IN_SECONDS );

		return true;
	}

	/* =========================================================
	 * ارسال
	 * =======================================================*/

	/**
	 * @param string $phone
	 * @param string $text
	 * @return bool
	 */
	public static function send( $phone, $text ) {
		$opts  = Pasokhban_Settings::instance()->get_options();
		$phone = self::normalize_phone( $phone );
		$text  = trim( (string) $text );

		if ( '' === $phone || '' === $text ) {
			return false;
		}

		$res = self::build_request( $opts, $phone, $text );
		if ( is_wp_error( $res ) ) {
			self::$last_error = $res->get_error_message();
			return false;
		}

		$response = wp_remote_post( $res['url'], array(
			'timeout' => 20,
			'headers' => $res['headers'],
			'body'    => $res['body'],
		) );

		if ( is_wp_error( $response ) ) {
			self::$last_error = $response->get_error_message();
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			// هر سه سرویس در موفقیت یک نشانگر متفاوت دارند
			$ok = false;
			if ( isset( $data['return']['status'] ) ) {
				$ok = ( 200 === (int) $data['return']['status'] );   // کاوه‌نگار
			} elseif ( isset( $data['IsSuccessful'] ) ) {
				$ok = (bool) $data['IsSuccessful'];                  // ملی‌پیامک
			} elseif ( isset( $data['status'] ) && 200 === (int) $data['status'] ) {
				$ok = true;                                          // SMS.ir
			} elseif ( isset( $data['data'] ) ) {
				$ok = true;
			}
			if ( $ok ) {
				self::$last_error = '';
				return true;
			}
		}

		self::$last_error = self::extract_error( $data, $raw, $code );
		return false;
	}

	/** آخرین خطا — برای نمایش در صفحهٔ تنظیمات. */
	public static $last_error = '';

	/**
	 * ساخت درخواست متناسب با سرویس.
	 *
	 * @param array  $opts
	 * @param string $phone
	 * @param string $text
	 * @return array|WP_Error { url, headers, body }
	 */
	private static function build_request( array $opts, $phone, $text ) {
		$key    = trim( (string) $opts['sms_api_key'] );
		$sender = trim( (string) $opts['sms_sender'] );

		switch ( $opts['sms_provider'] ) {

			case 'kavenegar':
				// کاوه‌نگار توکن را در آدرس می‌گیرد
				$url = 'https://api.kavenegar.com/v1/' . rawurlencode( $key ) . '/sms/send.json';
				return array(
					'url'     => $url,
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => http_build_query( array(
						'receptor' => $phone,
						'message'  => $text,
						'sender'   => $sender,
					) ),
				);

			case 'melipayamak':
				// ملی‌پیامک احراز هویت Base64 دارد
				$auth = base64_encode( $sender . ':' . $key );
				return array(
					'url'     => 'https://rest.payamak-panel.com/api/SendSMS/SendSMS',
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Basic ' . $auth,
					),
					'body'    => wp_json_encode( array(
						'From'        => $sender,
						'To'          => $phone,
						'Text'        => $text,
						'IsFlash'     => false,
					) ),
				);

			case 'smsir':
				return array(
					'url'     => 'https://api.sms.ir/v1/send/bulk',
					'headers' => array(
						'Content-Type' => 'application/json',
						'x-api-key'    => $key,
					),
					'body'    => wp_json_encode( array(
						'lineNumber' => $sender,
						'messageText' => $text,
						'mobiles'    => array( $phone ),
					) ),
				);
		}

		return new WP_Error( 'pasokhban_sms_provider', __( 'سرویس پیامک نامعتبر است.', 'pasokhban' ) );
	}

	/**
	 * @param mixed  $data
	 * @param string $raw
	 * @param int    $code
	 * @return string
	 */
	private static function extract_error( $data, $raw, $code ) {
		if ( is_array( $data ) ) {
			foreach ( array( 'message', 'error', 'Message', 'Error' ) as $k ) {
				if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
					return sanitize_text_field( $data[ $k ] );
				}
			}
			if ( isset( $data['return']['message'] ) ) {
				return sanitize_text_field( (string) $data['return']['message'] );
			}
		}
		/* translators: 1: HTTP code, 2: raw body */
		return sprintf(
			__( 'پیامک ارسال نشد (http %1$s): %2$s', 'pasokhban' ),
			$code,
			sanitize_text_field( mb_substr( (string) $raw, 0, 120 ) )
		);
	}

	/* =========================================================
	 * ابزارها
	 * =======================================================*/

	/**
	 * نرمال‌سازی شمارهٔ موبایل ایران.
	 *
	 * @param string $phone
	 * @return string خالی یعنی نامعتبر
	 */
	public static function normalize_phone( $phone ) {
		$s = (string) $phone;

		// ★ ارقام فارسی/عربی اول باید لاتین شوند. وگرنه الگوی \D که فقط
		// ارقام ASCII را می‌شناسد، کل شمارهٔ فارسی را حذف می‌کرد و کاربری
		// که شماره را با کیبورد فارسی تایپ کرده بود هرگز پیامک نمی‌گرفت.
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

		// ۹۱۲۱۲۳۴۵۶۷ → ۰۹۱۲۱۲۳۴۵۶۷
		if ( 10 === strlen( $s ) && '9' === $s[0] ) {
			$s = '0' . $s;
		}

		// موبایل ایران: ۰۹xxxxxxxxx
		if ( ! preg_match( '/^09\d{9}$/', $s ) ) {
			return '';
		}

		return $s;
	}

	/**
	 * @param string $text
	 * @param int    $max
	 * @return string
	 */
	private static function clip( $text, $max = 200 ) {
		$t = trim( (string) $text );
		// پیامک خط جدید چندگانه را دوست ندارد
		$t = preg_replace( '/\s*\n\s*/', ' — ', $t );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $t ) > $max ) {
			$t = mb_substr( $t, 0, $max ) . '…';
		}
		return $t;
	}

	/**
	 * آزمون ارسال از صفحهٔ تنظیمات.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_test_sms' );

		$opts  = Pasokhban_Settings::instance()->get_options();
		$phone = self::normalize_phone( $opts['sms_admin_phone'] );

		if ( '' === $phone ) {
			$res = array(
				'ok'      => false,
				'message' => __( 'شمارهٔ موبایل مدیر تنظیم نشده یا نامعتبر است (باید با ۰۹ شروع شود).', 'pasokhban' ),
			);
		} else {
			$sent = self::send( $phone, __( 'این یک پیام آزمایشی از پاسخ‌بان است.', 'pasokhban' ) );
			$res  = $sent
				? array( 'ok' => true, 'message' => __( 'ارسال شد. گوشی‌ات را چک کن.', 'pasokhban' ) )
				: array( 'ok' => false, 'message' => self::$last_error );
		}

		set_transient( 'pasokhban_sms_test', $res, 2 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=pasokhban-settings#notify' ) );
		exit;
	}
}
