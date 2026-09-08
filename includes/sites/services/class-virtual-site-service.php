<?php
/**
 * Virtual Site Service
 *
 * Virtual Site Service - handles CRUD operations for virtual sites
 *
 * @package WPTSALL
 * @since 0.3.0
  * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers from internal helpers (wptsall_table / \$wpdb->prefix . 'wptsall_*'); user values use prepare placeholders.
 */
namespace WPTSALL\Sites\Services;

use WPTSALL\Sites\Validators\Site_Relation_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Virtual_Site_Service class
 *
 * Provides business logic for virtual site management
 *
 * Database Schema Mapping:
 * - id -> id (primary key)
 * - site_name -> name
 * - site_tagline -> subtitle
 * - site_path -> path_prefix
 * - site_language -> lang
 * - site_logo -> logo_url
 * - source_blog_id -> blog_source_site
 * - enable_blog_sync -> enable_blog_sync
 * - permalink_structure -> permalink_structure (empty=inherit from source site)
 * - category_base -> category_base (empty=inherit from source site) v0.6.0
 * - tag_base -> tag_base (empty=inherit from source site) v0.6.0
 * - status -> status
 * - created_at, updated_at
 */
class Virtual_Site_Service {
	/**
	 * Check if the virtual sites table exists.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		if ( function_exists( 'wptsall_virtual_sites_table_exists' ) ) {
			return wptsall_virtual_sites_table_exists();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_sites';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $table_exists === $table;
	}

	/**
	 * Load virtual sites from option storage.
	 *
	 * @return array
	 */
	private static function load_option_sites() {
		$sites = get_option( 'wptsall_virtual_sites', array() );

		if ( ! is_array( $sites ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $sites as $site ) {
			$normalized_site = self::normalize_option_site( $site );
			if ( ! empty( $normalized_site ) ) {
				$normalized[] = $normalized_site;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize option-stored site data to API shape.
	 *
	 * @param array $site Raw option site entry.
	 * @return array
	 */
	private static function normalize_option_site( $site ) {
		if ( ! is_array( $site ) ) {
			return array();
		}

		$site_id = $site['id'] ?? '';
		if ( '' === $site_id ) {
			return array();
		}

		$path_prefix = $site['path_prefix'] ?? $site['base'] ?? $site['site_path'] ?? '';
		$path_prefix = trim( (string) $path_prefix, '/' );
		if ( '' === $path_prefix ) {
			$path_prefix = 'virtual/' . $site_id;
		}

		$created_at = $site['created_at'] ?? current_time( 'mysql' );
		$updated_at = $site['updated_at'] ?? $created_at;

		return array(
			'id'                   => $site_id,
			'name'                 => $site['name'] ?? $site['site_name'] ?? (string) $site_id,
			'subtitle'             => $site['subtitle'] ?? $site['site_tagline'] ?? $site['description'] ?? '',
			'path_prefix'          => $path_prefix,
			'lang'                 => $site['lang'] ?? $site['site_language'] ?? '',
			'logo_url'             => $site['logo_url'] ?? $site['site_logo'] ?? '',
			'enable_blog_sync'     => (int) ( $site['enable_blog_sync'] ?? 0 ),
			'blog_source_site'     => (int) ( $site['blog_source_site'] ?? $site['source_blog_id'] ?? 0 ),
			'permalink_structure'  => $site['permalink_structure'] ?? '',
			'category_base'        => $site['category_base'] ?? '',
			'tag_base'             => $site['tag_base'] ?? '',
			'status'               => $site['status'] ?? 'active',
			'created_at'           => $created_at,
			'updated_at'           => $updated_at,
		);
	}

	/**
	 * Fetch all sites from the database table without filters.
	 *
	 * @return array
	 */
	private static function get_all_from_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_sites';

		$results = wptsall_db_get_results(
			'SELECT * FROM %i ORDER BY created_at DESC',
			array( $table ),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'normalize_site_data' ), $results ? $results : array() );
	}

	/**
	 * Merge sites by ID, preserving the first occurrence.
	 *
	 * @param array $sites Existing sites.
	 * @param array $additions Sites to add.
	 * @return array
	 */
	private static function merge_sites( $sites, $additions ) {
		$index = array();
		foreach ( $sites as $site ) {
			$index[ (string) ( $site['id'] ?? '' ) ] = true;
		}

		foreach ( $additions as $site ) {
			$site_id = (string) ( $site['id'] ?? '' );
			if ( '' === $site_id || isset( $index[ $site_id ] ) ) {
				continue;
			}
			$sites[] = $site;
			$index[ $site_id ] = true;
		}

		return $sites;
	}

	/**
	 * Merge virtual sites from site_relations table (if present).
	 *
	 * @param array $sites Existing sites.
	 * @return array
	 */
	private static function merge_site_relations( $sites ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			return $sites;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relations = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT target_site_id, target_lang, target_theme_name, target_theme_path, status, created_at, updated_at
				 FROM %i
				 WHERE target_site_type = %s',
				$table,
				'virtual'
			),
			ARRAY_A
		);

		if ( empty( $relations ) ) {
			return $sites;
		}

		$index = array();
		foreach ( $sites as $site ) {
			$index[ (string) ( $site['id'] ?? '' ) ] = true;
		}

		foreach ( $relations as $relation ) {
			$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
			if ( '' === $target_site_id ) {
				continue;
			}

			$raw_id  = Site_Relation_Validator::parse_virtual_site_id( $target_site_id );
			$site_id = '' !== (string) $raw_id ? (string) $raw_id : $target_site_id;

			if ( isset( $index[ $site_id ] ) || isset( $index[ $target_site_id ] ) ) {
				continue;
			}

			$path_prefix = trim( (string) ( $relation['target_theme_path'] ?? '' ), '/' );
			if ( '' === $path_prefix && ! empty( $relation['target_lang'] ) ) {
				$path_prefix = sanitize_title( (string) $relation['target_lang'] );
			}
			if ( '' === $path_prefix ) {
				$path_prefix = 'virtual/' . $site_id;
			}

			$created_at = $relation['created_at'] ?? current_time( 'mysql' );
			$updated_at = $relation['updated_at'] ?? $created_at;

			$sites[] = array(
				'id'                   => $site_id,
				'name'                 => $relation['target_theme_name'] ?: $site_id,
				'subtitle'             => '',
				'path_prefix'          => $path_prefix,
				'lang'                 => $relation['target_lang'] ?? '',
				'logo_url'             => '',
				'enable_blog_sync'     => 0,
				'blog_source_site'     => 0,
				'permalink_structure'  => '',
				'status'               => $relation['status'] ?? 'active',
				'created_at'           => $created_at,
				'updated_at'           => $updated_at,
			);
			$index[ $site_id ] = true;
		}

		return $sites;
	}

