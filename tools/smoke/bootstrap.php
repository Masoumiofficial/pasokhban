<?php
/**
 * دودآزمای افزونه در PHP واقعی (wasm).
 *
 * بارگذاری افزونه، قلاب‌های پیشخوان، رندر صفحهٔ تنظیمات/راهنما و (در حالت
 * شبیه‌سازی‌شدهٔ لایسنس) ویجت و شورت‌کد را اجرا می‌کند تا خطای واقعی PHP 8.x
 * پیدا شود — همان چیزی که روی هاست به «خطای مرگ‌بار» تبدیل می‌شود.
 *
 * اجرا: NODE_PATH=… node tools/smoke-php.mjs <درخت افزونه>   (SIM_PRO=1 برای حالت کامل)
 */
error_reporting( E_ALL );
ini_set( 'display_errors', '0' );

$GLOBALS['__errs'] = array();
$GLOBALS['__hooks'] = array();
$GLOBALS['__opts'] = array();
$GLOBALS['__trans'] = array();

set_error_handler(
	function ( $no, $str, $file, $line ) {
		$GLOBALS['__errs'][] = sprintf( '[%s] %s — %s:%d', $no, $str, basename( $file ), $line );
		return true;
	}
);

echo "run.php شروع شد\n";
function plugin_basename( $f = '' ) { return 'gapineh/gapineh.php'; }
if ( file_exists( '/sim-pro' ) ) { define( 'GAPINEH_PRO', true ); }
define( 'ABSPATH', '/var/www/html/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', true );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}

class wpdb {
	public $prefix = 'wp_';
	public $usermeta = 'wp_usermeta';
	public $options = 'wp_options';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $users = 'wp_users';
	public $comments = 'wp_comments';
	public $commentmeta = 'wp_commentmeta';
	public $last_error = '';
	public $insert_id = 1;
	public $rows_affected = 1;
	public $charset_collate = 'DEFAULT CHARSET=utf8mb4';
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $q, ...$a ) { return $q; }
	public function query( $q ) { return 1; }
	public function get_var( $q = null ) { return 0; }
	public function get_row( $q = null, $o = OBJECT ) { return null; }
	public function get_results( $q = null, $o = OBJECT ) { return array(); }
	public function get_col( $q = null ) { return array(); }
	public function insert( $t, $d, $f = null ) { return 1; }
	public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
	public function delete( $t, $w, $f = null ) { return 1; }
	public function esc_like( $s ) { return $s; }
	public function tables( $s = 'all' ) { return array(); }
}
$GLOBALS['wpdb'] = new wpdb();

// ── خود وردپرس ───────────────────────────────────────────────────────────────
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['__hooks'][ $h ][ $p ][] = $cb; return true; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['__hooks'][ $h ][ $p ][] = $cb; return true; }
function remove_action( $h, $cb, $p = 10 ) { return true; }
function do_action( $h, ...$args ) {
	if ( empty( $GLOBALS['__hooks'][ $h ] ) ) { return; }
	ksort( $GLOBALS['__hooks'][ $h ] );
	foreach ( $GLOBALS['__hooks'][ $h ] as $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_callable( $cb ) ) { call_user_func_array( $cb, $args ); }
		}
	}
}
function apply_filters( $h, $v, ...$a ) {
	if ( empty( $GLOBALS['__hooks'][ $h ] ) ) { return $v; }
	foreach ( $GLOBALS['__hooks'][ $h ] as $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_callable( $cb ) ) { $v = call_user_func( $cb, $v ); }
		}
	}
	return $v;
}
function has_action( $h, $cb = false ) { return isset( $GLOBALS['__hooks'][ $h ] ); }
function did_action( $h ) { return 0; }

