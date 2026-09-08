<?php
/**
 * Model Object Service (Plugin Template Objects)
 *
 * Persists "plugin template" data in a 3-level structure:
 * plugin(model) -> storage objects -> object fields
 *
 * Requirement (B): scanning results must be mapped to a specific storage object
 * before being stored. Unmapped items are logged and skipped.
 *
 * @package WPTSALL
 * @since 1.0.1
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DISPLAY/REFERENCE service for scan results and manual field additions.
 *
 * This service manages model_objects and model_object_fields tables which store
 * discovered data for admin UI visualization. It does NOT affect sync behavior.
 * The AUTHORITATIVE source for sync is Translation_Rule_Service (reading from
 * the translation_rules table and its field_capabilities column).
 */
class Model_Object_Service {

	/**
	 * Sync storage objects + fields from a scan payload.
	 *
	 * This is a best-effort sync:
	 * - Upserts objects and fields.
	 * - Never overwrites manual fields with scan/derived data.
	 * - Unmapped fields are logged and skipped.
	 *
	 * @param int    $model_id    Model (plugin) ID.
	 * @param string $plugin_slug Plugin slug (for logging).
	 * @param array  $scan        Scan payload (from Plugin_Scanner::scan_plugin()).
	 * @return array Stats.
	 */
	public static function sync_from_scan( $model_id, $plugin_slug, $scan ) {
		$model_id    = (int) $model_id;
		$plugin_slug = sanitize_key( (string) $plugin_slug );
		$scan        = is_array( $scan ) ? $scan : array();

		$post_types    = is_array( $scan['post_types'] ?? null ) ? $scan['post_types'] : array();
		$taxonomies    = is_array( $scan['taxonomies'] ?? null ) ? $scan['taxonomies'] : array();
		$custom_tables = is_array( $scan['custom_tables'] ?? null ) ? $scan['custom_tables'] : array();
		$meta_fields   = is_array( $scan['meta_fields'] ?? null ) ? $scan['meta_fields'] : array();

		// Only run for real scan payloads (manual updates call Plugin_Mapping_Service::save() too).
		if ( empty( $post_types ) && empty( $taxonomies ) && empty( $custom_tables ) && empty( $meta_fields ) ) {
			return array(
				'objects_upserted' => 0,
				'fields_upserted'  => 0,
				'unmapped_skipped' => 0,
			);
		}

		$stats = array(
			'objects_upserted' => 0,
			'fields_upserted'  => 0,
			'unmapped_skipped' => 0,
		);

		// 1) Upsert objects.
		$object_ids = array(
			'post_type'    => array(),
			'taxonomy'     => array(),
			'custom_table' => array(),
		);

		foreach ( $post_types as $pt_data ) {
			$pt_name = is_array( $pt_data ) ? ( $pt_data['name'] ?? '' ) : (string) $pt_data;
			$pt_name = sanitize_key( $pt_name );
			if ( '' === $pt_name ) {
				continue;
			}

			$object_id = self::upsert_object(
				$model_id,
				'post_type',
				$pt_name,
				array(
					'url_signature' => self::canonical_post_url_signature( $pt_name ),
					'metadata'      => is_array( $pt_data ) ? $pt_data : array( 'name' => $pt_name ),
					'source_type'   => 'auto',
				)
			);

			if ( $object_id ) {
				$object_ids['post_type'][ $pt_name ] = (int) $object_id;
				++$stats['objects_upserted'];
			}
		}

		foreach ( $taxonomies as $tax_data ) {
			$tax_name = is_array( $tax_data ) ? ( $tax_data['name'] ?? '' ) : (string) $tax_data;
			$tax_name = sanitize_key( $tax_name );
			if ( '' === $tax_name ) {
				continue;
			}

			$object_id = self::upsert_object(
				$model_id,
				'taxonomy',
				$tax_name,
				array(
					'url_signature' => self::canonical_taxonomy_url_signature( $tax_name ),
					'metadata'      => is_array( $tax_data ) ? $tax_data : array( 'name' => $tax_name ),
					'source_type'   => 'auto',
				)
			);

			if ( $object_id ) {
				$object_ids['taxonomy'][ $tax_name ] = (int) $object_id;
				++$stats['objects_upserted'];
			}
		}

		foreach ( $custom_tables as $table_data ) {
			$table_name = '';
			if ( is_array( $table_data ) ) {
				$table_name = (string) ( $table_data['name'] ?? '' );
			}
			$table_name = sanitize_text_field( $table_name );
			if ( '' === $table_name ) {
				continue;
			}

			$object_id = self::upsert_object(
				$model_id,
				'custom_table',
				$table_name,
				array(
					'url_signature' => null,
					'metadata'      => is_array( $table_data ) ? $table_data : array( 'name' => $table_name ),
					'source_type'   => 'auto',
				)
			);

			if ( $object_id ) {
				$object_ids['custom_table'][ $table_name ] = (int) $object_id;
				++$stats['objects_upserted'];
			}
		}

		// 2) Upsert fields per object.
		// 2.1 Post type derived fields (from supports).
		foreach ( $post_types as $pt_data ) {
			$pt_name = is_array( $pt_data ) ? ( $pt_data['name'] ?? '' ) : (string) $pt_data;
			$pt_name = sanitize_key( $pt_name );
			if ( '' === $pt_name || empty( $object_ids['post_type'][ $pt_name ] ) ) {
				continue;
			}

			$object_id = (int) $object_ids['post_type'][ $pt_name ];
			$supports  = is_array( $pt_data ) && isset( $pt_data['supports'] ) && is_array( $pt_data['supports'] ) ? $pt_data['supports'] : array();
			$derived   = self::supports_to_fields( $supports );

			foreach ( $derived as $field_kind => $keys ) {
				foreach ( $keys as $field_key ) {
					$field_id = self::upsert_field(
						$object_id,
						$field_kind,
						$field_key,
						'derived',
						array(
							'derived_from' => 'supports',
							'post_type'    => $pt_name,
						)
					);
					if ( $field_id ) {
						++$stats['fields_upserted'];
					}

					// Enrich meta-kind derived fields with SSOT schema via classifier.
					if ( 'meta' === $field_kind && $field_id && class_exists( '\WPTSALL\Core\Smart_Field_Classifier' ) ) {
						$cls    = \WPTSALL\Core\Smart_Field_Classifier::classify_for_chain( $field_key, array(), 'post' );
						$c_type = $cls['type'] ?? 'sync';
						$schema = self::classifier_result_to_schema( $field_key, $c_type, $cls, array() );
						$derived_data = array(
							'field_kind'       => $field_kind,
							'field_key'        => $field_key,
							'source'           => 'derived',
							'data_type'        => $schema['data_type'],
							'reference_type'   => $schema['reference_type'],
							'reference_target' => $schema['reference_target'],
						);
						if ( 'skip' === $c_type ) {
							$derived_data['status'] = 'skip';
						}
						self::insert_field( $object_id, $derived_data );
					}
				}
			}
		}

		// 2.2 Runtime sampled post meta fields (from seeded/live data).
		// This complements source-scanned register_post_meta() discovery and captures
		// plugin-specific content fields that are not explicitly registered.
		foreach ( $object_ids['post_type'] as $pt_name => $object_id ) {
			$sampled_meta_fields = self::discover_sampled_post_meta_fields( (string) $pt_name );

			foreach ( $sampled_meta_fields as $mf ) {
				$field_data = array(
					'field_kind'    => 'meta',
					'field_key'     => (string) ( $mf['field_key'] ?? '' ),
					'source'        => 'sampled',
					'data_type'     => (string) ( $mf['data_type'] ?? 'text' ),
					'usage_count'   => (int) ( $mf['usage_count'] ?? 0 ),
					'sample_value'  => (string) ( $mf['sample_value'] ?? '' ),
					'status'        => (string) ( $mf['status'] ?? 'active' ),
					'discovered_at' => current_time( 'mysql' ),
				);

				if ( ! empty( $mf['reference_type'] ) ) {
					$field_data['reference_type'] = (string) $mf['reference_type'];
				}
				if ( ! empty( $mf['reference_target'] ) ) {
					$field_data['reference_target'] = (string) $mf['reference_target'];
				}

				$field_id = self::insert_field( (int) $object_id, $field_data );
				if ( $field_id ) {
					++$stats['fields_upserted'];
				}
			}
		}

		// 2.3 Taxonomy core fields (always available).
		foreach ( $object_ids['taxonomy'] as $tax_name => $object_id ) {
			foreach ( array( 'name', 'description', 'slug', 'parent' ) as $field_key ) {
				$field_id = self::upsert_field(
					(int) $object_id,
					'core',
					$field_key,
					'core',
					array(
						'taxonomy' => $tax_name,
					)
				);
				if ( $field_id ) {
					++$stats['fields_upserted'];
				}
			}
		}

		// 2.3b Taxonomy meta fields (sampled from wp_termmeta).
		foreach ( $object_ids['taxonomy'] as $tax_name => $object_id ) {
			$sampled_term_meta = self::discover_sampled_term_meta_fields( (string) $tax_name );
			foreach ( $sampled_term_meta as $mf ) {
				$field_id = self::insert_field( (int) $object_id, array(
					'field_kind'    => 'meta',
					'field_key'     => (string) ( $mf['field_key'] ?? '' ),
					'source'        => 'sampled',
					'data_type'     => (string) ( $mf['data_type'] ?? 'text' ),
					'usage_count'   => (int) ( $mf['usage_count'] ?? 0 ),
					'sample_value'  => (string) ( $mf['sample_value'] ?? '' ),
					'status'        => (string) ( $mf['status'] ?? 'active' ),
					'discovered_at' => current_time( 'mysql' ),
				) );
				if ( $field_id ) {
					++$stats['fields_upserted'];
				}
			}
		}

		// 2.4 Custom table columns.
		foreach ( $custom_tables as $table_data ) {
			if ( ! is_array( $table_data ) ) {
				continue;
			}
			$table_name = sanitize_text_field( (string) ( $table_data['name'] ?? '' ) );
			if ( '' === $table_name || empty( $object_ids['custom_table'][ $table_name ] ) ) {
				continue;
			}

			$object_id = (int) $object_ids['custom_table'][ $table_name ];
			$columns   = is_array( $table_data['columns'] ?? null ) ? $table_data['columns'] : array();

			foreach ( $columns as $col ) {
				if ( ! is_array( $col ) ) {
					continue;
				}
				$col_name = sanitize_key( (string) ( $col['name'] ?? '' ) );
				if ( '' === $col_name ) {
					continue;
				}

				$field_id = self::upsert_field(
					$object_id,
					'column',
					$col_name,
					'scan',
					array(
						'table'  => $table_name,
						'column' => $col,
					)
				);
				if ( $field_id ) {
					++$stats['fields_upserted'];
				}
			}
		}

		// 2.5 Source-scanned meta fields: MUST map to a specific object (post_type only for now).
		foreach ( $meta_fields as $mf ) {
			if ( ! is_array( $mf ) ) {
				continue;
			}

			$meta_key  = sanitize_key( (string) ( $mf['meta_key'] ?? '' ) );
			$obj_type  = sanitize_key( (string) ( $mf['object_type'] ?? '' ) );
			$obj_sub   = sanitize_key( (string) ( $mf['object_subtype'] ?? '' ) );
			$file      = sanitize_text_field( (string) ( $mf['file'] ?? '' ) );

			if ( '' === $meta_key ) {
				continue;
			}

			// Only accept post meta mapped to a concrete post_type.
			if ( 'post' !== $obj_type || '' === $obj_sub ) {
				++$stats['unmapped_skipped'];
				self::log_unmapped_field(
					$plugin_slug,
					$mf,
					'missing_object_subtype'
				);
				continue;
			}

			if ( empty( $object_ids['post_type'][ $obj_sub ] ) ) {
				++$stats['unmapped_skipped'];
				self::log_unmapped_field(
					$plugin_slug,
					$mf,
					'unknown_post_type'
				);
				continue;
			}

			$object_id = (int) $object_ids['post_type'][ $obj_sub ];

			$field_id = self::upsert_field(
				$object_id,
				'meta',
				$meta_key,
				'scan',
				array(
					'object_type'    => $obj_type,
					'object_subtype' => $obj_sub,
					'file'           => $file,
				)
			);

			if ( $field_id ) {
				++$stats['fields_upserted'];
			}
		}

		return $stats;
	}

