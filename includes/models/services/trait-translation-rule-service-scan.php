<?php
/**
 * Translation Rule Service scan/auto-rule helpers.
 *
 * @package WPTSALL
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
trait Translation_Rule_Service_Scan_Trait {
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter


	/**
	 * Incrementally update model (only changed parts).
	 *
	 * @param int   $model_id Model ID.
	 * @param array $scan_result Scan results.
	 * @return array
	 */
	public static function incremental_update( $model_id, $scan_result ) {
		$changes = array(
			'updated_fields' => array(),
			'added_rules'    => 0,
			'updated_rules'  => 0,
			'removed_rules'  => 0,
		);

		$existing_model = self::get_model( $model_id );
		if ( ! $existing_model ) {
			return $changes;
		}

		$new_model_data = $scan_result['model'] ?? array();
		$new_rules      = $scan_result['rules'] ?? array();
		$update_data    = array();

		if ( ! empty( $new_model_data['plugin_version'] ) && $new_model_data['plugin_version'] !== $existing_model['plugin_version'] ) {
			$update_data['plugin_version'] = $new_model_data['plugin_version'];
			$changes['updated_fields'][]   = 'plugin_version';
		}

		if ( ! empty( $new_model_data['plugin_name'] ) && $new_model_data['plugin_name'] !== $existing_model['plugin_name'] ) {
			$update_data['plugin_name']  = $new_model_data['plugin_name'];
			$changes['updated_fields'][] = 'plugin_name';
		}

		$old_post_types = $existing_model['post_types'] ?? array();
		$new_post_types = $new_model_data['post_types'] ?? array();
		if ( array_diff( $old_post_types, $new_post_types ) || array_diff( $new_post_types, $old_post_types ) ) {
			$update_data['post_types']   = $new_post_types;
			$changes['updated_fields'][] = 'post_types';
		}

		$old_taxonomies = $existing_model['taxonomies'] ?? array();
		$new_taxonomies = $new_model_data['taxonomies'] ?? array();
		if ( array_diff( $old_taxonomies, $new_taxonomies ) || array_diff( $new_taxonomies, $old_taxonomies ) ) {
			$update_data['taxonomies']   = $new_taxonomies;
			$changes['updated_fields'][] = 'taxonomies';
		}

		$update_data['last_scanned'] = current_time( 'mysql' );
		$update_data['scan_version'] = $new_model_data['scan_version'] ?? '';

		if ( ! empty( $update_data ) ) {
			self::update_model( $model_id, $update_data );
		}

		$existing_rules     = self::get_model_rules( $model_id );
		$existing_rules_map = array();
		foreach ( $existing_rules as $rule ) {
			$key                      = $rule['url_pattern'] . '|' . $rule['object_name'];
			$existing_rules_map[ $key ] = $rule;
		}

		$new_rule_keys = array();
		foreach ( $new_rules as $new_rule ) {
			$key             = ( $new_rule['url_pattern'] ?? '' ) . '|' . ( $new_rule['object_name'] ?? '' );
			$new_rule_keys[] = $key;

			if ( isset( $existing_rules_map[ $key ] ) ) {
				$existing_rule = $existing_rules_map[ $key ];
				// User-edited rules (auto_detected=0) are authoritative.
				if ( empty( $existing_rule['auto_detected'] ) ) {
					continue;
				}
				if ( self::rule_has_changes( $existing_rule, $new_rule ) ) {
					self::update_rule( $existing_rule['id'], $new_rule );
					$changes['updated_rules']++;
				}
			} else {
				self::create_rule( $model_id, $new_rule );
				$changes['added_rules']++;
			}
		}

		foreach ( $existing_rules as $rule ) {
			$key = $rule['url_pattern'] . '|' . $rule['object_name'];
			if ( ! in_array( $key, $new_rule_keys, true ) && $rule['auto_detected'] ) {
				self::delete_rule( $rule['id'] );
				$changes['removed_rules']++;
			}
		}

		wptsall_log(
			'model',
			'info',
			'Model incremental update completed',
			array(
				'model_id' => $model_id,
				'changes'  => $changes,
			)
		);

		return $changes;
	}

	/**
	 * Check if rule has changes.
	 *
	 * @param array $old_rule Existing rule.
	 * @param array $new_rule New rule.
	 * @return bool
	 */
	private static function rule_has_changes( $old_rule, $new_rule ) {
		$compare_fields = array(
			'url_type',
			'data_type',
			'primary_table',
			'meta_table',
			'backend_edit',
			'backend_list',
			'direction',
			'sync_mode',
		);

		foreach ( $compare_fields as $field ) {
			$old_value = $old_rule[ $field ] ?? '';
			$new_value = $new_rule[ $field ] ?? '';

			if ( $old_value !== $new_value ) {
				return true;
			}
		}

		$json_fields = array( 'field_capabilities', 'related_taxonomies' );
		foreach ( $json_fields as $field ) {
			$old_data = $old_rule[ $field ] ?? array();
			$new_data = $new_rule[ $field ] ?? array();

			if ( wp_json_encode( $old_data ) !== wp_json_encode( $new_data ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create rules for a model.
	 *
	 * @param int   $model_id Model ID.
	 * @param array $options Options.
	 * @return array
	 */
	public static function create_rules_for_model( $model_id, $options = array() ) {
		$defaults = array(
			'skip_existing'         => true,
			'post_types'            => array(),
			'complete_dependencies' => true,
		);
		$options = wp_parse_args( $options, $defaults );

		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
			'details' => array(),
		);

		$model = Plugin_Mapping_Service::get_by_id( $model_id );
		if ( ! $model ) {
			wptsall_log_error(
				'models-service',
				'create_rules_for_model: Model not found',
				array( 'model_id' => $model_id )
			);
			return $result;
		}

		$plugin_slug   = $model['plugin_slug'];
		$post_types    = $model['post_types'] ?? array();
		$taxonomies    = $model['taxonomies'] ?? array();
		$model_objects = Model_Object_Service::get_objects_for_model( $model_id );

		$post_type_name_set = array();
		foreach ( $post_types as $pt_data ) {
			$pt_name = is_array( $pt_data ) ? ( $pt_data['name'] ?? '' ) : $pt_data;
			$pt_name = sanitize_key( (string) $pt_name );
			if ( '' !== $pt_name ) {
				$post_type_name_set[ $pt_name ] = true;
			}
		}

		$taxonomy_name_set = array();
		foreach ( $taxonomies as $tax_data ) {
			$tax_name = is_array( $tax_data ) ? ( $tax_data['name'] ?? '' ) : $tax_data;
			$tax_name = sanitize_key( (string) $tax_name );
			if ( '' !== $tax_name ) {
				$taxonomy_name_set[ $tax_name ] = true;
			}
		}

		foreach ( $model_objects as $obj ) {
			$object_type = sanitize_key( (string) ( $obj['object_type'] ?? '' ) );
			$object_name = sanitize_key( (string) ( $obj['object_name'] ?? '' ) );
			if ( '' === $object_name ) {
				continue;
			}

			if ( 'post_type' === $object_type && ! isset( $post_type_name_set[ $object_name ] ) ) {
				$pt_obj       = get_post_type_object( $object_name );
				$post_types[] = array(
					'name'               => $object_name,
					'public'             => $pt_obj ? (bool) $pt_obj->public : false,
					'publicly_queryable' => $pt_obj ? (bool) $pt_obj->publicly_queryable : false,
					'show_ui'            => $pt_obj ? (bool) $pt_obj->show_ui : false,
					'rewrite'            => $pt_obj ? $pt_obj->rewrite : false,
				);
				$post_type_name_set[ $object_name ] = true;
			} elseif ( 'taxonomy' === $object_type && ! isset( $taxonomy_name_set[ $object_name ] ) ) {
				$tax_obj       = get_taxonomy( $object_name );
				$taxonomies[]  = array(
					'name'               => $object_name,
					'public'             => $tax_obj ? (bool) $tax_obj->public : false,
					'publicly_queryable' => $tax_obj ? (bool) $tax_obj->publicly_queryable : false,
					'show_ui'            => $tax_obj ? (bool) $tax_obj->show_ui : false,
					'rewrite'            => $tax_obj ? $tax_obj->rewrite : false,
				);
				$taxonomy_name_set[ $object_name ] = true;
			}
		}

		if ( empty( $post_types ) && empty( $taxonomies ) ) {
			wptsall_log_debug(
				'models-service',
				'create_rules_for_model: No post_types/taxonomies in model',
				array(
					'model_id'    => $model_id,
					'plugin_slug' => $plugin_slug,
				)
			);
			return $result;
		}

		wptsall_log_info(
			'models-service',
			'create_rules_for_model: Starting',
			array(
				'model_id'         => $model_id,
				'plugin_slug'      => $plugin_slug,
				'post_types_count' => count( $post_types ),
				'taxonomies_count' => count( $taxonomies ),
			)
		);

		$taxonomy_names = array();
		foreach ( $taxonomies as $tax_data ) {
			$tax_name = is_array( $tax_data ) ? ( $tax_data['name'] ?? '' ) : $tax_data;
			if ( ! empty( $tax_name ) ) {
				$taxonomy_names[] = $tax_name;
			}
		}

		$scan_result_raw  = $model['scan_result'] ?? '';
		$scan_result_data = ! empty( $scan_result_raw )
			? ( is_array( $scan_result_raw ) ? $scan_result_raw : json_decode( $scan_result_raw, true ) )
			: null;
		$read_source      = wptsall_get_ssot_read_source();

		if ( is_array( $scan_result_data ) && ! empty( $scan_result_data ) ) {
			$sync_service = new Template_Sync_Service();
			$sync_diff    = $sync_service->sync_scan_to_objects( $model_id, $scan_result_data, 'incremental' );
			wptsall_log_info(
				'models-service',
				'create_rules_for_model: merged scan fields into plugin template before rule generation',
				array(
					'model_id'  => $model_id,
					'source'    => $read_source,
					'added'     => count( $sync_diff['added'] ?? array() ),
					'orphan'    => count( $sync_diff['orphan'] ?? array() ),
					'unchanged' => (int) ( $sync_diff['unchanged'] ?? 0 ),
				)
			);
		}

		$model_objects = Model_Object_Service::get_objects_for_model( $model_id );
		foreach ( $model_objects as $obj ) {
			$object_type = sanitize_key( (string) ( $obj['object_type'] ?? '' ) );
			$object_name = sanitize_key( (string) ( $obj['object_name'] ?? '' ) );
			if ( '' === $object_name ) {
				continue;
			}

			if ( 'post_type' === $object_type && ! isset( $post_type_name_set[ $object_name ] ) ) {
				$pt_obj       = get_post_type_object( $object_name );
				$post_types[] = array(
					'name'               => $object_name,
					'public'             => $pt_obj ? (bool) $pt_obj->public : false,
					'publicly_queryable' => $pt_obj ? (bool) $pt_obj->publicly_queryable : false,
					'show_ui'            => $pt_obj ? (bool) $pt_obj->show_ui : false,
					'rewrite'            => $pt_obj ? $pt_obj->rewrite : false,
				);
				$post_type_name_set[ $object_name ] = true;
			} elseif ( 'taxonomy' === $object_type && ! isset( $taxonomy_name_set[ $object_name ] ) ) {
				$tax_obj      = get_taxonomy( $object_name );
				$taxonomies[] = array(
					'name'               => $object_name,
					'public'             => $tax_obj ? (bool) $tax_obj->public : false,
					'publicly_queryable' => $tax_obj ? (bool) $tax_obj->publicly_queryable : false,
					'show_ui'            => $tax_obj ? (bool) $tax_obj->show_ui : false,
					'rewrite'            => $tax_obj ? $tax_obj->rewrite : false,
				);
				$taxonomy_name_set[ $object_name ] = true;
			}
		}

		$fields_from_objects = self::build_capabilities_from_objects( $model_id );

		if ( 'dual' === $read_source && is_array( $scan_result_data ) && ! empty( $scan_result_data['rules_summary'] ) ) {
			$fields_from_scan = array();
			foreach ( $scan_result_data['rules_summary'] as $summary ) {
				$obj_name = $summary['object_name'] ?? '';
				if ( ! empty( $obj_name ) && ! empty( $summary['field_capabilities'] ) ) {
					$fields_from_scan[ $obj_name ] = $summary['field_capabilities'];
				}
			}
			$diff = self::compare_capabilities( $fields_from_objects, $fields_from_scan );
			if ( ! empty( $diff ) && function_exists( 'wptsall_log_warning' ) ) {
				wptsall_log_warning(
					'models-ssot',
					'Dual-read diff detected after merge (template remains authoritative)',
					array(
						'model_id' => $model_id,
						'diff'     => $diff,
					)
				);
			}
		}

		foreach ( $post_types as $pt_data ) {
			$pt_name = is_array( $pt_data ) ? ( $pt_data['name'] ?? '' ) : $pt_data;
			if ( empty( $pt_name ) ) {
				continue;
			}

			if ( ! empty( $options['post_types'] ) && ! in_array( $pt_name, $options['post_types'], true ) ) {
				continue;
			}

			if ( ! self::is_publicly_accessible( $pt_name, $pt_data ) ) {
				$result['details'][] = array(
					'post_type' => $pt_name,
					'status'    => 'skipped',
					'reason'    => 'not_publicly_accessible',
				);
				++$result['skipped'];
				continue;
			}

			$related_taxonomies = self::discover_related_taxonomies( $pt_name, $taxonomy_names );

			if ( $options['skip_existing'] && self::rule_exists( $model_id, 'post', $pt_name, 'single' ) ) {
				$existing_rule = self::get_rule_by_post_type( $model_id, $pt_name );

				if ( ! empty( $related_taxonomies ) && $existing_rule && empty( $existing_rule['related_taxonomies'] ) ) {
					self::update_rule(
						(int) $existing_rule['id'],
						array( 'related_taxonomies' => $related_taxonomies )
					);
				}

				$appended_fields = array();
				$removed_fields  = array();
				if ( $existing_rule && isset( $existing_rule['field_capabilities'] ) && is_array( $existing_rule['field_capabilities'] ) ) {
					$template_field_capabilities = is_array( $fields_from_objects[ $pt_name ] ?? null )
						? $fields_from_objects[ $pt_name ]
						: array();
					$template_field_capabilities = self::ensure_v3_format( $template_field_capabilities, 'post' );

					$user_locked = empty( $existing_rule['auto_detected'] );
					if ( $user_locked ) {
						// Draft scan may only append missing keys; never rewrite user types.
						$merge_result    = self::merge_rule_with_template_append_only(
							$existing_rule['field_capabilities'],
							$template_field_capabilities,
							'post'
						);
						$appended_fields = $merge_result['added_fields'];
						$removed_fields  = array();
					} else {
					$existing_constraint = self::constrain_rule_fields_to_template(
						$model_id,
						'post',
						$pt_name,
						$existing_rule['field_capabilities'],
						'create_rules_for_model_existing_post'
					);
					$removed_fields      = $existing_constraint['removed_fields'];

					$merge_result    = self::merge_rule_with_template_append_only(
						$existing_constraint['field_capabilities'],
						$template_field_capabilities,
						'post'
					);
					$appended_fields = $merge_result['added_fields'];
					}

					if ( ! empty( $appended_fields ) || ! empty( $removed_fields ) ) {
						$update_payload = array( 'field_capabilities' => $merge_result['field_capabilities'] );
						if ( $user_locked ) {
							$update_payload['auto_detected'] = 0;
						}
						$update_result = self::update_rule(
							(int) $existing_rule['id'],
							$update_payload
						);
						if ( is_wp_error( $update_result ) ) {
							++$result['errors'];
							wptsall_log_error(
								'models-service',
								'Failed to refresh existing post rule capabilities',
								array(
									'model_id'       => $model_id,
									'post_type'      => $pt_name,
									'rule_id'        => (int) $existing_rule['id'],
									'appended_fields'=> array_values( $appended_fields ),
									'removed_fields' => array_values( $removed_fields ),
									'error'          => $update_result->get_error_message(),
								)
							);
						} else {
							++$result['updated'];
						}
					}
				}

				self::ensure_term_rules_for_post_type( $model_id, $pt_name, $related_taxonomies, $plugin_slug );

				$result['details'][] = array(
					'post_type'       => $pt_name,
					'status'          => 'skipped',
					'reason'          => 'already_exists',
					'appended_fields' => array_values( $appended_fields ),
					'removed_fields'  => array_values( $removed_fields ),
				);
				++$result['skipped'];
				continue;
			}

			$url_pattern        = self::generate_url_pattern( $pt_name, $pt_data );
			$field_capabilities = is_array( $fields_from_objects[ $pt_name ] ?? null )
				? $fields_from_objects[ $pt_name ]
				: array();
			$field_capabilities = self::ensure_v3_format( $field_capabilities );

			$constraint         = self::constrain_rule_fields_to_template(
				$model_id,
				'post',
				$pt_name,
				$field_capabilities,
				'create_rules_for_model_post'
			);
			$field_capabilities = $constraint['field_capabilities'];
			if ( empty( $field_capabilities ) ) {
				$result['details'][] = array(
					'post_type' => $pt_name,
					'status'    => 'skipped',
					'reason'    => 'no_template_fields',
				);
				++$result['skipped'];
				continue;
			}

			$pt_obj_runtime = get_post_type_object( $pt_name );
			$requires_login = $pt_obj_runtime ? ! $pt_obj_runtime->publicly_queryable : false;

			$rule_data = array(
				'name'               => sprintf( '%s (%s)', ucfirst( $pt_name ), $plugin_slug ),
				'url_pattern'        => $url_pattern,
				'url_type'           => 'single',
				'requires_login'     => $requires_login,
				'data_type'          => 'post',
				'object_name'        => $pt_name,
				'direction'          => 'one_way',
				'sync_mode'          => 'new_only',
				'field_capabilities' => $field_capabilities,
				'related_taxonomies' => $related_taxonomies,
				'auto_detected'      => true,
				'is_active'          => true,
			);

			$pre_validation = self::validate_rule_data_before_save(
				array_merge(
					$rule_data,
					array( 'model_id' => $model_id )
				)
			);
			if ( ! $pre_validation['valid'] ) {
				wptsall_log_error(
					'models-service',
					'Pre-save validation blocked rule creation',
					array(
						'post_type' => $pt_name,
						'errors'    => $pre_validation['errors'],
					)
				);
				$result['details'][] = array(
					'post_type' => $pt_name,
					'status'    => 'validation_blocked',
					'errors'    => $pre_validation['errors'],
				);
				++$result['errors'];
				continue;
			}

			$rule_id = self::create_rule( $model_id, $rule_data );
			if ( is_wp_error( $rule_id ) ) {
				$result['details'][] = array(
					'post_type' => $pt_name,
					'status'    => 'error',
					'reason'    => $rule_id->get_error_message(),
				);
				++$result['errors'];
			} else {
				$result['details'][] = array(
					'post_type' => $pt_name,
					'status'    => 'created',
					'rule_id'   => $rule_id,
				);
				++$result['created'];

				self::ensure_term_rules_for_post_type( $model_id, $pt_name, $related_taxonomies, $plugin_slug );

				if ( class_exists( __NAMESPACE__ . '\\Rule_Validation_Service' ) ) {
					$rvs              = new Rule_Validation_Service();
					$chain_validation = $rvs->validate_all( (int) $rule_id );
					if ( ! $chain_validation['valid'] && function_exists( 'wptsall_log_warning' ) ) {
						wptsall_log_warning(
							'models',
							"Rule chain incomplete for {$pt_name}",
							array(
								'rule_id'  => $rule_id,
								'errors'   => $chain_validation['errors'],
								'warnings' => $chain_validation['warnings'],
							)
						);
					}
				}

				$sim_validator   = new Simulation_Validator();
				$rule_validation = $sim_validator->validate_rule( $rule_id );
				if ( ! $rule_validation['valid'] && function_exists( 'wptsall_log_warning' ) ) {
					wptsall_log_warning(
						'models',
						'Auto-created rule has validation warnings',
						array(
							'rule_id' => $rule_id,
							'errors'  => $rule_validation['errors'],
						)
					);
				}
				$result['details'][ count( $result['details'] ) - 1 ]['validation'] = $rule_validation;
			}
		}

		foreach ( $taxonomies as $tax_data ) {
			$tax_name = is_array( $tax_data ) ? ( $tax_data['name'] ?? '' ) : $tax_data;

			if ( empty( $tax_name ) ) {
				continue;
			}

			if ( ! self::is_taxonomy_publicly_accessible( $tax_name, $tax_data ) ) {
				$result['details'][] = array(
					'taxonomy' => $tax_name,
					'status'   => 'skipped',
					'reason'   => 'not_publicly_accessible',
				);
				++$result['skipped'];
				continue;
			}

			if ( $options['skip_existing'] && self::rule_exists( $model_id, 'term', $tax_name, 'taxonomy' ) ) {
				$existing_tax_rule = self::get_rule_by_taxonomy( $tax_name, $model_id );
				$appended_fields   = array();
				$removed_fields    = array();
				if ( $existing_tax_rule && isset( $existing_tax_rule['field_capabilities'] ) && is_array( $existing_tax_rule['field_capabilities'] ) ) {
					$template_field_capabilities = is_array( $fields_from_objects[ $tax_name ] ?? null )
						? $fields_from_objects[ $tax_name ]
						: array();
					$template_field_capabilities = self::ensure_v3_format( $template_field_capabilities, 'term' );

					$existing_constraint = self::constrain_rule_fields_to_template(
						$model_id,
						'term',
						$tax_name,
						$existing_tax_rule['field_capabilities'],
						'create_rules_for_model_existing_term'
					);
					$removed_fields      = $existing_constraint['removed_fields'];

					$merge_result    = self::merge_rule_with_template_append_only(
						$existing_constraint['field_capabilities'],
						$template_field_capabilities,
						'term'
					);
					$appended_fields = $merge_result['added_fields'];

					if ( ! empty( $appended_fields ) || ! empty( $removed_fields ) ) {
						$update_result = self::update_rule(
							(int) $existing_tax_rule['id'],
							array( 'field_capabilities' => $merge_result['field_capabilities'] )
						);
						if ( is_wp_error( $update_result ) ) {
							++$result['errors'];
							wptsall_log_error(
								'models-service',
								'Failed to refresh existing term rule capabilities',
								array(
									'model_id'       => $model_id,
									'taxonomy'       => $tax_name,
									'rule_id'        => (int) $existing_tax_rule['id'],
									'appended_fields'=> array_values( $appended_fields ),
									'removed_fields' => array_values( $removed_fields ),
									'error'          => $update_result->get_error_message(),
								)
							);
						} else {
							++$result['updated'];
						}
					}
				}

				$result['details'][] = array(
					'taxonomy'        => $tax_name,
					'status'          => 'skipped',
					'reason'          => 'already_exists',
					'appended_fields' => array_values( $appended_fields ),
					'removed_fields'  => array_values( $removed_fields ),
				);
				++$result['skipped'];
				continue;
			}

			$url_pattern        = self::generate_taxonomy_url_pattern( $tax_name, $tax_data );
			$field_capabilities = is_array( $fields_from_objects[ $tax_name ] ?? null )
				? $fields_from_objects[ $tax_name ]
				: array();
			$field_capabilities = self::ensure_v3_format( $field_capabilities, 'term' );

			$constraint         = self::constrain_rule_fields_to_template(
				$model_id,
				'term',
				$tax_name,
				$field_capabilities,
				'create_rules_for_model_tax'
			);
			$field_capabilities = $constraint['field_capabilities'];
			if ( empty( $field_capabilities ) ) {
				$result['details'][] = array(
					'taxonomy' => $tax_name,
					'status'   => 'skipped',
					'reason'   => 'no_template_fields',
				);
				++$result['skipped'];
				continue;
			}

			$tax_obj_runtime   = get_taxonomy( $tax_name );
			$tax_requires_login = $tax_obj_runtime ? ! $tax_obj_runtime->publicly_queryable : false;

			$rule_data = array(
				'name'               => sprintf( '%s (%s)', ucfirst( $tax_name ), $plugin_slug ),
				'url_pattern'        => $url_pattern,
				'url_type'           => 'taxonomy',
				'requires_login'     => $tax_requires_login,
				'data_type'          => 'term',
				'object_name'        => $tax_name,
				'direction'          => 'one_way',
				'sync_mode'          => 'new_only',
				'field_capabilities' => $field_capabilities,
				'auto_detected'      => true,
				'is_active'          => true,
			);

			$pre_validation = self::validate_rule_data_before_save(
				array_merge(
					$rule_data,
					array( 'model_id' => $model_id )
				)
			);
			if ( ! $pre_validation['valid'] ) {
				wptsall_log_error(
					'models-service',
					'Pre-save validation blocked taxonomy rule creation',
					array(
						'taxonomy' => $tax_name,
						'errors'   => $pre_validation['errors'],
					)
				);
				$result['details'][] = array(
					'taxonomy' => $tax_name,
					'status'   => 'validation_blocked',
					'errors'   => $pre_validation['errors'],
				);
				++$result['errors'];
				continue;
			}

			$rule_id = self::create_rule( $model_id, $rule_data );
			if ( is_wp_error( $rule_id ) ) {
				$result['details'][] = array(
					'taxonomy' => $tax_name,
					'status'   => 'error',
					'reason'   => $rule_id->get_error_message(),
				);
				++$result['errors'];
			} else {
				$result['details'][] = array(
					'taxonomy' => $tax_name,
					'status'   => 'created',
					'rule_id'  => $rule_id,
				);
				++$result['created'];

				$sim_validator   = new Simulation_Validator();
				$rule_validation = $sim_validator->validate_rule( $rule_id );
				if ( ! $rule_validation['valid'] && function_exists( 'wptsall_log_warning' ) ) {
					wptsall_log_warning(
						'models',
						'Auto-created taxonomy rule has validation warnings',
						array(
							'rule_id' => $rule_id,
							'errors'  => $rule_validation['errors'],
						)
					);
				}
				$result['details'][ count( $result['details'] ) - 1 ]['validation'] = $rule_validation;
			}
		}

		if ( ! empty( $options['complete_dependencies'] ) ) {
			self::ensure_dependency_target_rules(
				$model_id,
				$plugin_slug,
				$taxonomy_names,
				$result
			);
		}

		wptsall_log_info(
			'models-service',
			'create_rules_for_model: Completed',
			array(
				'model_id'    => $model_id,
				'plugin_slug' => $plugin_slug,
				'created'     => $result['created'],
				'skipped'     => $result['skipped'],
				'errors'      => $result['errors'],
			)
		);

		return $result;
	}

	/**
	 * Check if a post_type is publicly accessible.
	 *
	 * @param string $post_type Post type name.
	 * @param array  $pt_data Optional detailed post_type data.
	 * @return bool
	 */
	public static function is_publicly_accessible( $post_type, $pt_data = array() ) {
		$excluded = \WPTSALL\Models\Services\Plugin_Scanner::get_excluded_post_types();
		$excluded = array_merge(
			$excluded,
			array(
				'revision',
				'nav_menu_item',
				'shop_order_placehold',
				'acf-field',
			)
		);

		if ( in_array( $post_type, $excluded, true ) ) {
			return false;
		}

		if ( post_type_exists( $post_type ) ) {
			return true;
		}

		if ( is_array( $pt_data ) && ! empty( $pt_data['name'] ?? '' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if a translation rule already exists.
	 *
	 * @param int    $model_id Model ID.
	 * @param string $data_type Data type.
	 * @param string $object_name Object name.
	 * @param string $url_type URL type.
	 * @param bool   $active_only Only match active rules.
	 * @return bool
	 */
	public static function rule_exists( $model_id, $data_type, $object_name, $url_type, $active_only = false ) {
		global $wpdb;

		$table = wptsall_table( 'translation_rules' );
		$sql   = 'SELECT id FROM %i WHERE model_id = %d AND object_name = %s AND data_type = %s AND url_type = %s';
		if ( $active_only ) {
			$sql .= ' AND is_active = 1';
		}
		$sql .= ' LIMIT 1';

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				$sql,
				$table,
				$model_id,
				$object_name,
				$data_type,
				$url_type
			)
		);

		return ! empty( $exists );
	}

	/**
	 * Generate URL pattern for a post_type.
	 *
	 * @param string $post_type Post type.
	 * @param array  $pt_data Post type data.
	 * @return string
	 */
	public static function generate_url_pattern( $post_type, $pt_data = array() ) {
		return '?post_type=' . rawurlencode( (string) $post_type ) . '&p={id}';
	}

	/**
	 * Generate URL pattern for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $tax_data Taxonomy data.
	 * @return string
	 */
	public static function generate_taxonomy_url_pattern( $taxonomy, $tax_data = array() ) {
		return '?taxonomy=' . rawurlencode( (string) $taxonomy ) . '&term={term}';
	}

	/**
	 * Check if a taxonomy is publicly accessible.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $tax_data Optional taxonomy data.
	 * @return bool
	 */
	public static function is_taxonomy_publicly_accessible( $taxonomy, $tax_data = array() ) {
		$excluded = array(
			'nav_menu',
			'link_category',
			'post_format',
			'wp_theme',
		);

		if ( in_array( $taxonomy, $excluded, true ) ) {
			return false;
		}

		if ( taxonomy_exists( $taxonomy ) ) {
			return true;
		}

		if ( is_array( $tax_data ) && ! empty( $tax_data['name'] ?? '' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Ensure term rules exist for a post_type's related taxonomies.
	 *
	 * @param int    $model_id Model ID.
	 * @param string $post_type Post type name.
	 * @param array  $taxonomies Taxonomies.
	 * @param string $plugin_slug Plugin slug.
	 * @return void
	 */
	public static function ensure_term_rules_for_post_type( int $model_id, string $post_type, array $taxonomies, string $plugin_slug = '' ): void {
		if ( empty( $taxonomies ) ) {
			return;
		}

		foreach ( $taxonomies as $tax_name ) {
			if ( self::rule_exists( $model_id, 'term', $tax_name, 'taxonomy' ) ) {
				continue;
			}

			$tax_obj = get_taxonomy( $tax_name );
			if ( $tax_obj && ! $tax_obj->public ) {
				continue;
			}

			$url_pattern        = '?taxonomy=' . $tax_name . '&term={slug}';
			$object_caps_map    = self::build_capabilities_from_objects( $model_id );
			$field_capabilities = is_array( $object_caps_map[ $tax_name ] ?? null )
				? $object_caps_map[ $tax_name ]
				: array();

			if ( empty( $field_capabilities ) ) {
				wptsall_log_warning(
					'models-service',
					'Skipped auto-create term rule: taxonomy has no template fields',
					array(
						'model_id'  => $model_id,
						'post_type' => $post_type,
						'taxonomy'  => $tax_name,
					)
				);
				continue;
			}

			$field_capabilities = self::ensure_v3_format( $field_capabilities, 'term' );
			$rule_data          = array(
				'name'               => sprintf( '%s (%s)', ucfirst( $tax_name ), $plugin_slug ?: 'auto' ),
				'url_pattern'        => $url_pattern,
				'url_type'           => 'taxonomy',
				'data_type'          => 'term',
				'object_name'        => $tax_name,
				'direction'          => 'one_way',
				'sync_mode'          => 'new_only',
				'field_capabilities' => $field_capabilities,
				'auto_detected'      => true,
				'is_active'          => true,
			);

			$rule_id = self::create_rule( $model_id, $rule_data );

			if ( is_wp_error( $rule_id ) ) {
				wptsall_log_warning(
					'models-service',
					'Failed to auto-create term rule for post_type taxonomy',
					array(
						'model_id'  => $model_id,
						'post_type' => $post_type,
						'taxonomy'  => $tax_name,
						'error'     => $rule_id->get_error_message(),
					)
				);
			} else {
				wptsall_log_info(
					'models-service',
					'Auto-created term rule for post_type taxonomy',
					array(
						'model_id'  => $model_id,
						'post_type' => $post_type,
						'taxonomy'  => $tax_name,
						'rule_id'   => $rule_id,
					)
				);

				$sim_validator   = new Simulation_Validator();
				$rule_validation = $sim_validator->validate_rule( $rule_id );
				if ( ! $rule_validation['valid'] && function_exists( 'wptsall_log_warning' ) ) {
					wptsall_log_warning(
						'models',
						'Auto-created term rule has validation warnings',
						array(
							'rule_id'  => $rule_id,
							'taxonomy' => $tax_name,
							'errors'   => $rule_validation['errors'],
						)
					);
				}
			}
		}
	}

	/**
	 * Ensure missing rules are auto-created for resolved dependency targets.
	 *
	 * @param int    $model_id Model ID.
	 * @param string $plugin_slug Plugin slug.
	 * @param array  $taxonomy_names Taxonomy names.
	 * @param array  $result Mutable result payload.
	 * @return void
	 */
	private static function ensure_dependency_target_rules( int $model_id, string $plugin_slug, array $taxonomy_names, array &$result ): void {
		if ( $model_id <= 0 || ! class_exists( __NAMESPACE__ . '\\Model_Object_Dependency_Service' ) ) {
			return;
		}

		$dependency_sync = Model_Object_Dependency_Service::rebuild_for_model( $model_id );
		$dependencies    = Model_Object_Dependency_Service::get_model_dependencies( $model_id );
		if ( empty( $dependencies ) ) {
			return;
		}

		$object_caps_map = self::build_capabilities_from_objects( $model_id );
		$seen_targets    = array();
		$created_count   = 0;

		foreach ( $dependencies as $dep ) {
			$status             = sanitize_key( (string) ( $dep['status'] ?? '' ) );
			$target_object_type = sanitize_key( (string) ( $dep['target_object_type'] ?? '' ) );
			$target_object_name = sanitize_key( (string) ( $dep['target_object_name'] ?? '' ) );

			if ( 'resolved' !== $status || '' === $target_object_type || '' === $target_object_name ) {
				continue;
			}

			$rule_data_type = '';
			$url_type       = '';
			$v3_data_type   = 'post';

			if ( 'post_type' === $target_object_type ) {
				$rule_data_type = 'post';
				$url_type       = 'single';
				$v3_data_type   = 'post';
			} elseif ( 'taxonomy' === $target_object_type ) {
				$rule_data_type = 'term';
				$url_type       = 'taxonomy';
				$v3_data_type   = 'term';
			} else {
				continue;
			}

			$target_key = $rule_data_type . ':' . $target_object_name . ':' . $url_type;
			if ( isset( $seen_targets[ $target_key ] ) ) {
				continue;
			}
			$seen_targets[ $target_key ] = true;

			if ( self::rule_exists( $model_id, $rule_data_type, $target_object_name, $url_type ) ) {
				++$result['skipped'];
				$result['details'][] = array(
					'object_name'       => $target_object_name,
					'data_type'         => $rule_data_type,
					'status'            => 'skipped',
					'reason'            => 'dependency_target_rule_exists',
					'dependency_source' => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
				);
				continue;
			}

			if ( 'post' === $rule_data_type && ! self::is_publicly_accessible( $target_object_name ) ) {
				++$result['skipped'];
				$result['details'][] = array(
					'object_name'       => $target_object_name,
					'data_type'         => $rule_data_type,
					'status'            => 'skipped',
					'reason'            => 'dependency_target_not_publicly_accessible',
					'dependency_source' => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
				);
				continue;
			}

			if ( 'term' === $rule_data_type && ! self::is_taxonomy_publicly_accessible( $target_object_name ) ) {
				++$result['skipped'];
				$result['details'][] = array(
					'object_name'       => $target_object_name,
					'data_type'         => $rule_data_type,
					'status'            => 'skipped',
					'reason'            => 'dependency_target_not_publicly_accessible',
					'dependency_source' => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
				);
				continue;
			}

			$field_capabilities = is_array( $object_caps_map[ $target_object_name ] ?? null )
				? $object_caps_map[ $target_object_name ]
				: array();
			if ( empty( $field_capabilities ) ) {
				++$result['skipped'];
				$result['details'][] = array(
					'object_name'       => $target_object_name,
					'data_type'         => $rule_data_type,
					'status'            => 'skipped',
					'reason'            => 'dependency_target_no_template_fields',
					'dependency_source' => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
				);
				continue;
			}

			$field_capabilities = self::ensure_v3_format( $field_capabilities, $v3_data_type );
			$constraint         = self::constrain_rule_fields_to_template(
				$model_id,
				$rule_data_type,
				$target_object_name,
				$field_capabilities,
				'create_rules_for_model_dependency_target'
			);
			$field_capabilities = $constraint['field_capabilities'];

			if ( empty( $field_capabilities ) ) {
				++$result['skipped'];
				$result['details'][] = array(
					'object_name'       => $target_object_name,
					'data_type'         => $rule_data_type,
					'status'            => 'skipped',
					'reason'            => 'dependency_target_no_valid_template_fields',
					'dependency_source' => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
				);
				continue;
			}

			$related_taxonomies = array();
			$requires_login     = false;
			if ( 'post' === $rule_data_type ) {
				$url_pattern        = self::generate_url_pattern( $target_object_name );
				$related_taxonomies = self::discover_related_taxonomies( $target_object_name, $taxonomy_names );
				$pt_obj_runtime     = get_post_type_object( $target_object_name );
				$requires_login     = $pt_obj_runtime ? ! $pt_obj_runtime->publicly_queryable : false;
			} else {
				$url_pattern     = self::generate_taxonomy_url_pattern( $target_object_name );
				$tax_obj_runtime = get_taxonomy( $target_object_name );
				$requires_login  = $tax_obj_runtime ? ! $tax_obj_runtime->publicly_queryable : false;
			}

			$rule_data = array(
				'name'               => sprintf( '%s (%s)', ucfirst( $target_object_name ), $plugin_slug ?: 'auto' ),
				'url_pattern'        => $url_pattern,
				'url_type'           => $url_type,
				'requires_login'     => $requires_login,
				'data_type'          => $rule_data_type,
				'object_name'        => $target_object_name,
				'direction'          => 'one_way',
				'sync_mode'          => 'new_only',
				'field_capabilities' => $field_capabilities,
				'auto_detected'      => true,
				'is_active'          => true,
			);
			if ( 'post' === $rule_data_type ) {
				$rule_data['related_taxonomies'] = $related_taxonomies;
			}

			$pre_validation = self::validate_rule_data_before_save(
				array_merge(
					$rule_data,
					array( 'model_id' => $model_id )
				)
			);

			if ( ! $pre_validation['valid'] ) {
				++$result['errors'];
				$result['details'][] = array(
					'object_name'       => $target_object_name,
					'data_type'         => $rule_data_type,
					'status'            => 'validation_blocked',
					'reason'            => 'dependency_target_validation_failed',
					'errors'            => $pre_validation['errors'],
					'dependency_source' => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
				);
				continue;
			}

			$rule_id = self::create_rule( $model_id, $rule_data );
			if ( is_wp_error( $rule_id ) ) {
				++$result['errors'];
				$result['details'][] = array(
					'object_name'       => $target_object_name,
					'data_type'         => $rule_data_type,
					'status'            => 'error',
					'reason'            => $rule_id->get_error_message(),
					'dependency_source' => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
				);
				continue;
			}

			++$created_count;
			++$result['created'];
			$result['details'][] = array(
				'object_name'       => $target_object_name,
				'data_type'         => $rule_data_type,
				'status'            => 'created',
				'reason'            => 'dependency_target_rule_created',
				'rule_id'           => $rule_id,
				'dependency_source' => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
			);

			if ( 'post' === $rule_data_type && ! empty( $related_taxonomies ) ) {
				self::ensure_term_rules_for_post_type( $model_id, $target_object_name, $related_taxonomies, $plugin_slug );
			}

			$sim_validator   = new Simulation_Validator();
			$rule_validation = $sim_validator->validate_rule( $rule_id );
			$result['details'][ count( $result['details'] ) - 1 ]['validation'] = $rule_validation;
		}

		if ( $created_count > 0 ) {
			wptsall_log_info(
				'models-service',
				'Auto-created dependency target rules',
				array(
					'model_id'           => $model_id,
					'created_count'      => $created_count,
					'dependency_rebuild' => $dependency_sync,
				)
			);
		}
	}

	/**
	 * Delete auto-detected rules for a model.
	 *
	 * @param int $model_id Model ID.
	 * @return int|false
	 */
	public static function delete_auto_rules( $model_id ) {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );
		$result      = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE model_id = %d AND auto_detected = 1',
				$rules_table,
				$model_id
			)
		);

		if ( false !== $result ) {
			wptsall_log_debug(
				'models-service',
				'Deleted auto-detected rules for model',
				array(
					'model_id'      => $model_id,
					'deleted_count' => $result,
				)
			);
		}

		return $result;
	}

	/**
	 * Recreate auto rules for a model based on current read source.
	 *
	 * @param int $model_id Model ID.
	 * @return array
	 */
	public static function recreate_auto_rules( int $model_id ): array {
		self::delete_auto_rules( $model_id );

		$result = self::create_rules_for_model( $model_id );

		if ( function_exists( 'wptsall_log_info' ) ) {
			wptsall_log_info(
				'models-ssot',
				'Auto rules recreated',
				array(
					'model_id'    => $model_id,
					'read_source' => wptsall_get_ssot_read_source(),
					'result'      => is_array( $result ) ? count( $result ) : 0,
				)
			);
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Sync models.post_types and models.taxonomies from translation_rules.
	 *
	 * @param int $model_id Model ID.
	 * @return void
	 */
	private static function sync_model_post_types( int $model_id ): void {
		global $wpdb;

		$rules_table  = wptsall_table( 'translation_rules' );
		$models_table = wptsall_table( 'models' );

		$post_types = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT object_name FROM %i WHERE model_id = %d AND data_type = 'post' AND is_active = 1",
				$rules_table,
				$model_id
			)
		);

		$post_types_json = wp_json_encode(
			array_map(
				function ( $name ) {
					return array( 'name' => $name );
				},
				$post_types ? $post_types : array()
			)
		);

		$wpdb->update(
			$models_table,
			array( 'post_types' => $post_types_json ),
			array( 'id' => $model_id ),
			array( '%s' ),
			array( '%d' )
		);

		$taxonomies = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT object_name FROM %i WHERE model_id = %d AND data_type = 'term' AND is_active = 1",
				$rules_table,
				$model_id
			)
		);

		$taxonomies_json = wp_json_encode(
			array_map(
				function ( $name ) {
					return array( 'name' => $name );
				},
				$taxonomies ? $taxonomies : array()
			)
		);

		$wpdb->update(
			$models_table,
			array( 'taxonomies' => $taxonomies_json ),
			array( 'id' => $model_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
// phpcs:enable
}
