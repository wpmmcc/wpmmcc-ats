<?php
/**
 * Install the manual-isolation watchdog mu-plugin (P0-EV-01, test-only).
 *
 * Runs inside WP via wp-cli eval-file. Copies the mu-plugin body into
 * wp-content/mu-plugins and resets the attempt log.
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$source = __DIR__ . '/manual-isolation-watchdog.mu.php';
if ( ! is_readable( $source ) ) {
	echo "watchdog source not readable: {$source}\n";
	exit( 1 );
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	echo "wp_mkdir_p unavailable\n";
	exit( 1 );
}

$mu_dir = ( defined( 'WPMU_PLUGIN_DIR' ) && WPMU_PLUGIN_DIR ) ? WPMU_PLUGIN_DIR : ( WPMU_PLUGIN_DIR ?: WP_CONTENT_DIR . '/mu-plugins' );
if ( ! wp_mkdir_p( $mu_dir ) ) {
	echo "cannot create mu-plugins dir: {$mu_dir}\n";
	exit( 1 );
}

$target = $mu_dir . '/wptsall-manual-isolation-watchdog.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read__file_get_contents
$code = (string) file_get_contents( $source );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read__file_put_contents
if ( false === file_put_contents( $target, $code ) ) {
	echo "cannot write mu-plugin: {$target}\n";
	exit( 1 );
}

delete_option( 'wptsall_mi_http_attempts' );
echo "watchdog installed: {$target}\n";
