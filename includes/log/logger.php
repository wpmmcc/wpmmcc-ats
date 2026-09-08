<?php
/**
 * WPTSALL Logger
 *
 * Logger - Provides channel-based and level-based logging functionality.
 *
 * @package WPTSALL
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log level constants.
 */
define( 'WPTSALL_LOG_LEVEL_DEBUG', 'debug' );
define( 'WPTSALL_LOG_LEVEL_INFO', 'info' );
define( 'WPTSALL_LOG_LEVEL_WARNING', 'warning' );
define( 'WPTSALL_LOG_LEVEL_ERROR', 'error' );

/**
 * Log level priority (higher number = higher priority).
 */
function wptsall_log_level_priority( $level ) {
	$priorities = array(
		WPTSALL_LOG_LEVEL_DEBUG   => 1,
		WPTSALL_LOG_LEVEL_INFO    => 2,
		WPTSALL_LOG_LEVEL_WARNING => 3,
		WPTSALL_LOG_LEVEL_ERROR   => 4,
	);

	return isset( $priorities[ $level ] ) ? $priorities[ $level ] : 0;
}

/**
 * Get available log channels list.
 *
 * @return array Channel list.
 */
function wptsall_log_channels() {
	return array(
		// ========== Main module channels ==========
		'models'             => 'Models module main channel',
		'sites'              => 'Sites module main channel',
		'tasks'              => 'Tasks module main channel',
		'hooks'              => 'Hooks module main channel',
		'templates'          => 'Templates module main channel (language pack templates)',
		'core'               => 'Core module main channel',

		// ========== Models sub-module channels ==========
		'models-scanner'     => 'Models: Scanner (postmeta/usermeta/commentmeta)',
		'models-template'    => 'Models: Blog template scanning',
		'models-service'     => 'Models: Service layer operations',
		'models-api'         => 'Models: REST API operations',

		// ========== Sites sub-module channels ==========
		'sites-virtual'      => 'Sites: Virtual site management',
		'sites-relations'    => 'Sites: Site relation management',
		'sites-groups'       => 'Sites: Site group management',
		'sites-url'          => 'Sites: URL transformation',
		'sites-cache'        => 'Sites: Cache operations',
		'sites-api'          => 'Sites: REST API operations',
		'sites-rest'         => 'Sites: REST service layer',

		// ========== Tasks sub-module channels ==========
		'tasks-orchestrator' => 'Tasks: Task orchestration',
		'tasks-sync'         => 'Tasks: Sync tasks',
		'tasks-translation'  => 'Tasks: Translation tasks',
		'tasks-monitoring'   => 'Tasks: Monitoring tasks',
		'tasks-cron'         => 'Tasks: Cron tasks',
		'tasks-conflict'     => 'Tasks: Conflict handling',
		'tasks-preview'      => 'Tasks: Preview functionality',
		'tasks-rollback'     => 'Tasks: Rollback functionality',
		'tasks-snapshot'     => 'Tasks: Snapshot functionality',
		'tasks-admin'        => 'Tasks: Admin backend',
		'tasks-api'          => 'Tasks: REST API operations',
		'conflicts-api'      => 'Tasks: Conflict REST API',

		// ========== Templates sub-module channels ==========
		'templates-scanner'  => 'Templates: Language pack scanning',
		'templates-parser'   => 'Templates: POT parsing',
		'templates-service'  => 'Templates: Template service',
		'templates-entry'    => 'Templates: Translation entry management',
		'templates-api'      => 'Templates: REST API operations',

		// ========== Hooks sub-module channels ==========
		'hooks-manager'      => 'Hooks: Hook manager',
		'hooks-gettext'      => 'Hooks: Gettext filtering',
		'hooks-router'       => 'Hooks: Virtual site router',
		'hooks-admin'        => 'Hooks: Admin backend hooks',

		// ========== Core sub-module channels ==========
		'core-admin'         => 'Core: Admin backend general',
		'core-rest'          => 'Core: REST API general',
		'core-migration'     => 'Core: Database migration',
		'core-data'          => 'Core: Data processing',
		'core-classifier'    => 'Core: Field classifier',
		'core-cache'         => 'Core: Cache operations',
		'core-settings'      => 'Core: Settings management',

		// ========== Legacy channels (deprecated, use new format) ==========
		'scanner'            => '[Deprecated] Use models-scanner',
		'template'           => '[Deprecated] Use models-template',
		'sync'               => '[Deprecated] Use tasks-sync',
		'translation'        => '[Deprecated] Use tasks-translation',
		'cron'               => '[Deprecated] Use tasks-cron',
		'conflict'           => '[Deprecated] Use tasks-conflict',
		'preview'            => '[Deprecated] Use tasks-preview',
		'rollback'           => '[Deprecated] Use tasks-rollback',
		'snapshot'           => '[Deprecated] Use tasks-snapshot',
		'task'               => '[Deprecated] Use tasks',
		'virtual'            => '[Deprecated] Use sites-virtual',
		'admin'              => '[Deprecated] Use core-admin',
		'rest'               => '[Deprecated] Use core-rest',
		'migration'          => '[Deprecated] Use core-migration',
		'data'               => '[Deprecated] Use core-data',
		'settings'           => '[Deprecated] Use core-settings',
		'api'                => '[Deprecated] Use module-specific xxx-api channel',
	);
}

