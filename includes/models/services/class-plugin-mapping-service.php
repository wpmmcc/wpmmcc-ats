<?php
/**
 * WPTSALL Plugin Mapping Service
 *
 * Manages plugin mappings storage and retrieval.
 *
 * @package WPTSALL
 * @since 0.3.1
 * @since 0.9.0 Added V4 scan result support
 * @since 0.9.1 Added custom_tables support (ISS-MOD-019)
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Plugin Mapping Service Class
 *
 * CRUD operations for models table (plugin mappings unified into models).
 * Supports V4 Smart Scanner integration with scan_result storage.
 */
class Plugin_Mapping_Service {

	/**
	 * Scan status constants
	 */
	const SCAN_STATUS_PENDING   = 'pending';
	const SCAN_STATUS_SCANNING  = 'scanning';
	const SCAN_STATUS_COMPLETED = 'completed';
	const SCAN_STATUS_FAILED    = 'failed';

	/**
	 * Get all plugin mappings
	 *
	 * @param array $args Query arguments.
	 * @return array Array of mappings.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;

		$table    = wptsall_table( 'models' );
		$defaults = array(
			'is_content_plugin' => null,
			'orderby'           => 'plugin_name',
			'order'             => 'ASC',
			'limit'             => -1,
			'offset'            => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( null !== $args['is_content_plugin'] ) {
			$where[]  = 'is_content_plugin = %d';
			$values[] = (int) $args['is_content_plugin'];
		}

		$orderby = in_array( $args['orderby'], array( 'plugin_name', 'plugin_slug', 'created_at' ), true )
			? $args['orderby']
			: 'plugin_name';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		// $where fragments are fixed SQL with placeholders only; $orderby/$order are whitelisted.
		$where_sql = implode( ' AND ', $where );
		$sql       = 'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY ' . $orderby . ' ' . $order;
		$params    = array_merge( array( $table ), $values );

		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['limit'];
			$params[] = (int) $args['offset'];
		}

		$results = wptsall_db_get_results( $sql, $params, ARRAY_A );

		// Decode JSON fields (only fields that exist in database)
		foreach ( $results as &$row ) {
			$row['post_types']    = json_decode( $row['post_types'] ?? '[]', true ) ?: array();
			$row['taxonomies']    = json_decode( $row['taxonomies'] ?? '[]', true ) ?: array();
			$row['meta_fields']   = json_decode( $row['meta_fields'] ?? '[]', true ) ?: array();
			$row['custom_tables'] = json_decode( $row['custom_tables'] ?? '[]', true ) ?: array();
		}

		return $results;
	}

	/**
	 * Get count of plugin mappings
	 *
	 * @param array $args Query arguments.
	 * @return int Count.
	 */
	public static function get_count( $args = array() ) {
		global $wpdb;

		$table    = wptsall_table( 'models' );
		$defaults = array(
			'is_content_plugin' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( null !== $args['is_content_plugin'] ) {
			$where[]  = 'is_content_plugin = %d';
			$values[] = (int) $args['is_content_plugin'];
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = 'SELECT COUNT(*) FROM %i WHERE ' . $where_sql;
		$params    = array_merge( array( $table ), $values );

		return (int) wptsall_db_get_var( $sql, $params );
	}

	/**
	 * Get mapping by plugin slug
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array|null Mapping data or null.
	 */
	public static function get_by_slug( $plugin_slug ) {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE plugin_slug = %s', $table, $plugin_slug ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Decode JSON fields (only fields that exist in database).
		$row['post_types']    = json_decode( $row['post_types'] ?? '[]', true ) ?: array();
		$row['taxonomies']    = json_decode( $row['taxonomies'] ?? '[]', true ) ?: array();
		$row['meta_fields']   = json_decode( $row['meta_fields'] ?? '[]', true ) ?: array();
		$row['custom_tables'] = json_decode( $row['custom_tables'] ?? '[]', true ) ?: array();

		// Note: scan_result is kept as JSON string for Model_Config_Provider to decode with caching.

		return $row;
	}

	/**
	 * Get mapping by ID
	 *
	 * @since 0.9.2
	 *
	 * @param int $id Mapping ID.
	 * @return array|null Mapping data or null.
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Decode JSON fields.
		$row['post_types']    = json_decode( $row['post_types'] ?? '[]', true ) ?: array();
		$row['taxonomies']    = json_decode( $row['taxonomies'] ?? '[]', true ) ?: array();
		$row['meta_fields']   = json_decode( $row['meta_fields'] ?? '[]', true ) ?: array();
		$row['custom_tables'] = json_decode( $row['custom_tables'] ?? '[]', true ) ?: array();

		return $row;
	}

	/**
	 * Save or update a plugin mapping
	 *
	 * @param array $data Mapping data.
	 * @return int|false Mapping ID or false on failure.
	 */
	public static function save( $data ) {
		global $wpdb;

		$table = wptsall_table( 'models' );
		$now   = current_time( 'mysql' );

		// Check if exists (sanitize slug first so the lookup matches the insert key).
		$existing = self::get_by_slug( sanitize_key( $data['plugin_slug'] ) );

		wptsall_log_debug(
			'models',
			'Plugin_Mapping_Service saving mapping',
			array(
				'plugin_slug' => $data['plugin_slug'],
				'is_update'   => ! empty( $existing ),
			)
		);

		// Merge meta_fields if existing
		$meta_fields = isset( $data['meta_fields'] ) ? $data['meta_fields'] : ( $existing['meta_fields'] ?? array() );

		// Strict rule (B): scan-discovered meta fields MUST map to a concrete storage object (post_type).
		// Unmapped scan fields should not be stored. Manual entries are preserved.
		$allowed_post_types = array();
		$post_types_for_check = isset( $data['post_types'] ) ? $data['post_types'] : ( $existing['post_types'] ?? array() );
		if ( is_array( $post_types_for_check ) ) {
			foreach ( $post_types_for_check as $pt ) {
				$pt_name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
				$pt_name = sanitize_key( (string) $pt_name );
				if ( '' !== $pt_name ) {
					$allowed_post_types[] = $pt_name;
				}
			}
		}
		$allowed_post_types = array_values( array_unique( $allowed_post_types ) );

		if ( $existing && isset( $data['meta_fields'] ) ) {
			// Enforce uniqueness by (table_name, field_name) or (meta_key)
			$merged = is_array( $existing['meta_fields'] ) ? $existing['meta_fields'] : array();

			// Clean up invalid scan entries from old runs (e.g. register_meta('post',...) without subtype).
			$merged = array_values(
				array_filter(
					$merged,
					function ( $f ) {
						if ( ! is_array( $f ) ) {
							return false;
						}

						// New structure is treated as manual config and stays.
						if ( isset( $f['field_name'] ) ) {
							return true;
						}

						$src = $f['source'] ?? 'scan';
						if ( 'manual' === $src ) {
							return true;
						}

						// Old structure: keep only post meta that has a concrete object_subtype.
						$obj_type = sanitize_key( (string) ( $f['object_type'] ?? '' ) );
						$obj_sub  = sanitize_key( (string) ( $f['object_subtype'] ?? '' ) );

						return ( 'post' === $obj_type && '' !== $obj_sub );
					}
				)
			);

			foreach ( $data['meta_fields'] as $new_field ) {
				$new_key   = $new_field['field_name'] ?? ( $new_field['meta_key'] ?? '' );
				$new_table = $new_field['table_name'] ?? '';
				$new_source = $new_field['source'] ?? 'scan';
				
				if ( empty( $new_key ) ) {
					continue;
				}

				// If this is scan-detected WP meta registration, require a concrete post_type mapping.
				// Example to skip: register_meta( 'post', 'foo', ... ) (no subtype).
				if ( ! isset( $new_field['field_name'] ) && 'manual' !== $new_source ) {
					$obj_type = sanitize_key( (string) ( $new_field['object_type'] ?? '' ) );
					$obj_sub  = sanitize_key( (string) ( $new_field['object_subtype'] ?? '' ) );

					if ( 'post' !== $obj_type || '' === $obj_sub ) {
						wptsall_log_warning(
							'models-scanner',
							'Unmapped scan meta skipped (missing object_subtype)',
							array(
								'plugin_slug' => $data['plugin_slug'],
								'meta_key'    => $new_key,
								'object_type' => $obj_type,
								'file'        => $new_field['file'] ?? '',
							)
						);
						continue;
					}

					if ( ! empty( $allowed_post_types ) && ! in_array( $obj_sub, $allowed_post_types, true ) ) {
						wptsall_log_warning(
							'models-scanner',
							'Unmapped scan meta skipped (unknown post_type for plugin)',
							array(
								'plugin_slug'     => $data['plugin_slug'],
								'meta_key'        => $new_key,
								'object_subtype'  => $obj_sub,
								'allowed_objects' => $allowed_post_types,
								'file'            => $new_field['file'] ?? '',
							)
						);
						continue;
					}
				}
				
				$found = false;
				foreach ( $merged as &$existing_field ) {
					$existing_key   = $existing_field['field_name'] ?? ( $existing_field['meta_key'] ?? '' );
					$existing_table = $existing_field['table_name'] ?? '';
					$existing_source = $existing_field['source'] ?? 'scan';
					
					// If both have tables, check both. If one is old structure (meta only), check key.
					if ( $existing_key === $new_key && ( empty( $new_table ) || empty( $existing_table ) || $existing_table === $new_table ) ) {
						// Preserve manual fields when scanning
						if ( 'manual' === $existing_source && 'manual' !== $new_source ) {
							$found = true;
							break;
						}
						$existing_field = array_merge( $existing_field, $new_field, array( 'source' => $new_source ) );
						$found = true;
						break;
					}
				}
				
				if ( ! $found ) {
					$new_field['source'] = $new_source;
					$merged[] = $new_field;
				}
			}
			$meta_fields = $merged;
		} elseif ( ! $existing && isset( $data['meta_fields'] ) && is_array( $meta_fields ) ) {
			// Insert path: apply the same strict mapping rule for scan-discovered meta fields.
			$filtered = array();
			foreach ( $meta_fields as $new_field ) {
				if ( ! is_array( $new_field ) ) {
					continue;
				}

				// New structure is treated as manual config and stays.
				if ( isset( $new_field['field_name'] ) ) {
					$filtered[] = $new_field;
					continue;
				}

				$new_key    = $new_field['meta_key'] ?? '';
				$new_source = $new_field['source'] ?? 'scan';

				if ( '' === $new_key ) {
					continue;
				}

				// Only validate/skip scan-like entries.
				if ( 'manual' !== $new_source ) {
					$obj_type = sanitize_key( (string) ( $new_field['object_type'] ?? '' ) );
					$obj_sub  = sanitize_key( (string) ( $new_field['object_subtype'] ?? '' ) );

					if ( 'post' !== $obj_type || '' === $obj_sub ) {
						wptsall_log_warning(
							'models-scanner',
							'Unmapped scan meta skipped (missing object_subtype)',
							array(
								'plugin_slug' => $data['plugin_slug'],
								'meta_key'    => $new_key,
								'object_type' => $obj_type,
								'file'        => $new_field['file'] ?? '',
							)
						);
						continue;
					}

					if ( ! empty( $allowed_post_types ) && ! in_array( $obj_sub, $allowed_post_types, true ) ) {
						wptsall_log_warning(
							'models-scanner',
							'Unmapped scan meta skipped (unknown post_type for plugin)',
							array(
								'plugin_slug'     => $data['plugin_slug'],
								'meta_key'        => $new_key,
								'object_subtype'  => $obj_sub,
								'allowed_objects' => $allowed_post_types,
								'file'            => $new_field['file'] ?? '',
							)
						);
						continue;
					}
				}

				$filtered[] = $new_field;
			}

			$meta_fields = $filtered;
		}

		$scan_result = $data['scan_result'] ?? ( $existing['scan_result'] ?? null );
		if ( is_array( $scan_result ) ) {
			$scan_result = wp_json_encode( $scan_result );
		}

		$row = array(
			'plugin_slug'       => sanitize_key( $data['plugin_slug'] ),
			'plugin_name'       => sanitize_text_field( $data['plugin_name'] ?? ( $existing['plugin_name'] ?? $data['plugin_slug'] ) ),
			'plugin_version'    => sanitize_text_field( $data['plugin_version'] ?? ( $existing['plugin_version'] ?? '' ) ),
			'text_domain'       => sanitize_text_field( $data['text_domain'] ?? ( $existing['text_domain'] ?? '' ) ),
			'description'       => sanitize_textarea_field( $data['description'] ?? ( $existing['description'] ?? '' ) ),
			'plugin_file'       => sanitize_text_field( $data['plugin_file'] ?? ( $existing['plugin_file'] ?? '' ) ),
			'is_content_plugin' => (int) ( isset( $data['is_content_plugin'] ) ? $data['is_content_plugin'] : ( $existing['is_content_plugin'] ?? 0 ) ),
			'post_types'        => wp_json_encode( isset( $data['post_types'] ) ? $data['post_types'] : ( $existing['post_types'] ?? array() ) ),
			'taxonomies'        => wp_json_encode( isset( $data['taxonomies'] ) ? $data['taxonomies'] : ( $existing['taxonomies'] ?? array() ) ),
			'meta_fields'       => wp_json_encode( $meta_fields ),
			'custom_tables'     => wp_json_encode( isset( $data['custom_tables'] ) ? $data['custom_tables'] : ( $existing['custom_tables'] ?? array() ) ),
			'status'            => sanitize_text_field( $data['status'] ?? ( $existing['status'] ?? 'active' ) ),
			'usage_status'      => sanitize_text_field( $data['usage_status'] ?? ( $existing['usage_status'] ?? 'unused' ) ),
			'source_type'       => sanitize_text_field( $data['source_type'] ?? ( $existing['source_type'] ?? 'auto' ) ),
			'is_system'         => (int) ( $data['is_system'] ?? ( $existing['is_system'] ?? 0 ) ),
			'scan_version'      => sanitize_text_field( $data['scan_version'] ?? ( $existing['scan_version'] ?? '' ) ),
			'scan_result'       => $scan_result,
			'scan_status'       => sanitize_text_field( $data['scan_status'] ?? ( $existing['scan_status'] ?? self::SCAN_STATUS_PENDING ) ),
			'scan_error'        => sanitize_textarea_field( $data['scan_error'] ?? ( $existing['scan_error'] ?? '' ) ),
			'user_consent'      => (int) ( $data['user_consent'] ?? ( $existing['user_consent'] ?? 0 ) ),
			'consent_at'        => $data['consent_at'] ?? ( $existing['consent_at'] ?? null ),
			'detection_method'  => sanitize_text_field( $data['detection_method'] ?? ( $existing['detection_method'] ?? 'static' ) ),
			'last_scanned'      => $data['last_scanned'] ?? ( $existing['last_scanned'] ?? $now ),
			'updated_at'        => $now,
		);

		$format = array(
			'%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
		);

		if ( $existing ) {
			// Update
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				$row,
				array( 'id' => $existing['id'] ),
				$format,
				array( '%d' )
			);

			return false !== $result ? (int) $existing['id'] : false;
		} else {
			// Insert
			$row['created_at'] = $now;
			$format[]          = '%s';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $row, $format );

			return false !== $result ? $wpdb->insert_id : false;
		}
	}

