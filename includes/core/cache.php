<?php
/**
 * WPTSALL Caching Functions
 *
 * Provides caching layer using WordPress Transients API for improved performance.
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Cache group prefix for all WPTSALL transients.
 */
define( 'WPTSALL_CACHE_GROUP', 'wptsall_cache_' );

/**
 * Default cache expiration time (1 hour).
 *
 * @deprecated Use wptsall_get_cache_ttl() for configurable values.
 */
define( 'WPTSALL_CACHE_EXPIRATION', HOUR_IN_SECONDS );

/**
 * Get cache TTL for a specific cache type.
 *
 * Reads from task parameters configuration for dynamic TTL values.
 *
 * @since 0.8.1
 * @param string $type Cache type: 'default', 'templates', 'sites', 'stats'.
 * @return int TTL in seconds.
 */
function wptsall_get_cache_ttl( $type = 'default' ) {
	// Get task parameters if the function exists (may not be loaded yet during bootstrap).
	$params = array();
	if ( function_exists( 'wptsall_get_task_parameters' ) ) {
		$params = wptsall_get_task_parameters();
	}

	// Map type to parameter key and return TTL in seconds.
	switch ( $type ) {
		case 'templates':
			$minutes = $params['cache_templates_ttl'] ?? 120;
			break;
		case 'sites':
			$minutes = $params['cache_sites_ttl'] ?? 120;
			break;
		case 'stats':
			$minutes = $params['cache_stats_ttl'] ?? 5;
			break;
		case 'default':
		default:
			$minutes = $params['cache_default_ttl'] ?? 60;
			break;
	}

	return $minutes * MINUTE_IN_SECONDS;
}

/**
 * Get cached data or execute callback and cache result.
 *
 * @param string   $key        Cache key.
 * @param callable $callback   Callback to execute if cache miss.
 * @param int      $expiration Cache expiration in seconds (default: 1 hour).
 * @return mixed Cached data or callback result.
 */
function wptsall_cache_get_or_set( $key, $callback, $expiration = WPTSALL_CACHE_EXPIRATION ) {
	$cache_key = WPTSALL_CACHE_GROUP . $key;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		wptsall_log_debug( 'core', 'Cache hit', array(
			'key' => $key,
		) );

		return $cached;
	}

	wptsall_log_debug( 'core', 'Cache miss - executing callback', array(
		'key'        => $key,
		'expiration' => $expiration,
	) );

	$data = $callback();
	set_transient( $cache_key, $data, $expiration );

	return $data;
}


/**
 * Get a previously cached response for an Idempotency-Key.
 *
 * @since 1.6.0
 *
 * When a request hash is supplied, a cache entry created for a different
 * request body is returned with `conflict => true`. This prevents a caller
 * from replaying a successful response merely by reusing its HTTP header.
 * Entries written before request hashes were introduced remain replayable
 * until their normal TTL expires, preserving retry behaviour during upgrade.
 *
 * @param string $key          Idempotency-Key (or empty string for no key).
 * @param string $request_hash Canonical request-body hash, when available.
 * @return array|null Cached response ['status' => int, 'body' => array] or null.
 */
function wptsall_idempotency_get( string $key, string $request_hash = '' ) {
	if ( '' === $key ) {
		return null;
	}
	$cache_key = 'wptsall_idem_' . substr( md5( $key ), 0, 32 );
	$cached    = get_transient( $cache_key );
	if ( ! is_array( $cached ) || ! isset( $cached['status'], $cached['body'] ) ) {
		return null;
	}

	$cached_hash = (string) ( $cached['request_hash'] ?? '' );
	if ( '' !== $request_hash && '' !== $cached_hash && ! hash_equals( $cached_hash, $request_hash ) ) {
		$cached['conflict'] = true;
	}

	return $cached;
}

/**
 * Cache a response for an Idempotency-Key.
 *
 * @since 1.6.0
 *
 * @param string $key          Idempotency-Key.
 * @param int    $status       HTTP status code to return on replay.
 * @param array  $body         Response body to return on replay.
 * @param int    $ttl          Time-to-live in seconds (default 24 hours).
 * @param string $request_hash Canonical request-body hash for replay validation.
 * @return void
 */
