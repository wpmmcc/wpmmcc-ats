<?php
/**
 * Custom Table Scanner
 *
 * Scans plugins for custom database tables that store content data.
 * Supports plugins that use only custom tables without post_types.
 *
 * @package WPTSALL
 * @subpackage Models\Scanners
 * @since 0.9.1
 */

namespace WPTSALL\Models\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Custom Table Scanner class.
 *
 * Provides functionality to:
 * - Scan plugins for custom database tables
 * - Determine if tables store content vs config/log data
 * - Analyze table fields and classify them
 * - Infer table relationships
 *
 * @since 0.9.1
 */
class Custom_Table_Scanner {

	/**
	 * Keywords indicating content tables.
	 *
	 * @var array
	 */
	protected static $content_keywords = array(
		'booking',
		'appointment',
		'order',
		'item',
		'entry',
		'submission',
		'record',
		'listing',
		'event',
		'course',
		'lesson',
		'quiz',
		'question',
		'answer',
		'review',
		'comment',
		'message',
		'ticket',
		'form',
		'lead',
		'contact',
		'subscriber',
		'member',
		'user',
		'customer',
		'product',
		'service',
		'payment',
		'transaction',
		'invoice',
		'subscription',
	);

	/**
	 * Keywords indicating config/system tables.
	 *
	 * @var array
	 */
	protected static $config_keywords = array(
		'setting',
		'option',
		'config',
		'meta',
		'log',
		'cache',
		'session',
		'temp',
		'migration',
		'schema',
		'version',
		'queue',
		'job',
		'cron',
		'lock',
		'index',
		'stats',
		'analytics',
	);

	/**
	 * Field keywords for translation.
	 *
	 * @var array
	 */
	protected static $translate_field_keywords = array(
		'title',
		'name',
		'label',
		'description',
		'content',
		'text',
		'excerpt',
		'summary',
		'message',
		'body',
		'subject',
		'bio',
		'note',
		'comment',
	);

	/**
	 * Field keywords for ID mapping.
	 *
	 * @var array
	 */
	protected static $id_mapping_keywords = array(
		'_id',
		'_parent',
		'_author',
		'_user',
		'_post',
		'_term',
		'_attachment',
		'_image',
		'_thumbnail',
		'_media',
		'_category',
		'_product',
		'_order',
		'_customer',
	);

	/**
	 * Field keywords for compute fields.
	 *
	 * @var array
	 */
	protected static $compute_field_keywords = array(
		'slug',
		'permalink',
		'url',
		'guid',
		'hash',
		'count',
		'total',
		'average',
	);