	/**
	 * Delete a mapping by plugin slug
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return bool True on success.
	 */
	public static function delete( $plugin_slug ) {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'plugin_slug' => $plugin_slug ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Clear all mappings
	 *
	 * @return bool True on success.
	 */
	public static function clear_all() {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// Only reset scan-related fields for auto-scanned plugin records.
		$data = array(
			'scan_result' => null,
			'scan_status' => self::SCAN_STATUS_PENDING,
			'scan_error'  => null,
			'last_scanned' => null,
			'updated_at'  => current_time( 'mysql' ),
		);

		$format = array( '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'source_type' => 'auto' ),
			$format,
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Get all content plugins (replaces wptsall_content_plugins())
	 *
	 * Only returns plugins that have actual public frontend URLs.
	 *
	 * @return array Plugin slug => plugin name.
	 */
	public static function get_content_plugins() {
		$mappings = self::get_all(
			array(
				'is_content_plugin' => 1,
			)
		);

		// Always include wordpress-blog as first option
		$result = array(
			'wordpress-blog' => 'WordPress Blog (Default)',
		);

		foreach ( $mappings as $mapping ) {
			// Skip if no post types and no custom tables registered
			// Note: We trust is_content_plugin flag from scanner - it already considers
			// explicit post types found in source code, even if not publicly queryable
			// (e.g., testimonials shown via shortcode)
			// ISS-MOD-019: Also include plugins with custom tables only
			$post_types    = $mapping['post_types'] ?? array();
			$custom_tables = $mapping['custom_tables'] ?? array();

			if ( empty( $post_types ) && empty( $custom_tables ) ) {
				continue;
			}

			$result[ $mapping['plugin_slug'] ] = $mapping['plugin_name'];
		}

		return $result;
	}

	/**
	 * Get plugin objects (replaces wptsall_known_plugin_objects())
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Array with post_types and taxonomies.
	 */
	public static function get_plugin_objects( $plugin_slug ) {
		// Special case for wordpress-blog
		if ( 'wordpress-blog' === $plugin_slug ) {
			return array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array( 'category', 'post_tag' ),
			);
		}

		$mapping = self::get_by_slug( $plugin_slug );

		if ( ! $mapping ) {
			return array(
				'post_types' => array(),
				'taxonomies' => array(),
			);
		}

		// Filter to only return actually registered types
		$post_types = array_filter(
			$mapping['post_types'],
			function ( $pt ) {
				return post_type_exists( $pt );
			}
		);

		$taxonomies = array_filter(
			$mapping['taxonomies'],
			function ( $tax ) {
				return taxonomy_exists( $tax );
			}
		);

		return array(
			'post_types'    => array_values( $post_types ),
			'taxonomies'    => array_values( $taxonomies ),
			'meta_fields'   => $mapping['meta_fields'] ?? array(),
			'custom_tables' => $mapping['custom_tables'] ?? array(),
		);
	}

	/**
	 * Get content plugins without models (for dropdown)
	 *
	 * Only returns plugins that:
	 * 1. Are marked as content plugins
	 * 2. Don't have a model yet
	 * 3. Have actual public frontend URLs (verified at runtime)
	 *
	 * @return array Plugin slug => plugin name.
	 */
	public static function get_content_plugins_without_models() {
		// Get all content plugins
		$mappings = self::get_all(
			array(
				'is_content_plugin' => 1,
			)
		);

		// Get plugins that already have models (from models table directly, not relying on has_model flag)
		$plugins_with_models = Translation_Rule_Service::get_plugins_with_models();

		$result = array();

		foreach ( $mappings as $mapping ) {
			// Skip if already has a model
			if ( in_array( $mapping['plugin_slug'], $plugins_with_models, true ) ) {
				continue;
			}

			// Runtime validation: check if there is actually a frontend URL
			if ( ! self::has_public_frontend_urls( $mapping ) ) {
				continue;
			}

			$result[ $mapping['plugin_slug'] ] = $mapping['plugin_name'];
		}

		return $result;
	}

	/**
	 * Check if a plugin mapping has public frontend URLs
	 *
	 * Verifies at runtime that the plugin's post_types and taxonomies
	 * are actually registered and have public URLs.
	 *
	 * @param array $mapping Plugin mapping data.
	 * @return bool True if has public frontend URLs.
	 */
	public static function has_public_frontend_urls( $mapping ) {
		$post_types = $mapping['post_types'] ?? array();
		$taxonomies = $mapping['taxonomies'] ?? array();

		// Check post types
		foreach ( $post_types as $pt ) {
			$pt_name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
			if ( empty( $pt_name ) ) {
				continue;
			}
			
			$obj = get_post_type_object( $pt_name );
			if ( ! $obj ) {
				continue;
			}

			// Has public frontend URL (single page or archive)
			if ( $obj->publicly_queryable || ( $obj->public && ! empty( $obj->rewrite ) ) ) {
				return true;
			}
		}

		// Check taxonomies
		foreach ( $taxonomies as $tax ) {
			$tax_name = is_array( $tax ) ? ( $tax['name'] ?? '' ) : $tax;
			if ( empty( $tax_name ) ) {
				continue;
			}

			$obj = get_taxonomy( $tax_name );
			if ( ! $obj ) {
				continue;
			}

			// Has public frontend archive page
			if ( $obj->publicly_queryable || ( $obj->public && ! empty( $obj->rewrite ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scan and save all active plugins
	 *
	 * @return array Stats about the scan.
	 */
	public static function scan_and_save_all() {
		$start_time = microtime( true );

		wptsall_log_info(
			'models',
			'Plugin_Mapping_Service starting scan_and_save_all'
		);

		// Clear orphan resolver cache to get fresh dynamic mappings
		if ( class_exists( '\WPTSALL\Models\Scanners\Orphan_Resolver' ) ) {
			\WPTSALL\Models\Scanners\Orphan_Resolver::clear_cache();
		}

		// Scan all plugins
		$scan_results = Plugin_Scanner::scan_all_plugins();

		$stats = array(
			'total_scanned'   => 0,
			'content_plugins' => 0,
			'saved'           => 0,
			'orphans_resolved' => 0,
		);

		foreach ( $scan_results as $plugin_slug => $result ) {
			++$stats['total_scanned'];

			if ( $result['is_content_plugin'] ) {
				++$stats['content_plugins'];
			}

			// ISS-MOD-030: Skip saving if scan result is empty (no objects/fields).
			if ( self::is_scan_result_empty( $result ) ) {
				wptsall_log_debug(
					'models',
					'Skipping empty plugin scan result',
					array( 'plugin_slug' => $plugin_slug )
				);
				continue;
			}

			$saved = self::save( $result );
			if ( $saved ) {
				++$stats['saved'];

				// Persist plugin template objects/fields (B: plugin -> objects -> fields).
				if ( class_exists( '\WPTSALL\Models\Services\Model_Object_Service' ) ) {
					$sync_stats = \WPTSALL\Models\Services\Model_Object_Service::sync_from_scan( (int) $saved, $plugin_slug, $result );
					wptsall_log_debug(
						'models',
						'Model objects synced from scan',
						array(
							'plugin_slug' => $plugin_slug,
							'model_id'    => (int) $saved,
							'stats'       => $sync_stats,
						)
					);
				}
			}
		}

		// Resolve orphan post_types and taxonomies after initial scan
		$orphan_stats = self::resolve_orphans();
		$stats['orphans_resolved'] = $orphan_stats['resolved'];
		$stats['orphans_remaining'] = $orphan_stats['remaining'];

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );
		wptsall_log_info(
			'models',
			'Plugin_Mapping_Service scan_and_save_all completed',
			array(
				'total_scanned'    => $stats['total_scanned'],
				'content_plugins'  => $stats['content_plugins'],
				'saved'            => $stats['saved'],
				'orphans_resolved' => $stats['orphans_resolved'],
				'duration_ms'      => $duration_ms,
			)
		);

		return $stats;
	}

	/**
	 * Scan and save selected active plugins
	 *
	 * @param array $plugin_slugs Plugin slugs to scan.
	 * @return array Stats about the scan.
	 */
	public static function scan_and_save_selected( $plugin_slugs ) {
		$start_time = microtime( true );
		$plugin_slugs = array_values( array_filter( array_map( 'sanitize_key', (array) $plugin_slugs ) ) );

		wptsall_log_info(
			'models',
			'Plugin_Mapping_Service starting scan_and_save_selected',
			array(
				'plugins' => $plugin_slugs,
			)
		);

		// Clear orphan resolver cache to get fresh dynamic mappings
		if ( class_exists( '\WPTSALL\Models\Scanners\Orphan_Resolver' ) ) {
			\WPTSALL\Models\Scanners\Orphan_Resolver::clear_cache();
		}

		$scan_results = array();

		// Always include WordPress core (wordpress-blog)
		$blog_result = Plugin_Scanner::scan_blog();
		if ( $blog_result ) {
			$scan_results['wordpress-blog'] = $blog_result;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( ! empty( $network_plugins ) && is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		$active_plugins = array_unique( $active_plugins );

		foreach ( $active_plugins as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			$plugin_slug = Plugin_Scanner::get_plugin_slug( $plugin_file );
			if ( wptsall_is_self_plugin_slug( $plugin_slug ) ) {
				continue;
			}

			if ( ! in_array( $plugin_slug, $plugin_slugs, true ) ) {
				continue;
			}

			$result = Plugin_Scanner::scan_plugin( $plugin_slug, $plugin_file, $all_plugins[ $plugin_file ] );
			if ( $result ) {
				$scan_results[ $plugin_slug ] = $result;
			}
		}

		$stats = array(
			'total_scanned'    => 0,
			'content_plugins'  => 0,
			'saved'            => 0,
			'orphans_resolved' => 0,
			'orphans_remaining' => 0,
		);

		foreach ( $scan_results as $plugin_slug => $result ) {
			++$stats['total_scanned'];

			if ( $result['is_content_plugin'] ) {
				++$stats['content_plugins'];
			}

			// ISS-MOD-030: Skip saving if scan result is empty (no objects/fields).
			if ( self::is_scan_result_empty( $result ) ) {
				wptsall_log_debug(
					'models',
					'Skipping empty plugin scan result',
					array( 'plugin_slug' => $plugin_slug )
				);
				continue;
			}

			$saved = self::save( $result );
			if ( $saved ) {
				++$stats['saved'];

				// Persist plugin template objects/fields (B: plugin -> objects -> fields).
				if ( class_exists( '\WPTSALL\Models\Services\Model_Object_Service' ) ) {
					$sync_stats = \WPTSALL\Models\Services\Model_Object_Service::sync_from_scan( (int) $saved, $plugin_slug, $result );
					wptsall_log_debug(
						'models',
						'Model objects synced from scan',
						array(
							'plugin_slug' => $plugin_slug,
							'model_id'    => (int) $saved,
							'stats'       => $sync_stats,
						)
					);
				}
			}
		}

		$orphan_stats = self::resolve_orphans();
		$stats['orphans_resolved'] = $orphan_stats['resolved'];
		$stats['orphans_remaining'] = $orphan_stats['remaining'];

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );
		wptsall_log_info(
			'models',
			'Plugin_Mapping_Service scan_and_save_selected completed',
			array(
				'total_scanned'    => $stats['total_scanned'],
				'content_plugins'  => $stats['content_plugins'],
				'saved'            => $stats['saved'],
				'orphans_resolved' => $stats['orphans_resolved'],
				'duration_ms'      => $duration_ms,
			)
		);

		return $stats;
	}

	/**
	 * Resolve orphan post_types and taxonomies
	 *
	 * Finds content types not assigned to any plugin and tries to assign them
	 * using Orphan_Resolver strategies (known mappings, runtime tracking, fuzzy matching).
	 *
	 * @return array Stats about orphan resolution.
	 */
	protected static function resolve_orphans() {
		$stats = array(
			'resolved'  => 0,
			'remaining' => 0,
		);

		if ( ! class_exists( '\WPTSALL\Models\Scanners\Orphan_Resolver' ) ) {
			return $stats;
		}

		$resolver = '\WPTSALL\Models\Scanners\Orphan_Resolver';

		// Resolve all orphans
		$resolutions = $resolver::resolve_all_orphans();

		// Apply resolutions to plugin mappings
		$apply_result = $resolver::apply_resolutions( $resolutions );

		$stats['resolved']  = $apply_result['updated'] ?? 0;
		$stats['remaining'] = count( $resolutions['unresolved']['post_types'] ?? array() )
			+ count( $resolutions['unresolved']['taxonomies'] ?? array() );

		// Handle CPTUI post_types specially - create a model for them
		self::handle_cptui_types( $resolutions );

		return $stats;
	}

	/**
	 * Handle Custom Post Type UI types
	 *
	 * Creates or updates a model for CPTUI-created post_types if any are found.
	 *
	 * @param array $resolutions Resolution results from Orphan_Resolver.
	 */
	protected static function handle_cptui_types( $resolutions ) {
		$cptui_pts = array();
		$cptui_taxes = array();

		// Collect CPTUI types from resolutions
		foreach ( $resolutions['post_types'] as $pt => $data ) {
			if ( 'custom-post-type-ui' === ( $data['plugin_slug'] ?? '' ) ) {
				$cptui_pts[] = $pt;
			}
		}

		foreach ( $resolutions['taxonomies'] as $tax => $data ) {
			if ( 'custom-post-type-ui' === ( $data['plugin_slug'] ?? '' ) ) {
				$cptui_taxes[] = $tax;
			}
		}

		// Also check unresolved types against CPTUI option
		if ( class_exists( '\WPTSALL\Models\Scanners\Orphan_Resolver' ) ) {
			$cptui_option_pts = \WPTSALL\Models\Scanners\Orphan_Resolver::get_cptui_types();
			$cptui_option_taxes = \WPTSALL\Models\Scanners\Orphan_Resolver::get_cptui_taxonomies();

			foreach ( $resolutions['unresolved']['post_types'] as $orphan ) {
				if ( in_array( $orphan['name'], $cptui_option_pts, true ) ) {
					$cptui_pts[] = $orphan['name'];
				}
			}

			foreach ( $resolutions['unresolved']['taxonomies'] as $orphan ) {
				if ( in_array( $orphan['name'], $cptui_option_taxes, true ) ) {
					$cptui_taxes[] = $orphan['name'];
				}
			}
		}

		// If CPTUI types exist, save mapping
		if ( ! empty( $cptui_pts ) || ! empty( $cptui_taxes ) ) {
			self::save(
				array(
					'plugin_slug'       => 'custom-post-type-ui',
					'plugin_name'       => 'Custom Post Type UI',
					'is_content_plugin' => 1,
					'post_types'        => array_unique( $cptui_pts ),
					'taxonomies'        => array_unique( $cptui_taxes ),
					'detection_method'  => 'cptui_option',
				)
			);
		}
	}

	/**
	 * Check if a scan result is empty (no objects or fields discovered).
	 *
	 * ISS-MOD-030: Prevents creating empty model records.
	 *
	 * @since 1.0.3
	 *
	 * @param array $result Scan result array.
	 * @return bool True if all four categories are empty.
	 */
	private static function is_scan_result_empty( $result ) {
		if ( ! is_array( $result ) ) {
			return true;
		}

		$post_types    = $result['post_types'] ?? array();
		$taxonomies    = $result['taxonomies'] ?? array();
		$meta_fields   = $result['meta_fields'] ?? array();
		$custom_tables = $result['custom_tables'] ?? array();

		return empty( $post_types ) && empty( $taxonomies ) && empty( $meta_fields ) && empty( $custom_tables );
	}

	/**
	 * Check if initialization is complete
	 *
	 * @return bool True if initialized.
	 */
	public static function is_initialized() {
		$initialized = (bool) get_option( 'wptsall_initialized', false );
		if ( ! $initialized ) {
			return false;
		}

		// Self-heal: If someone removed plugin tables (manual DB cleanup, partial uninstall, etc.)
		// but the option flag survived, force re-initialization.
		if ( ! self::has_required_storage() ) {
			delete_option( 'wptsall_initialized' );
			wptsall_log_warning(
				'models',
				'Initialization flag is set but required tables are missing; forcing re-initialization',
				array(
					'blog_id' => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : null,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Whether the plugin has the minimum required storage to operate.
	 *
	 * Initialization UI/redirect logic relies on this to avoid false positives
	 * when the option flag exists but tables were dropped.
	 *
	 * @return bool
	 */
	private static function has_required_storage() {
		global $wpdb;

		$required_tables = array(
			wptsall_table( 'plugin_mappings' ),
			wptsall_table( 'models' ),
			wptsall_table( 'translation_rules' ),
		);

		foreach ( $required_tables as $table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
			if ( $exists !== $table_name ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Mark initialization as complete
	 *
	 * @return bool True on success.
	 */
	public static function mark_initialized() {
		return update_option( 'wptsall_initialized', true );
	}

	/**
	 * Reset initialization status
	 *
	 * @return bool True on success.
	 */
	public static function reset_initialization() {
		return delete_option( 'wptsall_initialized' );
	}

	// ==========================================
	// V4 Scan Result Methods (v0.9.0)
	// ==========================================

	/**
	 * Save V4 scan result
	 *
	 * Writes scanner output to the models.scan_result column. This data is used
	 * by Model_Config_Provider for field metadata queries (URL info, object metadata).
	 *
	 * IMPORTANT: The scan_result column is an archive/reference copy of raw scanner
	 * output. Translation rule creation reads from this data during
	 * create_rules_for_model(), but the AUTHORITATIVE source for sync behavior
	 * is translation_rules.field_capabilities (managed by Translation_Rule_Service).
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param array  $scan_result V4 scanner output (object_metadata, url_info, fields).
	 * @param string $scan_version Scanner version (e.g., 'v4.2').
	 * @return bool True on success.
	 */
	public static function save_scan_result( $plugin_slug, $scan_result, $scan_version = null ) {
		global $wpdb;

		$table = wptsall_table( 'models' );
		$now   = current_time( 'mysql' );

		// Get scanner version.
		if ( null === $scan_version ) {
			if ( class_exists( '\WPTSALL\Models\Scanners\Smart_Field_Scanner' ) ) {
				$scan_version = \WPTSALL\Models\Scanners\Smart_Field_Scanner::get_version();
			} else {
				$scan_version = 'v4.2';
			}
		}

		$data = array(
			'scan_result'  => wp_json_encode( $scan_result ),
			'scan_version' => sanitize_text_field( $scan_version ),
			'scan_status'  => self::SCAN_STATUS_COMPLETED,
			'scan_error'   => null,
			'last_scanned' => $now,
			'updated_at'   => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'plugin_slug' => $plugin_slug ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);

		if ( false !== $result ) {
			// Clear Model_Config_Provider cache.
			if ( class_exists( '\WPTSALL\Models\Services\Model_Config_Provider' ) ) {
				Model_Config_Provider::clear_cache( $plugin_slug );
			}

			wptsall_log_info(
				'models',
				'Scan result saved',
				array(
					'plugin_slug'  => $plugin_slug,
					'scan_version' => $scan_version,
					'post_types'   => array_keys( $scan_result ),
				)
			);
		}

		return false !== $result;
	}

	/**
	 * Save scan result for archive/debug purposes only.
	 *
	 * Writes to the models.scan_result column as an archival copy of raw scanner
	 * output. This data is NOT a data source for rule creation; the authoritative
	 * source for sync behavior is translation_rules.field_capabilities.
	 *
	 * Use this method when you want to explicitly archive a scan result without
	 * changing scan_status or scan_version metadata.
	 *
	 * @since 1.5.0
	 *
	 * @param int   $model_id    Model ID.
	 * @param array $scan_result Raw scanner output to archive.
	 * @return void
	 */
	public static function save_scan_result_archive( int $model_id, array $scan_result ): void {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'scan_result' => wp_json_encode( $scan_result ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $model_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wptsall_log_debug(
			'models',
			'Scan result archived (debug/archive only, not a rule creation source)',
			array(
				'model_id'   => $model_id,
				'post_types' => array_keys( $scan_result ),
			)
		);
	}

	/**
	 * Update scan status
	 *
	 * @param string      $plugin_slug Plugin slug.
	 * @param string      $status      Scan status (pending|scanning|completed|failed).
	 * @param string|null $error       Error message (for failed status).
	 * @return bool True on success.
	 */
	public static function update_scan_status( $plugin_slug, $status, $error = null ) {
		global $wpdb;

		$table = wptsall_table( 'models' );
		$now   = current_time( 'mysql' );

		$data = array(
			'scan_status' => sanitize_text_field( $status ),
			'updated_at'  => $now,
		);

		$format = array( '%s', '%s' );

		if ( null !== $error ) {
			$data['scan_error'] = sanitize_text_field( $error );
			$format[]           = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'plugin_slug' => $plugin_slug ),
			$format,
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Set user consent for scanning
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param bool   $consent     Consent status.
	 * @return bool True on success.
	 */
	public static function set_user_consent( $plugin_slug, $consent = true ) {
		global $wpdb;

		$table = wptsall_table( 'models' );
		$now   = current_time( 'mysql' );

		$data = array(
			'user_consent' => $consent ? 1 : 0,
			'updated_at'   => $now,
		);

		if ( $consent ) {
			$data['consent_at'] = $now;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'plugin_slug' => $plugin_slug ),
			$consent ? array( '%d', '%s', '%s' ) : array( '%d', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Set user consent for all plugins
	 *
	 * @param bool $consent Consent status.
	 * @return int Number of plugins updated.
	 */
	public static function set_all_user_consent( $consent = true ) {
		global $wpdb;

		$table = wptsall_table( 'models' );
		$now   = current_time( 'mysql' );

		$data = array(
			'user_consent' => $consent ? 1 : 0,
			'updated_at'   => $now,
		);

		if ( $consent ) {
			$data['consent_at'] = $now;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET user_consent = %d, consent_at = %s, updated_at = %s WHERE is_content_plugin = 1',
				$table,
				$consent ? 1 : 0,
				$consent ? $now : null,
				$now
			)
		);

		return (int) $result;
	}

	/**
	 * Run V4 scan for a plugin
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param bool   $force       Force re-scan even if already scanned.
	 * @return array|false Scan result or false on failure.
	 */
	public static function run_v4_scan( $plugin_slug, $force = false ) {
		// Check user consent.
		$mapping = self::get_by_slug( $plugin_slug );

		if ( ! $mapping ) {
			wptsall_log_error(
				'models',
				'Plugin not found for V4 scan',
				array( 'plugin_slug' => $plugin_slug )
			);
			return false;
		}

		if ( empty( $mapping['user_consent'] ) ) {
			wptsall_log_warning(
				'models',
				'V4 scan requires user consent',
				array( 'plugin_slug' => $plugin_slug )
			);
			return false;
		}

		// Check if already scanned.
		if ( ! $force && self::SCAN_STATUS_COMPLETED === ( $mapping['scan_status'] ?? '' ) ) {
			wptsall_log_debug(
				'models',
				'Plugin already scanned, skipping',
				array( 'plugin_slug' => $plugin_slug )
			);
			return false;
		}

		// Update status to scanning.
		self::update_scan_status( $plugin_slug, self::SCAN_STATUS_SCANNING );

		try {
			// Get post types for this plugin.
			$raw_post_types = $mapping['post_types'] ?? array();

			if ( empty( $raw_post_types ) ) {
				self::update_scan_status( $plugin_slug, self::SCAN_STATUS_FAILED, 'No post types found' );
				return false;
			}

			// Extract post_type name strings from object array.
			$post_types = array();
			foreach ( $raw_post_types as $pt ) {
				if ( is_array( $pt ) && ! empty( $pt['name'] ) ) {
					$post_types[] = $pt['name'];
				} elseif ( is_string( $pt ) ) {
					$post_types[] = $pt;
				}
			}

			if ( empty( $post_types ) ) {
				self::update_scan_status( $plugin_slug, self::SCAN_STATUS_FAILED, 'No valid post type names found' );
				return false;
			}

			// Run V4 scanner.
			if ( ! class_exists( '\WPTSALL\Models\Scanners\Smart_Field_Scanner' ) ) {
				self::update_scan_status( $plugin_slug, self::SCAN_STATUS_FAILED, 'Smart_Field_Scanner class not found' );
				return false;
			}

			$scanner     = new \WPTSALL\Models\Scanners\Smart_Field_Scanner( $plugin_slug, $post_types );
			$scan_result = $scanner->scan();

			// Save result.
			self::save_scan_result( $plugin_slug, $scan_result );

			return $scan_result;

		} catch ( \Exception $e ) {
			self::update_scan_status( $plugin_slug, self::SCAN_STATUS_FAILED, $e->getMessage() );

			wptsall_log_error(
				'models',
				'V4 scan failed with exception',
				array(
					'plugin_slug' => $plugin_slug,
					'error'       => $e->getMessage(),
				)
			);

			return false;
		}
	}

	/**
	 * Run V4 scan for all consented plugins
	 *
	 * @param bool $force Force re-scan even if already scanned.
	 * @return array Stats about the scan.
	 */
	public static function run_v4_scan_all( $force = false ) {
		$stats = array(
			'total'     => 0,
			'scanned'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'no_consent' => 0,
		);

		$mappings = self::get_all( array( 'is_content_plugin' => 1 ) );

		foreach ( $mappings as $mapping ) {
			++$stats['total'];

			if ( empty( $mapping['user_consent'] ) ) {
				++$stats['no_consent'];
				continue;
			}

			if ( ! $force && self::SCAN_STATUS_COMPLETED === ( $mapping['scan_status'] ?? '' ) ) {
				++$stats['skipped'];
				continue;
			}

			$result = self::run_v4_scan( $mapping['plugin_slug'], $force );

			if ( false !== $result ) {
				++$stats['scanned'];
			} else {
				++$stats['failed'];
			}
		}

		return $stats;
	}

	/**
	 * Get plugins pending scan (with consent but not yet scanned)
	 *
	 * @return array Array of plugin mappings.
	 */
	public static function get_pending_scans() {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE is_content_plugin = 1
				AND user_consent = 1
				AND (scan_status IS NULL OR scan_status = %s OR scan_status = %s)
				ORDER BY plugin_name ASC',
				$table,
				self::SCAN_STATUS_PENDING,
				self::SCAN_STATUS_FAILED
			),
			ARRAY_A
		);

		// Decode JSON fields.
		foreach ( $results as &$row ) {
			$row['post_types'] = json_decode( $row['post_types'] ?? '[]', true ) ?: array();
			$row['taxonomies'] = json_decode( $row['taxonomies'] ?? '[]', true ) ?: array();
		}

		return $results;
	}

	/**
	 * Get plugins needing consent
	 *
	 * @return array Array of plugin mappings without user consent.
	 */
	public static function get_plugins_needing_consent() {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE is_content_plugin = 1
				AND (user_consent IS NULL OR user_consent = %d)
				ORDER BY plugin_name ASC',
				$table,
				0
			),
			ARRAY_A
		);

		// Decode JSON fields.
		foreach ( $results as &$row ) {
			$row['post_types'] = json_decode( $row['post_types'] ?? '[]', true ) ?: array();
			$row['taxonomies'] = json_decode( $row['taxonomies'] ?? '[]', true ) ?: array();
		}

		return $results;
	}
}
