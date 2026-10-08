<?php
/**
 * تیم پشتیبانی — پاسخ‌بان.
 *
 * تا ۲.۲ اینباکس فقط با `manage_options` باز می‌شد، یعنی هر کسی که باید
 * پاسخ می‌داد دسترسی کامل مدیر سایت را داشت. اینجا یک نقش اختصاصی
 * اضافه می‌شود تا اپراتورها فقط به چت دسترسی داشته باشند.
 *
 * تصمیم طراحی: اپراتورها کاربر وردپرس‌اند، نه ردیف در یک جدول اختصاصی.
 * ساختن سیستم کاربری موازی یعنی مدیریت رمز عبور، بازنشانی، ایمیل تأیید
 * و یک سطح حملهٔ تازه — برای چیزی که وردپرس همین حالا دارد.
 *
 * @package Pasokhban
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس تیم.
 */
final class Pasokhban_Team {

	const ROLE = 'pasokhban_agent';
	const CAP  = 'pasokhban_reply';

	/** کلید گزینهٔ «چه کسانی آنلاین‌اند». */
	const ONLINE_OPT = 'pasokhban_agents_online';

	/** ثانیه‌هایی که یک اپراتور بعد از آخرین ضربان هنوز آنلاین حساب می‌شود. */
	const ONLINE_TTL = 45;

