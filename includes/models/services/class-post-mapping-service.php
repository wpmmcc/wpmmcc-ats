<?php
/**
 * Post Mapping Service
 *
 * Handles post relationship ID mappings between source and target sites.
 * Supports translation relationships, parent-child relationships, and cross-references.
 *
 * @package WPTSALL\Models\Services
 * @since 0.5.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post Mapping Service Class
 */
class Post_Mapping_Service {

	/**
	 * Get post mapping from source to target
	 *
	 * @param int    $source_post_id   Source post ID.
	 * @param string $source_post_type Source post type.
	 * @param int    $source_site_id   Source site ID.
	 * @param string $target_site_id   Target site ID (can be virtual).
	 * @param int    $relation_id      Optional relation ID. When provided, the
	 *                                 lookup is strictly relation-scoped.
	 * @return array|null Mapping data or null if not found.
	 */
	public static function get_mapping( $source_post_id, $source_post_type, $source_site_id, $target_site_id, $relation_id = 0 ) {
		global $wpdb;
		$table       = wptsall_table( 'post_mappings' );
		$relation_id = absint( $relation_id );

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE relation_id = %d
					AND source_post_id = %d
					AND source_post_type = %s
					AND source_site_id = %d
					AND target_site_id = %s",
					$table,
					$relation_id,
					$source_post_id,
					$source_post_type,
					$source_site_id,
					$target_site_id
				),
				ARRAY_A
			);
		} else {
			// Preserve the historical API for legacy callers. Relation-aware
			// write-back and ID resolution always pass relation_id.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE source_post_id = %d
					AND source_post_type = %s
					AND source_site_id = %d
					AND target_site_id = %s",
					$table,
					$source_post_id,
					$source_post_type,
					$source_site_id,
					$target_site_id
				),
				ARRAY_A
			);
		}

		wptsall_log_debug(
			'models',
			'Post_Mapping_Service get_mapping',
			array(
				'source_post_id' => $source_post_id,
				'post_type'      => $source_post_type,
				'target_site_id' => $target_site_id,
				'found'          => ! empty( $mapping ),
			)
		);

		return $mapping ? $mapping : null;
	}

	/**
	 * Get reverse mapping (target to source)
	 *
	 * @param int    $target_post_id   Target post ID.
	 * @param string $target_post_type Target post type.
	 * @param string $target_site_id   Target site ID.
	 * @param int    $source_site_id   Source site ID.
	 * @param int    $relation_id      Optional relation ID. When provided, the
	 *                                 lookup is strictly relation-scoped.
	 * @return array|null Mapping data or null if not found.
	 */
	public static function get_reverse_mapping( $target_post_id, $target_post_type, $target_site_id, $source_site_id, $relation_id = 0 ) {
		global $wpdb;
		$table       = wptsall_table( 'post_mappings' );
		$relation_id = absint( $relation_id );

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE relation_id = %d
					AND target_post_id = %d
					AND target_post_type = %s
					AND target_site_id = %s
					AND source_site_id = %d",
					$table,
					$relation_id,
					$target_post_id,
					$target_post_type,
					$target_site_id,
					$source_site_id
				),
				ARRAY_A
			);
		} else {
			// Preserve the historical API for legacy callers.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE target_post_id = %d
					AND target_post_type = %s
					AND target_site_id = %s
					AND source_site_id = %d",
					$table,
					$target_post_id,
					$target_post_type,
					$target_site_id,
					$source_site_id
				),
				ARRAY_A
			);
		}

		return $mapping ? $mapping : null;
	}

	/**
	 * Create or update post mapping
	 *
	 * @param array $mapping_data {
	 *     Mapping data.
	 *
	 *     @type int    $source_post_id     Source post ID.
	 *     @type string $source_post_type   Source post type.
	 *     @type int    $source_site_id     Source site ID.
	 *     @type int    $target_post_id     Target post ID.
	 *     @type string $target_post_type   Target post type.
	 *     @type string $target_site_id     Target site ID.
	 *     @type string $relationship_type  Relationship type (translation/reference/parent_child).
	 * }
	 * @return int|false Mapping ID or false on failure.
	 */
	public static function create_mapping( $mapping_data ) {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );

		// Check if mapping already exists
		$relation_id = isset( $mapping_data['relation_id'] )
			? absint( $mapping_data['relation_id'] )
			: 0;
		$existing = self::get_mapping(
			$mapping_data['source_post_id'],
			$mapping_data['source_post_type'],
			$mapping_data['source_site_id'],
			$mapping_data['target_site_id'],
			$relation_id
		);

		$now = current_time( 'mysql', true );

		if ( 0 === $relation_id && $existing && isset( $existing['relation_id'] ) ) {
			$relation_id = (int) $existing['relation_id'];
		}

		$data = array(
			'source_post_id'    => $mapping_data['source_post_id'],
			'source_post_type'  => $mapping_data['source_post_type'],
			'source_site_id'    => $mapping_data['source_site_id'],
			'target_post_id'    => $mapping_data['target_post_id'],
			'target_post_type'  => $mapping_data['target_post_type'],
			'target_site_id'    => $mapping_data['target_site_id'],
			'relationship_type' => $mapping_data['relationship_type'] ?? 'translation',
			'relation_id'       => $relation_id,
			'updated_at'        => $now,
		);

		$mapping_id = false;
		if ( $existing ) {
			// Update existing mapping
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				$data,
				array( 'id' => $existing['id'] ),
				array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			$mapping_id = $result !== false ? (int) $existing['id'] : false;

			wptsall_log_debug(
				'models',
				'Post_Mapping_Service updated mapping',
				array(
					'mapping_id'     => $mapping_id,
					'source_post_id' => $mapping_data['source_post_id'],
					'target_post_id' => $mapping_data['target_post_id'],
				)
			);
		} else {
			// Create new mapping
			$data['created_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$table,
				$data,
				array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			$mapping_id = $result ? (int) $wpdb->insert_id : false;

			wptsall_log_info(
				'models',
				'Post_Mapping_Service created mapping',
				array(
					'mapping_id'     => $mapping_id,
					'source_post_id' => $mapping_data['source_post_id'],
					'target_post_id' => $mapping_data['target_post_id'],
					'relationship'   => $mapping_data['relationship_type'] ?? 'translation',
				)
			);
		}

		// Audit log hook (P6-3).
		do_action( 'wptsall_post_mapping_created', $mapping_id, $mapping_data, (bool) $mapping_id );

		return $mapping_id;
	}

	/**
	 * Map post ID
	 *
	 * @param int    $source_post_id   Source post ID.
	 * @param string $post_type        Post type.
	 * @param int    $source_site_id   Source site ID.
	 * @param string $target_site_id   Target site ID.
	 * @param string $relationship     Relationship type.
	 * @param int    $relation_id      Optional site relation ID.
	 * @return int|false Target post ID or false if not found.
	 */
	public static function map_post_id( $source_post_id, $post_type, $source_site_id, $target_site_id, $relationship = 'translation', $relation_id = 0 ) {
		$mapping = self::get_mapping( $source_post_id, $post_type, $source_site_id, $target_site_id, $relation_id );

		if ( $mapping && $mapping['relationship_type'] === $relationship ) {
			return (int) $mapping['target_post_id'];
		}

		return false;
	}

	/**
	 * Get all mappings for a source post
	 *
	 * @param int    $source_post_id   Source post ID.
	 * @param string $source_post_type Source post type.
	 * @param int    $source_site_id   Source site ID.
	 * @return array Array of mappings.
	 */
	public static function get_all_mappings_for_source( $source_post_id, $source_post_type, $source_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE source_post_id = %d
				AND source_post_type = %s
				AND source_site_id = %d
				ORDER BY target_site_id ASC",
				$table,
				$source_post_id,
				$source_post_type,
				$source_site_id
			),
			ARRAY_A
		);

		return $mappings ? $mappings : array();
	}

	/**
	 * Get mappings by relationship type
	 *
	 * @param int    $source_post_id   Source post ID.
	 * @param string $source_post_type Source post type.
	 * @param int    $source_site_id   Source site ID.
	 * @param string $relationship     Relationship type.
	 * @return array Array of mappings.
	 */
	public static function get_mappings_by_type( $source_post_id, $source_post_type, $source_site_id, $relationship ) {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE source_post_id = %d
				AND source_post_type = %s
				AND source_site_id = %d
				AND relationship_type = %s",
				$table,
				$source_post_id,
				$source_post_type,
				$source_site_id,
				$relationship
			),
			ARRAY_A
		);

		return $mappings ? $mappings : array();
	}

	/**
	 * Batch map posts
	 *
	 * @param array  $post_ids        Array of post IDs.
	 * @param string $post_type       Post type.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID.
	 * @param string $relationship    Relationship type.
	 * @return array Array of source_id => target_id mappings.
	 */
	public static function batch_map_posts( $post_ids, $post_type, $source_site_id, $target_site_id, $relationship = 'translation' ) {
		$mapped = array();

		foreach ( $post_ids as $post_id ) {
			$target_id = self::map_post_id(
				$post_id,
				$post_type,
				$source_site_id,
				$target_site_id,
				$relationship
			);

			if ( $target_id ) {
				$mapped[ $post_id ] = $target_id;
			}
		}

		return $mapped;
	}

	/**
	 * Map array of post IDs
	 *
	 * Useful for meta fields containing comma-separated post IDs or arrays.
	 *
	 * @param string|array $post_ids_data  Post IDs (comma-separated string or array).
	 * @param string       $post_type      Post type.
	 * @param int          $source_site_id Source site ID.
	 * @param string       $target_site_id Target site ID.
	 * @param string       $relationship   Relationship type.
	 * @param bool         $preserve_format Whether to return same format as input.
	 * @return string|array Mapped post IDs in same format as input.
	 */
	public static function map_post_id_array( $post_ids_data, $post_type, $source_site_id, $target_site_id, $relationship = 'translation', $preserve_format = true ) {
		// Parse input
		$is_string = is_string( $post_ids_data );
		if ( $is_string ) {
			$post_ids = array_map( 'intval', explode( ',', $post_ids_data ) );
		} else {
			$post_ids = array_map( 'intval', (array) $post_ids_data );
		}

		// Filter out empty values
		$post_ids = array_filter( $post_ids );

		if ( empty( $post_ids ) ) {
			return $is_string ? '' : array();
		}

		// Map each ID
		$mapped_ids = array();
		foreach ( $post_ids as $post_id ) {
			$target_id = self::map_post_id(
				$post_id,
				$post_type,
				$source_site_id,
				$target_site_id,
				$relationship
			);

			if ( $target_id ) {
				$mapped_ids[] = $target_id;
			}
		}

		// Return in same format as input
		if ( $preserve_format && $is_string ) {
			return implode( ',', $mapped_ids );
		}

		return $mapped_ids;
	}

	/**
	 * Map parent post ID
	 *
	 * Special handling for post_parent field.
	 *
	 * @param int    $parent_id       Parent post ID.
	 * @param string $post_type       Post type.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID.
	 * @return int Mapped parent ID or 0 if not found.
	 */
	public static function map_parent_id( $parent_id, $post_type, $source_site_id, $target_site_id ) {
		if ( empty( $parent_id ) || 0 === (int) $parent_id ) {
			return 0;
		}

		$target_id = self::map_post_id(
			$parent_id,
			$post_type,
			$source_site_id,
			$target_site_id,
			'parent_child'
		);

		return $target_id ? $target_id : 0;
	}

	/**
	 * Delete mapping
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_mapping( $mapping_id ) {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $mapping_id ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Delete all mappings for a source post
	 *
	 * @param int    $source_post_id   Source post ID.
	 * @param string $source_post_type Source post type.
	 * @param int    $source_site_id   Source site ID.
	 * @return int Number of mappings deleted.
	 */
	public static function delete_mappings_for_source( $source_post_id, $source_post_type, $source_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array(
				'source_post_id'   => $source_post_id,
				'source_post_type' => $source_post_type,
				'source_site_id'   => $source_site_id,
			),
			array( '%d', '%s', '%d' )
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Clean up orphaned mappings
	 *
	 * Deletes mappings where source or target post no longer exists.
	 *
	 * @return int Number of mappings deleted.
	 */
	public static function cleanup_orphaned_mappings() {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );

		// Get all mappings
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i', $table ),
			ARRAY_A
		);

		$deleted = 0;

		foreach ( $mappings as $mapping ) {
			$source_exists = false;
			$target_exists = false;

			// Check source post exists
			if ( is_multisite() ) {
				switch_to_blog( $mapping['source_site_id'] );
			}
			$source_post = get_post( $mapping['source_post_id'] );
			$source_exists = $source_post && $source_post->post_type === $mapping['source_post_type'];
			if ( is_multisite() ) {
				restore_current_blog();
			}

			// Check target post exists (only for numeric site IDs)
			if ( is_numeric( $mapping['target_site_id'] ) ) {
				if ( is_multisite() ) {
					switch_to_blog( (int) $mapping['target_site_id'] );
				}
				$target_post = get_post( $mapping['target_post_id'] );
				$target_exists = $target_post && $target_post->post_type === $mapping['target_post_type'];
				if ( is_multisite() ) {
					restore_current_blog();
				}
			} else {
				// Virtual site - assume exists for now
				$target_exists = true;
			}

			// Delete if either doesn't exist
			if ( ! $source_exists || ! $target_exists ) {
				self::delete_mapping( $mapping['id'] );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Get mapped ID by relation (Chain 8 contract method)
	 *
	 * Simplified API method per MODULE-CHAINS.md Chain 8 spec.
	 * Get target ID by site relation ID and source ID.
	 *
	 * @since 0.8.1
	 *
	 * @param int $relation_id Site relation ID.
	 * @param int $source_id   Source post ID.
	 * @return int|null Target post ID or null.
	 */
	public static function get_mapped_id( int $relation_id, int $source_id ): ?int {
		// 1. Get site relation info
		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return null;
		}

		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );

		if ( empty( $target_site_id ) ) {
			return null;
		}

		// 2. Get source post info (switch to source blog to ensure correct context)
		$need_switch = is_multisite() && $source_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		$source_post = get_post( $source_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( ! $source_post ) {
			return null;
		}

		// 3. Query mapping
		$mapping = self::get_mapping(
			$source_id,
			$source_post->post_type,
			$source_site_id,
			$target_site_id,
			$relation_id
		);

		return $mapping ? (int) $mapping['target_post_id'] : null;
	}

	/**
	 * Get relationship statistics
	 *
	 * @return array Statistics about post mappings.
	 */
	public static function get_statistics() {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );

		// Total mappings
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);

		// By relationship type
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_type = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT relationship_type, COUNT(*) as count FROM %i GROUP BY relationship_type',
				$table
			),
			ARRAY_A
		);

		// By post type
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_post_type = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_post_type, COUNT(*) as count FROM %i GROUP BY source_post_type ORDER BY count DESC LIMIT 10',
				$table
			),
			ARRAY_A
		);

		return array(
			'total'         => $total,
			'by_type'       => $by_type ? $by_type : array(),
			'by_post_type'  => $by_post_type ? $by_post_type : array(),
		);
	}
}
