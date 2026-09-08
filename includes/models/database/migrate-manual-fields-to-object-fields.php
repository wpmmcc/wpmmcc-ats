<?php
/**
 * Migrate manual fields from models.meta_fields to model_object_fields (ISS-MOD-027)
 *
 * Moves manual field entries stored in the models.meta_fields JSON column
 * into the model_object_fields table with source='manual'.
 *
 * Can be run manually:
 *   wp eval-file includes/models/database/migrate-manual-fields-to-object-fields.php
 *
 * Or automatically via the migration chain (v1.0.3).
 *
 * @package WPTSALL\Models\Database
 * @since 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
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
		echo "\n";
		echo esc_html( "ISS-MOD-027: Migrating manual fields from meta_fields → model_object_fields\n" );
		echo esc_html( str_repeat( '-', 60 ) ) . "\n";
	}

	// Check required service classes.
	if ( ! class_exists( '\WPTSALL\Models\Services\Model_Object_Service' ) ) {
		$service_path = dirname( __DIR__ ) . '/services/class-model-object-service.php';
		if ( file_exists( $service_path ) ) {
			require_once $service_path;
		} else {
			if ( $is_cli ) {
				echo "Error: Model_Object_Service not found\n";
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
			echo "No models with meta_fields found. Nothing to migrate.\n";
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
			echo esc_html( "  {$plugin_slug}: {$result['saved']} fields migrated, {$result['skipped']} skipped\n" );
		}
	}

	if ( $is_cli ) {
		echo "\n";
		echo esc_html( "Migration complete:\n" );
		echo esc_html( "  Models scanned:  {$stats['models_scanned']}\n" );
		echo esc_html( "  Models migrated: {$stats['models_migrated']}\n" );
		echo esc_html( "  Fields migrated: {$stats['fields_migrated']}\n" );
		echo esc_html( "  Fields skipped:  {$stats['fields_skipped']}\n" );
		echo "\n";
	}

	if ( function_exists( 'wptsall_log_info' ) ) {
		wptsall_log_info( 'migration', 'ISS-MOD-027: Manual fields migration completed', $stats );
	}

	return $stats;
}

// Allow direct execution via WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	wptsall_migrate_manual_fields_to_object_fields();
}
