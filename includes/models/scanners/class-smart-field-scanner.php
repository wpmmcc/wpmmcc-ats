<?php
/**
 * Smart Field Scanner V4
 *
 * Intelligent field discovery for WordPress plugins using multi-method collaboration.
 * Migrated from dev-tools/scanning/v4-smart-scanner/core/smart-field-scanner.php
 *
 * @package WPTSALL
 * @subpackage Models\Scanners
 * @since 0.9.0
 */

namespace WPTSALL\Models\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Smart_Field_Scanner
 *
 * V4 implementation with Golden Record strategy and deep inspection.
 */
class Smart_Field_Scanner {

	/**
	 * Scanner version
	 *
	 * @var string
	 */
	const VERSION = 'v4.3';

	/**
	 * Plugin slug being scanned
	 *
	 * @var string
	 */
	protected $plugin_slug;

	/**
	 * Post types to scan
	 *
	 * @var array
	 */
	protected $post_types;

	/**
	 * Enable URL verification (default: false for performance)
	 *
	 * @var bool
	 */
	protected $enable_url_verification = false;

	/**
	 * Constructor
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param array  $post_types  Post types to scan.
	 */
	public function __construct( $plugin_slug, $post_types = array() ) {
		$this->plugin_slug = $plugin_slug;
		$this->post_types  = is_array( $post_types ) ? $post_types : array( $post_types );
	}

	/**
	 * Main entry point for scanning
	 *
	 * @return array Scan results with object_metadata, url_info, and fields.
	 */
	public function scan() {
		$results = array();

		foreach ( $this->post_types as $post_type ) {
			$type = $this->identify_plugin_type( $post_type );

			// Get prioritized method list.
			$methods           = $this->select_methods_with_priority( $type );
			$post_type_results = array();
			$field_coverage    = 0;

			// Execute methods by priority.
			foreach ( $methods as $method_info ) {
				$method   = $method_info['name'];
				$priority = $method_info['priority'];

				// Check if method can be skipped.
				if ( $this->can_skip_method( $method, $post_type_results, $priority, $field_coverage ) ) {
					continue;
				}

				if ( method_exists( $this, $method ) ) {
					$post_type_results[ $method ] = $this->$method( $post_type );
				}

				// Calculate current coverage.
				$field_coverage = $this->calculate_field_coverage( $post_type_results );
			}

			// Cross-validate and merge results.
			$fields = $this->cross_validate( $post_type_results );

			// Detect URLs and metadata.
			$url_info = $this->detect_urls_comprehensive( $post_type );
			$metadata = $this->get_object_metadata( $post_type );

			// Scan taxonomy usage for this post type.
			$taxonomy_usage = $this->scan_taxonomy_usage( $post_type );

			// Combine into three-layer structure.
			$results[ $post_type ] = array(
				'object_metadata' => $metadata,
				'url_info'        => $url_info,
				'fields'          => $fields,
				'taxonomy_usage'  => $taxonomy_usage,
			);
		}

		return $results;
	}

	/**
	 * Get scanner version
	 *
	 * @return string
	 */
	public static function get_version() {
		return self::VERSION;
	}

	/**
	 * Identify plugin type to optimize scanning strategy
	 *
	 * @param string $post_type Post type name.
	 * @return string Plugin type: modern_rest|custom_table|traditional.
	 */
	private function identify_plugin_type( $post_type ) {
		$pt_obj = get_post_type_object( $post_type );

		// Check REST API support.
		if ( $pt_obj && $pt_obj->show_in_rest ) {
			return 'modern_rest';
		}

		// Check for custom tables.
		if ( $this->has_custom_tables() ) {
			return 'custom_table';
		}

		return 'traditional';
	}

