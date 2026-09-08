<?php
/**
 * WPTSALL Relation Post Type Configs Table Schema
 *
 * Site relation level Post Type configuration override table
 * Allow overriding model default configuration at site relation level
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
 * Create relation level Post Type configuration table
 *
 * Used to store site relation Post Type configuration overrides
 * NULL value means inherit model default configuration
 *
 * @since 0.8.0
 */
function wptsall_create_relation_post_type_configs_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'relation_post_type_configs' );
	$charset_collate = $wpdb->get_charset_collate();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

		-- Association info
		relation_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Associated site relation ID',
		post_type VARCHAR(100) NOT NULL COMMENT 'Target Post Type',

		-- Override configuration (NULL means inherit model configuration)
		enabled TINYINT(1) DEFAULT NULL COMMENT 'Whether sync is enabled (NULL=inherit)',
		direction VARCHAR(30) DEFAULT NULL COMMENT 'Sync direction override (NULL=inherit)',
		sync_mode VARCHAR(20) DEFAULT NULL COMMENT 'Sync mode override (NULL=inherit)',

		-- Field-level overrides (JSON)
		field_overrides LONGTEXT DEFAULT NULL COMMENT 'Field configuration override (JSON object)',

		-- Timestamps
		created_at DATETIME NOT NULL COMMENT 'Created at',
		updated_at DATETIME NOT NULL COMMENT 'Updated at',

		PRIMARY KEY (id),

		-- Unique constraint: one config per post_type per relation
		UNIQUE KEY unique_relation_post_type (relation_id, post_type),

		-- Indexes
		INDEX idx_relation (relation_id),
		INDEX idx_post_type (post_type)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Site relation level Post Type configuration override table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Relation post type configs table created',
		array(
			'table'   => $table_name,
			'version' => '0.8.0',
		)
	);
}

/**
 * Check if relation configs table exists
 *
 * @since 0.8.0
 * @return bool
 */
function wptsall_relation_post_type_configs_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'relation_post_type_configs' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Delete relation configs table (used only during uninstall)
 *
 * @since 0.8.0
 */
/**
 * Get relation configs table statistics
 *
 * @since 0.8.0
 * @return array
 */
