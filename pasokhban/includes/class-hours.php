<?php
/**
 * ساعت کاری — پاسخ‌بان.
 *
 * بیرون از ساعت کاری، اپراتور انسانی «آفلاین» نشان داده می‌شود و
 * درخواست «گپ با پشتیبان» به‌جای اینکه کاربر را در انتظار بگذارد،
 * پیام مناسب و زمان بازگشایی را نشان می‌دهد. هوش مصنوعی به کار خودش
 * ادامه می‌دهد (قابل خاموش‌کردن).
 *
 * دو نکتهٔ پیاده‌سازی که آسان اشتباه می‌شوند:
 *
 * ۱) بازهٔ شبانه. «۲۲:۰۰ تا ۰۲:۰۰» یعنی از نیمه‌شب رد می‌شود. اگر فقط
 *    `from <= t && t <= to` بررسی می‌شد، این بازه هرگز باز نبود.
 *
 * ۲) منطقهٔ زمانی. سرورهای اشتراکی معمولاً روی UTC اند ولی مشتری در
 *    تهران است. پس زمان از `wp_timezone()` خوانده می‌شود، نه `time()`.
 *
 * @package Pasokhban
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس ساعت کاری.
 */
final class Pasokhban_Hours {

	/** نام روزها به ترتیب w (۰ = یکشنبه). */
	private static $day_names = array(
		0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه',
		4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه',
	);

