<?php
/**
 * Migrate manual fields from models.meta_fields to model_object_fields (ISS-MOD-027)
 *
 * Moves manual field entries stored in the models.meta_fields JSON column
 * into the model_object_fields table with source='manual'.
 *
 * Can be run manually:
 *   wp eval 'require_once "wp-content/plugins/wpmmcc-ats/includes/models/database/migrate-manual-fields-to-object-fields.php"; wptsall_migrate_manual_fields_to_object_fields();'
 *
 * Or automatically via the migration chain (v1.0.3).
 *
 * Output contract: CLI lines go through wptsall_migration_cli_line() below.
 * Never bare-echo from here — `wp plugin activate` runs with WP_CLI defined,
 * and core's activate_plugin() buffers the activation window and fails with
 * `unexpected_output` if any PHP output is produced inside it (2026-09-08
 * public plugin-check CI failure). The file also must not self-execute on
 * include; the migration chain (includes/core/database/migrations.php) is the
 * only automatic runner.
 *
 * @package WPTSALL\Models\Database
 * @since 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

/**
 * Print one CLI migration line without polluting PHP output buffers.
 *
 * WP_CLI::log() writes to the process stdout directly (bypasses ob_*),
 * so banners stay visible for manual runs while remaining invisible to
 * core's activate_plugin() output check.
 *
 * @param string $line Message text (without trailing newline).
 * @return void
 */
function wptsall_migration_cli_line( $line ) {
	if ( class_exists( '\WP_CLI' ) ) {
		\WP_CLI::log( esc_html( $line ) );
	} else {
		echo esc_html( $line ) . "\n";
	}
}

/**
 * Run the manual fields migration.
 *
 * @return array Migration stats.
 */
function wptsall_migrate_manual_fields_to_object_fields() {
	global $wpdb;

	$models_table = wptsall_table( 'models' );
	$is_cli       = defined( 'WP_CLI' ) && WP_CLI;

	$stats = array(
		'models_scanned'  => 0,
		'models_migrated' => 0,
		'fields_migrated' => 0,
		'fields_skipped'  => 0,
	);

	if ( $is_cli ) {
		wptsall_migration_cli_line( '' );
		wptsall_migration_cli_line( 'ISS-MOD-027: Migrating manual fields from meta_fields → model_object_fields' );
		wptsall_migration_cli_line( str_repeat( '-', 60 ) );
	}

	// Check required service classes.
	if ( ! class_exists( '\WPTSALL\Models\Services\Model_Object_Service' ) ) {
		$service_path = dirname( __DIR__ ) . '/services/class-model-object-service.php';
		if ( file_exists( $service_path ) ) {
			require_once $service_path;
		} else {
			if ( $is_cli ) {
				wptsall_migration_cli_line( 'Error: Model_Object_Service not found' );
			}
			return $stats;
		}
	}

	// Get all models with non-empty meta_fields.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$models = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, plugin_slug, meta_fields FROM %i WHERE meta_fields IS NOT NULL AND meta_fields != %s AND meta_fields != %s",
			$models_table,
			'',
			'[]'
		),
		ARRAY_A
	);

	if ( empty( $models ) ) {
		if ( $is_cli ) {
			wptsall_migration_cli_line( 'No models with meta_fields found. Nothing to migrate.' );
		}

		if ( function_exists( 'wptsall_log_info' ) ) {
			wptsall_log_info( 'migration', 'ISS-MOD-027: No manual fields to migrate' );
		}

		return $stats;
	}

	foreach ( $models as $model_row ) {
		++$stats['models_scanned'];

		$model_id    = (int) $model_row['id'];
		$plugin_slug = $model_row['plugin_slug'];
		$meta_fields = json_decode( $model_row['meta_fields'] ?? '[]', true );

		if ( ! is_array( $meta_fields ) || empty( $meta_fields ) ) {
			continue;
		}

		// Filter to only manual fields.
		$manual_fields = array_filter(
			$meta_fields,
			function ( $f ) {
				if ( ! is_array( $f ) ) {
					return false;
				}
				// New structure (has field_name) is treated as manual config.
				if ( isset( $f['field_name'] ) ) {
					return true;
				}
				// Old structure: only migrate explicitly marked manual entries.
				return 'manual' === ( $f['source'] ?? '' );
			}
		);

		if ( empty( $manual_fields ) ) {
			continue;
		}

		// Normalize to the format expected by save_manual_fields.
		$normalized = array();
		foreach ( $manual_fields as $field ) {
			if ( isset( $field['field_name'] ) ) {
				// New structure: { table_name, field_name, associated_id_map, description }.
				$normalized[] = array(
					'table_name'       => $field['table_name'] ?? '',
					'field_name'       => $field['field_name'] ?? '',
					'associated_id_map' => $field['associated_id_map'] ?? '',
					'description'      => $field['description'] ?? '',
				);
			} else {
				// Old structure: { meta_key, object_type, object_subtype, source }.
				$meta_key = $field['meta_key'] ?? '';
				if ( '' === $meta_key ) {
					++$stats['fields_skipped'];
					continue;
				}

				// Best-effort mapping: determine table from object_type.
				$obj_type = $field['object_type'] ?? 'post';
				$table_name = '';
				$id_map = '';

				if ( 'post' === $obj_type ) {
					$table_name = $wpdb->postmeta;
					$id_map     = 'post_id';
				} elseif ( 'term' === $obj_type ) {
					$table_name = $wpdb->termmeta;
					$id_map     = 'term_id';
				} elseif ( 'user' === $obj_type ) {
					$table_name = $wpdb->usermeta;
					$id_map     = 'user_id';
				}

				if ( '' === $table_name ) {
					++$stats['fields_skipped'];
					continue;
				}

				$normalized[] = array(
					'table_name'       => $table_name,
					'field_name'       => $meta_key,
					'associated_id_map' => $id_map,
					'description'      => $field['description'] ?? '',
				);
			}
		}

		if ( empty( $normalized ) ) {
			continue;
		}

		$result = \WPTSALL\Models\Services\Model_Object_Service::save_manual_fields(
			$model_id,
			$normalized
		);

		$stats['fields_migrated'] += $result['saved'];
		$stats['fields_skipped']  += $result['skipped'];
		++$stats['models_migrated'];

		if ( $is_cli ) {
			wptsall_migration_cli_line( "  {$plugin_slug}: {$result['saved']} fields migrated, {$result['skipped']} skipped" );
		}
	}

	if ( $is_cli ) {
		wptsall_migration_cli_line( '' );
		wptsall_migration_cli_line( 'Migration complete:' );
		wptsall_migration_cli_line( "  Models scanned:  {$stats['models_scanned']}" );
		wptsall_migration_cli_line( "  Models migrated: {$stats['models_migrated']}" );
		wptsall_migration_cli_line( "  Fields migrated: {$stats['fields_migrated']}" );
		wptsall_migration_cli_line( "  Fields skipped:  {$stats['fields_skipped']}" );
		wptsall_migration_cli_line( '' );
	}

	if ( function_exists( 'wptsall_log_info' ) ) {
		wptsall_log_info( 'migration', 'ISS-MOD-027: Manual fields migration completed', $stats );
	}

	return $stats;
}