	/**
	 * Filter and sort sites.
	 *
	 * @param array $sites Sites to filter.
	 * @param array $args  Filter arguments.
	 * @return array
	 */
	private static function filter_sites( $sites, $args ) {
		if ( isset( $args['status'] ) ) {
			$sites = array_filter( $sites, function ( $site ) use ( $args ) {
				return ( $site['status'] ?? '' ) === $args['status'];
			} );
		}

		if ( isset( $args['lang'] ) ) {
			$sites = array_filter( $sites, function ( $site ) use ( $args ) {
				return ( $site['lang'] ?? '' ) === $args['lang'];
			} );
		}

		if ( ! empty( $args['search'] ) ) {
			$needle = strtolower( $args['search'] );
			$sites  = array_filter( $sites, function ( $site ) use ( $needle ) {
				$haystack = strtolower(
					( $site['name'] ?? '' ) . ' ' . ( $site['subtitle'] ?? '' ) . ' ' . ( $site['path_prefix'] ?? '' )
				);
				return false !== strpos( $haystack, $needle );
			} );
		}

		$orderby = $args['orderby'] ?? 'created_at';
		$order   = ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		usort( $sites, function ( $a, $b ) use ( $orderby, $order ) {
			$left  = $a[ $orderby ] ?? '';
			$right = $b[ $orderby ] ?? '';
			if ( $left === $right ) {
				return 0;
			}
			$cmp = ( $left < $right ) ? -1 : 1;
			return ( 'ASC' === $order ) ? $cmp : -$cmp;
		} );

		if ( isset( $args['limit'] ) ) {
			$sites = array_slice( $sites, 0, absint( $args['limit'] ) );
		}

		return array_values( $sites );
	}

	/**
	 * Generate a numeric site ID for option storage.
	 *
	 * @param array $sites Existing option sites.
	 * @return int
	 */
	private static function generate_option_site_id( $sites ) {
		$max_id = 0;

		foreach ( $sites as $site ) {
			$site_id = $site['id'] ?? '';
			if ( is_numeric( $site_id ) ) {
				$max_id = max( $max_id, (int) $site_id );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists === $table ) {
			$ids = wptsall_db_get_col(
				"SELECT DISTINCT target_site_id FROM %i WHERE target_site_type = 'virtual'",
				array( $table )
			);
			foreach ( $ids as $id ) {
				$raw = Site_Relation_Validator::parse_virtual_site_id( $id );
				if ( is_numeric( $raw ) ) {
					$max_id = max( $max_id, (int) $raw );
				}
			}
		}

		return $max_id + 1;
	}

	/**
	 * Find option site index by ID.
	 *
	 * @param array  $sites Option sites.
	 * @param string $site_id Site ID.
	 * @return int
	 */
	private static function find_option_site_index( $sites, $site_id ) {
		foreach ( $sites as $index => $site ) {
			if ( (string) ( $site['id'] ?? '' ) === (string) $site_id ) {
				return $index;
			}
		}

		return -1;
	}

	/**
	 * Flush rewrite rules when virtual site routes change.
	 *
	 * @return void
	 */
	private static function flush_rewrite_rules_if_available() {
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Create a virtual site in option storage.
	 *
	 * @param array $data Site data.
	 * @return array
	 */
	private static function create_option_site( $data ) {
		$sites = get_option( 'wptsall_virtual_sites', array() );
		if ( ! is_array( $sites ) ) {
			$sites = array();
		}

		$path_prefix = sanitize_title( $data['path_prefix'] );
		$conflict    = self::check_url_conflict( $path_prefix );
		if ( $conflict['has_conflict'] ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'URL path prefix already exists', 'wpmmcc-ats' ) ),
			);
		}

		$site_id = self::generate_option_site_id( $sites );
		$base    = $path_prefix ? $path_prefix : 'virtual/' . $site_id;

		$new_site = array(
			'id'                  => $site_id,
			'name'                => sanitize_text_field( $data['name'] ),
			'subtitle'            => isset( $data['subtitle'] ) ? sanitize_text_field( $data['subtitle'] ) : '',
			'path_prefix'         => $path_prefix,
			'base'                => trim( $base, '/' ),
			'lang'                => sanitize_text_field( $data['lang'] ),
			'logo_url'            => isset( $data['logo_url'] ) ? esc_url_raw( $data['logo_url'] ) : '',
			'enable_blog_sync'    => isset( $data['enable_blog_sync'] ) ? (int) $data['enable_blog_sync'] : 0,
			'blog_source_site'    => isset( $data['blog_source_site'] ) ? absint( $data['blog_source_site'] ) : 0,
			'permalink_structure' => isset( $data['permalink_structure'] ) ? wp_strip_all_tags( $data['permalink_structure'] ) : '',
			'status'              => 'active',
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);

		$sites[] = $new_site;
		update_option( 'wptsall_virtual_sites', array_values( $sites ) );

		wptsall_log_info(
			'sites-virtual',
			'Virtual site created (option storage)',
			array(
				'site_id' => $site_id,
				'name'    => $new_site['name'],
			)
		);

