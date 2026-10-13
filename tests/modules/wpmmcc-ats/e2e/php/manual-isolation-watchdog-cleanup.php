<?php
/**
 * Remove the manual-isolation watchdog mu-plugin and its log (test-only).
 *
 * Runs inside WP via wp-cli eval-file.
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) && WPMU_PLUGIN_DIR ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
$target = $mu_dir . '/wptsall-manual-isolation-watchdog.php';

if ( file_exists( $target ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read__file_put_contents
	wp_delete_file( $target );
	echo "watchdog removed: {$target}\n";
} else {
	echo "watchdog not present: {$target}\n";
}

delete_option( 'wptsall_mi_http_attempts' );
echo "attempt log cleared\n";
