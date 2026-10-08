<?php
/**
 * اندپوینت‌های REST و اتصال به API گپ‌جی‌پی‌تی (پاسخ‌بان).
 *
 * @package Pasokhban
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس API.
 */
final class Pasokhban_API {

	/** @var Pasokhban_API|null */
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

	public function register_routes() {
		register_rest_route( 'pasokhban/v1', '/chat', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_chat' ),
			'permission_callback' => array( $this, 'public_permission' ),
		) );

		register_rest_route( 'pasokhban/v1', '/config', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_config' ),
			'permission_callback' => array( $this, 'public_permission' ),
		) );
	}

	public function public_permission() {
		// ویجت برای عموم باز است؛ محدودیت در rate-limit و key انجام می‌شود.
		return true;
	}

	/**
	 * محدودسازی نرخ درخواست بر اساس IP با استفاده از transient.
	 *
	 * @param string $bucket سطل شمارنده (chat / live) تا ویجت مغز و چت آنلاین
	 *                       سهمیهٔ یکدیگر را نخورند.
	 * @return bool
	 */
	public function is_rate_limited( $bucket = 'chat' ) {
		$opts  = Pasokhban_Settings::instance()->get_options();
		$limit = (int) $opts['rate_limit'];
		if ( $limit <= 0 ) {
			return false;
		}

		$ip  = class_exists( 'Pasokhban_DB' ) ? Pasokhban_DB::client_ip() : 'unknown';
		$key = 'pasokhban_rl_' . sanitize_key( $bucket ) . '_' . md5( $ip );
		$val = (int) get_transient( $key );

		if ( $val >= $limit ) {
			return true;
		}

		set_transient( $key, $val + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * سازگار با نسخهٔ قبل.
	 *
	 * @return bool
	 */
	private function rate_limited() {
		return $this->is_rate_limited( 'chat' );
	}

	/**
	 * اندپوینت پیکربندی برای جاوااسکریپت.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_config( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();
		$lang = isset( $request['lang'] ) ? sanitize_key( $request['lang'] ) : 'fa';
		$lang = in_array( $lang, array( 'fa', 'en' ), true ) ? $lang : 'fa';

		return rest_ensure_response( array(
			'model'              => $opts['model'],
			'lang'               => $lang,
			'greeting'           => $opts['greeting'],
			'placeholder'        => $opts['placeholder'],
			'launcherText'       => $opts['launcher_text'],
			'tts'                => (bool) $opts['tts'],
			'stt'                => (bool) $opts['stt'],
			'siteName'           => get_bloginfo( 'name' ),
			'knowledge'          => (bool) $opts['knowledge'],
			'accent'             => $opts['accent'],
			'suggested'          => $this->suggested_questions( $lang ),
			'logoUrl'            => PASOKHBAN_URL . 'assets/img/etehad-logo.png',
			'logoNeonUrl'        => PASOKHBAN_URL . 'assets/img/etehad-logo-neon.png',
		) );
	}

	/**
	 * ساخت نمونه‌سؤال‌های پیشنهادی.
	 *
	 * @param string $lang
	 * @return array
	 */
	private function suggested_questions( $lang = 'fa' ) {
		$items = array();

		if ( 'en' === $lang ) {
			$items[] = 'How can I contact you?';
			$items[] = 'Do you offer consulting services?';
			$items[] = 'What are your prices?';
			$items[] = 'Where should I start?';
		} else {
			$items[] = 'چطور می‌تونم با شما تماس بگیرم؟';
			$items[] = 'آیا خدمات مشاوره هم دارید؟';
			$items[] = 'هزینه خدمات شما چقدر است؟';
			$items[] = 'از کجا باید شروع کنم؟';
		}

		$q = new WP_Query( array(
			'post_type'           => array( 'post', 'page' ),
			'post_status'         => 'publish',
			'posts_per_page'      => 6,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		) );

		if ( $q->have_posts() ) {
			foreach ( $q->posts as $p ) {
				$title = get_the_title( $p );
				if ( 'en' === $lang ) {
					$items[] = 'Tell me more about "' . $title . '"';
				} else {
					$items[] = 'درباره‌ی «' . $title . '» بیشتر توضیح بده';
				}
			}
		}
		wp_reset_postdata();

		$items = array_values( array_unique( array_filter( array_map( 'trim', $items ) ) ) );
		return array_slice( $items, 0, 8 );
	}

	/**
	 * جستجوی محتوای مرتبط در وردپرس برای RAG.
	 *
	 * @param string $query
	 * @return array { context: string, sources: array<int, array{title,url}> }
	 */
	public function get_site_docs( $query ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		// ── گام ۰: جست‌وجوی معنایی ──
		// اگر فعال باشد و ایندکس ساخته شده باشد، اول برداری می‌گردیم.
		// هر سه حالت زیر عمداً به مسیر کلیدواژه‌ای می‌افتند تا یک خطای
		// embedding یا ایندکس خالی هرگز چت را از کار نیندازد:
		//   null      → قابلیت خاموش یا ایندکس خالی
		//   WP_Error  → API خطا داد
		//   context='' → هیچ تکه‌ای از آستانهٔ شباهت رد نشد
		$rag = Pasokhban_RAG::instance();
		if ( $rag->enabled() ) {
			$vec = $rag->retrieve( $query );
			if ( is_array( $vec ) && ! empty( $vec['context'] ) ) {
				return array(
					'context' => $vec['context'],
					'sources' => $vec['sources'],
					'engine'  => 'vector',
					'hits'    => isset( $vec['hits'] ) ? $vec['hits'] : array(),
				);
			}
		}

		$terms  = $this->tokenize( $query );
		$search = implode( ' ', array_slice( $terms, 0, 6 ) );
		$key_q  = $search ? $search : $query;

		// جستجوی LIKE روی post_content روی سایت‌های بزرگ گران است و برای هر
		// پیام اجرا می‌شد. نتیجه را کش می‌کنیم تا سؤالات تکراری ارزان شوند.
		$cache_key = 'pasokhban_rag_' . md5( $key_q . '|' . (int) $opts['knowledge_posts'] );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['context'], $cached['sources'] ) ) {
			return $cached;
		}

		$limit_wanted = (int) $opts['knowledge_posts'];
		$posts        = array();

		// ── گام ۱: جستجوی عنوان ──
		// پارامتر 's' وردپرس یک LIKE روی post_content می‌زند که full table
		// scan است و روی سایت‌های پرمحتوا چند ثانیه طول می‌کشد. چون این
		// کوئری قبل از فراخوانی AI اجرا می‌شود، مستقیماً زمان «در حال فکر
		// کردن» را زیاد می‌کرد. جستجوی عنوان بسیار ارزان‌تر است و معمولاً
		// نتایج مرتبط‌تری هم می‌دهد، پس اول آن را امتحان می‌کنیم.
		if ( ! empty( $terms ) ) {
			$title_terms = array_slice( $terms, 0, 4 );
			foreach ( $title_terms as $tt ) {
				$tq = new WP_Query( array(
					'post_type'           => array( 'post', 'page' ),
					'post_status'         => 'publish',
					'posts_per_page'      => $limit_wanted,
					's'                   => $tt,
					'search_columns'      => array( 'post_title' ),
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'fields'              => 'ids',
				) );
				if ( ! empty( $tq->posts ) ) {
					foreach ( $tq->posts as $pid ) {
						$posts[ (int) $pid ] = true;
					}
				}
				wp_reset_postdata();
				if ( count( $posts ) >= $limit_wanted ) {
					break;
				}
			}
		}

		// ── گام ۲: فقط اگر عنوان کافی نبود، به سراغ محتوا برو ──
		if ( count( $posts ) < 2 ) {
			$cq = new WP_Query( array(
				'post_type'           => array( 'post', 'page' ),
				'post_status'         => 'publish',
				'posts_per_page'      => $limit_wanted,
				's'                   => $key_q,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
			) );
			if ( ! empty( $cq->posts ) ) {
				foreach ( $cq->posts as $pid ) {
					$posts[ (int) $pid ] = true;
				}
			}
			wp_reset_postdata();
		}

		$ids = array_slice( array_keys( $posts ), 0, $limit_wanted );

		$candidates = array();

		foreach ( $ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
			if ( ! $excerpt ) {
				$excerpt = wp_trim_words( strip_shortcodes( $post->post_content ), 80, '…' );
			}
			$title = get_the_title( $post );
			$url   = get_permalink( $post );
			$body  = wp_strip_all_tags( $excerpt );

			$candidates[] = array(
				'title' => $title,
				'url'   => $url,
				'body'  => $body,
				'score' => $this->relevance_score( $terms, $title, $body ),
			);
		}

		// جستجوی LIKE وردپرس با تطابق حتی یک کلمه نتیجه می‌دهد، پس اکثر
		// نتایج بی‌ربط بودند. اینجا بر اساس تعداد کلمه‌های مشترک امتیاز
		// می‌دهیم و فقط مرتبط‌ها را نگه می‌داریم.
		$kept = array_filter( $candidates, function ( $c ) {
			return $c['score'] >= 2;
		} );

		// مرتب‌سازی نزولی بر اساس ربط
		usort( $kept, function ( $a, $b ) {
			return $b['score'] - $a['score'];
		} );

		$limit   = max( 1, min( 5, (int) $opts['knowledge_posts'] ) );
		$kept    = array_slice( $kept, 0, $limit );

		$docs    = array();
		$sources = array();

		foreach ( $kept as $c ) {
			$docs[] = sprintf(
				"- عنوان: %s\n  آدرس: %s\n  محتوا: %s",
				$c['title'],
				$c['url'],
				$c['body']
			);
			$sources[] = array(
				'title' => $c['title'],
				'url'   => $c['url'],
			);
		}

		if ( empty( $docs ) ) {
			// کلید engine عمداً اینجا هم هست: فراخوان باید بتواند بدون
			// حدس زدن بفهمد کدام موتور جواب داده، حتی وقتی جوابی نبوده.
			$result = array( 'context' => '', 'sources' => array(), 'engine' => 'keyword' );
			// نتیجهٔ خالی را کوتاه‌تر کش کن تا اگر محتوا اضافه شد زود به‌روز شود
			set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
			return $result;
		}

		$context = "اطلاعات زیر از سایت استخراج شده‌اند. اگر سؤال کاربر به این‌ها مربوط است، فقط بر اساس همین‌ها پاسخ بده و به لینک‌ها اشاره کن. اگر مرتبط نبود، نادیده بگیر:\n\n" . implode( "\n\n", $docs );

		$result = array(
			'context' => $context,
			'sources' => $sources,
			'engine'  => 'keyword',
		);

		/**
		 * مدت زمان کش RAG.
		 *
		 * @param int $seconds
		 */
		$ttl = (int) apply_filters( 'pasokhban_rag_cache_ttl', 6 * HOUR_IN_SECONDS );
		set_transient( $cache_key, $result, $ttl );

		return $result;
	}

	/**
	 * سازگار با نسخهٔ قبل.
	 *
	 * @param string $query
	 * @return string
	 */
	private function get_site_context( $query ) {
		$r = $this->get_site_docs( $query );
		return $r['context'];
	}

	/**
	 * امتیاز ربط یک سند به پرس‌وجو.
	 *
	 * تطابق در عنوان وزن بیشتری دارد چون عنوان معمولاً موضوع را بهتر
	 * نشان می‌دهد. آستانهٔ پذیرش ۲ است (یعنی یا یک کلمه در عنوان،
	 * یا دو کلمه در متن).
	 *
	 * @param array  $terms کلمه‌های پرس‌وجو
	 * @param string $title
	 * @param string $body
	 * @return int
	 */
	private function relevance_score( array $terms, $title, $body ) {
		if ( empty( $terms ) ) {
			return 0;
		}

		$title_h = mb_strtolower( (string) $title, 'UTF-8' );
		$body_h  = mb_strtolower( (string) $body, 'UTF-8' );
		$score   = 0;

		foreach ( $terms as $term ) {
			$t = mb_strtolower( (string) $term, 'UTF-8' );
			if ( '' === $t ) {
				continue;
			}
			if ( false !== mb_strpos( $title_h, $t, 0, 'UTF-8' ) ) {
				$score += 2;
			}
			if ( false !== mb_strpos( $body_h, $t, 0, 'UTF-8' ) ) {
				$score += 1;
			}
		}

		return $score;
	}

	/**
	 * جدا کردن کلمات برای جستجو.
	 *
	 * @param string $text
	 * @return array
	 */
	private function tokenize( $text ) {
		$text  = mb_strtolower( wp_strip_all_tags( $text ), 'UTF-8' );
		$text  = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$words = preg_split( '/\s+/u', trim( (string) $text ) );
		$words = array_values( array_filter( (array) $words, function ( $w ) {
			return mb_strlen( $w, 'UTF-8' ) > 2;
		} ) );
		return array_slice( $words, 0, 12 );
	}

	/* =========================================================
	 * موتور تولید پاسخ (مشترک بین ویجت مغز و چت آنلاین)
	 * =======================================================*/

	/**
	 * صدا زدن مدل و برگرداندن پاسخ.
	 *
	 * @param array  $history آرایهٔ { role, content } — آخرین پیام باید user باشد.
	 * @param string $lang    fa|en
	 * @param array  $context { page, name, email, mode }
	 * @return array|WP_Error  { content, model, sources }
	 */
	public function generate_reply( array $history, $lang = 'fa', array $context = array() ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['api_key'] ) ) {
			return new WP_Error( 'pasokhban_no_key', __( 'کلید API تنظیم نشده است.', 'pasokhban' ), array( 'status' => 500 ) );
		}

		$built = $this->build_payload( $history, $lang, $context );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$out     = $built['messages'];
		$sources = $built['sources'];

		// ---- فراخوانی مدل (از راه لایهٔ سرویس‌دهنده‌ها) ----
		// قالب درخواست، هدرها و قالب پاسخ بسته به سرویس فرق می‌کند
		// (OpenAI / Gemini / Anthropic)، پس همه در class-providers است.
		$req = Pasokhban_Providers::request( $out, $opts, false );
		if ( is_wp_error( $req ) ) {
			return $req;
		}

		$response = wp_remote_post( $req['url'], array(
			'timeout' => 60,
			'headers' => $req['headers'],
			'body'    => $req['body'],
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'pasokhban_remote',
				__( 'خطا در ارتباط با سرور هوش مصنوعی.', 'pasokhban' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'pasokhban_badjson',
				sprintf(
					/* translators: 1: HTTP code, 2: body */
					__( 'پاسخ سرویس JSON نبود (http %1$s): %2$s', 'pasokhban' ),
					$code,
					mb_substr( $body, 0, 160 )
				),
				array( 'status' => 502 )
			);
		}

		$content = Pasokhban_Providers::parse( $opts, $data );

		if ( $code >= 400 || '' === trim( $content ) ) {
			$msg = Pasokhban_Providers::parse_error( $opts, $data );
			if ( '' === $msg ) {
				$msg = __( 'پاسخی از مدل دریافت نشد.', 'pasokhban' );
			}
			return new WP_Error( 'pasokhban_model', sanitize_text_field( $msg ), array( 'status' => 502 ) );
		}

		return array(
			'content'  => $content,
			'model'    => $opts['model'],
			'sources'  => $sources,
			'provider' => Pasokhban_Providers::current( $opts )['key'],
		);
	}

	/**
	 * یک فراخوانی سادهٔ chat/completions با prompt دلخواه.
	 *
	 * چرا جدا از generate_reply: آن متد system_prompt سایت، RAG، حافظهٔ
	 * مکالمه و زبان را خودش سرهم می‌کند. کارهایی مثل «پیش‌نویس پاسخ برای
	 * اپراتور» یا «خلاصهٔ مکالمه» prompt کاملاً متفاوتی لازم دارند و
	 * نباید prompt چت با آن‌ها قاطی شود.
	 *
	 * @param string $system
	 * @param string $user
	 * @param int    $max_tokens
	 * @return string|WP_Error
	 */
	public function raw_complete( $system, $user, $max_tokens = 400 ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['api_key'] ) ) {
			return new WP_Error(
				'pasokhban_no_key',
				__( 'کلید API تنظیم نشده است.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		$user = trim( (string) $user );
		if ( '' === $user ) {
			return new WP_Error(
				'pasokhban_empty',
				__( 'متن ورودی خالی است.', 'pasokhban' ),
				array( 'status' => 400 )
			);
		}

		// دمای پایین‌تر از چت: پیش‌نویس باید قابل اتکا باشد، نه خلاق.
		// از راه لایهٔ سرویس‌دهنده‌ها می‌رود تا با جمینای و آنتروپیک هم کار کند.
		$raw_opts = $opts;
		$raw_opts['temperature'] = 0.3;
		$raw_opts['max_tokens']  = max( 50, (int) $max_tokens );

		$req = Pasokhban_Providers::request(
			array(
				array( 'role' => 'system', 'content' => (string) $system ),
				array( 'role' => 'user',   'content' => $user ),
			),
			$raw_opts,
			false
		);
		if ( is_wp_error( $req ) ) {
			return $req;
		}

		$response = wp_remote_post( $req['url'], array(
			'timeout' => 60,
			'headers' => $req['headers'],
			'body'    => $req['body'],
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'pasokhban_remote',
				__( 'خطا در ارتباط با سرور هوش مصنوعی.', 'pasokhban' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$text = is_array( $data ) ? Pasokhban_Providers::parse( $raw_opts, $data ) : '';

		if ( $code >= 400 || '' === trim( $text ) ) {
			$msg = is_array( $data ) ? Pasokhban_Providers::parse_error( $raw_opts, $data ) : '';
			if ( '' === $msg ) {
				$msg = __( 'پاسخی از مدل دریافت نشد.', 'pasokhban' );
			}
			return new WP_Error( 'pasokhban_model', sanitize_text_field( $msg ), array( 'status' => 502 ) );
		}

		return (string) $data['choices'][0]['message']['content'];
	}

	/**
	 * ساخت فهرست پیام‌هایی که به مدل فرستاده می‌شود.
	 *
	 * مشترک بین مسیر عادی (generate_reply) و مسیر جریان‌دار (Pasokhban_Stream)
	 * تا هر دو دقیقاً یک prompt بسازند.
	 *
	 * @param array  $history
	 * @param string $lang
	 * @param array  $context
	 * @return array|WP_Error { messages: array, sources: array }
	 */
	public function build_payload( array $history, $lang = 'fa', array $context = array() ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['api_key'] ) ) {
			return new WP_Error( 'pasokhban_no_key', __( 'کلید API تنظیم نشده است.', 'pasokhban' ), array( 'status' => 500 ) );
		}

		$history = $this->truncate_history( $history, (int) $opts['live_history'] );
		if ( empty( $history ) ) {
			return new WP_Error( 'pasokhban_empty', __( 'پیام خالی است.', 'pasokhban' ), array( 'status' => 400 ) );
		}

		$lang = in_array( $lang, array( 'fa', 'en' ), true ) ? $lang : 'fa';

		$system = $opts['system_prompt'];

		if ( ! empty( $context['mode'] ) && 'live' === $context['mode'] ) {
			$system .= "\n\n" . ( 'en' === $lang
				? 'You are the live-chat support agent of this website. Keep answers short (2-4 sentences), warm and practical. If the visitor clearly wants a human, tell them an agent will reply shortly.'
				: 'تو اپراتور گفتگوی آنلاین این سایت هستی. پاسخ‌ها را کوتاه (۲ تا ۴ جمله)، گرم و کاربردی بده. اگر کاربر صراحتاً انسان خواست، بگو به‌زودی یک اپراتور پاسخ می‌دهد.' );
		}

		$system .= 'en' === $lang ? "\n\nRespond to the user in English." : "\n\nپاسخ را به زبان فارسی بده.";

		$out = array( array( 'role' => 'system', 'content' => $system ) );

		$page_bits = array();
		if ( ! empty( $context['page'] ) ) {
			$page_bits[] = 'صفحه‌ای که کاربر در آن است: ' . esc_url_raw( $context['page'] );
		}
		if ( ! empty( $context['name'] ) ) {
			$page_bits[] = 'نام کاربر: ' . sanitize_text_field( $context['name'] );
		}
		if ( $page_bits ) {
			$out[] = array( 'role' => 'system', 'content' => implode( "\n", $page_bits ) );
		}

		// وضعیت سفارش ووکامرس — قبلاً از دیتابیس تأیید هویت شده است،
		// پس اینجا فقط به مدل داده می‌شود تا درباره‌اش حرف بزند.
		if ( ! empty( $context['order'] ) && is_array( $context['order'] )
			&& class_exists( 'Pasokhban_Woo' ) ) {
			$note = trim( Pasokhban_Woo::describe( $context['order'] ) );
			if ( '' !== $note ) {
				$out[] = array( 'role' => 'system', 'content' => $note );
			}
		}

		$sources = array();
		if ( ! empty( $opts['knowledge'] ) ) {
			$last = '';
			for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
				if ( isset( $history[ $i ]['role'] ) && 'user' === $history[ $i ]['role'] ) {
					$last = (string) $history[ $i ]['content'];
					break;
				}
			}
			if ( '' !== $last ) {
				$docs = $this->get_site_docs( $last );
				if ( $docs['context'] ) {
					$out[]   = array( 'role' => 'system', 'content' => $docs['context'] );
					$sources = $docs['sources'];
				}
			}
		}

		foreach ( $history as $m ) {
			$role  = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$out[] = array(
				'role'    => $role,
				'content' => isset( $m['content'] ) ? (string) $m['content'] : '',
			);
		}

		return array(
			'messages' => $out,
			'sources'  => $sources,
		);
	}

	/**
	 * کوتاه‌کردن تاریخچه تا آخرین N پیام، بدون شکستن جفت user/assistant.
	 *
	 * @param array $history
	 * @param int   $max
	 * @return array
	 */
	private function truncate_history( array $history, $max = 20 ) {
		$max = max( 4, min( 60, $max ) );

		if ( count( $history ) <= $max ) {
			return array_values( $history );
		}

		$slice = array_slice( $history, -$max );

		// اگر با پیام assistant شروع شد، آن را حذف کن تا مدل با user شروع کند.
		if ( isset( $slice[0]['role'] ) && 'assistant' === $slice[0]['role'] ) {
			array_shift( $slice );
		}

		return array_values( $slice );
	}

	/* =========================================================
	 * اندپوینت ویجت مغز
	 * =======================================================*/

	/**
	 * پردازش پیام و پاسخ‌گویی.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chat( $request ) {
		$opts = Pasokhban_Settings::instance()->get_options();

		if ( empty( $opts['api_key'] ) ) {
			return new WP_Error( 'pasokhban_no_key', __( 'کلید API تنظیم نشده است. با مدیر سایت تماس بگیرید.', 'pasokhban' ), array( 'status' => 500 ) );
		}

		if ( $this->rate_limited() ) {
			return new WP_Error( 'pasokhban_rate', __( 'درخواست‌های زیادی ارسال کردید. کمی صبر کنید.', 'pasokhban' ), array( 'status' => 429 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || empty( $params['messages'] ) || ! is_array( $params['messages'] ) ) {
			return new WP_Error( 'pasokhban_empty', __( 'پیام خالی است.', 'pasokhban' ), array( 'status' => 400 ) );
		}

		// نرمال‌سازی ایمن: نقش و محتوا همیشه هم‌ردیف می‌مانند.
		$history = array();
		foreach ( $params['messages'] as $m ) {
			if ( ! is_array( $m ) || ! isset( $m['content'] ) ) {
				continue;
			}
			$content = sanitize_textarea_field( (string) $m['content'] );
			if ( '' === $content ) {
				continue;
			}
			$role      = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$history[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		if ( empty( $history ) ) {
			return new WP_Error( 'pasokhban_empty', __( 'پیام خالی است.', 'pasokhban' ), array( 'status' => 400 ) );
		}

		$lang = isset( $params['lang'] ) ? sanitize_key( $params['lang'] ) : 'fa';

		$result = $this->generate_reply( $history, $lang, array( 'mode' => 'widget' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'content' => $result['content'],
			'sources' => $result['sources'],
			'model'   => $result['model'],
		) );
	}
}