function __( $s, $d = null ) { return $s; }
function _e( $s, $d = null ) { echo $s; }
function _x( $s, $c = null, $d = null ) { return $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_textarea( $s ) { return (string) $s; }
function esc_url( $s, $p = null, $c = null ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_js( $s ) { return (string) $s; }
function wp_kses_post( $s ) { return $s; }
function sanitize_text_field( $s ) { return (string) $s; }
function sanitize_textarea_field( $s ) { return (string) $s; }
function sanitize_key( $s ) { return $s; }
function sanitize_title( $s ) { return $s; }
function sanitize_email( $s ) { return $s; }
function sanitize_hex_color( $s ) { return $s; }
function sanitize_file_name( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_trim_words( $s, $n = 55, $m = null ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_args( $a, $d = array() ) {
	if ( is_object( $a ) ) { $a = get_object_vars( $a ); }
	if ( ! is_array( $a ) ) { $a = array(); }
	return array_merge( $d, $a );
}
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_unslash( $v ) { return $v; }
function stripslashes_deep( $v ) { return $v; }
function wp_slash( $v ) { return $v; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/gapineh/'; }
function plugins_url( $p = '', $f = '' ) { return 'https://example.test/wp-content/plugins/gapineh/' . ltrim( $p, '/' ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( $p, '/' ); }
function home_url( $p = '' ) { return 'https://example.test/' . ltrim( $p, '/' ); }
function site_url( $p = '' ) { return 'https://example.test/' . ltrim( $p, '/' ); }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); }
function includes_url( $p = '' ) { return 'https://example.test/wp-includes/' . ltrim( $p, '/' ); }
function content_url( $p = '' ) { return 'https://example.test/wp-content/' . ltrim( $p, '/' ); }
function get_bloginfo( $k = '' ) { return 'گپینه'; }
function get_locale() { return 'fa_IR'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function add_option( $k, $v = '', $d = '', $a = null ) { if ( ! isset( $GLOBALS['__opts'][ $k ] ) ) { $GLOBALS['__opts'][ $k ] = $v; } return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['__trans'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['__trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__trans'][ $k ] ); return true; }
function is_admin() { return true; }
function is_network_admin() { return false; }
function is_user_logged_in() { return true; }
function current_user_can( $c ) { return true; }
function wp_get_current_user() { return (object) array( 'ID' => 1, 'user_login' => 'admin', 'display_name' => 'مدیر', 'user_email' => 'a@b.c', 'roles' => array( 'administrator' ) ); }
function get_current_user_id() { return 1; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'مدیر' ); }
function get_users( $a = array() ) { return array(); }
function get_current_screen() { return (object) array( 'id' => 'plugins', 'base' => 'plugins', 'post_type' => '', 'action' => '' ); }
function add_query_arg( ...$a ) { return 'https://example.test/?x=1'; }
function remove_query_arg( $k, $u = '' ) { return $u; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_nonce_field( ...$a ) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return $u; }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }
function wp_verify_nonce( $n, $a = -1 ) { return 1; }
function check_admin_referer( ...$a ) { return true; }
function check_ajax_referer( ...$a ) { return true; }
function wp_safe_redirect( $u, $s = 302 ) { return true; }
function wp_redirect( $u, $s = 302 ) { return true; }
function wp_die( $m = '', $t = '', $a = array() ) { throw new RuntimeException( 'wp_die: ' . ( is_string( $m ) ? $m : 'obj' ) ); }
function add_menu_page( ...$a ) { $GLOBALS['__menus'] = ( $GLOBALS['__menus'] ?? 0 ) + 1; return 'toplevel_page_gapineh-inbox'; }
function add_submenu_page( ...$a ) { $GLOBALS['__menus_sub'] = ( $GLOBALS['__menus_sub'] ?? 0 ) + 1; return 'gapineh_page_x'; }
function remove_menu_page( $s ) { return true; }
function register_setting( ...$a ) { return true; }
function add_settings_error( ...$a ) { return true; }
function settings_fields( $g ) { echo ''; }
function submit_button( ...$a ) { echo '<button>ذخیره</button>'; }
function add_settings_section( ...$a ) { return true; }
function add_settings_field( ...$a ) { return true; }
function add_shortcode( $t, $cb ) { $GLOBALS['__shortcodes'][ $t ] = $cb; }
function shortcode_atts( $d, $a, $t = '' ) { return array_merge( $d, (array) $a ); }
function wp_enqueue_script( ...$a ) { return true; }
function wp_enqueue_style( ...$a ) { return true; }
function wp_register_script( ...$a ) { return true; }
function wp_register_style( ...$a ) { return true; }
function wp_localize_script( ...$a ) { return true; }
function wp_add_inline_script( ...$a ) { return true; }
function wp_add_inline_style( ...$a ) { return true; }
function wp_script_add_data( ...$a ) { return true; }
function wp_style_add_data( ...$a ) { return true; }
function register_activation_hook( $f, $cb ) { $GLOBALS['__activate'] = $cb; }
function register_deactivation_hook( $f, $cb ) { $GLOBALS['__deactivate'] = $cb; }
function register_rest_route( ...$a ) { return true; }
function rest_ensure_response( $d ) { return $d; }
function rest_get_server() { return new class() { public function get_routes() { return array(); } }; }
function wp_next_scheduled( $h, $a = array() ) { return false; }
function wp_schedule_event( ...$a ) { return true; }
function wp_unschedule_event( ...$a ) { return true; }
function wp_clear_scheduled_hook( $h, $a = array() ) { return true; }
function wp_timezone() { return new DateTimeZone( 'Asia/Tehran' ); }
function wp_timezone_string() { return 'Asia/Tehran'; }
function wp_date( $f, $t = null, $z = null ) { return date( $f, $t ?: time() ); }
function current_time( $t = 'timestamp', $gmt = 0 ) { return 'mysql' === $t ? date( 'Y-m-d H:i:s' ) : ( 'timestamp' === $t ? time() : date( $t ) ); }
function date_i18n( $f, $t = null, $gmt = false ) { return date( $f, $t ?: time() ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function human_time_diff( $a, $b = null ) { return '۵ دقیقه'; }
function size_format( $b, $d = 0 ) { return round( $b / 1024 ) . ' KB'; }
function wp_mail( ...$a ) { return true; }
function wp_remote_get( $u, $a = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{}' ); }
function wp_remote_post( $u, $a = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{}' ); }
function wp_remote_request( $u, $a = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{}' ); }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_upload_dir( $t = null, $c = false ) { return array( 'path' => '/tmp/up', 'url' => 'https://example.test/up', 'subdir' => '', 'basedir' => '/tmp/up', 'baseurl' => 'https://example.test/up', 'error' => false ); }
function wp_mkdir_p( $d ) { return true; }
function wp_generate_password( $l = 12, $s = true, $e = false ) { return 'abcdefghijkl'; }
function wp_generate_uuid4() { return 'uuid-4'; }
function wp_check_filetype_and_ext( $f, $n, $m = null ) { return array( 'ext' => 'jpg', 'type' => 'image/jpeg', 'proper_filename' => false ); }
function wp_get_referer() { return ''; }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function wp_reset_postdata() {}
function wp_list_pluck( $l, $f ) { return array(); }
function get_posts( $a = array() ) { return array(); }
function get_post( $id = null ) { return null; }
function get_permalink( $p = 0 ) { return 'https://example.test/p/1'; }
function get_the_title( $p = 0 ) { return 'عنوان'; }
function get_the_excerpt( $p = 0 ) { return 'خلاصه'; }
function get_term( $id, $t = '' ) { return null; }
function get_role( $r ) { return null; }
function add_role( ...$a ) { return null; }
function is_page( $p = '' ) { return false; }
function is_singular( $t = '' ) { return false; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function checked( $a, $b = true, $e = true ) { $r = (string) $a === (string) $b ? ' checked' : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = (string) $a === (string) $b ? ' selected' : ''; if ( $e ) { echo $r; } return $r; }
function disabled( $a, $b = true, $e = true ) { return ''; }
function submit_button_stub() {}
function current_user_can_stub() {}
function is_ssl() { return true; }
function wp_is_mobile() { return false; }
function get_woocommerce_currency_symbol() { return 'تومان'; }
function wc_get_order( $id ) { return null; }
function get_privacy_policy_url() { return ''; }
function wp_get_attachment_url( $id ) { return ''; }
function wp_attachment_is_image( $id ) { return false; }
function wp_read_image_metadata( $f ) { return array(); }
function utf8_uri_encode( $s ) { return $s; }
function wp_salt( $s = 'auth' ) { return 'salt'; }
function wp_hash( $d ) { return md5( $d ); }
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '', $e = 0 ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_cache_flush() { return true; }
function do_shortcode( $c ) { return $c; }
function shortcode_exists( $t ) { return isset( $GLOBALS['__shortcodes'][ $t ] ); }
function convert_smilies( $t ) { return $t; }
function balanceTags( $t, $f = false ) { return $t; }
function make_clickable( $t ) { return $t; }
function antispambot( $e ) { return $e; }
function zeroise( $n, $t ) { return sprintf( '%0' . $t . 'd', $n ); }
function urlencode_deep( $v ) { return $v; }
function map_deep( $v, $cb ) { return $v; }
function maybe_serialize( $d ) { return $d; }
function maybe_unserialize( $d ) { return $d; }
function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return null; }
function get_terms( $a = array() ) { return array(); }
function wp_get_theme() { return (object) array( 'Name' => 'Theme' ); }
function wp_get_environment_type() { return 'production'; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function wp_installing() { return false; }
function wp_rand( $m = 0, $x = 0 ) { return random_int( 1, 100 ); }
function wp_json_file_decode( $f, $a = array() ) { return null; }
function wp_filesize( $f ) { return 0; }
function wp_handle_upload( $f, $o = array() ) { return array( 'file' => '/tmp/x.jpg', 'url' => 'https://example.test/x.jpg', 'type' => 'image/jpeg' ); }


function load_plugin_textdomain( $d = '', $a = false, $p = false ) { return true; }
function load_textdomain( $d, $f ) { return true; }
function unload_textdomain( $d ) { return true; }
function is_textdomain_loaded( $d ) { return true; }
function determine_locale() { return 'fa_IR'; }
function get_user_locale( $u = 0 ) { return 'fa_IR'; }
function translate( $s, $d = null ) { return $s; }
function dbDelta( $q ) { return array(); }
function maybe_create_table( ...$a ) { return true; }
function maybe_add_column( ...$a ) { return true; }
function wp_get_db_schema( ...$a ) { return ''; }
function size_format_stub() {}
function wp_using_ext_object_cache() { return false; }
function wp_suspend_cache_invalidation( $s = true ) { return true; }
function get_blog_prefix( $id = null ) { return 'wp_'; }
function is_multisite() { return false; }
function get_sites( $a = array() ) { return array(); }
function switch_to_blog( $id ) { return true; }
function restore_current_blog() { return true; }
function wp_cache_supports( $f ) { return false; }
function wp_get_upload_dir() { return wp_upload_dir(); }
function wp_tempnam( $f = '' ) { return '/tmp/tmpfile'; }
function wp_max_upload_size() { return 8388608; }
function wp_is_file_mod_allowed( $c ) { return true; }
function get_filesystem_method( $a = array(), $c = '', $w = false ) { return 'direct'; }
function request_filesystem_credentials( ...$a ) { return true; }
function WP_Filesystem( $a = false, $c = '', $w = false ) { $GLOBALS['wp_filesystem'] = new class() { public function put_contents( $f, $c ) { return true; } public function get_contents( $f ) { return ''; } public function delete( $f, $r = false, $t = false ) { return true; } public function exists( $f ) { return false; } public function mkdir( $p, $c = false, $ch = false ) { return true; } public function move( $a, $b, $o = false ) { return true; } public function copy( $a, $b, $o = false ) { return true; } public function chmod( $f, $m = false ) { return true; } }; return true; }
function media_handle_upload( ...$a ) { return 0; }
function wp_handle_sideload( ...$a ) { return array( 'file' => '/tmp/x.jpg', 'type' => 'image/jpeg' ); }
function wp_get_image_editor( $f ) { return new WP_Error( 'no_editor', 'no editor' ); }
function wp_send_json( $d, $c = 200 ) { throw new RuntimeException( 'wp_send_json' ); }
function wp_send_json_success( $d = null, $c = 200 ) { throw new RuntimeException( 'wp_send_json_success' ); }
function wp_send_json_error( $d = null, $c = 200 ) { throw new RuntimeException( 'wp_send_json_error' ); }
function status_header( $c ) {}
function nocache_headers() {}
function header_stub() {}
function get_gmt_from_date( $d ) { return $d; }
function get_date_from_gmt( $d, $f = 'Y-m-d H:i:s' ) { return $d; }
function wp_get_post_terms( ...$a ) { return array(); }
function wp_set_post_terms( ...$a ) { return true; }
function wc_get_orders( $a = array() ) { return array(); }
function wc_get_order_statuses() { return array(); }
function get_woocommerce_order_status() { return ''; }
function wc_price( $p ) { return (string) $p; }
function wp_get_current_user_caps() { return array(); }
function wp_get_session_token() { return 'tok'; }
function wp_hash_password( $p ) { return md5( $p ); }
function wp_check_password( $p, $h ) { return true; }
function wp_set_current_user( $id ) { return true; }
function wp_set_auth_cookie( ...$a ) { return true; }
function wp_setcookie( ...$a ) { return true; }
function wp_parse_auth_cookie( ...$a ) { return array(); }
function wp_validate_auth_cookie( ...$a ) { return 1; }
function get_avatar_url( $id, $a = array() ) { return 'https://example.test/a.png'; }
function get_avatar( ...$a ) { return ''; }
function wp_sanitize_redirect( $l ) { return $l; }
function wp_validate_redirect( $l, $f = '' ) { return $l; }
function wp_is_json_request() { return false; }
function wp_is_serving_rest_request() { return false; }
function rest_authorization_required_code() { return 401; }
function rest_do_request( $r ) { return null; }
function register_shutdown_function_stub() {}
function error_get_last_stub() {}

// ── بارگذاری افزونه ──────────────────────────────────────────────────────────
$main = ( defined( 'GAPINEH_SMOKE_MAIN' ) ? GAPINEH_SMOKE_MAIN : '/var/www/html/wp-content/plugins/gapineh/gapineh.php' );
try {
	require $main;
} catch ( Throwable $e ) {
	echo 'FATAL(require): ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	exit;
}

echo "── بارگذاری شد ──\n";

try {
	Gapineh::instance();
	echo "instance ok\n";
} catch ( Throwable $e ) {
	echo 'FATAL(instance): ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
}

foreach ( array( 'plugins_loaded', 'init', 'admin_init', 'admin_menu', 'admin_notices', 'admin_enqueue_scripts', 'wp_loaded' ) as $hook ) {
	try {
		do_action( $hook, 'toplevel_page_gapineh-inbox' );
		echo "hook $hook ok\n";
	} catch ( Throwable $e ) {
		echo "FATAL($hook): " . get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	}
}

// رندر صفحهٔ تنظیمات و راهنمای سریع
foreach ( array( 'render_page', 'render_guide' ) as $m ) {
	if ( ! method_exists( 'Gapineh_Settings', $m ) ) { continue; }
	try {
		ob_start();
		Gapineh_Settings::instance()->$m();
		$html = ob_get_clean();
		echo "render $m ok (" . strlen( $html ) . " بایت)\n";
	} catch ( Throwable $e ) {
		ob_end_clean();
		echo "FATAL($m): " . get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	}
}

// وضعیت لایسنس
try {
	if ( class_exists( 'Gapineh_License' ) ) {
		echo 'license state: ' . Gapineh_License::status() . ' | pro: ' . var_export( gapineh_is_pro(), true ) . "\n";
	}
} catch ( Throwable $e ) {
	echo 'FATAL(license): ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
}

// ویجت و شورت‌کد (فقط در حالت کامل)
if ( gapineh_is_pro() ) {
	foreach ( array( 'render_widget', 'render_shortcode' ) as $m ) {
		try {
			ob_start();
			$args = 'render_shortcode' === $m ? array() : null;
			Gapineh::instance()->$m( $args );
			$html = ob_get_clean();
			echo "render $m ok (" . strlen( $html ) . " بایت)\n";
		} catch ( Throwable $e ) {
			ob_end_clean();
			echo "FATAL($m): " . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
		}
	}
}

echo 'منوهای ثبت‌شده: ' . (int) ( $GLOBALS['__menus'] ?? 0 ) . ' | زیرمنو: ' . (int) ( $GLOBALS['__menus_sub'] ?? 0 ) . "\n";

echo "\n── warning/notice ها (" . count( $GLOBALS['__errs'] ) . ") ──\n";
foreach ( array_slice( $GLOBALS['__errs'], 0, 40 ) as $e ) { echo $e . "\n"; }
