<?php
/**
 * Site Relation Service
 *
 * Site Relation Service - handles CRUD operations for site relations
 *
 * v0.7.0 changes:
 * - template field marked as deprecated, will be removed in a future version
 * - Newly created site relations will automatically sync template to relation_models table
 * - Recommend using Relation_Model_Service to manage model associations
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.4.0 Refactored to one-to-one storage structure
 * @updated 0.6.0 Support many-to-many model associations (via relation_models table)
 * @updated 0.7.0 Deprecate template field
 */

namespace WPTSALL\Sites\Services;

use WPTSALL\Sites\Validators\Site_Relation_Validator;
use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Models\Scanners\Model_Scanner_V2;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site_Relation_Service class
 *
 * Provides business logic for site relation management (one-to-one structure)
 */
class Site_Relation_Service {

	/**
	 * Cache group name
	 *
	 * @since 0.9.0
	 * @var string
	 */
	const CACHE_GROUP = 'wptsall_site_relations';

	/**
	 * Cache expiration time in seconds (1 hour)
	 *
	 * @since 0.9.0
	 * @var int
	 */
	const CACHE_EXPIRATION = 3600;

	/**
	 * Generate cache key for relations query
	 *
	 * @since 0.9.0
	 * @param string $prefix Key prefix.
	 * @param array  $params Query parameters.
	 * @return string Cache key.
	 */
	private static function get_cache_key( $prefix, $params = array() ) {
		return $prefix . '_' . md5( wp_json_encode( $params ) );
	}

	/**
	 * Clear all site relations cache
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public static function clear_cache() {
		wp_cache_delete( 'all_relations', self::CACHE_GROUP );
		wp_cache_delete( 'grouped_relations', self::CACHE_GROUP );

		// Clear individual relation caches by incrementing version
		$version = (int) wp_cache_get( 'cache_version', self::CACHE_GROUP );
		wp_cache_set( 'cache_version', $version + 1, self::CACHE_GROUP );

		if ( function_exists( 'wptsall_log_debug' ) ) {
			wptsall_log_debug( 'sites-cache', 'Site relations cache cleared' );
		}
	}

	/**
	 * Check for circular dependency
	 *
	 * Detects if creating a relation from A→B would create a circular dependency
	 * (i.e., if B→A already exists).
	 *
	 * @since 0.9.0
	 * @param int    $source_site_id Source site ID.
	 * @param string $source_lang    Source language.
	 * @param string $template       Model template.
	 * @param array  $target_sites   Target sites array.
	 * @return array Array of circular dependencies found, empty if none.
	 */
	public static function check_circular_dependency( $source_site_id, $source_lang, $template, $target_sites ) {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		$circular = array();

		// Only WP sites can be sources; virtual targets won't participate in cycles.
		$source_node = (int) $source_site_id . '|' . (string) $source_lang;

		// Build adjacency list from existing active wp->wp relations for this template.
		$adj = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_site_id, source_lang, target_site_id, target_lang
				 FROM %i
				 WHERE template = %s
				 AND target_site_type = %s
				 AND status = %s',
				$table,
				$template,
				'wp',
				'active'
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$from_id   = (int) ( $row['source_site_id'] ?? 0 );
			$from_lang = (string) ( $row['source_lang'] ?? '' );
			$to_id_raw = (string) ( $row['target_site_id'] ?? '' );
			$to_lang   = (string) ( $row['target_lang'] ?? '' );

			if ( $from_id <= 0 || '' === $to_id_raw || ! is_numeric( $to_id_raw ) ) {
				continue;
			}

			$from = $from_id . '|' . $from_lang;
			$to   = ( (int) $to_id_raw ) . '|' . $to_lang;

			if ( ! isset( $adj[ $from ] ) ) {
				$adj[ $from ] = array();
			}
			$adj[ $from ][] = $to;
		}