	/**
	 * Find database tables that belong to a plugin.
	 *
	 * Enhanced version that detects tables using multiple prefix patterns:
	 * - Standard: wp_{plugin_slug}_
	 * - Abbreviated: wp_{abbrev}_ (e.g., wp_wc_ for woocommerce)
	 * - Alternative: wp_{plugin_name}_ with variations
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Array of table data with name, row_count, columns, is_content.
	 */
	public static function find_plugin_tables( $plugin_slug ) {
		global $wpdb;

		$result = array();

		// Generate possible table prefixes for this plugin.
		$prefixes = self::generate_table_prefixes( $plugin_slug );

		$all_tables = array();
		foreach ( $prefixes as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$tables = $wpdb->get_col(
				$wpdb->prepare(
					"SHOW TABLES LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);

			if ( ! empty( $tables ) ) {
				$all_tables = array_merge( $all_tables, $tables );
			}
		}

		// Remove duplicates.
		$all_tables = array_unique( $all_tables );

		// Filter out WPTSALL's own tables.
		$all_tables = array_filter(
			$all_tables,
			function ( $table ) use ( $wpdb ) {
				return strpos( $table, $wpdb->prefix . 'wptsall_' ) !== 0;
			}
		);

		foreach ( $all_tables as $table ) {
			$table_data = self::analyze_table( $table );
			if ( $table_data ) {
				$result[] = $table_data;
			}
		}

		return $result;
	}

	/**
	 * Generate possible table prefixes for a plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Array of possible prefixes.
	 */
	protected static function generate_table_prefixes( $plugin_slug ) {
		global $wpdb;

		$prefixes = array();

		// Standard prefix: wp_{slug}_
		$slug_normalized = str_replace( '-', '_', $plugin_slug );
		$prefixes[]      = $wpdb->prefix . $slug_normalized . '_';
		$prefixes[]      = $wpdb->prefix . $slug_normalized;

		// Without underscores: wp_{pluginslug}
		$slug_no_sep = str_replace( array( '-', '_' ), '', $plugin_slug );
		$prefixes[]  = $wpdb->prefix . $slug_no_sep . '_';
		$prefixes[]  = $wpdb->prefix . $slug_no_sep;

		// Abbreviation prefix: wp_{abbrev}_
		$words = preg_split( '/[-_]/', $plugin_slug );
		if ( count( $words ) > 1 ) {
			$abbrev = '';
			foreach ( $words as $word ) {
				if ( ! empty( $word ) ) {
					$abbrev .= $word[0];
				}
			}
			if ( strlen( $abbrev ) >= 2 && strlen( $abbrev ) <= 4 ) {
				$prefixes[] = $wpdb->prefix . $abbrev . '_';
			}
		}

		// Common variations.
		// Some plugins use first word only (e.g., amelia for ameliabooking).
		if ( count( $words ) > 0 ) {
			$first_word = $words[0];
			if ( strlen( $first_word ) >= 3 ) {
				$prefixes[] = $wpdb->prefix . $first_word . '_';
			}
		}

		return array_unique( $prefixes );
	}

	/**
	 * Analyze a single table.
	 *
	 * @param string $table_name Table name.
	 * @return array|null Table analysis data or null on error.
	 */
	protected static function analyze_table( $table_name ) {
		global $wpdb;

		$row_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );

		// Get columns.
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ), ARRAY_A );

		if ( false === $columns ) {
			return null;
		}

		$column_data = array();
		foreach ( $columns as $col ) {
			$column_data[] = array(
				'name'    => $col['Field'],
				'type'    => $col['Type'],
				'null'    => $col['Null'],
				'key'     => $col['Key'],
				'default' => $col['Default'],
				'extra'   => $col['Extra'],
			);
		}

		// Determine if content table.
		$is_content = self::is_content_table( $table_name, $column_data );

		// Analyze fields if content table (with optional data sampling).
		$field_analysis = array();
		if ( $is_content ) {
			$field_analysis = self::analyze_table_fields( $column_data, $table_name );
		}