function wptsall_idempotency_set( string $key, int $status, array $body, int $ttl = DAY_IN_SECONDS, string $request_hash = '' ): void {
	if ( '' === $key ) {
		return;
	}
	$cache_key = 'wptsall_idem_' . substr( md5( $key ), 0, 32 );
	set_transient(
		$cache_key,
		array(
			'status'     => $status,
			'body'       => $body,
			'request_hash' => $request_hash,
			'created_at' => gmdate( 'c' ),
		),
		$ttl
	);
}

/**
 * Clear all cached Idempotency-Key responses (E2E reset / lab lane cleanup).
 *
 * @since 1.6.0
 *
 * @return int Number of transient rows deleted.
 */
function wptsall_idempotency_clear_all(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wptsall_idem_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wptsall_idem_' ) . '%'
		)
	);

	return max( 0, $deleted );
}

/**
 * Get cached saved templates.
 *
 * @return array Saved templates.
 */
function wptsall_get_cached_templates() {
	return wptsall_cache_get_or_set(
		'saved_templates',
		function () {
			return wptsall_saved_templates();
		},
		wptsall_get_cache_ttl( 'templates' )
	);
}

/**
 * Get cached site relations.
 *
 * @return array Site relations.
 */
function wptsall_get_cached_site_relations() {
	return wptsall_cache_get_or_set(
		'site_relations',
		function () {
			return get_option( 'wptsall_sites', array() );
		},
		wptsall_get_cache_ttl( 'sites' )
	);
}

/**
 * Get cached virtual sites.
 *
 * @return array Virtual sites.
 */
function wptsall_get_cached_virtual_sites() {
	return wptsall_cache_get_or_set(
		'virtual_sites',
		function () {
			return wptsall_get_virtual_sites();
		},
		wptsall_get_cache_ttl( 'sites' )
	);
}

/**
 * Get cached task statistics.
 *
 * Returns empty stats when tasks table is not available.
 *
 * @return array Task statistics (pending, processing, completed, retry counts).
 */
function wptsall_get_cached_task_stats() {
	if ( function_exists( 'wptsall_tasks_table_exists' ) && ! wptsall_tasks_table_exists() ) {
		return array( 'pending' => 0, 'processing' => 0, 'completed' => 0, 'retry' => 0 );
	}

	return wptsall_cache_get_or_set(
		'task_stats',
		function () {
			global $wpdb;
			$table = wptsall_table( 'tasks' );
			$stats = array();

			foreach ( array( 'pending', 'processing', 'completed', 'retry' ) as $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache layer query
				$stats[ $status ] = intval(
					$wpdb->get_var(
						$wpdb->prepare(
							'SELECT COUNT(*) FROM %i WHERE status = %s',
							$table,
							$status
						)
					)
				);
			}

			return $stats;
		},
		wptsall_get_cache_ttl( 'stats' )
	);
}

/**
 * Get cached hook statistics.
 *
 * @return array Hook statistics (enabled, disabled counts).
 */
function wptsall_get_cached_hook_stats() {
	return wptsall_cache_get_or_set(
		'hook_stats',
		function () {
			global $wpdb;
			$table = wptsall_table( 'hooks' );
			$stats = array();

			// Guard: hooks table deprecated in v0.9.0; may not exist on new installs.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return array( 'enabled' => 0, 'disabled' => 0 );
			}

			foreach ( array( 'enabled', 'disabled' ) as $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache layer query
				$stats[ $status ] = intval(
					$wpdb->get_var(
						$wpdb->prepare(
							'SELECT COUNT(*) FROM %i WHERE status = %s',
							$table,
							$status
						)
					)
				);
			}

			return $stats;
		},
		wptsall_get_cache_ttl( 'stats' )
	);
}

/**
 * Invalidate (delete) a specific cache entry.
 *
 * @param string $key Cache key.
 * @return bool True on success, false on failure.
 */