		foreach ( $target_sites as $target ) {
			$target_id   = $target['id'];
			$target_type = $target['type'] ?? 'wp';
			$target_lang = $target['lang'] ?? '';

			// Only check WP targets (virtual sites can't be sources).
			if ( 'wp' !== $target_type || ! is_numeric( $target_id ) ) {
				continue;
			}

			$target_node = ( (int) $target_id ) . '|' . (string) $target_lang;

			// BFS from target_node to see if we can reach source_node.
			$queue   = array( $target_node );
			$visited = array( $target_node => true );
			$parent  = array(); // child => parent node, for path reconstruction.
			$found   = false;

			while ( ! empty( $queue ) ) {
				$current = array_shift( $queue );

				if ( $current === $source_node ) {
					$found = true;
					break;
				}

				$neighbors = $adj[ $current ] ?? array();
				foreach ( $neighbors as $next ) {
					if ( isset( $visited[ $next ] ) ) {
						continue;
					}
					$visited[ $next ] = true;
					$parent[ $next ]  = $current;
					$queue[]          = $next;
				}
			}

			if ( ! $found ) {
				continue;
			}

			// Reconstruct path: target -> ... -> source
			$path_nodes = array( $source_node );
			$cursor     = $source_node;
			while ( isset( $parent[ $cursor ] ) ) {
				$cursor = $parent[ $cursor ];
				$path_nodes[] = $cursor;
				if ( $cursor === $target_node ) {
					break;
				}
			}
			$path_nodes = array_reverse( $path_nodes );

			$circular[] = array(
				'source'      => array(
					'site_id' => $source_site_id,
					'lang'    => $source_lang,
				),
				'target'      => array(
					'site_id' => $target_id,
					'lang'    => $target_lang,
				),
				'template'    => $template,
				'path'        => $path_nodes,
				'description' => sprintf(
					/* translators: 1: source site ID, 2: target site ID, 3: template name */
					__( 'Circular dependency detected: adding %1$d -> %2$d would form a cycle (model: %3$s)', 'wpmmcc-ats' ),
					$source_site_id,
					$target_id,
					$template
				),
			);
		}