	/**
	 * Get storage objects for a model.
	 *
	 * @param int $model_id Model ID.
	 * @return array
	 */
	public static function get_objects_for_model( $model_id ) {
		global $wpdb;
		$model_id = (int) $model_id;

		$table = wptsall_table( 'model_objects' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE model_id = %d ORDER BY object_type ASC, object_name ASC', $table, $model_id ),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['metadata'] = json_decode( $row['metadata'] ?? 'null', true );
		}

		return $rows;
	}

	/**
	 * Get a single storage object.
	 *
	 * @param int $object_id Object ID.
	 * @return array|null
	 */
	public static function get_object( $object_id ) {
		global $wpdb;
		$object_id = (int) $object_id;

		$table = wptsall_table( 'model_objects' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $object_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['metadata'] = json_decode( $row['metadata'] ?? 'null', true );

		return $row;
	}

	/**
	 * Get a specific object by model_id, type, and name.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $model_id    Model ID.
	 * @param string $object_type post_type/taxonomy/custom_table.
	 * @param string $object_name Object name.
	 * @return array|null
	 */
	public static function get_object_by_type( int $model_id, string $object_type, string $object_name ): ?array {
		global $wpdb;

		$table = wptsall_table( 'model_objects' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE model_id = %d AND object_type = %s AND object_name = %s LIMIT 1',
				$table,
				$model_id,
				$object_type,
				$object_name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['metadata'] = json_decode( $row['metadata'] ?? 'null', true );
		return $row;
	}

	/**
	 * Get fields for a storage object.
	 *
	 * @param int $object_id Object ID.
	 * @return array
	 */
	public static function get_fields_for_object( $object_id ) {
		global $wpdb;
		$object_id = (int) $object_id;

		$table = wptsall_table( 'model_object_fields' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE object_id = %d ORDER BY field_kind ASC, field_key ASC', $table, $object_id ),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row = self::hydrate_field_row( $row );
		}

		return $rows;
	}

	/**
	 * Upsert a storage object.
	 *
	 * @param int    $model_id     Model ID.
	 * @param string $object_type  post_type/taxonomy/option/custom_table.
	 * @param string $object_name  Slug/table name.
	 * @param array  $data         url_signature/metadata/source_type.
	 * @return int|false Object ID.
	 */
	private static function upsert_object( $model_id, $object_type, $object_name, $data ) {
		global $wpdb;

		$table = wptsall_table( 'model_objects' );
		$now   = current_time( 'mysql' );

		$model_id    = (int) $model_id;
		$object_type = sanitize_key( (string) $object_type );
		$object_name = (string) $object_name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE model_id = %d AND object_type = %s AND object_name = %s LIMIT 1',
				$table,
				$model_id,
				$object_type,
				$object_name
			)
		);

		$row = array(
			'model_id'      => $model_id,
			'object_type'   => $object_type,
			'object_name'   => $object_name,
			'url_signature' => isset( $data['url_signature'] ) ? (string) $data['url_signature'] : null,
			'metadata'      => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
			'source_type'   => isset( $data['source_type'] ) ? sanitize_text_field( $data['source_type'] ) : 'auto',
			'updated_at'    => $now,
		);

		$format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update(
				$table,
				$row,
				array( 'id' => (int) $existing_id ),
				$format,
				array( '%d' )
			);
			return false !== $ok ? (int) $existing_id : false;
		}

