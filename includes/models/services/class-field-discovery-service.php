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
			$results
		);

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
		$table = sanitize_key( $table );
		
		// Verify table belongs to this WP instance for safety
		if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		return array_map( function( $row ) {
			return array(
				'field' => $row['Field'],
				'type'  => $row['Type'],
			);
		}, $results );
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
		$table  = sanitize_key( $table );
		$column = sanitize_key( $column );

		// Verify table belongs to this WP instance
		if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
			return array();
		}

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

		// 1. Post Meta (Standard)
		if ( $table === $wpdb->postmeta ) {
			// For postmeta, associated_id_map is usually 'post_id', field is 'meta_key'
			if ( 'post_id' === $id_column ) {
				$val = get_post_meta( $sample_id, $field, true );
				return $val !== '' ? $val : '[No Data Found]';
			}
		}

		// 2. Custom Table or other meta tables
		$table = sanitize_key( $table );
		if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
			return '[Error] Invalid table prefix. Only WordPress tables are allowed.';
		}

		$column    = sanitize_key( $field );
		$id_column = sanitize_key( $id_column );

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
