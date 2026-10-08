<?php
/**
 * پشتیبانی از چند سرویس‌دهندهٔ هوش مصنوعی — پاسخ‌بان.
 *
 * تا ۲.۸ فقط قالب OpenAI-supported (`/chat/completions` با Bearer token)
 * پشتیبانی می‌شد. این کلاس سه قالب متفاوت را پشت یک رابط یکسان می‌برد:
 *
 *   • OpenAI-compatible — gapgpt، OpenAI، و هر پروکسی سازگار
 *   • Gemini            — گوگل؛ قالب contents/parts و کلید در query
 *   • Anthropic         — کلاد؛ هدر x-api-key و system بیرون از messages
 *
 * ── چرا یک کلاس جدا و نه if/else در سه جا ──
 * قالب درخواست، هدرها، قالب پاسخ، و قالب استریم هر سه سرویس با هم فرق
 * دارند. اگر این‌ها را در generate_reply و class-stream و class-copilot
 * پخش می‌کردیم، هر بار که سرویسی اضافه می‌شد باید سه جا را دست می‌زدیم
 * و یکی حتماً فراموش می‌شد.
 *
 * @package Pasokhban
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس سرویس‌دهنده‌ها.
 */
final class Pasokhban_Providers {

	public static function hooks() {
		add_action( 'admin_post_pasokhban_test_provider', array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * فهرست سرویس‌دهنده‌ها.
	 *
	 * @return array<string, array>
	 */
	public static function presets() {
		return array(
			'gapgpt'    => array(
				'label'    => __( 'گپ‌جی‌پی‌تی (پروکسی ایرانی)', 'pasokhban' ),
				'api'      => 'openai',
				'base_url' => 'https://api.gapgpt.app/v1',
				'models'   => array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'claude-3-5-sonnet' ),
				'note'     => __( 'بدون نیاز به پروکسی — از ایران مستقیم کار می‌کند.', 'pasokhban' ),
			),
			'openai'    => array(
				'label'    => 'OpenAI (ChatGPT)',
				'api'      => 'openai',
				'base_url' => 'https://api.openai.com/v1',
				'models'   => array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1-mini', 'o4-mini' ),
				'note'     => __( 'از ایران مستقیم در دسترس نیست؛ پروکسی لازم دارد.', 'pasokhban' ),
			),
			'gemini'    => array(
				'label'    => 'Google Gemini',
				'api'      => 'gemini',
				'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
				'models'   => array( 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash' ),
				'note'     => __( 'کلید رایگان از aistudio.google.com. از ایران ممکن است پروکسی لازم داشته باشد.', 'pasokhban' ),
			),
			'anthropic' => array(
				'label'    => 'Anthropic (Claude)',
				'api'      => 'anthropic',
				'base_url' => 'https://api.anthropic.com/v1',
				'models'   => array( 'claude-sonnet-4-5', 'claude-3-5-haiku', 'claude-opus-4-1' ),
				'note'     => __( 'از ایران مستقیم در دسترس نیست؛ پروکسی لازم دارد.', 'pasokhban' ),
			),
			'custom'    => array(
				'label'    => __( 'سازگار با OpenAI (دلخواه)', 'pasokhban' ),
				'api'      => 'openai',
				'base_url' => '',
				'models'   => array(),
				'note'     => __( 'هر سرویسی که قالب /chat/completions داشته باشد.', 'pasokhban' ),
			),
		);
	}

	/**
	 * سرویس‌دهندهٔ فعال.
	 *
	 * @param array $opts
	 * @return array { key, api, base_url, key_header }
	 */
	public static function current( array $opts ) {
		$presets = self::presets();
		$key     = isset( $opts['ai_provider'] ) ? (string) $opts['ai_provider'] : 'gapgpt';

		if ( ! isset( $presets[ $key ] ) ) {
			$key = 'gapgpt';
		}

		$p = $presets[ $key ];

		// آدرس پایه: اگر کاربر خودش چیزی گذاشته باشد، همان اولویت دارد
		// (مثلاً پروکسی شخصی). وگرنه پیش‌فرض سرویس.
		$base = ! empty( $opts['base_url'] ) ? (string) $opts['base_url'] : $p['base_url'];

		return array(
			'key'      => $key,
			'api'      => $p['api'],
			'base_url' => untrailingslashit( $base ),
		);
	}

	/* =========================================================
	 * ساخت درخواست
	 * =======================================================*/

	/**
	 * ساخت درخواست کامل برای یک سرویس‌دهنده.
	 *
	 * @param array $messages قالب OpenAI: [{role, content}, …]
	 * @param array $opts
	 * @param bool  $stream
	 * @return array|WP_Error { url, headers, body }
	 */
	public static function request( array $messages, array $opts, $stream = false ) {
		$prov = self::current( $opts );
		$key  = (string) $opts['api_key'];
		$model = (string) $opts['model'];

		if ( '' === trim( $key ) ) {
			return new WP_Error( 'pasokhban_no_key', __( 'کلید API تنظیم نشده است.', 'pasokhban' ) );
		}
		if ( '' === trim( $model ) ) {
			return new WP_Error( 'pasokhban_no_model', __( 'مدل انتخاب نشده است.', 'pasokhban' ) );
		}

		switch ( $prov['api'] ) {

			case 'gemini':
				return self::request_gemini( $messages, $prov, $key, $model, $opts, $stream );

			case 'anthropic':
				return self::request_anthropic( $messages, $prov, $key, $model, $opts, $stream );

			case 'openai':
			default:
				return self::request_openai( $messages, $prov, $key, $model, $opts, $stream );
		}
	}

	/**
	 * قالب OpenAI و همهٔ پروکسی‌های سازگار.
	 */
	private static function request_openai( array $messages, array $prov, $key, $model, array $opts, $stream ) {
		$payload = array(
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => (int) $opts['max_tokens'],
			'temperature' => (float) $opts['temperature'],
		);
		if ( $stream ) {
			$payload['stream'] = true;
		}

		return array(
			'url'     => $prov['base_url'] . '/chat/completions',
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => $stream ? 'text/event-stream' : 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		);
	}

	/**
	 * قالب گوگل جمینای.
	 *
	 * تفاوت‌های کلیدی با OpenAI:
	 *   • نقش system یک فیلد جدا به نام systemInstruction است، نه یک پیام
	 *   • نقش assistant به model تبدیل می‌شود
	 *   • محتوا داخل parts پیچیده می‌شود
	 *   • کلید API در query string می‌رود (یا هدر x-goog-api-key)
	 *   • مسیر استریم :streamGenerateContent?alt=sse است
	 */
	private static function request_gemini( array $messages, array $prov, $key, $model, array $opts, $stream ) {
		$system   = array();
		$contents = array();

		foreach ( $messages as $m ) {
			$role = isset( $m['role'] ) ? (string) $m['role'] : 'user';
			$text = isset( $m['content'] ) ? (string) $m['content'] : '';
			if ( '' === $text ) {
				continue;
			}

			if ( 'system' === $role ) {
				$system[] = $text;
				continue;
			}

			$contents[] = array(
				'role'  => ( 'assistant' === $role ) ? 'model' : 'user',
				'parts' => array( array( 'text' => $text ) ),
			);
		}

		// جمینای با تاریخچهٔ خالی خطا می‌دهد
		if ( empty( $contents ) ) {
			return new WP_Error( 'pasokhban_empty', __( 'پیامی برای ارسال نیست.', 'pasokhban' ) );
		}

		$payload = array(
			'contents'        => $contents,
			'generationConfig' => array(
				'maxOutputTokens' => (int) $opts['max_tokens'],
				'temperature'     => (float) $opts['temperature'],
			),
		);
		if ( ! empty( $system ) ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => implode( "\n\n", $system ) ) ),
			);
		}