		$row['created_at'] = $now;
		$format[]          = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $table, $row, $format );

		return false !== $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Upsert a field for a storage object.
	 *
	 * Never overwrites a manual field with scan/derived.
	 *
	 * @param int    $object_id  Object ID.
	 * @param string $field_kind core/meta/column.
	 * @param string $field_key  Field key.
	 * @param string $source     scan/manual/derived/core.
	 * @param array  $extra      Extra JSON.
	 * @return int|false Field ID.
	 */
	private static function upsert_field( $object_id, $field_kind, $field_key, $source, $extra = array() ) {
		global $wpdb;

		$table = wptsall_table( 'model_object_fields' );
		$now   = current_time( 'mysql' );

		$object_id  = (int) $object_id;
		$field_kind = sanitize_key( (string) $field_kind );
		$field_key  = (string) $field_key;
		$source     = sanitize_key( (string) $source );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, source FROM %i WHERE object_id = %d AND field_kind = %s AND field_key = %s LIMIT 1',
				$table,
				$object_id,
				$field_kind,
				$field_key
			),
			ARRAY_A
		);

		// Manual always wins.
		if ( $existing && ( $existing['source'] ?? '' ) === 'manual' && 'manual' !== $source ) {
			return (int) $existing['id'];
		}

		$input = is_array( $extra ) ? $extra : array();
		$metadata = self::normalize_field_metadata_input( $input );
		$extra_payload = $input;
		foreach ( array_keys( $metadata ) as $meta_key ) {
			unset( $extra_payload[ $meta_key ] );
		}
		$ssot_columns = array(
			'data_type',
			'reference_type',
			'reference_target',
			'status',
			'discovered_at',
			'sample_value',
			'usage_count',
		);
		foreach ( $ssot_columns as $meta_key ) {
			unset( $extra_payload[ $meta_key ] );
		}

		$row = array(
			'object_id'   => $object_id,
			'field_kind'  => $field_kind,
			'field_key'   => $field_key,
			'source'      => $source,
			'extra'       => ! empty( $extra_payload ) ? wp_json_encode( $extra_payload ) : null,
			'updated_at'  => $now,
		);
		if ( array_key_exists( 'data_type', $input ) ) {
			$row['data_type'] = null !== $input['data_type'] ? sanitize_key( (string) $input['data_type'] ) : null;
		}
		if ( array_key_exists( 'reference_type', $input ) ) {
			$row['reference_type'] = null !== $input['reference_type'] ? sanitize_key( (string) $input['reference_type'] ) : null;
		}
		if ( array_key_exists( 'reference_target', $input ) ) {
			$row['reference_target'] = null !== $input['reference_target'] ? sanitize_text_field( (string) $input['reference_target'] ) : null;
		}
		if ( array_key_exists( 'status', $input ) ) {
			$row['status'] = null !== $input['status'] ? sanitize_key( (string) $input['status'] ) : null;
		}
		if ( array_key_exists( 'discovered_at', $input ) ) {
			$row['discovered_at'] = null !== $input['discovered_at'] ? sanitize_text_field( (string) $input['discovered_at'] ) : null;
		}
		if ( array_key_exists( 'sample_value', $input ) ) {
			$row['sample_value'] = null !== $input['sample_value'] ? sanitize_text_field( (string) $input['sample_value'] ) : null;
		}
		if ( array_key_exists( 'usage_count', $input ) ) {
			$row['usage_count'] = (int) $input['usage_count'];
		}
		foreach ( $metadata as $col => $value ) {
			$row[ $col ] = $value;
		}

		$format = array( '%d', '%s', '%s', '%s', '%s', '%s' );
		if ( array_key_exists( 'data_type', $input ) ) {
			$format[] = '%s';
		}
		if ( array_key_exists( 'reference_type', $input ) ) {
			$format[] = '%s';
		}
		if ( array_key_exists( 'reference_target', $input ) ) {
			$format[] = '%s';
		}
		if ( array_key_exists( 'status', $input ) ) {
			$format[] = '%s';
		}
		if ( array_key_exists( 'discovered_at', $input ) ) {
			$format[] = '%s';
		}
		if ( array_key_exists( 'sample_value', $input ) ) {
			$format[] = '%s';
		}
		if ( array_key_exists( 'usage_count', $input ) ) {
			$format[] = '%d';
		}
		foreach ( $metadata as $col => $value ) {
			$format[] = in_array( $col, array( 'confidence_score', 'is_manual_override', 'is_admin_approved', 'approved_by' ), true ) ? '%d' : '%s';
		}

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update(
				$table,
				$row,
				array( 'id' => (int) $existing['id'] ),
				$format,
				array( '%d' )
			);
			return false !== $ok ? (int) $existing['id'] : false;
		}

		$row['created_at'] = $now;
		$format[]          = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $table, $row, $format );

		return false !== $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Convert post_type supports list to a field list.
	 *
	 * @param array $supports Supports list.
	 * @return array {field_kind => [field_key,...]}
	 */
	private static function supports_to_fields( $supports ) {
		$supports = is_array( $supports ) ? $supports : array();
		$out      = array(
			'core' => array(),
			'meta' => array(),
		);

		$map = array(
			'title'   => array( 'core', 'post_title' ),
			'editor'  => array( 'core', 'post_content' ),
			'excerpt' => array( 'core', 'post_excerpt' ),
			// Thumbnail is stored as post meta.
			'thumbnail' => array( 'meta', '_thumbnail_id' ),
		);

		foreach ( $supports as $s ) {
			$s = sanitize_key( (string) $s );
			if ( isset( $map[ $s ] ) ) {
				$out[ $map[ $s ][0] ][] = $map[ $s ][1];
			}
		}

		$out['core'] = array_values( array_unique( array_filter( $out['core'] ) ) );
		$out['meta'] = array_values( array_unique( array_filter( $out['meta'] ) ) );

		return $out;
	}

	/**
	 * Discover post meta keys from runtime data for a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return array Array of discovered meta field descriptors.
	 */
	private static function discover_sampled_post_meta_fields( string $post_type ): array {
		global $wpdb;

		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return array();
		}

		// Step 1: Get meta_keys with usage counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_key, COUNT(*) AS usage_count
				FROM %i pm
				INNER JOIN %i p ON p.ID = pm.post_id
				WHERE p.post_type = %s
					AND p.post_status NOT IN ('auto-draft', 'trash')
				GROUP BY pm.meta_key
				ORDER BY usage_count DESC
				LIMIT 200",
				$wpdb->postmeta,
				$wpdb->posts,
				$post_type
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		// Step 2: Collect multiple sample values per key for better classification.
		$keys_info = array();
		foreach ( $rows as $row ) {
			$meta_key = sanitize_key( (string) ( $row['meta_key'] ?? '' ) );
			if ( '' === $meta_key || self::should_skip_sampled_meta_key( $meta_key ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$samples = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value
					FROM %i pm
					INNER JOIN %i p ON p.ID = pm.post_id
					WHERE pm.meta_key = %s AND p.post_type = %s
						AND pm.meta_value != '' AND pm.meta_value IS NOT NULL
					ORDER BY pm.meta_id DESC
					LIMIT 5",
					$wpdb->postmeta,
					$wpdb->posts,
					$meta_key,
					$post_type
				)
			);

			$keys_info[ $meta_key ] = array(
				'samples'     => is_array( $samples ) ? $samples : array(),
				'usage_count' => (int) ( $row['usage_count'] ?? 0 ),
			);
		}

		// Step 3: Classify using Smart_Field_Classifier (primary) or infer_meta_field_schema (fallback).
		$use_classifier = class_exists( '\WPTSALL\Core\Smart_Field_Classifier' );
		$discovered     = array();

		foreach ( $keys_info as $meta_key => $info ) {
			if ( $use_classifier ) {
				$classification = \WPTSALL\Core\Smart_Field_Classifier::classify_for_chain(
					$meta_key,
					$info['samples'],
					'post'
				);

				$cap_type = $classification['type'] ?? 'sync';

				// Preserve skip fields with skip status (PII, system fields).
				$field_status = 'active';
				if ( 'skip' === $cap_type ) {
					$field_status = 'skip';
				}

				$schema = self::classifier_result_to_schema( $meta_key, $cap_type, $classification, $info['samples'] );
			} else {
				// Fallback: single-sample schema inference.
				$sample       = ! empty( $info['samples'] ) ? $info['samples'][0] : '';
				$schema       = self::infer_meta_field_schema( $meta_key, $sample );
				$field_status = 'active';
			}

			$discovered[] = array(
				'field_key'        => $meta_key,
				'data_type'        => $schema['data_type'],
				'reference_type'   => $schema['reference_type'],
				'reference_target' => $schema['reference_target'],
				'usage_count'      => $info['usage_count'],
				'sample_value'     => ! empty( $info['samples'] ) ? $info['samples'][0] : '',
				'status'           => $field_status,
			);
		}

		return $discovered;
	}

	/**
	 * Discover term meta keys from runtime data for a taxonomy.
	 *
	 * Symmetric with discover_sampled_post_meta_fields() but queries wp_termmeta
	 * joined with wp_term_taxonomy to scope by taxonomy.
	 *
	 * @since 1.2.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array Array of discovered term meta field descriptors.
	 */
	private static function discover_sampled_term_meta_fields( string $taxonomy ): array {
		global $wpdb;

		$taxonomy = sanitize_key( $taxonomy );
		if ( '' === $taxonomy ) {
			return array();
		}

		// Step 1: Get meta_keys with usage counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.meta_key, COUNT(*) AS usage_count
				FROM %i tm
				INNER JOIN %i tt ON tt.term_id = tm.term_id
				WHERE tt.taxonomy = %s
				GROUP BY tm.meta_key
				ORDER BY usage_count DESC
				LIMIT 200",
				$wpdb->termmeta,
				$wpdb->term_taxonomy,
				$taxonomy
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		// Step 2: Collect sample values per key for classification.
		$keys_info = array();
		foreach ( $rows as $row ) {
			$meta_key = (string) ( $row['meta_key'] ?? '' );
			if ( '' === $meta_key ) {
				continue;
			}

			// Skip WordPress internal term meta.
			if ( 0 === strpos( $meta_key, '_oembed_' ) || 0 === strpos( $meta_key, '_transient_' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$samples = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT tm.meta_value
					FROM %i tm
					INNER JOIN %i tt ON tt.term_id = tm.term_id
					WHERE tm.meta_key = %s AND tt.taxonomy = %s
						AND tm.meta_value != '' AND tm.meta_value IS NOT NULL
					ORDER BY tm.meta_id DESC
					LIMIT 5",
					$wpdb->termmeta,
					$wpdb->term_taxonomy,
					$meta_key,
					$taxonomy
				)
			);

			$keys_info[ $meta_key ] = array(
				'samples'     => is_array( $samples ) ? $samples : array(),
				'usage_count' => (int) ( $row['usage_count'] ?? 0 ),
			);
		}

		// Step 3: Classify using Smart_Field_Classifier.
		$use_classifier = class_exists( '\WPTSALL\Core\Smart_Field_Classifier' );
		$discovered     = array();

		foreach ( $keys_info as $meta_key => $info ) {
			if ( $use_classifier ) {
				$classification = \WPTSALL\Core\Smart_Field_Classifier::classify_for_chain(
					$meta_key,
					$info['samples'],
					'term'
				);

				$cap_type     = $classification['type'] ?? 'sync';
				$field_status = 'active';
				if ( 'skip' === $cap_type ) {
					$field_status = 'skip';
				}

				$schema = self::classifier_result_to_schema( $meta_key, $cap_type, $classification, $info['samples'] );
			} else {
				$sample       = ! empty( $info['samples'] ) ? $info['samples'][0] : '';
				$schema       = self::infer_meta_field_schema( $meta_key, $sample );
				$field_status = 'active';
			}

			$discovered[] = array(
				'field_key'        => $meta_key,
				'data_type'        => $schema['data_type'],
				'reference_type'   => $schema['reference_type'],
				'reference_target' => $schema['reference_target'],
				'usage_count'      => $info['usage_count'],
				'sample_value'     => ! empty( $info['samples'] ) ? $info['samples'][0] : '',
				'status'           => $field_status,
			);
		}

		return $discovered;
	}

	/**
	 * Decide whether a sampled meta key should be ignored.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private static function should_skip_sampled_meta_key( string $meta_key ): bool {
		$allow = array(
			'_thumbnail_id',
			'_wp_page_template',  // Page template selector — sync field.
		);
		if ( in_array( $meta_key, $allow, true ) ) {
			return false;
		}

		$skip_exact = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_attached_file',
			'_wp_attachment_metadata',
			'_wp_attachment_image_alt',
			'_wp_desired_post_slug',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_pingme',
			'_encloseme',
		);

		if ( in_array( $meta_key, $skip_exact, true ) ) {
			return true;
		}

		if ( 0 === strpos( $meta_key, '_wp_' ) ) {
			return true;
		}

		if ( 0 === strpos( $meta_key, '_oembed_' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Infer schema for a sampled meta field.
	 *
	 * @param string $meta_key     Meta key.
	 * @param string $sample_value Sample meta value.
	 * @return array {data_type, reference_type, reference_target}
	 */
	private static function infer_meta_field_schema( string $meta_key, string $sample_value ): array {
		$schema = array(
			'data_type'        => 'text',
			'reference_type'   => null,
			'reference_target' => null,
		);

		$sample = trim( $sample_value );

		// Reference-like keys first.
		if ( in_array( $meta_key, array( '_thumbnail_id', 'post_parent', 'post_author', 'parent' ), true ) ) {
			$schema['data_type'] = 'id_ref';
			if ( '_thumbnail_id' === $meta_key ) {
				$schema['reference_type'] = 'media';
			} elseif ( 'post_author' === $meta_key ) {
				$schema['reference_type'] = 'user';
			} else {
				$schema['reference_type'] = 'post';
			}
			return $schema;
		}

		if ( preg_match( '/_ids$/i', $meta_key ) ) {
			$schema['data_type'] = 'id_list';
			$schema['reference_type'] = 'post';
			return $schema;
		}

		if ( preg_match( '/(?:^|_)id$/i', $meta_key ) ) {
			$schema['data_type'] = 'id_ref';
			if ( strpos( $meta_key, 'user' ) !== false || strpos( $meta_key, 'author' ) !== false ) {
				$schema['reference_type'] = 'user';
			} elseif ( strpos( $meta_key, 'term' ) !== false || strpos( $meta_key, 'tax' ) !== false || strpos( $meta_key, 'category' ) !== false ) {
				$schema['reference_type'] = 'term';
			} else {
				$schema['reference_type'] = 'post';
			}
			return $schema;
		}

		// Slug-like keys.
		if ( in_array( $meta_key, array( 'slug', 'post_name' ), true ) ) {
			$schema['data_type'] = 'slug';
			return $schema;
		}

		// Structured data.
		if ( '' !== $sample ) {
			$decoded = json_decode( $sample, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$schema['data_type'] = 'json';
				return $schema;
			}

			if ( function_exists( 'is_serialized' ) && is_serialized( $sample ) ) {
				$schema['data_type'] = 'serialized';
				return $schema;
			}
		}

		// Primitive values.
		if ( '' !== $sample ) {
			if ( filter_var( $sample, FILTER_VALIDATE_URL ) ) {
				$schema['data_type'] = 'url';
				return $schema;
			}

			if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $sample ) ) {
				$schema['data_type'] = 'numeric';
				return $schema;
			}

			if ( preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $sample ) ) {
				$schema['data_type'] = 'datetime';
				return $schema;
			}

			if ( preg_match( '/^(?:true|false|yes|no)$/i', $sample ) ) {
				$schema['data_type'] = 'boolean';
				return $schema;
			}
		}

		// Key-based enum hints.
		if ( preg_match( '/(?:_status|_type|_rating|_mode|_format)$/i', $meta_key ) ) {
			$schema['data_type'] = 'enum';
			return $schema;
		}

		return $schema;
	}

	/**
	 * Map Smart_Field_Classifier result to model_object_fields schema.
	 *
	 * Converts the classifier's capability type (translate/sync/id_mapping/compute)
	 * back to data_type/reference_type/reference_target for storage in
	 * model_object_fields, preserving sub-type granularity from sample values.
	 *
	 * @param string $meta_key       Meta key.
	 * @param string $cap_type       Classifier capability type.
	 * @param array  $classification Full classifier result {type, content_format, confidence}.
	 * @param array  $samples        Sample values used for classification.
	 * @return array {data_type, reference_type, reference_target}
	 */
	private static function classifier_result_to_schema( string $meta_key, string $cap_type, array $classification, array $samples ): array {
		$schema = array(
			'data_type'        => 'text',
			'reference_type'   => null,
			'reference_target' => null,
		);

		$sample = ! empty( $samples ) ? trim( $samples[0] ) : '';

		switch ( $cap_type ) {
			case 'translate':
				$content_format = $classification['content_format'] ?? 'plain_text';
				$schema['data_type'] = in_array( $content_format, array( 'html', 'rich_text' ), true ) ? 'html' : 'text';
				break;

			case 'id_mapping':
				// Detect single ref vs list (comma-separated or serialized).
				$has_list = false;
				foreach ( $samples as $s ) {
					if ( false !== strpos( $s, ',' ) ) {
						$has_list = true;
						break;
					}
					if ( function_exists( 'is_serialized' ) && is_serialized( $s ) ) {
						$has_list = true;
						break;
					}
				}
				$schema['data_type'] = $has_list ? 'id_list' : 'id_ref';

				// Infer reference type from field name patterns.
				if ( false !== stripos( $meta_key, 'thumbnail' ) || false !== stripos( $meta_key, 'image' ) || false !== stripos( $meta_key, 'gallery' ) ) {
					$schema['reference_type'] = 'media';
				} elseif ( false !== stripos( $meta_key, 'user' ) || false !== stripos( $meta_key, 'author' ) ) {
					$schema['reference_type'] = 'user';
				} elseif ( false !== stripos( $meta_key, 'term' ) || false !== stripos( $meta_key, 'tax' ) || false !== stripos( $meta_key, 'category' ) ) {
					$schema['reference_type'] = 'term';
				} else {
					$schema['reference_type'] = 'post';
				}
				break;

			case 'compute':
				$schema['data_type'] = 'slug';
				break;

			case 'sync':
				// Preserve sub-type granularity using value inspection.
				if ( '' !== $sample ) {
					$decoded = json_decode( $sample, true );
					if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
						$schema['data_type'] = 'json';
					} elseif ( function_exists( 'is_serialized' ) && is_serialized( $sample ) ) {
						$schema['data_type'] = 'serialized';
					} elseif ( filter_var( $sample, FILTER_VALIDATE_URL ) ) {
						$schema['data_type'] = 'url';
					} elseif ( preg_match( '/^-?\d+(?:\.\d+)?$/', $sample ) ) {
						$schema['data_type'] = 'numeric';
					} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $sample ) ) {
						$schema['data_type'] = 'datetime';
					} elseif ( preg_match( '/^(?:true|false|yes|no)$/i', $sample ) ) {
						$schema['data_type'] = 'boolean';
					} else {
						$schema['data_type'] = 'enum';
					}
				} else {
					$schema['data_type'] = 'enum';
				}
				break;
		}

		return $schema;
	}

	private static function canonical_post_url_signature( $post_type ) {
		return '?post_type=' . rawurlencode( (string) $post_type ) . '&p={id}';
	}

	private static function canonical_taxonomy_url_signature( $taxonomy ) {
		return '?taxonomy=' . rawurlencode( (string) $taxonomy ) . '&term={term}';
	}

	// ==========================================
	// Manual Fields API (ISS-MOD-027)
	// ==========================================

	/**
	 * Save manual fields for a model.
	 *
	 * Replaces all existing manual fields with the provided set.
	 * Each field is mapped to the appropriate model_object based on
	 * explicit object binding (object_type + object_name).
	 *
	 * @since 1.0.2
	 *
	 * @param int   $model_id Model ID.
	 * @param array $fields   Array of
	 *                        { table_name, field_name, associated_id_map, description, object_type, object_name }.
	 * @return array Stats: saved, skipped.
	 */
	public static function save_manual_fields( $model_id, $fields ) {
		$model_id = (int) $model_id;
		$fields   = is_array( $fields ) ? $fields : array();

		$stats = array(
			'saved'   => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		// Get all objects for this model to resolve table→object mapping.
		$objects = self::get_objects_for_model( $model_id );

		// Index objects by type for lookup.
		$objects_by_type = array(
			'post_type'    => array(),
			'taxonomy'     => array(),
			'custom_table' => array(),
		);
		foreach ( $objects as $obj ) {
			$type = $obj['object_type'] ?? '';
			if ( isset( $objects_by_type[ $type ] ) ) {
				$objects_by_type[ $type ][ $obj['object_name'] ] = (int) $obj['id'];
			}
		}

		$normalized_fields = array();
		foreach ( $fields as $idx => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$table_name       = sanitize_text_field( (string) ( $field['table_name'] ?? '' ) );
			$field_name       = sanitize_text_field( (string) ( $field['field_name'] ?? '' ) );
			$associated_id_map = sanitize_text_field( (string) ( $field['associated_id_map'] ?? '' ) );
			$description      = sanitize_text_field( (string) ( $field['description'] ?? '' ) );
			$object_type      = sanitize_key( (string) ( $field['object_type'] ?? '' ) );
			$object_name      = sanitize_text_field( (string) ( $field['object_name'] ?? '' ) );

			if ( '' === $table_name || '' === $field_name || '' === $associated_id_map || '' === $object_type || '' === $object_name ) {
				++$stats['skipped'];
				continue;
			}

			$schema_check = self::validate_manual_field_schema( $table_name, $field_name, $associated_id_map );
			if ( ! $schema_check['valid'] ) {
				$stats['errors'][] = array(
					'index'             => (int) $idx,
					'table_name'        => $table_name,
					'field_name'        => $field_name,
					'associated_id_map' => $associated_id_map,
					'object_type'       => $object_type,
					'object_name'       => $object_name,
					'errors'            => array_values( $schema_check['errors'] ),
				);
				continue;
			}

			$table_name        = $schema_check['table_name'];
			$field_name        = $schema_check['field_name'];
			$associated_id_map = $schema_check['associated_id_map'];

			$resolved = self::resolve_object_for_manual_field(
				$model_id,
				$table_name,
				$object_type,
				$object_name,
				$objects_by_type
			);

			if ( ! $resolved ) {
				$stats['errors'][] = array(
					'index'             => (int) $idx,
					'table_name'        => $table_name,
					'field_name'        => $field_name,
					'associated_id_map' => $associated_id_map,
					'object_type'       => $object_type,
					'object_name'       => $object_name,
					'errors'            => array(
						__( 'No matching template object for the provided object binding.', 'wpmmcc-ats' ),
					),
				);
				continue;
			}

			$normalized_fields[] = array(
				'resolved'          => $resolved,
				'table_name'        => $table_name,
				'field_name'        => $field_name,
				'associated_id_map' => $associated_id_map,
				'description'       => $description,
				'object_type'       => $object_type,
				'object_name'       => $object_name,
			);
		}

		// Strong mode: any invalid row blocks this save batch.
		if ( ! empty( $stats['errors'] ) ) {
			if ( function_exists( 'wptsall_log_warning' ) ) {
				wptsall_log_warning(
					'models',
					'Manual fields validation failed',
					array(
						'model_id'     => $model_id,
						'error_count'  => count( $stats['errors'] ),
					)
				);
			}
			return $stats;
		}

		// Remove existing manual fields for this model only after validation passes.
		self::clear_manual_fields( $model_id );

		foreach ( $normalized_fields as $entry ) {
			$resolved          = $entry['resolved'];
			$table_name        = $entry['table_name'];
			$field_name        = $entry['field_name'];
			$associated_id_map = $entry['associated_id_map'];
			$description       = $entry['description'];
			$object_type       = $entry['object_type'];
			$object_name       = $entry['object_name'];

			$extra = array(
				'table_name'       => $table_name,
				'associated_id_map' => $associated_id_map,
				'description'      => $description,
				'object_type'      => $object_type,
				'object_name'      => $object_name,
			);

			$field_id = self::upsert_field(
				$resolved['object_id'],
				$resolved['field_kind'],
				$field_name,
				'manual',
				$extra
			);

			if ( $field_id ) {
				// Best-effort inference for manual ID-like fields so dependency
				// validation can run even without explicit reference metadata input.
				$schema = self::infer_manual_reference_schema( $field_name, $object_type, $object_name );
				self::update_field(
					(int) $field_id,
					array(
						'data_type'        => $schema['data_type'],
						'reference_type'   => $schema['reference_type'],
						'reference_target' => $schema['reference_target'],
					)
				);
				++$stats['saved'];
			} else {
				++$stats['skipped'];
			}
		}

		// Rebuild dependency graph after manual field replacement.
		$stats['dependency_sync'] = Model_Object_Dependency_Service::rebuild_for_model( $model_id );

		return $stats;
	}

	/**
	 * Validate manual-field schema against real database tables/columns.
	 *
	 * @since 1.6.0
	 *
	 * @param string $table_name        Candidate table name.
	 * @param string $field_name        Candidate field/column name.
	 * @param string $associated_id_map Candidate relation/id-map column.
	 * @return array {
	 *   @type bool   $valid
	 *   @type string $table_name
	 *   @type string $field_name
	 *   @type string $associated_id_map
	 *   @type array  $errors
	 * }
	 */
	private static function validate_manual_field_schema( $table_name, $field_name, $associated_id_map ) {
		$table_name        = self::normalize_manual_table_name( (string) $table_name );
		$field_name        = sanitize_text_field( (string) $field_name );
		$associated_id_map = sanitize_text_field( (string) $associated_id_map );

		$result = array(
			'valid'             => false,
			'table_name'        => $table_name,
			'field_name'        => $field_name,
			'associated_id_map' => $associated_id_map,
			'errors'            => array(),
		);

		if ( '' === $table_name ) {
			$result['errors'][] = __( 'Table name is required.', 'wpmmcc-ats' );
			return $result;
		}

		$columns = Field_Discovery_Service::get_table_columns( $table_name );
		if ( is_wp_error( $columns ) ) {
			$result['errors'][] = sprintf(
				/* translators: 1: table name, 2: error message */
				__( 'Table "%1$s" is invalid: %2$s', 'wpmmcc-ats' ),
				$table_name,
				$columns->get_error_message()
			);
			return $result;
		}

		$column_map = array();
		foreach ( (array) $columns as $column ) {
			$column_name = sanitize_text_field( (string) ( $column['field'] ?? '' ) );
			if ( '' === $column_name ) {
				continue;
			}
			$column_map[ strtolower( $column_name ) ] = $column_name;
		}

		$field_key_lower = strtolower( $field_name );
		if ( '' === $field_key_lower || ! isset( $column_map[ $field_key_lower ] ) ) {
			$result['errors'][] = sprintf(
				/* translators: 1: field name, 2: table name */
				__( 'Field "%1$s" does not exist in table "%2$s".', 'wpmmcc-ats' ),
				$field_name,
				$table_name
			);
		} else {
			$result['field_name'] = $column_map[ $field_key_lower ];
		}

		$id_map_lower = strtolower( $associated_id_map );
		if ( '' === $id_map_lower || ! isset( $column_map[ $id_map_lower ] ) ) {
			$result['errors'][] = sprintf(
				/* translators: 1: id-map field name, 2: table name */
				__( 'Associated ID field "%1$s" does not exist in table "%2$s".', 'wpmmcc-ats' ),
				$associated_id_map,
				$table_name
			);
		} else {
			$result['associated_id_map'] = $column_map[ $id_map_lower ];
		}

		$result['valid'] = empty( $result['errors'] );
		return $result;
	}

	/**
	 * Normalize manual table names to canonical WordPress-prefixed names.
	 *
	 * Supports both prefixed names (wp_posts) and common aliases (posts).
	 *
	 * @since 1.6.0
	 *
	 * @param string $table_name Input table name.
	 * @return string Normalized table name (or original sanitized input).
	 */
	private static function normalize_manual_table_name( string $table_name ): string {
		global $wpdb;

		$table_name = sanitize_text_field( trim( $table_name ) );
		if ( '' === $table_name ) {
			return '';
		}

		$table_lut  = self::get_discovery_table_lookup();
		$table_key  = strtolower( $table_name );
		if ( isset( $table_lut[ $table_key ] ) ) {
			return $table_lut[ $table_key ];
		}

		$aliases = array(
			'posts'         => $wpdb->posts,
			'postmeta'      => $wpdb->postmeta,
			'terms'         => $wpdb->terms,
			'termmeta'      => $wpdb->termmeta,
			'term_taxonomy' => $wpdb->term_taxonomy,
			'users'         => $wpdb->users,
			'usermeta'      => $wpdb->usermeta,
			'comments'      => $wpdb->comments,
			'commentmeta'   => $wpdb->commentmeta,
			'options'       => $wpdb->options,
		);

		if ( isset( $aliases[ $table_key ] ) ) {
			$alias_target = strtolower( (string) $aliases[ $table_key ] );
			if ( isset( $table_lut[ $alias_target ] ) ) {
				return $table_lut[ $alias_target ];
			}
			return (string) $aliases[ $table_key ];
		}

		$prefixed_candidate = strtolower( $wpdb->prefix . ltrim( $table_key, '_' ) );
		if ( isset( $table_lut[ $prefixed_candidate ] ) ) {
			return $table_lut[ $prefixed_candidate ];
		}

		return $table_name;
	}

	/**
	 * Get lowercase lookup map for discovered DB tables.
	 *
	 * @since 1.6.0
	 *
	 * @return array [lower_table_name => canonical_table_name]
	 */
	private static function get_discovery_table_lookup(): array {
		static $table_lut = null;

		if ( null !== $table_lut ) {
			return $table_lut;
		}

		$table_lut = array();
		$tables    = Field_Discovery_Service::get_tables();
		if ( is_array( $tables ) ) {
			foreach ( $tables as $table ) {
				$table_name = sanitize_text_field( (string) $table );
				if ( '' === $table_name ) {
					continue;
				}
				$table_lut[ strtolower( $table_name ) ] = $table_name;
			}
		}

		return $table_lut;
	}

	/**
	 * Get manual fields for a model.
	 *
	 * Returns fields in the same format used by the manual-fields UI:
	 * { table_name, field_name, associated_id_map, description, object_id, object_type, object_name }.
	 *
	 * @since 1.0.2
	 *
	 * @param int $model_id Model ID.
	 * @return array
	 */
	public static function get_manual_fields( $model_id ) {
		global $wpdb;

		$model_id = (int) $model_id;

		$objects_table = wptsall_table( 'model_objects' );
		$fields_table  = wptsall_table( 'model_object_fields' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.field_key, f.field_kind, f.extra, o.id AS object_id, o.object_type, o.object_name
				FROM %i AS f
				INNER JOIN %i AS o ON f.object_id = o.id
				WHERE o.model_id = %d AND f.source = 'manual'
				ORDER BY f.field_key ASC",
				$fields_table,
				$objects_table,
				$model_id
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $rows as $row ) {
			$extra = json_decode( $row['extra'] ?? 'null', true );
			$extra = is_array( $extra ) ? $extra : array();

			$result[] = array(
				'table_name'       => $extra['table_name'] ?? '',
				'field_name'       => $row['field_key'] ?? '',
				'associated_id_map' => $extra['associated_id_map'] ?? '',
				'description'      => $extra['description'] ?? '',
				'object_id'        => (int) ( $row['object_id'] ?? 0 ),
				'object_type'      => $row['object_type'] ?? ( $extra['object_type'] ?? '' ),
				'object_name'      => $row['object_name'] ?? ( $extra['object_name'] ?? '' ),
				'source'           => 'manual',
			);
		}

		return $result;
	}

	/**
	 * Clear all manual fields for a model.
	 *
	 * @since 1.0.2
	 *
	 * @param int $model_id Model ID.
	 * @return int Number of fields deleted.
	 */
	public static function clear_manual_fields( $model_id ) {
		global $wpdb;

		$model_id = (int) $model_id;

		$objects_table = wptsall_table( 'model_objects' );
		$fields_table  = wptsall_table( 'model_object_fields' );

		// Get object IDs for this model.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$object_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE model_id = %d',
				$objects_table,
				$model_id
			)
		);

		if ( empty( $object_ids ) ) {
			return 0;
		}

		list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $object_ids );

		$deleted = wptsall_db_query(
			"DELETE FROM %i WHERE object_id IN ($in_sql) AND source = 'manual'",
			array_merge( array( $fields_table ), $in_args )
		);

		return (int) $deleted;
	}

	/**
	 * Resolve which model_object a manual field should be attached to.
	 *
	 * Requires explicit object binding from caller to avoid ambiguous
	 * "first object" fallback in production save paths.
	 *
	 * @since 1.0.2
	 *
	 * @param int    $model_id         Model ID.
	 * @param string $table_name       Database table name.
	 * @param string $object_type      post_type/taxonomy/custom_table.
	 * @param string $object_name      Object identifier.
	 * @param array  $objects_by_type  Pre-indexed objects { type => { name => id } }.
	 * @return array|null { object_id, field_kind } or null.
	 */
	private static function resolve_object_for_manual_field( $model_id, $table_name, $object_type, $object_name, &$objects_by_type ) {
		$object_type = sanitize_key( (string) $object_type );
		$object_name = sanitize_text_field( (string) $object_name );
		$table_name  = sanitize_text_field( (string) $table_name );

		if ( '' === $object_type || '' === $object_name ) {
			return null;
		}

		$allowed_types = array( 'post_type', 'taxonomy', 'option', 'custom_table' );
		if ( ! in_array( $object_type, $allowed_types, true ) ) {
			return null;
		}

		$expected_type = self::infer_object_type_from_table_name( $table_name );
		if ( '' !== $expected_type && $expected_type !== $object_type ) {
			return null;
		}

		$field_kind = 'column';
		$table_lower = strtolower( $table_name );
		if ( false !== strpos( $table_lower, 'meta' ) ) {
			$field_kind = 'meta';
		} elseif ( 'post_type' === $object_type || 'taxonomy' === $object_type ) {
			$field_kind = 'core';
		} elseif ( 'option' === $object_type ) {
			$field_kind = 'meta';
		}

		if ( isset( $objects_by_type[ $object_type ][ $object_name ] ) ) {
			return array(
				'object_id'  => (int) $objects_by_type[ $object_type ][ $object_name ],
				'field_kind' => $field_kind,
			);
		}

		// For custom tables, allow creating the object on demand.
		if ( 'custom_table' === $object_type ) {
			$object_id = self::upsert_object(
				$model_id,
				'custom_table',
				$object_name,
				array(
					'url_signature' => null,
					'metadata'      => array( 'name' => $object_name ),
					'source_type'   => 'manual',
				)
			);

			if ( $object_id ) {
				$objects_by_type['custom_table'][ $object_name ] = (int) $object_id;
				return array(
					'object_id'  => (int) $object_id,
					'field_kind' => $field_kind,
				);
			}
		}

		return null;
	}

	/**
	 * Infer expected object type from a source table name.
	 *
	 * @since 1.5.0
	 *
	 * @param string $table_name Database table name.
	 * @return string object_type or empty string if unknown.
	 */
	private static function infer_object_type_from_table_name( $table_name ) {
		$table_lower = strtolower( (string) $table_name );

		if ( false !== strpos( $table_lower, 'postmeta' ) || false !== strpos( $table_lower, 'posts' ) || false !== strpos( $table_lower, 'usermeta' ) || false !== strpos( $table_lower, 'users' ) ) {
			return 'post_type';
		}

		if ( false !== strpos( $table_lower, 'termmeta' ) || false !== strpos( $table_lower, 'terms' ) || false !== strpos( $table_lower, 'term_taxonomy' ) ) {
			return 'taxonomy';
		}

		if ( false !== strpos( $table_lower, 'options' ) ) {
			return 'option';
		}

		return 'custom_table';
	}

	/**
	 * Infer reference schema for manual field definitions.
	 *
	 * Manual field UI currently captures binding info (table/object/field) but
	 * may not provide explicit reference_type/reference_target. This helper adds
	 * best-effort inference for ID-like fields so dependency validation can run.
	 *
	 * @since 1.6.0
	 *
	 * @param string $field_name  Field name.
	 * @param string $object_type Bound object type.
	 * @param string $object_name Bound object name.
	 * @return array {data_type, reference_type, reference_target}
	 */
	private static function infer_manual_reference_schema( $field_name, $object_type, $object_name ) {
		$field_key   = strtolower( sanitize_key( (string) $field_name ) );
		$object_type = sanitize_key( (string) $object_type );
		$object_name = sanitize_key( (string) $object_name );

		$schema = array(
			'data_type'        => null,
			'reference_type'   => null,
			'reference_target' => null,
		);

		if ( '' === $field_key ) {
			return $schema;
		}

		if ( in_array( $field_key, array( '_thumbnail_id', '_image_id', 'image_id', 'attachment_id' ), true ) ) {
			$schema['data_type']        = 'id_ref';
			$schema['reference_type']   = 'media';
			$schema['reference_target'] = 'attachment';
			return $schema;
		}

		if ( in_array( $field_key, array( 'post_parent', 'parent' ), true ) ) {
			$schema['data_type']      = 'id_ref';
			$schema['reference_type'] = ( 'taxonomy' === $object_type ) ? 'taxonomy' : 'post';
			if ( in_array( $object_type, array( 'post_type', 'taxonomy' ), true ) ) {
				$schema['reference_target'] = $object_name;
			}
			return $schema;
		}

		if ( false !== strpos( $field_key, 'author' ) || false !== strpos( $field_key, 'user' ) ) {
			if ( preg_match( '/_id$|_ids$/', $field_key ) ) {
				$schema['data_type']        = preg_match( '/_ids$/', $field_key ) ? 'id_list' : 'id_ref';
				$schema['reference_type']   = 'user';
				$schema['reference_target'] = 'user';
				return $schema;
			}
		}

		if ( false !== strpos( $field_key, 'cat' ) || false !== strpos( $field_key, 'tag' ) || false !== strpos( $field_key, 'taxonomy' ) || false !== strpos( $field_key, 'term' ) ) {
			if ( preg_match( '/_id$|_ids$/', $field_key ) || false !== strpos( $field_key, 'primary_' ) ) {
				$schema['data_type']      = preg_match( '/_ids$/', $field_key ) ? 'id_list' : 'id_ref';
				$schema['reference_type'] = 'taxonomy';

				$tax = self::infer_manual_taxonomy_target( $field_key );
				if ( '' !== $tax ) {
					$schema['reference_target'] = $tax;
				}
				return $schema;
			}
		}

		if ( preg_match( '/_ids$/', $field_key ) ) {
			$schema['data_type']      = 'id_list';
			$schema['reference_type'] = 'post';
			if ( 'post_type' === $object_type && '' !== $object_name ) {
				$schema['reference_target'] = $object_name;
			}
			return $schema;
		}

		if ( preg_match( '/_id$/', $field_key ) ) {
			$schema['data_type']      = 'id_ref';
			$schema['reference_type'] = 'post';
			if ( 'post_type' === $object_type && '' !== $object_name ) {
				$schema['reference_target'] = $object_name;
			}
			return $schema;
		}

		return $schema;
	}

	/**
	 * Infer taxonomy target slug from a manual field key.
	 *
	 * @since 1.6.0
	 *
	 * @param string $field_key Field key.
	 * @return string
	 */
	private static function infer_manual_taxonomy_target( $field_key ) {
		$field_key = strtolower( (string) $field_key );

		$known = array(
			'_yoast_wpseo_primary_category'    => 'category',
			'_yoast_wpseo_primary_product_cat' => 'product_cat',
			'rank_math_primary_category'       => 'category',
			'rank_math_primary_product_cat'    => 'product_cat',
		);
		if ( isset( $known[ $field_key ] ) ) {
			return $known[ $field_key ];
		}

		if ( false !== strpos( $field_key, 'product_cat' ) ) {
			return 'product_cat';
		}
		if ( false !== strpos( $field_key, 'category' ) || false !== strpos( $field_key, '_cat_' ) ) {
			return 'category';
		}
		if ( false !== strpos( $field_key, 'post_tag' ) || false !== strpos( $field_key, '_tag_' ) ) {
			return 'post_tag';
		}

		if ( preg_match( '/^_?([a-z0-9_]+)_ids?$/', $field_key, $m ) ) {
			$candidate = sanitize_key( $m[1] );
			if ( ! in_array( $candidate, array( 'id', 'post', 'term', 'meta' ), true ) ) {
				return $candidate;
			}
		}

		if ( preg_match( '/primary_([a-z0-9_]+)$/', $field_key, $m ) ) {
			return sanitize_key( $m[1] );
		}

		return '';
	}

	// ==========================================
	// Object & Field CRUD API (ISS-MOD-028)
	// ==========================================

	/**
	 * Create a new storage object (public wrapper).
	 *
	 * @since 1.0.3
	 *
	 * @param int    $model_id    Model ID.
	 * @param string $object_type post_type/taxonomy/option/custom_table.
	 * @param string $object_name Slug or table name.
	 * @param array  $data        Optional: url_signature, metadata, source_type.
	 * @return int|false Object ID or false on failure.
	 */
	public static function create_object( $model_id, $object_type, $object_name, $data = array() ) {
		$model_id    = (int) $model_id;
		$object_type = sanitize_key( (string) $object_type );
		$object_name = sanitize_text_field( (string) $object_name );

		if ( $model_id <= 0 || '' === $object_type || '' === $object_name ) {
			return false;
		}

		$allowed_types = array( 'post_type', 'taxonomy', 'option', 'custom_table' );
		if ( ! in_array( $object_type, $allowed_types, true ) ) {
			return false;
		}

		$defaults = array(
			'url_signature' => null,
			'metadata'      => null,
			'source_type'   => 'manual',
		);
		$data = wp_parse_args( $data, $defaults );

		return self::upsert_object( $model_id, $object_type, $object_name, $data );
	}

	/**
	 * Delete a storage object and all its fields.
	 *
	 * @since 1.0.3
	 *
	 * @param int $object_id Object ID.
	 * @return bool True on success.
	 */
	public static function delete_object( $object_id ) {
		global $wpdb;
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return false;
		}

		$objects_table = wptsall_table( 'model_objects' );
		$fields_table  = wptsall_table( 'model_object_fields' );

		// Delete all fields first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $fields_table, array( 'object_id' => $object_id ), array( '%d' ) );

		// Delete the object.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $objects_table, array( 'id' => $object_id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Create a field for a storage object (public wrapper).
	 *
	 * @since 1.0.3
	 *
	 * @param int    $object_id  Object ID.
	 * @param string $field_kind core/meta/column.
	 * @param string $field_key  Field key.
	 * @param string $source     scan/manual/derived/core.
	 * @param array  $extra      Extra JSON data.
	 * @return int|false Field ID or false on failure.
	 */
	public static function add_field( $object_id, $field_kind, $field_key, $source = 'manual', $extra = array() ) {
		$object_id  = (int) $object_id;
		$field_kind = sanitize_key( (string) $field_kind );
		$field_key  = (string) $field_key;
		$source     = sanitize_key( (string) $source );

		if ( $object_id <= 0 || '' === $field_kind || '' === $field_key ) {
			return false;
		}

		return self::upsert_field( $object_id, $field_kind, $field_key, $source, $extra );
	}

	/**
	 * Update a field's attributes.
	 *
	 * @since 1.0.3
	 *
	 * @param int   $field_id Field ID.
	 * @param array $data     Allowed keys: field_kind, source, extra (merged into existing).
	 * @return bool True on success.
	 */
	public static function update_field( $field_id, $data ) {
		global $wpdb;
		$field_id = (int) $field_id;
		if ( $field_id <= 0 ) {
			return false;
		}

		$table = wptsall_table( 'model_object_fields' );

		// Fetch current row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $field_id ),
			ARRAY_A
		);
		if ( ! $existing ) {
			return false;
		}

		$update = array(
			'updated_at' => current_time( 'mysql' ),
		);
		$format = array( '%s' );

		if ( isset( $data['field_kind'] ) ) {
			$kind = sanitize_key( (string) $data['field_kind'] );
			if ( in_array( $kind, array( 'core', 'meta', 'column' ), true ) ) {
				$update['field_kind'] = $kind;
				$format[]             = '%s';
			}
		}

		if ( isset( $data['source'] ) ) {
			$src = sanitize_key( (string) $data['source'] );
			if ( in_array( $src, array( 'scan', 'manual', 'derived', 'core' ), true ) ) {
				$update['source'] = $src;
				$format[]         = '%s';
			}
		}

		// SSOT columns: data_type, reference_type, reference_target, usage_count, sample_value.
		if ( array_key_exists( 'data_type', $data ) ) {
			$update['data_type'] = null !== $data['data_type'] ? sanitize_key( (string) $data['data_type'] ) : null;
			$format[]            = '%s';
		}

		if ( array_key_exists( 'reference_type', $data ) ) {
			$update['reference_type'] = null !== $data['reference_type'] ? sanitize_key( (string) $data['reference_type'] ) : null;
			$format[]                 = '%s';
		}

		if ( array_key_exists( 'reference_target', $data ) ) {
			$update['reference_target'] = null !== $data['reference_target'] ? sanitize_text_field( (string) $data['reference_target'] ) : null;
			$format[]                   = '%s';
		}

		if ( array_key_exists( 'usage_count', $data ) ) {
			$update['usage_count'] = (int) $data['usage_count'];
			$format[]              = '%d';
		}

		if ( array_key_exists( 'sample_value', $data ) ) {
			$update['sample_value'] = null !== $data['sample_value'] ? sanitize_text_field( (string) $data['sample_value'] ) : null;
			$format[]               = '%s';
		}

		$metadata = self::normalize_field_metadata_input( $data );
		foreach ( $metadata as $col => $value ) {
			$update[ $col ] = $value;
			$format[] = in_array( $col, array( 'confidence_score', 'is_manual_override', 'is_admin_approved', 'approved_by' ), true ) ? '%d' : '%s';
		}

		if ( isset( $data['extra'] ) && is_array( $data['extra'] ) ) {
			$current_extra = json_decode( $existing['extra'] ?? 'null', true );
			$current_extra = is_array( $current_extra ) ? $current_extra : array();
			$merged        = array_merge( $current_extra, $data['extra'] );
			$update['extra'] = wp_json_encode( $merged );
			$format[]        = '%s';
		}

		if ( count( $update ) <= 1 ) {
			return true; // Nothing to update.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, $update, array( 'id' => $field_id ), $format, array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Delete a single field.
	 *
	 * @since 1.0.3
	 *
	 * @param int $field_id Field ID.
	 * @return bool True on success.
	 */
	public static function delete_field( $field_id ) {
		global $wpdb;
		$field_id = (int) $field_id;
		if ( $field_id <= 0 ) {
			return false;
		}

		$table = wptsall_table( 'model_object_fields' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $table, array( 'id' => $field_id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Get a single field by ID.
	 *
	 * @since 1.0.3
	 *
	 * @param int $field_id Field ID.
	 * @return array|null
	 */
	public static function get_field( $field_id ) {
		global $wpdb;
		$field_id = (int) $field_id;

		$table = wptsall_table( 'model_object_fields' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $field_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return self::hydrate_field_row( $row );
	}

	private static function hydrate_field_row( array $row ): array {
		$row['extra'] = json_decode( $row['extra'] ?? 'null', true );
		$row['extra'] = is_array( $row['extra'] ) ? $row['extra'] : array();
		$row['source_origin_list'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row['source_origin'] ?? '' ) ) ) ) );
		$row['approval_state'] = self::derive_approval_state( $row );
		return $row;
	}

	private static function derive_approval_state( array $row ): string {
		if ( ! empty( $row['is_admin_approved'] ) ) {
			return 'approved_semantic';
		}
		$source_origin = strtolower( (string) ( $row['source_origin'] ?? '' ) );
		$source        = strtolower( (string) ( $row['source'] ?? '' ) );
		if ( ! empty( $row['is_manual_override'] ) || 'manual' === $source || false !== strpos( $source_origin, 'manual' ) ) {
			return 'manual_supplement';
		}
		if ( false !== strpos( $source_origin, 'wpml_config_xml' ) ) {
			return 'imported_declaration';
		}
		return 'raw_scan';
	}

	private static function sanitize_source_origin( $value ): string {
		return sanitize_key( (string) $value );
	}

	private static function normalize_field_metadata_input( array $data ): array {
		$normalized = array();

		if ( array_key_exists( 'approval_state', $data ) ) {
			$approval_state = sanitize_key( (string) $data['approval_state'] );
			switch ( $approval_state ) {
				case 'approved':
				case 'approved_semantic':
					$normalized['is_admin_approved'] = 1;
					$normalized['approved_at'] = current_time( 'mysql' );
					$normalized['approved_by'] = get_current_user_id() ?: null;
					break;
				case 'manual':
				case 'manual_supplement':
					$normalized['is_manual_override'] = 1;
					if ( ! array_key_exists( 'source_origin', $data ) ) {
						$normalized['source_origin'] = 'manual';
					}
					break;
				case 'imported':
				case 'imported_declaration':
					if ( ! array_key_exists( 'source_origin', $data ) ) {
						$normalized['source_origin'] = 'wpml_config_xml';
					}
					break;
				case 'raw':
				case 'raw_scan':
					if ( ! array_key_exists( 'source_origin', $data ) ) {
						$normalized['source_origin'] = 'scan';
					}
					break;
			}
		}

		if ( array_key_exists( 'source_origin', $data ) ) {
			$normalized['source_origin'] = self::sanitize_source_origin( $data['source_origin'] );
		}
		if ( array_key_exists( 'source_detail', $data ) ) {
			$normalized['source_detail'] = null !== $data['source_detail'] ? sanitize_textarea_field( (string) $data['source_detail'] ) : null;
		}
		if ( array_key_exists( 'confidence_score', $data ) ) {
			$normalized['confidence_score'] = max( 0, min( 100, (int) $data['confidence_score'] ) );
		}
		if ( array_key_exists( 'confidence_reason', $data ) ) {
			$normalized['confidence_reason'] = null !== $data['confidence_reason'] ? sanitize_textarea_field( (string) $data['confidence_reason'] ) : null;
		}
		if ( array_key_exists( 'is_manual_override', $data ) ) {
			$normalized['is_manual_override'] = ! empty( $data['is_manual_override'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'is_admin_approved', $data ) ) {
			$is_approved = ! empty( $data['is_admin_approved'] ) ? 1 : 0;
			$normalized['is_admin_approved'] = $is_approved;
			if ( $is_approved ) {
				$normalized['approved_at'] = current_time( 'mysql' );
				$normalized['approved_by'] = get_current_user_id() ?: null;
			} else {
				$normalized['approved_at'] = null;
				$normalized['approved_by'] = null;
			}
		}

		return $normalized;
	}

	public static function get_model_diagnostics( int $model_id ): array {
		global $wpdb;
		$model_id = (int) $model_id;
		if ( $model_id <= 0 ) {
			return array(
				'low_confidence_fields' => array(),
				'unapproved_fields' => array(),
				'manual_override_fields' => array(),
				'by_approval_state' => array(),
				'by_source_origin' => array(),
				'summary' => array( 'total_fields' => 0, 'low_confidence' => 0, 'unapproved' => 0, 'manual_override' => 0 ),
			);
		}

		$objects_table = wptsall_table( 'model_objects' );
		$fields_table  = wptsall_table( 'model_object_fields' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.id, f.field_key, f.field_kind, f.source_origin, f.confidence_score, f.confidence_reason, f.is_manual_override, f.is_admin_approved, o.id AS object_id, o.object_type, o.object_name
				 FROM %i f
				 INNER JOIN %i o ON o.id = f.object_id
				 WHERE o.model_id = %d
				 ORDER BY o.object_type ASC, o.object_name ASC, f.field_key ASC',
				$fields_table,
				$objects_table,
				$model_id
			),
			ARRAY_A
		);

		$low = array();
		$unapproved = array();
		$manual = array();
		$by_approval_state = array();
		$by_source_origin  = array();
		foreach ( (array) $rows as $row ) {
			$row = self::hydrate_field_row( $row );
			if ( (int) ( $row['confidence_score'] ?? 0 ) > 0 && (int) $row['confidence_score'] < 70 ) {
				$low[] = $row;
			}
			if ( empty( $row['is_admin_approved'] ) ) {
				$unapproved[] = $row;
			}
			if ( ! empty( $row['is_manual_override'] ) ) {
				$manual[] = $row;
			}
			$approval_state = (string) ( $row['approval_state'] ?? 'raw_scan' );
			$by_approval_state[ $approval_state ] = (int) ( $by_approval_state[ $approval_state ] ?? 0 ) + 1;
			foreach ( (array) ( $row['source_origin_list'] ?? array() ) as $origin ) {
				$by_source_origin[ $origin ] = (int) ( $by_source_origin[ $origin ] ?? 0 ) + 1;
			}
		}

		ksort( $by_approval_state );
		ksort( $by_source_origin );

		return array(
			'low_confidence_fields' => $low,
			'unapproved_fields' => $unapproved,
			'manual_override_fields' => $manual,
			'by_approval_state' => $by_approval_state,
			'by_source_origin' => $by_source_origin,
			'summary' => array(
				'total_fields' => count( (array) $rows ),
				'low_confidence' => count( $low ),
				'unapproved' => count( $unapproved ),
				'manual_override' => count( $manual ),
			),
		);
	}

	private static function log_unmapped_field( $plugin_slug, $field, $reason ) {
		$plugin_slug = sanitize_key( (string) $plugin_slug );
		$reason      = sanitize_key( (string) $reason );

		wptsall_log_debug(
			'models',
			'Unmapped field skipped',
			array(
				'plugin_slug' => $plugin_slug,
				'reason'      => $reason,
				'field'       => $field,
			)
		);
	}

	// ==========================================
	// Template Sync Service Support (C2)
	// ==========================================

	/**
	 * Insert a new field with SSOT columns.
	 *
	 * Unlike add_field() which only sets basic fields, this method also
	 * sets the SSOT columns added in C1 (data_type, reference_type, etc.).
	 *
	 * @since 1.2.0
	 *
	 * @param int   $object_id Object ID.
	 * @param array $data      Field data with keys: field_kind, field_key, source,
	 *                         data_type, reference_type, reference_target,
	 *                         usage_count, sample_value, status, discovered_at, extra.
	 * @return int|false Field ID or false on failure.
	 */
	public static function insert_field( int $object_id, array $data ) {
		global $wpdb;

		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return false;
		}

		$field_kind = sanitize_key( (string) ( $data['field_kind'] ?? '' ) );
		$field_key  = (string) ( $data['field_key'] ?? '' );
		$source     = sanitize_key( (string) ( $data['source'] ?? 'scan' ) );

		if ( '' === $field_kind || '' === $field_key ) {
			return false;
		}

		$table = wptsall_table( 'model_object_fields' );
		$now   = current_time( 'mysql' );

		// Check if field already exists (avoid duplicates)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, source FROM %i WHERE object_id = %d AND field_kind = %s AND field_key = %s LIMIT 1',
				$table,
				$object_id,
				$field_kind,
				$field_key
			),
			ARRAY_A
		);

		if ( $existing ) {
			// Manual always wins — don't overwrite manual with scan.
			if ( 'manual' === ( $existing['source'] ?? '' ) && 'manual' !== $source ) {
				return (int) $existing['id'];
			}

			// Update existing with new SSOT data.
			$update = array(
				'source'     => $source,
				'updated_at' => $now,
			);
			$format = array( '%s', '%s' );

			$ssot_columns = array(
				'data_type'        => '%s',
				'reference_type'   => '%s',
				'reference_target' => '%s',
				'status'           => '%s',
				'discovered_at'    => '%s',
				'sample_value'     => '%s',
			);
			foreach ( $ssot_columns as $col => $fmt ) {
				if ( isset( $data[ $col ] ) ) {
					$update[ $col ] = sanitize_text_field( (string) $data[ $col ] );
					$format[]       = $fmt;
				}
			}
			if ( isset( $data['usage_count'] ) ) {
				$update['usage_count'] = (int) $data['usage_count'];
				$format[]              = '%d';
			}
			$metadata = self::normalize_field_metadata_input( $data );
			foreach ( $metadata as $col => $value ) {
				$update[ $col ] = $value;
				$format[] = in_array( $col, array( 'confidence_score', 'is_manual_override', 'is_admin_approved', 'approved_by' ), true ) ? '%d' : '%s';
			}
			if ( isset( $data['extra'] ) && is_array( $data['extra'] ) ) {
				$current      = self::get_field( (int) $existing['id'] );
				$current_extra = is_array( $current['extra'] ?? null ) ? $current['extra'] : array();
				$merged_extra  = array_merge( $current_extra, $data['extra'] );
				$update['extra'] = ! empty( $merged_extra ) ? wp_json_encode( $merged_extra ) : null;
				$format[]        = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $update, array( 'id' => (int) $existing['id'] ), $format, array( '%d' ) );
			return (int) $existing['id'];
		}

		// Insert new row.
		$row = array(
			'object_id'  => $object_id,
			'field_kind' => $field_kind,
			'field_key'  => $field_key,
			'source'     => $source,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$format = array( '%d', '%s', '%s', '%s', '%s', '%s' );

		$ssot_columns = array(
			'data_type'        => '%s',
			'reference_type'   => '%s',
			'reference_target' => '%s',
			'status'           => '%s',
			'discovered_at'    => '%s',
			'sample_value'     => '%s',
		);
		foreach ( $ssot_columns as $col => $fmt ) {
			if ( isset( $data[ $col ] ) ) {
				$row[ $col ] = sanitize_text_field( (string) $data[ $col ] );
				$format[]    = $fmt;
			}
		}
		if ( isset( $data['usage_count'] ) ) {
			$row['usage_count'] = (int) $data['usage_count'];
			$format[]           = '%d';
		}
		$metadata = self::normalize_field_metadata_input( $data );
		foreach ( $metadata as $col => $value ) {
			$row[ $col ] = $value;
			$format[] = in_array( $col, array( 'confidence_score', 'is_manual_override', 'is_admin_approved', 'approved_by' ), true ) ? '%d' : '%s';
		}
		if ( isset( $data['extra'] ) && is_array( $data['extra'] ) ) {
			$row['extra'] = ! empty( $data['extra'] ) ? wp_json_encode( $data['extra'] ) : null;
			$format[]     = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $table, $row, $format );

		return false !== $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a field's status.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $field_id Field ID.
	 * @param string $status   New status (new/active/orphan/deprecated).
	 * @return bool
	 */
	public static function update_field_status( int $field_id, string $status ): bool {
		global $wpdb;

		$field_id = (int) $field_id;
		if ( $field_id <= 0 ) {
			return false;
		}

		$allowed = array( 'new', 'active', 'orphan', 'deprecated', 'skip' );
		$status  = sanitize_key( $status );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$table = wptsall_table( 'model_object_fields' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $field_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update a field's usage statistics.
	 *
	 * @since 1.2.0
	 *
	 * @param int         $field_id     Field ID.
	 * @param int         $usage_count  New usage count.
	 * @param string|null $sample_value New sample value.
	 * @return bool
	 */
	public static function update_field_stats( int $field_id, int $usage_count, ?string $sample_value = null ): bool {
		global $wpdb;

		$field_id = (int) $field_id;
		if ( $field_id <= 0 ) {
			return false;
		}

		$table = wptsall_table( 'model_object_fields' );

		$update = array(
			'usage_count' => $usage_count,
			'updated_at'  => current_time( 'mysql' ),
		);
		$format = array( '%d', '%s' );

		if ( null !== $sample_value ) {
			$update['sample_value'] = sanitize_text_field( $sample_value );
			$format[]               = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$update,
			array( 'id' => $field_id ),
			$format,
			array( '%d' )
		);

		return false !== $result;
	}
}
