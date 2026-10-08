<?php
/**
 * تبدیل تاریخ میلادی به شمسی — پاسخ‌بان.
 *
 * چرا خودمان نوشتیم و از یک کتابخانهٔ بیرونی استفاده نکردیم:
 * یک پلاگین نباید برای نمایش تاریخ، یک افزونهٔ دیگر را لازم داشته باشد.
 * این فقط یک تابع ریاضی است (الگوریتم استاندارد jdf) و هیچ وابستگی ندارد.
 *
 * چرا تاریخ در دیتابیس میلادی می‌ماند و فقط نمایش شمسی می‌شود:
 * ۱) وردپرس و MySQL همه چیز را میلادی ذخیره و مرتب می‌کنند. اگر ستون‌ها
 *    را شمسی می‌کردیم، ORDER BY و بازه‌های زمانی و `updated_at < cutoff`
 *    در cleanup همه خراب می‌شدند.
 * ۲) اگر کاربر بعداً شمسی را خاموش کند، داده‌ها باید سالم باشند.
 * پس: ذخیره میلادی، نمایش شمسی.
 *
 * @package Pasokhban
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس تاریخ شمسی.
 */
final class Pasokhban_Jalali {

	/** روزهای تجمعی ماه‌های میلادی (سال غیر کبیسه). */
	private static $g_days_in_month = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );

	/** نام ماه‌های شمسی. */
	private static $j_months = array(
		1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
		5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
		9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
	);

	/** نام روزهای هفته — اندیس مطابق w در PHP (۰ = یکشنبه). */
	private static $j_weekdays = array(
		0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه',
		4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه',
	);

	/**
	 * آیا نمایش شمسی روشن است؟
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! class_exists( 'Pasokhban_Settings' ) ) {
			return false;
		}
		$opts = Pasokhban_Settings::instance()->get_options();
		return ! empty( $opts['jalali_dates'] );
	}

	/**
	 * آیا ارقام فارسی نمایش داده شوند؟
	 *
	 * @return bool
	 */
	public static function fa_digits() {
		if ( ! class_exists( 'Pasokhban_Settings' ) ) {
			return false;
		}
		$opts = Pasokhban_Settings::instance()->get_options();
		return ! empty( $opts['jalali_digits'] );
	}

	/**
	 * میلادی → شمسی.
	 *
	 * @param int $gy
	 * @param int $gm
	 * @param int $gd
	 * @return array{0:int,1:int,2:int}
	 */
	public static function to_jalali( $gy, $gm, $gd ) {
		$gy  = (int) $gy;
		$gm  = (int) $gm;
		$gd  = (int) $gd;

		if ( $gm < 1 || $gm > 12 || $gd < 1 || $gd > 31 ) {
			return array( 0, 0, 0 );
		}

		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666
			+ ( 365 * $gy )
			+ (int) ( ( $gy2 + 3 ) / 4 )
			- (int) ( ( $gy2 + 99 ) / 100 )
			+ (int) ( ( $gy2 + 399 ) / 400 )
			+ $gd
			+ self::$g_days_in_month[ $gm - 1 ];

		$jy    = -1595 + ( 33 * (int) ( $days / 12053 ) );
		$days  = $days % 12053;

		$jy   += 4 * (int) ( $days / 1461 );
		$days  = $days % 1461;

		if ( $days > 365 ) {
			$jy   += (int) ( ( $days - 1 ) / 365 );
			$days  = ( $days - 1 ) % 365;
		}

		if ( $days < 186 ) {
			$jm = 1 + (int) ( $days / 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + (int) ( ( $days - 186 ) / 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}

		return array( (int) $jy, (int) $jm, (int) $jd );
	}

	/**
	 * شمسی → میلادی (برای بازه‌های زمانی و فیلترها).
	 *
	 * @param int $jy
	 * @param int $jm
	 * @param int $jd
	 * @return array{0:int,1:int,2:int}
	 */
	public static function to_gregorian( $jy, $jm, $jd ) {
		$jy = (int) $jy;
		$jm = (int) $jm;
		$jd = (int) $jd;

		if ( $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 ) {
			return array( 0, 0, 0 );
		}

		// قرینهٔ دقیق تابع رفت: همان ثابت‌ها با علامت معکوس. هر عدد دیگری
		// اینجا باعث جابه‌جایی چند روزه می‌شود که در نگاه اول دیده نمی‌شود.
		$jy += 1595;

		$days = -355668
			+ ( 365 * $jy )
			+ ( (int) ( $jy / 33 ) * 8 )
			+ (int) ( ( ( $jy % 33 ) + 3 ) / 4 )
			+ $jd
			+ ( ( $jm < 7 ) ? ( ( $jm - 1 ) * 31 ) : ( ( ( $jm - 7 ) * 30 ) + 186 ) );

		// شمارش سال میلادی از روی تعداد روز — از صفر شروع می‌شود و
		// پله‌پله (۴۰۰، ۱۰۰، ۴، ۱ ساله) اضافه می‌شود.
		$gy    = 0;
		$gy   += 400 * (int) ( $days / 146097 );
		$days  = $days % 146097;

		if ( $days > 36524 ) {
			$gy   += 100 * (int) ( --$days / 36524 );
			$days  = $days % 36524;
			if ( $days >= 365 ) {
				$days++;
			}
		}

		$gy   += 4 * (int) ( $days / 1461 );
		$days  = $days % 1461;

		if ( $days > 365 ) {
			$gy   += (int) ( ( $days - 1 ) / 365 );
			$days  = ( $days - 1 ) % 365;
		}

		$gd = $days + 1;

		$sal_a = array(
			0, 31,
			( ( $gy % 4 === 0 && $gy % 100 !== 0 ) || ( $gy % 400 === 0 ) ) ? 29 : 28,
			31, 30, 31, 30, 31, 31, 30, 31, 30, 31,
		);

		$gm = 0;
		for ( $i = 1; $i <= 12 && $gd > $sal_a[ $i ]; $i++ ) {
			$gd -= $sal_a[ $i ];
			$gm  = $i;
		}
		$gm++;

		return array( (int) $gy, (int) $gm, (int) $gd );
	}

	/**
	 * ارقام لاتین → فارسی.
	 *
	 * @param string|int $n
	 * @return string
	 */
	public static function digits( $n ) {
		$s = (string) $n;
		if ( ! self::fa_digits() ) {
			return $s;
		}
		return str_replace(
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ),
			$s
		);
	}

	/**
	 * قالب‌بندی یک timestamp.
	 *
	 * @param int    $ts
	 * @param string $format short|full|datetime|date|time
	 * @return string
	 */
	public static function format( $ts, $format = 'datetime' ) {
		$ts = (int) $ts;
		if ( $ts <= 0 ) {
			return '';
		}

		// منطقهٔ زمانی سایت، نه UTC سرور
		$off = self::tz_offset();
		$l   = $ts + $off;

		$g = array( (int) gmdate( 'Y', $l ), (int) gmdate( 'n', $l ), (int) gmdate( 'j', $l ) );

		if ( ! self::enabled() ) {
			$hh = gmdate( 'H:i', $l );
			switch ( $format ) {
				case 'date':
					return self::digits( sprintf( '%04d/%02d/%02d', $g[0], $g[1], $g[2] ) );
				case 'time':
					return self::digits( $hh );
				case 'full':
					return self::digits( sprintf( '%s %s %04d/%02d/%02d', self::weekday_name( (int) gmdate( 'w', $l ) ), $hh, $g[0], $g[1], $g[2] ) );
				default:
					return self::digits( sprintf( '%04d/%02d/%02d %s', $g[0], $g[1], $g[2], $hh ) );
			}
		}

		$j  = self::to_jalali( $g[0], $g[1], $g[2] );
		$hh = gmdate( 'H:i', $l );

		switch ( $format ) {
			case 'date':
				return self::digits( sprintf( '%04d/%02d/%02d', $j[0], $j[1], $j[2] ) );
			case 'time':
				return self::digits( $hh );
			case 'long':
				return self::digits( $j[2] ) . ' ' . self::month_name( $j[1] ) . ' ' . self::digits( $j[0] );
			case 'full':
				return self::weekday_name( (int) gmdate( 'w', $l ) ) . ' '
					. self::digits( $j[2] ) . ' ' . self::month_name( $j[1] ) . ' '
					. self::digits( $j[0] ) . ' — ' . self::digits( $hh );
			default:
				return self::digits( sprintf( '%04d/%02d/%02d', $j[0], $j[1], $j[2] ) ) . ' — ' . self::digits( $hh );
		}
	}

	/**
	 * قالب‌بندی یک رشتهٔ DATETIME که در دیتابیس ذخیره شده (UTC).
	 *
	 * @param string $mysql_time
	 * @param string $format
	 * @return string
	 */
	public static function format_mysql( $mysql_time, $format = 'datetime' ) {
		$ts = self::parse_mysql( $mysql_time );
		return $ts ? self::format( $ts, $format ) : '';
	}

	/**
	 * رشتهٔ DATETIME دیتابیس → timestamp.
	 *
	 * ★ بررسی «خالی بودن» قبل از strtotime لازم است:
	 * `strtotime(' UTC')` مقدار false نمی‌دهد بلکه زمان *الان* را
	 * برمی‌گرداند. پس یک رکورد با تاریخ خالی، تاریخ امروز را نشان
	 * می‌داد — که از خالی‌بودن خیلی بدتر است.
	 *
	 * @param string $mysql_time
	 * @return int صفر یعنی نامعتبر
	 */
	private static function parse_mysql( $mysql_time ) {
		$s = trim( (string) $mysql_time );
		if ( '' === $s || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $s ) ) {
			return 0;
		}
		$ts = strtotime( $s . ' UTC' );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * @param int $m شمارهٔ ماه شمسی
	 * @return string
	 */
	public static function month_name( $m ) {
		$m = (int) $m;
		return isset( self::$j_months[ $m ] ) ? self::$j_months[ $m ] : '';
	}

	/**
	 * @param int $w مطابق w در PHP (۰ = یکشنبه)
	 * @return string
	 */
	public static function weekday_name( $w ) {
		$w = (int) $w % 7;
		return isset( self::$j_weekdays[ $w ] ) ? self::$j_weekdays[ $w ] : '';
	}

	/**
	 * اختلاف زمانی از منطقهٔ زمانی سایت به ثانیه.
	 *
	 * @return int
	 */
	public static function tz_offset() {
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$tz = wp_timezone();
				$dt = new DateTime( 'now', $tz );
				return (int) $dt->getOffset();
			} catch ( Exception $e ) {
				return 0;
			}
		}
		return (int) round( ( (float) get_option( 'gmt_offset', 0 ) ) * HOUR_IN_SECONDS );
	}

	/**
	 * «۵ دقیقه پیش» با ارقام و واحد فارسی.
	 *
	 * @param string $mysql_time
	 * @return string
	 */
	public static function ago( $mysql_time ) {
		$ts = self::parse_mysql( $mysql_time );
		if ( ! $ts ) {
			return '';
		}

		$diff = time() - $ts;
		if ( $diff < 0 ) {
			$diff = 0;
		}

		if ( $diff < 45 ) {
			$label = __( 'همین حالا', 'pasokhban' );
		} elseif ( $diff < 3600 ) {
			/* translators: %s: minutes */
			$label = sprintf( __( '%s دقیقه پیش', 'pasokhban' ), self::digits( (int) round( $diff / 60 ) ) );
		} elseif ( $diff < 86400 ) {
			/* translators: %s: hours */
			$label = sprintf( __( '%s ساعت پیش', 'pasokhban' ), self::digits( (int) round( $diff / 3600 ) ) );
		} elseif ( $diff < 2592000 ) {
			/* translators: %s: days */
			$label = sprintf( __( '%s روز پیش', 'pasokhban' ), self::digits( (int) round( $diff / 86400 ) ) );
		} else {
			return self::format( $ts, 'date' );
		}

		return $label;
	}

	/**
	 * نام فایل امن برای خروجی CSV با تاریخ شمسی.
	 *
	 * ارقام فارسی در نام فایل می‌توانند روی بعضی سیستم‌عامل‌ها مشکل‌ساز
	 * شوند، پس در نام فایل همیشه ارقام لاتین استفاده می‌شود.
	 *
	 * @param string $prefix
	 * @return string
	 */
	public static function file_stamp( $prefix ) {
		$ts = time() + self::tz_offset();
		if ( self::enabled() ) {
			$j    = self::to_jalali( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
			$part = sprintf( '%04d-%02d-%02d', $j[0], $j[1], $j[2] );
		} else {
			$part = gmdate( 'Y-m-d', $ts );
		}
		return $prefix . '-' . $part;
	}
}