		$action = $stream ? ':streamGenerateContent?alt=sse' : ':generateContent';
		$url    = $prov['base_url'] . '/models/' . rawurlencode( $model ) . $action . '&key=' . rawurlencode( $key );

		return array(
			'url'     => $url,
			'headers' => array(
				'Content-Type'   => 'application/json',
				// کلید هم در query هست و هم در هدر — بعضی پروکسی‌ها query
				// را دور می‌اندازند، پس هدر را هم می‌فرستیم.
				'x-goog-api-key' => $key,
				'Accept'         => $stream ? 'text/event-stream' : 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		);
	}

	/**
	 * قالب آنتروپیک (کلاد).
	 *
	 * تفاوت‌های کلیدی:
	 *   • system یک فیلد رشته‌ای در سطح بالاست، نه پیام
	 *   • احراز هویت با x-api-key و هدر anthropic-version
	 *   • max_tokens اجباری است
	 *   • مسیر /messages است
	 */
	private static function request_anthropic( array $messages, array $prov, $key, $model, array $opts, $stream ) {
		$system   = array();
		$msgs     = array();

		foreach ( $messages as $m ) {
			$role = isset( $m['role'] ) ? (string) $m['role'] : 'user';
			$text = isset( $m['content'] ) ? (string) $m['content'] : '';
			if ( '' === $text ) {
				continue;
			}
			if ( 'system' === $role ) {
				$system[] = $text;
				continue;
			}
			$msgs[] = array(
				'role'    => ( 'assistant' === $role ) ? 'assistant' : 'user',
				'content' => $text,
			);
		}

		if ( empty( $msgs ) ) {
			return new WP_Error( 'pasokhban_empty', __( 'پیامی برای ارسال نیست.', 'pasokhban' ) );
		}

		$payload = array(
			'model'       => $model,
			'messages'    => $msgs,
			'max_tokens'  => max( 1, (int) $opts['max_tokens'] ),
			'temperature' => (float) $opts['temperature'],
			'stream'      => (bool) $stream,
		);
		if ( ! empty( $system ) ) {
			$payload['system'] = implode( "\n\n", $system );
		}

		return array(
			'url'     => $prov['base_url'] . '/messages',
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
				'Accept'            => $stream ? 'text/event-stream' : 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		);
	}

