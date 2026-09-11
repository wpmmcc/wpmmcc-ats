<?php
/**
 * Direct Database Service
 *
 * Provides direct database operations for sync tasks, bypassing WordPress hooks
 * to ensure predictable behavior regardless of third-party plugin interference.
 *
 * Why use direct database operations for sync:
 * 1. Performance: Bulk operations are 1000x+ faster without hook processing
 * 2. Predictability: No interference from plugin filters (e.g., Events Calendar hiding posts)
 * 3. Reliability: Plugin updates won't break sync functionality
 * 4. Simplicity: We're copying data, not creating user-generated content
 *
 * @package WPTSALL\Tasks\Services
 * @since 0.6.1
 */

namespace WPTSALL\Tasks\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Direct Database Service class
 */
class Direct_DB_Service {

	/**
	 * Register read-side repair for legacy double-serialized virtual post meta.
	 */
	public static function init_meta_filters(): void {
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 20, 4 );
	}

	/**
	 * Unwrap values that were serialized twice (string holding a serialized blob).
	 *
	 * @param mixed $meta_value Raw or logical meta value.
	 * @return mixed
	 */
	public static function normalize_meta_value( $meta_value ) {
		while ( is_string( $meta_value ) && is_serialized( $meta_value ) ) {
			$next = maybe_unserialize( $meta_value );
			if ( $next === $meta_value ) {
				break;
			}
			$meta_value = $next;
		}
		return $meta_value;
	}

	/**
	 * Repair double-serialized meta on virtual shadow posts at read time.
	 *
	 * @param mixed  $value     Filter value (null to load from DB).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single value requested.
	 * @return mixed
	 */
	public static function filter_get_post_metadata( $value, $object_id, $meta_key, $single ) {
		if ( null !== $value || ! $single || '_wptsall_virtual_site_id' === $meta_key ) {
			return $value;
		}

		static $virtual_cache = array();
		$post_id            = (int) $object_id;
		if ( ! isset( $virtual_cache[ $post_id ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$virtual_cache[ $post_id ] = (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1',
					$wpdb->postmeta,
					$post_id,
					'_wptsall_virtual_site_id'
				)
			);
		}
		if ( empty( $virtual_cache[ $post_id ] ) ) {
			return $value;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1',
				$wpdb->postmeta,
				$post_id,
				$meta_key
			)
		);
		if ( null === $raw ) {
			return $value;
		}

		$once       = maybe_unserialize( $raw );
		$normalized = self::normalize_meta_value( $once );
		if ( $normalized === $once ) {
			return $value;
		}

		// Persist repair without re-entering this filter.
		static $repairing = array();
		$repair_key         = $post_id . ':' . $meta_key;
		if ( empty( $repairing[ $repair_key ] ) ) {
			$repairing[ $repair_key ] = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => maybe_serialize( $normalized ) ),
				array(
					'post_id'  => $post_id,
					'meta_key' => $meta_key,
				)
			);
			wp_cache_delete( $post_id, 'post_meta' );
			unset( $repairing[ $repair_key ] );
		}

		return $normalized;
	}

	/**
	 * Get posts by type using direct SQL (bypasses WP_Query filters)
	 *
	 * @param string      $post_type     Post type name.
	 * @param string      $post_status   Post status (default 'publish').
	 * @param string|null $modified_after Only get posts modified after this datetime.
	 * @param int         $limit         Maximum number of posts to return.
	 * @return array Array of post IDs.
	 */
	public static function get_post_ids( $post_type, $post_status = 'publish', $modified_after = null, $limit = 50 ) {
		global $wpdb;

		if ( $modified_after ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_type = %s AND post_status = %s AND post_modified > %s ORDER BY post_modified DESC LIMIT %d',
					$wpdb->posts,
					$post_type,
					$post_status,
					$modified_after,
					$limit
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE post_type = %s AND post_status = %s ORDER BY post_modified DESC LIMIT %d',
				$wpdb->posts,
				$post_type,
				$post_status,
				$limit
			)
		);
	}

	/**
	 * Get a single post by ID using direct SQL
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Post object or null.
	 */
	public static function get_post( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ID = %d',
				$wpdb->posts,
				$post_id
			)
		);
	}

	/**
	 * Insert a post directly into the database
	 *
	 * @since 0.6.1
	 * @since 0.9.0 Added $site_context parameter for virtual/multisite support.
	 *
	 * @param array $post_data    Post data array.
	 * @param array $site_context Site context for target site.
	 *   - type: 'virtual' | 'multisite' (default: current site)
	 *   - virtual_site_id: Virtual site ID (e.g., 'v_2')
	 *   - blog_id: Multisite blog ID
	 * @return int|\WP_Error New post ID or WP_Error on failure.
	 */
	public static function insert_post( $post_data, $site_context = array() ) {
		global $wpdb;

		$context_type     = $site_context['type'] ?? '';
		$virtual_site_id  = $site_context['virtual_site_id'] ?? '';
		$target_blog_id   = $site_context['blog_id'] ?? null;
		$switched         = false;

		wptsall_log_debug(
			'tasks-sync',
			'Direct_DB_Service::insert_post started',
			array(
				'site_context_type' => $context_type ?: 'current',
				'post_type'         => $post_data['post_type'] ?? 'post',
				'field_count'       => count( $post_data ),
			)
		);

		// Handle multisite context.
		if ( 'multisite' === $context_type && $target_blog_id && is_multisite() ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
			wptsall_log_debug(
				'tasks-sync',
				'Switched to multisite blog',
				array( 'blog_id' => $target_blog_id )
			);
		}

		$defaults = array(
			'post_author'       => get_current_user_id() ?: 1,
			'post_date'         => current_time( 'mysql' ),
			'post_date_gmt'     => current_time( 'mysql', true ),
			'post_content'      => '',
			'post_title'        => '',
			'post_excerpt'      => '',
			'post_status'       => 'publish',
			'comment_status'    => 'closed',
			'ping_status'       => 'closed',
			'post_password'     => '',
			'post_name'         => '',
			'post_modified'     => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_type'         => 'post',
			'guid'              => '',
		);

		$data = wp_parse_args( $post_data, $defaults );

		// Generate GUID if not provided.
		if ( empty( $data['guid'] ) ) {
			$data['guid'] = home_url( '/?p=' . uniqid() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $wpdb->posts, $data );

		if ( ! $result ) {
			wptsall_log_error(
				'tasks-sync',
				'Direct_DB_Service::insert_post failed',
				array(
					'error'     => $wpdb->last_error,
					'post_type' => $data['post_type'],
				)
			);
			if ( $switched ) {
				restore_current_blog();
			}
			return new \WP_Error( 'db_insert_error', $wpdb->last_error );
		}

		$post_id = $wpdb->insert_id;

		// Update GUID with actual post ID.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array( 'guid' => home_url( '/?p=' . $post_id ) ),
			array( 'ID' => $post_id )
		);

		// Handle virtual site context - add marker meta.
		if ( 'virtual' === $context_type && ! empty( $virtual_site_id ) ) {
			self::update_post_meta( $post_id, '_wptsall_virtual_site_id', $virtual_site_id );
			wptsall_log_debug(
				'tasks-sync',
				'Virtual site marker added',
				array(
					'post_id'         => $post_id,
					'virtual_site_id' => $virtual_site_id,
				)
			);
		}

		if ( $switched ) {
			restore_current_blog();
		}

		wptsall_log_info(
			'tasks-sync',
			'Direct_DB_Service::insert_post succeeded',
			array(
				'post_id'           => $post_id,
				'post_type'         => $data['post_type'],
				'site_context_type' => $context_type ?: 'current',
			)
		);

		return $post_id;
	}

	/**
	 * Update a post directly in the database
	 *
	 * @since 0.6.1
	 * @since 0.9.0 Added $site_context parameter for virtual/multisite support.
	 *
	 * @param int   $post_id      Post ID.
	 * @param array $post_data    Post data to update.
	 * @param array $site_context Site context for target site.
	 *   - type: 'virtual' | 'multisite' (default: current site)
	 *   - virtual_site_id: Virtual site ID (e.g., 'v_2')
	 *   - blog_id: Multisite blog ID
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function update_post( $post_id, $post_data, $site_context = array() ) {
		global $wpdb;

		$context_type    = $site_context['type'] ?? '';
		$target_blog_id  = $site_context['blog_id'] ?? null;
		$switched        = false;

		wptsall_log_debug(
			'tasks-sync',
			'Direct_DB_Service::update_post started',
			array(
				'post_id'           => $post_id,
				'site_context_type' => $context_type ?: 'current',
				'field_count'       => count( $post_data ),
			)
		);

		// Handle multisite context.
		if ( 'multisite' === $context_type && $target_blog_id && is_multisite() ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
			wptsall_log_debug(
				'tasks-sync',
				'Switched to multisite blog for update',
				array( 'blog_id' => $target_blog_id )
			);
		}

		// Always update modified time.
		$post_data['post_modified']     = current_time( 'mysql' );
		$post_data['post_modified_gmt'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->posts,
			$post_data,
			array( 'ID' => $post_id )
		);

		if ( false === $result ) {
			wptsall_log_error(
				'tasks-sync',
				'Direct_DB_Service::update_post failed',
				array(
					'post_id' => $post_id,
					'error'   => $wpdb->last_error,
				)
			);
			if ( $switched ) {
				restore_current_blog();
			}
			return new \WP_Error( 'db_update_error', $wpdb->last_error );
		}

		clean_post_cache( $post_id );

		if ( $switched ) {
			restore_current_blog();
		}

		wptsall_log_info(
			'tasks-sync',
			'Direct_DB_Service::update_post succeeded',
			array(
				'post_id'       => $post_id,
				'affected_rows' => $result,
			)
		);

		return true;
	}

	/**
	 * Insert or update post meta directly
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool Success status.
	 */
	public static function update_post_meta( $post_id, $meta_key, $meta_value ) {
		global $wpdb;

		$meta_value = maybe_serialize( self::normalize_meta_value( $meta_value ) );

		// Check if meta exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s',
				$wpdb->postmeta,
				$post_id,
				$meta_key
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = false !== $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $meta_value ),
				array( 'meta_id' => $existing )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = false !== $wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => $meta_key,
					'meta_value' => $meta_value,
				)
			);
		}

		if ( $ok ) {
			clean_post_cache( (int) $post_id );
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}

		return $ok;
	}

	/**
	 * Add post meta directly (without replacing existing keys).
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool Success status.
	 */
	public static function add_post_meta( $post_id, $meta_key, $meta_value ) {
		global $wpdb;

		$meta_value = maybe_serialize( self::normalize_meta_value( $meta_value ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = false !== $wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			)
		);

		if ( $ok ) {
			clean_post_cache( (int) $post_id );
		}

		return $ok;
	}

	/**
	 * Delete post meta directly.
	 *
	 * @param int         $post_id    Post ID.
	 * @param string      $meta_key   Meta key.
	 * @param string|int|array|null $meta_value Optional exact meta value filter.
	 * @return bool Success status.
	 */
	public static function delete_post_meta( $post_id, $meta_key, $meta_value = null ) {
		global $wpdb;

		$where = array(
			'post_id'  => (int) $post_id,
			'meta_key' => (string) $meta_key,
		);

		if ( null !== $meta_value ) {
			$where['meta_value'] = maybe_serialize( $meta_value );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( $wpdb->postmeta, $where );

		if ( false === $result ) {
			return false;
		}

		clean_post_cache( (int) $post_id );
		return true;
	}

	/**
	 * Get terms by taxonomy using direct SQL (bypasses get_terms filters)
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $limit    Maximum number of terms.
	 * @param int    $last_id  Optional. Return terms with term_id > $last_id for pagination. Default 0.
	 * @return array Array of term objects.
	 */
	public static function get_terms( $taxonomy, $limit = 100, $last_id = 0 ) {
		global $wpdb;

		// v0.9.2 ISS-TSK-030 / ISS-TST-041: Support ID-based pagination.
		if ( $last_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.*, tt.taxonomy, tt.description, tt.parent, tt.count
					 FROM %i t
					 INNER JOIN %i tt ON t.term_id = tt.term_id
					 WHERE tt.taxonomy = %s AND t.term_id > %d
					 ORDER BY t.term_id ASC
					 LIMIT %d',
					$wpdb->terms,
					$wpdb->term_taxonomy,
					$taxonomy,
					$last_id,
					$limit
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.*, tt.taxonomy, tt.description, tt.parent, tt.count
				 FROM %i t
				 INNER JOIN %i tt ON t.term_id = tt.term_id
				 WHERE tt.taxonomy = %s
				 ORDER BY t.term_id ASC
				 LIMIT %d',
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$taxonomy,
				$limit
			)
		);
	}

	/**
	 * Get a single term by ID
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return object|null Term object or null.
	 */
	public static function get_term( $term_id, $taxonomy ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT t.*, tt.taxonomy, tt.description, tt.parent, tt.count, tt.term_taxonomy_id
				 FROM %i t
				 INNER JOIN %i tt ON t.term_id = tt.term_id
				 WHERE t.term_id = %d AND tt.taxonomy = %s',
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$term_id,
				$taxonomy
			)
		);
	}

	/**
	 * Insert a term directly into the database
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $args     Additional arguments (slug, description, parent).
	 * @return int|false New term ID or false on failure.
	 */
	public static function insert_term( $name, $taxonomy, $args = array() ) {
		global $wpdb;

		$slug = isset( $args['slug'] ) ? $args['slug'] : sanitize_title( $name );

		wptsall_log_debug(
			'tasks-sync',
			'Direct_DB_Service::insert_term started',
			array(
				'name'     => $name,
				'taxonomy' => $taxonomy,
				'slug'     => $slug,
			)
		);

		// Check if term with same slug exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT t.term_id FROM %i t
				 INNER JOIN %i tt ON t.term_id = tt.term_id
				 WHERE t.slug = %s AND tt.taxonomy = %s',
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$slug,
				$taxonomy
			)
		);

		if ( $existing ) {
			wptsall_log_debug(
				'tasks-sync',
				'Term already exists, returning existing ID',
				array(
					'term_id'  => (int) $existing,
					'taxonomy' => $taxonomy,
				)
			);
			return (int) $existing;
		}

		// Insert into terms table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$wpdb->terms,
			array(
				'name'       => $name,
				'slug'       => $slug,
				'term_group' => 0,
			)
		);

		if ( ! $result ) {
			wptsall_log_error(
				'tasks-sync',
				'Direct_DB_Service::insert_term failed',
				array(
					'name'     => $name,
					'taxonomy' => $taxonomy,
					'error'    => $wpdb->last_error,
				)
			);
			return false;
		}

		$term_id = $wpdb->insert_id;

		// Insert into term_taxonomy table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_id'     => $term_id,
				'taxonomy'    => $taxonomy,
				'description' => isset( $args['description'] ) ? $args['description'] : '',
				'parent'      => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
				'count'       => 0,
			)
		);

		wptsall_log_info(
			'tasks-sync',
			'Direct_DB_Service::insert_term succeeded',
			array(
				'term_id'  => $term_id,
				'name'     => $name,
				'taxonomy' => $taxonomy,
			)
		);

		return $term_id;
	}

	/**
	 * Update a term directly in the database
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $args     Data to update (name, slug, description, parent).
	 * @return bool Success status.
	 */
	public static function update_term( $term_id, $taxonomy, $args ) {
		global $wpdb;

		wptsall_log_debug(
			'tasks-sync',
			'Direct_DB_Service::update_term started',
			array(
				'term_id'     => $term_id,
				'taxonomy'    => $taxonomy,
				'field_count' => count( $args ),
			)
		);

		$term_data = array();
		$tt_data   = array();

		if ( isset( $args['name'] ) ) {
			$term_data['name'] = $args['name'];
		}
		if ( isset( $args['slug'] ) ) {
			$term_data['slug'] = $args['slug'];
		}
		if ( isset( $args['description'] ) ) {
			$tt_data['description'] = $args['description'];
		}
		if ( isset( $args['parent'] ) ) {
			$tt_data['parent'] = (int) $args['parent'];
		}

		$success = true;

		if ( ! empty( $term_data ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->terms,
				$term_data,
				array( 'term_id' => $term_id )
			);
			$success = $success && ( false !== $result );
			if ( false === $result ) {
				wptsall_log_error(
					'tasks-sync',
					'Direct_DB_Service::update_term terms table failed',
					array(
						'term_id' => $term_id,
						'error'   => $wpdb->last_error,
					)
				);
			}
		}

		if ( ! empty( $tt_data ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->term_taxonomy,
				$tt_data,
				array(
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				)
			);
			$success = $success && ( false !== $result );
			if ( false === $result ) {
				wptsall_log_error(
					'tasks-sync',
					'Direct_DB_Service::update_term term_taxonomy table failed',
					array(
						'term_id'  => $term_id,
						'taxonomy' => $taxonomy,
						'error'    => $wpdb->last_error,
					)
				);
			}
		}

		if ( $success ) {
			clean_term_cache( $term_id, $taxonomy );
			wptsall_log_info(
				'tasks-sync',
				'Direct_DB_Service::update_term succeeded',
				array(
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				)
			);
		}

		return $success;
	}

	/**
	 * Insert or update term meta directly
	 *
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool Success status.
	 */
	public static function update_term_meta( $term_id, $meta_key, $meta_value ) {
		global $wpdb;

		$meta_value = maybe_serialize( $meta_value );

		// Check if meta exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_id FROM %i WHERE term_id = %d AND meta_key = %s',
				$wpdb->termmeta,
				$term_id,
				$meta_key
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update(
				$wpdb->termmeta,
				array( 'meta_value' => $meta_value ),
				array( 'meta_id' => $existing )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert(
			$wpdb->termmeta,
			array(
				'term_id'    => $term_id,
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			)
		);
	}

	/**
	 * Find term by meta value
	 *
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param string $taxonomy   Optional taxonomy filter.
	 * @return int|null Term ID or null.
	 */
	public static function get_term_by_meta( $meta_key, $meta_value, $taxonomy = null ) {
		global $wpdb;

		if ( $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT tm.term_id FROM %i tm
					 INNER JOIN %i tt ON tm.term_id = tt.term_id
					 WHERE tm.meta_key = %s AND tm.meta_value = %s AND tt.taxonomy = %s
					 LIMIT 1',
					$wpdb->termmeta,
					$wpdb->term_taxonomy,
					$meta_key,
					maybe_serialize( $meta_value ),
					$taxonomy
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT tm.term_id FROM %i tm
					 WHERE tm.meta_key = %s AND tm.meta_value = %s
					 LIMIT 1',
					$wpdb->termmeta,
					$meta_key,
					maybe_serialize( $meta_value )
				)
			);
		}

		return $result ? (int) $result : null;
	}

	/**
	 * Find post by meta value
	 *
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return int|null Post ID or null.
	 */
	public static function get_post_by_meta( $meta_key, $meta_value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s LIMIT 1',
				$wpdb->postmeta,
				$meta_key,
				maybe_serialize( $meta_value )
			)
		);

		return $result ? (int) $result : null;
	}

	/**
	 * Read post meta directly from the postmeta table.
	 *
	 * Bypasses the plugin's get_post_metadata filter on purpose: sync write
	 * paths (copy_once target checks, _elementor_data URL rewrites) must
	 * see the stored value, not the filtered one.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param bool   $single   Return the first value only (matches get_post_meta()).
	 * @return mixed Stored value; '' (single) or array() (multi) when absent.
	 */
	public static function get_post_meta( $post_id, $meta_key, $single = true ) {
		global $wpdb;

		$post_id  = absint( $post_id );
		$meta_key = (string) $meta_key;
		if ( $post_id <= 0 || '' === $meta_key ) {
			return $single ? '' : array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id',
				$wpdb->postmeta,
				$post_id,
				$meta_key
			),
			ARRAY_A
		);

		$values = array();
		foreach ( (array) $rows as $row ) {
			$values[] = maybe_unserialize( $row['meta_value'] ?? '' );
		}

		if ( $single ) {
			return $values[0] ?? '';
		}
		return $values;
	}

	/**
	 * Copy post meta from one post to another (direct copy)
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param int   $target_post_id Target post ID.
	 * @param array $exclude_keys   Meta keys to exclude.
	 * @return int Number of meta entries copied.
	 */
	public static function copy_post_meta( $source_post_id, $target_post_id, $exclude_keys = array() ) {
		global $wpdb;

		$exclude_keys = array_merge( $exclude_keys, array( '_wptsall_source_post_id', '_wptsall_sync_hash' ) );

		if ( ! empty( $exclude_keys ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $exclude_keys ), '%s' ) );
			$metas        = wptsall_db_get_results(
				"SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key NOT IN ({$placeholders})",
				array_merge( array( $wpdb->postmeta, $source_post_id ), $exclude_keys )
			);
		} else {
			$metas = wptsall_db_get_results(
				'SELECT meta_key, meta_value FROM %i WHERE post_id = %d',
				array( $wpdb->postmeta, $source_post_id )
			);
		}

		$count = 0;
		foreach ( (array) $metas as $meta ) {
			self::update_post_meta( $target_post_id, $meta->meta_key, maybe_unserialize( $meta->meta_value ) );
			$count++;
		}

		return $count;
	}
}
