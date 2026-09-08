<?php
/**
 * WPTSALL Log Module
 *
 * Log Module - Handles plugin development debugging and runtime logging.
 *
 * @package WPTSALL
 * @since 0.4.0
 */

namespace WPTSALL\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get log directory path.
 *
 * Logs are stored in the wp-content/uploads/wptsall-logs/ directory
 * to avoid Plugin Check scanning runtime files and prevent log loss during plugin updates.
 *
 * @return string Log directory path.
 */
function get_log_dir() {
	$upload_dir = wp_upload_dir();
	return $upload_dir['basedir'] . '/wptsall-logs';
}

/**
 * Initialize the log module.
 */
function init() {
	// Ensure log directory exists (located at wp-content/uploads/wptsall-logs/).
	$log_dir = get_log_dir();
	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}

	// Add .htaccess to protect the log directory.
	$htaccess = $log_dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess, 'Deny from all' );
	}

	// Add index.php to prevent directory browsing.
	$index = $log_dir . '/index.php';
	if ( ! file_exists( $index ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $index, '<?php // Silence is golden' );
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\init', 1 );

// Load logger functions.
require_once __DIR__ . '/logger.php';
