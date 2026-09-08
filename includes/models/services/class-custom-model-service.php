<?php
/**
 * Custom Model Service
 *
 * Handles custom model creation and management for manual plugin configuration.
 *
 * @package WPTSALL
 * @subpackage Models\Services
 * @since 0.7.1
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom Model Service class.
 *
 * Provides functionality for:
 * - Listing plugins without registered models
 * - Checking if a plugin has data
 * - Creating custom models with manual configuration
 * - Managing link chain configurations
 *
 * @since 0.7.1
 */
class Custom_Model_Service {

	/**
	 * Get list of plugins that don't have registered models.
	 *
	 * @return array Array of plugins without models.
	 */
	public static function get_unregistered_plugins() {
		// Get all active plugins.
		$all_plugins = self::get_all_active_plugins();

		// Get plugins that already have models.
		$registered_slugs = Translation_Rule_Service::get_registered_plugin_slugs();

		// Filter out registered plugins.
		$unregistered = array();
		foreach ( $all_plugins as $slug => $plugin_data ) {
			if ( ! in_array( $slug, $registered_slugs, true ) ) {
				$unregistered[ $slug ] = $plugin_data;
			}
		}

		return $unregistered;
	}

	/**
	 * Get all active plugins with basic info.
	 *
	 * Supports both single-site and multisite environments.
	 *
	 * @return array Array of plugin slug => plugin data.
	 */
	public static function get_all_active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		// Get site-level active plugins
		$active_plugins = get_option( 'active_plugins', array() );

