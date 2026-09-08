<?php
/**
 * Clean up duplicate translation rules (ISS-MOD-001)
 *
 * This script cleans up duplicate translation rules created by the model scanner.
 * After running this script:
 * 1. Delete duplicate rules (keep the one with smallest ID)
 * 2. Add unique key constraint to prevent future duplicates
 *
 * Usage:
 *   wp eval-file includes/models/database/migrate-clean-duplicate-rules.php
 *
 * @package WPTSALL\Models\Database
 * @since 0.9.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

/**
 * Run the duplicate translation rules cleanup migration.
 *
 * @return void
 */
function wptsall_migrate_clean_duplicate_rules() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'wptsall_translation_rules';

	echo "\n";
	echo esc_html( "╔══════════════════════════════════════════════════════════════╗\n" );
	echo esc_html( "║  Clean Up Duplicate Translation Rules (ISS-MOD-001)                           ║\n" );
	echo esc_html( "╚══════════════════════════════════════════════════════════════╝\n" );
	echo "\n";

	// Step 1: Check if table exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	if ( $table_exists !== $table_name ) {
		echo esc_html( "Error: translation_rules table does not exist\n" );
		exit( 1 );
	}

	echo esc_html( "Table exists: {$table_name}" ) . "\n\n";

	// Step 2: Find duplicate rules
	echo esc_html( "[Step 1/3] Finding duplicate rules...\n" );
	echo esc_html( str_repeat( '-', 60 ) ) . "\n";

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$duplicates = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT model_id, data_type, object_name, url_type, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id) as ids
			FROM %i
			GROUP BY model_id, data_type, object_name, url_type
			HAVING cnt > 1
			ORDER BY cnt DESC',
			$table_name
		),
		ARRAY_A
	);

	$deleted = 0;

	if ( empty( $duplicates ) ) {
		echo esc_html( "No duplicate rules found\n" );
	} else {
		echo esc_html( 'Found ' . count( $duplicates ) . " groups of duplicate rules:\n" ) . "\n";

		$total_duplicates = 0;
		foreach ( $duplicates as $dup ) {
			$ids              = explode( ',', $dup['ids'] );
			$keep_id          = $ids[0];
			$delete_count     = count( $ids ) - 1;
			$total_duplicates += $delete_count;

			echo esc_html( "  - {$dup['object_name']} ({$dup['url_type']}): {$dup['cnt']}  rule(s)\n" );
			echo esc_html( "    Keep: ID {$keep_id}\n" );
			echo esc_html( '    Delete: ' . implode( ', ', array_slice( $ids, 1 ) ) ) . "\n";
		}

		echo "\n" . esc_html( "Total to delete: {$total_duplicates} duplicate rules" ) . "\n\n";
	}

	// Step 3: Delete duplicate rules
	if ( ! empty( $duplicates ) ) {
		echo esc_html( "[Step 2/3] Deleting duplicate rules...\n" );
		echo esc_html( str_repeat( '-', 60 ) ) . "\n";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE t1 FROM %i t1
				INNER JOIN (
					SELECT MIN(id) as min_id, model_id, data_type, object_name, url_type
					FROM %i
					GROUP BY model_id, data_type, object_name, url_type
					HAVING COUNT(*) > 1
				) t2 ON t1.model_id = t2.model_id
					AND t1.data_type = t2.data_type
					AND t1.object_name = t2.object_name
					AND t1.url_type = t2.url_type
					AND t1.id > t2.min_id',
				$table_name,
				$table_name
			)
		);

		if ( false === $deleted ) {
			echo esc_html( 'Delete failed: ' . $wpdb->last_error ) . "\n";
			exit( 1 );
		}

		echo esc_html( "Deleted {$deleted} duplicate rules" ) . "\n\n";
	} else {
		echo esc_html( "[Step 2/3] Skipping deletion (no duplicates)\n" ) . "\n";
	}

	// Step 4: Add unique key constraint
	echo esc_html( "[Step 3/3] Adding unique key constraint...\n" );
	echo esc_html( str_repeat( '-', 60 ) ) . "\n";

	// Check if unique key already exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$index_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, 'unique_rule' )
		);

	if ( $index_exists ) {
		echo esc_html( "Unique key constraint already exists\n" );
	} else {
		$result = wptsall_db_alter_table(
			$table_name,
			'ADD UNIQUE KEY unique_rule (model_id, data_type, object_name, url_type)'
		);

		if ( false === $result ) {
			echo esc_html( 'Failed to add unique key constraint: ' . $wpdb->last_error ) . "\n";
			exit( 1 );
		}

		echo esc_html( "Unique key constraint added: unique_rule (model_id, data_type, object_name, url_type)\n" );
	}

	echo "\n";
	echo esc_html( "╔══════════════════════════════════════════════════════════════╗\n" );
	echo esc_html( "║  Cleanup Complete                                                  ║\n" );
	echo esc_html( "╠══════════════════════════════════════════════════════════════╣\n" );
	if ( ! empty( $duplicates ) ) {
		echo esc_html( '║  Deleted: ' . str_pad( $deleted, 3, ' ', STR_PAD_LEFT ) . " duplicate rules                                       ║\n" );
	}
	echo esc_html( "║  Added: Unique key constraint to prevent future duplicates                              ║\n" );
	echo esc_html( "╚══════════════════════════════════════════════════════════════╝\n" );
	echo "\n";
}

// Allow direct execution via WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	wptsall_migrate_clean_duplicate_rules();
}
