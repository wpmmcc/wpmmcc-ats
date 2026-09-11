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

	// Retention: prune log files older than 30 days at most once a day.
	// Runs only when logging is enabled (release default: disabled), so the
	// usual request path never touches the filesystem here.
	if ( wptsall_log_enabled() ) {
		wptsall_log_retention_tick();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\init', 1 );

/**
 * Run the daily log-retention tick: delete log files older than 30 days and
 * record the run timestamp so it executes at most once per day.
 *
 * Split out of init() so the retention logic itself stays unit-testable
 * without requiring the WPTSALL_LOG_ENABLED constant to be set.
 *
 * @return int Number of files deleted by this tick (0 when the daily guard
 *             is still fresh).
 */
function wptsall_log_retention_tick() {
	$last_run = (int) get_option( 'wptsall_log_cleanup_last_run', 0 );
	if ( ( time() - $last_run ) <= DAY_IN_SECONDS ) {
		return 0;
	}
	update_option( 'wptsall_log_cleanup_last_run', time(), false );
	return wptsall_clean_old_logs( 30 );
}

// Load logger functions.
require_once __DIR__ . '/logger.php';
