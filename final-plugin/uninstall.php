<?php
/**
 * پاک‌سازی داده‌ها هنگام حذف پلاگین پاسخ‌بان.
 *
 * @package Pasokhban
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$upload_dirs = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();

// جداول گفتگوی آنلاین، ایندکس برداری و نگاشت بله
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pasokhban_bale_links' ); // phpcs:ignore WordPress.DB
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pasokhban_chunks' );    // phpcs:ignore WordPress.DB
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pasokhban_feedback' );  // phpcs:ignore WordPress.DB
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pasokhban_messages' );  // phpcs:ignore WordPress.DB
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pasokhban_sessions' );  // phpcs:ignore WordPress.DB

// گزینه‌ها
delete_option( 'pasokhban_options' );
delete_option( 'pasokhban_db_version' );
delete_option( 'pasokhban_db_error' );

// وضعیت ایندکس برداری
delete_option( 'pasokhban_rag_state' );
delete_option( 'pasokhban_rag_dirty' );
delete_option( 'pasokhban_rag_cursor' );

// اعلان‌های موقت بله
delete_transient( 'pasokhban_bale_notice' );

// نقش و گزینه‌های تیم
delete_option( 'pasokhban_agents_online' );
delete_option( 'pasokhban_rr_index' );
if ( function_exists( 'remove_role' ) ) {
	remove_role( 'pasokhban_agent' );
}
$ety_admin = get_role( 'administrator' );
if ( $ety_admin ) {
	$ety_admin->remove_cap( 'pasokhban_reply' );
}

// فایل‌های آپلودشدهٔ بازدیدکننده‌ها
$ety_updir = trailingslashit( isset( $upload_dirs['basedir'] ) ? $upload_dirs['basedir'] : WP_CONTENT_DIR . '/uploads' ) . 'pasokhban';
if ( is_dir( $ety_updir ) ) {
	foreach ( (array) glob( $ety_updir . '/*' ) as $ety_f ) {
		if ( is_file( $ety_f ) ) {
			@unlink( $ety_f );
		}
	}
	@rmdir( $ety_updir );
}
delete_option( 'pasokhban_updir_hard' );

// transientهای باقی‌مانده (اعلان‌ها، کش پرس‌وجوها، rate-limit و اعلان)
$wpdb->query( // phpcs:ignore WordPress.DB
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pasokhban\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pasokhban\\_%'"
);