/**
 * Check if logging is enabled.
 *
 * @return bool
 */
function wptsall_log_enabled() {
	return defined( 'WPTSALL_LOG_ENABLED' ) && WPTSALL_LOG_ENABLED;
}

/**
 * Get the currently configured minimum log level.
 *
 * @return string
 */
function wptsall_log_min_level() {
	if ( defined( 'WPTSALL_LOG_LEVEL' ) ) {
		return WPTSALL_LOG_LEVEL;
	}
	// Production environment defaults to error only.
	return WPTSALL_LOG_LEVEL_ERROR;
}

/**
 * Get the currently configured channel filter.
 *
 * @return array|null null means log all channels.
 */
function wptsall_log_channels_filter() {
	if ( defined( 'WPTSALL_LOG_CHANNELS' ) && is_array( WPTSALL_LOG_CHANNELS ) ) {
		return WPTSALL_LOG_CHANNELS;
	}
	return null; // Log all channels.
}

/**
 * Check if a log entry should be recorded for the specified channel and level.
 *
 * @param string $channel Channel.
 * @param string $level   Level.
 * @return bool
 */
function wptsall_should_log( $channel, $level ) {
	// Check if logging is enabled.
	if ( ! wptsall_log_enabled() ) {
		return false;
	}

	// Check if level meets minimum requirement.
	$min_level = wptsall_log_min_level();
	if ( wptsall_log_level_priority( $level ) < wptsall_log_level_priority( $min_level ) ) {
		return false;
	}

	// Check channel filter.
	$channels_filter = wptsall_log_channels_filter();
	if ( null !== $channels_filter && ! in_array( $channel, $channels_filter, true ) ) {
		return false;
	}

	return true;
}

/**
 * Get log file path.
 *
 * @param string $channel Channel.
 * @return string Log file path.
 */
function wptsall_log_file_path( $channel ) {
	$log_dir = \WPTSALL\Log\get_log_dir();
	$date    = gmdate( 'Y-m-d' );

	return $log_dir . '/wptsall-' . $channel . '-' . $date . '.log';
}

/**
 * Redact credentials and bearer material before it reaches any log sink.
 *
 * This is deliberately centralized at the logger boundary so new call sites
 * cannot accidentally persist secrets in context arrays. Keys are matched
 * case-insensitively and nested arrays/objects are handled recursively.
 *
 * @param mixed $value Context value or message text.
 * @return mixed Redacted value.
 */
function wptsall_redact_log_value( $value ) {
	$sensitive_key = static function ( $key ) {
		$key = strtolower( (string) $key );
		return (bool) preg_match( '/(?:token|secret|password|passwd|api[_-]?key|credential|authorization|private[_-]?key|access[_-]?key|client[_-]?secret)/', $key );
	};

	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = $sensitive_key( $key ) ? '[REDACTED]' : wptsall_redact_log_value( $item );
		}
		return $out;
	}
	if ( is_object( $value ) ) {
		return wptsall_redact_log_value( get_object_vars( $value ) );
	}
	if ( ! is_string( $value ) ) {
		return $value;
	}

	// Never persist URL userinfo or credential-like query/header values even
	// when a caller places them in a generic field such as "message" or "url".
	$value = (string) preg_replace( '#(https?://)([^/@\s]+):([^/@\s]+)@#i', '$1[REDACTED]@', $value );
	$value = (string) preg_replace( '/(authorization\s*:\s*(?:bearer\s+)?|bearer\s+|(?:token|secret|password|api[_-]?key)\s*[:=]\s*)([^\s,;]+)/i', '$1[REDACTED]', $value );
	$value = (string) preg_replace( '/([?&](?:token|secret|password|api[_-]?key|access[_-]?key)=)[^&#\s]+/i', '$1[REDACTED]', $value );
	return $value;
}

/**
 * Redact a log context array.
 *
 * @param mixed $context Context value.
 * @return mixed
 */
function wptsall_redact_log_context( $context ) {
	return wptsall_redact_log_value( $context );
}

/**
 * Write a log entry.
 *
 * @param string $channel Channel: models, sites, tasks, hooks, core, api.
 * @param string $level   Level: debug, info, warning, error.
 * @param string $message Log message.
 * @param array  $context Context data.
 * @return bool Whether write was successful.
 */
