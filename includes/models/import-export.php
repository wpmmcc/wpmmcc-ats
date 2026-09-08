<?php
/**
 * WPTSALL Import/Export Functions
 *
 * Provides import and export functionality for templates, site relations, hooks, and tasks.
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).




/**
 * Build a stable composite key for site relation import de-duplication.
 *
 * @param array $relation Raw relation row.
 * @return string
 */
function wptsall_build_site_relation_import_key( $relation ) {
	return implode(
		'|',
		array(
			(int) ( $relation['source_site_id'] ?? 0 ),
			(string) ( $relation['source_lang'] ?? '' ),
			(string) ( $relation['template'] ?? '' ),
			(string) ( $relation['target_site_type'] ?? 'wp' ),
			(string) ( $relation['target_site_id'] ?? '' ),
			(string) ( $relation['target_lang'] ?? '' ),
		)
	);
}





// =============================================
// H1: Plugin Template (Model) Import/Export v3.0
// =============================================

/**
 * Export a single model with its objects and fields as v3.0 JSON.
 *
 * Reads model basic info, model_objects, and model_object_fields to construct
 * a portable export structure with auto-increment IDs removed.
 *
 * @since 1.4.0
 *
 * @param int $model_id Model ID.
 * @return string|WP_Error JSON string on success, WP_Error on failure.
 */
function wptsall_export_model( $model_id ) {
	global $wpdb;

	$model_id = (int) $model_id;
	if ( $model_id <= 0 ) {
		return new WP_Error( 'invalid_model_id', __( 'Invalid model ID', 'wpmmcc-ats' ) );
	}

	$models_table  = wptsall_table( 'models' );
	$objects_table  = wptsall_table( 'model_objects' );
	$fields_table   = wptsall_table( 'model_object_fields' );

	// 1. Read model basic info.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$model = $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $models_table, $model_id ),
		ARRAY_A
	);

	if ( ! $model ) {
		return new WP_Error( 'not_found', __( 'Model not found', 'wpmmcc-ats' ) );
	}

	// Parse JSON columns for export.
	$model['post_types']    = json_decode( $model['post_types'] ?? '[]', true ) ?: array();
	$model['taxonomies']    = json_decode( $model['taxonomies'] ?? '[]', true ) ?: array();
	$model['meta_fields']   = json_decode( $model['meta_fields'] ?? '[]', true ) ?: array();
	$model['custom_tables'] = json_decode( $model['custom_tables'] ?? '[]', true ) ?: array();
	$model['scan_result']   = json_decode( $model['scan_result'] ?? '{}', true ) ?: array();

	// Build plugin info (subset).
	$plugin_info = array(
		'plugin_slug'    => $model['plugin_slug'],
		'plugin_name'    => $model['plugin_name'],
		'plugin_version' => $model['plugin_version'] ?? '',
		'text_domain'    => $model['text_domain'] ?? '',
	);

	// Build model info (excluding auto-increment ID and timestamps).
	$model_info = array(
		'description'      => $model['description'] ?? '',
		'plugin_file'      => $model['plugin_file'] ?? '',
		'is_content_plugin' => (int) ( $model['is_content_plugin'] ?? 0 ),
		'post_types'       => $model['post_types'],
		'taxonomies'       => $model['taxonomies'],
		'meta_fields'      => $model['meta_fields'],
		'custom_tables'    => $model['custom_tables'],
		'status'           => $model['status'] ?? 'active',
		'usage_status'     => $model['usage_status'] ?? 'unused',
		'source_type'      => $model['source_type'] ?? 'auto',
		'is_system'        => (int) ( $model['is_system'] ?? 0 ),
		'scan_version'     => $model['scan_version'] ?? '',
		'scan_result'      => $model['scan_result'],
		'scan_status'      => $model['scan_status'] ?? 'pending',
		'detection_method' => $model['detection_method'] ?? '',
	);

	// 2. Read model_objects.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$objects = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT * FROM %i WHERE model_id = %d ORDER BY id ASC',
			$objects_table,
			$model_id
		),
		ARRAY_A
	);

	$export_objects = array();
	$export_fields  = array();

	foreach ( $objects as $obj ) {
		$obj_key = $obj['object_type'] . ':' . $obj['object_name'];

		$export_objects[] = array(
			'object_type'   => $obj['object_type'],
			'object_name'   => $obj['object_name'],
			'url_signature' => $obj['url_signature'] ?? null,
			'metadata'      => json_decode( $obj['metadata'] ?? '{}', true ) ?: array(),
			'source_type'   => $obj['source_type'] ?? 'auto',
		);

		// 3. Read model_object_fields for this object.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$fields = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_id = %d ORDER BY id ASC',
				$fields_table,
				(int) $obj['id']
			),
			ARRAY_A
		);

		$export_fields[ $obj_key ] = array();
		foreach ( $fields as $field ) {
			$field_entry = array(
				'field_kind'       => $field['field_kind'],
				'field_key'        => $field['field_key'],
				'source'           => $field['source'] ?? 'scan',
				'data_type'        => $field['data_type'] ?? null,
				'reference_type'   => $field['reference_type'] ?? null,
				'reference_target' => $field['reference_target'] ?? null,
				'usage_count'      => (int) ( $field['usage_count'] ?? 0 ),
				'sample_value'     => $field['sample_value'] ?? null,
				'status'           => $field['status'] ?? 'active',
			);

			// Include extra if present.
			if ( ! empty( $field['extra'] ) ) {
				$decoded_extra = json_decode( $field['extra'], true );
				if ( $decoded_extra ) {
					$field_entry['extra'] = $decoded_extra;
				}
			}

			$export_fields[ $obj_key ][] = $field_entry;
		}
	}

	$export = array(
		'version'     => '3.0',
		'type'        => 'template',
		'exported_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'plugin'      => $plugin_info,
		'model'       => $model_info,
		'objects'     => $export_objects,
		'fields'      => $export_fields,
	);

	return wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Import a model from v3.0 JSON.
 *
 * @since 1.4.0
 *
 * @param string $json   JSON string (v3.0 format).
 * @param string $action Action: 'check', 'overwrite', 'skip', 'duplicate'.
 * @return array|WP_Error Result array on success, WP_Error on failure.
 */