		return $circular;
	}

	/**
	 * Create site relation
	 *
	 * v0.4.0: Create a separate record for each target site
	 *
	 * @param array $data Relation data
	 *   - source_site_id (int): Source site ID
	 *   - source_lang (string): Source site language
	 *   - template (string): Model identifier
	 *   - target_sites (array): Target sites array [{id, type, lang}]
	 *   - auto_create_model (bool): Whether to auto-create model (default true)
	 * @return array Result array('success' => bool, 'relation_ids' => array(), 'errors' => array())
	 */
	public static function create_relation( $data ) {
		// Validate required fields
		$required = array( 'template', 'source_site_id', 'source_lang', 'target_sites' );
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) || ( '' === $data[ $field ] && 'source_lang' !== $field ) ) {
				return array(
					'success' => false,
					'errors'  => array(
						sprintf(
							/* translators: %s: field name */
							__( 'Missing required field: %s', 'wpmmcc-ats' ),
							$field
						),
					),
				);
			}
		}

		if ( empty( $data['target_sites'] ) || ! is_array( $data['target_sites'] ) ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'At least one target site is required', 'wpmmcc-ats' ) ),
			);
		}

		// Auto-create model (if not exists)
		$auto_create_model = $data['auto_create_model'] ?? true;
		if ( $auto_create_model ) {
			$model_result = self::ensure_model_exists( $data['template'], $data['source_site_id'] );
			if ( ! $model_result['success'] ) {
				return array(
					'success' => false,
					'errors'  => $model_result['errors'],
				);
			}
		}

		// Validate using validator
		$validation = Site_Relation_Validator::validate_new_relation( $data );

		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'errors'  => $validation['errors'],
			);
		}

		// v0.9.0: Circular dependency detection
		$allow_circular = $data['allow_circular'] ?? false;
		if ( ! $allow_circular ) {
			$circular = self::check_circular_dependency(
				(int) $data['source_site_id'],
				$data['source_lang'] ?? '',
				$data['template'],
				$data['target_sites']
			);

			if ( ! empty( $circular ) ) {
				// Log circular dependency warning
				wptsall_log_warning(
					'sites-relations',
					'Circular dependency detected',
					array(
						'source_site_id' => $data['source_site_id'],
						'source_lang'    => $data['source_lang'] ?? '',
						'template'       => $data['template'],
						'circular_count' => count( $circular ),
						'details'        => $circular,
					)
				);

				$error_messages = array_map(
					function ( $c ) {
						return $c['description'];
					},
					$circular
				);

				return array(
					'success'  => false,
					'errors'   => $error_messages,
					'circular' => $circular,
				);
			}
		}

		// Get source site theme info
		$source_theme = self::get_site_theme_info( $data['source_site_id'] );

		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		$relation_ids = array();
		$errors       = array();
		$now          = current_time( 'mysql' );

		// Create a separate record for each target site
		foreach ( $data['target_sites'] as $target ) {
			$target_id   = $target['id'];
			$target_type = $target['type'] ?? 'wp';
			$target_lang = $target['lang'] ?? $target['lang_to'] ?? '';

			// Normalize virtual site IDs to v_ prefix format for consistent storage.
			if ( 'virtual' === $target_type ) {
				$target_id = Site_Relation_Validator::format_virtual_site_id( $target_id );
			}

			// Get target site theme info (if wp site or resolvable virtual site)
			$target_theme = array( 'name' => '', 'path' => '' );
			if ( 'wp' === $target_type && is_numeric( $target_id ) ) {
				$target_theme = self::get_site_theme_info( (int) $target_id );
			} elseif ( 'virtual' === $target_type ) {
				$virtual_id  = Site_Relation_Validator::parse_virtual_site_id( $target_id );
				$virtual_site = Virtual_Site_Service::get( $virtual_id );
				if ( $virtual_site ) {
					$target_theme = array(
						'name' => $virtual_site['name'] ?? '',
						'path' => $virtual_site['path_prefix'] ?? '',
					);
					if ( empty( $target_lang ) && ! empty( $virtual_site['lang'] ) ) {
						$target_lang = $virtual_site['lang'];
					}
				}
			}

			// Detect plugin status
			$plugin_status = self::detect_single_plugin_status(
				$data['template'],
				$data['source_site_id'],
				$target_id,
				$target_type
			);

			// Get media_handling from request data (default: 'copy').
			$media_handling = isset( $data['media_handling'] ) && in_array( $data['media_handling'], array( 'copy', 'reference' ), true )
				? $data['media_handling']
				: 'copy';

			// Build insert data.
			$insert_data = array(
				'source_site_id'    => (int) $data['source_site_id'],
				'source_site_type'  => 'wp',
				'source_lang'       => sanitize_text_field( $data['source_lang'] ),
				'source_theme_name' => sanitize_text_field( $source_theme['name'] ),
				'source_theme_path' => sanitize_text_field( $source_theme['path'] ),
				'template'          => sanitize_key( $data['template'] ),
				'target_site_id'    => sanitize_text_field( (string) $target_id ),
				'target_site_type'  => sanitize_key( $target_type ),
				'target_lang'       => sanitize_text_field( $target_lang ),
				'target_theme_name' => sanitize_text_field( $target_theme['name'] ),
				'target_theme_path' => sanitize_text_field( $target_theme['path'] ),
				'status'            => 'active',
				'plugin_status'     => wp_json_encode( $plugin_status ),
				'media_handling'    => $media_handling,
				// Product decision (2026-01-29): only first delivery + backfill, no update/delete push.
				// Keep column value normalized even if schema default is legacy ("full").
				'sync_mode'         => 'new_only',
				'created_at'        => $now,
				'updated_at'        => $now,
			);
			$insert_formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

			// Insert into database
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$table,
				$insert_data,
				$insert_formats
			);

			if ( false === $result ) {
				$errors[] = sprintf(
					/* translators: %1$s: target site id, %2$s: database error */
					__( 'Failed to create target site %1$s: %2$s', 'wpmmcc-ats' ),
					$target_id,
					$wpdb->last_error
				);
				continue;
			}

			$relation_ids[] = $wpdb->insert_id;
		}

		if ( empty( $relation_ids ) ) {
			return array(
				'success' => false,
				'errors'  => $errors,
			);
		}

		// Log entry
		wptsall_log_info(
			'sites-relations',
			'Site relations created',
			array(
				'relation_ids'   => $relation_ids,
				'template'       => $data['template'],
				'source_site_id' => $data['source_site_id'],
				'source_lang'    => $data['source_lang'],
				'target_count'   => count( $relation_ids ),
			)
		);

		// v0.9.0: Clear cache
		self::clear_cache();

		// Trigger action
		do_action( 'wptsall_site_relations_created', $relation_ids, $data );

		return array(
			'success'      => true,
			'relation_ids' => $relation_ids,
			'errors'       => $errors,
		);
	}

	/**
	 * Add target sites to existing relation group
	 *
	 * @param int    $source_site_id Source site ID
	 * @param string $source_lang    Source site language
	 * @param string $template       Model identifier
	 * @param array  $new_targets    New target sites array
	 * @return array Result
	 */
	public static function add_target_sites( $source_site_id, $source_lang, $template, $new_targets ) {
		// Validate new target sites
		$validation = Site_Relation_Validator::validate_add_targets(
			$source_site_id,
			$source_lang,
			$template,
			$new_targets
		);

		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'errors'  => $validation['errors'],
			);
		}

		// Create new relation records
		return self::create_relation( array(
			'source_site_id' => $source_site_id,
			'source_lang'    => $source_lang,
			'template'       => $template,
			'target_sites'   => $new_targets,
		) );
	}

	/**
	 * Delete a single site relation
	 *
	 * @since 0.3.0
	 * @updated 0.6.0 Also deletes relation_models association records
	 *
	 * @param int $relation_id Relation ID
	 * @return array Result
	 */
	public static function delete_relation( $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// Fetch relation info for logging
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $relation_id ),
			ARRAY_A
		);

		if ( ! $relation ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Relation does not exist', 'wpmmcc-ats' ) ),
			);
		}

		// v0.6.0: Delete relation_models association records first
		Relation_Model_Service::delete_relation_models( $relation_id );

		// v0.9.0: Delete associated hook records
		\WPTSALL\Hooks\Hook_Manager::delete_hooks_by_site( $relation_id );

		// v1.2.0: Cascade delete relation_post_type_configs.
		$configs_table = wptsall_table( 'relation_post_type_configs' );
		if ( $configs_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $configs_table, array( 'relation_id' => $relation_id ), array( '%d' ) );
		}

		// v1.2.0: Cascade delete monitoring tasks for this relation.
		if ( class_exists( '\WPTSALL\Tasks\Services\Monitoring_Task_Service' ) ) {
			\WPTSALL\Tasks\Services\Monitoring_Task_Service::delete_by_relation( $relation_id );
		}

		// Delete site relation
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array( 'id' => $relation_id ),
			array( '%d' )
		);

		wptsall_log_info(
			'sites-relations',
			'Site relation deleted',
			array(
				'relation_id' => $relation_id,
				'template'    => $relation['template'],
				'target_id'   => $relation['target_site_id'],
			)
		);

		// v0.9.0: Clear cache
		self::clear_cache();

		do_action( 'wptsall_site_relation_deleted', $relation_id, $relation );

		return array( 'success' => true );
	}

	/**
	 * Update relation status
	 *
	 * @param int    $relation_id Relation ID
	 * @param string $status      Status (active/inactive)
	 * @return array Result
	 */
	public static function update_status( $relation_id, $status ) {
		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Invalid status value', 'wpmmcc-ats' ) ),
			);
		}

		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// Check if relation exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id = %d',
				$table,
				$relation_id
			)
		);

		if ( ! $exists ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Site relation does not exist', 'wpmmcc-ats' ) ),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $relation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Update failed', 'wpmmcc-ats' ) ),
			);
		}

		wptsall_log_info(
			'sites-relations',
			'Site relation status updated',
			array(
				'relation_id' => $relation_id,
				'status'      => $status,
			)
		);

		do_action( 'wptsall_site_relation_status_updated', $relation_id, $status );

		return array( 'success' => true );
	}

	/**
	 * Get all relations (one-to-one structure)
	 *
	 * @since 0.3.0
	 * @updated 0.9.0 Added object cache support
	 *
	 * @param array $filters Filter criteria
	 *   - template (string): Model identifier
	 *   - source_site_id (int): Source site ID
	 *   - source_lang (string): Source site language
	 *   - status (string): Status
	 * @param bool  $use_cache Whether to use cache (default true)
	 * @return array List of relations
	 */
	public static function get_all_relations( $filters = array(), $use_cache = true ) {
		// v0.9.0: Try to fetch from cache
		$cache_key = self::get_cache_key( 'all_relations', $filters );
		if ( $use_cache ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// Build SQL from fixed placeholder fragments only.
		$where  = array( '1=1' );
		$params = array( $table );
		if ( ! empty( $filters['template'] ) ) {
			$where[]  = 'template = %s';
			$params[] = $filters['template'];
		}
		if ( ! empty( $filters['source_site_id'] ) ) {
			$where[]  = 'source_site_id = %d';
			$params[] = (int) $filters['source_site_id'];
		}
		if ( isset( $filters['source_lang'] ) && '' !== $filters['source_lang'] ) {
			$where[]  = 'source_lang = %s';
			$params[] = $filters['source_lang'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		$where_sql = implode( ' AND ', $where );

		$relations = wptsall_db_get_results(
			'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY created_at DESC',
			$params,
			ARRAY_A
		);
		$relations = is_array( $relations ) ? $relations : array();

		// Parse JSON fields
		foreach ( $relations as &$relation ) {
			$relation['plugin_status'] = json_decode( $relation['plugin_status'], true );
		}

		// v0.9.0: Store in cache
		if ( $use_cache ) {
			wp_cache_set( $cache_key, $relations, self::CACHE_GROUP, self::CACHE_EXPIRATION );
		}

		return $relations;
	}

	/**
	 * Get grouped relations (for list display)
	 *
	 * Grouped by source_site_id + source_lang + template
	 *
	 * @param array $filters Filter criteria
	 * @return array Grouped relation list
	 */
	public static function get_grouped_relations( $filters = array() ) {
		if ( ! is_array( $filters ) ) {
			$filters = array(
				'source_site_id' => (int) $filters,
			);
		}

		$relations = self::get_all_relations( $filters );

		$grouped = array();
		foreach ( $relations as $relation ) {
			$group_key = sprintf(
				'%d_%s_%s',
				$relation['source_site_id'],
				$relation['source_lang'],
				$relation['template']
			);

			if ( ! isset( $grouped[ $group_key ] ) ) {
				$grouped[ $group_key ] = array(
					'source_site_id'    => $relation['source_site_id'],
					'source_site_type'  => $relation['source_site_type'],
					'source_lang'       => $relation['source_lang'],
					'source_theme_name' => $relation['source_theme_name'],
					'source_theme_path' => $relation['source_theme_path'],
					'template'          => $relation['template'],
					'targets'           => array(),
				);
			}

				$grouped[ $group_key ]['targets'][] = array(
					'id'               => $relation['id'],
					'target_site_id'   => $relation['target_site_id'],
					'target_site_type' => $relation['target_site_type'],
					'target_lang'      => $relation['target_lang'],
					'theme_name'       => $relation['target_theme_name'],
					'theme_path'       => $relation['target_theme_path'],
					'status'           => $relation['status'],
					'created_at'       => $relation['created_at'],
					'updated_at'       => $relation['updated_at'],
					// Backward-compatible aliases kept for existing API consumers/tests.
					'site_id'          => $relation['target_site_id'],
					'site_type'        => $relation['target_site_type'],
					'lang'             => $relation['target_lang'],
				);
			}

		return array_values( $grouped );
	}

	/**
	 * Get a single relation
	 *
	 * @since 0.3.0
	 * @updated 0.6.0 Returned data includes models_count field
	 *
	 * @param int  $relation_id    Relation ID
	 * @param bool $include_models Whether to include associated model details (default false)
	 * @return array|null Relation data
	 */
	public static function get_relation( $relation_id, $include_models = false ) {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $relation_id ),
			ARRAY_A
		);

		if ( ! $relation ) {
			return null;
		}

		// Parse JSON fields
		$relation['plugin_status'] = json_decode( $relation['plugin_status'], true );

		// v0.6.0: Optionally include associated model details
		if ( $include_models ) {
			$relation['models'] = Relation_Model_Service::get_models_by_relation( $relation_id );
		}

		return $relation;
	}

	/**
	 * Get all target sites for a source site
	 *
	 * Used for fast lookup by the Hooks module
	 *
	 * @param int    $source_site_id Source site ID
	 * @param string $source_lang    Source site language
	 * @param string $template       Model identifier
	 * @return array List of target sites
	 */
	public static function get_targets_for_source( $source_site_id, $source_lang, $template ) {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relations = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT target_site_id, target_site_type, target_lang FROM %i
				 WHERE source_site_id = %d AND source_lang = %s AND template = %s AND status = %s',
				$table,
				$source_site_id,
				$source_lang,
				$template,
				'active'
			),
			ARRAY_A
		);

		return $relations;
	}

	/**
	 * Count relations for a given model
	 *
	 * @param string $template Model identifier
	 * @return int Relation count
	 */
	public static function count_by_template( $template ) {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE template = %s',
				$table,
				$template
			)
		);
	}

	/**
	 * Sync theme info
	 *
	 * @param int $relation_id Relation ID
	 * @return array Result
	 */
	public static function sync_theme_info( $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// Fetch relation
		$relation = self::get_relation( $relation_id );
		if ( ! $relation ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Relation does not exist', 'wpmmcc-ats' ) ),
			);
		}

		// Get source site theme info
		$source_theme = self::get_site_theme_info( $relation['source_site_id'] );

		// Get target site theme info (if WP site)
		$target_theme = array( 'name' => '', 'path' => '' );
		if ( 'wp' === $relation['target_site_type'] && is_numeric( $relation['target_site_id'] ) ) {
			$target_theme = self::get_site_theme_info( (int) $relation['target_site_id'] );
		}

		// Update database
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'source_theme_name' => $source_theme['name'],
				'source_theme_path' => $source_theme['path'],
				'target_theme_name' => $target_theme['name'],
				'target_theme_path' => $target_theme['path'],
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $relation_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		wptsall_log_info(
			'sites-relations',
			'Theme info synced',
			array(
				'relation_id'  => $relation_id,
				'source_theme' => $source_theme,
				'target_theme' => $target_theme,
			)
		);

		return array(
			'success'      => true,
			'source_theme' => $source_theme,
			'target_theme' => $target_theme,
		);
	}

	/**
	 * Get site theme information
	 *
	 * @param int $site_id Site ID
	 * @return array Theme info ['name' => string, 'path' => string]
	 */
	public static function get_site_theme_info( $site_id ) {
		$current_blog_id = get_current_blog_id();
		$need_switch     = is_multisite() && (int) $site_id !== $current_blog_id;

		if ( $need_switch ) {
			switch_to_blog( $site_id );
		}

		$theme = wp_get_theme();
		$info  = array(
			'name' => $theme->get( 'Name' ),
			'path' => $theme->get_stylesheet(),
		);

		if ( $need_switch ) {
			restore_current_blog();
		}

		return $info;
	}

	/**
	 * Detect plugin status for a single target
	 *
	 * @param string $template       Model identifier
	 * @param int    $source_site_id Source site ID
	 * @param mixed  $target_id      Target site ID
	 * @param string $target_type    Target site type
	 * @return array Status data
	 */
	private static function detect_single_plugin_status( $template, $source_site_id, $target_id, $target_type ) {
		$source_active = Site_Relation_Validator::plugin_is_active_on_site( $template, $source_site_id );

		$target_active = true;
		if ( 'wp' === $target_type && is_numeric( $target_id ) ) {
			$target_active = Site_Relation_Validator::plugin_is_active_on_site( $template, (int) $target_id );
		}

		return array(
			'template' => $template,
			'source'   => array(
				'site_id' => $source_site_id,
				'active'  => $source_active,
			),
			'target'   => array(
				'site_id' => $target_id,
				'type'    => $target_type,
				'active'  => $target_active,
			),
		);
	}

	/**
	 * Get relation statistics
	 *
	 * @return array Statistics
	 */
	public static function get_stats() {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$table,
				'active'
			)
		);

		// Count by model
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_template = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT template, COUNT(*) as count FROM %i GROUP BY template',
				$table
			),
			ARRAY_A
		);

		// Count relation groups by source site + language + model
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$group_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT CONCAT(source_site_id, "_", source_lang, "_", template)) FROM %i',
				$table
			)
		);

		return array(
			'total'       => $total,
			'active'      => $active,
			'groups'      => $group_count,
			'by_template' => $by_template,
		);
	}

	/**
	 * Ensure model exists; auto-create if missing
	 *
	 * @param string $template       Model identifier (plugin slug)
	 * @param int    $source_site_id Source site ID (used when switching sites for scan)
	 * @return array Result ['success' => bool, 'model_id' => int|null, 'errors' => array(), 'created' => bool]
	 */
	public static function ensure_model_exists( $template, $source_site_id = 0 ) {
		// Check if model already exists (via Translation_Rule_Service)
		$existing_model = Translation_Rule_Service::get_model( $template );
		if ( $existing_model ) {
			return array(
				'success'  => true,
				'model_id' => $existing_model['id'],
				'errors'   => array(),
				'created'  => false,
			);
		}

		// Model does not exist; try scan and create
		$current_blog_id = get_current_blog_id();
		$need_switch     = is_multisite() && $source_site_id > 0 && (int) $source_site_id !== $current_blog_id;

		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		try {
			$scanner = new Model_Scanner_V2();
			// Creating a site relation triggers a model scan: use 'full' mode so
			// translation rules are auto-created for the newly discovered model.
			$scanner->set_mode( 'full' );
			$scan_result = $scanner->scan_plugin( $template );

			if ( $need_switch ) {
				restore_current_blog();
			}

			if ( is_wp_error( $scan_result ) ) {
				return array(
					'success'  => false,
					'model_id' => null,
					'errors'   => array( $scan_result->get_error_message() ),
					'created'  => false,
				);
			}

			// Create model (using Translation_Rule_Service V2 field format)
			$model_data = $scan_result['model'];
			$model_id   = Translation_Rule_Service::create_model( array(
				'plugin_slug'    => $model_data['plugin_slug'],
				'plugin_name'    => $model_data['plugin_name'],
				'plugin_version' => $model_data['plugin_version'] ?? '1.0.0',
				'text_domain'    => $model_data['text_domain'] ?? $template,
				'description'    => $model_data['description'] ?? '',
				'post_types'     => $model_data['post_types'] ?? array(),
				'taxonomies'     => $model_data['taxonomies'] ?? array(),
				'status'         => 'active',
				'usage_status'   => 'unused',
				'is_system'      => false,
				'scan_version'   => $model_data['scan_version'] ?? Model_Scanner_V2::VERSION,
				'last_scanned'   => current_time( 'mysql' ),
			) );

			if ( is_wp_error( $model_id ) ) {
				return array(
					'success'  => false,
					'model_id' => null,
					'errors'   => array( $model_id->get_error_message() ),
					'created'  => false,
				);
			}

			// Create translation rules (via Translation_Rule_Service)
			if ( ! empty( $scan_result['rules'] ) ) {
				foreach ( $scan_result['rules'] as $rule ) {
					Translation_Rule_Service::create_rule( $model_id, $rule );
				}
			}

			wptsall_log_info(
				'sites-relations',
				'Model auto-created for site relation',
				array(
					'template'       => $template,
					'model_id'       => $model_id,
					'source_site_id' => $source_site_id,
					'rules_count'    => count( $scan_result['rules'] ?? array() ),
				)
			);

			// Trigger model creation hook
			do_action( 'wptsall_model_auto_created', $model_id, $template, $source_site_id );

			return array(
				'success'  => true,
				'model_id' => $model_id,
				'errors'   => array(),
				'created'  => true,
			);
		} catch ( \Exception $e ) {
			if ( $need_switch ) {
				restore_current_blog();
			}

			return array(
				'success'  => false,
				'model_id' => null,
				'errors'   => array( $e->getMessage() ),
				'created'  => false,
			);
		}
	}

	/**
	 * Update relation information
	 *
	 * @param int   $relation_id Relation ID
	 * @param array $data        Update data
	 * @return array Result
	 */
	public static function update_relation( $relation_id, $data ) {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		$relation = self::get_relation( $relation_id );
		if ( ! $relation ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Relation does not exist', 'wpmmcc-ats' ) ),
			);
		}

		$update_data = array(
			'updated_at' => current_time( 'mysql' ),
		);
		$formats     = array( '%s' );

		// Allowed updatable fields
		$allowed_fields = array(
			'target_lang'       => '%s',
			'status'            => '%s',
			'media_handling'    => '%s',
			'sync_mode'         => '%s',
			'direction'         => '%s',
			'conflict_strategy' => '%s',
		);

		// Validate media_handling value.
		if ( isset( $data['media_handling'] ) && ! in_array( $data['media_handling'], array( 'copy', 'reference' ), true ) ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Invalid media handling mode', 'wpmmcc-ats' ) ),
			);
		}

		// Validate sync_mode value.
		// NOTE: sync_mode is used by monitoring as the "monitoring strategy" (new_only/new_and_update/full),
		// while some legacy code may still send sync_only/translate_only/manual/incremental.
		if ( isset( $data['sync_mode'] ) && ! in_array( $data['sync_mode'], array( 'full', 'new_only', 'new_and_update', 'incremental', 'manual', 'sync_only', 'translate_only' ), true ) ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Invalid sync mode', 'wpmmcc-ats' ) ),
			);
		}

		// Product decision (2026-01-29): sync_mode is fixed to "new_only".
		// We still accept legacy values for backward compatibility, but normalize on write.
		if ( isset( $data['sync_mode'] ) ) {
			$raw_sync_mode = sanitize_key( (string) $data['sync_mode'] );
			if ( 'new_only' !== $raw_sync_mode ) {
				wptsall_log_warning(
					'sites-relations',
					'Deprecated sync_mode provided, forced to new_only',
					array(
						'relation_id' => $relation_id,
						'provided'    => $raw_sync_mode,
					)
				);
			}
			$data['sync_mode'] = 'new_only';
		}

		// Validate direction value (unified + legacy).
		if ( isset( $data['direction'] ) && ! in_array( $data['direction'], array( 'one_way', 'source_to_target', 'forward', 'bidirectional', 'both', 'two_way' ), true ) ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Invalid sync direction', 'wpmmcc-ats' ) ),
			);
		}

		// Validate conflict_strategy value.
		if ( isset( $data['conflict_strategy'] ) && ! in_array( $data['conflict_strategy'], array( 'source_wins', 'target_wins', 'newest_wins', 'manual', 'merge' ), true ) ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Invalid conflict strategy', 'wpmmcc-ats' ) ),
			);
		}

		foreach ( $allowed_fields as $field => $format ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			$value = $data[ $field ];

			switch ( $field ) {
				case 'status':
				case 'media_handling':
				case 'sync_mode':
				case 'direction':
				case 'conflict_strategy':
					$value = sanitize_key( $value );
					break;
				case 'target_lang':
				default:
					$value = sanitize_text_field( $value );
					break;
			}

			$update_data[ $field ] = $value;
			$formats[]             = $format;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $relation_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return array(
				'success' => false,
				'errors'  => array( $wpdb->last_error ),
			);
		}

		wptsall_log_info(
			'sites-relations',
			'Site relation updated',
			array(
				'relation_id' => $relation_id,
				'updated'     => array_keys( $update_data ),
			)
		);

		// v0.9.0: Clear cache
		self::clear_cache();

		do_action( 'wptsall_site_relation_updated', $relation_id, $data );

		return array( 'success' => true );
	}

	/**
	 * Get an active site relation by target site ID and type.
	 *
	 * Supports multiple ID formats for virtual sites (v_{id}, v_{lang_code},
	 * v_{path_prefix}, raw path_prefix, raw string id) so callers do not need
	 * to replicate format-guessing logic.
	 *
	 * Results are cached via wp_cache for 300 seconds.
	 *
	 * @since 1.2.0
	 *
	 * @param string|int $target_site_id   Target site identifier (may be any format).
	 * @param string     $target_site_type Target site type ('virtual' or 'wp').
	 * @param array      $extra_ids        Optional extra ID variants to try.
	 * @return array|null Relation row (ARRAY_A) or null if not found.
	 */
	public static function get_relation_by_target( $target_site_id, $target_site_type = 'virtual', $extra_ids = array() ) {
		$cache_key = "relation_target_{$target_site_type}_{$target_site_id}";
		$cached    = wp_cache_get( $cache_key, 'wpmmcc-ats' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		// Build list of IDs to try.
		$ids_to_try = array_merge( array( (string) $target_site_id ), $extra_ids );
		$ids_to_try = array_unique( array_filter( $ids_to_try ) );

		if ( empty( $ids_to_try ) ) {
			wp_cache_set( $cache_key, 0, 'wpmmcc-ats', 300 );
			return null;
		}

		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		$placeholders = implode( ', ', array_fill( 0, count( $ids_to_try ), '%s' ) );
		$query_args   = array_merge( array( $table ), array_values( $ids_to_try ), array( $target_site_type ) );

		// $placeholders is an implode of literal '%s' tokens only (not user data).
		$relation = wptsall_db_get_row(
			"SELECT * FROM %i WHERE target_site_id IN ($placeholders) AND target_site_type = %s AND status = 'active' ORDER BY id ASC LIMIT 1",
			$query_args,
			ARRAY_A
		);

		if ( $relation ) {
			$relation['plugin_status'] = json_decode( $relation['plugin_status'], true );
		}

		// Cache the result (store 0 for "not found" to avoid repeated DB queries).
		wp_cache_set( $cache_key, $relation ?: 0, 'wpmmcc-ats', 300 );

		return $relation;
	}
}