function wptsall_log( $channel, $level, $message, $context = array() ) {
	$message = (string) wptsall_redact_log_value( $message );
	$context = wptsall_redact_log_context( $context );
	// Validate channel (before check, ensure valid value).
	$valid_channels = array_keys( wptsall_log_channels() );
	if ( ! in_array( $channel, $valid_channels, true ) ) {
		$channel = 'core'; // Invalid channel falls back to core.
	}

	// Validate level (before check, ensure valid value).
	$valid_levels = array(
		WPTSALL_LOG_LEVEL_DEBUG,
		WPTSALL_LOG_LEVEL_INFO,
		WPTSALL_LOG_LEVEL_WARNING,
		WPTSALL_LOG_LEVEL_ERROR,
	);
	if ( ! in_array( $level, $valid_levels, true ) ) {
		$level = WPTSALL_LOG_LEVEL_INFO;
	}

	// Check whether this entry should be logged (using the validated values).
	if ( ! wptsall_should_log( $channel, $level ) ) {
		return false;
	}

	// Build the log entry.
	$timestamp = gmdate( 'Y-m-d H:i:s' );
	$level_upper = strtoupper( $level );

	$log_entry = "[{$timestamp}] [{$level_upper}] {$message}";

	// Append context data.
	if ( ! empty( $context ) ) {
		$log_entry .= ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
	}

	$log_entry .= PHP_EOL;

	// Get the log file path.
	$log_file = wptsall_log_file_path( $channel );

	// Ensure the log directory exists (silently, no output even on failure).
	$log_dir = dirname( $log_file );
	if ( ! is_dir( $log_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		@mkdir( $log_dir, 0755, true );
	}

	if ( ! is_dir( $log_dir ) ) {
		// Directory could not be created; skip writing silently.
		return false;
	}

	// Write to the log file.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$result = file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );

	return false !== $result;
}

/**
 * Log a debug level entry.
 *
 * @param string $channel Channel.
 * @param string $message Message.
 * @param array  $context Context data.
 * @return bool
 */
function wptsall_log_debug( $channel, $message, $context = array() ) {
	return wptsall_log( $channel, WPTSALL_LOG_LEVEL_DEBUG, $message, $context );
}

/**
 * Log an info level entry.
 *
 * @param string $channel Channel.
 * @param string $message Message.
 * @param array  $context Context data.
 * @return bool
 */
function wptsall_log_info( $channel, $message, $context = array() ) {
	return wptsall_log( $channel, WPTSALL_LOG_LEVEL_INFO, $message, $context );
}

/**
 * Log a warning level entry.
 *
 * @param string $channel Channel.
 * @param string $message Message.
 * @param array  $context Context data.
 * @return bool
 */
function wptsall_log_warning( $channel, $message, $context = array() ) {
	return wptsall_log( $channel, WPTSALL_LOG_LEVEL_WARNING, $message, $context );
}

/**
 * Log an error level entry.
 *
 * @param string $channel Channel.
 * @param string $message Message.
 * @param array  $context Context data.
 * @return bool
 */
function wptsall_log_error( $channel, $message, $context = array() ) {
	return wptsall_log( $channel, WPTSALL_LOG_LEVEL_ERROR, $message, $context );
}

/**
 * Get log file list.
 *
 * @param string|null $channel Specific channel, null means all channels.
 * @return array Log file list.
 */
function wptsall_get_log_files( $channel = null ) {
	$log_dir = \WPTSALL\Log\get_log_dir();
	$files   = array();

	if ( ! is_dir( $log_dir ) ) {
		return $files;
	}

	$pattern = $channel ? "wptsall-{$channel}-*.log" : 'wptsall-*.log';
	$matches = glob( $log_dir . '/' . $pattern );

	if ( $matches ) {
		foreach ( $matches as $file ) {
			$files[] = array(
				'path'     => $file,
				'name'     => basename( $file ),
				'size'     => filesize( $file ),
				'modified' => filemtime( $file ),
			);
		}

		// Sort by modification time in descending order.
		usort( $files, function( $a, $b ) {
			return $b['modified'] - $a['modified'];
		} );
	}

	return $files;
}

/**
 * Read log file contents.
 *
 * @param string $file_path File path.
 * @param int    $lines     Number of lines to read (from the end).
 * @return string Log contents.
 */
function wptsall_read_log_file( $file_path, $lines = 100 ) {
	if ( ! file_exists( $file_path ) ) {
		return '';
	}

	// Ensure the file is within the log directory.
	$log_dir = \WPTSALL\Log\get_log_dir();
	$real_path = realpath( $file_path );
	$real_log_dir = realpath( $log_dir );

	if ( ! $real_path || strpos( $real_path, $real_log_dir ) !== 0 ) {
		return '';
	}

	// Read last N lines.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$content = file_get_contents( $file_path );
	$all_lines = explode( PHP_EOL, $content );
	$last_lines = array_slice( $all_lines, -$lines );

	return implode( PHP_EOL, $last_lines );
}

/**
 * Clean up log files older than the specified number of days.
 *
 * @param int $days Number of days to retain.
 * @return int Number of files deleted.
 */
function wptsall_clean_old_logs( $days = 30 ) {
	$log_dir = \WPTSALL\Log\get_log_dir();
	$deleted = 0;

	if ( ! is_dir( $log_dir ) ) {
		return $deleted;
	}

	$threshold = time() - ( $days * DAY_IN_SECONDS );
	$files = glob( $log_dir . '/wptsall-*.log' );

	if ( $files ) {
		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $threshold ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				if ( unlink( $file ) ) {
					$deleted++;
				}
			}
		}
	}

	return $deleted;
}