function wptsall_import_model( $json, $action = 'check' ) {
	$data = json_decode( $json, true );

	if ( null === $data || json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error( 'invalid_json', __( 'Invalid JSON format', 'wpmmcc-ats' ) );
	}

	if ( ! isset( $data['version'] ) || '3.0' !== $data['version'] ) {
		return new WP_Error( 'invalid_version', __( 'Unsupported export version. Expected 3.0.', 'wpmmcc-ats' ) );
	}

	if ( ! isset( $data['type'] ) || 'template' !== $data['type'] ) {
		return new WP_Error( 'invalid_type', __( 'Invalid export type. Expected template.', 'wpmmcc-ats' ) );
	}

	if ( empty( $data['plugin']['plugin_slug'] ) ) {
		return new WP_Error( 'missing_plugin_slug', __( 'Missing plugin_slug in export data', 'wpmmcc-ats' ) );
	}

	$plugin_slug = sanitize_key( $data['plugin']['plugin_slug'] );

	// Check for existing model with same plugin_slug.
	global $wpdb;
	$models_table = wptsall_table( 'models' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing = $wpdb->get_row(
		$wpdb->prepare( 'SELECT id, plugin_slug FROM %i WHERE plugin_slug = %s', $models_table, $plugin_slug ),
		ARRAY_A
	);

	$has_conflict = ! empty( $existing );

	// Action: check -- return conflict info without modifying data.
	if ( 'check' === $action ) {
		$result = array(
			'conflict'    => $has_conflict,
			'plugin_slug' => $plugin_slug,
		);

		if ( $has_conflict ) {
			$result['existing_id'] = (int) $existing['id'];
			$result['differences'] = wptsall_diff_model_export( (int) $existing['id'], $data );
		}

		return $result;
	}

	// Action: skip -- do nothing if conflict exists.
	if ( 'skip' === $action ) {
		if ( $has_conflict ) {
			return array(
				'action'  => 'skipped',
				'message' => sprintf(
					/* translators: %s: plugin slug */
					__( 'Model %s already exists, skipped', 'wpmmcc-ats' ),
					$plugin_slug
				),
			);
		}
		// No conflict, fall through to insert.
	}

	// Action: overwrite -- delete existing model (cascade), then insert.
	if ( 'overwrite' === $action && $has_conflict ) {
		$delete_result = wptsall_delete_model_cascade( (int) $existing['id'] );
		if ( is_wp_error( $delete_result ) ) {
			return $delete_result;
		}
	}

	// Action: duplicate -- append suffix to plugin_slug to avoid conflict.
	if ( 'duplicate' === $action && $has_conflict ) {
		$suffix = 1;
		$new_slug = $plugin_slug . '-copy-' . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE plugin_slug = %s', $models_table, $new_slug ) ) ) {
			$suffix++;
			$new_slug = $plugin_slug . '-copy-' . $suffix;
		}
		$data['plugin']['plugin_slug'] = $new_slug;
	}

	// Execute import.
	$import_result = wptsall_insert_model_from_export( $data );
	if ( is_wp_error( $import_result ) ) {
		return $import_result;
	}

	// Post-import structural validation (warning mode, does not block import).
	$validation_warnings = array();
	if ( class_exists( '\\WPTSALL\\Models\\Services\\Template_Validation_Service' ) ) {
		$validator           = new \WPTSALL\Models\Services\Template_Validation_Service();
		$validation_result   = $validator->validate_all( (int) $import_result );
		$validation_warnings = $validation_result['warnings'] ?? array();

		if ( ! empty( $validation_result['errors'] ) ) {
			$validation_warnings = array_merge( $validation_warnings, $validation_result['errors'] );
		}
	}

	$result = array(
		'action'      => $action,
		'success'     => true,
		'model_id'    => $import_result,
		'plugin_slug' => $data['plugin']['plugin_slug'],
	);

	if ( ! empty( $validation_warnings ) ) {
		$result['validation_warnings'] = $validation_warnings;
	}

	return $result;
}