	/**
	 * Check for custom tables associated with the plugin
	 *
	 * @return bool
	 */
	private function has_custom_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_tables = $wpdb->get_col(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		// WordPress core tables to exclude.
		$core_tables = array(
			'posts',
			'postmeta',
			'users',
			'usermeta',
			'terms',
			'termmeta',
			'term_relationships',
			'term_taxonomy',
			'comments',
			'commentmeta',
			'links',
			'options',
		);

		// Generate possible prefixes from plugin slug.
		$slug_underscore   = str_replace( '-', '_', strtolower( $this->plugin_slug ) );
		$possible_prefixes = array( $slug_underscore );

		// Add acronym.
		$slug_parts = explode( '_', $slug_underscore );
		if ( count( $slug_parts ) > 1 ) {
			$acronym = '';
			foreach ( $slug_parts as $part ) {
				if ( ! empty( $part ) ) {
					$acronym .= $part[0];
				}
			}
			if ( strlen( $acronym ) >= 2 ) {
				$possible_prefixes[] = $acronym;
			}
		}

		// Check for tables matching any prefix.
		foreach ( $all_tables as $table ) {
			$table_name = str_replace( $wpdb->prefix, '', $table );

			if ( in_array( $table_name, $core_tables, true ) ) {
				continue;
			}

			if ( strpos( $table_name, 'wptsall_' ) === 0 ) {
				continue;
			}

			foreach ( $possible_prefixes as $prefix ) {
				if ( strpos( $table_name, $prefix ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Select scanning methods with priority
	 *
	 * @param string $type Plugin type.
	 * @return array Array of method configs with priority.
	 */
	private function select_methods_with_priority( $type ) {
		$methods = array();

		if ( 'modern_rest' === $type ) {
			$methods[] = array(
				'name'     => 'scan_rest_api',
				'priority' => 0,
			);
			$methods[] = array(
				'name'     => 'scan_database',
				'priority' => 1,
			);
			$methods[] = array(
				'name'     => 'scan_registered_meta',
				'priority' => 2,
			);
		} elseif ( 'custom_table' === $type ) {
			$methods[] = array(
				'name'     => 'scan_custom_tables',
				'priority' => 0,
			);
			$methods[] = array(
				'name'     => 'scan_database',
				'priority' => 1,
			);
			$methods[] = array(
				'name'     => 'scan_registered_meta',
				'priority' => 2,
			);
		} else {
			$methods[] = array(
				'name'     => 'scan_database',
				'priority' => 1,
			);
			$methods[] = array(
				'name'     => 'scan_registered_meta',
				'priority' => 2,
			);
		}

		return $methods;
	}

	/**
	 * Calculate field coverage percentage
	 *
	 * @param array $results Scanning results from multiple methods.
	 * @return int Coverage percentage (0-100).
	 */
	private function calculate_field_coverage( $results ) {
		if ( empty( $results ) ) {
			return 0;
		}

		$all_fields = array();
		foreach ( $results as $method => $fields ) {
			if ( is_array( $fields ) ) {
				$all_fields = array_merge( $all_fields, array_keys( $fields ) );
			}
		}

		$count = count( array_unique( $all_fields ) );

		if ( $count >= 30 ) {
			return 95;
		}
		if ( $count >= 20 ) {
			return 80;
		}
		if ( $count >= 10 ) {
			return 60;
		}
		if ( $count >= 5 ) {
			return 40;
		}

		return 20;
	}

	/**
	 * Check if a method can be skipped based on coverage
	 *
	 * @param string $method   Method name.
	 * @param array  $results  Current results.
	 * @param int    $priority Method priority (0-3).
	 * @param int    $coverage Current coverage percentage.
	 * @return bool True if can skip.
	 */
	private function can_skip_method( $method, $results, $priority, $coverage ) {
		// P1 methods (scan_database) never skip.
		if ( 1 === $priority || 'scan_database' === $method ) {
			return false;
		}

		// P3 methods skip if coverage >= 80%.
		if ( 3 === $priority && $coverage >= 80 ) {
			return true;
		}

		// P2 methods skip if coverage >= 95%.
		if ( 2 === $priority && $coverage >= 95 ) {
			return true;
		}

		return false;
	}

	// ==========================================
	// Scanning Methods
	// ==========================================

	/**
	 * Scan REST API for field schema
	 *
	 * @param string $post_type Post type name.
	 * @return array Fields from REST API.
	 */
	private function scan_rest_api( $post_type ) {
		$pt_obj = get_post_type_object( $post_type );

		if ( ! $pt_obj || ! $pt_obj->show_in_rest ) {
			return array();
		}

		$rest_base      = $pt_obj->rest_base ? $pt_obj->rest_base : $post_type;
		$rest_namespace = isset( $pt_obj->rest_namespace ) ? $pt_obj->rest_namespace : 'wp/v2';
		$endpoint       = "/{$rest_namespace}/{$rest_base}";

		$request  = new \WP_REST_Request( 'OPTIONS', $endpoint );
		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$data       = $response->get_data();
		$properties = $data['schema']['properties'] ?? array();

		$fields = array();
		foreach ( $properties as $field_name => $schema ) {
			$fields[ $field_name ] = array(
				'type'   => $this->map_api_type( $schema['type'] ?? 'string', $field_name ),
				'source' => 'rest_api',
			);
		}

		return $fields;
	}

	/**
	 * Scan registered meta keys
	 *
	 * @param string $post_type Post type name.
	 * @return array Fields from registered meta.
	 */
	private function scan_registered_meta( $post_type ) {
		$registered = get_registered_meta_keys( 'post', $post_type );
		$fields     = array();

		foreach ( $registered as $key => $args ) {
			$fields[ $key ] = array(
				'type'   => $this->map_meta_type( $args['type'] ?? 'string', $key ),
				'source' => 'registered_meta',
			);
		}

		return $fields;
	}

	/**
	 * Scan database using Golden Record strategy
	 *
	 * Uses Top-N (N=5) Golden Records: selects the 5 published posts with
	 * the most meta keys, then merges their meta (union of keys, keeping
	 * the longest non-empty value per key) for deeper field discovery.
	 *
	 * @param string $post_type Post type name.
	 * @return array Fields from database.
	 */
	private function scan_database( $post_type ) {
		global $wpdb;

		$fields = array();

		// Scan wp_posts table columns.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts_columns = $wpdb->get_results(
			$wpdb->prepare( 'DESCRIBE %i', $wpdb->posts )
		);

		foreach ( $posts_columns as $col ) {
			$field_name            = $col->Field;
			$type_info             = $this->map_posts_column_type( $field_name, $col->Type );
			$fields[ $field_name ] = array(
				'type'   => $type_info,
				'source' => 'wp_posts_column',
				'sample' => '',
			);
		}

		// Golden Record Strategy: Find top 5 published posts with most meta keys.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$golden_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT post_id
				FROM %i pm
				INNER JOIN %i p ON pm.post_id = p.ID
				WHERE p.post_type = %s AND p.post_status = \'publish\'
				GROUP BY post_id
				ORDER BY COUNT(meta_key) DESC
				LIMIT 5',
				$wpdb->postmeta,
				$wpdb->posts,
				$post_type
			)
		);

		if ( empty( $golden_ids ) ) {
			return $fields;
		}

		// Merge meta from all Golden Records: union of keys, keep longest value per key.
		$merged_meta = array();
		foreach ( $golden_ids as $gid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT meta_key, meta_value FROM %i WHERE post_id = %d',
					$wpdb->postmeta,
					$gid
				)
			);

			foreach ( $meta_rows as $row ) {
				$key   = $row->meta_key;
				$value = $row->meta_value;
				if ( ! isset( $merged_meta[ $key ] ) || strlen( (string) $value ) > strlen( (string) $merged_meta[ $key ] ) ) {
					$merged_meta[ $key ] = $value;
				}
			}
		}

		// Process merged meta from Golden Records.
		foreach ( $merged_meta as $key => $value ) {
			$inferred_type  = $this->inspect_value( $value, $key );
			$fields[ $key ] = array(
				'type'   => $inferred_type,
				'source' => 'database_deep_scan',
				'sample' => is_scalar( $value ) ? substr( (string) $value, 0, 50 ) : 'complex_data',
			);
		}

		// Supplementary scan: Get all distinct meta_keys for published posts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT pm.meta_key
				FROM %i pm
				INNER JOIN %i p ON pm.post_id = p.ID
				WHERE p.post_type = %s AND p.post_status = \'publish\'
				LIMIT 100',
				$wpdb->postmeta,
				$wpdb->posts,
				$post_type
			)
		);

		foreach ( $all_meta_keys as $meta_key ) {
			if ( isset( $fields[ $meta_key ] ) ) {
				continue;
			}

			$inferred_type        = $this->infer_type_from_field_name( $meta_key );
			$fields[ $meta_key ]  = array(
				'type'   => $inferred_type,
				'source' => 'database_distinct_keys',
				'sample' => '',
			);
		}

		return $fields;
	}

	/**
	 * Scan custom tables
	 *
	 * @param string $post_type Post type name.
	 * @return array Fields from custom tables.
	 */
	private function scan_custom_tables( $post_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_tables = $wpdb->get_col(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		$core_tables = array(
			'posts',
			'postmeta',
			'users',
			'usermeta',
			'terms',
			'termmeta',
			'term_relationships',
			'term_taxonomy',
			'comments',
			'commentmeta',
			'links',
			'options',
		);

		$slug_underscore   = str_replace( '-', '_', strtolower( $this->plugin_slug ) );
		$possible_prefixes = array( $slug_underscore );

		$slug_parts = explode( '_', $slug_underscore );
		if ( count( $slug_parts ) > 1 ) {
			$acronym = '';
			foreach ( $slug_parts as $part ) {
				if ( ! empty( $part ) ) {
					$acronym .= $part[0];
				}
			}
			if ( strlen( $acronym ) >= 2 ) {
				$possible_prefixes[] = $acronym;
			}
		}

		$fields = array();

		foreach ( $all_tables as $table ) {
			$table_name = str_replace( $wpdb->prefix, '', $table );

			if ( in_array( $table_name, $core_tables, true ) ) {
				continue;
			}

			if ( strpos( $table_name, 'wptsall_' ) === 0 ) {
				continue;
			}

			$matches = false;
			foreach ( $possible_prefixes as $prefix ) {
				if ( strpos( $table_name, $prefix ) === 0 ) {
					$matches = true;
					break;
				}
			}

			if ( ! $matches ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$columns = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table ) );

			foreach ( $columns as $col ) {
				$fields[ $col->Field ] = array(
					'type'   => $this->map_db_type( $col->Type, $col->Field ),
					'source' => 'custom_table',
					'table'  => $table,
				);
			}
		}

		return $fields;
	}

	// ==========================================
	// Deep Inspection Logic
	// ==========================================

	/**
	 * Inspect value to infer type
	 *
	 * @param mixed  $value Meta value.
	 * @param string $key   Meta key.
	 * @return array Type information.
	 */
	private function inspect_value( $value, $key ) {
		$info = array(
			'storage'   => 'text',
			'semantic'  => 'text',
			'structure' => 'single',
			'sync'      => 'copy',
		);

		// Serialized/JSON data.
		if ( is_serialized( $value ) || ( is_string( $value ) && is_array( json_decode( $value, true ) ) && JSON_ERROR_NONE === json_last_error() ) ) {
			$info['storage']   = is_serialized( $value ) ? 'serialized' : 'json';
			$info['structure'] = 'complex';

			$data = is_serialized( $value ) ? maybe_unserialize( $value ) : json_decode( $value, true );

			// Relation list check.
			if ( is_array( $data ) && ! empty( $data ) && is_numeric( reset( $data ) ) ) {
				$first_id    = reset( $data );
				$target_type = get_post_type( $first_id );
				if ( $target_type ) {
					$info['semantic']        = 'relation_list';
					$info['relation_target'] = $target_type;
					$info['sync']            = 'translate_id_list';
					return $info;
				}
			}

			$info['semantic'] = 'complex_structure';
			return $info;
		}

		// Numeric values.
		if ( is_numeric( $value ) ) {
			$info['storage'] = 'number';

			if ( $value > 0 && (int) $value == $value ) {
				$int_val = (int) $value;

				// Check if it's an image.
				if ( strpos( $key, 'thumbnail' ) !== false || strpos( $key, 'image' ) !== false ) {
					if ( wp_attachment_is_image( $int_val ) ) {
						$info['semantic']        = 'image';
						$info['relation_target'] = 'attachment';
						$info['sync']            = 'translate_id';
						return $info;
					}
				}

				// Check if it's a post ID.
				$target_type = get_post_type( $int_val );
				if ( $target_type ) {
					$info['semantic']        = 'attachment' === $target_type ? 'media' : 'relation';
					$info['relation_target'] = $target_type;
					$info['sync']            = 'translate_id';
					return $info;
				}

				// Check if it's a term ID.
				$common_taxonomies = get_taxonomies( array( 'public' => true ) );
				foreach ( $common_taxonomies as $tax ) {
					$term = get_term( $int_val, $tax );
					if ( $term && ! is_wp_error( $term ) ) {
						$info['semantic']        = 'relation_term';
						$info['relation_target'] = $tax;
						$info['sync']            = 'translate_term_id';
						return $info;
					}
				}
			}

			$info['semantic'] = 'number';

			// Fallback: infer relation type from field name (e.g. _thumbnail_id).
			$by_name = $this->infer_type_from_field_name( $key );
			if ( isset( $by_name['sync'] ) && in_array( $by_name['sync'], array( 'translate_id', 'translate_id_list' ), true ) ) {
				return $by_name;
			}
			return $info;
		}

		// String values.
		if ( is_string( $value ) ) {
			if ( wp_strip_all_tags( $value ) !== $value ) {
				$info['semantic'] = 'html';
				$info['sync']     = 'translate_content';
				return $info;
			}
			if ( filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
				$info['semantic'] = 'email';
				return $info;
			}
			if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$info['semantic'] = 'url';
				return $info;
			}
		}

		return $info;
	}

	/**
	 * Infer type from field name only
	 *
	 * @param string $key Field name.
	 * @return array Type information.
	 */
	private function infer_type_from_field_name( $key ) {
		// ID references.
		if ( preg_match( '/_id$|_thumbnail|_image|_attachment|_parent|_author/', $key ) ) {
			return array(
				'storage'   => 'number',
				'semantic'  => 'relation',
				'structure' => 'single',
				'sync'      => 'translate_id',
			);
		}

		// Lists of IDs.
		if ( preg_match( '/_ids$|_gallery|_images|_attachments/', $key ) ) {
			return array(
				'storage'   => 'serialized',
				'semantic'  => 'relation_list',
				'structure' => 'complex',
				'sync'      => 'translate_id_list',
			);
		}

		// WordPress core edit meta.
		if ( preg_match( '/^_edit_/', $key ) ) {
			return array(
				'storage'   => 'text',
				'semantic'  => 'text',
				'structure' => 'single',
				'sync'      => 'skip',
			);
		}

		// SEO fields (translatable).
		if ( preg_match( '/seo.*title|seo.*desc|meta.*desc|focus.*keyword/i', $key ) ) {
			return array(
				'storage'   => 'text',
				'semantic'  => 'text',
				'structure' => 'single',
				'sync'      => 'translate_content',
			);
		}

		// Price/numeric fields.
		if ( preg_match( '/_price|_cost|_amount|_count|_stock|_quantity/', $key ) ) {
			return array(
				'storage'   => 'number',
				'semantic'  => 'number',
				'structure' => 'single',
				'sync'      => 'copy',
			);
		}

		// Date/time fields.
		if ( preg_match( '/_date|_time|_timestamp|_expire/', $key ) ) {
			return array(
				'storage'   => 'datetime',
				'semantic'  => 'datetime',
				'structure' => 'single',
				'sync'      => 'copy',
			);
		}

		// Default: text field.
		return array(
			'storage'   => 'text',
			'semantic'  => 'text',
			'structure' => 'single',
			'sync'      => 'copy',
		);
	}

	// ==========================================
	// Type Mapping Helpers
	// ==========================================

	/**
	 * Map wp_posts column to semantic type
	 *
	 * @param string $field_name Column name.
	 * @param string $db_type    Database type.
	 * @return array Type information.
	 */
	private function map_posts_column_type( $field_name, $db_type ) {
		// Map from canonical five-level type to scanner sync strategy.
		static $type_to_sync = array(
			'translate'  => 'translate_content',
			'sync'       => 'copy',
			'id_mapping' => 'translate_id',
			'compute'    => 'compute',
			'skip'       => 'skip',
		);

		// Use canonical core_post_fields from Smart_Field_Classifier (H1).
		$core_fields = \WPTSALL\Core\Smart_Field_Classifier::$core_post_fields;
		if ( isset( $core_fields[ $field_name ] ) ) {
			$canonical_type = $core_fields[ $field_name ];
			$sync_action    = $type_to_sync[ $canonical_type ] ?? 'copy';

			// Determine storage/semantic from db type and field name.
			$storage  = 'text';
			$semantic = 'text';
			if ( preg_match( '/_date|_modified|_gmt/', $field_name ) ) {
				$storage  = 'datetime';
				$semantic = 'datetime';
			} elseif ( 'post_content' === $field_name ) {
				$semantic = 'html';
			} elseif ( stripos( $db_type, 'int' ) !== false || in_array( $field_name, array( 'post_author', 'post_parent', 'comment_count' ), true ) ) {
				$storage  = 'number';
				$semantic = 'number';
			}

			return array(
				'storage'   => $storage,
				'semantic'  => $semantic,
				'structure' => 'single',
				'sync'      => $sync_action,
			);
		}

		// ID column.
		if ( 'ID' === $field_name ) {
			return array(
				'storage'   => 'number',
				'semantic'  => 'number',
				'structure' => 'single',
				'sync'      => 'skip',
			);
		}

		// Date/time fields.
		if ( preg_match( '/_date|_modified|_gmt/', $field_name ) ) {
			return array(
				'storage'   => 'datetime',
				'semantic'  => 'datetime',
				'structure' => 'single',
				'sync'      => 'copy',
			);
		}

		// Numeric fields.
		if ( preg_match( '/count|order/', $field_name ) || stripos( $db_type, 'int' ) !== false ) {
			return array(
				'storage'   => 'number',
				'semantic'  => 'number',
				'structure' => 'single',
				'sync'      => 'copy',
			);
		}

		// Default.
		return array(
			'storage'   => 'text',
			'semantic'  => 'text',
			'structure' => 'single',
			'sync'      => 'copy',
		);
	}

	/**
	 * Map API type to internal type
	 *
	 * @param string $api_type   API type.
	 * @param string $field_name Field name.
	 * @return string Type.
	 */
	private function map_api_type( $api_type, $field_name ) {
		if ( 'integer' === $api_type ) {
			return 'number';
		}
		if ( 'boolean' === $api_type ) {
			return 'boolean';
		}
		if ( preg_match( '/date/i', $field_name ) ) {
			return 'datetime';
		}
		return 'text';
	}

	/**
	 * Map meta type to internal type
	 *
	 * @param string $type Meta type.
	 * @param string $key  Meta key.
	 * @return string Type.
	 */
	private function map_meta_type( $type, $key ) {
		if ( 'integer' === $type ) {
			return 'number';
		}
		return 'text';
	}

	/**
	 * Map database type to internal type
	 *
	 * @param string $db_type    Database type.
	 * @param string $field_name Field name.
	 * @return string Type.
	 */
	private function map_db_type( $db_type, $field_name ) {
		if ( stripos( $db_type, 'int' ) !== false ) {
			return 'number';
		}
		if ( stripos( $db_type, 'text' ) !== false ) {
			return 'text';
		}
		return 'text';
	}

	// ==========================================
	// Cross-Validation
	// ==========================================

	/**
	 * Cross-validate and merge results from multiple methods
	 *
	 * @param array $results Results from multiple scanning methods.
	 * @return array Merged and validated fields.
	 */
	private function cross_validate( $results ) {
		$final_fields = array();

		foreach ( $results as $method => $fields ) {
			foreach ( $fields as $key => $info ) {
				$info = $this->normalize_field_info( $info, $key );

				if ( ! isset( $final_fields[ $key ] ) ) {
					$final_fields[ $key ]              = $info;
					$final_fields[ $key ]['discovery'] = array(
						'confidence' => $this->get_confidence( $method ),
						'methods'    => array( $method ),
					);
				} else {
					$final_fields[ $key ]['discovery']['methods'][] = $method;

					// Upgrade if higher confidence.
					if ( $this->get_confidence( $method ) > $final_fields[ $key ]['discovery']['confidence'] ) {
						$existing_methods                                  = $final_fields[ $key ]['discovery']['methods'];
						$final_fields[ $key ]                              = array_merge( $final_fields[ $key ], $info );
						$final_fields[ $key ]['discovery']['confidence']   = $this->get_confidence( $method );
						$final_fields[ $key ]['discovery']['methods']      = $existing_methods;
					}
				}
			}
		}

		return $final_fields;
	}

	/**
	 * Normalize field info to standard structure
	 *
	 * @param array  $info Field info.
	 * @param string $key  Field key.
	 * @return array Normalized info.
	 */
	private function normalize_field_info( $info, $key ) {
		if ( isset( $info['type_definition'] ) ) {
			return $info;
		}

		if ( isset( $info['type']['semantic'] ) ) {
			return array(
				'identity'        => array( 'key' => $key ),
				'type_definition' => $info['type'],
				'sync_strategy'   => array( 'action' => $info['type']['sync'] ?? 'copy' ),
				'sample'          => $info['sample'] ?? '',
			);
		}

		$type_str = is_array( $info['type'] ) ? 'unknown' : $info['type'];
		return array(
			'identity'        => array( 'key' => $key ),
			'type_definition' => array(
				'storage'     => 'unknown',
				'semantic'    => $type_str,
				'is_relation' => false,
			),
			'sync_strategy'   => array( 'action' => 'copy' ),
		);
	}

	/**
	 * Get confidence score for a method
	 *
	 * @param string $method Method name.
	 * @return int Confidence score.
	 */
	private function get_confidence( $method ) {
		$scores = array(
			'scan_rest_api'          => 100,
			'scan_custom_tables'     => 95,
			'database_deep_scan'     => 95,
			'scan_database'          => 85,
			'scan_registered_meta'   => 90,
			'scan_source_code'       => 70,
		);
		return $scores[ $method ] ?? 50;
	}

	// ==========================================
	// URL Detection Methods
	// ==========================================

	/**
	 * Comprehensive URL detection
	 *
	 * @param string $post_type Post type name.
	 * @return array URL information.
	 */
	private function detect_urls_comprehensive( $post_type ) {
		$url_info = array(
			'frontend_urls'     => array(),
			'backend_urls'      => array(),
			'detection_methods' => array(),
		);

		// Layer 1: Golden Record URLs.
		$frontend_from_golden = $this->detect_frontend_urls( $post_type );
		if ( ! empty( $frontend_from_golden ) ) {
			$url_info['frontend_urls']      = array_merge( $url_info['frontend_urls'], $frontend_from_golden );
			$url_info['detection_methods'][] = 'golden_record';
		}

		// Layer 2: REST API info.
		$rest_info = $this->detect_urls_from_rest_api( $post_type );
		if ( $rest_info ) {
			$url_info['rest_api']            = $rest_info;
			$url_info['detection_methods'][] = 'rest_api';
		}

		// Layer 3: Rewrite rules (only if Golden failed).
		if ( empty( $frontend_from_golden ) ) {
			$rewrite_urls = $this->detect_urls_from_rewrite_rules( $post_type );
			if ( ! empty( $rewrite_urls ) ) {
				foreach ( $rewrite_urls as $rewrite_url ) {
					$exists = false;
					foreach ( $url_info['frontend_urls'] as $existing ) {
						if ( isset( $existing['url_pattern'], $rewrite_url['url_pattern'] ) &&
							$existing['url_pattern'] === $rewrite_url['url_pattern'] ) {
							$exists = true;
							break;
						}
					}
					if ( ! $exists ) {
						$url_info['frontend_urls'][] = $rewrite_url;
					}
				}
				$url_info['detection_methods'][] = 'rewrite_rules';
			}
		}

		// Layer 4: Backend URLs.
		$backend_urls = $this->detect_backend_urls( $post_type );
		if ( ! empty( $backend_urls ) ) {
			$url_info['backend_urls']        = $backend_urls;
			$url_info['detection_methods'][] = 'admin_menu_scan';
		}

		// Layer 5: Fallback.
		if ( empty( $url_info['frontend_urls'] ) && empty( $url_info['backend_urls'] ) ) {
			$url_info['detection_methods'][] = 'api_fallback';
			$pt_obj                          = get_post_type_object( $post_type );
			if ( $pt_obj && $this->is_publicly_accessible( $post_type ) ) {
				$url_slug                    = $this->get_url_slug( $pt_obj, $post_type );
				$url_info['frontend_urls'][] = array(
					'url_type'         => 'single',
					'url_pattern'      => '/' . $url_slug . '/{slug}/',
					'example_url'      => null,
					'detection_method' => 'api_inference',
				);
			}
		}

		return $url_info;
	}

	/**
	 * Detect frontend URLs using Golden Record
	 *
	 * @param string $post_type Post type name.
	 * @return array Frontend URLs.
	 */
	private function detect_frontend_urls( $post_type ) {
		$frontend_urls = array();
		$pt_obj        = get_post_type_object( $post_type );

		if ( ! $pt_obj || ! $this->is_publicly_accessible( $post_type ) ) {
			return $frontend_urls;
		}

		global $wpdb;

		// Get Golden Record ID.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$golden_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT p.ID
				FROM %i p
				LEFT JOIN %i pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status = \'publish\'
				GROUP BY p.ID
				ORDER BY COUNT(pm.meta_key) DESC
				LIMIT 1',
				$wpdb->posts,
				$wpdb->postmeta,
				$post_type
			)
		);

		if ( $golden_id ) {
			$real_url = get_permalink( $golden_id );
			if ( $real_url ) {
				$url_pattern = $this->extract_url_pattern( $real_url, $post_type );

				$frontend_urls[] = array(
					'url_type'         => 'single',
					'url_pattern'      => $url_pattern,
					'example_url'      => $real_url,
					'golden_record_id' => $golden_id,
					'requires_login'   => ! $pt_obj->publicly_queryable,
					'is_public'        => $pt_obj->public,
					'verified'         => false,
				);
			}
		}

		// Archive page.
		if ( $pt_obj->has_archive ) {
			$archive_url = get_post_type_archive_link( $post_type );
			if ( $archive_url ) {
				$frontend_urls[] = array(
					'url_type'    => 'archive',
					'url_pattern' => wp_parse_url( $archive_url, PHP_URL_PATH ),
					'example_url' => $archive_url,
					'is_public'   => true,
					'verified'    => false,
				);
			}
		}

		return $frontend_urls;
	}

	/**
	 * Detect URLs from REST API
	 *
	 * @param string $post_type Post type name.
	 * @return array|null REST API info.
	 */
	private function detect_urls_from_rest_api( $post_type ) {
		$pt_obj = get_post_type_object( $post_type );

		if ( ! $pt_obj || ! $pt_obj->show_in_rest ) {
			return null;
		}

		$rest_base      = $pt_obj->rest_base ? $pt_obj->rest_base : $post_type;
		$rest_namespace = isset( $pt_obj->rest_namespace ) ? $pt_obj->rest_namespace : 'wp/v2';
		$endpoint       = "/{$rest_namespace}/{$rest_base}";

		return array(
			'rest_endpoint'    => rest_url( $endpoint ),
			'has_frontend_url' => true,
		);
	}

	/**
	 * Detect URLs from rewrite rules
	 *
	 * @param string $post_type Post type name.
	 * @return array Frontend URLs.
	 */
	private function detect_urls_from_rewrite_rules( $post_type ) {
		global $wp_rewrite;

		$frontend_urls = array();
		$rules         = $wp_rewrite->wp_rewrite_rules();

		if ( empty( $rules ) ) {
			return $frontend_urls;
		}

		$pt_obj = get_post_type_object( $post_type );
		if ( ! $pt_obj ) {
			return $frontend_urls;
		}

		foreach ( $rules as $pattern => $replacement ) {
			if ( strpos( $replacement, 'post_type=' . $post_type ) !== false ) {
				$url_pattern = $this->convert_regex_to_pattern( $pattern );
				$url_type    = strpos( $replacement, 'name=' ) !== false ? 'single' : 'archive';

				$frontend_urls[] = array(
					'url_type'         => $url_type,
					'url_pattern'      => $url_pattern,
					'regex_pattern'    => $pattern,
					'detection_method' => 'rewrite_rules',
				);
			}
		}

		return $frontend_urls;
	}

	/**
	 * Detect backend URLs
	 *
	 * @param string $post_type Post type name.
	 * @return array Backend URLs.
	 */
	private function detect_backend_urls( $post_type ) {
		$backend_urls = array();
		$pt_obj       = get_post_type_object( $post_type );

		if ( $pt_obj && $pt_obj->show_ui ) {
			$backend_urls['edit'] = '/wp-admin/post.php?post={id}&action=edit';
			$backend_urls['list'] = '/wp-admin/edit.php?post_type=' . $post_type;
			$backend_urls['new']  = '/wp-admin/post-new.php?post_type=' . $post_type;

			if ( 'post' === $post_type ) {
				$backend_urls['list'] = '/wp-admin/edit.php';
				$backend_urls['new']  = '/wp-admin/post-new.php';
			} elseif ( 'page' === $post_type ) {
				$backend_urls['list'] = '/wp-admin/edit.php?post_type=page';
				$backend_urls['new']  = '/wp-admin/post-new.php?post_type=page';
			}
		}

		return $backend_urls;
	}

	// ==========================================
	// Helper Methods
	// ==========================================

	/**
	 * Extract URL pattern from real URL
	 *
	 * @param string $url       Real URL.
	 * @param string $post_type Post type name.
	 * @return string URL pattern.
	 */
	private function extract_url_pattern( $url, $post_type ) {
		$path    = wp_parse_url( $url, PHP_URL_PATH );
		$pattern = preg_replace( '#/[^/]+/?$#', '/{slug}/', $path );
		return $pattern;
	}

	/**
	 * Convert regex pattern to readable URL pattern
	 *
	 * @param string $regex Regex pattern.
	 * @return string URL pattern.
	 */
	private function convert_regex_to_pattern( $regex ) {
		$pattern = str_replace( '?', '', $regex );
		$pattern = str_replace( '$', '', $pattern );
		$pattern = preg_replace( '/\(\[\^\/\]\+\)/', '{slug}', $pattern );
		$pattern = preg_replace( '/\(.*?\)/', '{param}', $pattern );
		return '/' . ltrim( $pattern, '/' );
	}

	/**
	 * Get URL slug from post type object
	 *
	 * @param object $pt_obj    Post type object.
	 * @param string $post_type Post type name.
	 * @return string URL slug.
	 */
	private function get_url_slug( $pt_obj, $post_type ) {
		if ( isset( $pt_obj->rewrite['slug'] ) && $pt_obj->rewrite['slug'] ) {
			return $pt_obj->rewrite['slug'];
		}
		return $post_type;
	}

	/**
	 * Check if post type is publicly accessible
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private function is_publicly_accessible( $post_type ) {
		$pt_obj = get_post_type_object( $post_type );
		return $pt_obj && ( $pt_obj->public || $pt_obj->publicly_queryable );
	}

	/**
	 * Get object metadata
	 *
	 * @param string $post_type Post type name.
	 * @return array Metadata.
	 */
	private function get_object_metadata( $post_type ) {
		$pt_obj = get_post_type_object( $post_type );

		if ( ! $pt_obj ) {
			return array(
				'label'       => $post_type,
				'object_type' => 'unknown',
			);
		}

		$supports = function_exists( 'get_all_post_type_supports' )
			? get_all_post_type_supports( $post_type )
			: array();

		return array(
			'label'              => $pt_obj->label,
			'label_singular'     => $pt_obj->labels->singular_name ?? $pt_obj->label,
			'label_plural'       => $pt_obj->labels->name ?? $pt_obj->label,
			'description'        => $pt_obj->description ?? '',
			'has_archive'        => (bool) $pt_obj->has_archive,
			'publicly_queryable' => (bool) $pt_obj->publicly_queryable,
			'show_ui'            => (bool) $pt_obj->show_ui,
			'show_in_menu'       => (bool) $pt_obj->show_in_menu,
			'show_in_rest'       => (bool) $pt_obj->show_in_rest,
			'rest_base'          => $pt_obj->rest_base ?? '',
			'rewrite_slug'       => $this->get_url_slug( $pt_obj, $post_type ),
			'hierarchical'       => (bool) $pt_obj->hierarchical,
			'supports'           => array_keys( $supports ),
			'taxonomies'         => get_object_taxonomies( $post_type ),
		);
	}

	// ==========================================
	// Taxonomy Usage Detection
	// ==========================================

	/**
	 * Scan taxonomy usage for a post type.
	 *
	 * Queries the database to determine which taxonomies are actually used
	 * by published posts of this type, how many terms exist, and whether
	 * those terms have translatable names.
	 *
	 * @param string $post_type Post type name.
	 * @return array Keyed by taxonomy name, each with term_count, post_count,
	 *               sample_terms, is_actually_used, has_translatable_names.
	 */
	public function scan_taxonomy_usage( $post_type ) {
		global $wpdb;

		$taxonomies = get_object_taxonomies( $post_type );

		if ( empty( $taxonomies ) ) {
			return array();
		}

		$results = array();

		foreach ( $taxonomies as $taxonomy ) {
			// Get term count for this taxonomy.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$term_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE taxonomy = %s',
					$wpdb->term_taxonomy,
					$taxonomy
				)
			);

			// Get post count: how many published posts of this type use this taxonomy.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT p.ID)
					FROM %i p
					INNER JOIN %i tr ON p.ID = tr.object_id
					INNER JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE p.post_type = %s
					AND p.post_status = \'publish\'
					AND tt.taxonomy = %s',
					$wpdb->posts,
					$wpdb->term_relationships,
					$wpdb->term_taxonomy,
					$post_type,
					$taxonomy
				)
			);

			// Get top 5 sample terms by usage count.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$sample_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.term_id, t.name, t.slug, tt.count
					FROM %i t
					INNER JOIN %i tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					ORDER BY tt.count DESC
					LIMIT 5',
					$wpdb->terms,
					$wpdb->term_taxonomy,
					$taxonomy
				)
			);

			$sample_terms = array();
			$has_translatable_names = false;

			foreach ( $sample_rows as $row ) {
				$sample_terms[] = array(
					'term_id' => (int) $row->term_id,
					'name'    => $row->name,
					'slug'    => $row->slug,
					'count'   => (int) $row->count,
				);

				// A term has a translatable name if the name differs from the slug
				// and is longer than 2 characters (filters out single-char or trivial names).
				if ( ! $has_translatable_names && $row->name !== $row->slug && strlen( $row->name ) > 2 ) {
					$has_translatable_names = true;
				}
			}

			$results[ $taxonomy ] = array(
				'term_count'              => $term_count,
				'post_count'              => $post_count,
				'sample_terms'            => $sample_terms,
				'is_actually_used'        => $post_count > 0,
				'has_translatable_names'  => $has_translatable_names,
			);
		}

		return $results;
	}
}