		return array(
			'name'           => $table_name,
			'row_count'      => $row_count,
			'columns'        => $column_data,
			'is_content'     => $is_content,
			'field_analysis' => $field_analysis,
		);
	}

	/**
	 * Determine if a table stores content data vs config/system data.
	 *
	 * @param string $table_name  Table name.
	 * @param array  $columns     Column data.
	 * @return bool True if content table.
	 */
	public static function is_content_table( $table_name, $columns = array() ) {
		global $wpdb;

		// Remove prefix for analysis.
		$table_short = str_replace( $wpdb->prefix, '', $table_name );
		$table_lower = strtolower( $table_short );

		// Check for config keywords (negative indicators).
		foreach ( self::$config_keywords as $keyword ) {
			if ( strpos( $table_lower, $keyword ) !== false ) {
				// Exception: *_meta tables can be content-related.
				if ( 'meta' === $keyword ) {
					// Check if it's a postmeta-like table.
					if ( preg_match( '/^[a-z]+_meta$/', $table_lower ) ) {
						return true;
					}
				}
				return false;
			}
		}

		// Check for content keywords (positive indicators).
		foreach ( self::$content_keywords as $keyword ) {
			if ( strpos( $table_lower, $keyword ) !== false ) {
				return true;
			}
		}

		// Heuristic: tables with certain column patterns are likely content tables.
		if ( ! empty( $columns ) ) {
			$column_names = array_column( $columns, 'name' );
			$column_names_lower = array_map( 'strtolower', $column_names );

			// Content tables typically have:
			// - An ID column.
			// - A created_at/date column.
			// - A title/name/description column.
			$has_id    = ! empty( array_filter( $column_names_lower, fn( $c ) => $c === 'id' || str_ends_with( $c, '_id' ) ) );
			$has_date  = ! empty( array_filter( $column_names_lower, fn( $c ) => strpos( $c, 'date' ) !== false || strpos( $c, 'created' ) !== false || strpos( $c, 'time' ) !== false ) );
			$has_title = ! empty( array_filter( $column_names_lower, fn( $c ) => strpos( $c, 'title' ) !== false || strpos( $c, 'name' ) !== false ) );

			if ( $has_id && ( $has_date || $has_title ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Analyze table fields and classify them.
	 *
	 * When a table name is provided, performs data sampling to refine
	 * schema-based classifications with actual value detection.
	 *
	 * @param array       $columns    Column data.
	 * @param string|null $table_name Optional table name for data sampling.
	 * @return array Field classification results.
	 */
	public static function analyze_table_fields( $columns, $table_name = null ) {
		$analysis = array(
			'translate_fields' => array(),
			'sync_fields'      => array(),
			'field_mappings'   => array(),
			'compute_fields'   => array(),
		);

		foreach ( $columns as $col ) {
			$field_name  = strtolower( $col['name'] );
			$field_type  = strtolower( $col['type'] );
			$is_primary  = 'PRI' === $col['key'];
			$is_auto_inc = strpos( $col['extra'] ?? '', 'auto_increment' ) !== false;

			// Skip primary auto-increment ID.
			if ( $is_primary && $is_auto_inc ) {
				continue;
			}

			// Check for translate fields.
			$is_text_type = preg_match( '/^(varchar|text|mediumtext|longtext|char)/i', $field_type );
			if ( $is_text_type ) {
				foreach ( self::$translate_field_keywords as $keyword ) {
					if ( strpos( $field_name, $keyword ) !== false ) {
						$analysis['translate_fields'][] = $col['name'];
						break;
					}
				}
			}

			// Check for ID mapping fields.
			foreach ( self::$id_mapping_keywords as $keyword ) {
				if ( str_ends_with( $field_name, $keyword ) || strpos( $field_name, $keyword . '_' ) !== false ) {
					$analysis['field_mappings'][] = $col['name'];
					break;
				}
			}

			// Check for compute fields.
			foreach ( self::$compute_field_keywords as $keyword ) {
				if ( strpos( $field_name, $keyword ) !== false ) {
					$analysis['compute_fields'][] = $col['name'];
					break;
				}
			}

			// Remaining text fields are sync fields.
			if ( $is_text_type && ! in_array( $col['name'], $analysis['translate_fields'], true ) && ! in_array( $col['name'], $analysis['field_mappings'], true ) && ! in_array( $col['name'], $analysis['compute_fields'], true ) ) {
				$analysis['sync_fields'][] = $col['name'];
			}

			// Numeric fields (except IDs) are sync fields.
			if ( preg_match( '/^(int|bigint|tinyint|smallint|decimal|float|double)/i', $field_type ) ) {
				if ( ! in_array( $col['name'], $analysis['field_mappings'], true ) ) {
					$analysis['sync_fields'][] = $col['name'];
				}
			}

			// Date fields are sync fields.
			if ( preg_match( '/^(date|datetime|timestamp)/i', $field_type ) ) {
				$analysis['sync_fields'][] = $col['name'];
			}
		}

		// Remove duplicates.
		$analysis['translate_fields'] = array_unique( $analysis['translate_fields'] );
		$analysis['sync_fields']      = array_unique( $analysis['sync_fields'] );
		$analysis['field_mappings']   = array_unique( $analysis['field_mappings'] );
		$analysis['compute_fields']   = array_unique( $analysis['compute_fields'] );

		// Refine classification with data sampling when table name is available.
		if ( $table_name ) {
			$sample_results = self::sample_table_data( $table_name, $columns );
			if ( ! empty( $sample_results ) ) {
				$analysis['sample_refinements'] = $sample_results;

				// Reclassify fields based on sample detection.
				foreach ( $sample_results as $col_name => $detected_format ) {
					// If detected as JSON/serialized/HTML, promote to translate_fields
					// if not already there.
					if ( in_array( $detected_format, array( 'json', 'serialized', 'html' ), true ) ) {
						if ( ! in_array( $col_name, $analysis['translate_fields'], true ) ) {
							$analysis['translate_fields'][] = $col_name;
							// Remove from sync_fields if present.
							$analysis['sync_fields'] = array_values(
								array_diff( $analysis['sync_fields'], array( $col_name ) )
							);
						}
					}

					// If detected as id_ref, promote to field_mappings if not already there.
					if ( 'id_ref' === $detected_format ) {
						if ( ! in_array( $col_name, $analysis['field_mappings'], true ) ) {
							$analysis['field_mappings'][] = $col_name;
							// Remove from sync_fields if present.
							$analysis['sync_fields'] = array_values(
								array_diff( $analysis['sync_fields'], array( $col_name ) )
							);
						}
					}
				}
			}
		}

		return $analysis;
	}

	/**
	 * Sample actual data from a table to refine field classifications.
	 *
	 * Queries one row of real data and inspects each text/numeric column
	 * to detect the actual value format (JSON, serialized, HTML, or ID reference).
	 * Only returns columns where sampling detected a more specific type than
	 * what the schema alone provides.
	 *
	 * @param string $table_name Full table name (with prefix).
	 * @param array  $columns    Column data from SHOW COLUMNS.
	 * @return array Keyed by column name => detected format string.
	 */
	public static function sample_table_data( $table_name, $columns ) {
		global $wpdb;

		$refinements = array();

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', $table_name ), ARRAY_A );

		if ( empty( $row ) ) {
			return $refinements;
		}

		// Build a set of text/numeric columns from schema for targeted inspection.
		$text_columns    = array();
		$numeric_columns = array();
		foreach ( $columns as $col ) {
			$col_name = $col['name'];
			$col_type = strtolower( $col['type'] );

			if ( preg_match( '/^(varchar|text|mediumtext|longtext|char|tinytext)/i', $col_type ) ) {
				$text_columns[] = $col_name;
			} elseif ( preg_match( '/^(int|bigint|tinyint|smallint|mediumint)/i', $col_type ) ) {
				$numeric_columns[] = $col_name;
			}
		}

		// Inspect text columns for JSON, serialized, or HTML content.
		foreach ( $text_columns as $col_name ) {
			if ( ! isset( $row[ $col_name ] ) || '' === $row[ $col_name ] || null === $row[ $col_name ] ) {
				continue;
			}

			$value = $row[ $col_name ];

			// JSON detection: starts with { or [ and decodes successfully.
			if ( ( '{' === $value[0] || '[' === $value[0] ) ) {
				$decoded = json_decode( $value, true );
				if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
					$refinements[ $col_name ] = 'json';
					continue;
				}
			}

			// Serialized detection.
			if ( is_serialized( $value ) ) {
				$refinements[ $col_name ] = 'serialized';
				continue;
			}

			// HTML detection: strip_tags differs from original and content is substantial.
			if ( strlen( $value ) > 100 && wp_strip_all_tags( $value ) !== $value ) {
				$refinements[ $col_name ] = 'html';
				continue;
			}
		}

		// Inspect numeric columns for ID references.
		foreach ( $numeric_columns as $col_name ) {
			if ( ! isset( $row[ $col_name ] ) || '' === $row[ $col_name ] || null === $row[ $col_name ] ) {
				continue;
			}

			$value = $row[ $col_name ];

			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$post = get_post( (int) $value );
				if ( $post ) {
					$refinements[ $col_name ] = 'id_ref';
				}
			}
		}

		return $refinements;
	}

}
