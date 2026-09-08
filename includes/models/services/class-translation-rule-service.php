<?php
/**
 * WPTSALL Translation Rule Service
 *
 * Translation rule service class, handles CRUD operations for new rules
 * Works with Model Scanner V2 and Rule_Validation_Service
 *
 * @package WPTSALL
 * @since 0.4.0
 * @updated 0.6.0 Added incremental update detection methods
 */

namespace WPTSALL\Models\Services;

use WPTSALL\Models\Services\Simulation_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
require_once __DIR__ . '/trait-translation-rule-service-config.php';
require_once __DIR__ . '/trait-translation-rule-service-scan.php';

/**
 * Translation Rule Service Class
 *
 * AUTHORITATIVE service for sync behavior configuration. The translation_rules
 * table (specifically field_capabilities) drives the sync pipeline. Both
 * Sync_Executor (Path B) and Monitoring_Task_Service (Path A) read from this
 * service to determine which fields to translate, sync, map, or skip.
 *
 * For display/visualization of scan results, see Model_Object_Service which
 * operates on model_objects / model_object_fields tables.
 */
class Translation_Rule_Service {
	use Translation_Rule_Service_Config_Trait;
	use Translation_Rule_Service_Scan_Trait;

	/**
	 * Get all models with optional filtering
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_models( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'       => '',
			'usage_status' => '',
			'per_page'     => 50,
			'page'         => 1,
			'orderby'      => 'plugin_name',
			'order'        => 'ASC',
			'with_counts'  => true,
		);

		$args         = wp_parse_args( $args, $defaults );
		$models_table = wptsall_table( 'models' );
		$rules_table  = wptsall_table( 'translation_rules' );

		$where_clauses = array( '1=1' );
		$where_values  = array();

		if ( ! empty( $args['status'] ) ) {
			$where_clauses[] = 'm.status = %s';
			$where_values[]  = $args['status'];
		}

		if ( ! empty( $args['usage_status'] ) ) {
			$where_clauses[] = 'm.usage_status = %s';
			$where_values[]  = $args['usage_status'];
		}

		$where_sql = implode( ' AND ', $where_clauses );
		$offset    = ( $args['page'] - 1 ) * $args['per_page'];

		// Build orderby
		$allowed_orderby = array( 'id', 'plugin_slug', 'plugin_name', 'status', 'created_at', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? 'm.' . $args['orderby'] : 'm.plugin_name';
		$order           = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		// Get total count ($where_sql / $orderby are fixed fragments or whitelist only).
		$total = (int) wptsall_db_get_var(
			'SELECT COUNT(*) FROM %i m WHERE ' . $where_sql,
			array_merge( array( $models_table ), $where_values )
		);

		// Get models with rule counts
		if ( $args['with_counts'] ) {
			$models = wptsall_db_get_results(
				'SELECT m.*, COUNT(r.id) as rule_count
					FROM %i m
					LEFT JOIN %i r ON m.id = r.model_id
					WHERE ' . $where_sql . '
					GROUP BY m.id
					ORDER BY ' . $orderby . ' ' . $order . '
					LIMIT %d OFFSET %d',
				array_merge( array( $models_table, $rules_table ), $where_values, array( $args['per_page'], $offset ) ),
				ARRAY_A
			);
		} else {
			$models = wptsall_db_get_results(
				'SELECT * FROM %i m WHERE ' . $where_sql . ' ORDER BY ' . $orderby . ' ' . $order . ' LIMIT %d OFFSET %d',
				array_merge( array( $models_table ), $where_values, array( $args['per_page'], $offset ) ),
				ARRAY_A
			);
		}

		// Parse JSON fields and enrich with plugin_mappings data
		foreach ( $models as &$model ) {
			$model['post_types'] = json_decode( $model['post_types'] ?? '[]', true ) ?: array();
			$model['taxonomies'] = json_decode( $model['taxonomies'] ?? '[]', true ) ?: array();

			// Enrich with detailed data from plugin_mappings
			$model = self::enrich_model_with_mapping_data( $model );
		}

		return array(
			'models'      => $models,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => (int) ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Get single model by ID or plugin_slug
	 *
	 * @param int|string $id_or_slug Model ID or plugin_slug.
	 * @return array|null
	 */
	public static function get_model( $id_or_slug ) {
		global $wpdb;

		$models_table = wptsall_table( 'models' );

		if ( is_numeric( $id_or_slug ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$model = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $models_table, (int) $id_or_slug ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$model = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE plugin_slug = %s', $models_table, $id_or_slug ),
				ARRAY_A
			);
		}

		if ( ! $model ) {
			return null;
		}

		// Parse JSON fields
		$model['post_types'] = json_decode( $model['post_types'] ?? '[]', true ) ?: array();
		$model['taxonomies'] = json_decode( $model['taxonomies'] ?? '[]', true ) ?: array();

		// Enrich with detailed data from plugin_mappings
		$model = self::enrich_model_with_mapping_data( $model );

		// Get translation rules
		$model['rules'] = self::get_model_rules( $model['id'] );

		return $model;
	}

	/**
	 * Approve a model semantic state.
	 *
	 * @param int $model_id Model ID.
	 * @return bool|\WP_Error
	 */
	public static function approve_model( $model_id ) {
		return self::update_model( (int) $model_id, array( 'semantic_status' => 'approved' ) );
	}

