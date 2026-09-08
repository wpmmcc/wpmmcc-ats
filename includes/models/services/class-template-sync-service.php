<?php
/**
 * Template Sync Service
 *
 * Syncs scan_result JSON to SSOT tables (model_objects + model_object_fields).
 * Provides incremental diff mechanism to track field changes across scans.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\Models\Services;

use WPTSALL\Models\Services\Simulation_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Sync_Service {

	/**
	 * Sync scan_result to model_objects + model_object_fields (SSOT).
	 *
	 * Performs incremental diff for each discovered object, persisting:
	 * - Added fields: inserted with status='new', discovered_at=now
	 * - Orphan fields: status updated to 'orphan' (never deleted)
	 * - Type changed fields: logged via wptsall_log_info, data_type updated,
	 *   history stored in extra column
	 * - Full mode only: usage_count and sample_value refreshed for unchanged fields
	 *
	 * @since 1.2.0
	 *
	 * @param int    $model_id    Model ID.
	 * @param array  $scan_result Scanner output (post_types/taxonomies/custom_tables/rules_summary).
	 * @param string $mode        'full' (trigger A: full re-scan) or 'incremental' (trigger B/C).
	 * @return array Diff result { added: [], orphan: [], unchanged: int, type_changed: [] }.
	 */
	public function sync_scan_to_objects( int $model_id, array $scan_result, string $mode = 'incremental' ): array {
		$diff = array(
			'added'        => array(),
			'orphan'       => array(),
			'unchanged'    => 0,
			'type_changed' => array(),
		);

		// E4: Pre-validation gate — block on structural errors before persisting.
		$pre_errors = $this->pre_validate_scan_result( $model_id, $scan_result );
		if ( ! empty( $pre_errors ) ) {
			if ( function_exists( 'wptsall_log_error' ) ) {
				wptsall_log_error( 'models-sync', 'Scan result blocked by pre-validation', array(
					'model_id' => $model_id,
					'errors'   => $pre_errors,
				) );
			}
			$diff['validation'] = array(
				'valid'    => false,
				'errors'   => $pre_errors,
				'warnings' => array(),
				'blocked'  => true,
			);
			return $diff;
		}

		// 1. Parse objects from scan_result (post_types + taxonomies + custom_tables)
		$discovered_objects = $this->parse_objects_from_scan( $scan_result );

		// 2. For each object: upsert model_objects row
		foreach ( $discovered_objects as $obj ) {
			$object_id = Model_Object_Service::create_object(
				$model_id,
				$obj['object_type'],
				$obj['object_name'],
				array(
					'url_signature' => $obj['url_signature'] ?? null,
					'metadata'      => $obj['metadata'] ?? null,
					'source_type'   => 'auto',
				)
			);

			if ( ! $object_id ) {
				continue;
			}

			// 3. Parse fields for this object
			$discovered_fields = $this->parse_fields_for_object( $scan_result, $obj );

			// 4. Diff with existing model_object_fields
			$field_diff = $this->detect_field_changes( $object_id, $discovered_fields );

			// 5. Persist added fields (status='new', discovered_at=now).
			foreach ( $field_diff['added'] as $field ) {
				Model_Object_Service::insert_field( $object_id, array(
					'field_kind'       => $field['field_kind'],
					'field_key'        => $field['field_key'],
					'source'           => 'scan',
					'data_type'        => $field['data_type'] ?? null,
					'reference_type'   => $field['reference_type'] ?? null,
					'reference_target' => $field['reference_target'] ?? null,
					'usage_count'      => $field['usage_count'] ?? 0,
					'sample_value'     => $field['sample_value'] ?? null,
					'status'           => 'new',
					'discovered_at'    => current_time( 'mysql' ),
				) );
			}

			// 6. Orphan fields: mark status='orphan' (don't delete).
			foreach ( $field_diff['orphan'] as $field ) {
				Model_Object_Service::update_field_status( (int) $field['id'], 'orphan' );
			}

			// 7. Type changed fields: log each change, update data_type, store history.
			foreach ( $field_diff['type_changed'] as $change ) {
				$field_id = (int) ( $change['id'] ?? 0 );

				// Log the type change.
				if ( function_exists( 'wptsall_log_info' ) ) {
					wptsall_log_info(
						'models-sync',
						'Field data_type changed',
						array(
							'field_key'  => $change['field_key'] ?? '',
							'field_kind' => $change['field_kind'] ?? '',
							'old_type'   => $change['old_type'] ?? null,
							'new_type'   => $change['new_type'] ?? null,
							'object_id'  => $object_id,
							'model_id'   => $model_id,
						)
					);
				}

				// Update the data_type in the DB and store history in extra.
				if ( $field_id > 0 ) {
					$this->persist_type_change( $field_id, $change );
				}
			}

			// 8. Full mode: update usage_count and sample_value for unchanged fields.
			if ( 'full' === $mode ) {
				foreach ( $field_diff['unchanged'] as $field ) {
					Model_Object_Service::update_field_stats(
						(int) $field['id'],
						$field['new_usage_count'] ?? 0,
						$field['new_sample_value'] ?? null
					);
				}
			}

			// Accumulate diff across all objects.
			$diff['added']        = array_merge( $diff['added'], $field_diff['added'] );
			$diff['orphan']       = array_merge( $diff['orphan'], $field_diff['orphan'] );
			$diff['type_changed'] = array_merge( $diff['type_changed'], $field_diff['type_changed'] );
			$diff['unchanged']   += count( $field_diff['unchanged'] );
		}

		// E4: Post-save template validation gate.
		// Run Simulation_Validator on persisted data to detect quality issues.
		// This is informational — warnings are logged but do not block the save.
		$validator  = new Simulation_Validator();
		$validation = $validator->validate_template( $model_id );

		if ( ! $validation['valid'] ) {
			if ( function_exists( 'wptsall_log_warning' ) ) {
				wptsall_log_warning( 'models-sync', 'Template validation has warnings after sync', array(
					'model_id' => $model_id,
					'errors'   => $validation['errors'],
				) );
			}
		}

		// Include validation result for caller consumption.
		$diff['validation'] = $validation;

		return $diff;
	}

	/**
	 * Incremental diff: existing model_object_fields vs new scan results.
	 *
	 * Compares existing fields in model_object_fields with newly discovered fields
	 * from a scan and categorizes them:
	 *
	 * - added: fields in new scan not present in existing data
	 * - orphan: scan-sourced fields in existing data not in new scan (manual/derived/core
	 *   fields are NEVER orphaned)
	 * - type_changed: fields where data_type differs between old and new
	 * - unchanged: fields present in both (with new_usage_count and new_sample_value merged).
	 *   Note: type_changed fields are also included in unchanged so their stats get updated.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $object_id  model_objects.id.
	 * @param array $new_fields Newly discovered fields.
	 * @return array { added: [], orphan: [], unchanged: [], type_changed: [] }.
	 */
	public function detect_field_changes( int $object_id, array $new_fields ): array {
		$existing     = Model_Object_Service::get_fields_for_object( $object_id );
		$existing_map = array();
		foreach ( $existing as $f ) {
			$key = ( $f['field_kind'] ?? '' ) . ':' . ( $f['field_key'] ?? '' );
			$existing_map[ $key ] = $f;
		}

		$result = array(
			'added'        => array(),
			'orphan'       => array(),
			'unchanged'    => array(),
			'type_changed' => array(),
		);

		$seen_keys = array();
		foreach ( $new_fields as $field ) {
			$kind = $field['field_kind'] ?? '';
			$fkey = $field['field_key'] ?? '';
			$key  = $kind . ':' . $fkey;

			$seen_keys[] = $key;

			if ( ! isset( $existing_map[ $key ] ) ) {
				// Brand new field.
				$result['added'][] = $field;
			} else {
				$old      = $existing_map[ $key ];
				$old_type = $old['data_type'] ?? null;
				$new_type = $field['data_type'] ?? null;

				// Detect type change (only when both sides are non-null, or one changes from/to null).
				if ( $old_type !== $new_type ) {
					$result['type_changed'][] = array(
						'id'         => $old['id'] ?? 0,
						'field_key'  => $fkey,
						'field_kind' => $kind,
						'old_type'   => $old_type,
						'new_type'   => $new_type,
					);
				}

				// Always include in unchanged for stats updates (even if type changed).
				// This ensures full-mode stat refresh covers type-changed fields too.
				$result['unchanged'][] = array_merge( $old, array(
					'new_usage_count'  => $field['usage_count'] ?? 0,
					'new_sample_value' => $field['sample_value'] ?? null,
				) );
			}
		}

		// Orphan detection: only scan-sourced fields not seen in new results.
		// Manual, derived, and core-sourced fields are never orphaned by scan changes.
		foreach ( $existing_map as $key => $f ) {
			if ( ! in_array( $key, $seen_keys, true ) && 'scan' === ( $f['source'] ?? '' ) ) {
				$result['orphan'][] = $f;
			}
		}

		return $result;
	}

	/**
	 * Persist a type change: update data_type and store history in extra column.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $field_id Field ID.
	 * @param array $change   { id, field_key, field_kind, old_type, new_type }.
	 */
	private function persist_type_change( int $field_id, array $change ): void {
		// Read current extra to preserve existing metadata and append history.
		$field = Model_Object_Service::get_field( $field_id );
		if ( ! $field ) {
			return;
		}

		$extra = is_array( $field['extra'] ) ? $field['extra'] : array();

		// Append to type_change_history array in extra.
		if ( ! isset( $extra['type_change_history'] ) || ! is_array( $extra['type_change_history'] ) ) {
			$extra['type_change_history'] = array();
		}
		$extra['type_change_history'][] = array(
			'old_type'   => $change['old_type'] ?? null,
			'new_type'   => $change['new_type'] ?? null,
			'changed_at' => current_time( 'mysql' ),
		);

		// Update the field: new data_type + updated extra with history.
		Model_Object_Service::update_field( $field_id, array(
			'extra' => $extra,
		) );

		// Also update the data_type column directly since update_field() doesn't handle SSOT columns.
		global $wpdb;
		$table = wptsall_table( 'model_object_fields' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'data_type'  => isset( $change['new_type'] ) ? sanitize_text_field( (string) $change['new_type'] ) : null,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $field_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * E4: Pre-validate scan result for structural errors.
	 *
	 * Blocks sync when the scan data is structurally invalid. Quality issues
	 * (missing fields, unknown data_types) are handled post-save as warnings.
	 *
	 * @since 1.4.0
	 *
	 * @param int   $model_id    Model ID.
	 * @param array $scan_result Scanner output.
	 * @return array Error messages (empty if valid).
	 */
	private function pre_validate_scan_result( int $model_id, array $scan_result ): array {
		$errors = array();

		// The model must exist.
		if ( $model_id <= 0 ) {
			$errors[] = 'Invalid model_id: must be a positive integer.';
		}

		// Scan result must contain at least one discoverable object category.
		$post_types    = $scan_result['post_types'] ?? array();
		$taxonomies    = $scan_result['taxonomies'] ?? array();
		$custom_tables = $scan_result['custom_tables'] ?? array();

		if ( empty( $post_types ) && empty( $taxonomies ) && empty( $custom_tables ) ) {
			$errors[] = 'Scan result contains no discoverable objects (post_types, taxonomies, or custom_tables).';
		}

		// rules_summary, if present, must be an array.
		if ( isset( $scan_result['rules_summary'] ) && ! is_array( $scan_result['rules_summary'] ) ) {
			$errors[] = 'rules_summary must be an array when present.';
		}

		return $errors;
	}

	/**
	 * Parse objects from scan_result.
	 *
	 * @param array $scan_result Scanner output.
	 * @return array List of { object_type, object_name, url_signature, metadata }.
	 */
	private function parse_objects_from_scan( array $scan_result ): array {
		$objects = array();

		// Post types
		$post_types = is_array( $scan_result['post_types'] ?? null ) ? $scan_result['post_types'] : array();
		foreach ( $post_types as $pt ) {
			$name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : (string) $pt;
			$name = sanitize_key( $name );
			if ( '' === $name ) {
				continue;
			}
			$objects[] = array(
				'object_type'   => 'post_type',
				'object_name'   => $name,
				'url_signature' => '?post_type=' . rawurlencode( $name ) . '&p={id}',
				'metadata'      => is_array( $pt ) ? $pt : array( 'name' => $name ),
			);
		}

		// Taxonomies
		$taxonomies = is_array( $scan_result['taxonomies'] ?? null ) ? $scan_result['taxonomies'] : array();
		foreach ( $taxonomies as $tax ) {
			$name = is_array( $tax ) ? ( $tax['name'] ?? '' ) : (string) $tax;
			$name = sanitize_key( $name );
			if ( '' === $name ) {
				continue;
			}
			$objects[] = array(
				'object_type'   => 'taxonomy',
				'object_name'   => $name,
				'url_signature' => '?taxonomy=' . rawurlencode( $name ) . '&term={term}',
				'metadata'      => is_array( $tax ) ? $tax : array( 'name' => $name ),
			);
		}

		// Custom tables
		$custom_tables = is_array( $scan_result['custom_tables'] ?? null ) ? $scan_result['custom_tables'] : array();
		foreach ( $custom_tables as $ct ) {
			$name = is_array( $ct ) ? ( $ct['name'] ?? '' ) : '';
			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$objects[] = array(
				'object_type'   => 'custom_table',
				'object_name'   => $name,
				'url_signature' => null,
				'metadata'      => is_array( $ct ) ? $ct : array( 'name' => $name ),
			);
		}

		return $objects;
	}

	/**
	 * Parse fields for a specific object from scan_result.
	 *
	 * Extracts fields from rules_summary if available, falling back to
	 * post_type supports and meta_fields.
	 *
	 * @param array $scan_result Scanner output.
	 * @param array $obj         Object descriptor { object_type, object_name }.
	 * @return array List of { field_kind, field_key, data_type, reference_type, reference_target, usage_count, sample_value }.
	 */
	private function parse_fields_for_object( array $scan_result, array $obj ): array {
		$fields      = array();
		$object_type = $obj['object_type'] ?? '';
		$object_name = $obj['object_name'] ?? '';

		// Try rules_summary first (most complete source)
		$rules_summary = is_array( $scan_result['rules_summary'] ?? null ) ? $scan_result['rules_summary'] : array();
		foreach ( $rules_summary as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$rule_object = $rule['object_name'] ?? ( $rule['post_type'] ?? ( $rule['taxonomy'] ?? '' ) );
			if ( (string) $rule_object !== $object_name ) {
				continue;
			}

			// Extract field_capabilities entries
			$caps = is_array( $rule['field_capabilities'] ?? null ) ? $rule['field_capabilities'] : array();
			foreach ( $caps as $field_key => $cap_info ) {
				$data_type  = null;
				$ref_type   = null;
				$ref_target = null;

				if ( is_array( $cap_info ) ) {
					// cap_info['data_type'] may already be a proper format (text/html/id_ref).
					// cap_info['type'] is the capability type (translate/id_mapping/compute/sync).
					// If data_type is missing or is a capability type, derive from cap type.
					$data_type  = $cap_info['data_type'] ?? null;
					$cap_type   = $cap_info['type'] ?? null;
					$ref_type   = $cap_info['reference_type'] ?? null;
					$ref_target = $cap_info['reference_target'] ?? null;

					// If data_type looks like a capability type, map it to proper format.
					if ( null === $data_type || in_array( $data_type, array( 'translate', 'id_mapping', 'compute', 'sync' ), true ) ) {
						$data_type = self::map_capability_to_data_type( $cap_type ?? $data_type, $field_key );
					}
				} elseif ( is_string( $cap_info ) ) {
					// String cap_info is a capability type, not a data format.
					$data_type = self::map_capability_to_data_type( $cap_info, $field_key );
				}

				$fields[] = array(
					'field_kind'       => $this->detect_field_kind( $field_key, $object_type ),
					'field_key'        => $field_key,
					'data_type'        => $data_type,
					'reference_type'   => $ref_type,
					'reference_target' => $ref_target,
					'usage_count'      => 0,
					'sample_value'     => null,
				);
			}
		}

		// If no rules_summary fields found, fallback to post_type supports + meta_fields
		if ( empty( $fields ) && 'post_type' === $object_type ) {
			$post_types = is_array( $scan_result['post_types'] ?? null ) ? $scan_result['post_types'] : array();
			foreach ( $post_types as $pt ) {
				$pt_name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : (string) $pt;
				if ( (string) $pt_name !== $object_name || ! is_array( $pt ) ) {
					continue;
				}
				// From supports
				$supports    = is_array( $pt['supports'] ?? null ) ? $pt['supports'] : array();
				$support_map = array(
					'title'   => 'post_title',
					'editor'  => 'post_content',
					'excerpt' => 'post_excerpt',
				);
				foreach ( $supports as $s ) {
					if ( isset( $support_map[ (string) $s ] ) ) {
						$field_key = $support_map[ (string) $s ];
						$fields[]  = array(
							'field_kind' => 'core',
							'field_key'  => $field_key,
							'data_type'  => ( 'post_content' === $field_key ) ? 'html' : 'text',
						);
					}
				}
				if ( in_array( 'thumbnail', $supports, true ) ) {
					$fields[] = array(
						'field_kind'     => 'meta',
						'field_key'      => '_thumbnail_id',
						'data_type'      => 'id_ref',
						'reference_type' => 'media',
					);
				}
				break;
			}

			// From meta_fields
			$meta_fields = is_array( $scan_result['meta_fields'] ?? null ) ? $scan_result['meta_fields'] : array();
			foreach ( $meta_fields as $mf ) {
				if ( ! is_array( $mf ) ) {
					continue;
				}
				$obj_sub = $mf['object_subtype'] ?? '';
				if ( (string) $obj_sub !== $object_name ) {
					continue;
				}
				$fields[] = array(
					'field_kind' => 'meta',
					'field_key'  => $mf['meta_key'] ?? '',
				);
			}
		}

		// Taxonomy core fields
		if ( empty( $fields ) && 'taxonomy' === $object_type ) {
			foreach ( array( 'name', 'description', 'slug', 'parent' ) as $core_field ) {
				$fields[] = array(
					'field_kind' => 'core',
					'field_key'  => $core_field,
				);
			}
		}

		return $fields;
	}

	/**
	 * Detect field_kind from field_key and object_type.
	 *
	 * @param string $field_key   Field key.
	 * @param string $object_type Object type.
	 * @return string core/meta/column.
	 */
	private function detect_field_kind( string $field_key, string $object_type ): string {
		if ( 'custom_table' === $object_type ) {
			return 'column';
		}

		// Core WordPress post/taxonomy fields
		$core_post_fields = array(
			'post_title', 'post_content', 'post_excerpt', 'post_name',
			'post_date', 'post_status', 'post_author', 'post_parent',
			'menu_order', 'comment_status', 'ping_status', 'guid',
		);
		$core_tax_fields = array( 'name', 'description', 'slug', 'parent', 'term_group' );

		if ( 'taxonomy' === $object_type && in_array( $field_key, $core_tax_fields, true ) ) {
			return 'core';
		}
		if ( in_array( $field_key, $core_post_fields, true ) ) {
			return 'core';
		}

		// Everything else is meta
		return 'meta';
	}

	/**
	 * Map a capability type to data_type format.
	 *
	 * Capability types (translate/id_mapping/compute/sync) describe what to DO
	 * with a field. Data types (text/html/id_ref/slug) describe the data FORMAT.
	 * This method bridges the two when only capability type is available.
	 *
	 * @since 1.5.0
	 *
	 * @param string|null $cap_type  Capability type (translate/id_mapping/compute/sync).
	 * @param string      $field_key Field key for context-sensitive mapping.
	 * @return string|null Data type format or null for sync.
	 */
	private static function map_capability_to_data_type( ?string $cap_type, string $field_key = '' ): ?string {
		switch ( $cap_type ) {
			case 'translate':
				// post_content and description fields are HTML, others are plain text.
				$html_fields = array( 'post_content', 'description' );
				return in_array( $field_key, $html_fields, true ) ? 'html' : 'text';

			case 'id_mapping':
				return 'id_ref';

			case 'compute':
				return 'slug';

			case 'sync':
			default:
				return null;
		}
	}
}