		do_action( 'wptsall_virtual_site_created', $site_id, $data );
		self::flush_rewrite_rules_if_available();

		return array(
			'success' => true,
			'site_id' => $site_id,
		);
	}

	/**
	 * Update a virtual site in option storage.
	 *
	 * @param string $site_id Site ID.
	 * @param array  $data Site data.
	 * @return array
	 */
	private static function update_option_site( $site_id, $data ) {
		$sites = get_option( 'wptsall_virtual_sites', array() );
		if ( ! is_array( $sites ) ) {
			$sites = array();
		}

		$index = self::find_option_site_index( $sites, $site_id );
		if ( $index < 0 ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Virtual site does not exist', 'wpmmcc-ats' ) ),
			);
		}

		$site = $sites[ $index ];

		if ( isset( $data['path_prefix'] ) ) {
			$path_prefix = sanitize_title( $data['path_prefix'] );
			$conflict    = self::check_url_conflict( $path_prefix, $site_id );
			if ( $conflict['has_conflict'] ) {
				return array(
					'success' => false,
					'errors'  => array( __( 'URL path prefix already exists', 'wpmmcc-ats' ) ),
				);
			}
			$site['path_prefix'] = $path_prefix;
			$site['base']        = trim( $path_prefix ? $path_prefix : ( 'virtual/' . $site_id ), '/' );
		}

		if ( isset( $data['name'] ) ) {
			$site['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['subtitle'] ) ) {
			$site['subtitle'] = sanitize_text_field( $data['subtitle'] );
		}
		if ( isset( $data['lang'] ) ) {
			$site['lang'] = sanitize_text_field( $data['lang'] );
		}
		if ( isset( $data['logo_url'] ) ) {
			$site['logo_url'] = esc_url_raw( $data['logo_url'] );
		}
		if ( isset( $data['enable_blog_sync'] ) ) {
			$site['enable_blog_sync'] = (int) $data['enable_blog_sync'];
		}
		if ( isset( $data['blog_source_site'] ) ) {
			$site['blog_source_site'] = absint( $data['blog_source_site'] );
		}
		if ( isset( $data['permalink_structure'] ) ) {
			$site['permalink_structure'] = wp_strip_all_tags( $data['permalink_structure'] );
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'active', 'inactive' ), true ) ) {
			$site['status'] = $data['status'];
		}

		$site['updated_at'] = current_time( 'mysql' );

		$sites[ $index ] = $site;
		update_option( 'wptsall_virtual_sites', array_values( $sites ) );

		wptsall_log_info(
			'sites-virtual',
			'Virtual site updated (option storage)',
			array(
				'site_id' => $site_id,
				'fields'  => array_keys( $data ),
			)
		);

		do_action( 'wptsall_virtual_site_updated', $site_id, $data );
		self::flush_rewrite_rules_if_available();

		return array(
			'success' => true,
		);
	}

	/**
	 * Delete a virtual site from option storage.
	 *
	 * @param string $site_id Site ID.
	 * @return bool
	 */
	private static function delete_option_site( $site_id ) {
		$sites = get_option( 'wptsall_virtual_sites', array() );
		if ( ! is_array( $sites ) ) {
			return false;
		}

		$index = self::find_option_site_index( $sites, $site_id );
		if ( $index < 0 ) {
			return false;
		}

		unset( $sites[ $index ] );
		update_option( 'wptsall_virtual_sites', array_values( $sites ) );

		return true;
	}

	/**
	 * Get all virtual sites
	 *
	 * @param array $args Query parameters
	 * @return array Virtual site list
	 */
	public static function get_all( $args = array() ) {
		$sites = array();

		if ( self::table_exists() ) {
			$sites = self::get_all_from_table();
		}

		$sites = self::merge_sites( $sites, self::load_option_sites() );
		$sites = self::merge_site_relations( $sites );

		return self::filter_sites( $sites, $args );
	}

	/**
	 * Get a single virtual site
	 *
	 * @param string $site_id Site ID.
	 * @return array|null Virtual site data
	 */
	public static function get( $site_id ) {
		$site_id = (string) $site_id;
		if ( '' === $site_id ) {
			return null;
		}

		$candidates = array( $site_id );
		if ( 0 === strpos( $site_id, 'v_' ) ) {
			$candidates[] = substr( $site_id, 2 );
		} else {
			$candidates[] = 'v_' . $site_id;
		}
		$candidates = array_values( array_unique( array_filter( $candidates, 'strlen' ) ) );

		if ( self::table_exists() ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wptsall_virtual_sites';

			foreach ( $candidates as $cid ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$site = $wpdb->get_row(
					$wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', $table, $cid ),
					ARRAY_A
				);
				if ( $site ) {
					return self::normalize_site_data( $site );
				}
			}
		}

		$sites = self::get_all();
		foreach ( $sites as $site ) {
			$sid = (string) ( $site['id'] ?? '' );
			if ( in_array( $sid, $candidates, true ) ) {
				return $site;
			}
			// Meta / relation IDs often keep a v_ prefix while merged rows strip it.
			if ( '' !== $sid && in_array( 'v_' . $sid, $candidates, true ) ) {
				return $site;
			}
		}

		return null;
	}

	/**
	 * Get the virtual site associated with a site relation ID
	 *
	 * Finds the virtual site linked to the given site relation.
	 * Note: A site relation can only be linked to one virtual site (target type virtual).
	 *
	 * @since 1.0.0
	 * @param int $relation_id Site relation ID.
	 * @return array|null Virtual site data or null.
	 */
	public static function get_by_relation( $relation_id ) {
		global $wpdb;
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations_table ) );
		if ( $table_exists !== $relations_table ) {
			return null;
		}

		// Query site relation to get virtual site ID.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT target_site_id, target_lang, target_theme_name, target_theme_path, status, created_at, updated_at
				FROM %i
				WHERE id = %d AND target_site_type = 'virtual'",
				$relations_table,
				$relation_id
			),
			ARRAY_A
		);

		if ( ! $relation ) {
			return null;
		}

		$target_site_id = $relation['target_site_id'] ?? '';
		if ( '' === $target_site_id ) {
			return null;
		}

		// Try to fetch full data from the virtual sites table.
		$raw_id = Site_Relation_Validator::parse_virtual_site_id( $target_site_id );
		$site   = self::get( $raw_id );

		if ( $site ) {
			// Add associated relation ID.
			$site['site_relation_id'] = (int) $relation_id;
			return $site;
		}

		// If not in the virtual sites table, build from site_relations data.
		$site_id     = '' !== (string) $raw_id ? (string) $raw_id : $target_site_id;
		$path_prefix = trim( (string) ( $relation['target_theme_path'] ?? '' ), '/' );

		if ( '' === $path_prefix && ! empty( $relation['target_lang'] ) ) {
			$path_prefix = sanitize_title( (string) $relation['target_lang'] );
		}
		if ( '' === $path_prefix ) {
			$path_prefix = 'virtual/' . $site_id;
		}

		return array(
			'id'                   => $site_id,
			'site_relation_id'     => (int) $relation_id,
			'name'                 => $relation['target_theme_name'] ?: $site_id,
			'subtitle'             => '',
			'path_prefix'          => $path_prefix,
			'lang'                 => $relation['target_lang'] ?? '',
			'logo_url'             => '',
			'enable_blog_sync'     => 0,
			'blog_source_site'     => 0,
			'permalink_structure'  => '',
			'category_base'        => '',
			'tag_base'             => '',
			'status'               => $relation['status'] ?? 'active',
			'created_at'           => $relation['created_at'] ?? current_time( 'mysql' ),
			'updated_at'           => $relation['updated_at'] ?? current_time( 'mysql' ),
		);
	}

	/**
	 * Create virtual site
	 *
	 * @param array $data Site data
	 * @return array Result
	 */
	public static function create( $data ) {
		// Validate required fields
		$required = array( 'name', 'path_prefix', 'lang' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
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

		$data['path_prefix'] = sanitize_title( $data['path_prefix'] );

		if ( self::table_exists() ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wptsall_virtual_sites';

			$conflict = self::check_url_conflict( $data['path_prefix'] );
			if ( $conflict['has_conflict'] ) {
				return array(
					'success' => false,
					'errors'  => array( __( 'URL path prefix already exists', 'wpmmcc-ats' ) ),
				);
			}

			// Insert data (ID is auto-increment, no need to set it)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$table,
				array(
					'site_name'            => sanitize_text_field( $data['name'] ),
					'site_tagline'         => isset( $data['subtitle'] ) ? sanitize_text_field( $data['subtitle'] ) : '',
					'site_path'            => $data['path_prefix'],
					'site_language'        => sanitize_text_field( $data['lang'] ),
					'site_logo'            => isset( $data['logo_url'] ) ? esc_url_raw( $data['logo_url'] ) : '',
					'enable_blog_sync'     => isset( $data['enable_blog_sync'] ) ? (int) $data['enable_blog_sync'] : 0,
					'source_blog_id'       => isset( $data['blog_source_site'] ) ? absint( $data['blog_source_site'] ) : 0,
					'permalink_structure'  => isset( $data['permalink_structure'] ) ? wp_strip_all_tags( $data['permalink_structure'] ) : '',
					'category_base'        => isset( $data['category_base'] ) ? sanitize_title( $data['category_base'] ) : '',
					'tag_base'             => isset( $data['tag_base'] ) ? sanitize_title( $data['tag_base'] ) : '',
					'status'               => 'active',
					'created_at'           => current_time( 'mysql' ),
					'updated_at'           => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $result ) {
				return array(
					'success' => false,
					'errors'  => array( $wpdb->last_error ? $wpdb->last_error : __( 'Database insert failed', 'wpmmcc-ats' ) ),
				);
			}

			// Get the auto-generated ID
			$site_id = $wpdb->insert_id;

			// Log entry
			wptsall_log_info(
				'sites-virtual',
				'Virtual site created',
				array(
					'site_id' => $site_id,
					'name'    => $data['name'],
				)
			);

			do_action( 'wptsall_virtual_site_created', $site_id, $data );
			self::flush_rewrite_rules_if_available();

			return array(
				'success' => true,
				'site_id' => $site_id,
			);
		}

		return self::create_option_site( $data );
	}

	/**
	 * Update virtual site
	 *
	 * @param string $site_id Site ID
	 * @param array  $data    Update data
	 * @return array Result
	 */
	public static function update( $site_id, $data ) {
		$site_id = (string) $site_id;

		if ( self::table_exists() ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wptsall_virtual_sites';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$site = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', $table, $site_id ),
				ARRAY_A
			);

			if ( $site ) {
				if ( isset( $data['path_prefix'] ) ) {
					$path_prefix = sanitize_title( $data['path_prefix'] );
					$conflict    = self::check_url_conflict( $path_prefix, $site_id );
					if ( $conflict['has_conflict'] ) {
						return array(
							'success' => false,
							'errors'  => array( __( 'URL path prefix already exists', 'wpmmcc-ats' ) ),
						);
					}
				}

				// Prepare update data
				$update_data = array(
					'updated_at' => current_time( 'mysql' ),
				);

				$format = array( '%s' );

				// Updatable field mapping
				$field_map = array(
					'name'                => 'site_name',
					'subtitle'            => 'site_tagline',
					'path_prefix'         => 'site_path',
					'lang'                => 'site_language',
					'logo_url'            => 'site_logo',
					'enable_blog_sync'    => 'enable_blog_sync',
					'blog_source_site'    => 'source_blog_id',
					'permalink_structure' => 'permalink_structure',
					'category_base'       => 'category_base',
					'tag_base'            => 'tag_base',
					'status'              => 'status',
				);

				foreach ( $field_map as $api_field => $db_field ) {
					if ( isset( $data[ $api_field ] ) ) {
						switch ( $api_field ) {
							case 'name':
							case 'subtitle':
							case 'lang':
								$update_data[ $db_field ] = sanitize_text_field( $data[ $api_field ] );
								$format[]                 = '%s';
								break;

							case 'permalink_structure':
								// Use wp_strip_all_tags to preserve % placeholders.
								$update_data[ $db_field ] = wp_strip_all_tags( $data[ $api_field ] );
								$format[]                 = '%s';
								break;

							case 'category_base':
							case 'tag_base':
								// Use sanitize_title to allow alphanumeric characters.
								$update_data[ $db_field ] = sanitize_title( $data[ $api_field ] );
								$format[]                 = '%s';
								break;

							case 'path_prefix':
								$update_data[ $db_field ] = sanitize_title( $data[ $api_field ] );
								$format[]                 = '%s';
								break;

							case 'logo_url':
								$update_data[ $db_field ] = esc_url_raw( $data[ $api_field ] );
								$format[]                 = '%s';
								break;

							case 'enable_blog_sync':
								$update_data[ $db_field ] = (int) $data[ $api_field ];
								$format[]                 = '%d';
								break;

							case 'blog_source_site':
								$update_data[ $db_field ] = absint( $data[ $api_field ] );
								$format[]                 = '%d';
								break;

							case 'status':
								if ( in_array( $data[ $api_field ], array( 'active', 'inactive' ), true ) ) {
									$update_data[ $db_field ] = $data[ $api_field ];
									$format[]                 = '%s';
								}
								break;
						}
					}
				}

				// Execute update
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					$table,
					$update_data,
					array( 'id' => $site_id ),
					$format,
					array( '%s' )
				);

				if ( false === $result ) {
					return array(
						'success' => false,
						'errors'  => array( $wpdb->last_error ),
					);
				}

				// Log entry
				wptsall_log_info(
					'sites-virtual',
					'Virtual site updated',
					array(
						'site_id' => $site_id,
						'fields'  => array_keys( $update_data ),
					)
				);

		do_action( 'wptsall_virtual_site_updated', $site_id, $data );
		self::flush_rewrite_rules_if_available();

				return array(
					'success' => true,
				);
			}
		}

		return self::update_option_site( $site_id, $data );
	}

	/**
	 * Delete virtual site
	 *
	 * @param string $site_id Site ID
	 * @return array Result
	 */
	public static function delete( $site_id ) {
		$site_id = (string) $site_id;
		$site    = self::get( $site_id );

		if ( ! $site ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Virtual site does not exist', 'wpmmcc-ats' ) ),
			);
		}

		$prefixed_id = Site_Relation_Validator::format_virtual_site_id( $site_id );
		$in_use      = 0;

		global $wpdb;
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations_table ) );
		if ( $table_exists === $relations_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$in_use = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE target_site_type = %s AND target_site_id IN (%s, %s)',
					$relations_table,
					'virtual',
					$prefixed_id,
					$site_id
				)
			);
		}

		if ( $in_use > 0 ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Virtual site is in use and cannot be deleted', 'wpmmcc-ats' ) ),
			);
		}

		$legacy_relations = get_option( 'wptsall_sites', array() );
		if ( is_array( $legacy_relations ) ) {
			foreach ( $legacy_relations as $relation ) {
				foreach ( (array) ( $relation['targets'] ?? array() ) as $target ) {
					if ( 'virtual' === ( $target['type'] ?? '' ) && ( (string) ( $target['id'] ?? '' ) === $site_id || (string) ( $target['id'] ?? '' ) === $prefixed_id ) ) {
						return array(
							'success' => false,
							'errors'  => array( __( 'Virtual site is in use and cannot be deleted', 'wpmmcc-ats' ) ),
						);
					}
				}
			}
		}

		$deleted = false;
		if ( self::table_exists() ) {
			$table = $wpdb->prefix . 'wptsall_virtual_sites';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->delete(
				$table,
				array( 'id' => $site_id ),
				array( '%s' )
			);

			if ( false === $result ) {
				return array(
					'success' => false,
					'errors'  => array( $wpdb->last_error ),
				);
			}

			$deleted = $result > 0;
		}

		if ( ! $deleted ) {
			$deleted = self::delete_option_site( $site_id );
		}

		if ( ! $deleted ) {
			return array(
				'success' => false,
				'errors'  => array( __( 'Virtual site does not exist', 'wpmmcc-ats' ) ),
			);
		}

		// Log entry
		wptsall_log_info(
			'sites-virtual',
			'Virtual site deleted',
			array(
				'site_id' => $site_id,
				'name'    => $site['name'],
			)
		);

		do_action( 'wptsall_virtual_site_deleted', $site_id, $site );
		self::flush_rewrite_rules_if_available();

		return array(
			'success' => true,
		);
	}

	/**
	 * Check URL conflicts
	 *
	 * @param string $path_prefix URL path prefix
	 * @param string $exclude_id  Site ID to exclude
	 * @return array Conflict information
	 */
	public static function check_url_conflict( $path_prefix, $exclude_id = '' ) {
		$conflicts = array();
		$path_prefix = trim( (string) $path_prefix, '/' );

		$exclude_id = (string) $exclude_id;
		$sites      = self::get_all();

		foreach ( $sites as $site ) {
			$site_id     = (string) ( $site['id'] ?? '' );
			$site_prefix = trim( (string) ( $site['path_prefix'] ?? '' ), '/' );

			if ( '' === $site_prefix || $site_id === $exclude_id ) {
				continue;
			}

			if ( $site_prefix === $path_prefix ) {
				$conflicts[] = array(
					'type' => 'virtual_site',
					'id'   => $site_id,
					'name' => $site['name'] ?? $site_id,
					'path' => $site_prefix,
				);
			}
		}

		// Check multisite subdirectory conflicts
		if ( is_multisite() ) {
			$sites = get_sites(
				array(
					'number' => 100,
					'path'   => '/' . $path_prefix . '/',
				)
			);

			foreach ( $sites as $site ) {
				$conflicts[] = array(
					'type' => 'multisite',
					'id'   => $site->blog_id,
					'name' => get_blog_details( $site->blog_id )->blogname,
					'path' => trim( $site->path, '/' ),
				);
			}
		}

		return array(
			'has_conflict' => ! empty( $conflicts ),
			'conflicts'    => $conflicts,
		);
	}

	/**
	 * Get available languages list
	 *
	 * Uses centralized wptsall_get_available_languages() function.
	 *
	 * @since 0.8.1 Refactored to use centralized function.
	 * @return array Language list
	 */
	public static function get_available_languages() {
		return wptsall_get_available_languages();
	}

	/**
	 * Bulk create virtual sites
	 *
	 * @since 0.9.0
	 * @param array $sites_data Array of site data; each element contains name, path_prefix, lang, etc.
	 * @return array Result with success, created, and errors
	 */
	public static function bulk_create( $sites_data ) {
		if ( ! is_array( $sites_data ) || empty( $sites_data ) ) {
			return array(
				'success' => false,
				'created' => array(),
				'errors'  => array( __( 'No valid site data provided', 'wpmmcc-ats' ) ),
			);
		}

		$created = array();
		$errors  = array();

		foreach ( $sites_data as $index => $data ) {
			$result = self::create( $data );

			if ( $result['success'] ) {
				$created[] = array(
					'index'   => $index,
					'site_id' => $result['site_id'],
					'name'    => $data['name'] ?? '',
				);
			} else {
				$errors[] = array(
					'index'  => $index,
					'name'   => $data['name'] ?? '',
					'errors' => $result['errors'] ?? array( __( 'Creation failed', 'wpmmcc-ats' ) ),
				);
			}
		}

		// Log entry
		wptsall_log_info(
			'sites-virtual',
			'Bulk create virtual sites',
			array(
				'total'   => count( $sites_data ),
				'created' => count( $created ),
				'failed'  => count( $errors ),
			)
		);

		return array(
			'success' => empty( $errors ),
			'created' => $created,
			'errors'  => $errors,
		);
	}

	/**
	 * Bulk delete virtual sites
	 *
	 * @since 0.9.0
	 * @param array $site_ids Array of site IDs
	 * @return array Result with success, deleted, and errors
	 */
	public static function bulk_delete( $site_ids ) {
		if ( ! is_array( $site_ids ) || empty( $site_ids ) ) {
			return array(
				'success' => false,
				'deleted' => array(),
				'errors'  => array( __( 'No valid site IDs provided', 'wpmmcc-ats' ) ),
			);
		}

		$deleted = array();
		$errors  = array();

		foreach ( $site_ids as $site_id ) {
			$site_id = (string) $site_id;
			$site    = self::get( $site_id );

			if ( ! $site ) {
				$errors[] = array(
					'site_id' => $site_id,
					'errors'  => array( __( 'Virtual site does not exist', 'wpmmcc-ats' ) ),
				);
				continue;
			}

			$result = self::delete( $site_id );

			if ( $result['success'] ) {
				$deleted[] = array(
					'site_id' => $site_id,
					'name'    => $site['name'] ?? '',
				);
			} else {
				$errors[] = array(
					'site_id' => $site_id,
					'name'    => $site['name'] ?? '',
					'errors'  => $result['errors'] ?? array( __( 'Delete failed', 'wpmmcc-ats' ) ),
				);
			}
		}

		// Log entry
		wptsall_log_info(
			'sites-virtual',
			'Bulk delete virtual sites',
			array(
				'total'   => count( $site_ids ),
				'deleted' => count( $deleted ),
				'failed'  => count( $errors ),
			)
		);

		return array(
			'success' => empty( $errors ),
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/**
	 * Bulk update virtual sites
	 *
	 * @since 0.9.0
	 * @param array $updates Array of update data; each element contains site_id and fields to update
	 * @return array Result with success, updated, and errors
	 */
	public static function bulk_update( $updates ) {
		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return array(
				'success' => false,
				'updated' => array(),
				'errors'  => array( __( 'No valid update data provided', 'wpmmcc-ats' ) ),
			);
		}

		$updated = array();
		$errors  = array();

		foreach ( $updates as $index => $update ) {
			if ( ! isset( $update['site_id'] ) ) {
				$errors[] = array(
					'index'  => $index,
					'errors' => array( __( 'Missing site_id field', 'wpmmcc-ats' ) ),
				);
				continue;
			}

			$site_id = (string) $update['site_id'];
			$site    = self::get( $site_id );

			if ( ! $site ) {
				$errors[] = array(
					'index'   => $index,
					'site_id' => $site_id,
					'errors'  => array( __( 'Virtual site does not exist', 'wpmmcc-ats' ) ),
				);
				continue;
			}

			// Remove site_id; remaining keys are fields to update
			$data = $update;
			unset( $data['site_id'] );

			if ( empty( $data ) ) {
				$errors[] = array(
					'index'   => $index,
					'site_id' => $site_id,
					'errors'  => array( __( 'No fields to update provided', 'wpmmcc-ats' ) ),
				);
				continue;
			}

			$result = self::update( $site_id, $data );

			if ( $result['success'] ) {
				$updated[] = array(
					'index'   => $index,
					'site_id' => $site_id,
					'name'    => $site['name'] ?? '',
					'fields'  => array_keys( $data ),
				);
			} else {
				$errors[] = array(
					'index'   => $index,
					'site_id' => $site_id,
					'name'    => $site['name'] ?? '',
					'errors'  => $result['errors'] ?? array( __( 'Update failed', 'wpmmcc-ats' ) ),
				);
			}
		}

		// Log entry
		wptsall_log_info(
			'sites-virtual',
			'Bulk update virtual sites',
			array(
				'total'   => count( $updates ),
				'updated' => count( $updated ),
				'failed'  => count( $errors ),
			)
		);

		return array(
			'success' => empty( $errors ),
			'updated' => $updated,
			'errors'  => $errors,
		);
	}

	// =============================================
	// Virtual content query methods (Router support)
	// =============================================

	/**
	 * Find a virtual post by ID from wp_posts + _wptsall_virtual_site_id meta.
	 *
	 * Tries each site ID format in order until a match is found.
	 *
	 * @since 1.5.0
	 *
	 * @param array $site_ids List of virtual site ID formats to try.
	 * @param int   $post_id  WordPress post ID.
	 * @return object|null Row with ID, post_type columns, or null.
	 */
	/**
	 * Find the shadow post id written for a source post on a virtual site.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $site_ids  Virtual site id formats to try.
	 * @param int    $source_id Source post id.
	 * @param string $post_type Optional post type filter.
	 * @return int Shadow post id or 0.
	 */
	public static function find_shadow_post_id_by_source( array $site_ids, int $source_id, string $post_type = '' ) {
		global $wpdb;

		if ( $source_id <= 0 || empty( $site_ids ) ) {
			return 0;
		}

		foreach ( $site_ids as $try_id ) {
			if ( '' !== $post_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT p.ID
						FROM %i p
						INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
						INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
						WHERE pm1.meta_value = %s
						AND pm2.meta_value = %d
						AND p.post_type = %s
						LIMIT 1",
						$wpdb->posts,
						$wpdb->postmeta,
						$wpdb->postmeta,
						(string) $try_id,
						$source_id,
						$post_type
					)
				);
				if ( $found ) {
					return (int) $found;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID
					FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
					WHERE pm1.meta_value = %s
					AND pm2.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					(string) $try_id,
					$source_id
				)
			);
			if ( $found ) {
				return (int) $found;
			}
		}

		return 0;
	}

	/**
	 * Find the shadow term id written for a source term on a virtual site.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $site_ids        Virtual site id formats to try.
	 * @param int    $source_term_id  Source term id.
	 * @param string $taxonomy        Optional taxonomy filter (unused in SQL; reserved).
	 * @return int Shadow term id or 0.
	 */
	public static function find_shadow_term_id_by_source( array $site_ids, int $source_term_id, string $taxonomy = '' ) {
		global $wpdb;

		unset( $taxonomy );

		if ( $source_term_id <= 0 || empty( $site_ids ) ) {
			return 0;
		}

		foreach ( $site_ids as $try_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT t.term_id
					FROM %i t
					INNER JOIN %i tm1 ON t.term_id = tm1.term_id AND tm1.meta_key = '_wptsall_virtual_site_id'
					INNER JOIN %i tm2 ON t.term_id = tm2.term_id AND tm2.meta_key = '_wptsall_source_term_id'
					WHERE tm1.meta_value = %s
					AND tm2.meta_value = %d
					LIMIT 1",
					$wpdb->terms,
					$wpdb->termmeta,
					$wpdb->termmeta,
					(string) $try_id,
					$source_term_id
				)
			);
			if ( $found ) {
				return (int) $found;
			}
		}

		return 0;
	}

	public static function find_virtual_post_by_id( array $site_ids, int $post_id ) {
		global $wpdb;

		foreach ( $site_ids as $try_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT p.ID, p.post_type
					FROM %i p
					INNER JOIN %i pm ON p.ID = pm.post_id
					WHERE p.ID = %d
					AND pm.meta_key = '_wptsall_virtual_site_id'
					AND pm.meta_value = %s
					AND p.post_status = 'publish'
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$post_id,
					$try_id
				)
			);

			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Find a virtual post by slug from wp_posts + _wptsall_virtual_site_id meta.
	 *
	 * Tries each site ID format in order. Optionally filters by post_type.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $site_ids  List of virtual site ID formats to try.
	 * @param string $post_name Post slug.
	 * @param string $post_type Optional post type filter (empty = any).
	 * @return object|null Row with ID, post_type columns, or null.
	 */
	public static function find_virtual_post_by_slug( array $site_ids, string $post_name, string $post_type = '' ) {
		global $wpdb;

		foreach ( $site_ids as $try_id ) {
			$result = null;

			if ( '' !== $post_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT p.ID, p.post_type
						FROM %i p
						INNER JOIN %i pm ON p.ID = pm.post_id
						WHERE p.post_name = %s
						AND p.post_type = %s
						AND pm.meta_key = '_wptsall_virtual_site_id'
						AND pm.meta_value = %s
						AND p.post_status = 'publish'
						LIMIT 1",
						$wpdb->posts,
						$wpdb->postmeta,
						$post_name,
						$post_type,
						$try_id
					)
				);
			}

			if ( ! $result ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT p.ID, p.post_type
						FROM %i p
						INNER JOIN %i pm ON p.ID = pm.post_id
						WHERE p.post_name = %s
						AND pm.meta_key = '_wptsall_virtual_site_id'
						AND pm.meta_value = %s
						AND p.post_status = 'publish'
						LIMIT 1",
						$wpdb->posts,
						$wpdb->postmeta,
						$post_name,
						$try_id
					)
				);
			}

			if ( $result ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Get virtual post content from wp_posts + postmeta storage.
	 *
	 * Returns post fields and meta for a virtual post identified by
	 * _wptsall_virtual_site_id and _wptsall_source_post_id meta.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $site_ids  List of virtual site ID formats to try.
	 * @param int    $source_id Source post ID.
	 * @param string $subtype   Post type (empty = any).
	 * @return array|null Array with 'post' and 'meta' keys, or null.
	 */
	public static function get_virtual_post_content( array $site_ids, int $source_id, string $subtype = '' ) {
		global $wpdb;

		$virtual_post = null;

		// First pass: with post_type constraint.
		if ( '' !== $subtype ) {
			foreach ( $site_ids as $try_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$virtual_post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT p.ID, p.post_title, p.post_content, p.post_excerpt,
							p.post_name, p.post_status, p.post_type, p.post_date,
							p.post_author, p.menu_order, p.comment_status, p.ping_status
						FROM %i p
						INNER JOIN %i pm1 ON p.ID = pm1.post_id
							AND pm1.meta_key = '_wptsall_virtual_site_id'
						INNER JOIN %i pm2 ON p.ID = pm2.post_id
							AND pm2.meta_key = '_wptsall_source_post_id'
						WHERE pm1.meta_value = %s
						AND pm2.meta_value = %d
						AND p.post_type = %s
						LIMIT 1",
						$wpdb->posts,
						$wpdb->postmeta,
						$wpdb->postmeta,
						$try_id,
						$source_id,
						$subtype
					)
				);

				if ( $virtual_post ) {
					break;
				}
			}
		}

		// Second pass: without post_type constraint.
		if ( ! $virtual_post ) {
			foreach ( $site_ids as $try_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$virtual_post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT p.ID, p.post_title, p.post_content, p.post_excerpt,
							p.post_name, p.post_status, p.post_type, p.post_date,
							p.post_author, p.menu_order, p.comment_status, p.ping_status
						FROM %i p
						INNER JOIN %i pm1 ON p.ID = pm1.post_id
							AND pm1.meta_key = '_wptsall_virtual_site_id'
						INNER JOIN %i pm2 ON p.ID = pm2.post_id
							AND pm2.meta_key = '_wptsall_source_post_id'
						WHERE pm1.meta_value = %s
						AND pm2.meta_value = %d
						LIMIT 1",
						$wpdb->posts,
						$wpdb->postmeta,
						$wpdb->postmeta,
						$try_id,
						$source_id
					)
				);

				if ( $virtual_post ) {
					break;
				}
			}
		}

		if ( ! $virtual_post ) {
			return null;
		}

		$content = array(
			'post' => array(
				'post_title'     => $virtual_post->post_title,
				'post_content'   => $virtual_post->post_content,
				'post_excerpt'   => $virtual_post->post_excerpt,
				'post_name'      => $virtual_post->post_name,
				'post_status'    => $virtual_post->post_status,
				'post_type'      => $virtual_post->post_type,
				'post_date'      => $virtual_post->post_date,
				'post_author'    => $virtual_post->post_author,
				'menu_order'     => $virtual_post->menu_order,
				'comment_status' => $virtual_post->comment_status,
				'ping_status'    => $virtual_post->ping_status,
			),
			'meta' => array(),
		);

		// Fetch post meta (excluding internal markers).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i
				WHERE post_id = %d
				AND meta_key NOT LIKE %s',
				$wpdb->postmeta,
				$virtual_post->ID,
				$wpdb->esc_like( '_wptsall_' ) . '%'
			)
		);

		if ( $meta_rows ) {
			foreach ( $meta_rows as $meta_row ) {
				$content['meta'][ $meta_row->meta_key ] = $meta_row->meta_value;
			}
		}

		return $content;
	}

	/**
	 * Find content by slug in the virtual_site_content table.
	 *
	 * Queries JSON_EXTRACT on the content column for post_name matching.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $site_ids       List of virtual site ID formats to try.
	 * @param int    $source_blog_id Source blog ID.
	 * @param string $post_name      Post slug to match in content JSON.
	 * @param string $subtype        Post type filter (empty = any).
	 * @return object|null Row with source_object_id, subtype, content columns, or null.
	 */
	public static function find_content_by_slug( array $site_ids, int $source_blog_id, string $post_name, string $subtype = '' ) {
		global $wpdb;

		$table = wptsall_table( 'virtual_site_content' );
		// Legacy table may not exist on newer installs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			return null;
		}

		// Use $placeholders name so WPCS recognizes dynamic IN-list tokens.
		$placeholders = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );

		// With subtype constraint.
		if ( '' !== $subtype ) {
			$query_args = array_merge( array( $table ), $site_ids, array( $source_blog_id, $subtype, $post_name ) );
			$row        = wptsall_db_get_row(
				"SELECT source_object_id, subtype, content FROM %i WHERE virtual_site_id IN ($placeholders) AND source_blog_id = %d AND object_type = 'post_type' AND subtype = %s AND JSON_UNQUOTE(JSON_EXTRACT(content, '$.post.post_name')) = %s LIMIT 1",
				$query_args
			);

			if ( $row ) {
				return $row;
			}
		}

		// Without subtype constraint.
		$query_args = array_merge( array( $table ), $site_ids, array( $source_blog_id, $post_name ) );
		return wptsall_db_get_row(
			"SELECT source_object_id, subtype, content FROM %i WHERE virtual_site_id IN ($placeholders) AND source_blog_id = %d AND object_type = 'post_type' AND JSON_UNQUOTE(JSON_EXTRACT(content, '$.post.post_name')) = %s LIMIT 1",
			$query_args
		);
	}

	/**
	 * Normalize site data
	 *
	 * Converts database field names to API field names
	 *
	 * @param array $site Raw database site data
	 * @return array Normalized site data
	 */
	private static function normalize_site_data( $site ) {
		if ( ! is_array( $site ) ) {
			return array();
		}

		return array(
			'id'                   => $site['id'],
			'name'                 => $site['site_name'],
			'subtitle'             => $site['site_tagline'],
			'path_prefix'          => $site['site_path'],
			'lang'                 => $site['site_language'],
			'logo_url'             => $site['site_logo'],
			'enable_blog_sync'     => (int) $site['enable_blog_sync'],
			'blog_source_site'     => isset( $site['source_blog_id'] ) ? (int) $site['source_blog_id'] : 0,
			'permalink_structure'  => isset( $site['permalink_structure'] ) ? $site['permalink_structure'] : '',
			'category_base'        => isset( $site['category_base'] ) ? $site['category_base'] : '',
			'tag_base'             => isset( $site['tag_base'] ) ? $site['tag_base'] : '',
			'status'               => $site['status'],
			'created_at'           => $site['created_at'],
			'updated_at'           => $site['updated_at'],
		);
	}

}
