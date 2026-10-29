<?php
/**
 * پاک‌سازی داده‌ها هنگام حذف پلاگین گپینه.
 *
 * @package Gapineh
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// تنظیم «حذف داده‌ها هنگام حذف پلاگین» — تنها وقتی روشن باشد داده‌ها پاک می‌شوند.
// این تنظیم در نسخۀ ۱.۰.۰ به‌طور پیش‌فرض خاموش است؛ پس حذف افزونه فاجعه نمی‌سازد.
$opts = get_option( 'gapineh_options', array() );
$delete_data = ! empty( $opts['uninstall_delete_data'] );

$upload_dirs = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();

// ── پاک‌سازی بی‌ضرر: همیشه انجام می‌شود (چیزی از دادهٔ کاربر نمی‌برد) ──
// اعلان‌های موقتِ پیشخوان و برچسب صفحه‌ها، تا پس از حذف افزونه ردی از منو نماند.
delete_transient( 'gapineh_bale_notice' );

if ( $delete_data ) {
	// جداول دیتابیس: مکالمات، پیام‌ها، مخاطبین غیره
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'gapineh_bale_links' ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'gapineh_chunks' );      // phpcs:ignore WordPress.DB
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'gapineh_feedback' );    // phpcs:ignore WordPress.DB
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'gapineh_messages' );    // phpcs:ignore WordPress.DB
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'gapineh_sessions' );    // phpcs:ignore WordPress.DB

	// همهٔ گزینه‌ها و وضعیت ایندکس
	delete_option( 'gapineh_options' );
	delete_option( 'gapineh_db_version' );
	delete_option( 'gapineh_db_error' );
	delete_option( 'gapineh_rag_state' );
	delete_option( 'gapineh_rag_dirty' );
	delete_option( 'gapineh_rag_cursor' );
	delete_option( 'gapineh_agents_online' );
	delete_option( 'gapineh_rr_index' );
	delete_option( 'gapineh_updir_hard' );

	// نقش اپراتور و توانایی‌اش
	if ( function_exists( 'remove_role' ) ) {
		remove_role( 'gapineh_agent' );
	}
	$ety_admin = get_role( 'administrator' );
	if ( $ety_admin ) {
		$ety_admin->remove_cap( 'gapineh_reply' );
	}

	// فایل‌های آپلودشدهٔ بازدیدکننده‌ها
	$ety_updir = trailingslashit( isset( $upload_dirs['basedir'] ) ? $upload_dirs['basedir'] : WP_CONTENT_DIR . '/uploads' ) . 'gapineh';
	if ( is_dir( $ety_updir ) ) {
		foreach ( (array) glob( $ety_updir . '/*' ) as $ety_f ) {
			if ( is_file( $ety_f ) ) {
				@unlink( $ety_f );
			}
		}
		@rmdir( $ety_updir );
	}

	// transientهای باقی‌مانده (اعلان‌ها، کش پرس‌وجوها، rate-limit)
	$wpdb->query( // phpcs:ignore WordPress.DB
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_gapineh\\_%' OR option_name LIKE '\\_transient\\_timeout\\_gapineh\\_%'"
	);
} else {
	// داده‌ها حفظ است: جداول، گزینه‌ها، فایل‌ها و نقش‌ها دست‌نخورده می‌مانند،
	// تا اگر افزونه دوباره نصب شد همه‌چیز از همان‌جا ادامه کند.
	// فقط transientهای اطلاعیه پاک می‌شوند (دادهٔ کاربر نیستند).
	$wpdb->query( // phpcs:ignore WordPress.DB
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_gapineh\\_notice%' OR option_name LIKE '\\_transient\\_timeout\\_gapineh\\_notice%'"
	);
}
