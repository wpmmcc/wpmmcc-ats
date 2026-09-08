<?php
/**
 * WPTSALL Site Relations Table Schema
 *
 * Site relations table structure definition (v0.4.0 one-to-one structure)
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.4.0 Refactored to one-to-one storage structure
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create site relations table (v0.4.0 new structure)
 *
 * Core business rules:
 * - One record per target site (one-to-one structure)
 * - Five-tuple unique constraint: source_site_id + source_lang + template + target_site_id + target_lang
 */
function wptsall_create_site_relations_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'site_relations' );
	$charset_collate = $wpdb->get_charset_collate();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

		-- Source site info
		source_site_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Source site ID',
		source_site_type VARCHAR(20) DEFAULT 'wp' COMMENT 'Source site type: wp (fixed)',
		source_lang VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'Source site language code',
		source_theme_name VARCHAR(255) DEFAULT '' COMMENT 'Source site theme name',
		source_theme_path VARCHAR(255) DEFAULT '' COMMENT 'Source site theme path',

		-- Plugin/Model
		template VARCHAR(100) NOT NULL COMMENT 'Model/plugin identifier',

		-- Target site info
		target_site_id VARCHAR(50) NOT NULL COMMENT 'Target site ID (numeric for wp, string for virtual)',
		target_site_type VARCHAR(20) NOT NULL DEFAULT 'wp' COMMENT 'Target type: wp / virtual',
		target_lang VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'Target site language code',
		target_theme_name VARCHAR(255) DEFAULT '' COMMENT 'Target site theme name (wp site)',
		target_theme_path VARCHAR(255) DEFAULT '' COMMENT 'Target site theme path (wp site)',

		-- Sync configuration (v0.8.0)
		media_handling VARCHAR(20) DEFAULT 'copy' COMMENT 'Media handling strategy: copy / reference',
		sync_mode VARCHAR(20) DEFAULT 'new_only' COMMENT 'Sync mode: new_only (create only)',
		direction VARCHAR(20) DEFAULT 'source_to_target' COMMENT 'Sync direction: source_to_target / bidirectional',
		conflict_strategy VARCHAR(20) DEFAULT 'source_wins' COMMENT 'Conflict strategy: source_wins / target_wins / newest_wins / manual / merge',

		-- Status
		status VARCHAR(20) DEFAULT 'active' COMMENT 'Relation status: active / inactive',
		plugin_status MEDIUMTEXT COMMENT 'Plugin status detection result (JSON)',

		-- Timestamps
		created_at DATETIME NOT NULL COMMENT 'Created at',
		updated_at DATETIME NOT NULL COMMENT 'Updated at',

		PRIMARY KEY (id),

		-- Five-tuple unique constraint
		UNIQUE KEY unique_relation (source_site_id, source_lang, template, target_site_id, target_lang),

		-- Indexes (optimized for hook queries)
		INDEX idx_source (source_site_id, source_lang),
		INDEX idx_target (target_site_id, target_site_type, target_lang),
		INDEX idx_template (template),
		INDEX idx_status (status)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Site relations table (one-to-one structure)';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Log entry
	wptsall_log(
		'database',
		'info',
		'Site relations table created (v0.4.0)',
		array(
			'table'   => $table_name,
			'version' => '0.4.0',
		)
	);
}

/**
 * Check if site relations table exists
 *
 * @return bool
 */
function wptsall_site_relations_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'site_relations' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Delete site relations table (used only during uninstall)
 */
/**
 * Get site relations table statistics
 *
 * @return array
 */
/**
 * Migrate site relations table for v0.8.0
 *
 * Adds media_handling column if it doesn't exist.
 *
 * @since 0.8.0
 */
function wptsall_migrate_site_relations_080() {
	global $wpdb;
	$table_name = wptsall_table( 'site_relations' );

	if ( ! wptsall_site_relations_table_exists() ) {
		return;
	}

	// Check if media_handling column exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column_exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'media_handling' ) );

	if ( empty( $column_exists ) ) {
		// Add media_handling column
		wptsall_db_alter_table(
			$table_name,
			"ADD COLUMN media_handling VARCHAR(20) DEFAULT 'copy' COMMENT 'Media handling strategy: copy / reference' AFTER target_theme_path"
		);

		wptsall_log(
			'database',
			'info',
			'Site relations table migrated to v0.8.0',
			array(
				'table'         => $table_name,
				'added_columns' => array( 'media_handling' ),
			)
		);
	}
}

/**
 * Migrate site relations table for v0.8.1
 *
 * Adds sync_mode, direction, conflict_strategy columns.
 *
 * @since 0.8.1
 */
function wptsall_migrate_site_relations_081() {
	global $wpdb;
	$table_name = wptsall_table( 'site_relations' );

	if ( ! wptsall_site_relations_table_exists() ) {
		return;
	}

	$added_columns = array();

	// Check and add sync_mode column.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column_exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'sync_mode' ) );
	if ( empty( $column_exists ) ) {
		wptsall_db_alter_table(
			$table_name,
			"ADD COLUMN sync_mode VARCHAR(20) DEFAULT 'new_only' COMMENT 'Sync mode: new_only (create only)' AFTER media_handling"
		);
		$added_columns[] = 'sync_mode';
	}

	// Check and add direction column.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column_exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'direction' ) );
	if ( empty( $column_exists ) ) {
		wptsall_db_alter_table(
			$table_name,
			"ADD COLUMN direction VARCHAR(20) DEFAULT 'source_to_target' COMMENT 'Sync direction: source_to_target / bidirectional' AFTER sync_mode"
		);
		$added_columns[] = 'direction';
	}

	// Check and add conflict_strategy column.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column_exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'conflict_strategy' ) );
	if ( empty( $column_exists ) ) {
		wptsall_db_alter_table(
			$table_name,
			"ADD COLUMN conflict_strategy VARCHAR(20) DEFAULT 'source_wins' COMMENT 'Conflict strategy: source_wins / target_wins / newest_wins / manual / merge' AFTER direction"
		);
		$added_columns[] = 'conflict_strategy';
	}

	if ( ! empty( $added_columns ) ) {
		wptsall_log(
			'database',
			'info',
			'Site relations table migrated to v0.8.1',
			array(
				'table'         => $table_name,
				'added_columns' => $added_columns,
			)
		);
	}
}