/**
 * Compare an existing model with import data to detect differences.
 *
 * @since 1.4.0
 *
 * @param int   $existing_model_id Existing model ID.
 * @param array $import_data       Parsed import data (v3.0 structure).
 * @return array Differences summary.
 */
function wptsall_diff_model_export( $existing_model_id, $import_data ) {
	global $wpdb;

	$objects_table = wptsall_table( 'model_objects' );
	$fields_table  = wptsall_table( 'model_object_fields' );

	// Count existing objects.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing_object_count = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE model_id = %d', $objects_table, $existing_model_id )
	);

	$import_object_count = count( $import_data['objects'] ?? array() );

	// Count existing fields per object.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing_objects = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, object_type, object_name FROM %i WHERE model_id = %d ORDER BY id ASC',
			$objects_table,
			$existing_model_id
		),
		ARRAY_A
	);

	$existing_field_counts = array();
	foreach ( $existing_objects as $obj ) {
		$obj_key = $obj['object_type'] . ':' . $obj['object_name'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_field_counts[ $obj_key ] = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE object_id = %d', $fields_table, (int) $obj['id'] )
		);
	}

	$import_field_counts = array();
	foreach ( $import_data['fields'] ?? array() as $obj_key => $fields ) {
		$import_field_counts[ $obj_key ] = count( $fields );
	}

	$object_diff = array();
	$all_keys    = array_unique( array_merge( array_keys( $existing_field_counts ), array_keys( $import_field_counts ) ) );

	foreach ( $all_keys as $key ) {
		$existing_fc = $existing_field_counts[ $key ] ?? 0;
		$import_fc   = $import_field_counts[ $key ] ?? 0;

		if ( $existing_fc !== $import_fc || ! isset( $existing_field_counts[ $key ] ) || ! isset( $import_field_counts[ $key ] ) ) {
			$object_diff[ $key ] = array(
				'existing_fields' => $existing_fc,
				'import_fields'   => $import_fc,
			);

			if ( ! isset( $existing_field_counts[ $key ] ) ) {
				$object_diff[ $key ]['status'] = 'new';
			} elseif ( ! isset( $import_field_counts[ $key ] ) ) {
				$object_diff[ $key ]['status'] = 'removed';
			} else {
				$object_diff[ $key ]['status'] = 'changed';
			}
		}
	}

	return array(
		'objects' => array(
			'existing' => $existing_object_count,
			'import'   => $import_object_count,
		),
		'fields'  => $object_diff,
	);
}

/**
 * Delete a model and its child objects/fields (cascade).
 *
 * Deletes in foreign-key order: model_object_fields -> model_objects -> models.
 *
 * @since 1.4.0
 *
 * @param int $model_id Model ID.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function wptsall_delete_model_cascade( $model_id ) {
	global $wpdb;

	$model_id      = (int) $model_id;
	$models_table  = wptsall_table( 'models' );
	$objects_table = wptsall_table( 'model_objects' );
	$fields_table  = wptsall_table( 'model_object_fields' );
	$rules_table   = wptsall_table( 'translation_rules' );

	// 1. Get all object IDs for this model.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$object_ids = $wpdb->get_col(
		$wpdb->prepare( 'SELECT id FROM %i WHERE model_id = %d', $objects_table, $model_id )
	);

	// 2. Delete fields for each object.
	if ( ! empty( $object_ids ) ) {
		foreach ( $object_ids as $object_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $fields_table, array( 'object_id' => (int) $object_id ), array( '%d' ) );
		}
	}

	// 3. Delete objects.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $objects_table, array( 'model_id' => $model_id ), array( '%d' ) );

	// 4. Delete translation rules.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $rules_table, array( 'model_id' => $model_id ), array( '%d' ) );

	// 5. Delete the model itself.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->delete( $models_table, array( 'id' => $model_id ), array( '%d' ) );

	if ( false === $result ) {
		return new WP_Error( 'db_error', __( 'Failed to delete model', 'wpmmcc-ats' ) );
	}

	return true;
}

/**
 * Insert a model from v3.0 export data.
 *
 * Creates model -> objects -> fields in sequence, mapping new IDs.
 *
 * @since 1.4.0
 *
 * @param array $data Parsed v3.0 export data.
 * @return int|WP_Error New model ID on success, WP_Error on failure.
 */
