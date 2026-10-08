<?php
/**
 * پاسخ جریان‌دار (Server-Sent Events) برای گفتگوی آنلاین.
 *
 * متن کلمه‌به‌کلمه به مرورگر می‌رسد؛ در پایان، پیام کامل در دیتابیس
 * ذخیره می‌شود و شناسه‌اش برای ثبت امتیاز (CSAT) فرستاده می‌شود.
 *
 * اگر به هر دلیلی جریان برقرار نشود، یک رویداد error می‌فرستیم و کلاینت
 * به مسیر معمولی /live/send برمی‌گردد.
 *
 * @package Pasokhban
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس پاسخ جریان‌دار.
 */
final class Pasokhban_Stream {

	/** @var Pasokhban_Stream|null */
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
		register_rest_route(
			'pasokhban/v1',
			'/live/stream',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_stream' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * هندلر جریان.
	 *
	 * @param WP_REST_Request $request
	 */
	public function handle_stream( $request ) {
		$opts   = Pasokhban_Settings::instance()->get_options();
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$this->open_stream();

		if ( empty( $opts['live_enabled'] ) ) {
			$this->fail( __( 'گفتگوی آنلاین فعال نیست.', 'pasokhban' ) );
		}

		$content = isset( $params['content'] ) ? sanitize_textarea_field( (string) $params['content'] ) : '';
		if ( '' === $content ) {
			$this->fail( __( 'پیام خالی است.', 'pasokhban' ) );
		}
		if ( mb_strlen( $content, 'UTF-8' ) > 4000 ) {
			$content = mb_substr( $content, 0, 4000, 'UTF-8' );
		}

		$session = Pasokhban_DB::get_session_by_key( isset( $params['key'] ) ? $params['key'] : '' );
		if ( ! $session ) {
			$this->fail( __( 'نشست پیدا نشد. صفحه را تازه کن.', 'pasokhban' ), 'nosession' );
		}

		if ( Pasokhban_API::instance()->is_rate_limited( 'live' ) ) {
			$this->fail( __( 'درخواست‌های زیادی ارسال کردی. کمی صبر کن.', 'pasokhban' ), 'rate' );
		}

		$lang = isset( $params['lang'] ) ? sanitize_key( (string) $params['lang'] ) : 'fa';
		$lang = in_array( $lang, array( 'fa', 'en' ), true ) ? $lang : 'fa';

		// ضمیمه‌ها — همان منطق /live/send (کلاینت توکن می‌فرستد، نه URL)
		$meta = array();
		if ( ! empty( $opts['live_upload'] ) && ! empty( $params['attachments'] ) ) {
			$tokens = is_string( $params['attachments'] ) ? explode( ',', $params['attachments'] ) : (array) $params['attachments'];
			$atts   = Pasokhban_Upload::resolve_tokens( (int) $session->id, $tokens );
			if ( ! empty( $atts ) ) {
				$meta['attachments'] = $atts;
			}
		}

		// پیام کاربر را اول ذخیره کن
		$visitor_msg = Pasokhban_DB::add_message( (int) $session->id, 'visitor', $content, $meta );
		if ( ! $visitor_msg ) {
			$this->fail( __( 'ذخیرهٔ پیام ممکن نشد.', 'pasokhban' ) );
		}

		Pasokhban_DB::update_session(
			(int) $session->id,
			array(
				'lang'         => $lang,
				'current_url'  => isset( $params['current_url'] ) ? esc_url_raw( (string) $params['current_url'] ) : $session->current_url,
				'unread_agent' => (int) $session->unread_agent + 1,
			)
		);
		Pasokhban_DB::touch_session( (int) $session->id, $content, 'visitor' );

		$this->emit( array( 'visitor' => (int) $visitor_msg->id ) );

		// اگر اپراتور انسانی متولی است، جریان معنا ندارد
		if ( 'agent' === $session->mode ) {
			$this->emit( array( 'queued' => true, 'mode' => 'agent' ) );
			$this->close_stream();
		}

		// ---- ساخت prompt ----
		$history = array();
		foreach ( Pasokhban_DB::get_messages( (int) $session->id, 0, (int) $opts['live_history'] ) as $m ) {
			if ( 'visitor' === $m->sender ) {
				$history[] = array( 'role' => 'user', 'content' => $m->content . Pasokhban_Upload::describe( $m ) );
			} elseif ( 'agent' === $m->sender ) {
				$history[] = array( 'role' => 'assistant', 'content' => $m->content );
			}
		}

		$built = Pasokhban_API::instance()->build_payload(
			$history,
			$lang,
			array(
				'mode'  => 'live',
				'page'  => $session->current_url ? $session->current_url : $session->entry_url,
				'name'  => $session->visitor_name,
				'order' => Pasokhban_Woo::latest_from_messages(
					Pasokhban_DB::get_messages( (int) $session->id, 0, (int) $opts['live_history'] )
				),
			)
		);

		if ( is_wp_error( $built ) ) {
			$this->fail( $built->get_error_message(), $built->get_error_code() );
		}

		// قالب درخواست و هدرها بسته به سرویس فرق می‌کند (OpenAI / Gemini /
		// Anthropic)، پس همه در class-providers ساخته می‌شود.
		$req = Pasokhban_Providers::request( $built['messages'], $opts, true );
		if ( is_wp_error( $req ) ) {
			$this->fail( $req->get_error_message() );
		}

		$started = microtime( true );

		$response = wp_remote_post( $req['url'], array(
			'timeout'  => 120,
			'stream'   => true,
			'blocking' => true,
			'headers'  => $req['headers'],
			'body'     => $req['body'],
		) );

		if ( is_wp_error( $response ) ) {
			$this->fail( __( 'خطا در ارتباط با سرور هوش مصنوعی.', 'pasokhban' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = isset( $response['body'] ) ? $response['body'] : null;

		// اگر سرور جریان نداد، بدنه یک رشتهٔ JSON معمولی است
		if ( ! is_resource( $body ) ) {
			$data = json_decode( (string) $body, true );
			$msg  = '';
			if ( is_array( $data ) ) {
				$text = Pasokhban_Providers::parse( $opts, $data );
				if ( '' !== trim( $text ) ) {
					$this->finish(
						$session,
						$text,
						$built['sources'],
						(int) round( ( microtime( true ) - $started ) * 1000 )
					);
				}
				$msg = Pasokhban_Providers::parse_error( $opts, $data );
			}
			if ( '' === $msg ) {
				$msg = __( 'پاسخ دریافت نشد.', 'pasokhban' );
			}
			$this->fail( sanitize_text_field( $msg ), 'model', $code );
		}

		if ( $code >= 400 ) {
			$raw  = '';
			$read = fread( $body, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$raw  = is_string( $read ) ? $read : '';
			fclose( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$data = json_decode( $raw, true );
			$msg  = is_array( $data ) ? Pasokhban_Providers::parse_error( $opts, $data ) : '';
			if ( '' === $msg ) {
				$msg = __( 'پاسخ دریافت نشد.', 'pasokhban' );
			}
			$this->fail( sanitize_text_field( $msg ), 'model', $code );
		}

		// ---- خواندن جریان ----
		$full   = '';
		$buffer = '';
		$loop   = 0;

		while ( ! feof( $body ) ) {
			// اگر کاربر صفحه را بسته باشد، ادامهٔ خواندن از API فقط
			// منابع سرور و سهمیهٔ هوش مصنوعی را هدر می‌دهد.
			if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
				break;
			}

			// سقف ایمنی در برابر حلقهٔ بی‌پایان
			if ( ++$loop > 20000 ) {
				break;
			}

			$chunk = fread( $body, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$buffer .= $chunk;

			// رویدادهای SSE با خط خالی جدا می‌شوند
			while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
				$line   = rtrim( substr( $buffer, 0, $pos ), "\r" );
				$buffer = substr( $buffer, $pos + 1 );

				if ( '' === $line ) {
					continue;
				}
				if ( 0 !== strpos( $line, 'data:' ) ) {
					continue;
				}

				$data = trim( substr( $line, 5 ) );

				// پایان جریان بسته به سرویس فرق می‌کند: OpenAI با [DONE]،
				// آنتروپیک با message_stop، جمینای با finishReason.
				if ( Pasokhban_Providers::stream_done( $opts, $data ) ) {
					break 2;
				}

				$json = json_decode( $data, true );
				if ( ! is_array( $json ) ) {
					continue;
				}

				$sse_err = Pasokhban_Providers::parse_error( $opts, $json );
				if ( '' !== $sse_err ) {
					fclose( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$this->fail( sanitize_text_field( $sse_err ), 'model' );
				}

				// قالب توکن هم بسته به سرویس فرق می‌کند
				$token = Pasokhban_Providers::stream_token( $opts, $json );

				if ( '' !== $token ) {
					$full .= $token;
					$this->emit( array( 't' => $token ) );
				}
			}
		}

		if ( is_resource( $body ) ) {
			fclose( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		if ( '' === trim( $full ) ) {
			$this->fail( __( 'پاسخ خالی بود.', 'pasokhban' ) );
		}

		$this->finish( $session, $full, $built['sources'], (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}

	/* =========================================================
	 * ابزارهای SSE
	 * =======================================================*/

	/**
	 * باز کردن جریان و پاک‌کردن بافرهای وردپرس.
	 */
	private function open_stream() {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform, must-revalidate' );
		header( 'X-Accel-Buffering: no' );   // nginx
		header( 'Connection: keep-alive' );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// عمداً ignore_user_abort تنظیم نمی‌کنیم: می‌خواهیم اگر کاربر
		// صفحه را بست، connection_aborted() تشخیص بدهد و حلقه متوقف شود.

		// فشرده‌سازی خروجی، جریان را بافر می‌کند و اثر streaming را از بین می‌برد
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// همهٔ بافرها را خالی کن وگرنه رویدادها تا پایان نگه داشته می‌شوند
		$guard = 0;
		while ( ob_get_level() > 0 && $guard < 20 ) {
			if ( false === @ob_end_flush() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			}
			$guard++;
		}

		$this->emit( array( 'open' => true ) );
	}

	/**
	 * فرستادن یک رویداد.
	 *
	 * @param array $data
	 */
	private function emit( array $data ) {
		echo 'data: ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) . "\n\n";
		$this->flush();
	}

	/**
	 * خطا و پایان.
	 *
	 * @param string $msg
	 * @param string $code
	 * @param int    $status
	 */
	private function fail( $msg, $code = 'error', $status = 0 ) {
		$this->emit( array( 'error' => $msg, 'code' => $code, 'status' => $status ) );
		$this->close_stream();
	}

	/**
	 * ذخیرهٔ پاسخ کامل و فرستادن رویداد پایان.
	 *
	 * @param object $session
	 * @param string $content
	 * @param array  $sources
	 * @param int    $ms
	 */
	private function finish( $session, $content, array $sources, $ms ) {
		$saved = Pasokhban_DB::add_message(
			(int) $session->id,
			'agent',
			$content,
			array(
				'via'     => 'ai',
				'sources' => $sources,
			),
			$ms
		);

		if ( $saved ) {
			Pasokhban_DB::update_session(
				(int) $session->id,
				array( 'unread_visitor' => (int) $session->unread_visitor + 1 )
			);
			Pasokhban_DB::touch_session( (int) $session->id, $content, 'agent' );
		}

		$this->emit(
			array(
				'done'    => true,
				'id'      => $saved ? (int) $saved->id : 0,
				'ms'      => $ms,
				'sources' => $sources,
			)
		);

		$this->close_stream();
	}

	/**
	 * پایان جریان.
	 */
	private function close_stream() {
		$this->flush();
		exit;
	}

	/**
	 * flush با سازگاری محیط‌های مختلف.
	 */
	private function flush() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// فقط flush، نه پایان درخواست
		}
		if ( ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