		// In multisite, also get network-activated plugins
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			// Network plugins are stored as plugin_file => timestamp
			$network_plugin_files = array_keys( $network_plugins );
			$active_plugins       = array_unique( array_merge( $active_plugins, $network_plugin_files ) );
		}

		$result = array();

		foreach ( $active_plugins as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ] ) ) {
				$slug = self::get_plugin_slug( $plugin_file );

				// Skip WPTSALL itself.
				if ( wptsall_is_self_plugin_slug( $slug ) ) {
					continue;
				}

				$result[ $slug ] = array(
					'name'        => $all_plugins[ $plugin_file ]['Name'],
					'version'     => $all_plugins[ $plugin_file ]['Version'],
					'description' => $all_plugins[ $plugin_file ]['Description'],
					'text_domain' => $all_plugins[ $plugin_file ]['TextDomain'] ?? $slug,
					'plugin_file' => $plugin_file,
				);
			}
		}

		return $result;
	}

	/**
	 * Get plugin slug from plugin file path.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return string Plugin slug.
	 */
	private static function get_plugin_slug( $plugin_file ) {
		$parts = explode( '/', $plugin_file );
		return $parts[0];
	}

	/**
	 * Check if a plugin has data in the database.
	 *
	 * Checks for:
	 * - Post types with the plugin prefix
	 * - Meta keys with the plugin prefix
	 * - Custom tables created by the plugin
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Data check results.
	 */
	public static function check_plugin_has_data( $plugin_slug ) {
		global $wpdb;

		$result = array(
			'has_data'   => false,
			'post_types' => array(),
			'meta_keys'  => array(),
			'tables'     => array(),
			'count'      => 0,
		);

		// 1. Check for post types with plugin prefix.
		$post_types = self::find_plugin_post_types( $plugin_slug );
		if ( ! empty( $post_types ) ) {
			$result['post_types'] = $post_types;
			foreach ( $post_types as $pt ) {
				$result['count'] += $pt['count'];
			}
		}

		// 2. Check for meta keys with plugin prefix.
		$meta_keys = self::find_plugin_meta_keys( $plugin_slug );
		if ( ! empty( $meta_keys ) ) {
			$result['meta_keys'] = $meta_keys;
			$result['count'] += count( $meta_keys );
		}

		// 3. Check for custom tables.
		$tables = self::find_plugin_tables( $plugin_slug );
		if ( ! empty( $tables ) ) {
			$result['tables'] = $tables;
			foreach ( $tables as $table ) {
				$result['count'] += $table['row_count'];
			}
		}

		$result['has_data'] = $result['count'] > 0;

		return $result;
	}

	/**
	 * Find post types that might belong to a plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Post types with counts.
	 */
	private static function find_plugin_post_types( $plugin_slug ) {
		global $wpdb;

		$result = array();
		$prefix = str_replace( '-', '_', $plugin_slug );

		// Get all custom post types.
		$post_types = get_post_types( array( '_builtin' => false ), 'objects' );

		foreach ( $post_types as $pt ) {
			// Check if post type name contains plugin slug.
			if ( stripos( $pt->name, $prefix ) !== false || stripos( $pt->name, str_replace( '_', '', $prefix ) ) !== false ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE post_type = %s',
						$wpdb->posts,
						$pt->name
					)
				);

				$result[] = array(
					'name'  => $pt->name,
					'label' => $pt->label,
					'count' => $count,
				);
			}
		}

		return $result;
	}

	/**
	 * Find meta keys that might belong to a plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Meta keys found.
	 */
	private static function find_plugin_meta_keys( $plugin_slug ) {
		global $wpdb;

		$result = array();
		$prefix = str_replace( '-', '_', $plugin_slug );

		// Common prefixes to check.
		$prefixes = array(
			'_' . $prefix,
			$prefix . '_',
			'_' . str_replace( '_', '', $prefix ),
		);

		// Search in postmeta.
		foreach ( $prefixes as $meta_prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s LIMIT 50',
					$wpdb->postmeta,
					$wpdb->esc_like( $meta_prefix ) . '%'
				)
			);

			$result = array_merge( $result, $keys );
		}

		return array_unique( $result );
	}

	/**
	 * Find database tables that might belong to a plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Tables with row counts.
	 */
	private static function find_plugin_tables( $plugin_slug ) {
		global $wpdb;

		$result = array();
		$prefix = $wpdb->prefix . str_replace( '-', '_', $plugin_slug );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col(
			$wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

			$result[] = array(
				'name'      => $table,
				'row_count' => $count,
			);
		}

		return $result;
	}

	/**
	 * Validate a link chain configuration.
	 *
	 * @param array $chain_config Chain configuration.
	 * @return array Validation result.
	 */
	public static function validate_link_chain( $chain_config ) {
		global $wpdb;

		$result = array(
			'valid'  => true,
			'errors' => array(),
		);

		if ( empty( $chain_config ) || ! is_array( $chain_config ) ) {
			$result['valid']    = false;
			$result['errors'][] = __( 'Chain configuration is empty or invalid.', 'wpmmcc-ats' );
			return $result;
		}

		foreach ( $chain_config as $index => $node ) {
			$node_errors = self::validate_chain_node( $node, $index );
			if ( ! empty( $node_errors ) ) {
				$result['valid']  = false;
				$result['errors'] = array_merge( $result['errors'], $node_errors );
			}
		}

		return $result;
	}

	/**
	 * Validate a single chain node.
	 *
	 * @param array $node Node configuration.
	 * @param int   $index Node index.
	 * @return array Array of error messages.
	 */
	private static function validate_chain_node( $node, $index ) {
		global $wpdb;

		$errors = array();

		// Check required fields.
		if ( empty( $node['source_type'] ) ) {
			$errors[] = sprintf(
				/* translators: %d: node index */
				__( 'Node %d: source_type is required.', 'wpmmcc-ats' ),
				$index + 1
			);
		}

		if ( empty( $node['target_table'] ) ) {
			$errors[] = sprintf(
				/* translators: %d: node index */
				__( 'Node %d: target_table is required.', 'wpmmcc-ats' ),
				$index + 1
			);
		} else {
			// Verify table exists.
			$table_name = $node['target_table'];
			if ( strpos( $table_name, $wpdb->prefix ) !== 0 ) {
				$table_name = $wpdb->prefix . $table_name;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
			if ( ! $table_exists ) {
				$errors[] = sprintf(
					/* translators: 1: node index, 2: table name */
					__( 'Node %1$d: table "%2$s" does not exist.', 'wpmmcc-ats' ),
					$index + 1,
					$node['target_table']
				);
			} else {
				// Verify columns exist.
				if ( ! empty( $node['target_match_column'] ) ) {
					if ( ! self::column_exists( $table_name, $node['target_match_column'] ) ) {
						$errors[] = sprintf(
							/* translators: 1: node index, 2: column name, 3: table name */
							__( 'Node %1$d: column "%2$s" does not exist in table "%3$s".', 'wpmmcc-ats' ),
							$index + 1,
							$node['target_match_column'],
							$node['target_table']
						);
					}
				}

				if ( ! empty( $node['target_id_column'] ) ) {
					if ( ! self::column_exists( $table_name, $node['target_id_column'] ) ) {
						$errors[] = sprintf(
							/* translators: 1: node index, 2: column name, 3: table name */
							__( 'Node %1$d: column "%2$s" does not exist in table "%3$s".', 'wpmmcc-ats' ),
							$index + 1,
							$node['target_id_column'],
							$node['target_table']
						);
					}
				}
			}
		}

		// Validate source_type specific fields.
		if ( 'url_param' === $node['source_type'] ) {
			if ( empty( $node['source_param'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: node index */
					__( 'Node %d: source_param is required when source_type is url_param.', 'wpmmcc-ats' ),
					$index + 1
				);
			}
		} elseif ( 'table_column' === $node['source_type'] ) {
			if ( empty( $node['source_table'] ) || empty( $node['source_column'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: node index */
					__( 'Node %d: source_table and source_column are required when source_type is table_column.', 'wpmmcc-ats' ),
					$index + 1
				);
			}
		}

		return $errors;
	}

	/**
	 * Check if a column exists in a table.
	 *
	 * @param string $table_name Table name.
	 * @param string $column_name Column name.
	 * @return bool True if column exists.
	 */
	private static function column_exists( $table_name, $column_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table_name,
				$column_name
			)
		);

		return ! empty( $result );
	}

	/**
	 * Test a link chain with a sample value.
	 *
	 * @param array  $chain_config Chain configuration.
	 * @param string $sample_value Sample value to test.
	 * @return array Test results.
	 */
	public static function test_link_chain( $chain_config, $sample_value ) {
		global $wpdb;

		$result = array(
			'success'      => false,
			'steps'        => array(),
			'final_ids'    => array(),
			'error'        => null,
			'matched_rows' => 0,
		);

		if ( empty( $chain_config ) || empty( $sample_value ) ) {
			$result['error'] = __( 'Chain configuration or sample value is empty.', 'wpmmcc-ats' );
			return $result;
		}

		$current_value = $sample_value;

		foreach ( $chain_config as $index => $node ) {
			$step_result = self::execute_chain_step( $node, $current_value, $index );
			$result['steps'][] = $step_result;

			if ( ! $step_result['success'] ) {
				$result['error'] = $step_result['error'];
				return $result;
			}

			$current_value = $step_result['output_value'];
		}

		$result['success']      = true;
		$result['final_ids']    = is_array( $current_value ) ? $current_value : array( $current_value );
		$result['matched_rows'] = count( $result['final_ids'] );

		return $result;
	}

	/**
	 * Execute a single chain step.
	 *
	 * @param array  $node Chain node configuration.
	 * @param mixed  $input_value Input value from previous step.
	 * @param int    $index Step index.
	 * @return array Step execution result.
	 */
	private static function execute_chain_step( $node, $input_value, $index ) {
		global $wpdb;

		$step = array(
			'index'        => $index,
			'success'      => false,
			'input_value'  => $input_value,
			'output_value' => null,
			'query'        => null,
			'matched'      => 0,
			'error'        => null,
		);

		$table_name = $node['target_table'];
		if ( strpos( $table_name, $wpdb->prefix ) !== 0 ) {
			$table_name = $wpdb->prefix . $table_name;
		}

		// Build the query (table/column identifiers via %i; value via %s).
		// Inline prepare at call site so Plugin Directory scanners do not flag a free $query variable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT %i FROM %i WHERE %i = %s',
				$node['target_id_column'],
				$table_name,
				$node['target_match_column'],
				$input_value
			)
		);

		$step['query'] = 'SELECT ' . $node['target_id_column'] . ' FROM ' . $table_name . ' WHERE ' . $node['target_match_column'] . ' = ?';

		if ( empty( $results ) ) {
			$step['error'] = sprintf(
				/* translators: 1: table name, 2: column name, 3: input value */
				__( 'No match found in %1$s.%2$s for value "%3$s".', 'wpmmcc-ats' ),
				$node['target_table'],
				$node['target_match_column'],
				$input_value
			);
			return $step;
		}

		$step['success']      = true;
		$step['output_value'] = count( $results ) === 1 ? $results[0] : $results;
		$step['matched']      = count( $results );

		return $step;
	}

	/**
	 * Create a custom model with initial rule.
	 *
	 * @param array $data Model data including url_pattern.
	 * @return array|WP_Error Array with model_id and rule_id on success, WP_Error on failure.
	 */
	public static function create_custom_model( $data ) {
		global $wpdb;

		$table = wptsall_table( 'models' );
		$now   = current_time( 'mysql' );

		// Validate required fields.
		if ( empty( $data['plugin_slug'] ) ) {
			return new \WP_Error( 'missing_plugin_slug', __( 'Plugin slug is required.', 'wpmmcc-ats' ) );
		}

		// ISS-MOD-004: url_pattern only required when using root-level params (no rules array).
		if ( empty( $data['rules'] ) && empty( $data['url_pattern'] ) ) {
			return new \WP_Error( 'missing_url_pattern', __( 'URL pattern is required when not using rules array.', 'wpmmcc-ats' ) );
		}

		// Check if model already exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE plugin_slug = %s',
				$table,
				$data['plugin_slug']
			)
		);

		if ( $exists ) {
			return new \WP_Error(
				'model_exists',
				sprintf(
					/* translators: %s: plugin slug */
					__( 'A model for plugin "%s" already exists.', 'wpmmcc-ats' ),
					$data['plugin_slug']
				)
			);
		}

		// Insert the model.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'plugin_slug'    => $data['plugin_slug'],
				'plugin_name'    => $data['plugin_name'] ?? $data['plugin_slug'],
				'plugin_version' => $data['plugin_version'] ?? '',
				'text_domain'    => $data['text_domain'] ?? $data['plugin_slug'],
				'description'    => $data['description'] ?? '',
				'post_types'     => wp_json_encode( $data['post_types'] ?? array() ),
				'taxonomies'     => wp_json_encode( $data['taxonomies'] ?? array() ),
				'status'         => 'active',
				'usage_status'   => 'unused',
				'source_type'    => 'manual',
				'is_system'      => 0,
				'scan_version'   => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '0.0.0',
				'last_scanned'   => $now,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array(
				'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
			)
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to insert model.', 'wpmmcc-ats' ) );
		}

		$model_id = $wpdb->insert_id;

		// Create translation rules (ISS-MOD-003: refactored logic).
		$rule_table          = wptsall_table( 'translation_rules' );
		$rule_id             = null;
		$additional_rule_ids = array();

		if ( ! empty( $data['rules'] ) && is_array( $data['rules'] ) ) {
			// Create all rules using the rules array.
			foreach ( $data['rules'] as $index => $rule ) {
				// Build field_capabilities from rule data.
				$field_capabilities = $rule['field_capabilities'] ?? array();
				if ( empty( $field_capabilities ) ) {
					$field_capabilities = self::build_field_capabilities_from_arrays(
						$rule['translate_fields'] ?? array(),
						$rule['sync_fields'] ?? array(),
						$rule['field_mappings'] ?? array(),
						$rule['compute_fields'] ?? array()
					);
				}

				// Insert rule.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$rule_inserted = $wpdb->insert(
					$rule_table,
					array(
						'model_id'           => $model_id,
						'name'               => $rule['rule_name'] ?? $data['plugin_slug'] . ' - ' . ( $rule['object_name'] ?? '' ),
						'url_pattern'        => $rule['url_pattern'] ?? '/{slug}/',
						'url_type'           => $rule['url_type'] ?? 'frontend',
						'data_type'          => $rule['data_type'] ?? 'post',
						'object_name'        => $rule['object_name'] ?? '',
						'direction'          => $rule['direction'] ?? 'source_to_target',
						'sync_mode'          => $rule['sync_mode'] ?? 'full',
						'field_capabilities' => wp_json_encode( $field_capabilities ),
						'is_active'          => 1,
						'auto_detected'      => 0,
						'created_at'         => $now,
						'updated_at'         => $now,
					),
					array(
						'%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s',
					)
				);

				if ( ! $rule_inserted ) {
					// Delete the model if rule creation failed.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete( $table, array( 'id' => $model_id ), array( '%d' ) );
					return new \WP_Error(
						'rule_insert_failed',
						sprintf(
							/* translators: %d: rule index */
							__( 'Failed to create translation rule at index %d.', 'wpmmcc-ats' ),
							$index
						)
					);
				}

				$inserted_rule_id = $wpdb->insert_id;

				// First rule saves as rule_id, rest go to additional_rule_ids.
				if ( 0 === $index ) {
					$rule_id = $inserted_rule_id;
				} else {
					$additional_rule_ids[] = $inserted_rule_id;
				}

				// Save link chains for this rule.
				if ( ! empty( $rule['link_chains'] ) && is_array( $rule['link_chains'] ) ) {
					$chains_result = self::save_link_chains( $inserted_rule_id, $rule['link_chains'] );
					if ( is_wp_error( $chains_result ) ) {
						wptsall_log(
							'models',
							'warning',
							'Failed to save link chains',
							array(
								'rule_id' => $inserted_rule_id,
								'index'   => $index,
								'error'   => $chains_result->get_error_message(),
							)
						);
					}
				}
			}
		} else {
			// Create single rule using root-level params (backward compatible).
			$field_capabilities = $data['field_capabilities'] ?? array();
			if ( empty( $field_capabilities ) ) {
				$field_capabilities = self::build_field_capabilities_from_arrays(
					$data['translate_fields'] ?? array(),
					$data['sync_fields'] ?? array(),
					$data['field_mappings'] ?? array(),
					$data['compute_fields'] ?? array()
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rule_inserted = $wpdb->insert(
				$rule_table,
				array(
					'model_id'           => $model_id,
					'name'               => $data['rule_name'] ?? $data['plugin_slug'] . ' - ' . __( 'Primary Rule', 'wpmmcc-ats' ),
					'url_pattern'        => $data['url_pattern'],
					'url_type'           => $data['url_type'] ?? 'frontend',
					'data_type'          => $data['data_type'] ?? 'post',
					'object_name'        => $data['object_name'] ?? '',
					'direction'          => $data['direction'] ?? 'source_to_target',
					'sync_mode'          => $data['sync_mode'] ?? 'full',
					'field_capabilities' => wp_json_encode( $field_capabilities ),
					'is_active'          => 1,
					'auto_detected'      => 0,
					'created_at'         => $now,
					'updated_at'         => $now,
				),
				array(
					'%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s',
				)
			);

			if ( ! $rule_inserted ) {
				// Delete the model if rule creation failed.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $table, array( 'id' => $model_id ), array( '%d' ) );
				return new \WP_Error( 'rule_insert_failed', __( 'Failed to create translation rule.', 'wpmmcc-ats' ) );
			}

			$rule_id = $wpdb->insert_id;

			// Save link chains if provided.
			if ( ! empty( $data['link_chains'] ) && is_array( $data['link_chains'] ) ) {
				$chains_result = self::save_link_chains( $rule_id, $data['link_chains'] );
				if ( is_wp_error( $chains_result ) ) {
					wptsall_log(
						'models',
						'warning',
						'Failed to save link chains',
						array(
							'rule_id' => $rule_id,
							'error'   => $chains_result->get_error_message(),
						)
					);
				}
			}
		}

		wptsall_log(
			'models',
			'info',
			'Custom model created',
			array(
				'model_id'             => $model_id,
				'rule_id'              => $rule_id,
				'additional_rule_ids'  => $additional_rule_ids,
				'total_rules'          => 1 + count( $additional_rule_ids ),
				'plugin_slug'          => $data['plugin_slug'],
				'source_type'          => 'manual',
			)
		);

		return array(
			'model_id'            => $model_id,
			'rule_id'             => $rule_id,
			'additional_rule_ids' => $additional_rule_ids,
			'total_rules'         => 1 + count( $additional_rule_ids ),
		);
	}

	/**
	 * Save link chains for a rule.
	 *
	 * @param int   $rule_id Rule ID.
	 * @param array $chains Link chain configurations.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function save_link_chains( $rule_id, $chains ) {
		global $wpdb;

		$table = wptsall_table( 'model_link_chains' );
		$now   = current_time( 'mysql' );

		// Delete existing chains for this rule.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'rule_id' => $rule_id ), array( '%d' ) );

		// Insert new chains.
		foreach ( $chains as $index => $chain ) {
			$normalized_chain = self::normalize_link_chain_for_storage( $chain, $index );
			if ( is_wp_error( $normalized_chain ) ) {
				return $normalized_chain;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$table,
				array(
						'rule_id'             => $rule_id,
						'chain_order'         => $index,
						'source_type'         => $normalized_chain['source_type'],
						'source_param'        => $normalized_chain['source_param'],
						'source_table'        => $normalized_chain['source_table'],
						'source_column'       => $normalized_chain['source_column'],
						'target_table'        => $normalized_chain['target_table'],
						'target_match_column' => $normalized_chain['target_match_column'],
						'target_id_column'    => $normalized_chain['target_id_column'],
						'created_at'          => $now,
						'updated_at'          => $now,
					),
				array(
					'%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				)
			);

			if ( ! $inserted ) {
				return new \WP_Error(
					'chain_insert_failed',
					sprintf(
						/* translators: %d: chain index */
						__( 'Failed to insert chain at index %d.', 'wpmmcc-ats' ),
						$index
					)
				);
			}
		}

		wptsall_log(
			'models',
			'info',
			'Link chains saved',
			array(
				'rule_id'     => $rule_id,
				'chain_count' => count( $chains ),
			)
		);

		return true;
	}

	/**
	 * Normalize link chain payload for DB storage.
	 *
	 * Supports legacy payload shape:
	 * - type -> source_type
	 * - key -> source_param
	 * - pattern is accepted but currently not persisted in chain table.
	 *
	 * @param mixed $chain Raw chain payload.
	 * @param int   $index Chain index.
	 * @return array|\WP_Error
	 */
	private static function normalize_link_chain_for_storage( $chain, $index ) {
		if ( ! is_array( $chain ) ) {
			return new \WP_Error(
				'invalid_chain_payload',
				sprintf(
					/* translators: %d: chain index */
					__( 'Invalid link chain payload at index %d.', 'wpmmcc-ats' ),
					$index
				)
			);
		}

		global $wpdb;
		$source_type = sanitize_key( (string) ( $chain['source_type'] ?? $chain['type'] ?? '' ) );
		$source_param = isset( $chain['source_param'] )
			? sanitize_text_field( (string) $chain['source_param'] )
			: ( isset( $chain['key'] ) ? sanitize_text_field( (string) $chain['key'] ) : null );
		$source_table  = isset( $chain['source_table'] ) ? sanitize_text_field( (string) $chain['source_table'] ) : null;
		$source_column = isset( $chain['source_column'] ) ? sanitize_text_field( (string) $chain['source_column'] ) : null;

		$target_table        = sanitize_text_field( (string) ( $chain['target_table'] ?? '' ) );
		$target_match_column = sanitize_text_field( (string) ( $chain['target_match_column'] ?? '' ) );
		$target_id_column    = sanitize_text_field( (string) ( $chain['target_id_column'] ?? '' ) );

		// Backward compatibility for legacy link_chains payload shape.
		if ( '' === $source_type ) {
			$source_type = 'meta';
		}

		if ( '' === $target_table || '' === $target_match_column || '' === $target_id_column ) {
			if ( 'meta' === $source_type ) {
				$target_table        = '' !== $target_table ? $target_table : $wpdb->postmeta;
				$target_match_column = '' !== $target_match_column ? $target_match_column : 'meta_value';
				$target_id_column    = '' !== $target_id_column ? $target_id_column : 'post_id';
				$source_column       = $source_column ?: 'meta_value';
			} else {
				$target_table        = '' !== $target_table ? $target_table : $wpdb->posts;
				$target_match_column = '' !== $target_match_column ? $target_match_column : 'post_name';
				$target_id_column    = '' !== $target_id_column ? $target_id_column : 'ID';
			}
		}

		if ( '' === $target_table || '' === $target_match_column || '' === $target_id_column ) {
			return new \WP_Error(
				'invalid_chain_target_config',
				sprintf(
					/* translators: %d: chain index */
					__( 'Incomplete link chain target config at index %d.', 'wpmmcc-ats' ),
					$index
				)
			);
		}

		return array(
			'source_type'         => $source_type,
			'source_param'        => $source_param,
			'source_table'        => $source_table,
			'source_column'       => $source_column,
			'target_table'        => $target_table,
			'target_match_column' => $target_match_column,
			'target_id_column'    => $target_id_column,
		);
	}

	/**
	 * Get link chains for a rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @return array Link chains.
	 */
	public static function get_link_chains( $rule_id ) {
		global $wpdb;

		$table = wptsall_table( 'model_link_chains' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$chains = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE rule_id = %d ORDER BY chain_order ASC',
				$table,
				$rule_id
			),
			ARRAY_A
		);

		return $chains ?: array();
	}

	/**
	 * Get all database tables available for link chain configuration.
	 *
	 * @return array Array of table names with their columns.
	 */
	public static function get_database_tables() {
		global $wpdb;

		$result = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

		foreach ( $tables as $table ) {
			// Skip WPTSALL internal tables.
			if ( strpos( $table, $wpdb->prefix . 'wptsall_' ) === 0 ) {
				continue;
			}

			$result[] = array(
				'name'         => $table,
				'display_name' => str_replace( $wpdb->prefix, '', $table ),
			);
		}

		return $result;
	}

	/**
	 * Get columns for a specific table.
	 *
	 * @param string $table_name Table name.
	 * @return array Array of column information.
	 */
	public static function get_table_columns( $table_name ) {
		global $wpdb;

		// Ensure table name has prefix.
		if ( strpos( $table_name, $wpdb->prefix ) !== 0 ) {
			$table_name = $wpdb->prefix . $table_name;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ), ARRAY_A );

		$result = array();
		foreach ( $columns as $col ) {
			$result[] = array(
				'name'    => $col['Field'],
				'type'    => $col['Type'],
				'key'     => $col['Key'],
				'default' => $col['Default'],
			);
		}

		return $result;
	}

	/**
	 * Get a custom model by ID.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Model ID.
	 * @return array|null Model data or null if not found.
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$model = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$table,
				$id
			),
			ARRAY_A
		);

		if ( ! $model ) {
			return null;
		}

		// Decode JSON fields.
		$model['post_types'] = json_decode( $model['post_types'] ?? '[]', true ) ?: array();
		$model['taxonomies'] = json_decode( $model['taxonomies'] ?? '[]', true ) ?: array();

		// Get associated translation rules.
		$rules_table = wptsall_table( 'translation_rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE model_id = %d',
				$rules_table,
				$id
			),
			ARRAY_A
		);

		$model['rules'] = array();
		foreach ( $rules as $rule ) {
			$rule['field_capabilities'] = json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array();
			$model['rules'][]           = $rule;
		}

		return $model;
	}

	/**
	 * Get all custom models.
	 *
	 * @since 0.9.0
	 *
	 * @param array $args Query arguments.
	 * @return array Array of models.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;

		$table   = wptsall_table( 'models' );
		$where   = array( '1=1' );
		$prepare = array();

		// Filter by source_type (manual models only by default).
		if ( isset( $args['source_type'] ) ) {
			$where[]   = 'source_type = %s';
			$prepare[] = $args['source_type'];
		}

		// Filter by status.
		if ( isset( $args['status'] ) ) {
			$where[]   = 'status = %s';
			$prepare[] = $args['status'];
		}

		// Filter by plugin_slug.
		if ( isset( $args['plugin_slug'] ) ) {
			$where[]   = 'plugin_slug = %s';
			$prepare[] = $args['plugin_slug'];
		}

		$where_clause = implode( ' AND ', $where );
		$order_by     = 'ORDER BY created_at DESC';

		// Pagination.
		$limit  = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50;
		$offset = isset( $args['page'] ) ? ( absint( $args['page'] ) - 1 ) * $limit : 0;

		// Build query: table via %i; WHERE fragments are fixed placeholders only.
		$sql    = 'SELECT * FROM %i WHERE ' . $where_clause . ' ' . $order_by . ' LIMIT %d OFFSET %d';
		$params = array_merge( array( $table ), $prepare, array( $limit, $offset ) );
		$models = wptsall_db_get_results( $sql, $params, ARRAY_A );

		// Count total (same filters, no LIMIT/OFFSET).
		$count_sql    = 'SELECT COUNT(*) FROM %i WHERE ' . $where_clause;
		$count_params = array_merge( array( $table ), $prepare );
		$total        = (int) wptsall_db_get_var( $count_sql, $count_params );

		// Decode JSON fields.
		foreach ( $models as &$model ) {
			$model['post_types'] = json_decode( $model['post_types'] ?? '[]', true ) ?: array();
			$model['taxonomies'] = json_decode( $model['taxonomies'] ?? '[]', true ) ?: array();
		}

		return array(
			'items' => $models,
			'total' => (int) $total,
		);
	}

	/**
	 * Update a custom model.
	 *
	 * @since 0.9.0
	 *
	 * @param int   $id   Model ID.
	 * @param array $data Data to update.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$table = wptsall_table( 'models' );

		// Check if model exists.
		$existing = self::get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'not_found', __( 'Model not found.', 'wpmmcc-ats' ) );
		}

		// Build update data.
		$update_data   = array();
		$update_format = array();

		$allowed_fields = array(
			'plugin_name'    => '%s',
			'plugin_version' => '%s',
			'text_domain'    => '%s',
			'description'    => '%s',
			'status'         => '%s',
			'usage_status'   => '%s',
		);

		foreach ( $allowed_fields as $field => $format ) {
			if ( isset( $data[ $field ] ) ) {
				$update_data[ $field ] = $data[ $field ];
				$update_format[]       = $format;
			}
		}

		// Handle JSON fields.
		if ( isset( $data['post_types'] ) ) {
			$update_data['post_types'] = wp_json_encode( (array) $data['post_types'] );
			$update_format[]           = '%s';
		}

		if ( isset( $data['taxonomies'] ) ) {
			$update_data['taxonomies'] = wp_json_encode( (array) $data['taxonomies'] );
			$update_format[]           = '%s';
		}

		if ( empty( $update_data ) ) {
			return new \WP_Error( 'no_data', __( 'No data to update.', 'wpmmcc-ats' ) );
		}

		$update_data['updated_at'] = current_time( 'mysql' );
		$update_format[]           = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $id ),
			$update_format,
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update model.', 'wpmmcc-ats' ) );
		}

		wptsall_log(
			'models',
			'info',
			'Custom model updated',
			array(
				'model_id' => $id,
				'fields'   => array_keys( $update_data ),
			)
		);

		return true;
	}

	/**
	 * Delete a custom model.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Model ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$models_table = wptsall_table( 'models' );
		$rules_table  = wptsall_table( 'translation_rules' );
		$chains_table = wptsall_table( 'model_link_chains' );

		// Check if model exists.
		$existing = self::get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'not_found', __( 'Model not found.', 'wpmmcc-ats' ) );
		}

		// Get rule IDs for cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE model_id = %d',
				$rules_table,
				$id
			)
		);

		// Delete link chains for all rules.
		if ( ! empty( $rule_ids ) ) {
			list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $rule_ids );
			wptsall_db_query(
				"DELETE FROM %i WHERE rule_id IN ($in_sql)",
				array_merge( array( $chains_table ), $in_args )
			);
		}

		// Delete translation rules.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $rules_table, array( 'model_id' => $id ), array( '%d' ) );

		// Delete the model.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $models_table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $result ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete model.', 'wpmmcc-ats' ) );
		}

		wptsall_log(
			'models',
			'info',
			'Custom model deleted',
			array(
				'model_id'      => $id,
				'plugin_slug'   => $existing['plugin_slug'],
				'rules_deleted' => count( $rule_ids ),
			)
		);

		return true;
	}

	/**
	 * Set field overrides for a model's rule.
	 *
	 * @since 0.9.0
	 *
	 * @param int   $model_id  Model ID.
	 * @param array $overrides Field overrides (field_capabilities format).
	 * @param int   $rule_id   Optional specific rule ID. If not provided, uses the first rule.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function set_field_overrides( $model_id, $overrides, $rule_id = null ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// Get the rule to update.
		if ( $rule_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rule = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d AND model_id = %d',
					$rules_table,
					$rule_id,
					$model_id
				),
				ARRAY_A
			);
		} else {
			// Get the first (primary) rule for the model.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rule = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE model_id = %d ORDER BY id ASC LIMIT 1',
					$rules_table,
					$model_id
				),
				ARRAY_A
			);
		}

		if ( ! $rule ) {
			return new \WP_Error( 'rule_not_found', __( 'Translation rule not found for this model.', 'wpmmcc-ats' ) );
		}

		// Merge with existing field_capabilities.
		$existing_capabilities = json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array();
		$merged_capabilities   = array_merge( $existing_capabilities, $overrides );

		// Update the rule.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$rules_table,
			array(
				'field_capabilities' => wp_json_encode( $merged_capabilities ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => $rule['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update field overrides.', 'wpmmcc-ats' ) );
		}

		wptsall_log(
			'models',
			'info',
			'Field overrides set',
			array(
				'model_id'     => $model_id,
				'rule_id'      => $rule['id'],
				'field_count'  => count( $overrides ),
			)
		);

		return true;
	}

	/**
	 * Get field overrides for a model's rule.
	 *
	 * @since 0.9.0
	 *
	 * @param int $model_id Model ID.
	 * @param int $rule_id  Optional specific rule ID. If not provided, uses the first rule.
	 * @return array|WP_Error Field capabilities array or WP_Error on failure.
	 */
	public static function get_field_overrides( $model_id, $rule_id = null ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// Get the rule.
		if ( $rule_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rule = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT field_capabilities FROM %i WHERE id = %d AND model_id = %d',
					$rules_table,
					$rule_id,
					$model_id
				),
				ARRAY_A
			);
		} else {
			// Get the first (primary) rule for the model.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rule = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT field_capabilities FROM %i WHERE model_id = %d ORDER BY id ASC LIMIT 1',
					$rules_table,
					$model_id
				),
				ARRAY_A
			);
		}

		if ( ! $rule ) {
			return new \WP_Error( 'rule_not_found', __( 'Translation rule not found for this model.', 'wpmmcc-ats' ) );
		}

		return json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array();
	}

	/**
	 * Build field_capabilities from separate field arrays.
	 *
	 * Converts legacy format (separate arrays) to v0.8.0 unified format.
	 *
	 * @since 0.8.0
	 *
	 * @param array $translate_fields Translate fields array.
	 * @param array $sync_fields      Sync fields array.
	 * @param array $field_mappings   Field mappings array.
	 * @param array $compute_fields   Compute fields array.
	 * @return array Unified field_capabilities structure.
	 */
	private static function build_field_capabilities_from_arrays( $translate_fields, $sync_fields, $field_mappings, $compute_fields ) {
		$capabilities = array();

		// Translate fields.
		foreach ( (array) $translate_fields as $field ) {
			if ( is_string( $field ) && ! empty( $field ) ) {
				$capabilities[ $field ] = array(
					'type'      => 'translate',
					'direction' => 'one_way',
					'enabled'   => true,
				);
			}
		}

		// Sync fields.
		foreach ( (array) $sync_fields as $field ) {
			if ( is_string( $field ) && ! empty( $field ) ) {
				$capabilities[ $field ] = array(
					'type'      => 'sync',
					'direction' => 'one_way',
					'enabled'   => true,
				);
			}
		}

		// Mapping fields.
		if ( is_array( $field_mappings ) ) {
			foreach ( $field_mappings as $field => $type ) {
				$field_name = is_string( $field ) ? $field : $type;
				if ( ! empty( $field_name ) ) {
					$entry = array(
						'type'      => 'id_mapping',
						'direction' => 'one_way',
						'enabled'   => true,
					);

					// Include v2 keys (reference_type, value_format, reference_target) when provided.
					if ( is_array( $type ) ) {
						if ( isset( $type['reference_type'] ) ) {
							$entry['reference_type'] = $type['reference_type'];
						}
						if ( isset( $type['value_format'] ) ) {
							$entry['value_format'] = $type['value_format'];
						}
						if ( isset( $type['reference_target'] ) ) {
							$entry['reference_target'] = $type['reference_target'];
						}
					}

					$capabilities[ $field_name ] = $entry;
				}
			}
		}

		// Compute fields.
		foreach ( (array) $compute_fields as $field ) {
			if ( is_string( $field ) && ! empty( $field ) ) {
				$capabilities[ $field ] = array(
					'type'    => 'compute',
					'enabled' => true,
				);
			}
		}

		return $capabilities;
	}
}
