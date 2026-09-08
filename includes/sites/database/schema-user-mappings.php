<?php
/**
 * WPTSALL User Mappings Table Schema
 *
 * User ID mapping table - stores user mapping relationships between source and target sites
 *
 * @package WPTSALL
 * @since 0.8.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create user mappings table
 *
 * Used to store cross-site user ID mappings, supporting:
 * - Manual mapping: Admin specifies source user to target user mapping
 * - Auto-match: Match users by email
 * - Default fallback: Use default user when no match
 *
 * @since 0.8.0
 */
function wptsall_create_user_mappings_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'user_mappings' );
	$charset_collate = $wpdb->get_charset_collate();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

		-- Site info
		source_site_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Source site ID',
		target_site_id VARCHAR(50) NOT NULL COMMENT 'Target site ID (can be virtual site ID e.g. v_2)',

		-- Mapping info
		source_user_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Source site user ID',
		target_user_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Target site user ID',

		-- Mapping type
		mapping_type VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'Mapping type: manual/email_match/auto_fallback',

		-- Timestamps
		created_at DATETIME NOT NULL COMMENT 'Created at',
		updated_at DATETIME NOT NULL COMMENT 'Updated at',

		PRIMARY KEY (id),

		-- Unique constraint: one mapping per source user per site pair
		UNIQUE KEY unique_site_user (source_site_id, target_site_id, source_user_id),

		-- Indexes
		INDEX idx_source_site (source_site_id),
		INDEX idx_target_site (target_site_id),
		INDEX idx_source_user (source_user_id),
		INDEX idx_target_user (target_user_id),
		INDEX idx_mapping_type (mapping_type)
	) {$charset_collate} ENGINE=InnoDB COMMENT='User ID mapping table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'User mappings table created',
		array(
			'table'   => $table_name,
			'version' => '0.8.0',
		)
	);
}

/**
 * Check if user mappings table exists
 *
 * @since 0.8.0
 * @return bool
 */
function wptsall_user_mappings_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'user_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Delete user mappings table (used only during uninstall)
 *
 * @since 0.8.0
 */
function wptsall_drop_user_mappings_table() {
	global $wpdb;
	$table_name = wptsall_table( 'user_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );

	wptsall_log(
		'database',
		'info',
		'User mappings table dropped',
		array( 'table' => $table_name )
	);
}