	/* =========================================================
	 * تجزیهٔ پاسخ
	 * =======================================================*/

	/**
	 * استخراج متن از پاسخ غیرجریانی.
	 *
	 * @param array $opts
	 * @param array $data
	 * @return string
	 */
	public static function parse( array $opts, array $data ) {
		$api = self::current( $opts );

		switch ( $api['api'] ) {
			case 'gemini':
				// جمینای متن را در چند part می‌دهد؛ همه را به هم می‌چسبانیم
				if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
					$parts = array();
					foreach ( $data['candidates'][0]['content']['parts'] as $p ) {
						if ( isset( $p['text'] ) ) {
							$parts[] = (string) $p['text'];
						}
					}
					return implode( '', $parts );
				}
				return '';

			case 'anthropic':
				if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
					$parts = array();
					foreach ( $data['content'] as $block ) {
						if ( isset( $block['text'] ) ) {
							$parts[] = (string) $block['text'];
						}
					}
					return implode( '', $parts );
				}
				return '';

			case 'openai':
			default:
				if ( isset( $data['choices'][0]['message']['content'] ) ) {
					return (string) $data['choices'][0]['message']['content'];
				}
				return '';
		}
	}

	/**
	 * استخراج پیام خطا از پاسخ.
	 *
	 * @param array $opts
	 * @param array $data
	 * @return string
	 */
	public static function parse_error( array $opts, array $data ) {
		$api = self::current( $opts );

		if ( 'gemini' === $api['api'] && isset( $data['error']['message'] ) ) {
			return (string) $data['error']['message'];
		}
		if ( 'anthropic' === $api['api'] && isset( $data['error']['message'] ) ) {
			return (string) $data['error']['message'];
		}
		if ( isset( $data['error']['message'] ) ) {
			return (string) $data['error']['message'];
		}
		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return $data['error'];
		}

		return '';
	}

	/**
	 * استخراج یک توکن از رویداد SSE.
	 *
	 * @param array $opts
	 * @param array $json
	 * @return string
	 */
	public static function stream_token( array $opts, array $json ) {
		$api = self::current( $opts );

		switch ( $api['api'] ) {
			case 'gemini':
				if ( isset( $json['candidates'][0]['content']['parts'] ) && is_array( $json['candidates'][0]['content']['parts'] ) ) {
					$out = '';
					foreach ( $json['candidates'][0]['content']['parts'] as $p ) {
						if ( isset( $p['text'] ) ) {
							$out .= (string) $p['text'];
						}
					}
					return $out;
				}
				return '';

			case 'anthropic':
				// رویداد content_block_delta با delta.text
				if ( isset( $json['delta']['text'] ) ) {
					return (string) $json['delta']['text'];
				}
				// بعضی پیاده‌سازی‌ها کل بلوک را در content_block_start می‌دهند
				if ( isset( $json['content_block']['text'] ) ) {
					return (string) $json['content_block']['text'];
				}
				return '';

			case 'openai':
			default:
				if ( isset( $json['choices'][0]['delta']['content'] ) ) {
					return (string) $json['choices'][0]['delta']['content'];
				}
				if ( isset( $json['choices'][0]['message']['content'] ) ) {
					return (string) $json['choices'][0]['message']['content'];
				}
				return '';
		}
	}

	/**
	 * آیا این خط پایان جریان است؟
	 *
	 * OpenAI با «data: [DONE]» تمام می‌کند. جمینای و آنتروپیک نه — آن‌ها
	 * با بستن اتصال تمام می‌شوند، پس این تابع فقط برای OpenAI مقدار
	 * واقعی برمی‌گرداند.
	 *
	 * @param array  $opts
	 * @param string $line
	 * @return bool
	 */
	public static function stream_done( array $opts, $line ) {
		$api = self::current( $opts );

		if ( 'anthropic' === $api['api'] ) {
			return ( false !== strpos( (string) $line, 'message_stop' ) );
		}
		if ( 'gemini' === $api['api'] ) {
			// جمینای [DONE] نمی‌فرستد؛ پایان با finishReason مشخص می‌شود
			return ( false !== strpos( (string) $line, '"finishReason"' ) );
		}
		return ( '[DONE]' === trim( (string) $line ) );
	}

	/* =========================================================
	 * ابزارها
	 * =======================================================*/

	/**
	 * فهرست مدل‌های پیشنهادی برای سرویس فعال.
	 *
	 * @param array $opts
	 * @return array
	 */
	public static function models_for( array $opts ) {
		$prov    = self::current( $opts );
		$presets = self::presets();
		return isset( $presets[ $prov['key'] ]['models'] ) ? $presets[ $prov['key'] ]['models'] : array();
	}

	/**
	 * اکشن دکمهٔ «آزمایش اتصال» در تب اتصال.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_test_provider' );

		$opts = Pasokhban_Settings::instance()->get_options();
		$prov = self::current( $opts );
		$ps   = self::presets();

		$res = self::test_connection( $opts );

		if ( is_wp_error( $res ) ) {
			$notice = array(
				'ok'   => false,
				'text' => sprintf(
					/* translators: 1: provider label, 2: error */
					__( '%1$s: %2$s', 'pasokhban' ),
					isset( $ps[ $prov['key'] ]['label'] ) ? $ps[ $prov['key'] ]['label'] : $prov['key'],
					$res->get_error_message()
				),
			);
		} else {
			$notice = array(
				'ok'   => true,
				'text' => sprintf(
					/* translators: 1: provider label, 2: model */
					__( '%1$s با مدل %2$s پاسخ داد.', 'pasokhban' ),
					isset( $ps[ $prov['key'] ]['label'] ) ? $ps[ $prov['key'] ]['label'] : $prov['key'],
					$opts['model']
				),
			);
		}

		set_transient( 'pasokhban_provider_test', $notice, 2 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=pasokhban-settings#conn' ) );
		exit;
	}

	/**
	 * بررسی سریع اتصال — برای دکمهٔ «آزمایش» در تنظیمات.
	 *
	 * @param array $opts
	 * @return true|WP_Error
	 */
	public static function test_connection( array $opts ) {
		$req = self::request(
			array( array( 'role' => 'user', 'content' => 'ping' ) ),
			$opts,
			false
		);
		if ( is_wp_error( $req ) ) {
			return $req;
		}

		$response = wp_remote_post( $req['url'], array(
			'timeout' => 30,
			'headers' => $req['headers'],
			'body'    => $req['body'],
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pasokhban_remote', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$msg = is_array( $data ) ? self::parse_error( $opts, $data ) : '';
			if ( '' === $msg ) {
				$msg = sprintf(
					/* translators: 1: HTTP code, 2: body */
					__( 'خطای HTTP %1$s: %2$s', 'pasokhban' ),
					$code,
					mb_substr( $raw, 0, 160 )
				);
			}
			return new WP_Error( 'pasokhban_provider', $msg );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pasokhban_badjson', __( 'پاسخ سرویس JSON نبود.', 'pasokhban' ) );
		}

		$text = self::parse( $opts, $data );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'pasokhban_empty', __( 'پاسخ خالی بود.', 'pasokhban' ) );
		}

		return true;
	}
}