	/** کلید گزینهٔ نوبت واگذاری خودکار. */
	const RR_OPT = 'pasokhban_rr_index';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'pasokhban/v1', '/admin/assign', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_assign' ),
			'permission_callback' => array( __CLASS__, 'can_access' ),
		) );
		register_rest_route( 'pasokhban/v1', '/admin/agents', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_agents' ),
			'permission_callback' => array( __CLASS__, 'can_access' ),
		) );
	}

	/* =========================================================
	 * نقش و دسترسی
	 * =======================================================*/

	/**
	 * ساخت نقش اپراتور. روی فعال‌سازی پلاگین صدا زده می‌شود.
	 */
	public static function install() {
		if ( ! function_exists( 'add_role' ) ) {
			return;
		}
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role(
				self::ROLE,
				__( 'اپراتور پاسخ‌بان', 'pasokhban' ),
				array(
					'read'          => true,
					self::CAP       => true,
					'upload_files'  => true,
				)
			);
		}

		// مدیر سایت همیشه باید بتواند، حتی اگر کسی نقش را دستکاری کرده باشد.
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
	}

	/**
	 * حذف نقش. فقط از uninstall.php صدا زده می‌شود.
	 */
	public static function uninstall() {
		delete_option( self::ONLINE_OPT );
		delete_option( self::RR_OPT );
		if ( function_exists( 'remove_role' ) ) {
			remove_role( self::ROLE );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( self::CAP );
		}
	}

	/**
	 * آیا کاربر جاری به اینباکس دسترسی دارد؟
	 *
	 * @return bool
	 */
	public static function can_access() {
		return current_user_can( 'manage_options' ) || current_user_can( self::CAP );
	}

	/**
	 * آیا کاربر جاری مدیر است (نه فقط اپراتور)؟
	 *
	 * کارهایی که فقط مدیر باید بتواند: حذف مکالمه، تنظیمات، مخاطبین،
	 * آمار، ساخت ایندکس.
	 *
	 * @return bool
	 */
	public static function is_admin() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return int شناسهٔ کاربر جاری، یا ۰
	 */
	public static function current_id() {
		$u = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		return ( $u && ! empty( $u->ID ) ) ? (int) $u->ID : 0;
	}

	/**
	 * @return string
	 */
	public static function current_name() {
		$u = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		if ( ! $u || empty( $u->ID ) ) {
			return '';
		}
		return (string) ( ! empty( $u->display_name ) ? $u->display_name : $u->user_login );
	}

	/**
	 * فهرست همهٔ اپراتورها (کاربران وردپرس با قابلیت پاسخ‌گویی).
	 *
	 * @return array<int, array{id:int, name:string, online:bool, admin:bool}>
	 */
	public static function agents() {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = get_users( array(
			'capability' => array( self::CAP ),
			'fields'     => array( 'ID', 'display_name', 'user_login' ),
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		) );

		$online = self::online_ids();
		$out    = array();

		foreach ( (array) $users as $u ) {
			$id   = (int) ( isset( $u->ID ) ? $u->ID : 0 );
			if ( ! $id ) {
				continue;
			}
			$name = ! empty( $u->display_name ) ? (string) $u->display_name : (string) $u->user_login;
			$out[] = array(
				'id'     => $id,
				'name'   => $name,
				'online' => isset( $online[ $id ] ),
				'admin'  => false,
			);
		}

		return $out;
	}

	/**
	 * نام یک کاربر.
	 *
	 * @param int $user_id
	 * @return string
	 */
	public static function agent_name( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return '';
		}
		if ( ! function_exists( 'get_userdata' ) ) {
			return '';
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return '';
		}
		return (string) ( ! empty( $u->display_name ) ? $u->display_name : $u->user_login );
	}

	/* =========================================================
	 * حضور (presence)
	 * =======================================================*/

	/**
	 * ثبت ضربان قلب کاربر جاری.
	 *
	 * چرا در یک گزینه و نه transient به ازای هر کاربر: transientها را
	 * نمی‌شود به‌صورت قابل‌حمل شمارش کرد، پس «چه کسانی آنلاین‌اند» قابل
	 * فهرست‌کردن نبود. یک آرایهٔ کوچک در گزینه، با هرس در هر خواندن.
	 */
	public static function heartbeat() {
		$id = self::current_id();
		if ( $id < 1 ) {
			return array();
		}

		$map = self::online_map();
		$now = time();

		// هرس: کسانی که از TTL گذشته‌اند حذف می‌شوند تا آرایه بزرگ نشود
		foreach ( $map as $uid => $ts ) {
			if ( $now - (int) $ts > self::ONLINE_TTL ) {
				unset( $map[ $uid ] );
			}
		}

		$map[ $id ] = $now;
		update_option( self::ONLINE_OPT, $map );

		return $map;
	}

	/**
	 * @return array<int, int> user_id => last_seen
	 */
	private static function online_map() {
		$map = get_option( self::ONLINE_OPT, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$out = array();
		foreach ( $map as $uid => $ts ) {
			$uid = (int) $uid;
			$ts  = (int) $ts;
			if ( $uid > 0 && $ts > 0 ) {
				$out[ $uid ] = $ts;
			}
		}
		return $out;
	}

	/**
	 * شناسهٔ اپراتورهای آنلاین (هرس‌شده).
	 *
	 * @return array<int, int>
	 */
	public static function online_ids() {
		$now = time();
		$out = array();
		foreach ( self::online_map() as $uid => $ts ) {
			if ( $now - $ts <= self::ONLINE_TTL ) {
				$out[ $uid ] = $ts;
			}
		}
		return $out;
	}

	/**
	 * آیا هیچ اپراتوری آنلاین است؟
	 *
	 * @return bool
	 */
	public static function anyone_online() {
		$ids = self::online_ids();
		return ! empty( $ids );
	}

	/* =========================================================
	 * واگذاری
	 * =======================================================*/

	/**
	 * واگذاری خودکار به نوبت (round-robin) بین اپراتورهای آنلاین.
	 *
	 * اگر هیچ‌کس آنلاین نباشد، به اولین اپراتور ثبت‌شده می‌دهد تا
	 * مکالمه بی‌صاحب نماند.
	 *
	 * @param int $session_id
	 * @return int شناسهٔ اپراتور واگذارشده، یا ۰
	 */
	public static function auto_assign( $session_id ) {
		$opts = Pasokhban_Settings::instance()->get_options();
		if ( empty( $opts['team_auto_assign'] ) ) {
			return 0;
		}

		$session = Pasokhban_DB::get_session( $session_id );
		if ( ! $session ) {
			return 0;
		}

		// قبلاً واگذار شده؟ دست نزن.
		if ( ! empty( $session->assigned_to ) && (int) $session->assigned_to > 0 ) {
			return (int) $session->assigned_to;
		}

		$all    = self::agents();
		$online = self::online_ids();

		$pool = array();
		foreach ( $all as $a ) {
			if ( isset( $online[ $a['id'] ] ) ) {
				$pool[] = $a['id'];
			}
		}
		if ( empty( $pool ) ) {
			foreach ( $all as $a ) {
				$pool[] = $a['id'];
			}
		}
		if ( empty( $pool ) ) {
			return 0;
		}

		// نوبت بعدی — با پیمانه بر طول استخر، تا با تغییر تعداد اپراتورها
		// هم درست بچرخد و هرگز از محدوده بیرون نزند.
		$i   = (int) get_option( self::RR_OPT, 0 );
		$pick = $pool[ $i % count( $pool ) ];
		update_option( self::RR_OPT, ( $i + 1 ) % 1000 );

		Pasokhban_DB::assign_session( (int) $session_id, $pick );

		return (int) $pick;
	}

	/**
	 * واگذاری دستی.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_assign( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$to      = (int) $request->get_param( 'user_id' );
		$session = Pasokhban_DB::get_session( $id );

		if ( ! $session ) {
			return new WP_Error(
				'pasokhban_notfound',
				__( 'مکالمه پیدا نشد.', 'pasokhban' ),
				array( 'status' => 404 )
			);
		}

		if ( ! self::can_touch( $session ) ) {
			return new WP_Error(
				'pasokhban_forbidden',
				__( 'این مکالمه به اپراتور دیگری واگذار شده است.', 'pasokhban' ),
				array( 'status' => 403 )
			);
		}

		// واگذاری به کاربر نامعتبر قبول نمی‌شود؛ وگرنه مکالمه به یک
		// شناسهٔ بی‌صاحب می‌چسبید و هیچ‌کس نمی‌دیدش.
		if ( $to > 0 ) {
			$valid = false;
			foreach ( self::agents() as $a ) {
				if ( $a['id'] === $to ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				return new WP_Error(
					'pasokhban_badagent',
					__( 'این کاربر اپراتور پاسخ‌بان نیست.', 'pasokhban' ),
					array( 'status' => 400 )
				);
			}
		}

		Pasokhban_DB::assign_session( $id, $to );

		$who = $to > 0 ? self::agent_name( $to ) : '';
		Pasokhban_DB::add_message(
			$id,
			'system',
			$to > 0
				/* translators: %s: agent name */
				? sprintf( __( 'مکالمه به %s واگذار شد.', 'pasokhban' ), $who )
				: __( 'واگذاری مکالمه لغو شد.', 'pasokhban' ),
			array( 'note' => 'assign', 'assigned_to' => $to )
		);

		return rest_ensure_response( array(
			'ok'          => true,
			'assigned_to' => $to,
			'assignedName' => $who,
		) );
	}

	/**
	 * آیا کاربر جاری اجازهٔ دست‌زدن به این مکالمه را دارد؟
	 *
	 * مدیر همیشه. اپراتور فقط اگر مکالمه بی‌صاحب باشد یا به خودش
	 * واگذار شده باشد — آن هم فقط وقتی محدودسازی روشن باشد.
	 *
	 * @param object $session
	 * @return bool
	 */
	public static function can_touch( $session ) {
		if ( self::is_admin() ) {
			return true;
		}

		$opts = Pasokhban_Settings::instance()->get_options();
		if ( empty( $opts['team_restrict'] ) ) {
			return true;
		}

		$owner = isset( $session->assigned_to ) ? (int) $session->assigned_to : 0;
		return 0 === $owner || $owner === self::current_id();
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_agents( $request ) {
		return rest_ensure_response( array(
			'ok'     => true,
			'agents' => self::agents(),
			'me'     => self::current_id(),
		) );
	}

	/**
	 * فیلتر لیست نشست‌ها بر اساس دسترسی کاربر جاری.
	 *
	 * @param array $args آرگومان‌های list_sessions
	 * @return array
	 */
	public static function scope_list_args( array $args ) {
		if ( self::is_admin() ) {
			return $args;
		}

		$opts = Pasokhban_Settings::instance()->get_options();
		if ( ! empty( $opts['team_restrict'] ) ) {
			// اپراتور محدود: فقط مکالمات خودش و بی‌صاحب‌ها
			$args['assigned'] = 'me';
			$args['agent_id'] = self::current_id();
		}

		return $args;
	}
}