function wptsall_insert_model_from_export( $data ) {
	global $wpdb;

	$models_table  = wptsall_table( 'models' );
	$objects_table = wptsall_table( 'model_objects' );
	$fields_table  = wptsall_table( 'model_object_fields' );

	$plugin = $data['plugin'];
	$model  = $data['model'] ?? array();
	$now    = current_time( 'mysql' );

	// 1. Insert model.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$insert_result = $wpdb->insert(
		$models_table,
		array(
			'plugin_slug'      => sanitize_key( $plugin['plugin_slug'] ),
			'plugin_name'      => sanitize_text_field( $plugin['plugin_name'] ?? $plugin['plugin_slug'] ),
			'plugin_version'   => sanitize_text_field( $plugin['plugin_version'] ?? '' ),
			'text_domain'      => sanitize_key( $plugin['text_domain'] ?? $plugin['plugin_slug'] ),
			'description'      => sanitize_textarea_field( $model['description'] ?? '' ),
			'plugin_file'      => sanitize_text_field( $model['plugin_file'] ?? '' ),
			'is_content_plugin' => (int) ( $model['is_content_plugin'] ?? 0 ),
			'post_types'       => wp_json_encode( $model['post_types'] ?? array() ),
			'taxonomies'       => wp_json_encode( $model['taxonomies'] ?? array() ),
			'meta_fields'      => wp_json_encode( $model['meta_fields'] ?? array() ),
			'custom_tables'    => wp_json_encode( $model['custom_tables'] ?? array() ),
			'status'           => in_array( $model['status'] ?? 'active', array( 'active', 'inactive' ), true ) ? ( $model['status'] ?? 'active' ) : 'active',
			'usage_status'     => in_array( $model['usage_status'] ?? 'unused', array( 'active', 'unused' ), true ) ? ( $model['usage_status'] ?? 'unused' ) : 'unused',
			'source_type'      => sanitize_key( $model['source_type'] ?? 'auto' ),
			'is_system'        => (int) ( $model['is_system'] ?? 0 ),
			'scan_version'     => sanitize_text_field( $model['scan_version'] ?? '' ),
			'scan_result'      => wp_json_encode( $model['scan_result'] ?? array() ),
			'scan_status'      => sanitize_key( $model['scan_status'] ?? 'pending' ),
			'detection_method' => sanitize_text_field( $model['detection_method'] ?? '' ),
			'created_at'       => $now,
			'updated_at'       => $now,
		),
		array(
			'%s', '%s', '%s', '%s', '%s', '%s', '%d',
			'%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
			'%s', '%s',
		)
	);

	if ( false === $insert_result ) {
		return new WP_Error( 'db_error', __( 'Failed to insert model: ', 'wpmmcc-ats' ) . $wpdb->last_error );
	}

	$new_model_id = $wpdb->insert_id;

	// 2. Insert objects and collect ID mapping.
	$objects = $data['objects'] ?? array();
	$fields  = $data['fields'] ?? array();

	foreach ( $objects as $obj ) {
		$obj_key = $obj['object_type'] . ':' . $obj['object_name'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$obj_result = $wpdb->insert(
			$objects_table,
			array(
				'model_id'      => $new_model_id,
				'object_type'   => sanitize_key( $obj['object_type'] ),
				'object_name'   => sanitize_key( $obj['object_name'] ),
				'url_signature' => isset( $obj['url_signature'] ) ? sanitize_text_field( $obj['url_signature'] ) : null,
				'metadata'      => wp_json_encode( $obj['metadata'] ?? array() ),
				'source_type'   => sanitize_key( $obj['source_type'] ?? 'auto' ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $obj_result ) {
			continue;
		}

		$new_object_id = $wpdb->insert_id;

		// 3. Insert fields for this object.
		if ( isset( $fields[ $obj_key ] ) && is_array( $fields[ $obj_key ] ) ) {
			foreach ( $fields[ $obj_key ] as $field ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$fields_table,
					array(
						'object_id'        => $new_object_id,
						'field_kind'       => sanitize_key( $field['field_kind'] ),
						'field_key'        => sanitize_text_field( $field['field_key'] ),
						'source'           => sanitize_key( $field['source'] ?? 'scan' ),
						'data_type'        => isset( $field['data_type'] ) ? sanitize_key( $field['data_type'] ) : null,
						'reference_type'   => isset( $field['reference_type'] ) ? sanitize_key( $field['reference_type'] ) : null,
						'reference_target' => isset( $field['reference_target'] ) ? sanitize_text_field( $field['reference_target'] ) : null,
						'usage_count'      => (int) ( $field['usage_count'] ?? 0 ),
						'sample_value'     => isset( $field['sample_value'] ) ? sanitize_text_field( $field['sample_value'] ) : null,
						'status'           => sanitize_key( $field['status'] ?? 'active' ),
						'extra'            => isset( $field['extra'] ) ? wp_json_encode( $field['extra'] ) : null,
						'created_at'       => $now,
						'updated_at'       => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}
	}

	return $new_model_id;
}

// =============================================
// H2: Translation Rule Import/Export v3.0
// =============================================

/**
 * Export a single translation rule as v3.0 JSON.
 *
 * @since 1.4.0
 *
 * @param int $rule_id Rule ID.
 * @return string|WP_Error JSON string on success, WP_Error on failure.
 */
function wptsall_export_rule( $rule_id ) {
	$rule_id = (int) $rule_id;
	if ( $rule_id <= 0 ) {
		return new WP_Error( 'invalid_rule_id', __( 'Invalid rule ID', 'wpmmcc-ats' ) );
	}

	if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
		return new WP_Error( 'service_unavailable', __( 'Translation Rule Service not available', 'wpmmcc-ats' ) );
	}

	$rule = \WPTSALL\Models\Services\Translation_Rule_Service::get_rule( $rule_id );

	if ( ! $rule ) {
		return new WP_Error( 'not_found', __( 'Rule not found', 'wpmmcc-ats' ) );
	}

	// field_capabilities is already decoded by get_rule().
	$field_capabilities = $rule['field_capabilities'] ?? array();
	$model              = \WPTSALL\Models\Services\Translation_Rule_Service::get_model( (int) ( $rule['model_id'] ?? 0 ) );
	$template_ref       = array(
		'plugin_slug' => sanitize_key( (string) ( $model['plugin_slug'] ?? '' ) ),
	);
	$source_semantics_snapshot = wptsall_build_rule_source_semantics_snapshot( $rule );

	// Build rule metadata (exclude auto-increment IDs and timestamps).
	$rule_meta = array(
		'name'               => $rule['name'] ?? '',
		'data_type'          => $rule['data_type'] ?? '',
		'object_name'        => $rule['object_name'] ?? '',
		'url_type'           => $rule['url_type'] ?? '',
		'url_pattern'        => $rule['url_pattern'] ?? '',
		'priority'           => (int) ( $rule['priority'] ?? 10 ),
		'is_active'          => (int) ( $rule['is_active'] ?? 1 ),
		'related_taxonomies' => $rule['related_taxonomies'] ?? array(),
	);

	$export = array(
		'version'                   => '3.0',
		'type'                      => 'rule',
		'exported_at'               => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'template_ref'              => $template_ref,
		'rule'                      => $rule_meta,
		'field_capabilities'        => $field_capabilities,
		'source_semantics_snapshot' => $source_semantics_snapshot,
	);

	return wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Build a declaration snapshot for rule source semantics.
 *
 * This is informational export metadata only. Import still relies on the
 * target model object's field declarations and field capabilities.
 *
 * @param array $rule Rule row.
 * @return array
 */
function wptsall_build_rule_source_semantics_snapshot( array $rule ) {
	$empty = array(
		'declaration_source' => 'model_object_fields.extra',
		'source_group'       => '',
		'routing_profile'    => '',
		'delivery_target'    => '',
		'field_source_roles' => array(),
	);

	if ( ! class_exists( '\WPTSALL\Models\Services\Model_Object_Service' ) ) {
		return $empty;
	}

	$model_id    = (int) ( $rule['model_id'] ?? 0 );
	$object_name = sanitize_key( (string) ( $rule['object_name'] ?? '' ) );
	$object_type = wptsall_map_rule_data_type_to_model_object_type( (string) ( $rule['data_type'] ?? '' ) );

	if ( $model_id <= 0 || '' === $object_name || '' === $object_type ) {
		return $empty;
	}

	$object = \WPTSALL\Models\Services\Model_Object_Service::get_object_by_type( $model_id, $object_type, $object_name );
	if ( empty( $object['id'] ) ) {
		return $empty;
	}

	$snapshot = $empty;
	$fields   = \WPTSALL\Models\Services\Model_Object_Service::get_fields_for_object( (int) $object['id'] );
	foreach ( $fields as $field ) {
		$field_key = sanitize_text_field( (string) ( $field['field_key'] ?? '' ) );
		if ( '' === $field_key ) {
			continue;
		}

		$semantic = wptsall_extract_field_semantic_declaration( $field );
		if ( '' === $snapshot['source_group'] && '' !== $semantic['source_group'] ) {
			$snapshot['source_group'] = $semantic['source_group'];
		}
		if ( '' === $snapshot['routing_profile'] && '' !== $semantic['routing_profile'] ) {
			$snapshot['routing_profile'] = $semantic['routing_profile'];
		}
		if ( '' === $snapshot['delivery_target'] && '' !== $semantic['delivery_target'] ) {
			$snapshot['delivery_target'] = $semantic['delivery_target'];
		}
		if ( '' !== $semantic['source_role'] ) {
			$snapshot['field_source_roles'][ $field_key ] = $semantic['source_role'];
		}
	}

	return $snapshot;
}

/**
 * Extract normalized semantic declaration from one model field row.
 *
 * @param array $field Field row.
 * @return array
 */
function wptsall_extract_field_semantic_declaration( array $field ) {
	$extra = is_array( $field['extra'] ?? null ) ? $field['extra'] : array();
	$meta  = is_array( $extra['source_semantics'] ?? null ) ? $extra['source_semantics'] : array();
	$meta  = array_merge(
		array(
			'source_group'    => '',
			'routing_profile' => '',
			'delivery_target' => '',
			'source_role'     => '',
		),
		$meta
	);

	foreach ( array( 'source_group', 'routing_profile', 'delivery_target', 'source_role' ) as $key ) {
		if ( '' === (string) ( $meta[ $key ] ?? '' ) && array_key_exists( $key, $extra ) ) {
			$meta[ $key ] = $extra[ $key ];
		}
	}

	if ( '' === (string) ( $meta['routing_profile'] ?? '' ) && array_key_exists( 'source_profile', $extra ) ) {
		$meta['routing_profile'] = $extra['source_profile'];
	}

	if ( '' === (string) ( $meta['source_role'] ?? '' ) && array_key_exists( 'message_template_part', $extra ) ) {
		$part = sanitize_key( (string) $extra['message_template_part'] );
		if ( in_array( $part, array( 'subject', 'heading', 'body' ), true ) ) {
			$meta['source_role'] = 'message_' . $part;
		}
	}

	return array(
		'source_group'    => sanitize_key( (string) ( $meta['source_group'] ?? '' ) ),
		'routing_profile' => sanitize_key( (string) ( $meta['routing_profile'] ?? '' ) ),
		'delivery_target' => sanitize_key( (string) ( $meta['delivery_target'] ?? '' ) ),
		'source_role'     => sanitize_key( (string) ( $meta['source_role'] ?? '' ) ),
	);
}

/**
 * Map rule data type to model object type.
 *
 * @param string $data_type Rule data type.
 * @return string
 */
function wptsall_map_rule_data_type_to_model_object_type( $data_type ) {
	switch ( sanitize_key( (string) $data_type ) ) {
		case 'post':
			return 'post_type';
		case 'term':
			return 'taxonomy';
		case 'user':
			return 'user';
		case 'comment':
			return 'comment';
		case 'option':
			return 'option';
		default:
			return '';
	}
}

/**
 * Import a translation rule from v3.0 JSON.
 *
 * @since 1.4.0
 *
 * @param string $json     JSON string (v3.0 format).
 * @param int    $model_id Target model ID.
 * @param string $action      Action: 'check', 'overwrite', 'skip', 'duplicate'.
 * @param string $plugin_slug Optional plugin slug for model lookup/validation.
 * @return array|WP_Error Result array on success, WP_Error on failure.
 */
function wptsall_import_rule( $json, $model_id, $action = 'check', $plugin_slug = '' ) {
	$data = json_decode( $json, true );

	if ( null === $data || json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error( 'invalid_json', __( 'Invalid JSON format', 'wpmmcc-ats' ) );
	}

	if ( ! isset( $data['version'] ) || '3.0' !== $data['version'] ) {
		return new WP_Error( 'invalid_version', __( 'Unsupported export version. Expected 3.0.', 'wpmmcc-ats' ) );
	}

	if ( ! isset( $data['type'] ) || 'rule' !== $data['type'] ) {
		return new WP_Error( 'invalid_type', __( 'Invalid export type. Expected rule.', 'wpmmcc-ats' ) );
	}

	if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
		return new WP_Error( 'service_unavailable', __( 'Translation Rule Service not available', 'wpmmcc-ats' ) );
	}

	$model_id             = (int) $model_id;
	$requested_slug       = sanitize_key( (string) $plugin_slug );
	$template_ref         = is_array( $data['template_ref'] ?? null ) ? $data['template_ref'] : array();
	$template_plugin_slug = sanitize_key( (string) ( $template_ref['plugin_slug'] ?? '' ) );

	if ( $model_id <= 0 ) {
		$lookup_slug = '' !== $requested_slug ? $requested_slug : $template_plugin_slug;
		if ( '' === $lookup_slug ) {
			return new WP_Error( 'invalid_model_id', __( 'Invalid model ID or plugin_slug', 'wpmmcc-ats' ) );
		}

		$model = \WPTSALL\Models\Services\Translation_Rule_Service::get_model_by_plugin_slug( $lookup_slug );
		if ( ! $model ) {
			return new WP_Error(
				'model_not_found',
				sprintf(
					/* translators: %s: plugin slug */
					__( 'Model not found for plugin_slug "%s"', 'wpmmcc-ats' ),
					$lookup_slug
				)
			);
		}
		$model_id = (int) $model['id'];
	} else {
		$model = \WPTSALL\Models\Services\Translation_Rule_Service::get_model( $model_id );
		if ( ! $model ) {
			return new WP_Error( 'invalid_model_id', __( 'Invalid model ID', 'wpmmcc-ats' ) );
		}

		$expected_slug = '' !== $requested_slug ? $requested_slug : $template_plugin_slug;
		if ( '' !== $expected_slug ) {
			$actual_slug = sanitize_key( (string) ( $model['plugin_slug'] ?? '' ) );
			if ( $actual_slug !== $expected_slug ) {
				return new WP_Error(
					'model_slug_mismatch',
					sprintf(
						/* translators: 1: expected plugin slug, 2: actual plugin slug */
						__( 'Target model plugin_slug mismatch (expected %1$s, got %2$s).', 'wpmmcc-ats' ),
						$expected_slug,
						$actual_slug
					)
				);
			}
		}
	}

	$rule_data = $data['rule'] ?? array();
	$data_type   = sanitize_key( $rule_data['data_type'] ?? '' );
	$object_name = sanitize_key( $rule_data['object_name'] ?? '' );
	$url_type    = sanitize_key( $rule_data['url_type'] ?? '' );

	if ( empty( $data_type ) || empty( $object_name ) ) {
		return new WP_Error( 'missing_fields', __( 'Missing data_type or object_name in rule data', 'wpmmcc-ats' ) );
	}

	// Strong check: target model must already contain the corresponding template object.
	$object_type = wptsall_rule_data_type_to_object_type( $data_type );
	if ( '' !== $object_type && class_exists( '\\WPTSALL\\Models\\Services\\Model_Object_Service' ) ) {
		$template_object = \WPTSALL\Models\Services\Model_Object_Service::get_object_by_type( $model_id, $object_type, $object_name );
		if ( ! $template_object ) {
			return new WP_Error(
				'missing_template_object',
				sprintf(
					/* translators: 1: object type, 2: object name */
					__( 'Cannot import rule: template object %1$s/%2$s does not exist in target model.', 'wpmmcc-ats' ),
					$object_type,
					$object_name
				),
				array(
					'model_id'      => $model_id,
					'object_type'   => $object_type,
					'object_name'   => $object_name,
					'plugin_slug'   => sanitize_key( (string) ( $model['plugin_slug'] ?? '' ) ),
				)
			);
		}
	}

	// Check for existing rule with same (model_id, data_type, object_name, url_type).
	$existing_rules = \WPTSALL\Models\Services\Translation_Rule_Service::get_model_rules( $model_id );
	$existing_rule  = null;

	foreach ( $existing_rules as $r ) {
		if ( $r['data_type'] === $data_type && $r['object_name'] === $object_name && sanitize_key( (string) ( $r['url_type'] ?? '' ) ) === $url_type ) {
			$existing_rule = $r;
			break;
		}
	}

	$has_conflict = ! empty( $existing_rule );

	// Action: check -- return conflict info without modifying data.
	if ( 'check' === $action ) {
		$result = array(
			'conflict'    => $has_conflict,
			'data_type'   => $data_type,
			'object_name' => $object_name,
			'url_type'    => $url_type,
		);

		if ( $has_conflict ) {
			$result['existing_id'] = (int) $existing_rule['id'];
			$result['differences'] = wptsall_diff_rule_export( $existing_rule, $data );
		}

		return $result;
	}

	// Action: skip -- do nothing if conflict exists.
	if ( 'skip' === $action ) {
		if ( $has_conflict ) {
			return array(
				'action'  => 'skipped',
				'message' => sprintf(
					/* translators: 1: data type, 2: object name, 3: url_type */
					__( 'Rule for %1$s/%2$s (%3$s) already exists, skipped', 'wpmmcc-ats' ),
					$data_type,
					$object_name,
					$url_type
				),
			);
		}
	}

	// Action: overwrite -- delete existing rule, then create new.
	if ( 'overwrite' === $action && $has_conflict ) {
		\WPTSALL\Models\Services\Translation_Rule_Service::delete_rule( (int) $existing_rule['id'] );
	}

	// Action: duplicate -- modify object_name to avoid conflict.
	if ( 'duplicate' === $action && $has_conflict ) {
		$suffix   = 1;
		$new_name = $object_name . '_copy_' . $suffix;
		$still_conflict = true;

		while ( $still_conflict ) {
			$still_conflict = false;
			foreach ( $existing_rules as $r ) {
				if ( $r['data_type'] === $data_type && $r['object_name'] === $new_name && sanitize_key( (string) ( $r['url_type'] ?? '' ) ) === $url_type ) {
					$still_conflict = true;
					$suffix++;
					$new_name = $object_name . '_copy_' . $suffix;
					break;
				}
			}
		}

		$rule_data['object_name'] = $new_name;
		$object_name              = $new_name;
	}

	// Build creation data.
	$create_data = array(
		'name'               => sanitize_text_field( $rule_data['name'] ?? '' ),
		'data_type'          => $data_type,
		'object_name'        => sanitize_key( $rule_data['object_name'] ?? $object_name ),
		'url_type'           => $url_type,
		'url_pattern'        => sanitize_text_field( $rule_data['url_pattern'] ?? '' ),
		'priority'           => (int) ( $rule_data['priority'] ?? 10 ),
		'is_active'          => (int) ( $rule_data['is_active'] ?? 1 ),
		'field_capabilities' => $data['field_capabilities'] ?? array(),
		'related_taxonomies' => $rule_data['related_taxonomies'] ?? array(),
	);

	// Normalize field_capabilities before creating the rule.
	if ( ! empty( $create_data['field_capabilities'] ) && is_array( $create_data['field_capabilities'] ) ) {
		$create_data['field_capabilities'] = \WPTSALL\Models\Services\Translation_Rule_Service::normalize_field_capabilities( $create_data['field_capabilities'] );
	}

	$result = \WPTSALL\Models\Services\Translation_Rule_Service::create_rule( $model_id, $create_data );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$response = array(
		'action'      => $action,
		'success'     => true,
		'rule_id'     => (int) $result,
		'model_id'    => (int) $model_id,
		'plugin_slug' => sanitize_key( (string) ( $model['plugin_slug'] ?? '' ) ),
		'data_type'   => $data_type,
		'object_name' => $object_name,
		'url_type'    => $url_type,
	);

	// H2: Post-import validation (warning mode — never blocks import).
	try {
		if ( class_exists( '\WPTSALL\Models\Services\Simulation_Validator' ) ) {
			$validator  = new \WPTSALL\Models\Services\Simulation_Validator();
			$validation = $validator->validate_rule( (int) $result );

			$response['validation'] = $validation;
		}
	} catch ( \Exception $e ) {
		// Never let validation errors block the import.
		$response['validation'] = null;
	}

	return $response;
}

/**
 * Map translation rule data_type to model object_type.
 *
 * @since 1.6.0
 *
 * @param string $data_type Rule data type.
 * @return string Object type or empty string.
 */
function wptsall_rule_data_type_to_object_type( $data_type ) {
	$data_type = sanitize_key( (string) $data_type );

	switch ( $data_type ) {
		case 'post':
			return 'post_type';
		case 'term':
		case 'taxonomy':
			return 'taxonomy';
		case 'custom_table':
			return 'custom_table';
		default:
			return '';
	}
}

/**
 * Compare an existing rule with import data to detect differences.
 *
 * @since 1.4.0
 *
 * @param array $existing_rule Existing rule data (from get_rule()).
 * @param array $import_data   Parsed import data (v3.0 structure).
 * @return array Differences summary.
 */
function wptsall_diff_rule_export( $existing_rule, $import_data ) {
	$existing_caps = $existing_rule['field_capabilities'] ?? array();
	$import_caps   = $import_data['field_capabilities'] ?? array();

	$existing_field_names = array_keys( $existing_caps );
	$import_field_names   = array_keys( $import_caps );

	$added   = array_diff( $import_field_names, $existing_field_names );
	$removed = array_diff( $existing_field_names, $import_field_names );
	$common  = array_intersect( $existing_field_names, $import_field_names );

	$type_changes = array();
	foreach ( $common as $field_name ) {
		$existing_type = $existing_caps[ $field_name ]['type'] ?? '';
		$import_type   = $import_caps[ $field_name ]['type'] ?? '';

		if ( $existing_type !== $import_type ) {
			$type_changes[ $field_name ] = array(
				'existing' => $existing_type,
				'import'   => $import_type,
			);
		}
	}

	return array(
		'field_count' => array(
			'existing' => count( $existing_field_names ),
			'import'   => count( $import_field_names ),
		),
		'added'        => array_values( $added ),
		'removed'      => array_values( $removed ),
		'type_changes' => $type_changes,
	);
}


