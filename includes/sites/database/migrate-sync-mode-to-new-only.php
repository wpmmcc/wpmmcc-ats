<?php
/**
 * Migrate sync_mode values to 'new_only' (ISS-SIT-037)
 *
 * Updates all legacy sync_mode values (full, incremental, manual, sync_only,
 * translate_only) to 'new_only' across site_relations, translation_rules,
 * and relation_post_type_configs tables.
 *
 * Product decision (2026-01-29): sync_mode is fixed to 'new_only'.
 *
 * @package WPTSALL\Sites\Database
 * @since 1.0.3
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

/**
 * Run the sync_mode migration.
 *
 * @return array Migration stats.
 */
function wptsall_migrate_sync_mode_to_new_only() {
	global $wpdb;

	$stats = array(
		'site_relations_updated'    => 0,
		'translation_rules_updated' => 0,
		'relation_configs_updated'  => 0,
	);

	$legacy_values = array( 'full', 'incremental', 'manual', 'sync_only', 'translate_only', 'new_and_update' );

	// 1. Site relations table.
	$relations_table = wptsall_table( 'site_relations' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $relations_table )
	);

	if ( $table_exists ) {
		$placeholders = implode( ',', array_fill( 0, count( $legacy_values ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET sync_mode = 'new_only' WHERE sync_mode IN ({$placeholders})",
				array_merge( array( $relations_table ), $legacy_values )
			)
		);
		$stats['site_relations_updated'] = (int) $updated;
	}

	// 2. Translation rules table.
	$rules_table = wptsall_table( 'translation_rules' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $rules_table )
	);

	if ( $table_exists ) {
		$placeholders = implode( ',', array_fill( 0, count( $legacy_values ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET sync_mode = 'new_only' WHERE sync_mode IN ({$placeholders})",
				array_merge( array( $rules_table ), $legacy_values )
			)
		);
		$stats['translation_rules_updated'] = (int) $updated;
	}

	// 3. Relation configs table (if exists).
	$configs_table = wptsall_table( 'relation_post_type_configs' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $configs_table )
	);

	if ( $table_exists ) {
		$placeholders = implode( ',', array_fill( 0, count( $legacy_values ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET sync_mode = 'new_only' WHERE sync_mode IN ({$placeholders})",
				array_merge( array( $configs_table ), $legacy_values )
			)
		);
		$stats['relation_configs_updated'] = (int) $updated;
	}

	// 4. Fix column defaults to 'new_only' on existing tables (including models).
	$models_table   = wptsall_table( 'models' );
	$default_tables = array( $relations_table, $rules_table, $configs_table, $models_table );
	foreach ( $default_tables as $dt ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$dt_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dt ) );
		if ( ! $dt_exists ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col_exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $dt, 'sync_mode' ) );
		if ( ! empty( $col_exists ) ) {
			wptsall_db_alter_table( $dt, "ALTER COLUMN sync_mode SET DEFAULT 'new_only'" );
		}
	}

	if ( function_exists( 'wptsall_log_info' ) ) {
		wptsall_log_info( 'migration', 'ISS-SIT-037: sync_mode migration completed', $stats );
	}

	return $stats;
}
