<?php
/**
 * License Simulator
 *
 * Provides dev license bypass and blocks external HTTP requests during
 * integration tests. This file MUST be included BEFORE wp-load.php so
 * that WPTSALL_DEV_LICENSE is defined before WordPress and the plugin
 * boot up.
 *
 * Usage in run.php (before require_once $wp_load_path):
 *   require_once __DIR__ . '/simulators/client-token-simulator.php';
 *   require_once $wp_load_path;
 *   wptsall_test_register_http_interceptor();
 *
 * @package WPTSALL
 * @since 1.1.0
 */

// Must be included before wp-load.php.
// WPTSALL_DEV_LICENSE triggers Client_Token_Service::is_dev_domain() bypass so
// that wptsall_is_pro_enabled() returns true without a real license key.
// See: wptsall/includes/client-pairing/services/class-client-token-service.php
if ( ! defined( 'WPTSALL_DEV_LICENSE' ) ) {
	define( 'WPTSALL_DEV_LICENSE', true );
}

/**
 * Register the pre_http_request filter that blocks external HTTP calls.
 *
 * Must be called AFTER wp-load.php because add_filter() requires WordPress
 * to be loaded. Requests to 127.0.0.1 and localhost are allowed through so
 * that the test suite can still reach the local WP REST API, mock-translate-api
 * (:9090), and the Rust server (:8787).
 *
 * @return void
 */
function wptsall_test_register_http_interceptor() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
				// Allow all local traffic and current WP site host (wp-cron/self calls).
				$allowed_hosts = array( '127.0.0.1', 'localhost', '::1' );
				if ( function_exists( 'home_url' ) ) {
					$home_host = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
					if ( '' !== $home_host ) {
						$allowed_hosts[] = $home_host;
					}
				}
				if ( function_exists( 'site_url' ) ) {
					$site_host = strtolower( (string) parse_url( site_url(), PHP_URL_HOST ) );
					if ( '' !== $site_host ) {
						$allowed_hosts[] = $site_host;
					}
				}

				if ( '' === $host || in_array( $host, array_unique( $allowed_hosts ), true ) ) {
					return $preempt;
				}

				fwrite( STDERR, "[TEST] Blocked external HTTP: {$url}\n" );
			return new WP_Error( 'test_blocked', "External HTTP blocked in tests: {$url}" );
		},
		1,
		3
	);
}
