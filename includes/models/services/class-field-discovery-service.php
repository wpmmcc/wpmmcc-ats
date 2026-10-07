<?php
/**
 * WPTSALL Field Discovery Service
 *
 * Provides utilities for exploring database schema and verifying field configurations.
 *
 * @package WPTSALL
 * @since 0.5.1
  * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers from internal helpers (wptsall_table / \$wpdb->prefix . 'wptsall_*'); user values use prepare placeholders.
 */
namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Field Discovery Service Class
 */
class Field_Discovery_Service {

	/**
	 * Content-table registration is separate from the settings capability.
	 *
	 * Custom integrations register table => column[] through this filter.
	 * Core identity/configuration and internal task tables are never admitted.
	 *
	 * @return array
	 */
	private static function content_tables() {
		global $wpdb;
		return (array) apply_filters(
			'wptsall_discovery_content_tables',
			array(
				$wpdb->posts => array( 'ID', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_type', 'post_status', 'post_date', 'post_date_gmt', 'post_parent', 'menu_order' ),
				$wpdb->postmeta => array( 'meta_id', 'post_id', 'meta_key' ),
				$wpdb->terms => array( 'term_id', 'name', 'slug', 'term_group' ),
				$wpdb->term_taxonomy => array( 'term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count' ),
				$wpdb->term_relationships => array( 'object_id', 'term_taxonomy_id', 'term_order' ),
			)
		);
	}

	private static function allowed_columns( $table ) {
		global $wpdb;
		if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $table )
			|| 0 !== strpos( $table, $wpdb->prefix ) ) {
			return array();
		}
		$blocked = array( $wpdb->users, $wpdb->usermeta, $wpdb->options, $wpdb->sitemeta );
		if ( in_array( $table, $blocked, true )
			|| preg_match( '/^(?:wptsall_|wpmmcc_|users$|usermeta$|options$|sitemeta$|blogs$|blogmeta$|site$|signups$|registration_log$)/i', substr( $table, strlen( $wpdb->prefix ) ) ) ) {
			return array();
		}
		$tables = self::content_tables();
		$columns = $tables[ $table ] ?? array();
		return is_array( $columns ) ? array_values( array_filter( $columns, 'is_string' ) ) : array();
	}

	/**
	 * Get all database tables (filtered by WordPress prefix)
	 *
	 * @return array List of table names.
	 */
	public static function get_tables() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_N );
		$tables  = array_map(
			function ( $row ) {
				return $row[0];
			},
			is_array( $results ) ? $results : array()
		);
		$tables = array_values( array_filter( $tables, function ( $table ) {
			return ! empty( self::allowed_columns( $table ) );
		} ) );

		wptsall_log_debug(
			'models-scanner',
			'Field_Discovery_Service get_tables',
			array( 'tables_count' => count( $tables ) )
		);

		return $tables;
	}

	/**
	 * Get columns for a specific table
	 *
	 * @param string $table Table name.
	 * @return array List of column names and types.
	 */
	public static function get_table_columns( $table ) {
		global $wpdb;
		$allowed = self::allowed_columns( $table );
		if ( empty( $allowed ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$results = array_filter( is_array( $results ) ? $results : array(), function ( $row ) use ( $allowed ) {
			return in_array( $row['Field'], $allowed, true );
		} );
		return array_values( array_map( function( $row ) {
			return array(
				'field' => $row['Field'],
				'type'  => $row['Type'],
			);
		}, $results ) );
	}

	/**
	 * Get unique meta keys from wp_postmeta
	 *
	 * @param string $post_type      Optional. Filter by post type.
	 * @param bool   $include_hidden Optional. Whether to include keys starting with underscore.
	 * @return array List of meta keys.
	 */
	public static function get_post_meta_keys( $post_type = '', $include_hidden = false ) {
		global $wpdb;

		if ( ! empty( $post_type ) ) {
			$post_type = sanitize_key( $post_type );
			if ( $include_hidden ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$results = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT DISTINCT meta_key FROM %i pm
						 INNER JOIN %i p ON p.ID = pm.post_id
						 WHERE p.post_type = %s',
						$wpdb->postmeta,
						$wpdb->posts,
						$post_type
					),
					ARRAY_A
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$results = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT DISTINCT meta_key FROM %i pm
						 INNER JOIN %i p ON p.ID = pm.post_id
						 WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s',
						$wpdb->postmeta,
						$wpdb->posts,
						$post_type,
						'\_%'
					),
					ARRAY_A
				);
			}
		} elseif ( $include_hidden ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT meta_key FROM %i',
					$wpdb->postmeta
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT meta_key FROM %i WHERE meta_key NOT LIKE %s',
					$wpdb->postmeta,
					'\_%'
				),
				ARRAY_A
			);
		}

		$meta_keys = array_column( $results, 'meta_key' );

		wptsall_log_debug(
			'models-scanner',
			'Field_Discovery_Service get_post_meta_keys',
			array(
				'post_type'      => $post_type ?: '(all)',
				'include_hidden' => $include_hidden,
				'meta_keys_count' => count( $meta_keys ),
			)
		);

		return $meta_keys;
	}

	/**
	 * Get distinct values for a specific column in a table (e.g. meta_keys)
	 *
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @param int    $limit Limit results.
	 * @return array List of distinct values.
	 */
	public static function get_distinct_values( $table, $column, $limit = 100 ) {
		global $wpdb;
		if ( ! is_string( $column ) || ! in_array( $column, self::allowed_columns( $table ), true ) ) {
			return array();
		}
		$limit = max( 1, min( 100, (int) $limit ) );

		// Verify column exists in table
		$columns = self::get_table_columns( $table );
		$column_exists = false;
		foreach ( $columns as $c ) {
			if ( $c['field'] === $column ) {
				$column_exists = true;
				break;
			}
		}

		if ( ! $column_exists ) {
			return array();
		}

		// Identifiers via %i (WP 6.2+); limit via prepare placeholder.
		$results = wptsall_db_get_col(
			'SELECT DISTINCT %i FROM %i WHERE %i != %s ORDER BY %i ASC LIMIT %d',
			array( $column, $table, $column, '', $column, $limit )
		);

		return $results;
	}

	/**
	 * Test a field configuration against a sample ID
	 *
	 * @param array $config Field configuration (table, field, associated_id_map).
	 * @param int   $sample_id Sample object ID.
	 * @return mixed The extracted value or error message.
	 */
	public static function test_field_config( $config, $sample_id ) {
		global $wpdb;
		
		$table      = $config['table'] ?? '';
		$field      = $config['field'] ?? '';
		$id_column  = $config['associated_id_map'] ?? ''; 
		$sample_id  = (int) $sample_id;

		if ( empty( $table ) || empty( $field ) || empty( $id_column ) ) {
			return '[Error] Table, Field, and Relationship Field are all required.';
		}
		$allowed = self::allowed_columns( $table );
		if ( empty( $allowed ) || ! in_array( $id_column, $allowed, true ) ) {
			return new \WP_Error( 'rest_forbidden', 'Table or relationship column is not registered content.', array( 'status' => 403 ) );
		}

		// 1. Post Meta (Standard)
		if ( $table === $wpdb->postmeta ) {
			// For postmeta, associated_id_map is usually 'post_id', field is 'meta_key'
			if ( 'post_id' === $id_column ) {
				if ( ! \WPTSALL\Core\Field_Write_Policy::allows_translation( (string) $field, 'post_type', true ) ) {
					return new \WP_Error( 'rest_forbidden', 'Internal metadata cannot be sampled.', array( 'status' => 403 ) );
				}
				$val = get_post_meta( $sample_id, $field, true );
				return $val !== '' ? $val : '[No Data Found]';
			}
		}

		// 2. Custom Table or other meta tables
		if ( ! is_string( $field ) || ! in_array( $field, $allowed, true ) ) {
			return new \WP_Error( 'rest_forbidden', 'Column is not registered content.', array( 'status' => 403 ) );
		}
		$column = $field;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$val = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT %i FROM %i WHERE %i = %d LIMIT 1',
				$column,
				$table,
				$id_column,
				$sample_id
			)
		);

		return $val !== null ? $val : '[No Data Found]';
	}
}
