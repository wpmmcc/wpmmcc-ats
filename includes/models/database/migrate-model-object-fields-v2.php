<?php
/**
 * Migration: Add SSOT columns to model_object_fields table.
 *
 * @package WPTSALL\Models\Database
 * @since 1.1.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add 8 new columns to model_object_fields for SSOT support.
 */
function wptsall_migrate_model_object_fields_v2() {
	global $wpdb;

	$table = $wpdb->prefix . 'wptsall_model_object_fields';

	// Check if table exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
	);
	if ( ! $table_exists ) {
		return; // Table doesn't exist yet, schema-models.php will create it with new columns.
	}

	// Check if columns already exist (idempotent).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0 );
	if ( in_array( 'data_type', $columns, true ) ) {
		return; // Already migrated.
	}

	wptsall_db_alter_table(
		$table,
		"ADD COLUMN data_type VARCHAR(50) DEFAULT NULL COMMENT 'text/html/numeric/id_ref/id_list/url/datetime/serialized/json/enum/slug/boolean' AFTER source,
		ADD COLUMN reference_type VARCHAR(50) DEFAULT NULL COMMENT 'When data_type=id_ref/id_list: post/taxonomy/media/user' AFTER data_type,
		ADD COLUMN reference_target VARCHAR(100) DEFAULT NULL COMMENT 'Specific target: attachment/product/product_cat etc.' AFTER reference_type,
		ADD COLUMN usage_count INT UNSIGNED DEFAULT 0 COMMENT 'Usage count from scan' AFTER reference_target,
		ADD COLUMN sample_value TEXT DEFAULT NULL COMMENT 'Sample value (first 100 chars)' AFTER usage_count,
		ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active/new/orphan/deprecated' AFTER sample_value,
		ADD COLUMN discovered_at DATETIME DEFAULT NULL COMMENT 'First discovered timestamp' AFTER status,
		ADD COLUMN confirmed_at DATETIME DEFAULT NULL COMMENT 'User confirmed timestamp' AFTER discovered_at,
		ADD INDEX idx_status (status),
		ADD INDEX idx_data_type (data_type)"
	);

	if ( function_exists( 'wptsall_log_info' ) ) {
		wptsall_log_info( 'database', 'model_object_fields table migrated to v2', array( 'added_columns' => 8 ) );
	}
}