	/**
	 * Enrich model data with detailed info from plugin_mappings
	 *
	 * The models table only stores type names (e.g., ["post", "page"]),
	 * but plugin_mappings stores detailed info (supports, labels, rewrite, etc.).
	 * This method enriches the model data with that detailed information.
	 *
	 * @since 0.7.0
	 * @param array $model Model data with basic post_types/taxonomies.
	 * @return array Enriched model data.
	 */
	private static function enrich_model_with_mapping_data( $model ) {
		if ( empty( $model['plugin_slug'] ) ) {
			return $model;
		}

		// Get detailed mapping data
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) ) {
			return $model;
		}

		$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( $model['plugin_slug'] );
		if ( ! $mapping ) {
			return $model;
		}

		// Enrich post_types with detailed data
		if ( ! empty( $mapping['post_types'] ) && is_array( $mapping['post_types'] ) ) {
			$detailed_post_types = array();
			$model_pt_names      = array_map(
				function ( $pt ) {
					return is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
				},
				$model['post_types']
			);

			foreach ( $mapping['post_types'] as $pt ) {
				if ( is_array( $pt ) && isset( $pt['name'] ) ) {
					// Only include if it's in the model's post_types
					if ( in_array( $pt['name'], $model_pt_names, true ) ) {
						$detailed_post_types[] = $pt;
					}
				}
			}

			// If we found detailed data, use it; otherwise keep original
			if ( ! empty( $detailed_post_types ) ) {
				$model['post_types'] = $detailed_post_types;
			}
		}

		// Enrich taxonomies with detailed data
		if ( ! empty( $mapping['taxonomies'] ) && is_array( $mapping['taxonomies'] ) ) {
			$detailed_taxonomies = array();
			$model_tax_names     = array_map(
				function ( $tax ) {
					return is_array( $tax ) ? ( $tax['name'] ?? '' ) : $tax;
				},
				$model['taxonomies']
			);

			foreach ( $mapping['taxonomies'] as $tax ) {
				if ( is_array( $tax ) && isset( $tax['name'] ) ) {
					// Only include if it's in the model's taxonomies
					if ( in_array( $tax['name'], $model_tax_names, true ) ) {
						$detailed_taxonomies[] = $tax;
					}
				}
			}

			// If we found detailed data, use it; otherwise keep original
			if ( ! empty( $detailed_taxonomies ) ) {
				$model['taxonomies'] = $detailed_taxonomies;
			}
		}

		// Include manual fields from mapping
		$model['meta_fields'] = $mapping['meta_fields'] ?? array();

		return $model;
	}

	/**
	 * Get translation rules for a model
	 *
	 * @param int    $model_id Model ID.
	 * @param string $url_type Filter by url_type (optional).
	 * @return array
	 */
	public static function get_model_rules( $model_id, $url_type = '' ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		$where  = 'model_id = %d';
		$params = array( $rules_table, $model_id );

		if ( ! empty( $url_type ) ) {
			$where   .= ' AND url_type = %s';
			$params[] = $url_type;
		}

		$rules = wptsall_db_get_results(
			'SELECT * FROM %i WHERE ' . $where . ' ORDER BY url_type, priority ASC',
			$params,
			ARRAY_A
		);

		// Parse JSON fields (v0.8.0: use field_capabilities, v1.5.0: normalize to v3)
		foreach ( $rules as &$rule ) {
			$rule['field_capabilities'] = self::normalize_field_capabilities(
				json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array(),
				$rule['data_type'] ?? 'post'
			);
			$rule['related_taxonomies'] = json_decode( $rule['related_taxonomies'] ?? '[]', true ) ?: array();
		}

		return $rules;
	}

	/**
	 * Get single translation rule
	 *
	 * @param int $rule_id Rule ID.
	 * @return array|null
	 */
	public static function get_rule( $rule_id ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $rules_table, $rule_id ),
			ARRAY_A
		);

		if ( ! $rule ) {
			return null;
		}
		// Parse JSON fields (v0.8.0: only field_capabilities, v1.5.0: normalize to v3)
		$rule['field_capabilities'] = self::normalize_field_capabilities(
			json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array(),
			$rule['data_type'] ?? 'post'
		);
		$rule['related_taxonomies'] = json_decode( $rule['related_taxonomies'] ?? '[]', true ) ?: array();

		return $rule;
	}

	// ========================================
	// v0.8.0 configuration merge methods
	/**
	 * Get rule for a specific post_type
	 *
	 * @since 0.8.0
	 *
	 * @param int    $model_id  Model ID.
	 * @param string $post_type Post type name.
	 * @return array|null Rule data or null.
	 */
	public static function get_rule_by_post_type( $model_id, $post_type ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE model_id = %d AND object_name = %s AND data_type = 'post' LIMIT 1",
				$rules_table,
				$model_id,
				$post_type
			),
			ARRAY_A
		);

		if ( ! $rule ) {
			return null;
		}

		// Parse JSON fields (v1.5.0: normalize to v3)
		$rule['field_capabilities'] = self::normalize_field_capabilities(
			json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array()
		);
		$rule['related_taxonomies'] = json_decode( $rule['related_taxonomies'] ?? '[]', true ) ?: array();

		return $rule;
	}

	/**
	 * Get translation rules for a model with pagination and sorting
	 *
	 * @param int   $model_id Model ID.
	 * @param array $args     Query arguments.
	 * @return array
	 */
	public static function get_rules_for_model( $model_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'orderby'  => 'url_pattern',
			'order'    => 'ASC',
			'per_page' => 20,
			'page'     => 1,
			'url_type' => '',
		);

		$args        = wp_parse_args( $args, $defaults );
		$rules_table = wptsall_table( 'translation_rules' );

		$where_clauses = array( 'model_id = %d' );
		$where_values  = array( $model_id );

		if ( ! empty( $args['url_type'] ) ) {
			$where_clauses[] = 'url_type = %s';
			$where_values[]  = $args['url_type'];
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Build orderby
		$allowed_orderby = array( 'id', 'url_pattern', 'url_type', 'data_type', 'object_name', 'priority', 'created_at', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'url_pattern';
		$order           = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		// Get total count ($where_sql / $orderby are fixed fragments or whitelist only).
		$total = (int) wptsall_db_get_var(
			'SELECT COUNT(*) FROM %i WHERE ' . $where_sql,
			array_merge( array( $rules_table ), $where_values )
		);

		// Get rules with pagination
		$rules = wptsall_db_get_results(
			'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY ' . $orderby . ' ' . $order . ' LIMIT %d OFFSET %d',
			array_merge( array( $rules_table ), $where_values, array( $args['per_page'], $offset ) ),
			ARRAY_A
		);

		// Parse JSON fields (v0.8.0: use field_capabilities, v1.5.0: normalize to v3)
		foreach ( $rules as &$rule ) {
			$rule['field_capabilities'] = self::normalize_field_capabilities(
				json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array(),
				$rule['data_type'] ?? 'post'
			);
			$rule['related_taxonomies'] = json_decode( $rule['related_taxonomies'] ?? '[]', true ) ?: array();
		}

		return array(
			'items'       => $rules,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => (int) ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Get all translation rules with optional plugin filter
	 *
	 * @since 0.9.2
	 * @param array $args Query arguments.
	 * @return array Rules list with pagination.
	 */
	public static function get_all_rules( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'orderby'     => 'url_pattern',
			'order'       => 'ASC',
			'per_page'    => 20,
			'page'        => 1,
			'plugin_slug' => '',
			'model_id'    => 0,
			'post_type'   => '',
		);

		$args         = wp_parse_args( $args, $defaults );
		$rules_table  = wptsall_table( 'translation_rules' );
		$models_table = wptsall_table( 'models' );

		$where_clauses = array( '1=1' );
		$where_values  = array();

		// Filter by plugin_slug (join with models table).
		if ( ! empty( $args['plugin_slug'] ) ) {
			$where_clauses[] = 'm.plugin_slug = %s';
			$where_values[]  = $args['plugin_slug'];
		}

		// Filter by model_id (ISS-MOD-032).
		if ( ! empty( $args['model_id'] ) ) {
			$where_clauses[] = 'r.model_id = %d';
			$where_values[]  = (int) $args['model_id'];
		}

		// Filter by post_type / object_name (ISS-MOD-032).
		if ( ! empty( $args['post_type'] ) ) {
			$where_clauses[] = 'r.object_name = %s';
			$where_values[]  = $args['post_type'];
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Build orderby
		$allowed_orderby = array( 'id', 'url_pattern', 'url_type', 'data_type', 'object_name', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? 'r.' . $args['orderby'] : 'r.url_pattern';
		$order           = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		// Get total count ($where_sql / $orderby are fixed fragments or whitelist only).
		$count_sql    = 'SELECT COUNT(*) FROM %i r INNER JOIN %i m ON r.model_id = m.id WHERE ' . $where_sql;
		$count_params = array_merge( array( $rules_table, $models_table ), $where_values );
		$total        = (int) wptsall_db_get_var( $count_sql, $count_params );

		// Get rules with pagination and plugin info
		$select_sql    = 'SELECT r.*, m.plugin_slug, m.plugin_name
			FROM %i r
			INNER JOIN %i m ON r.model_id = m.id
			WHERE ' . $where_sql . '
			ORDER BY ' . $orderby . ' ' . $order . '
			LIMIT %d OFFSET %d';
		$select_params = array_merge( array( $rules_table, $models_table ), $where_values, array( $args['per_page'], $offset ) );
		$rules         = wptsall_db_get_results( $select_sql, $select_params, ARRAY_A );

		// Parse JSON fields (v1.5.0: normalize to v3)
		foreach ( $rules as &$rule ) {
			$rule['field_capabilities'] = self::normalize_field_capabilities(
				json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array()
			);
			$rule['related_taxonomies'] = json_decode( $rule['related_taxonomies'] ?? '[]', true ) ?: array();
		}

		return array(
			'rules'       => $rules,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => (int) ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Create a new translation rule with validation
	 *
	 * @param int   $model_id Model ID.
	 * @param array $data     Rule data.
	 * @return int|\WP_Error Rule ID or error.
	 */
	public static function create_rule( $model_id, $data ) {
		global $wpdb;
		// Validate model_id parameter
		if ( is_wp_error( $model_id ) ) {
			wptsall_log_error(
				'models-service',
				'create_rule called with WP_Error as model_id',
				array(
					'error' => $model_id->get_error_message(),
					'name'  => $data['name'] ?? '',
				)
			);
			return $model_id; // Return the original error
		}

		if ( ! is_numeric( $model_id ) || (int) $model_id <= 0 ) {
			return new \WP_Error('invalid_model_id',
				__( 'Invalid model ID', 'wpmmcc-ats' )
			);
		}

		$model_id = (int) $model_id;

		// Enforce: rule fields must be declared in plugin template.
		if ( array_key_exists( 'field_capabilities', $data ) ) {
			$incoming_caps = $data['field_capabilities'];
			if ( is_string( $incoming_caps ) ) {
				$incoming_caps = json_decode( $incoming_caps, true ) ?: array();
			}
			if ( ! is_array( $incoming_caps ) ) {
				$incoming_caps = array();
			}
			$had_incoming_caps = ! empty( $incoming_caps );
			$target_data_type  = sanitize_key( (string) ( $data['data_type'] ?? '' ) );
			$target_object     = sanitize_key( (string) ( $data['object_name'] ?? '' ) );

			// is_system models (test fixtures) skip template-object and removed-fields
			// checks. Production models still enforce template constraints.
			$models_table    = wptsall_table( 'models' );
			$model_is_system = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT is_system FROM %i WHERE id = %d', $models_table, $model_id )
			);

			if ( ! $model_is_system ) {
				$constraint = self::constrain_rule_fields_to_template(
					$model_id,
					$target_data_type,
					$target_object,
					$incoming_caps,
					'create_rule'
				);
				$data['field_capabilities'] = $constraint['field_capabilities'];

				if ( $had_incoming_caps && ! $constraint['has_template_object'] ) {
			return new \WP_Error('template_object_not_found',
						__( 'Rule target object does not exist in plugin template.', 'wpmmcc-ats' ),
						array(
							'model_id'    => $model_id,
							'data_type'   => $target_data_type,
							'object_name' => $target_object,
						)
					);
				}

				if ( $had_incoming_caps && ! empty( $constraint['removed_fields'] ) ) {
			return new \WP_Error('rule_fields_not_in_template',
						__( 'Some rule fields are not declared in the corresponding plugin template.', 'wpmmcc-ats' ),
						array(
							'model_id'       => $model_id,
							'data_type'      => $target_data_type,
							'object_name'    => $target_object,
							'removed_fields' => array_values( $constraint['removed_fields'] ),
						)
					);
				}
			}

			$normalized_data_type       = sanitize_key( (string) ( $data['data_type'] ?? 'post' ) );
			$data['field_capabilities'] = self::normalize_field_capabilities(
				is_array( $data['field_capabilities'] ?? null ) ? $data['field_capabilities'] : array(),
				$normalized_data_type
			);
		}

		// Validate using Rule_Validation_Service (replaces deprecated Translation_Rule_Validator).
		// Rule_Validation_Service operates on persisted rules (by rule_id), so for pre-save
		// validation we use validate_rule_data_before_save() which checks field_capabilities
		// structure, and fall back to basic format checks for rules without field_capabilities.
		//
		// is_system models (test fixtures) skip the template-aware validation; their
		// capability set is whatever the test passed in.
		$skip_template_validation = ! empty( $model_is_system );
		if ( ! empty( $data['field_capabilities'] ) && ! $skip_template_validation ) {
			$validation_input = array_merge(
				$data,
				array(
					'model_id' => $model_id,
				)
			);
			$validation       = self::validate_rule_data_before_save( $validation_input );
		} elseif ( $skip_template_validation ) {
			$validation = array( 'valid' => true, 'errors' => array(), 'warnings' => array() );
		} else {
			// Minimal pre-save checks for rules created without field_capabilities
			// (e.g., manual UI creation where capabilities are added later).
			$validation = array( 'valid' => true, 'errors' => array(), 'warnings' => array() );

			if ( empty( $data['url_type'] ) ) {
				$validation['valid']    = false;
				$validation['errors'][] = 'url_type is required';
			}
			if ( empty( $data['data_type'] ) ) {
				$validation['valid']    = false;
				$validation['errors'][] = 'data_type is required';
			}
			if ( empty( $data['object_name'] ) ) {
				$validation['valid']    = false;
				$validation['errors'][] = 'object_name is required';
			}
		}

		if ( ! $validation['valid'] ) {
			return new \WP_Error('validation_failed',
				__( 'Rule validation failed', 'wpmmcc-ats' ),
				array( 'errors' => $validation['errors'] )
			);
		}

		$rules_table = wptsall_table( 'translation_rules' );
		$now         = current_time( 'mysql' );

		// ISS-MOD-001: Check if identical rule already exists (defense layer 2)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_rule_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE model_id = %d AND data_type = %s AND object_name = %s AND url_type = %s',
				$rules_table,
				$model_id,
				sanitize_key( $data['data_type'] ),
				sanitize_key( $data['object_name'] ),
				sanitize_key( $data['url_type'] )
			)
		);

		if ( $existing_rule_id ) {
			wptsall_log_debug(
				'models-service',
				'Rule already exists, updating instead of creating (ISS-MOD-001)',
				array(
					'existing_rule_id' => $existing_rule_id,
					'model_id'         => $model_id,
					'object_name'      => $data['object_name'],
					'url_type'         => $data['url_type'],
				)
			);
			// Update existing rule instead of creating new one
			$update_result = self::update_rule( $existing_rule_id, $data );
			if ( is_wp_error( $update_result ) ) {
				return $update_result;
			}
			return (int) $existing_rule_id;
		}

		// Prepare JSON fields (v0.8.0: use field_capabilities instead of old separate fields)
		$field_capabilities = is_array( $data['field_capabilities'] ?? null ) ? $data['field_capabilities'] : array();
		$related_taxonomies = is_array( $data['related_taxonomies'] ?? null ) ? $data['related_taxonomies'] : array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$rules_table,
			array(
				'model_id'           => $model_id,
				'name'               => sanitize_text_field( $data['name'] ?? '' ),
				'url_pattern'        => sanitize_text_field( $data['url_pattern'] ),
				'url_type'           => sanitize_key( $data['url_type'] ),
				'requires_login'     => ! empty( $data['requires_login'] ) ? 1 : 0,
				'example_url'        => sanitize_text_field( $data['example_url'] ?? '' ),
				'data_type'          => sanitize_key( $data['data_type'] ),
				'object_name'        => sanitize_key( $data['object_name'] ),
				'primary_table'      => sanitize_text_field( $data['primary_table'] ?? '' ),
				'meta_table'         => sanitize_text_field( $data['meta_table'] ?? '' ),
				'direction'          => sanitize_key( $data['direction'] ?? 'one_way' ),
				// Product decision (2026-01-29): sync_mode is fixed to "new_only".
				'sync_mode'          => 'new_only',
				'field_capabilities' => wp_json_encode( $field_capabilities ),
				'backend_edit'       => sanitize_text_field( $data['backend_edit'] ?? '' ),
				'backend_list'       => sanitize_text_field( $data['backend_list'] ?? '' ),
				'backend_new'        => sanitize_text_field( $data['backend_new'] ?? '' ),
				'related_taxonomies' => wp_json_encode( $related_taxonomies ),
				'parent_rule_id'     => ! empty( $data['parent_rule_id'] ) ? (int) $data['parent_rule_id'] : null,
				'priority'           => (int) ( $data['priority'] ?? 10 ),
				'is_active'          => isset( $data['is_active'] ) ? ( $data['is_active'] ? 1 : 0 ) : 1,
				'auto_detected'      => ! empty( $data['auto_detected'] ) ? 1 : 0,
				'note'               => sanitize_textarea_field( $data['note'] ?? '' ),
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error('db_error', __( 'Database error: ', 'wpmmcc-ats' ) . $wpdb->last_error );
		}

		$rule_id = $wpdb->insert_id;

		wptsall_log_info(
			'models-service',
			'Translation rule created',
			array(
				'rule_id'     => $rule_id,
				'model_id'    => $model_id,
				'name'        => $data['name'] ?? '',
				'object_name' => $data['object_name'] ?? '',
				'url_type'    => $data['url_type'] ?? '',
			)
		);

		/**
		 * Fires after a translation rule is created or updated.
		 *
		 * @since 0.5.0
		 *
		 * @param int $rule_id  The rule ID.
		 * @param int $model_id The model ID.
		 */
		do_action( 'wptsall_rule_updated', $rule_id, $model_id );

		// Keep models.post_types / models.taxonomies in sync with actual rules.
		self::sync_model_post_types( $model_id );

		return $rule_id;
	}

	/**
	 * Update a translation rule with validation
	 *
	 * @param int   $rule_id Rule ID.
	 * @param array $data    Rule data.
	 * @return bool|\WP_Error
	 */
	public static function update_rule( $rule_id, $data ) {
		global $wpdb;
		$rules_table = wptsall_table( 'translation_rules' );

		// Get existing rule
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $rules_table, $rule_id ),
			ARRAY_A
		);

		if ( ! $existing ) {
			return new \WP_Error( 'not_found', __( 'Rule not found', 'wpmmcc-ats' ) );
		}

		// Enforce: updated rule fields must stay within plugin template.
		// is_system models (test fixtures) skip the template constraints.
		$models_table_update     = wptsall_table( 'models' );
		$existing_model_is_system = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT is_system FROM %i WHERE id = %d', $models_table_update, (int) ( $existing['model_id'] ?? 0 ) )
		);
		$incoming_caps_provided = array_key_exists( 'field_capabilities', $data );
		$key_binding_changed    = isset( $data['data_type'] ) || isset( $data['object_name'] );
		if ( ( $incoming_caps_provided || $key_binding_changed ) && ! $existing_model_is_system ) {
			if ( $incoming_caps_provided ) {
				$incoming_caps = $data['field_capabilities'];
				if ( is_string( $incoming_caps ) ) {
					$incoming_caps = json_decode( $incoming_caps, true ) ?: array();
				}
				if ( ! is_array( $incoming_caps ) ) {
					$incoming_caps = array();
				}
			} else {
				$incoming_caps = json_decode( (string) ( $existing['field_capabilities'] ?? '{}' ), true );
				$incoming_caps = is_array( $incoming_caps ) ? self::normalize_field_capabilities( $incoming_caps ) : array();
			}

			$had_incoming_caps = ! empty( $incoming_caps );
			$target_data_type  = sanitize_key( (string) ( $data['data_type'] ?? $existing['data_type'] ?? '' ) );
			$target_object     = sanitize_key( (string) ( $data['object_name'] ?? $existing['object_name'] ?? '' ) );

			$constraint = self::constrain_rule_fields_to_template(
				(int) $existing['model_id'],
				$target_data_type,
				$target_object,
				$incoming_caps,
				'update_rule'
			);
			$data['field_capabilities'] = $constraint['field_capabilities'];

			if ( $had_incoming_caps && ! $constraint['has_template_object'] ) {
				return new \WP_Error(
					'template_object_not_found',
					__( 'Rule target object does not exist in plugin template.', 'wpmmcc-ats' ),
					array(
						'rule_id'      => (int) $rule_id,
						'model_id'     => (int) $existing['model_id'],
						'data_type'    => $target_data_type,
						'object_name'  => $target_object,
					)
				);
			}

			if ( $had_incoming_caps && ! empty( $constraint['removed_fields'] ) ) {
				return new \WP_Error(
					'rule_fields_not_in_template',
					__( 'Some rule fields are not declared in the corresponding plugin template.', 'wpmmcc-ats' ),
					array(
						'rule_id'        => (int) $rule_id,
						'model_id'       => (int) $existing['model_id'],
						'data_type'      => $target_data_type,
						'object_name'    => $target_object,
						'removed_fields' => array_values( $constraint['removed_fields'] ),
					)
				);
			}
		}

		if ( array_key_exists( 'field_capabilities', $data ) ) {
			$normalize_data_type        = sanitize_key( (string) ( $data['data_type'] ?? $existing['data_type'] ?? 'post' ) );
			$data['field_capabilities'] = self::normalize_field_capabilities(
				is_array( $data['field_capabilities'] ?? null ) ? $data['field_capabilities'] : array(),
				$normalize_data_type
			);
		}

		// Merge existing data with new data for validation
		$merged_data       = array_merge( $existing, $data );
		$merged_data['id'] = $rule_id;

		// Validate using Rule_Validation_Service (replaces deprecated Translation_Rule_Validator).
		// For updates, prefer post-save validation via Rule_Validation_Service when available,
		// with pre-save structural checks as the primary gate.
		// is_system models: skip template-aware validation (see create_rule bypass).
		if ( $existing_model_is_system ) {
			$validation = array( 'valid' => true, 'errors' => array(), 'warnings' => array() );
		} elseif ( class_exists( __NAMESPACE__ . '\\Rule_Validation_Service' ) ) {
			// Post-save validation will run after update; use structural pre-save check here.
			$fc = $merged_data['field_capabilities'] ?? null;
			if ( is_string( $fc ) ) {
				$fc = json_decode( $fc, true );
			}
			if ( ! empty( $fc ) && is_array( $fc ) ) {
				$check_data = array_merge( $merged_data, array( 'field_capabilities' => $fc ) );
				$validation = self::validate_rule_data_before_save( $check_data );
			} else {
				$validation = array( 'valid' => true, 'errors' => array(), 'warnings' => array() );
			}
		} else {
			$validation = array( 'valid' => true, 'errors' => array(), 'warnings' => array() );
		}

		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'validation_failed',
				__( 'Rule validation failed', 'wpmmcc-ats' ),
				array( 'errors' => $validation['errors'] )
			);
		}

		$update_data = array(
			'updated_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s' );

		// Text fields
		$text_fields = array( 'name', 'url_pattern', 'example_url', 'primary_table', 'meta_table', 'backend_edit', 'backend_list', 'backend_new', 'note' );
		foreach ( $text_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$update_data[ $field ] = sanitize_text_field( $data[ $field ] );
				$formats[]             = '%s';
			}
		}

		// Key fields
		$key_fields = array( 'url_type', 'data_type', 'object_name' );
		foreach ( $key_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$update_data[ $field ] = sanitize_key( $data[ $field ] );
				$formats[]             = '%s';
			}
		}

		// Boolean fields
		if ( isset( $data['requires_login'] ) ) {
			$update_data['requires_login'] = $data['requires_login'] ? 1 : 0;
			$formats[]                     = '%d';
		}

		if ( isset( $data['is_active'] ) ) {
			$update_data['is_active'] = $data['is_active'] ? 1 : 0;
			$formats[]                = '%d';
		}

		if ( array_key_exists( 'auto_detected', $data ) ) {
			$update_data['auto_detected'] = ! empty( $data['auto_detected'] ) ? 1 : 0;
			$formats[]                    = '%d';
		}

		// Integer fields
		if ( isset( $data['priority'] ) ) {
			$update_data['priority'] = (int) $data['priority'];
			$formats[]               = '%d';
		}

		if ( isset( $data['parent_rule_id'] ) ) {
			$update_data['parent_rule_id'] = ! empty( $data['parent_rule_id'] ) ? (int) $data['parent_rule_id'] : null;
			$formats[]                     = '%d';
		}

		// JSON fields (v0.8.0: only field_capabilities and related_taxonomies)
		if ( isset( $data['field_capabilities'] ) ) {
			$update_data['field_capabilities'] = wp_json_encode( is_array( $data['field_capabilities'] ) ? $data['field_capabilities'] : array() );
			$formats[]                         = '%s';
		}

		if ( isset( $data['related_taxonomies'] ) ) {
			$update_data['related_taxonomies'] = wp_json_encode( is_array( $data['related_taxonomies'] ) ? $data['related_taxonomies'] : array() );
			$formats[]                         = '%s';
		}

		// v0.8.0 fields
		if ( isset( $data['direction'] ) ) {
			$update_data['direction'] = sanitize_key( $data['direction'] );
			$formats[]                = '%s';
		}

		if ( isset( $data['sync_mode'] ) ) {
			// Product decision (2026-01-29): sync_mode is fixed to "new_only" (ignore requested value).
			$update_data['sync_mode'] = 'new_only';
			$formats[]                = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$rules_table,
			$update_data,
			array( 'id' => $rule_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Database error: ', 'wpmmcc-ats' ) . $wpdb->last_error );
		}

		wptsall_log_info(
			'models-service',
			'Translation rule updated',
			array(
				'rule_id'        => $rule_id,
				'model_id'       => (int) $existing['model_id'],
				'updated_fields' => array_keys( $update_data ),
			)
		);

		/**
		 * Fires after a translation rule is created or updated.
		 *
		 * @since 0.5.0
		 *
		 * @param int $rule_id  The rule ID.
		 * @param int $model_id The model ID.
		 */
		do_action( 'wptsall_rule_updated', $rule_id, (int) $existing['model_id'] );

		// Keep models.post_types / models.taxonomies in sync with actual rules.
		self::sync_model_post_types( (int) $existing['model_id'] );

		return true;
	}

	/**
	 * Delete a translation rule
	 *
	 * @param int $rule_id Rule ID.
	 * @return bool|\WP_Error
	 */
	public static function delete_rule( $rule_id ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// Get rule info before deletion (for hook)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, model_id FROM %i WHERE id = %d', $rules_table, $rule_id ),
			ARRAY_A
		);

		if ( ! $rule ) {
			return new \WP_Error( 'not_found', __( 'Rule not found', 'wpmmcc-ats' ) );
		}

		$model_id = (int) $rule['model_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $rules_table, array( 'id' => $rule_id ), array( '%d' ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Database error: ', 'wpmmcc-ats' ) . $wpdb->last_error );
		}

		wptsall_log_info(
			'models-service',
			'Translation rule deleted',
			array(
				'rule_id'  => $rule_id,
				'model_id' => $model_id,
			)
		);

		/**
		 * Fires after a translation rule is deleted.
		 *
		 * @since 0.5.0
		 *
		 * @param int $rule_id  The deleted rule ID.
		 * @param int $model_id The model ID.
		 */
		do_action( 'wptsall_rule_updated', $rule_id, $model_id );

		// Keep models.post_types / models.taxonomies in sync with actual rules.
		self::sync_model_post_types( $model_id );

		return true;
	}

	/**
	 * Get hooks for a specific model
	 *
	 * Legacy compatibility shim.
	 *
	 * Since v0.8.0 the `translation_rules.hooks` column was removed and
	 * hook registration is derived from model/relation data instead of rule-
	 * persisted hook JSON. Keep this method as a stable empty-return helper so
	 * older call sites do not issue invalid SQL against new schemas.
	 *
	 * @since 0.6.0
	 *
	 * @param int $model_id Model ID.
	 * @return array Array of hooks with rule context.
	 */
	public static function get_hooks_by_model( $model_id ) {
		unset( $model_id );
		return array();
	}

	/**
	 * Get all hooks for active site relations
	 *
	 * Used by Hook_Manager to get all hooks that should be registered.
	 *
	 * @since 0.6.0
	 *
	 * @return array Array of hooks grouped by relation_id.
	 */
	public static function get_all_active_hooks() {
		global $wpdb;

		$rules_table    = wptsall_table( 'translation_rules' );
		$models_table   = wptsall_table( 'models' );
		$relation_table = wptsall_table( 'relation_models' );

		// Get all models associated with active site relations
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation_models = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT rm.relation_id, rm.model_id, m.plugin_slug
				FROM %i rm
				INNER JOIN %i m ON rm.model_id = m.id
				WHERE m.status = %s',
				$relation_table,
				$models_table,
				'active'
			),
			ARRAY_A
		);

		if ( empty( $relation_models ) ) {
			return array();
		}

		$hooks_by_relation = array();

		foreach ( $relation_models as $rm ) {
			$model_id    = (int) $rm['model_id'];
			$relation_id = (int) $rm['relation_id'];
			$plugin_slug = $rm['plugin_slug'];

			$model_hooks = self::get_hooks_by_model( $model_id );

			foreach ( $model_hooks as $hook ) {
				$hook['relation_id'] = $relation_id;
				$hook['model_id']    = $model_id;
				$hook['plugin_slug'] = $plugin_slug;

				if ( ! isset( $hooks_by_relation[ $relation_id ] ) ) {
					$hooks_by_relation[ $relation_id ] = array();
				}

				$hooks_by_relation[ $relation_id ][] = $hook;
			}
		}

		return $hooks_by_relation;
	}

	/**
	 * Update model status
	 *
	 * @param int    $model_id Model ID.
	 * @param string $status   New status (active/inactive).
	 * @return bool|\WP_Error
	 */
	public static function update_model_status( $model_id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			return new \WP_Error( 'invalid_status', __( 'Invalid status value', 'wpmmcc-ats' ) );
		}

		$models_table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$models_table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $model_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Database error: ', 'wpmmcc-ats' ) . $wpdb->last_error );
		}

		wptsall_log_info(
			'models-service',
			'Model status updated',
			array(
				'model_id' => $model_id,
				'status'   => $status,
			)
		);

		return true;
	}

	/**
	 * Delete a model and all its rules
	 *
	 * @param int $model_id Model ID.
	 * @return bool|\WP_Error
	 */
	public static function delete_model( $model_id ) {
		global $wpdb;

		$models_table = wptsall_table( 'models' );
		$rules_table  = wptsall_table( 'translation_rules' );

		// Check if model exists and is not system
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$model = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, plugin_slug, is_system FROM %i WHERE id = %d', $models_table, $model_id ),
			ARRAY_A
		);

		if ( ! $model ) {
			return new \WP_Error( 'not_found', __( 'Model not found', 'wpmmcc-ats' ) );
		}

		if ( $model['is_system'] ) {
			return new \WP_Error( 'system_model', __( 'System models cannot be deleted', 'wpmmcc-ats' ) );
		}

		// Cascade: capture affected relation IDs before deleting relation_models.
		$relation_models_table = wptsall_table( 'relation_models' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected_relation_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT relation_id FROM %i WHERE model_id = %d", $relation_models_table, $model_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $relation_models_table, array( 'model_id' => $model_id ), array( '%d' ) );

		// Cascade: clear template reference on site_relations that used this model's plugin_slug.
		$plugin_slug     = $model['plugin_slug'] ?? '';
		$relations_table = wptsall_table( 'site_relations' );
		if ( $plugin_slug ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare(
				"UPDATE %i SET template = '' WHERE template = %s",
				$relations_table, $plugin_slug
			) );

			// Cascade: cancel pending/retry tasks that reference this template.
			$tasks_table = wptsall_table( 'tasks' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', status_note = 'model_deleted' WHERE template = %s AND status IN ('pending', 'retry')",
				$tasks_table, $plugin_slug
			) );
		}

		// Cascade: delete orphaned relation_post_type_configs for relations with no remaining models.
		if ( ! empty( $affected_relation_ids ) ) {
			$configs_table = wptsall_table( 'relation_post_type_configs' );
			foreach ( $affected_relation_ids as $rid ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$remaining = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE relation_id = %d", $relation_models_table, (int) $rid )
				);
				if ( 0 === $remaining ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete( $configs_table, array( 'relation_id' => (int) $rid ), array( '%d' ) );
				}
			}
		}

		// Cascade delete: remove model_object_fields via object_id lookup.
		$object_deps_table   = wptsall_table( 'model_object_dependencies' );
		$object_fields_table = wptsall_table( 'model_object_fields' );
		$objects_table       = wptsall_table( 'model_objects' );

		// Delete dependency edges first (depends on model/object/field rows).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $object_deps_table ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $object_deps_table, array( 'model_id' => $model_id ), array( '%d' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $objects_table ) ) ) {
			// First get object IDs belonging to this model.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$object_ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE model_id = %d', $objects_table, $model_id )
			);

			// Delete fields for each object (model_object_fields has object_id, not model_id).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! empty( $object_ids ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $object_fields_table ) ) ) {
				foreach ( $object_ids as $object_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete( $object_fields_table, array( 'object_id' => (int) $object_id ), array( '%d' ) );
				}
			}

			// Then delete model_objects.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $objects_table, array( 'model_id' => $model_id ), array( '%d' ) );
		}

		// Delete rules.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $rules_table, array( 'model_id' => $model_id ), array( '%d' ) );

		// Delete model.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $models_table, array( 'id' => $model_id ), array( '%d' ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Database error: ', 'wpmmcc-ats' ) . $wpdb->last_error );
		}

		wptsall_log_info(
			'models-service',
			'Model deleted',
			array(
				'id'          => $model_id,
				'plugin_slug' => $model['plugin_slug'],
			)
		);

		/**
		 * Fires after a model is deleted.
		 *
		 * @since 0.5.0
		 *
		 * @param int    $model_id    The deleted model ID.
		 * @param string $plugin_slug The plugin slug of the deleted model.
		 */
		do_action( 'wptsall_model_deleted', $model_id, $model['plugin_slug'] );

		return true;
	}

	/**
	 * Get rules grouped by url_type
	 *
	 * @param int $model_id Model ID.
	 * @return array
	 */
	public static function get_rules_by_type( $model_id ) {
		$rules  = self::get_model_rules( $model_id );
		$grouped = array();

		foreach ( $rules as $rule ) {
			$type = $rule['url_type'];
			if ( ! isset( $grouped[ $type ] ) ) {
				$grouped[ $type ] = array();
			}
			$grouped[ $type ][] = $rule;
		}

		return $grouped;
	}

	/**
	 * Toggle rule active status
	 *
	 * @param int $rule_id Rule ID.
	 * @return bool|\WP_Error
	 */
	public static function toggle_rule_active( $rule_id ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// Get current status
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = $wpdb->get_var(
			$wpdb->prepare( 'SELECT is_active FROM %i WHERE id = %d', $rules_table, $rule_id )
		);

		if ( null === $current ) {
			return new \WP_Error( 'not_found', __( 'Rule not found', 'wpmmcc-ats' ) );
		}

		$new_status = $current ? 0 : 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$rules_table,
			array(
				'is_active'  => $new_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $rule_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Database error', 'wpmmcc-ats' ) );
		}

		return true;
	}

	/**
	 * Check if a model has any translation rules
	 *
	 * @param int $model_id Model ID.
	 * @return bool
	 */
	public static function has_translation_rules( $model_id ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE model_id = %d AND is_active = 1',
				$rules_table,
				$model_id
			)
		);

		return $count > 0;
	}

	/**
	 * Update model usage status
	 *
	 * @param int    $model_id Model ID.
	 * @param string $status   Usage status (active/unused).
	 * @return bool|\WP_Error
	 */
	public static function update_usage_status( $model_id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, array( 'active', 'unused' ), true ) ) {
			return new \WP_Error( 'invalid_status', __( 'Invalid usage status value', 'wpmmcc-ats' ) );
		}

		$models_table = wptsall_table( 'models' );

		// Get current status before update (for hook)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_status = $wpdb->get_var(
			$wpdb->prepare( 'SELECT usage_status FROM %i WHERE id = %d', $models_table, $model_id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$models_table,
			array(
				'usage_status' => $status,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $model_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Database error: ', 'wpmmcc-ats' ) . $wpdb->last_error );
		}

		// Fire hook only if status actually changed
		if ( $old_status !== $status ) {
			/**
			 * Fires when a model's usage status changes.
			 *
			 * @since 0.5.0
			 *
			 * @param int    $model_id   The model ID.
			 * @param string $old_status The previous usage status.
			 * @param string $new_status The new usage status.
			 */
			do_action( 'wptsall_model_usage_changed', $model_id, $old_status, $status );
		}

		return true;
	}

	/**
	 * Get model by plugin_slug
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array|null
	 */
	public static function get_model_by_plugin_slug( $plugin_slug ) {
		global $wpdb;

		$models_table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$model = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE plugin_slug = %s', $models_table, $plugin_slug ),
			ARRAY_A
		);

		if ( ! $model ) {
			return null;
		}

		// Parse JSON fields
		$model['post_types'] = json_decode( $model['post_types'] ?? '[]', true ) ?: array();
		$model['taxonomies'] = json_decode( $model['taxonomies'] ?? '[]', true ) ?: array();

		return $model;
	}

	/**
	 * Get list of plugin slugs that already have models
	 *
	 * @return array Array of plugin slugs.
	 */
	public static function get_plugins_with_models() {
		global $wpdb;

		$models_table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare( 'SELECT plugin_slug FROM %i', $models_table )
		);

		return $results ?: array();
	}

	/**
	 * Get registered plugin slugs (alias for get_plugins_with_models)
	 *
	 * @since 0.7.1
	 * @return array Array of plugin slugs that have registered models.
	 */
	public static function get_registered_plugin_slugs() {
		return self::get_plugins_with_models();
	}

	/**
	 * Create a new model
	 *
	 * @param array $data Model data.
	 * @return int|\WP_Error Model ID or error.
	 */
	public static function create_model( $data ) {
		global $wpdb;

		$models_table = wptsall_table( 'models' );

		// Validate required fields
		if ( empty( $data['plugin_slug'] ) ) {
			return new \WP_Error( 'missing_plugin_slug', __( 'Plugin slug cannot be empty', 'wpmmcc-ats' ) );
		}

		// Check if plugin_slug exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE plugin_slug = %s', $models_table, $data['plugin_slug'] )
		);

		if ( $exists ) {
			return new \WP_Error( 'plugin_exists', __( 'Model already exists for this plugin', 'wpmmcc-ats' ) );
		}

		$now = current_time( 'mysql' );

		// Prepare JSON fields
		$post_types = is_array( $data['post_types'] ?? null ) ? $data['post_types'] : array();
		$taxonomies = is_array( $data['taxonomies'] ?? null ) ? $data['taxonomies'] : array();

		// Validate content types for non-system models
		if ( empty( $data['is_system'] ) && empty( $post_types ) && empty( $taxonomies ) ) {
			return new \WP_Error(
				'no_content_types',
				__( 'Model must contain at least one post_type or taxonomy', 'wpmmcc-ats' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$models_table,
			array(
				'plugin_slug'    => sanitize_key( $data['plugin_slug'] ),
				'plugin_name'    => sanitize_text_field( $data['plugin_name'] ?? $data['plugin_slug'] ),
				'plugin_version' => sanitize_text_field( $data['plugin_version'] ?? '' ),
				'text_domain'    => sanitize_key( $data['text_domain'] ?? $data['plugin_slug'] ),
				'description'    => sanitize_textarea_field( $data['description'] ?? '' ),
				'post_types'     => wp_json_encode( $post_types ),
				'taxonomies'     => wp_json_encode( $taxonomies ),
				'status'         => in_array( $data['status'] ?? 'active', array( 'active', 'inactive' ), true ) ? ( $data['status'] ?? 'active' ) : 'active',
				'usage_status'   => in_array( $data['usage_status'] ?? 'unused', array( 'active', 'unused' ), true ) ? ( $data['usage_status'] ?? 'unused' ) : 'unused',
				'is_system'      => ! empty( $data['is_system'] ) ? 1 : 0,
				'scan_version'   => sanitize_text_field( $data['scan_version'] ?? '' ),
				'semantic_status' => in_array( $data['semantic_status'] ?? 'draft', array( 'draft', 'reviewed', 'approved', 'stale' ), true ) ? ( $data['semantic_status'] ?? 'draft' ) : 'draft',
				'last_scanned'   => $data['last_scanned'] ?? null,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Database error: ', 'wpmmcc-ats' ) . $wpdb->last_error );
		}

		$model_id = $wpdb->insert_id;

		wptsall_log(
			'model',
			'info',
			'Model created',
			array(
				'id'          => $model_id,
				'plugin_slug' => $data['plugin_slug'],
			)
		);

		/**
		 * Fires after a model is saved (created).
		 *
		 * @since 0.5.0
		 *
		 * @param int   $model_id   The model ID.
		 * @param array $model_data The model data.
		 */
		do_action( 'wptsall_model_saved', $model_id, $data );

		return $model_id;
	}

	/**
	 * Update a model
	 *
	 * @param int   $model_id Model ID.
	 * @param array $data     Model data.
	 * @return bool|\WP_Error
	 */
	public static function update_model( $model_id, $data ) {
		global $wpdb;

		$models_table = wptsall_table( 'models' );

		// Check if model exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $models_table, $model_id )
		);

		if ( ! $exists ) {
			return new \WP_Error( 'not_found', __( 'Model not found', 'wpmmcc-ats' ) );
		}

		$update_data = array(
			'updated_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s' );

		// Text fields
		$text_fields = array( 'plugin_name', 'plugin_version', 'text_domain', 'description', 'scan_version' );
		foreach ( $text_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$update_data[ $field ] = sanitize_text_field( $data[ $field ] );
				$formats[]             = '%s';
			}
		}

		// Status fields
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'active', 'inactive' ), true ) ) {
			$update_data['status'] = $data['status'];
			$formats[]             = '%s';
		}

		if ( isset( $data['usage_status'] ) && in_array( $data['usage_status'], array( 'active', 'unused' ), true ) ) {
			$update_data['usage_status'] = $data['usage_status'];
			$formats[]                   = '%s';
		}

		if ( isset( $data['semantic_status'] ) && in_array( $data['semantic_status'], array( 'draft', 'reviewed', 'approved', 'stale' ), true ) ) {
			$update_data['semantic_status'] = $data['semantic_status'];
			$update_data['last_semantic_review_at'] = current_time( 'mysql' );
			$update_data['last_semantic_review_by'] = get_current_user_id() ?: null;
			$formats[] = '%s';
			$formats[] = '%s';
			$formats[] = '%d';
		}

		// JSON fields
		if ( isset( $data['post_types'] ) ) {
			$update_data['post_types'] = wp_json_encode( is_array( $data['post_types'] ) ? $data['post_types'] : array() );
			$formats[]                 = '%s';
		}

		if ( isset( $data['taxonomies'] ) ) {
			$update_data['taxonomies'] = wp_json_encode( is_array( $data['taxonomies'] ) ? $data['taxonomies'] : array() );
			$formats[]                 = '%s';
		}

		// DateTime field
		if ( isset( $data['last_scanned'] ) ) {
			$update_data['last_scanned'] = $data['last_scanned'];
			$formats[]                   = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$models_table,
			$update_data,
			array( 'id' => $model_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Database error: ', 'wpmmcc-ats' ) . $wpdb->last_error );
		}

		wptsall_log(
			'model',
			'info',
			'Model updated',
			array( 'id' => $model_id )
		);

		/**
		 * Fires after a model is saved (updated).
		 *
		 * @since 0.5.0
		 *
		 * @param int   $model_id   The model ID.
		 * @param array $model_data The updated model data.
		 */
		do_action( 'wptsall_model_saved', $model_id, $data );

		return true;
	}

	/**
	 * Get available fields for a data type and object.
	 *
	 * Template fields are authoritative when model_id is provided.
	 * Runtime DB sampling is only a fallback for legacy callers.
	 *
	 * @param string $data_type   Data type (post, term, etc.).
	 * @param string $object_name Object name (post_type or taxonomy).
	 * @param int    $model_id    Optional model ID for template-constrained fields.
	 * @return array
	 */
	public static function get_available_fields( $data_type, $object_name, $model_id = 0 ) {
		$data_type   = sanitize_key( (string) $data_type );
		$object_name = sanitize_key( (string) $object_name );
		$model_id    = (int) $model_id;

		$fields = array(
			'main' => array(),
			'meta' => array(),
		);

		// Authoritative path: plugin template object fields.
		if ( $model_id > 0 ) {
			$object_type = self::map_rule_data_type_to_object_type( $data_type );
			if ( '' !== $object_type ) {
				$object = Model_Object_Service::get_object_by_type( $model_id, $object_type, $object_name );
				if ( $object ) {
					$object_id       = (int) ( $object['id'] ?? 0 );
					$template_fields = Model_Object_Service::get_fields_for_object( $object_id );

					foreach ( $template_fields as $field ) {
						$field_key = sanitize_text_field( (string) ( $field['field_key'] ?? '' ) );
						if ( '' === $field_key ) {
							continue;
						}

						$status = sanitize_key( (string) ( $field['status'] ?? 'active' ) );
						if ( in_array( $status, array( 'orphan', 'deprecated' ), true ) ) {
							continue;
						}

						$field_kind   = sanitize_key( (string) ( $field['field_kind'] ?? 'meta' ) );
						$cap_type = ( 'skip' === $status )
							? 'skip'
							: self::infer_capability_from_data_type(
								$field['data_type'] ?? null,
								$field,
								$object_type
							);
						$is_main_field = in_array( $field_kind, array( 'core', 'column' ), true );
						$bucket        = $is_main_field ? 'main' : 'meta';

						$fields[ $bucket ][ $field_key ] = array(
							'label'              => self::get_available_field_label( $field_key ),
							'translatable'       => self::field_supports_translate_selection(
								$field_key,
								$cap_type,
								$field['data_type'] ?? null
							),
							'default_capability' => $cap_type,
							'source'       => 'template',
						);
					}

					return $fields;
				}
			}
		}

		// Legacy fallback path: runtime sampling.
		if ( 'post' === $data_type ) {
			$fields['main'] = array(
				'post_title'   => array( 'label' => self::get_available_field_label( 'post_title' ), 'translatable' => true, 'default_capability' => 'translate' ),
				'post_content' => array( 'label' => self::get_available_field_label( 'post_content' ), 'translatable' => true, 'default_capability' => 'translate' ),
				'post_excerpt' => array( 'label' => self::get_available_field_label( 'post_excerpt' ), 'translatable' => true, 'default_capability' => 'translate' ),
				'post_name'    => array( 'label' => self::get_available_field_label( 'post_name' ), 'translatable' => true, 'default_capability' => 'translate' ),
			);

			if ( post_type_exists( $object_name ) ) {
				global $wpdb;
				$like_prefix = $wpdb->esc_like( '_' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_keys = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT pm.meta_key
						FROM %i pm
						JOIN %i p ON pm.post_id = p.ID
						WHERE p.post_type = %s
						AND pm.meta_key NOT LIKE %s
						LIMIT 50',
						$wpdb->postmeta,
						$wpdb->posts,
						$object_name,
						$like_prefix
					)
				);

				foreach ( $meta_keys as $key ) {
					$fields['meta'][ $key ] = array(
						'label'        => self::get_available_field_label( $key ),
						'translatable' => true,
						'default_capability' => 'translate',
						'source'       => 'runtime',
					);
				}
			}
		} elseif ( 'term' === $data_type ) {
			$fields['main'] = array(
				'name'        => array( 'label' => self::get_available_field_label( 'name' ), 'translatable' => true, 'default_capability' => 'translate' ),
				'slug'        => array( 'label' => self::get_available_field_label( 'slug' ), 'translatable' => true, 'default_capability' => 'translate' ),
				'description' => array( 'label' => self::get_available_field_label( 'description' ), 'translatable' => true, 'default_capability' => 'translate' ),
			);

			if ( taxonomy_exists( $object_name ) ) {
				global $wpdb;
				$like_prefix = $wpdb->esc_like( '_' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_keys = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT tm.meta_key
						FROM %i tm
						JOIN %i tt ON tm.term_id = tt.term_id
						WHERE tt.taxonomy = %s
						AND tm.meta_key NOT LIKE %s
						LIMIT 50',
						$wpdb->termmeta,
						$wpdb->term_taxonomy,
						$object_name,
						$like_prefix
					)
				);

				foreach ( $meta_keys as $key ) {
					$fields['meta'][ $key ] = array(
						'label'        => self::get_available_field_label( $key ),
						'translatable' => true,
						'default_capability' => 'translate',
						'source'       => 'runtime',
					);
				}
			}
		}

		return $fields;
	}

	/**
	 * Return the UI label for an available field.
	 *
	 * @since 1.6.0
	 *
	 * @param string $field_key Field key.
	 * @return string
	 */
	private static function get_available_field_label( string $field_key ): string {
		switch ( sanitize_key( $field_key ) ) {
			case 'post_title':
				return __( 'Title', 'wpmmcc-ats' );
			case 'post_content':
				return __( 'Content', 'wpmmcc-ats' );
			case 'post_excerpt':
				return __( 'Excerpt', 'wpmmcc-ats' );
			case 'post_name':
			case 'slug':
				return __( 'URL Slug', 'wpmmcc-ats' );
			case 'name':
				return __( 'Name', 'wpmmcc-ats' );
			case 'description':
				return __( 'Description', 'wpmmcc-ats' );
			default:
				return $field_key;
		}
	}

	/**
	 * Whether a field should be exposed as a selectable translate field in the UI.
	 *
	 * This does not change the authoritative default capability. It only controls
	 * whether the field picker should allow the user to explicitly opt into
	 * translation for fields like post/term slugs.
	 *
	 * @since 1.6.0
	 *
	 * @param string      $field_key        Field key.
	 * @param string      $default_cap_type Default capability inferred by the rule service.
	 * @param string|null $data_type        Optional template field data_type.
	 * @return bool
	 */
	private static function field_supports_translate_selection( string $field_key, string $default_cap_type = '', ?string $data_type = null ): bool {
		if ( 'translate' === sanitize_key( $default_cap_type ) ) {
			return true;
		}

		if ( 'slug' === sanitize_key( (string) $data_type ) ) {
			return true;
		}

		return in_array( sanitize_key( $field_key ), array( 'post_name', 'slug' ), true );
	}

	// ========================================
	// v0.6.0 Incremental update detection methods
	// ========================================

	/**
	 * Check if model needs update (plugin version changed)
	 *
	 * @since 0.6.0
	 *
	 * @param int|string $model_id_or_slug Model ID or plugin_slug.
	 * @return array ['needs_update' => bool, 'reason' => string, 'old_version' => string, 'new_version' => string]
	 */
	public static function check_model_needs_update( $model_id_or_slug ) {
		$model = self::get_model( $model_id_or_slug );

		if ( ! $model ) {
			return array(
				'needs_update' => false,
				'reason'       => 'model_not_found',
				'old_version'  => '',
				'new_version'  => '',
			);
		}

		// Get current installed plugin version
		$installed_version = self::get_installed_plugin_version( $model['plugin_slug'] );

		if ( empty( $installed_version ) ) {
			return array(
				'needs_update' => false,
				'reason'       => 'plugin_not_installed',
				'old_version'  => $model['plugin_version'] ?? '',
				'new_version'  => '',
			);
		}

		$stored_version = $model['plugin_version'] ?? '';

		// Version comparison
		if ( empty( $stored_version ) || version_compare( $installed_version, $stored_version, '>' ) ) {
			return array(
				'needs_update' => true,
				'reason'       => 'version_changed',
				'old_version'  => $stored_version,
				'new_version'  => $installed_version,
			);
		}

		return array(
			'needs_update' => false,
			'reason'       => 'up_to_date',
			'old_version'  => $stored_version,
			'new_version'  => $installed_version,
		);
	}

	/**
	 * Get all models that need updates
	 *
	 * @since 0.6.0
	 *
	 * @return array List of models needing updates.
	 */
	public static function get_outdated_models() {
		$result   = self::get_models( array( 'status' => 'active', 'per_page' => 500 ) );
		$outdated = array();

		foreach ( $result['models'] as $model ) {
			$check = self::check_model_needs_update( $model['id'] );

			if ( $check['needs_update'] ) {
				$model['update_info'] = $check;
				$outdated[]           = $model;
			}
		}

		return $outdated;
	}

	/**
	 * Get installed plugin version
	 *
	 * @since 0.6.0
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return string|null Plugin version or null.
	 */
	public static function get_installed_plugin_version( $plugin_slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			// Extract slug from file path
			$file_slug = dirname( $plugin_file );
			if ( '.' === $file_slug ) {
				// Single-file plugin
				$file_slug = basename( $plugin_file, '.php' );
			}

			if ( $file_slug === $plugin_slug ) {
				return $plugin_data['Version'] ?? null;
			}
		}

		return null;
	}

	/**
	 * Get model update statistics
	 *
	 * @since 0.6.0
	 *
	 * @return array
	 */
	public static function get_update_stats() {
		$outdated = self::get_outdated_models();

		return array(
			'total_models'    => self::get_models( array( 'per_page' => 1 ) )['total'],
			'outdated_count'  => count( $outdated ),
			'outdated_models' => array_map( function ( $m ) {
				return array(
					'id'           => $m['id'],
					'plugin_slug'  => $m['plugin_slug'],
					'plugin_name'  => $m['plugin_name'],
					'old_version'  => $m['update_info']['old_version'],
					'new_version'  => $m['update_info']['new_version'],
				);
			}, $outdated ),
		);
	}

	/**
	 * Pre-save structural validation for rule data (E4).
	 *
	 * Validates the rule_data structure before persisting via create_rule().
	 * Catches structural issues (empty capabilities, invalid types) early
	 * so that only well-formed rules reach the database.
	 *
	 * @since 1.3.0
	 *
	 * @param array $rule_data Rule data array to validate.
	 * @return array {
	 *     @type bool   $valid    True if no blocking errors.
	 *     @type array  $errors   List of blocking error messages.
	 *     @type array  $warnings List of non-blocking warning messages.
	 * }
	 */
	private static function validate_rule_data_before_save( array $rule_data ): array {
		$result = array(
			'valid'    => true,
			'errors'   => array(),
			'warnings' => array(),
		);

		$field_capabilities = $rule_data['field_capabilities'] ?? array();
		if ( empty( $field_capabilities ) || ! is_array( $field_capabilities ) ) {
			$result['valid']    = false;
			$result['errors'][] = 'field_capabilities is empty or not an array';
			return $result;
		}

		$metadata_keys = array(
			'translate_fields',
			'sync_fields',
			'id_mapping_fields',
			'compute_fields',
			'skip_fields',
			'id_mapping_details',
		);

		$valid_types      = array( 'translate', 'sync', 'copy_once', 'id_mapping', 'mapping', 'compute', 'skip', 'no_sync' );
		$valid_ref_types  = array( 'media', 'post', 'user', 'taxonomy', 'option', 'custom_table' );
		$valid_cf_formats = \WPTSALL\Core\Smart_Field_Classifier::get_supported_content_formats();

		$model_id           = (int) ( $rule_data['model_id'] ?? 0 );
		$data_type          = sanitize_key( (string) ( $rule_data['data_type'] ?? 'post' ) );
		$object_name        = sanitize_key( (string) ( $rule_data['object_name'] ?? '' ) );
		$source_object_type = self::map_rule_data_type_to_object_type( $data_type );
		$template_field_map = array();
		$object_lookup      = array();
		$has_translate      = false;

		if ( $model_id > 0 ) {
			$model_objects = Model_Object_Service::get_objects_for_model( $model_id );
			foreach ( $model_objects as $obj ) {
				$ot = sanitize_key( (string) ( $obj['object_type'] ?? '' ) );
				$on = sanitize_key( (string) ( $obj['object_name'] ?? '' ) );
				if ( '' !== $ot && '' !== $on ) {
					$object_lookup[ $ot . ':' . $on ] = (int) ( $obj['id'] ?? 0 );
				}
			}
		}

		if ( $model_id > 0 && '' !== $source_object_type && '' !== $object_name ) {
			$source_object = Model_Object_Service::get_object_by_type( $model_id, $source_object_type, $object_name );
			if ( ! $source_object ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'Rule target object "%s" (%s) is not found in plugin template',
					$object_name,
					$source_object_type
				);
			} else {
				$template_fields = Model_Object_Service::get_fields_for_object( (int) $source_object['id'] );
				foreach ( $template_fields as $template_field ) {
					$field_key = sanitize_text_field( (string) ( $template_field['field_key'] ?? '' ) );
					$status    = sanitize_key( (string) ( $template_field['status'] ?? 'active' ) );
					if ( '' === $field_key || 'orphan' === $status ) {
						continue;
					}
					$template_field_map[ $field_key ] = array(
						'data_type'        => sanitize_key( (string) ( $template_field['data_type'] ?? '' ) ),
						'reference_type'   => sanitize_text_field( (string) ( $template_field['reference_type'] ?? '' ) ),
						'reference_target' => sanitize_text_field( (string) ( $template_field['reference_target'] ?? '' ) ),
					);
				}
			}
		}

		foreach ( $field_capabilities as $field_name => $field_config ) {
			$field_name = sanitize_text_field( (string) $field_name );
			if ( '' === $field_name || in_array( $field_name, $metadata_keys, true ) ) {
				continue;
			}

			if ( ! is_array( $field_config ) ) {
				$result['warnings'][] = sprintf( 'Field "%s" config is not an object', $field_name );
				continue;
			}

			$type = sanitize_key( (string) ( $field_config['type'] ?? '' ) );
			if ( 'mapping' === $type ) {
				$type = 'id_mapping';
			}

			if ( '' === $type ) {
				$result['warnings'][] = sprintf( 'Field "%s" has no type specified', $field_name );
				continue;
			}

			if ( ! in_array( $type, $valid_types, true ) ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf( 'Field "%s" has invalid type "%s"', $field_name, $type );
				continue;
			}

			if ( ! empty( $template_field_map ) && ! isset( $template_field_map[ $field_name ] ) ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'Field "%s" is not declared in plugin template for "%s"',
					$field_name,
					$object_name
				);
				continue;
			}

			if ( 'translate' === $type ) {
				$has_translate   = true;
				$content_format  = sanitize_key( (string) ( $field_config['content_format'] ?? '' ) );
				if ( '' === $content_format ) {
					$result['warnings'][] = sprintf(
						'Field "%s" has no content_format; server will auto-infer it',
						$field_name
					);
				} elseif ( ! in_array( $content_format, $valid_cf_formats, true ) ) {
					$result['warnings'][] = sprintf(
						'Field "%s" has unsupported content_format "%s"; server will normalize it',
						$field_name,
						$content_format
					);
				}
			}

			$template_field = $template_field_map[ $field_name ] ?? array();
			$raw_ref_type   = sanitize_text_field( (string) ( $field_config['reference_type'] ?? '' ) );
			if ( '' !== $raw_ref_type ) {
				$normalized_ref_type = self::normalize_rule_reference_type( $raw_ref_type );
				if ( '' === $normalized_ref_type ) {
					$result['warnings'][] = sprintf(
						'Field "%s" has unknown reference_type "%s"',
						$field_name,
						$raw_ref_type
					);
				}
			}

			if ( 'id_mapping' !== $type ) {
				continue;
			}

			$resolved_ref_type = self::resolve_rule_reference_type_for_validation( $field_name, $field_config, $template_field );
			if ( '' === $resolved_ref_type || ! in_array( $resolved_ref_type, $valid_ref_types, true ) ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'Field "%s" is id_mapping but has invalid reference_type (resolved: "%s")',
					$field_name,
					$resolved_ref_type
				);
				continue;
			}

			$target_resolution = self::resolve_rule_reference_target_for_validation(
				$resolved_ref_type,
				$field_name,
				$data_type,
				$object_name,
				$field_config,
				$template_field
			);

			if ( ! empty( $target_resolution['error'] ) ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'Field "%1$s" dependency is invalid: %2$s',
					$field_name,
					$target_resolution['error']
				);
				continue;
			}

			$target_object_type = sanitize_key( (string) ( $target_resolution['target_object_type'] ?? '' ) );
			$target_object_name = sanitize_key( (string) ( $target_resolution['target_object_name'] ?? '' ) );
			if ( '' !== $target_object_type && '' !== $target_object_name ) {
				$target_key = $target_object_type . ':' . $target_object_name;
				if ( ! isset( $object_lookup[ $target_key ] ) ) {
					$result['valid']    = false;
					$result['errors'][] = sprintf(
						'Field "%1$s" references missing target object "%2$s" (%3$s)',
						$field_name,
						$target_object_name,
						$target_object_type
					);
				}
			}
		}

		if ( 'post' === $data_type && ! $has_translate ) {
			$result['warnings'][] = 'No translate fields found; rule will only sync/map without translating';
		}

		return $result;
	}

	/**
	 * Normalize rule reference_type for save-time validation.
	 *
	 * @since 1.6.0
	 *
	 * @param string $reference_type Raw reference type.
	 * @return string Canonical reference type.
	 */
	private static function normalize_rule_reference_type( string $reference_type ): string {
		$raw = strtolower( trim( $reference_type ) );
		if ( '' === $raw ) {
			return '';
		}

		$map = array(
			'post'         => 'post',
			'post_type'    => 'post',
			'generic'      => 'post',
			'term'         => 'taxonomy',
			'taxonomy'     => 'taxonomy',
			'media'        => 'media',
			'attachment'   => 'media',
			'image'        => 'media',
			'user'         => 'user',
			'author'       => 'user',
			'option'       => 'option',
			'custom_table' => 'custom_table',
			'table'        => 'custom_table',
		);

		return $map[ $raw ] ?? '';
	}

	/**
	 * Resolve reference_type for id_mapping validation.
	 *
	 * Priority: rule config > template field > heuristic fallback.
	 *
	 * @since 1.6.0
	 *
	 * @param string $field_name     Field key.
	 * @param array  $field_config   Rule field capability.
	 * @param array  $template_field Template field metadata.
	 * @return string Canonical reference type.
	 */
	private static function resolve_rule_reference_type_for_validation( string $field_name, array $field_config, array $template_field ): string {
		$candidate = sanitize_text_field( (string) ( $field_config['reference_type'] ?? '' ) );
		if ( '' === $candidate ) {
			$candidate = sanitize_text_field( (string) ( $template_field['reference_type'] ?? '' ) );
		}
		if ( '' === $candidate && class_exists( __NAMESPACE__ . '\\Id_Mapping_Resolver' ) ) {
			$candidate = (string) Id_Mapping_Resolver::infer_reference_type( $field_name );
		}

		return self::normalize_rule_reference_type( $candidate );
	}

	/**
	 * Resolve target object expectations for id_mapping validation.
	 *
	 * Returns empty target_object_type/name for external references that do not
	 * need a model object (media/user/option).
	 *
	 * @since 1.6.0
	 *
	 * @param string $reference_type Canonical reference type.
	 * @param string $field_name     Field key.
	 * @param string $data_type      Rule data_type.
	 * @param string $object_name    Rule object_name.
	 * @param array  $field_config   Rule field capability.
	 * @param array  $template_field Template field metadata.
	 * @return array {target_object_type,target_object_name,error}
	 */
	private static function resolve_rule_reference_target_for_validation(
		string $reference_type,
		string $field_name,
		string $data_type,
		string $object_name,
		array $field_config,
		array $template_field
	): array {
		$target = sanitize_key( (string) ( $field_config['reference_target'] ?? '' ) );
		if ( '' === $target ) {
			$target = sanitize_key( (string) ( $template_field['reference_target'] ?? '' ) );
		}

		$result = array(
			'target_object_type' => '',
			'target_object_name' => '',
			'error'              => '',
		);

		if ( in_array( $reference_type, array( 'media', 'user', 'option' ), true ) ) {
			return $result;
		}

		if ( 'parent' === $field_name && in_array( $data_type, array( 'term', 'taxonomy' ), true ) ) {
			$result['target_object_type'] = 'taxonomy';
			$result['target_object_name'] = sanitize_key( $object_name );
			return $result;
		}

		if ( 'post' === $reference_type ) {
			if ( in_array( $target, array( 'attachment', 'media', 'user' ), true ) ) {
				return $result;
			}

			if ( '' === $target || in_array( $target, array( 'self', 'post', 'post_type', 'generic' ), true ) ) {
				$target = ( 'post' === $data_type ) ? $object_name : '';
			}

			if ( '' === $target ) {
				$result['error'] = 'missing reference_target for post dependency';
				return $result;
			}

			$result['target_object_type'] = 'post_type';
			$result['target_object_name'] = sanitize_key( $target );
			return $result;
		}

		if ( 'taxonomy' === $reference_type ) {
			if ( '' === $target || in_array( $target, array( 'self', 'term', 'taxonomy', 'generic' ), true ) ) {
				$target = in_array( $data_type, array( 'term', 'taxonomy' ), true ) ? $object_name : '';
			}

			if ( '' === $target ) {
				$result['error'] = 'missing reference_target for taxonomy dependency';
				return $result;
			}

			$result['target_object_type'] = 'taxonomy';
			$result['target_object_name'] = sanitize_key( $target );
			return $result;
		}

		if ( 'custom_table' === $reference_type ) {
			if ( '' === $target ) {
				$result['error'] = 'missing reference_target for custom_table dependency';
				return $result;
			}

			$result['target_object_type'] = 'custom_table';
			$result['target_object_name'] = sanitize_key( $target );
			return $result;
		}

		$result['error'] = sprintf( 'unsupported reference_type "%s"', $reference_type );
		return $result;
	}

	/**
	 * Get rule for a specific taxonomy
	 *
	 * Mirrors get_rule_by_post_type() but queries data_type = 'term'.
	 *
	 * @since 1.4.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $model_id Model ID.
	 * @return array|null Rule data or null.
	 */
	public static function get_rule_by_taxonomy( $taxonomy, $model_id ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE model_id = %d AND object_name = %s AND data_type = 'term' AND is_active = 1 LIMIT 1",
				$rules_table,
				$model_id,
				$taxonomy
			),
			ARRAY_A
		);

		if ( ! $rule ) {
			return null;
		}

		// Parse JSON fields (v1.5.0: normalize to v3).
		$rule['field_capabilities'] = self::normalize_field_capabilities(
			json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array()
		);
		$rule['related_taxonomies'] = json_decode( $rule['related_taxonomies'] ?? '[]', true ) ?: array();

		return $rule;
	}

	/**
	 * Find the model ID that owns a given object (post_type or taxonomy).
	 *
	 * Scans all active models and checks their JSON post_types / taxonomies
	 * columns. When multiple models match, the one with an active translation
	 * rule for the object is preferred.
	 *
	 * Results are cached via wp_cache for 300 seconds.
	 *
	 * @since 1.2.0
	 *
	 * @param string $object_type 'post_type' or 'taxonomy'.
	 * @param string $object_name Concrete name (e.g. 'post', 'category').
	 * @return int|false Model ID on success, false if no matching model.
	 */
	public static function get_model_id_for_object( $object_type, $object_name ) {
		$cache_key = "model_for_object_{$object_type}_{$object_name}";
		$cached    = wp_cache_get( $cache_key, 'wpmmcc-ats' );
		if ( false !== $cached ) {
			// Cached 0 means "not found".
			return 0 === $cached ? false : $cached;
		}

		global $wpdb;
		$models_table = wptsall_table( 'models' );
		$rules_table  = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$models = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_types, taxonomies FROM %i WHERE status = 'active'",
				$models_table
			),
			ARRAY_A
		);

		if ( empty( $models ) ) {
			wp_cache_set( $cache_key, 0, 'wpmmcc-ats', 300 );
			return false;
		}

		$json_field = ( 'post_type' === $object_type ) ? 'post_types' : 'taxonomies';
		$candidates = array();

		foreach ( $models as $model ) {
			$items = json_decode( $model[ $json_field ], true );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$name = is_array( $item ) ? ( $item['name'] ?? '' ) : $item;
				if ( $name === $object_name ) {
					$candidates[] = (int) $model['id'];
					break;
				}
			}
		}

		if ( empty( $candidates ) ) {
			wp_cache_set( $cache_key, 0, 'wpmmcc-ats', 300 );
			return false;
		}

		if ( 1 === count( $candidates ) ) {
			wp_cache_set( $cache_key, $candidates[0], 'wpmmcc-ats', 300 );
			return $candidates[0];
		}

		// Multiple candidates: prefer one with an active translation rule.
		foreach ( $candidates as $candidate_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_rule = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE model_id = %d AND object_name = %s AND is_active = 1 LIMIT 1',
					$rules_table,
					$candidate_id,
					$object_name
				)
			);
			if ( $has_rule ) {
				wp_cache_set( $cache_key, $candidate_id, 'wpmmcc-ats', 300 );
				return $candidate_id;
			}
		}

		// Fallback to first candidate.
		wp_cache_set( $cache_key, $candidates[0], 'wpmmcc-ats', 300 );
		return $candidates[0];
	}
	// ==========================================
	// SSOT Grayscale Methods (C7)
	// ==========================================

	/**
	 * Build field capabilities from model_object_fields table (SSOT path).
	 *
	 * Reads field data from the SSOT tables and builds the same
	 * field_capabilities format as the scan_result path produces.
	 *
	 * @since 1.2.0
	 *
	 * @param int $model_id Model ID.
	 * @return array Associative array: field_key => capability info.
	 */
	private static function build_capabilities_from_objects( int $model_id ): array {
		$capabilities = array();

		// Get all objects for this model.
		$objects = Model_Object_Service::get_objects_for_model( $model_id );

		foreach ( $objects as $object ) {
			$object_id   = (int) ( $object['id'] ?? 0 );
			$object_name = $object['object_name'] ?? '';
			$object_type = $object['object_type'] ?? '';

			if ( '' === $object_name ) {
				continue;
			}

			// Initialize the object-keyed bucket.
			if ( ! isset( $capabilities[ $object_name ] ) ) {
				$capabilities[ $object_name ] = array();
			}

			// Get fields for this object.
			$fields = Model_Object_Service::get_fields_for_object( $object_id );

			foreach ( $fields as $field ) {
				$field_key  = $field['field_key'] ?? '';
				$data_type  = $field['data_type'] ?? null;
				$ref_type   = $field['reference_type'] ?? null;
				$ref_target = $field['reference_target'] ?? null;
				$source     = $field['source'] ?? 'scan';
				$status     = $field['status'] ?? 'active';

				if ( '' === $field_key ) {
					continue;
				}

				// Skip orphaned fields.
				if ( 'orphan' === $status ) {
					continue;
				}

				// Infer capability type from template field schema + metadata.
				$cap_type = ( 'skip' === $status )
					? 'skip'
					: self::infer_capability_from_data_type( $data_type, $field, (string) $object_type );

				$cap = array(
					'type'    => $cap_type,
					'enabled' => true,
					'source'  => $source,
				);

				// translate fields: add content_format (D2/D3).
				if ( 'translate' === $cap_type ) {
					$core_defaults     = self::get_core_field_defaults();
					$plugin_defaults   = method_exists( self::class, 'get_plugin_field_defaults' )
						? self::get_plugin_field_defaults()
						: array();
					$combined_defaults = array_merge( $core_defaults, $plugin_defaults );
					if ( isset( $combined_defaults[ $field_key ] ) && is_array( $combined_defaults[ $field_key ] ) ) {
						foreach ( array( 'content_format', 'task_type', 'storage', 'direction' ) as $copy_key ) {
							if ( ! empty( $combined_defaults[ $field_key ][ $copy_key ] ) ) {
								$cap[ $copy_key ] = $combined_defaults[ $field_key ][ $copy_key ];
							}
						}
						if ( isset( $combined_defaults[ $field_key ]['type'] ) ) {
							$cap['type'] = sanitize_key( (string) $combined_defaults[ $field_key ]['type'] );
						}
					} elseif ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
						$cap['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::infer_content_format( $field_key );
					} else {
						$cap['content_format'] = 'plain_text';
					}
					if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
						$cap['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::normalize_content_format(
							(string) ( $cap['content_format'] ?? '' ),
							'plain_text'
						);
					}
					if ( 'media_ref' === ( $cap['content_format'] ?? '' ) && empty( $cap['task_type'] ) ) {
						$cap['task_type'] = self::infer_media_task_type_for_field( $field_key );
					}
				}

				// id_mapping fields: add reference info.
				if ( 'id_mapping' === $cap_type ) {
					if ( ! empty( $ref_type ) ) {
						$cap['reference_type'] = $ref_type;
					}
					if ( ! empty( $ref_target ) ) {
						$cap['reference_target'] = $ref_target;
					}
					$cap['value_format'] = self::infer_value_format_from_data_type( $data_type );
				}

				$capabilities[ $object_name ][ $field_key ] = $cap;
			}
		}

		return $capabilities;
	}

	/**
	 * Merge existing rule capabilities with template capabilities in append-only mode.
	 *
	 * Existing field configurations are preserved as-is. Newly discovered template
	 * fields are appended to the rule. No existing rule fields are removed.
	 *
	 * @since 1.6.2
	 *
	 * @param array  $existing_caps Existing rule field_capabilities.
	 * @param array  $template_caps Template-derived field_capabilities.
	 * @param string $data_type     Rule data type (post|term|custom_table).
	 * @return array {
	 *   @type array $field_capabilities Merged field capabilities.
	 *   @type array $added_fields       Newly appended field keys.
	 *   @type int   $added_count        Count of appended fields.
	 * }
	 */
	private static function merge_rule_with_template_append_only( array $existing_caps, array $template_caps, string $data_type = 'post' ): array {
		$metadata_keys = array(
			'translate_fields',
			'sync_fields',
			'id_mapping_fields',
			'compute_fields',
			'skip_fields',
			'id_mapping_details',
		);

		$merged      = self::ensure_v3_format( $existing_caps, $data_type );
		$template    = self::ensure_v3_format( $template_caps, $data_type );
		$added_fields = array();

		foreach ( $template as $field_key => $field_config ) {
			if ( in_array( $field_key, $metadata_keys, true ) || ! is_array( $field_config ) ) {
				continue;
			}

			if ( ! isset( $merged[ $field_key ] ) || ! is_array( $merged[ $field_key ] ) ) {
				$merged[ $field_key ] = $field_config;
				$added_fields[]       = $field_key;
			}
		}

		$meta = array(
			'translate_fields'  => array(),
			'sync_fields'       => array(),
			'copy_once_fields'  => array(),
			'id_mapping_fields' => array(),
			'compute_fields'    => array(),
			'skip_fields'       => array(),
			'id_mapping_details'=> array(),
		);

		foreach ( $merged as $field_key => $field_config ) {
			if ( in_array( $field_key, $metadata_keys, true ) || ! is_array( $field_config ) ) {
				continue;
			}

			$type = Field_Capability::normalize_type( (string) ( $field_config['type'] ?? '' ) );
			// Persist normalized type back into merged caps.
			$merged[ $field_key ]['type'] = $type;

			if ( 'translate' === $type ) {
				$meta['translate_fields'][] = $field_key;
			} elseif ( 'sync' === $type ) {
				$meta['sync_fields'][] = $field_key;
			} elseif ( 'copy_once' === $type ) {
				$meta['copy_once_fields'][] = $field_key;
			} elseif ( 'id_mapping' === $type ) {
				$meta['id_mapping_fields'][] = $field_key;
				$detail = array();
				if ( ! empty( $field_config['reference_type'] ) ) {
					$detail['reference_type'] = sanitize_text_field( (string) $field_config['reference_type'] );
				}
				if ( ! empty( $field_config['reference_target'] ) ) {
					$detail['reference_target'] = sanitize_text_field( (string) $field_config['reference_target'] );
				}
				if ( ! empty( $field_config['value_format'] ) ) {
					$detail['value_format'] = sanitize_text_field( (string) $field_config['value_format'] );
				}
				if ( ! empty( $detail ) ) {
					$meta['id_mapping_details'][ $field_key ] = $detail;
				}
			} elseif ( 'compute' === $type ) {
				$meta['compute_fields'][] = $field_key;
			} elseif ( 'skip' === $type ) {
				$meta['skip_fields'][] = $field_key;
			}
		}

		$merged['translate_fields']  = array_values( array_unique( $meta['translate_fields'] ) );
		$merged['sync_fields']       = array_values( array_unique( $meta['sync_fields'] ) );
		$merged['copy_once_fields']  = array_values( array_unique( $meta['copy_once_fields'] ) );
		$merged['id_mapping_fields'] = array_values( array_unique( $meta['id_mapping_fields'] ) );
		$merged['compute_fields']    = array_values( array_unique( $meta['compute_fields'] ) );
		$merged['skip_fields']       = array_values( array_unique( $meta['skip_fields'] ) );
		$merged['id_mapping_details']= $meta['id_mapping_details'];
		$merged                      = self::ensure_v3_format( $merged, $data_type );

		return array(
			'field_capabilities' => $merged,
			'added_fields'       => array_values( array_unique( $added_fields ) ),
			'added_count'        => count( $added_fields ),
		);
	}

	/**
	 * Infer rule-layer capability type from template-layer data_type.
	 *
	 * @since 1.2.0
	 *
	 * Priority chain:
	 * 1) extra.wpml_action
	 * 2) extra.rule_capability_type
	 * 3) core field defaults (object-aware)
	 * 4) template data_type
	 * 5) field_key heuristics
	 *
	 * @param string|null $data_type    Template field data_type.
	 * @param array       $field        Full field record.
	 * @param string      $object_type  Model object type (post_type|taxonomy|option|custom_table).
	 * @return string translate|sync|id_mapping|compute|skip
	 */
	private static function infer_capability_from_data_type( ?string $data_type, array $field, string $object_type = '' ): string {
		$field_key   = sanitize_text_field( (string) ( $field['field_key'] ?? '' ) );
		$object_type = sanitize_key( $object_type );
		$extra       = self::decode_template_field_extra( $field );

		// 1) Explicit WPML action takes highest precedence.
		$wpml_action = sanitize_key( (string) ( $extra['wpml_action'] ?? '' ) );
		if ( 'translate' === $wpml_action ) {
			return 'translate';
		}
		if ( in_array( $wpml_action, array( 'sync', 'copy' ), true ) ) {
			return 'sync';
		}
		if ( in_array( $wpml_action, array( 'copy-once', 'copy_once', 'copyonce' ), true ) ) {
			return 'copy_once';
		}
		if ( in_array( $wpml_action, array( 'skip', 'ignore' ), true ) ) {
			return 'skip';
		}

		// Internal WPTSALL bookkeeping markers must follow authoritative core defaults,
		// even when legacy scan metadata still carries an outdated rule capability.
		$core_capability = self::infer_capability_from_core_defaults( $field_key, $object_type );
		if ( '' !== $core_capability && 0 === strpos( $field_key, '_wptsall_' ) ) {
			return $core_capability;
		}

		// 2) Existing rule capability marker from carried metadata.
		$rule_cap = sanitize_key( (string) ( $extra['rule_capability_type'] ?? '' ) );
		if ( in_array( $rule_cap, array( 'translate', 'sync', 'copy_once', 'id_mapping', 'mapping', 'compute', 'skip' ), true ) ) {
			return ( 'mapping' === $rule_cap ) ? 'id_mapping' : $rule_cap;
		}

		// 3) Core defaults are the next priority, with object-aware override for term slug.
		if ( '' !== $core_capability ) {
			return $core_capability;
		}

		// 4) Infer from template data_type.
		$data_type = sanitize_key( (string) $data_type );
		if ( '' !== $data_type ) {
			switch ( $data_type ) {
				case 'text':
				case 'html':
					return 'translate';

				case 'id_ref':
				case 'id_list':
					return 'id_mapping';

				case 'slug':
					return 'translate';

				case 'numeric':
				case 'datetime':
				case 'enum':
				case 'boolean':
				case 'url':
					return 'sync';

				case 'serialized':
				case 'json':
					// Complex types: classifier result in extra can refine the capability.
					$classified = sanitize_key( (string) ( $extra['classification']['type'] ?? '' ) );
					if ( in_array( $classified, array( 'translate', 'sync', 'id_mapping', 'compute', 'skip' ), true ) ) {
						return $classified;
					}
					// Keep consistency with scanner path: structured text defaults to translate.
					return 'translate';
			}
		}

		// 5) Fallback heuristics by field_key.
		return self::infer_capability_from_field_key( $field_key, $object_type );
	}

	/**
	 * Decode template field extra metadata.
	 *
	 * @since 1.6.0
	 *
	 * @param array $field Template field row.
	 * @return array
	 */
	private static function decode_template_field_extra( array $field ): array {
		$extra = $field['extra'] ?? null;
		if ( is_string( $extra ) ) {
			$decoded = json_decode( $extra, true );
			$extra   = is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $extra ) ? $extra : array();
	}

	/**
	 * Infer capability from authoritative core field defaults.
	 *
	 * @since 1.6.0
	 *
	 * @param string $field_key   Field key.
	 * @param string $object_type Object type context.
	 * @return string Empty string when no default exists.
	 */
	private static function infer_capability_from_core_defaults( string $field_key, string $object_type = '' ): string {
		if ( '' === $field_key ) {
			return '';
		}

		$core_defaults = self::get_core_field_defaults();
		$core_type     = sanitize_key( (string) ( $core_defaults[ $field_key ]['type'] ?? '' ) );
		if ( '' === $core_type ) {
			return '';
		}

		if ( 'mapping' === $core_type ) {
			$core_type = 'id_mapping';
		}

		if ( in_array( $core_type, array( 'translate', 'sync', 'id_mapping', 'compute', 'skip' ), true ) ) {
			return $core_type;
		}

		return '';
	}

	/**
	 * Infer media task_type for a media_ref field key.
	 *
	 * @since 1.9.0
	 *
	 * @param string $field_key Field key.
	 * @return string image|video|audio|document
	 */
	private static function infer_media_task_type_for_field( string $field_key ): string {
		$key = strtolower( $field_key );
		if ( preg_match( '/(video|mp4|webm|movie|trailer)/', $key ) ) {
			return 'video';
		}
		if ( preg_match( '/(audio|mp3|podcast|sound|voice|wav)/', $key ) ) {
			return 'audio';
		}
		if ( preg_match( '/(pdf|document|docx?|xlsx?|file|download|attachment)/', $key ) ) {
			return 'document';
		}
		return 'image';
	}

	/**
	 * Infer value_format from data_type for id_mapping fields.
	 *
	 * @since 1.2.0
	 *
	 * @param string|null $data_type Data type.
	 * @return string scalar|csv|serialized|json
	 */
	private static function infer_value_format_from_data_type( ?string $data_type ): string {
		switch ( $data_type ) {
			case 'id_list':
				return 'csv';
			case 'serialized':
				return 'serialized';
			case 'json':
				return 'json';
			default:
				return 'scalar';
		}
	}

	/**
	 * Infer capability type from field_key when data_type is not set.
	 *
	 * @since 1.2.0
	 *
	 * @param string $field_key   Field key.
	 * @param string $object_type Object type context.
	 * @return string translate|sync|id_mapping|compute
	 */
	private static function infer_capability_from_field_key( string $field_key, string $object_type = '' ): string {
		$object_type = sanitize_key( $object_type );

		// Core translatable fields.
		$translate_fields = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'name', 'description', 'slug' );
		if ( in_array( $field_key, $translate_fields, true ) ) {
			return 'translate';
		}

		// Core id_mapping fields.
		$id_mapping_fields = array( '_thumbnail_id', 'post_parent', 'post_author', 'parent' );
		if ( in_array( $field_key, $id_mapping_fields, true ) ) {
			return 'id_mapping';
		}

		// Core compute fields.
		$compute_fields = array( 'guid' );
		if ( in_array( $field_key, $compute_fields, true ) ) {
			return 'compute';
		}

		// ID-like field names.
		if ( preg_match( '/_id$|_ids$|_parent$/i', $field_key ) ) {
			return 'id_mapping';
		}

		// Default to sync for unknown fields.
		return 'sync';
	}

	/**
	 * Constrain rule field_capabilities to fields declared in plugin template.
	 *
	 * Ensures translation rule fields are always a subset of model_object_fields
	 * for the bound object (post_type/taxonomy/custom_table).
	 *
	 * @since 1.6.0
	 *
	 * @param int    $model_id           Model ID.
	 * @param string $data_type          Rule data_type (post/term/custom_table).
	 * @param string $object_name        Rule object_name.
	 * @param array  $field_capabilities Candidate field capabilities.
	 * @param string $context            Caller context for logs.
	 * @return array {
	 *   @type array $field_capabilities Filtered capabilities.
	 *   @type array $removed_fields     Removed field keys.
	 *   @type bool  $has_template_object Whether model object exists.
	 *   @type int   $template_field_count Template field count.
	 * }
	 */
	private static function constrain_rule_fields_to_template(
		int $model_id,
		string $data_type,
		string $object_name,
		array $field_capabilities,
		string $context = 'rule_save'
	): array {
		$result = array(
			'field_capabilities'  => array(),
			'removed_fields'      => array(),
			'has_template_object' => false,
			'template_field_count'=> 0,
		);

		if ( $model_id <= 0 || '' === $object_name || empty( $field_capabilities ) ) {
			$result['field_capabilities'] = is_array( $field_capabilities ) ? $field_capabilities : array();
			return $result;
		}

		$object_type = self::map_rule_data_type_to_object_type( $data_type );
		if ( '' === $object_type ) {
			$result['field_capabilities'] = is_array( $field_capabilities ) ? $field_capabilities : array();
			return $result;
		}

		$object = Model_Object_Service::get_object_by_type( $model_id, $object_type, $object_name );
		if ( ! $object ) {
			// No template object: strict mode, remove all field entries.
			$metadata_keys = array(
				'translate_fields',
				'sync_fields',
				'id_mapping_fields',
				'compute_fields',
				'skip_fields',
				'id_mapping_details',
			);
			$filtered = array();
			foreach ( $field_capabilities as $field_key => $field_config ) {
				if ( in_array( $field_key, $metadata_keys, true ) ) {
					continue;
				}
				$result['removed_fields'][] = $field_key;
			}
			$result['field_capabilities'] = $filtered;
			wptsall_log_warning(
				'models-service',
				'Template object not found when constraining rule fields; all rule fields removed',
				array(
					'context'     => $context,
					'model_id'    => $model_id,
					'data_type'   => $data_type,
					'object_name' => $object_name,
				)
			);
			return $result;
		}

		$result['has_template_object'] = true;

		$fields      = Model_Object_Service::get_fields_for_object( (int) $object['id'] );
		$allowed_map = array();
		foreach ( $fields as $field ) {
			$key    = sanitize_text_field( (string) ( $field['field_key'] ?? '' ) );
			$status = sanitize_key( (string) ( $field['status'] ?? 'active' ) );
			if ( '' === $key || 'orphan' === $status ) {
				continue;
			}
			$allowed_map[ $key ] = true;
		}

		$result['template_field_count'] = count( $allowed_map );

		$metadata_keys = array(
			'translate_fields',
			'sync_fields',
			'id_mapping_fields',
			'compute_fields',
			'skip_fields',
			'id_mapping_details',
		);
		$metadata = array();
		$filtered = array();

		foreach ( $field_capabilities as $field_key => $field_config ) {
			if ( in_array( $field_key, $metadata_keys, true ) ) {
				$metadata[ $field_key ] = $field_config;
				continue;
			}

			if ( isset( $allowed_map[ $field_key ] ) ) {
				$filtered[ $field_key ] = $field_config;
			} else {
				$result['removed_fields'][] = $field_key;
			}
		}

		$kept_keys = array_fill_keys( array_keys( $filtered ), true );

		// Keep metadata keys only after pruning references to removed fields.
		if ( isset( $metadata['translate_fields'] ) && is_array( $metadata['translate_fields'] ) ) {
			$metadata['translate_fields'] = array_values(
				array_filter(
					$metadata['translate_fields'],
					function ( $key ) use ( $kept_keys ) {
						return isset( $kept_keys[ $key ] );
					}
				)
			);
		}
		if ( isset( $metadata['sync_fields'] ) && is_array( $metadata['sync_fields'] ) ) {
			$metadata['sync_fields'] = array_values(
				array_filter(
					$metadata['sync_fields'],
					function ( $key ) use ( $kept_keys ) {
						return isset( $kept_keys[ $key ] );
					}
				)
			);
		}
		if ( isset( $metadata['id_mapping_fields'] ) && is_array( $metadata['id_mapping_fields'] ) ) {
			$metadata['id_mapping_fields'] = array_values(
				array_filter(
					$metadata['id_mapping_fields'],
					function ( $key ) use ( $kept_keys ) {
						return isset( $kept_keys[ $key ] );
					}
				)
			);
		}
		if ( isset( $metadata['compute_fields'] ) && is_array( $metadata['compute_fields'] ) ) {
			$metadata['compute_fields'] = array_values(
				array_filter(
					$metadata['compute_fields'],
					function ( $key ) use ( $kept_keys ) {
						return isset( $kept_keys[ $key ] );
					}
				)
			);
		}
		if ( isset( $metadata['skip_fields'] ) && is_array( $metadata['skip_fields'] ) ) {
			$metadata['skip_fields'] = array_values(
				array_filter(
					$metadata['skip_fields'],
					function ( $key ) use ( $kept_keys ) {
						return isset( $kept_keys[ $key ] );
					}
				)
			);
		}
		if ( isset( $metadata['id_mapping_details'] ) && is_array( $metadata['id_mapping_details'] ) ) {
			$metadata['id_mapping_details'] = array_filter(
				$metadata['id_mapping_details'],
				function ( $detail, $key ) use ( $kept_keys ) {
					return isset( $kept_keys[ $key ] );
				},
				ARRAY_FILTER_USE_BOTH
			);
		}

		$result['field_capabilities'] = array_merge( $filtered, $metadata );

		if ( ! empty( $result['removed_fields'] ) ) {
			wptsall_log_warning(
				'models-service',
				'Rule field_capabilities contained fields outside plugin template; removed',
				array(
					'context'        => $context,
					'model_id'       => $model_id,
					'data_type'      => $data_type,
					'object_name'    => $object_name,
					'removed_fields' => $result['removed_fields'],
				)
			);
		}

		return $result;
	}

	/**
	 * Map rule data_type to model object_type.
	 *
	 * @since 1.6.0
	 *
	 * @param string $data_type Rule data_type.
	 * @return string
	 */
	private static function map_rule_data_type_to_object_type( string $data_type ): string {
		$data_type = sanitize_key( $data_type );
			switch ( $data_type ) {
				case 'post':
					return 'post_type';
				case 'term':
				case 'taxonomy':
					return 'taxonomy';
				case 'option':
					return 'option';
				case 'custom_table':
					return 'custom_table';
			default:
				return '';
		}
	}

	/**
	 * Compare two capability sets and return differences.
	 *
	 * Used in 'dual' read mode to compare scan_result vs model_objects paths.
	 *
	 * @since 1.2.0
	 *
	 * @param array $from_objects Capabilities from model_objects path.
	 * @param array $from_scan   Capabilities from scan_result path.
	 * @return array Differences found (empty if identical).
	 */
	private static function compare_capabilities( array $from_objects, array $from_scan ): array {
		$diff = array();

		// Keys only in scan.
		$only_in_scan = array_diff_key( $from_scan, $from_objects );
		if ( ! empty( $only_in_scan ) ) {
			$diff['only_in_scan'] = array_keys( $only_in_scan );
		}

		// Keys only in objects.
		$only_in_objects = array_diff_key( $from_objects, $from_scan );
		if ( ! empty( $only_in_objects ) ) {
			$diff['only_in_objects'] = array_keys( $only_in_objects );
		}

		// Keys in both but different values.
		$common_keys = array_intersect_key( $from_objects, $from_scan );
		$value_diffs = array();
		foreach ( $common_keys as $key => $obj_val ) {
			$scan_val = $from_scan[ $key ] ?? null;
			// Normalize to string for comparison.
			$obj_str  = is_array( $obj_val ) ? wp_json_encode( $obj_val ) : (string) $obj_val;
			$scan_str = is_array( $scan_val ) ? wp_json_encode( $scan_val ) : (string) $scan_val;
			if ( $obj_str !== $scan_str ) {
				$value_diffs[ $key ] = array(
					'objects' => $obj_val,
					'scan'    => $scan_val,
				);
			}
		}
		if ( ! empty( $value_diffs ) ) {
			$diff['value_diff'] = $value_diffs;
		}

		return $diff;
	}


}