	/**
	 * آیا همین حالا باز هستیم؟
	 *
	 * @param int|null $ts زمان UNIX؛ null یعنی الان
	 * @return bool
	 */
	public static function is_open( $ts = null ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['hours_enabled'] ) ) {
			// وقتی ساعت کاری تعریف نشده، همیشه باز فرض می‌شود —
			// وگرنه فعال‌کردن این قابلیت بی‌تنظیم، پشتیبانی را کور می‌کرد.
			return true;
		}

		$parts = self::now_parts( $ts );
		if ( ! $parts ) {
			return true;
		}

		$days = self::parse_days( isset( $opts['hours_days'] ) ? $opts['hours_days'] : '' );
		if ( ! in_array( $parts['w'], $days, true ) ) {
			return false;
		}

		$from = self::to_minutes( isset( $opts['hours_from'] ) ? $opts['hours_from'] : '09:00' );
		$to   = self::to_minutes( isset( $opts['hours_to'] ) ? $opts['hours_to'] : '18:00' );

		if ( null === $from || null === $to ) {
			return true;
		}

		$now = $parts['h'] * 60 + $parts['i'];

		// بازهٔ شبانه: ۲۲:۰۰ تا ۰۲:۰۰
		if ( $from > $to ) {
			return ( $now >= $from || $now < $to );
		}

		return ( $now >= $from && $now < $to );
	}

	/**
	 * اجزای زمان در منطقهٔ زمانی سایت.
	 *
	 * @param int|null $ts
	 * @return array|null { w:int, h:int, i:int, ts:int }
	 */
	private static function now_parts( $ts = null ) {
		$ts = ( null === $ts ) ? time() : (int) $ts;
		if ( $ts <= 0 ) {
			return null;
		}

		// وردپرس ۵.۳ به بعد wp_timezone دارد؛ برای نسخه‌های قدیمی‌تر از
		// gmt_offset استفاده می‌کنیم.
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$dt = new DateTime( '@' . $ts );
				$dt->setTimezone( wp_timezone() );
				return array(
					'w'  => (int) $dt->format( 'w' ),
					'h'  => (int) $dt->format( 'G' ),
					'i'  => (int) $dt->format( 'i' ),
					'ts' => $ts,
				);
			} catch ( Exception $e ) {
				return null;
			}
		}

		$off   = (int) round( ( (float) get_option( 'gmt_offset', 0 ) ) * HOUR_IN_SECONDS );
		$local = $ts + $off;
		return array(
			'w'  => (int) gmdate( 'w', $local ),
			'h'  => (int) gmdate( 'G', $local ),
			'i'  => (int) gmdate( 'i', $local ),
			'ts' => $ts,
		);
	}

	/**
	 * «09:30» → ۵۷۰ دقیقه.
	 *
	 * @param string $hhmm
	 * @return int|null
	 */
	public static function to_minutes( $hhmm ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( (string) $hhmm ), $m ) ) {
			return null;
		}
		$h = (int) $m[1];
		$i = (int) $m[2];
		if ( $h > 23 || $i > 59 ) {
			return null;
		}
		return $h * 60 + $i;
	}

	/**
	 * «0,1,2,3,4» → array(0,1,2,3,4). خالی یعنی هر روز.
	 *
	 * @param string $csv
	 * @return array<int, int>
	 */
	public static function parse_days( $csv ) {
		$out = array();
		foreach ( explode( ',', (string) $csv ) as $d ) {
			$d = trim( (string) $d );
			// ★ بررسی «رشتهٔ خالی» قبل از تبدیل به عدد لازم است:
			// (int)'' برابر صفر است، پس یک فیلد خالی به‌جای «هر روز باز»
			// به «فقط یکشنبه» تبدیل می‌شد و بی‌صدا پشتیبانی را شش روز
			// هفته می‌بست. همین‌طور «abc» هم صفر می‌شد.
			if ( '' === $d || ! preg_match( '/^\d+$/', $d ) ) {
				continue;
			}
			$d = (int) $d;
			if ( $d >= 0 && $d <= 6 && ! in_array( $d, $out, true ) ) {
				$out[] = $d;
			}
		}
		// هیچ روزی انتخاب نشده بود → هر روز باز
		if ( empty( $out ) ) {
			$out = array( 0, 1, 2, 3, 4, 5, 6 );
		}
		sort( $out );
		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	public static function day_names() {
		return self::$day_names;
	}

	/**
	 * نزدیک‌ترین زمان بازگشایی، به شکل خوانا.
	 *
	 * @param int|null $ts
	 * @return string
	 */
	public static function next_open_label( $ts = null ) {
		$opts  = Pasokhban_Settings::instance()->get_options();
		$ts    = ( null === $ts ) ? time() : (int) $ts;
		$days  = self::parse_days( isset( $opts['hours_days'] ) ? $opts['hours_days'] : '' );
		$from  = self::to_minutes( isset( $opts['hours_from'] ) ? $opts['hours_from'] : '09:00' );

		if ( null === $from ) {
			return '';
		}

		$parts = self::now_parts( $ts );
		if ( ! $parts ) {
			return '';
		}

		$now_min = $parts['h'] * 60 + $parts['i'];

		// امروز و هنوز مانده؟
		if ( in_array( $parts['w'], $days, true ) && $now_min < $from ) {
			return sprintf(
				/* translators: 1: day name, 2: time */
				__( 'امروز ساعت %2$s', 'pasokhban' ),
				self::$day_names[ $parts['w'] ],
				self::fmt_hhmm( $from )
			);
		}

		// وگرنه اولین روز کاری بعدی
		for ( $d = 1; $d <= 7; $d++ ) {
			$wd = ( $parts['w'] + $d ) % 7;
			if ( in_array( $wd, $days, true ) ) {
				$word = ( 1 === $d ) ? __( 'فردا', 'pasokhban' ) : self::$day_names[ $wd ];
				return sprintf(
					/* translators: 1: day, 2: time */
					__( '%1$s ساعت %2$s', 'pasokhban' ),
					$word,
					self::fmt_hhmm( $from )
				);
			}
		}

		return '';
	}

	/**
	 * @param int $minutes
	 * @return string
	 */
	private static function fmt_hhmm( $minutes ) {
		$minutes = (int) $minutes;
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/**
	 * پیامی که بیرون از ساعت کاری به کاربر نشان داده می‌شود.
	 *
	 * @return string
	 */
	public static function offline_message() {
		$opts = Pasokhban_Settings::instance()->get_options();
		$msg  = isset( $opts['hours_offline_msg'] ) ? trim( (string) $opts['hours_offline_msg'] ) : '';

		if ( '' === $msg ) {
			$msg = __( 'الان بیرون از ساعت کاری هستیم. پیامت ثبت شد و در اولین فرصت پاسخ می‌دهیم.', 'pasokhban' );
		}

		$next = self::next_open_label();
		if ( '' !== $next ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: next opening time */
				__( 'پاسخ‌گویی انسانی از %s.', 'pasokhban' ),
				$next
			);
		}

		return $msg;
	}

	/**
	 * آیا بیرون از ساعت کاری، هوش مصنوعی هنوز پاسخ بدهد؟
	 *
	 * @return bool
	 */
	public static function ai_outside_hours() {
		$opts = Pasokhban_Settings::instance()->get_options();
		return ! empty( $opts['hours_ai_outside'] );
	}

	/**
	 * آیا اپراتور انسانی الان در دسترس است؟
	 *
	 * ترکیب دو شرط: ساعت کاری + واقعاً کسی آنلاین باشد.
	 *
	 * @return bool
	 */
	public static function agent_available() {
		if ( ! self::is_open() ) {
			return false;
		}
		return Pasokhban_Team::anyone_online();
	}
}
