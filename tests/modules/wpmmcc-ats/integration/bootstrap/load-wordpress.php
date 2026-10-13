<?php
/**
 * Shared WordPress bootstrap for standalone integration flow scripts.
 *
 * Resolves the site root via env vars (WPTSALL_WP_ROOT / WP_SITE_PATH / WP_ROOT)
 * and common local/Docker paths, then loads wp-load.php once.
 *
 * Usage (at top of a flow script):
 *   if ( ! defined( 'ABSPATH' ) ) {
 *       require_once dirname( __DIR__ ) . '/bootstrap/load-wordpress.php';
 *   }
 *
 * @package WPTSALL\Tests\Integration
 */

if ( defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Detect WordPress root for integration flows.
 *
 * @return string Absolute path with trailing slash.
 */
function wptsall_integration_detect_wp_root() {
	$candidates = array(
		getenv( 'WPTSALL_WP_ROOT' ),
		getenv( 'WP_SITE_PATH' ),
		getenv( 'WP_ROOT' ),
		'/var/www/html',
		'/var/www/wordpress',
		'/usr/local/var/www',
	);

	foreach ( $candidates as $candidate ) {
		if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
			continue;
		}
		$normalized = rtrim( trim( $candidate ), '/\\' ) . '/';
		if ( is_readable( $normalized . 'wp-load.php' ) ) {
			return $normalized;
		}
	}

	fwrite( STDERR, "WPTSALL integration: WordPress root not found. Set WPTSALL_WP_ROOT.\n" );
	exit( 1 );
}

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

// Sensible defaults for CLI / container runs before WP boots.
if ( empty( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = 'localhost';
}
if ( empty( $_SERVER['REQUEST_URI'] ) ) {
	$_SERVER['REQUEST_URI'] = '/';
}

$wptsall_wp_root = wptsall_integration_detect_wp_root();
if ( ! defined( 'WP_SITE_PATH' ) ) {
	define( 'WP_SITE_PATH', $wptsall_wp_root );
}

// Integration flows run over plain HTTP in Docker; client transport crypto is
// enforced for non-SSL. Allow plain HTTP only while WPTSALL_TEST_RUNNER or
// WPTSALL_INTEGRATION_RUNNER is set by the parent runner, or when running a
// flow script directly under test load.
if ( ! defined( 'WPTSALL_INTEGRATION_FLOW' ) ) {
	define( 'WPTSALL_INTEGRATION_FLOW', true );
}

require_once $wptsall_wp_root . 'wp-load.php';

// After WP loads: relax client transport encryption for in-process REST tests.
// Production HTTPS behavior is unchanged (filter only returns false in test).
if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'wptsall_client_transport_encryption_required',
		static function ( $required ) {
			if ( defined( 'WPTSALL_INTEGRATION_RUNNER' ) || defined( 'WPTSALL_TEST_RUNNER' ) || defined( 'WPTSALL_INTEGRATION_FLOW' ) ) {
				return false;
			}
			return $required;
		},
		1
	);
}
