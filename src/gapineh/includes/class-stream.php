<?php
/**
 * Compatibility endpoint for clients with a cached streaming setting.
 *
 * The former WordPress HTTP streaming implementation buffered the response to
 * a file and then retried the model on failure. Until true incremental
 * streaming is supported, reject before storing a message: older clients fall
 * back to /live/send without creating duplicates or making two model calls.
 *
 * @package Gapineh
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gapineh_Stream {
	/** @var Gapineh_Stream|null */
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
			'gapineh/v1',
			'/live/stream',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_stream' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** @return WP_Error */
	public function handle_stream( $request ) {
		return new WP_Error( 'gapineh_stream_unavailable', __( 'پاسخ جریان‌دار فعلاً در دسترس نیست.', 'gapineh' ), array( 'status' => 503 ) );
	}
}
