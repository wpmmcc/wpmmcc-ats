<?php
/**
 * WPTSALL Relation Models Table Schema
 *
 * Site relation-model association table (many-to-many relationship)
 *
 * @package WPTSALL\Sites
 * @since 0.6.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create site relation-model association table
 *
 * Implement many-to-many relationships between site relations and models
 * A site relation can be associated with multiple plugins/models
 *
 * @since 0.6.0
 */
function wptsall_create_relation_models_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'relation_models' );
	$charset_collate = $wpdb->get_charset_collate();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		relation_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Site relation ID',
		model_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Model ID',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created at',

		PRIMARY KEY (id),
		UNIQUE KEY relation_model (relation_id, model_id),
		INDEX idx_relation_id (relation_id),
		INDEX idx_model_id (model_id)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Site relation-model association table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Relation models table created',
		array(
			'table'   => $table_name,
			'version' => '0.6.0',
		)
	);
}

/**
 * Check if relation models table exists
 *
 * @return bool
 */
/**
 * Delete relation models table (used only during uninstall)
 */
