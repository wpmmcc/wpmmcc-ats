<?php
/**
 * Client data REST rate-limit counter schema.
 *
 * @package WPTSALL\Tasks
 * @since 2.1.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create the atomic per-device client rate-limit counter table.
 *
 * The key is a SHA-256 digest of the bucket and device id; no raw device
 * identifier is persisted. Counters are updated with an atomic SQL upsert so
 * concurrent PHP workers cannot lose increments like a transient read/modify/
 * write cycle can.
 *
 * @return void
 */
function wptsall_create_client_rate_limits_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'client_rate_limits' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		bucket_hash CHAR(64) NOT NULL,
		request_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		reset_at BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (bucket_hash),
		KEY idx_reset_at (reset_at)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Atomic client data REST rate-limit counters';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Ensure the rate-limit table exists on installations upgraded in place.
 *
 * @return bool True when the table is available.
 */
function wptsall_ensure_client_rate_limits_table() {
	global $wpdb;
	$table_name = wptsall_table( 'client_rate_limits' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	if ( $exists !== $table_name && function_exists( 'wptsall_create_client_rate_limits_table' ) ) {
		wptsall_create_client_rate_limits_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	}
	return $exists === $table_name;
}
