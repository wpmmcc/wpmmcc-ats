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
 * Logs are stored under wp-content/uploads/ in a directory whose name carries
 * a per-installation hash suffix (wptsall-logs-<hash8>). Plain .htaccess
 * files are ignored by nginx/Caddy/OpenResty, so with a predictable directory
 * name the log files would be directly downloadable via URL guessing on such
 * stacks (BUG-SEC-01); the hash breaks that chain while uploads-based storage
 * keeps logs safe across plugin updates and out of Plugin Check's scan scope.
 * Define WPTSALL_LOG_DIR to relocate logs entirely (e.g. outside the web root).
 *
 * @return string Log directory path.
 */
function get_log_dir() {
	// Explicit override wins (absolute path expected).
	if ( defined( 'WPTSALL_LOG_DIR' ) && '' !== WPTSALL_LOG_DIR ) {
		return WPTSALL_LOG_DIR;
	}

	$upload_dir = wp_upload_dir();
	$base       = rtrim( (string) $upload_dir['basedir'], '/\\' );
	$dir        = $base . '/wptsall-logs-' . wptsall_log_dir_suffix();

	// Migrate the legacy predictable directory name (pre-2.1.3) on first touch.
	$legacy = $base . '/wptsall-logs';
	if ( is_dir( $legacy ) && ! is_dir( $dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		@rename( $legacy, $dir );
	}

	return $dir;
}

/**
 * Per-installation log directory suffix (8 hex chars).
 *
 * Derived from the site's secret salts: stable across requests (unlike a
 * random value, which would orphan log files) but not guessable from the
 * outside. Falls back through the standard salt constants; without any salt
 * the suffix is still a fixed hash, which is no worse than the legacy name.
 *
 * @return string 8-character hex suffix.
 */
function wptsall_log_dir_suffix() {
	$salt = '';
	foreach ( array( 'NONCE_SALT', 'AUTH_SALT', 'LOGGED_IN_SALT', 'AUTH_KEY' ) as $wptsall_salt_const ) {
		if ( defined( $wptsall_salt_const ) && '' !== constant( $wptsall_salt_const ) ) {
			$salt = (string) constant( $wptsall_salt_const );
			break;
		}
	}
	return substr( md5( 'wptsall-log-dir' . $salt ), 0, 8 );
}

/**
 * Initialize the log module.
 */
function init() {
	// Ensure log directory exists (uploads/wptsall-logs-<hash8>, or WPTSALL_LOG_DIR).
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
