<?php
/**
 * موتور جست‌وجوی معنایی (RAG برداری) — پاسخ‌بان.
 *
 * چرا این فایل وجود دارد:
 * تا نسخهٔ ۱.۸ «دانش سایت» با LIKE و شمارش کلمه‌های مشترک کار می‌کرد.
 * یعنی سؤال «شرایط بازگشت وجه چطوریه؟» فقط وقتی به نوشتهٔ «قوانین
 * بازگشت کالا» می‌رسید که کلمهٔ مشترک داشته باشند. سؤال‌های هم‌معنی
 * ولی با کلمه‌های متفاوت هیچ نتیجه‌ای نمی‌گرفتند.
 *
 * اینجا محتوا یک‌بار تکه‌تکه و به بردار (embedding) تبدیل می‌شود و در
 * دیتابیس ذخیره می‌شود؛ در زمان سؤال، فقط سؤال برداری می‌شود و نزدیک‌ترین
 * تکه‌ها با ضرب داخلی پیدا می‌شوند.
 *
 * سه تصمیم طراحی که آگاهانه گرفته شده‌اند:
 *
 * ۱) بدون سرویس خارجی. بردارها در همان جدول وردپرس نگه داشته می‌شوند و
 *    جست‌وجو در PHP انجام می‌شود. بهای آن این است که جست‌وجو خطی است؛
 *    برای همین سقف MAX_CHUNKS داریم و ابعاد بردار قابل کاهش است
 *    (پارامتر dimensions در مدل‌های embedding-3).
 *
 * ۲) هرگز در مسیر پاسخ بلوکه نمی‌شود. اگر embedding خطا داد یا ایندکس
 *    خالی بود، retrieve() مقدار null/WP_Error برمی‌گرداند و
 *    Pasokhban_API::get_site_docs() به همان جست‌وجوی کلیدواژه‌ای قدیمی
 *    برمی‌گردد. یعنی خاموش‌کردن این قابلیت = رفتار دقیق نسخهٔ ۱.۸.
 *
 * ۳) ایندکس‌سازی هرگز داخل درخواست چت انجام نمی‌شود. فقط با دکمهٔ
 *    «ساخت ایندکس» در پیشخوان یا با کرون ساعتی. وگرنه اولین کاربر بعد
 *    از انتشار یک نوشته، هزینهٔ embedding همهٔ سایت را می‌داد.
 *
 * @package Pasokhban
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس RAG برداری.
 */
final class Pasokhban_RAG {

	const OPT_STATE  = 'pasokhban_rag_state';
	const OPT_DIRTY  = 'pasokhban_rag_dirty';
	const OPT_CURSOR = 'pasokhban_rag_cursor';

	/** هوک کرون برای همگام‌سازی منابع تغییریافته. */
	const CRON_HOOK = 'pasokhban_rag_sync';

	/**
	 * سقف تکه‌ها.
	 *
	 * جست‌وجو خطی و در PHP است؛ بالای این عدد زمان جست‌وجو روی هر پیام
	 * محسوس می‌شود. برای سایت‌های بزرگ‌تر باید به یک موتور برداری واقعی
	 * (مثلاً pgvector) وصل شد که خارج از دامنهٔ یک پلاگین است.
	 */
	const MAX_CHUNKS = 4000;

	/** بالای این عدد در پیشخوان هشدار می‌دهیم (هنوز کار می‌کند، فقط کندتر). */
	const SOFT_CHUNKS = 1500;

	/** سقف تکه برای هر منبع، تا یک نوشتهٔ بسیار بلند کل ایندکس را پر نکند. */
	const MAX_PER_SOURCE = 24;

	/** تعداد سطری که هر بار برای محاسبهٔ شباهت خوانده می‌شود. */
	const SCAN_PAGE = 120;

	/** بیشترین تعداد متن در یک درخواست embedding. */
	const EMBED_BATCH = 16;

	/** بودجهٔ زمانی هر اجرای ایندکس‌سازی (ثانیه). */
	const BUILD_BUDGET = 45;

	/** @var Pasokhban_RAG|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'delete_post', array( $this, 'on_delete_post' ) );
		add_action( self::CRON_HOOK, array( $this, 'sync_dirty' ) );
		add_action( 'admin_post_pasokhban_rag_build', array( $this, 'handle_build' ) );
		add_action( 'admin_post_pasokhban_rag_clear', array( $this, 'handle_clear' ) );
		add_action( 'admin_post_pasokhban_rag_test', array( $this, 'handle_test' ) );
	}

	/* =========================================================
	 * وضعیت
	 * =======================================================*/

	/**
	 * آیا جست‌وجوی برداری فعال و قابل استفاده است؟
	 *
	 * نکته: عمداً «خالی‌بودن ایندکس» را اینجا بررسی نمی‌کنیم؛ retrieve()
	 * خودش تصمیم می‌گیرد. وگرنه با هر بار خالی‌شدن ایندکس، رفتار
	 * get_site_docs بین دو مسیر جابه‌جا می‌شد و دیباگ غیرممکن می‌شد.
	 *
	 * @return bool
	 */
	public function enabled() {
		$opts = Pasokhban_Settings::instance()->get_options();
		return ! empty( $opts['rag_vector'] ) && '' !== trim( (string) $opts['api_key'] );
	}