function wptsall_cache_invalidate( $key ) {
	$cache_key = WPTSALL_CACHE_GROUP . $key;
	$result    = delete_transient( $cache_key );

	wptsall_log_debug(
		'core-cache',
		'Cache invalidated',
		array(
			'key'     => $key,
			'success' => $result,
		)
	);

	return $result;
}

/**
 * Invalidate all WPTSALL caches.
 *
 * @return int Number of caches invalidated.
 */
function wptsall_cache_invalidate_all() {
	global $wpdb;
	$count = 0;

	// Get all WPTSALL transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache invalidation query
	$transients = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			'_transient_' . WPTSALL_CACHE_GROUP . '%'
		)
	);

	foreach ( $transients as $transient ) {
		$key = str_replace( '_transient_' . WPTSALL_CACHE_GROUP, '', $transient );
		if ( wptsall_cache_invalidate( $key ) ) {
			$count++;
		}
	}

	wptsall_log_debug(
		'core-cache',
		'All caches invalidated',
		array(
			'total_found'      => count( $transients ),
			'total_invalidated' => $count,
		)
	);

	return $count;
}

/**
 * Invalidate template-related caches.
 *
 * Call this when templates are modified.
 */
function wptsall_cache_invalidate_templates() {
	wptsall_log_debug( 'core-cache', 'Invalidating template caches' );
	wptsall_cache_invalidate( 'saved_templates' );
}

/**
 * Invalidate site-related caches.
 *
 * Call this when site relations or virtual sites are modified.
 */
function wptsall_cache_invalidate_sites() {
	wptsall_log_debug( 'core-cache', 'Invalidating site caches' );
	wptsall_cache_invalidate( 'site_relations' );
	wptsall_cache_invalidate( 'virtual_sites' );
}

/**
 * Invalidate task-related caches.
 *
 * Call this when tasks are created, updated, or deleted.
 */
function wptsall_cache_invalidate_tasks() {
	wptsall_log_debug( 'core-cache', 'Invalidating task caches' );
	wptsall_cache_invalidate( 'task_stats' );
}

/**
 * Invalidate hook-related caches.
 *
 * Call this when hooks are created, updated, or deleted.
 */
function wptsall_cache_invalidate_hooks() {
	wptsall_log_debug( 'core-cache', 'Invalidating hook caches' );
	wptsall_cache_invalidate( 'hook_stats' );
}


/**
 * Get cache statistics.
 *
 * @return array Cache statistics (total transients, size estimate).
 */
function wptsall_cache_get_stats() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache stats query
	$count = intval(
		$wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				'_transient_' . WPTSALL_CACHE_GROUP . '%'
			)
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache stats query
	$size = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT SUM(LENGTH(option_value)) FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			'_transient_' . WPTSALL_CACHE_GROUP . '%'
		)
	);

	return array(
		'count' => $count,
		'size'  => $size ? intval( $size ) : 0,
		'size_human' => $size ? size_format( intval( $size ), 2 ) : '0 B',
	);
}

// Auto-invalidate caches on relevant actions.
add_action( 'wptsall_template_saved', 'wptsall_cache_invalidate_templates' );
add_action( 'wptsall_template_deleted', 'wptsall_cache_invalidate_templates' );
add_action( 'wptsall_site_saved', 'wptsall_cache_invalidate_sites' );
add_action( 'wptsall_site_deleted', 'wptsall_cache_invalidate_sites' );
add_action( 'wptsall_task_created', 'wptsall_cache_invalidate_tasks' );
add_action( 'wptsall_task_updated', 'wptsall_cache_invalidate_tasks' );
add_action( 'wptsall_task_deleted', 'wptsall_cache_invalidate_tasks' );
add_action( 'wptsall_hook_created', 'wptsall_cache_invalidate_hooks' );
add_action( 'wptsall_hook_updated', 'wptsall_cache_invalidate_hooks' );
add_action( 'wptsall_hook_deleted', 'wptsall_cache_invalidate_hooks' );