	/**
	 * @return array
	 */
	public function state() {
		$s = get_option( self::OPT_STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return wp_parse_args( $s, array(
			'built_at'  => 0,
			'chunks'    => 0,
			'sources'   => 0,
			'model'     => '',
			'dims'      => 0,
			'remaining' => 0,
			'error'     => '',
		) );
	}

	/**
	 * @param array $patch
	 */
	public function save_state( array $patch ) {
		update_option( self::OPT_STATE, array_merge( $this->state(), $patch ) );
	}

	/**
	 * آیا جدول ایندکس واقعاً وجود دارد؟
	 *
	 * چرا لازم است: روی هاست‌هایی که کاربر MySQL حق CREATE TABLE ندارد،
	 * dbDelta بی‌صدا رد می‌شود. آن‌وقت هر کوئری روی جدول ناموجود در
	 * وردپرس یک خطای دیتابیس چاپ می‌کرد و جلوی خروجی JSON را می‌گرفت.
	 * نتیجه در طول یک درخواست کش می‌شود.
	 *
	 * @return bool
	 */
	public function table_ready() {
		static $checked = null;
		if ( null !== $checked ) {
			return $checked;
		}
		global $wpdb;
		$found   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Pasokhban_DB::chunks_table() ) ); // phpcs:ignore WordPress.DB
		$checked = ( null !== $found && false !== $found );
		return $checked;
	}

	/**
	 * تعداد واقعی تکه‌ها در جدول.
	 *
	 * از خود جدول می‌خوانیم نه از گزینه: اگر کاربر دستی جدول را خالی
	 * کرده باشد، گزینهٔ ذخیره‌شده دروغ می‌گفت و retrieve() روی جدول خالی
	 * هر بار یک درخواست embedding بی‌فایده می‌زد.
	 *
	 * @return int
	 */
	public function count_chunks() {
		if ( ! $this->table_ready() ) {
			return 0;
		}
		global $wpdb;
		$n = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Pasokhban_DB::chunks_table() ); // phpcs:ignore WordPress.DB
		return (int) $n;
	}

	/* =========================================================
	 * تکه‌کردن متن
	 * =======================================================*/

	/**
	 * متن را به تکه‌های هم‌اندازه با هم‌پوشانی تقسیم می‌کند.
	 *
	 * ترتیب برش: پاراگراف → جمله → برش سخت. یعنی تا جای ممکن وسط جمله
	 * بریده نمی‌شود، چون تکه‌ای که وسط جمله قطع شده باشد بردار بی‌مزه‌ای
	 * می‌دهد و در جست‌وجو گم می‌شود.
	 *
	 * هم‌پوشانی با چسباندن دنبالهٔ تکهٔ قبلی به ابتدای تکهٔ بعدی انجام
	 * می‌شود؛ این کار باعث می‌شود پاسخی که روی مرز دو تکه افتاده از دست
	 * نرود.
	 *
	 * @param string $text
	 * @param int    $size    اندازهٔ هدف هر تکه (کاراکتر)
	 * @param int    $overlap هم‌پوشانی (کاراکتر)
	 * @return array<int, string>
	 */
	public static function chunk_text( $text, $size = 1200, $overlap = 200 ) {
		$text = (string) $text;
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array();
		}

		$size    = max( 200, (int) $size );
		$overlap = max( 0, min( (int) $size - 50, (int) $overlap ) );

		if ( mb_strlen( $text ) <= $size ) {
			return array( $text );
		}

		$blocks = self::split_blocks( $text, $size );
		$chunks = array();
		$buf    = '';

		foreach ( $blocks as $block ) {
			// اگر افزودن این بلوک از سقف رد می‌شود، بافر را ببند.
			if ( '' !== $buf && ( mb_strlen( $buf ) + mb_strlen( $block ) + 1 ) > $size ) {
				$chunks[] = trim( $buf );
				$buf      = self::tail( $buf, $overlap );
			}
			$buf = ( '' === $buf ) ? $block : $buf . "\n" . $block;

			// بلوکی که خودش از سقف بزرگ‌تر است (نباید پیش بیاید، ولی
			// محافظت در برابر حلقهٔ بی‌پایان واجب است).
			while ( mb_strlen( $buf ) > $size ) {
				$chunks[] = trim( mb_substr( $buf, 0, $size ) );
				$buf      = self::tail( $buf, $overlap, $size );
				if ( '' === trim( $buf ) ) {
					$buf = '';
					break;
				}
			}
		}

		if ( '' !== trim( $buf ) ) {
			$chunks[] = trim( $buf );
		}

		$chunks = array_values( array_filter( $chunks, function ( $c ) {
			return '' !== trim( (string) $c );
		} ) );

		// یک نوشتهٔ خیلی بلند نباید کل ایندکس را بگیرد.
		return array_slice( $chunks, 0, self::MAX_PER_SOURCE );
	}

	/**
	 * دنبالهٔ یک متن به طول $len.
	 *
	 * @param string $text
	 * @param int    $len
	 * @param int    $skip   از ابتدای متن چند کاراکتر رد شود (برای حالتی که
	 *                       $size کاراکتر اول همین حالا برداشته شده).
	 * @return string
	 */
	private static function tail( $text, $len, $skip = 0 ) {
		$len = (int) $len;
		if ( $len <= 0 ) {
			return '';
		}
		$rest = ( $skip > 0 ) ? mb_substr( $text, $skip ) : $text;
		if ( '' === $rest ) {
			return '';
		}
		return ( mb_strlen( $rest ) <= $len ) ? $rest : mb_substr( $rest, -$len );
	}

	/**
	 * شکستن متن به بلوک‌هایی که هیچ‌کدام از $size بزرگ‌تر نباشند.
	 *
	 * @param string $text
	 * @param int    $size
	 * @return array<int, string>
	 */
	private static function split_blocks( $text, $size ) {
		$out = array();

		foreach ( preg_split( '/\n{2,}/', $text ) as $para ) {
			$para = trim( (string) $para );
			if ( '' === $para ) {
				continue;
			}
			if ( mb_strlen( $para ) <= $size ) {
				$out[] = $para;
				continue;
			}

			// پاراگراف بزرگ: بر اساس جمله بشکن. علامت‌های فارسی هم لحاظ شده.
			$parts = preg_split( '/(?<=[.!?؟؛;\n])\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( (array) $parts as $sentence ) {
				$sentence = trim( (string) $sentence );
				if ( '' === $sentence ) {
					continue;
				}
				if ( mb_strlen( $sentence ) <= $size ) {
					$out[] = $sentence;
					continue;
				}
				// جملهٔ بزرگ‌تر از سقف: برش سخت.
				for ( $i = 0; $i < mb_strlen( $sentence ); $i += $size ) {
					$out[] = mb_substr( $sentence, $i, $size );
				}
			}
		}

		return $out;
	}

	/* =========================================================
	 * ریاضیات بردار
	 * =======================================================*/

	/**
	 * بردار را واحد می‌کند تا شباهت کسینوسی فقط یک ضرب داخلی باشد.
	 *
	 * @param array $v
	 * @return array
	 */
	public static function normalize( array $v ) {
		$sum = 0.0;
		foreach ( $v as $x ) {
			$sum += ( (float) $x ) * ( (float) $x );
		}
		$norm = sqrt( $sum );
		if ( $norm <= 0.0 ) {
			return array();
		}
		$out = array();
		foreach ( $v as $x ) {
			$out[] = round( ( (float) $x ) / $norm, 6 );
		}
		return $out;
	}

	/**
	 * ضرب داخلی دو بردار واحد = کسینوس زاویهٔ بین آن‌ها.
	 *
	 * @param array $a
	 * @param array $b
	 * @return float
	 */
	public static function dot( array $a, array $b ) {
		$n   = min( count( $a ), count( $b ) );
		$sum = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$sum += $a[ $i ] * $b[ $i ];
		}
		return $sum;
	}

	/**
	 * بردار را برای ذخیره در دیتابیس سریال می‌کند.
	 *
	 * قالب: float32 با ترتیب بایت big-endian (کد G در pack) + base64.
	 *
	 * چرا نه pack('f*'): آن به اندینس ماشین وابسته است و با جابه‌جایی
	 * هاست یا یک mysqldump بین دو معماری، بی‌سروصدا همهٔ بردارها خراب
	 * می‌شدند. کد G همیشه big-endian است، پس فایل قابل انتقال است.
	 *
	 * چرا نه JSON: در ۵۱۲ بعد، JSON حدود ۴٫۶ کیلوبایت و این قالب ۲٫۷
	 * کیلوبایت است؛ ضمن اینکه unpack در سطح C اجرا می‌شود و از
	 * json_decode سریع‌تر است. چون جست‌وجو خطی است، این اندازه مستقیماً
	 * روی زمان هر پیام اثر دارد.
	 *
	 * @param array $v
	 * @return string
	 */
	public static function encode( array $v ) {
		if ( empty( $v ) ) {
			return '';
		}
		$floats = array();
		foreach ( $v as $x ) {
			$floats[] = (float) $x;
		}
		return base64_encode( pack( 'G*', ...$floats ) );
	}

	/**
	 * @param string $s
	 * @return array
	 */
	public static function decode( $s ) {
		$s = (string) $s;
		if ( '' === $s ) {
			return array();
		}

		// قالب جدید
		$bin = base64_decode( $s, true );
		if ( false !== $bin && '' !== $bin && 0 === ( strlen( $bin ) % 4 ) ) {
			$v = unpack( 'G*', $bin );
			if ( is_array( $v ) && ! empty( $v ) ) {
				return array_values( $v );
			}
		}

		// سازگاری با ایندکسی که با قالب JSON ساخته شده بود: به‌جای
		// بی‌اعتبارکردن کل ایندکس، همان را می‌خوانیم.
		$j = json_decode( $s, true );
		if ( is_array( $j ) ) {
			return array_values( array_map( 'floatval', $j ) );
		}

		return array();
	}

	/* =========================================================
	 * فراخوانی embedding
	 * =======================================================*/

	/**
	 * تبدیل متن‌ها به بردار.
	 *
	 * @param array  $texts
	 * @param string $model خالی = از تنظیمات
	 * @return array|WP_Error آرایه‌ای از بردارهای واحد، هم‌ترتیب با $texts
	 */
	public function embed( array $texts, $model = '' ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['api_key'] ) ) {
			return new WP_Error( 'pasokhban_no_key', __( 'کلید API تنظیم نشده است.', 'pasokhban' ) );
		}

		$texts = array_values( array_filter( array_map( function ( $t ) {
			return trim( (string) $t );
		}, $texts ), function ( $t ) {
			return '' !== $t;
		} ) );

		if ( empty( $texts ) ) {
			return array();
		}

		$model = ( '' !== trim( (string) $model ) ) ? trim( (string) $model ) : $opts['rag_model'];
		$dims  = (int) $opts['rag_dims'];

		$payload = array(
			'model' => $model,
			'input' => $texts,
		);
		// models embedding-3 اجازهٔ بردار کوتاه‌تر می‌دهند (Matryoshka).
		// برای سایت‌های بزرگ این یعنی جست‌وجوی چند برابر سریع‌تر.
		if ( $dims > 0 ) {
			$payload['dimensions'] = $dims;
		}

		$result = $this->embed_request( $payload, $opts );

		// بعضی درگاه‌های سازگار، پارامتر dimensions را قبول نمی‌کنند و 400
		// می‌دهند. یک بار بدون آن تلاش می‌کنیم تا کاربر مجبور نباشد خودش
		// تشخیص دهد مشکل از کجاست.
		if ( is_wp_error( $result ) && 'pasokhban_dims' === $result->get_error_code() ) {
			unset( $payload['dimensions'] );
			$result = $this->embed_request( $payload, $opts );
			if ( ! is_wp_error( $result ) ) {
				$this->save_state( array( 'dims' => count( $result[0] ) ) );
			}
		}

		return $result;
	}

	/**
	 * @param array $payload
	 * @param array $opts
	 * @return array|WP_Error
	 */
	private function embed_request( array $payload, array $opts ) {
		$response = wp_remote_post(
			trailingslashit( $opts['base_url'] ) . 'embeddings',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $opts['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pasokhban_remote', __( 'خطا در ارتباط با سرور هوش مصنوعی.', 'pasokhban' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( self::looks_like_html( $body ) ) {
			return new WP_Error(
				'pasokhban_embed',
				__( 'سرور به‌جای JSON صفحهٔ HTML فرستاد. معمولاً یعنی آدرس API یا کلید اشتباه است، یا درگاه از embedding پشتیبانی نمی‌کند.', 'pasokhban' )
			);
		}

		if ( $code >= 400 ) {
			$msg = ( is_array( $data ) && isset( $data['error']['message'] ) )
				? sanitize_text_field( $data['error']['message'] )
				: __( 'سرور embedding خطا داد.', 'pasokhban' );

			// اگر فقط مشکل از dimensions بود، کد مشخص برمی‌گردانیم تا
			// embed() یک بار بدون آن تلاش کند.
			if ( isset( $payload['dimensions'] ) && preg_match( '/dimension|unsupported|invalid/i', $msg ) ) {
				return new WP_Error( 'pasokhban_dims', $msg );
			}
			return new WP_Error( 'pasokhban_embed', $msg );
		}

		if ( ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error( 'pasokhban_embed', __( 'پاسخ embedding خالی یا نامعتبر بود.', 'pasokhban' ) );
		}

		// ترتیب پاسخ تضمین‌شده نیست؛ بر اساس index بازچینی می‌کنیم. بدون
		// این کار بردار هر تکه به تکهٔ دیگری وصل می‌شد و جست‌وجو بی‌سروصدا
		// نتیجهٔ غلط می‌داد.
		usort( $data['data'], function ( $a, $b ) {
			$ai = isset( $a['index'] ) ? (int) $a['index'] : 0;
			$bi = isset( $b['index'] ) ? (int) $b['index'] : 0;
			return $ai - $bi;
		} );

		$vectors = array();
		foreach ( $data['data'] as $row ) {
			if ( ! isset( $row['embedding'] ) || ! is_array( $row['embedding'] ) || empty( $row['embedding'] ) ) {
				return new WP_Error( 'pasokhban_embed', __( 'یکی از بردارهای برگشتی خالی بود.', 'pasokhban' ) );
			}
			$vectors[] = self::normalize( array_map( 'floatval', $row['embedding'] ) );
		}

		if ( count( $vectors ) !== count( $payload['input'] ) ) {
			return new WP_Error(
				'pasokhban_embed',
				sprintf(
					/* translators: 1: requested, 2: returned */
					__( 'تعداد بردارها با تعداد متن‌ها نمی‌خواند (%1$s در برابر %2$s).', 'pasokhban' ),
					count( $payload['input'] ),
					count( $vectors )
				)
			);
		}

		return $vectors;
	}

	/**
	 * آیا بدنهٔ پاسخ HTML است (نه JSON)؟
	 *
	 * @param string $body
	 * @return bool
	 */
	public static function looks_like_html( $body ) {
		$head = strtolower( substr( trim( (string) $body ), 0, 512 ) );
		if ( '' === $head ) {
			return false;
		}
		return (bool) preg_match( '/<!doctype|<html|<head|<script|window\[\'ppconfig\'\]/', $head );
	}

	/* =========================================================
	 * منابع ایندکس
	 * =======================================================*/

	/**
	 * فهرست منابعی که ایندکس می‌شوند.
	 *
	 * @return array<int, array{type:string, id:string, title:string, url:string, body:string, hash:string}>
	 */
	public function sources() {
		$opts  = Pasokhban_Settings::instance()->get_options();
		$types = array_values( array_filter( array_map( 'trim', explode( ',', (string) $opts['rag_post_types'] ) ) ) );
		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}

		$sig = $this->signature();
		$out = array();

		$posts = get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );

		foreach ( (array) $posts as $post ) {
			$title = isset( $post->post_title ) ? wp_strip_all_tags( (string) $post->post_title ) : '';
			$raw   = isset( $post->post_content ) ? (string) $post->post_content : '';
			$body  = wp_strip_all_tags( strip_shortcodes( $raw ) );

			if ( mb_strlen( trim( $body ) ) < 40 ) {
				continue; // نوشته‌های تقریباً خالی ارزش بردار شدن ندارند.
			}

			$id   = (string) ( isset( $post->ID ) ? (int) $post->ID : 0 );
			$out[] = array(
				'type'  => 'post',
				'id'    => $id,
				'title' => $title,
				'url'   => get_permalink( $post ),
				'body'  => trim( $body ),
				'hash'  => md5( $sig . '|' . $id . '|' . $title . '|' . $body ),
			);
		}

		/**
		 * افزودن منابع دیگر به ایندکس (مثلاً محصولات ووکامرس).
		 *
		 * هر منبع باید همین ساختار را داشته باشد:
		 * { type, id, title, url, body, hash }
		 * hash باید با تغییر محتوا عوض شود، وگرنه آن منبع هیچ‌وقت
		 * دوباره ایندکس نمی‌شود.
		 *
		 * @param array $out
		 * @param array $opts
		 */
		return apply_filters( 'pasokhban_rag_sources', $out, $opts );
	}

	/**
	 * امضای تنظیماتی که اگر عوض شوند، کل ایندکس باید دوباره ساخته شود.
	 *
	 * @return string
	 */
	public function signature() {
		$opts = Pasokhban_Settings::instance()->get_options();
		return implode( '|', array(
			$opts['rag_model'],
			(int) $opts['rag_dims'],
			(int) $opts['rag_chunk_size'],
			(int) $opts['rag_chunk_overlap'],
		) );
	}

	/* =========================================================
	 * ساخت ایندکس
	 * =======================================================*/

	/**
	 * ساخت یا به‌روزرسانی ایندکس.
	 *
	 * افزایشی است: منبعی که hash آن عوض نشده دست نمی‌خورد. یعنی بعد از
	 * انتشار یک نوشته، فقط همان نوشته دوباره embedding می‌شود و کاربر
	 * هزینهٔ کل سایت را یک‌بار دیگر نمی‌دهد.
	 *
	 * @param array $args { reset:bool, limit:int, only:array }
	 * @return array خلاصهٔ اجرا
	 */
	public function build( array $args = array() ) {
		global $wpdb;

		$opts  = Pasokhban_Settings::instance()->get_options();
		$table = Pasokhban_DB::chunks_table();

		$summary = array(
			'ok'        => true,
			'sources'   => 0,
			'chunks'    => 0,
			'embedded'  => 0,
			'skipped'   => 0,
			'removed'   => 0,
			'remaining' => 0,
			'errors'    => array(),
			'ms'        => 0,
		);

		if ( ! $this->table_ready() ) {
			$msg = __( 'جدول ایندکس ساخته نشده است. پلاگین را یک‌بار غیرفعال و دوباره فعال کن؛ اگر نشد، کاربر دیتابیس حق CREATE TABLE ندارد.', 'pasokhban' );
			$summary['ok']       = false;
			$summary['errors'][] = $msg;
			$this->save_state( array( 'error' => $msg ) );
			return $summary;
		}

		if ( empty( $opts['api_key'] ) ) {
			$summary['ok']     = false;
			$summary['errors'][] = __( 'کلید API تنظیم نشده است.', 'pasokhban' );
			$this->save_state( array( 'error' => __( 'کلید API تنظیم نشده است.', 'pasokhban' ) ) );
			return $summary;
		}

		$t0 = microtime( true );

		if ( ! empty( $args['reset'] ) ) {
			$wpdb->query( 'DELETE FROM ' . $table ); // phpcs:ignore WordPress.DB
			delete_option( self::OPT_CURSOR );
		}

		$sources = $this->sources();

		// منابعی که حذف شده‌اند ولی تکه‌هایشان مانده: پاکشان کن.
		$alive   = array();
		foreach ( $sources as $s ) {
			$alive[ $s['type'] . ':' . $s['id'] ] = true;
		}
		$rows = $wpdb->get_results( 'SELECT DISTINCT source_type, source_id FROM ' . $table ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$key = $r->source_type . ':' . $r->source_id;
			if ( ! isset( $alive[ $key ] ) ) {
				$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
					'DELETE FROM ' . $table . ' WHERE source_type = %s AND source_id = %s',
					$r->source_type,
					$r->source_id
				) );
				$summary['removed']++;
			}
		}

		// hash فعلی هر منبع در جدول — برای ردکردن منابع تغییریافته‌نکرده.
		$have = array();
		foreach ( (array) $wpdb->get_results( 'SELECT source_type, source_id, content_hash FROM ' . $table ) as $r ) { // phpcs:ignore WordPress.DB
			$have[ $r->source_type . ':' . $r->source_id ] = (string) $r->content_hash;
		}

		$only = ! empty( $args['only'] ) ? array_flip( (array) $args['only'] ) : null;

		$pending = array();
		foreach ( $sources as $s ) {
			$key = $s['type'] . ':' . $s['id'];
			if ( $only && ! isset( $only[ $key ] ) ) {
				continue;
			}
			if ( isset( $have[ $key ] ) && $have[ $key ] === $s['hash'] ) {
				$summary['skipped']++;
				continue;
			}
			$pending[] = $s;
		}

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		if ( $limit > 0 ) {
			$summary['remaining'] = max( 0, count( $pending ) - $limit );
			$pending              = array_slice( $pending, 0, $limit );
		}

		$total_chunks = $this->count_chunks();
		$deadline     = $t0 + self::BUILD_BUDGET;

		// تکه‌ها را جمع می‌کنیم و دسته‌ای embedding می‌گیریم: یک درخواست
		// شبکه برای ۱۶ تکه به‌جای ۱۶ درخواست.
		$buffer   = array();   // متن‌ها
		$buffer_m = array();   // متادیتای متناظر

		$flush = function () use ( &$buffer, &$buffer_m, &$summary, &$total_chunks, $table, $wpdb ) {
			if ( empty( $buffer ) ) {
				return true;
			}

			$vectors = $this->embed( $buffer );
			if ( is_wp_error( $vectors ) ) {
				$summary['ok']       = false;
				$summary['errors'][] = $vectors->get_error_message();
				$buffer              = array();
				$buffer_m            = array();
				return false;
			}

			$now = gmdate( 'Y-m-d H:i:s' );
			foreach ( $vectors as $i => $vec ) {
				if ( empty( $vec ) ) {
					continue;
				}
				$meta = isset( $buffer_m[ $i ] ) ? $buffer_m[ $i ] : null;
				if ( ! $meta ) {
					continue;
				}
				$wpdb->insert( $table, array(
					'source_type'  => $meta['type'],
					'source_id'    => $meta['id'],
					'title'        => mb_substr( $meta['title'], 0, 190 ),
					'url'          => mb_substr( (string) $meta['url'], 0, 700 ),
					'content'      => $meta['content'],
					'content_hash' => $meta['hash'],
					'model'        => $meta['model'],
					'dims'         => count( $vec ),
					'vector'       => self::encode( $vec ),
					'created_at'   => $now,
				) );
				$summary['chunks']++;
				$total_chunks++;
			}

			$summary['embedded'] += count( $vectors );
			$buffer   = array();
			$buffer_m = array();
			return true;
		};

		$stopped = false;

		foreach ( $pending as $s ) {
			if ( microtime( true ) > $deadline ) {
				$stopped = true;
				break;
			}
			if ( $total_chunks >= self::MAX_CHUNKS ) {
				$stopped = true;
				$summary['errors'][] = sprintf(
					/* translators: %d: max chunks */
					__( 'به سقف %d تکه رسیدیم؛ بقیهٔ منابع ایندکس نشدند.', 'pasokhban' ),
					self::MAX_CHUNKS
				);
				break;
			}

			// تکه‌های قبلی این منبع را بردار تا نسخهٔ کهنه نماند.
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'DELETE FROM ' . $table . ' WHERE source_type = %s AND source_id = %s',
				$s['type'],
				$s['id']
			) );

			$pieces = self::chunk_text(
				$s['title'] . "\n\n" . $s['body'],
				(int) $opts['rag_chunk_size'],
				(int) $opts['rag_chunk_overlap']
			);

			foreach ( $pieces as $piece ) {
				if ( count( $buffer ) >= self::EMBED_BATCH ) {
					if ( ! $flush() ) {
						$stopped = true;
						break 2;
					}
				}
				$buffer[]   = $piece;
				$buffer_m[] = array(
					'type'    => $s['type'],
					'id'      => $s['id'],
					'title'   => $s['title'],
					'url'     => $s['url'],
					'content' => $piece,
					'hash'    => $s['hash'],
					'model'   => $opts['rag_model'],
				);
			}
			$summary['sources']++;
		}

		// بافر باقی‌مانده را هم می‌فرستیم؛ حتی اگر اجرا به سقف زمان یا
		// سقف تکه خورده باشد، تکه‌های آماده نباید دور ریخته شوند.
		$flush();

		$remaining = $summary['remaining'];
		if ( $stopped ) {
			$remaining = max( $remaining, count( $pending ) - $summary['sources'] );
		}

		$chunks = $this->count_chunks();
		$this->save_state( array(
			'built_at'  => time(),
			'chunks'    => $chunks,
			'sources'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT DISTINCT source_type, source_id FROM ' . $table . ') ety_src' ), // phpcs:ignore WordPress.DB
			'model'     => $opts['rag_model'],
			'remaining' => $remaining,
			'error'     => $summary['ok'] ? '' : implode( ' — ', $summary['errors'] ),
		) );

		// کش پرس‌وجوهای قبلی دیگر معتبر نیست.
		$this->flush_query_cache();

		$summary['ms']        = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$summary['remaining'] = $remaining;
		$summary['total']     = $chunks;

		if ( $chunks > self::SOFT_CHUNKS ) {
			$summary['warning'] = sprintf(
				/* translators: 1: chunks, 2: soft limit */
				__( 'ایندکس %1$d تکه دارد (بالای %2$d). جست‌وجو هنوز کار می‌کند ولی روی هر پیام کمی کندتر می‌شود؛ ابعاد بردار را کم کن.', 'pasokhban' ),
				$chunks,
				self::SOFT_CHUNKS
			);
		}

		return $summary;
	}

	/**
	 * خالی‌کردن کامل ایندکس.
	 */
	public function clear() {
		global $wpdb;
		if ( $this->table_ready() ) {
			$wpdb->query( 'DELETE FROM ' . Pasokhban_DB::chunks_table() ); // phpcs:ignore WordPress.DB
		}
		delete_option( self::OPT_STATE );
		delete_option( self::OPT_DIRTY );
		delete_option( self::OPT_CURSOR );
		$this->flush_query_cache();
	}

	/* =========================================================
	 * جست‌وجو
	 * =======================================================*/

	/**
	 * نزدیک‌ترین تکه‌ها به یک پرس‌وجو.
	 *
	 * @param string $query
	 * @return array|WP_Error|null  null یعنی «برداری در دسترس نیست، به
	 *                              مسیر کلیدواژه‌ای برگرد».
	 */
	public function retrieve( $query ) {
		global $wpdb;

		$query = trim( (string) $query );
		if ( '' === $query || ! $this->enabled() ) {
			return null;
		}

		$opts  = Pasokhban_Settings::instance()->get_options();
		$table = Pasokhban_DB::chunks_table();

		if ( ! $this->table_ready() || $this->count_chunks() < 1 ) {
			return null; // جدول یا ایندکس نیست — بدون خطا، مسیر قدیمی کار کند.
		}

		$top_k   = max( 1, min( 8, (int) $opts['rag_top_k'] ) );
		$min     = (float) $opts['rag_min_score'];
		$st      = $this->state();
		$ckey    = 'pasokhban_ragq_' . md5( $query . '|' . $this->signature() . '|' . $top_k . '|' . $min . '|' . (int) $st['built_at'] );
		$cached  = get_transient( $ckey );

		if ( is_array( $cached ) && isset( $cached['context'], $cached['sources'] ) ) {
			return $cached;
		}

		$vectors = $this->embed( array( $query ) );
		if ( is_wp_error( $vectors ) ) {
			// خطای embedding نباید چت را بیندازد: null بده تا
			// get_site_docs به جست‌وجوی کلیدواژه‌ای برگردد.
			$this->save_state( array( 'error' => $vectors->get_error_message() ) );
			return $vectors;
		}
		if ( empty( $vectors[0] ) ) {
			return null;
		}
		$qv = $vectors[0];

		$scored = array();
		$offset = 0;
		$paged  = 0;

		// صفحه‌به‌صفحه می‌خوانیم تا حافظه با اندازهٔ ایندکس بزرگ نشود.
		while ( true ) {
			// عمداً فقط id و vector خوانده می‌شود. content تا ۴۰۰۰
			// کاراکتر است و کشیدنش برای هر سطر یعنی چند مگابایت انتقال
			// اضافه از دیتابیس برای چیزی که معمولاً استفاده نمی‌شود؛
			// متن فقط برای برنده‌های نهایی واکشی می‌شود.
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT id, vector FROM ' . $table
				. ' WHERE id > %d ORDER BY id ASC LIMIT ' . self::SCAN_PAGE,
				$offset
			) );

			if ( empty( $rows ) ) {
				break;
			}
			$paged = count( $rows );

			foreach ( $rows as $r ) {
				$vec = self::decode( $r->vector );
				if ( empty( $vec ) ) {
					$offset = (int) $r->id;
					continue;
				}
				// اگر ابعاد عوض شده و ایندکس قدیمی مانده، این سطر بی‌فایده است.
				if ( count( $vec ) === count( $qv ) ) {
					$score = self::dot( $qv, $vec );
					if ( $score >= $min ) {
						$scored[] = array( 'id' => (int) $r->id, 'score' => $score );
					}
				}
				$offset = (int) $r->id;
			}
			unset( $rows, $r, $vec );

			if ( $paged < self::SCAN_PAGE ) {
				break;
			}
		}

		usort( $scored, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		$scored = array_slice( $scored, 0, $top_k );

		// حالا فقط متن برنده‌ها را بخوان.
		$best = array();
		if ( ! empty( $scored ) ) {
			$ids      = array_map( 'intval', array_column( $scored, 'id' ) );
			$scores   = array();
			foreach ( $scored as $sc ) {
				$scores[ (int) $sc['id'] ] = $sc['score'];
			}
			$in       = implode( ',', $ids );
			$full     = $wpdb->get_results( 'SELECT id, title, url, content FROM ' . $table . " WHERE id IN ({$in}) ORDER BY id ASC" ); // phpcs:ignore WordPress.DB
			$by_id    = array();
			foreach ( (array) $full as $row ) {
				$by_id[ (int) $row->id ] = $row;
			}
			// ترتیب امتیاز را حفظ کن (کوئری بر اساس id مرتب شده است).
			foreach ( $ids as $id ) {
				if ( ! isset( $by_id[ $id ] ) ) {
					continue;
				}
				$best[] = array(
					'id'    => $id,
					'title' => (string) $by_id[ $id ]->title,
					'url'   => (string) $by_id[ $id ]->url,
					'body'  => (string) $by_id[ $id ]->content,
					'score' => $scores[ $id ],
				);
			}
		}

		if ( empty( $best ) ) {
			$result = array( 'context' => '', 'sources' => array(), 'hits' => array() );
			// نتیجهٔ خالی را کوتاه کش کن؛ شاید کاربر همین حالا ایندکس بسازد.
			set_transient( $ckey, $result, 10 * MINUTE_IN_SECONDS );
			return $result;
		}

		$docs    = array();
		$sources = array();
		$seen    = array();

		foreach ( $best as $h ) {
			$docs[] = sprintf(
				"- عنوان: %s\n  آدرس: %s\n  محتوا: %s",
				$h['title'],
				$h['url'],
				wp_trim_words( $h['body'], 220, '…' )
			);

			if ( ! isset( $seen[ $h['url'] ] ) ) {
				$seen[ $h['url'] ]  = true;
				$sources[]          = array(
					'title' => $h['title'],
					'url'   => $h['url'],
				);
			}
		}

		$context = "اطلاعات زیر با جست‌وجوی معنایی از سایت استخراج شده‌اند. اگر سؤال کاربر به این‌ها مربوط است، فقط بر اساس همین‌ها پاسخ بده و به لینک‌ها اشاره کن. اگر مرتبط نبود، نادیده بگیر:\n\n" . implode( "\n\n", $docs );

		$result = array(
			'context' => $context,
			'sources' => $sources,
			'hits'    => array_map( function ( $h ) {
				return array( 'title' => $h['title'], 'url' => $h['url'], 'score' => round( $h['score'], 4 ) );
			}, $best ),
		);

		$ttl = (int) apply_filters( 'pasokhban_rag_cache_ttl', 6 * HOUR_IN_SECONDS );
		set_transient( $ckey, $result, $ttl );

		return $result;
	}

	/**
	 * پاک‌کردن کش پرس‌وجوها.
	 *
	 * کلیدها شامل hash امضا و built_at هستند، پس در عمل با عوض‌شدن
	 * ایندکس خودشان بی‌اثر می‌شوند؛ این متد برای حالت «پاک‌کردن دستی»
	 * و تغییر تنظیمات است.
	 */
	public function flush_query_cache() {
		global $wpdb;
		// transientها در گزینه‌ها ذخیره می‌شوند؛ حذف مستقیم ارزان‌تر از
		// نگه‌داشتن فهرست کلیدهاست.
		if ( isset( $wpdb->options ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pasokhban\\_ragq\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pasokhban\\_ragq\\_%'"
			);
		}
	}

	/* =========================================================
	 * همگام‌سازی خودکار
	 * =======================================================*/

	/**
	 * وقتی نوشته‌ای ذخیره می‌شود، منبع را در صف تغییر می‌گذاریم.
	 *
	 * چرا اینجا embedding نمی‌گیریم: save_post داخل درخواست ذخیرهٔ مدیر
	 * اجرا می‌شود. گرفتن embedding آنجا یعنی دکمهٔ «انتشار» چند ثانیه
	 * هنگ می‌کند و اگر API خطا بدهد، ذخیرهٔ نوشته با خطا مواجه می‌شود.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public function on_save_post( $post_id, $post = null ) {
		if ( ! $this->enabled() ) {
			return;
		}
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( empty( $opts['rag_auto_sync'] ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$dirty   = get_option( self::OPT_DIRTY, array() );
		$dirty   = is_array( $dirty ) ? $dirty : array();
		$dirty[] = 'post:' . (int) $post_id;
		$dirty   = array_values( array_unique( $dirty ) );
		update_option( self::OPT_DIRTY, array_slice( $dirty, -200 ) );

		$this->maybe_schedule();
	}

	/**
	 * @param int $post_id
	 */
	public function on_delete_post( $post_id ) {
		if ( ! $this->table_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'DELETE FROM ' . Pasokhban_DB::chunks_table() . " WHERE source_type = 'post' AND source_id = %s",
			(string) (int) $post_id
		) );

		$dirty = get_option( self::OPT_DIRTY, array() );
		if ( is_array( $dirty ) ) {
			$dirty = array_values( array_diff( $dirty, array( 'post:' . (int) $post_id ) ) );
			update_option( self::OPT_DIRTY, $dirty );
		}
	}

	/**
	 * کرون ساعتی: فقط منابع تغییریافته.
	 */
	public function sync_dirty() {
		if ( ! $this->enabled() ) {
			return array( 'ok' => false, 'errors' => array( 'disabled' ) );
		}
		$dirty = get_option( self::OPT_DIRTY, array() );
		$dirty = is_array( $dirty ) ? array_values( $dirty ) : array();
		if ( empty( $dirty ) ) {
			return array( 'ok' => true, 'sources' => 0, 'chunks' => 0, 'skipped' => 0 );
		}

		$batch = array_slice( $dirty, 0, 20 );
		$res   = $this->build( array( 'only' => $batch ) );

		$rest = array_slice( $dirty, count( $batch ) );
		update_option( self::OPT_DIRTY, $rest );

		return $res;
	}

	/**
	 * نقطهٔ ورود از admin_init — زمان‌بندی کرون را بررسی می‌کند.
	 *
	 * چرا لازم است: اگر کاربر بعداً «به‌روزرسانی خودکار» را روشن کند،
	 * بدون این هوک هیچ کرونی زمان‌بندی نمی‌شد و نوشته‌های تازه تا ابد
	 * ایندکس نمی‌شدند.
	 */
	public static function bootstrap() {
		self::instance()->maybe_schedule();
	}

	/**
	 * زمان‌بندی کرون فقط وقتی لازم باشد.
	 */
	public function maybe_schedule() {
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( ! $this->enabled() || empty( $opts['rag_auto_sync'] ) ) {
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_unschedule_event( time(), self::CRON_HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	/* =========================================================
	 * اکشن‌های پیشخوان
	 * =======================================================*/

	/**
	 * @param array  $summary
	 * @param string $type    success|error|warning
	 */
	private function notice( array $summary, $type = 'success' ) {
		$lines = array();

		if ( ! empty( $summary['warning'] ) ) {
			$lines[] = $summary['warning'];
		}
		$lines[] = sprintf(
			/* translators: 1: sources, 2: chunks, 3: skipped, 4: ms */
			__( 'منابع پردازش‌شده: %1$d · تکهٔ تازه: %2$d · بدون تغییر: %3$d · زمان: %4$s ثانیه', 'pasokhban' ),
			(int) $summary['sources'],
			(int) $summary['chunks'],
			(int) $summary['skipped'],
			round( ( (int) $summary['ms'] ) / 1000, 1 )
		);
		if ( ! empty( $summary['removed'] ) ) {
			$lines[] = sprintf(
				/* translators: %d: removed sources */
				__( '%d منبع حذف‌شده از ایندکس پاک شد.', 'pasokhban' ),
				(int) $summary['removed']
			);
		}
		if ( ! empty( $summary['remaining'] ) ) {
			$lines[] = sprintf(
				/* translators: %d: remaining */
				__( 'هنوز %d منبع مانده (محدودیت زمان اجرا). دوباره همین دکمه را بزن تا ادامه پیدا کند.', 'pasokhban' ),
				(int) $summary['remaining']
			);
		}
		if ( ! empty( $summary['errors'] ) ) {
			$lines[] = implode( ' | ', array_map( 'sanitize_text_field', $summary['errors'] ) );
		}

		set_transient(
			'pasokhban_rag_notice',
			array( 'type' => $type, 'text' => implode( "\n", $lines ) ),
			5 * MINUTE_IN_SECONDS
		);
	}

	public function handle_build() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_rag_build' );

		$reset = ! empty( $_POST['reset'] );
		$res   = $this->build( array( 'reset' => $reset ) );

		$this->notice( $res, $res['ok'] ? 'success' : 'error' );
		$this->redirect_back();
	}

	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_rag_clear' );

		$this->clear();
		set_transient( 'pasokhban_rag_notice', array(
			'type' => 'success',
			'text' => __( 'ایندکس پاک شد. برای ساخت دوباره، دکمهٔ «ساخت ایندکس» را بزن.', 'pasokhban' ),
		), 5 * MINUTE_IN_SECONDS );
		$this->redirect_back();
	}

	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_rag_test' );

		$t0  = microtime( true );
		$res = $this->embed( array( 'آزمون اتصال پاسخ‌بان' ) );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$notice = array( 'type' => 'error', 'text' => $res->get_error_message() );
		} else {
			$notice = array(
				'type' => 'success',
				'text' => sprintf(
					/* translators: 1: dims, 2: ms */
					__( 'اتصال برقرار است. بردار %1$d بعدی در %2$s میلی‌ثانیه برگشت.', 'pasokhban' ),
					count( $res[0] ),
					$ms
				),
			);
		}

		set_transient( 'pasokhban_rag_notice', $notice, 5 * MINUTE_IN_SECONDS );
		$this->redirect_back();
	}

	private function redirect_back() {
		$url = wp_safe_redirect( admin_url( 'admin.php?page=pasokhban#pasokhban-rag' ) );
		if ( ! $url ) {
			wp_safe_redirect( admin_url( 'admin.php?page=pasokhban' ) );
		}
		exit;
	}

	/**
	 * نوار وضعیت برای رندر در صفحهٔ تنظیمات.
	 *
	 * @return array|null
	 */
	public function take_notice() {
		$n = get_transient( 'pasokhban_rag_notice' );
		if ( is_array( $n ) && ! empty( $n['text'] ) ) {
			delete_transient( 'pasokhban_rag_notice' );
			return $n;
		}
		return null;
	}
}
