<?php
/**
 * Client Data REST Controller discovery trait.
 *
 * Extracted from Client_Data_REST_Controller to isolate token validation and
 * discovery metadata endpoints from content retrieval, claim, callback, and
 * media upload logic.
 *
 * @package WPTSALL\Tasks\API
 */

namespace WPTSALL\Tasks\API;

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Client_Data_REST_Controller_Discovery_Trait {


	/**
	 * GET /client/validate-token
	 *
	 * Validates the client token and returns site information. The token
	 * itself is verified by the permission_callback (check_client_permission),
	 * so reaching this handler means the token is valid.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function validate_token( $request ) {
		$task_params = function_exists( 'wptsall_get_task_parameters' ) ? wptsall_get_task_parameters() : array();
		$runtime     = function_exists( 'wptsall_get_client_api_runtime_status' ) ? wptsall_get_client_api_runtime_status() : array();

		$active_relations_count = 0;
		if ( class_exists( '\WPTSALL\Sites\Services\Site_Relation_Service' ) ) {
			$relations = Site_Relation_Service::get_all_relations(
				array( 'status' => 'active' ),
				false
			);
			$active_relations_count = is_array( $relations ) ? count( $relations ) : 0;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'site_url'               => home_url(),
					'site_domain'            => wp_parse_url( home_url(), PHP_URL_HOST ),
					'plugin_version'         => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '',
					'site_status'            => $runtime['domain_status'] ?? 'active',
					'max_relations'          => (int) ( $runtime['max_relations'] ?? 0 ),
					'token_status'           => 'valid',
					'encryption_supported'   => class_exists( '\WPTSALL\Core\Transport_Crypto' ),
					'active_relations_count' => $active_relations_count,
					'sync_execution_mode'    => $task_params['sync_execution_mode'] ?? 'client',
					'wordpress_version'      => get_bloginfo( 'version' ),
				),
			)
		);
	}

	/**
	 * GET /client/site-relations
	 *
	 * Returns all active site relations with their associated models,
	 * languages, and configuration. Used by the client to discover what
	 * content needs translation.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_site_relations( $request ) {
		$relations = Site_Relation_Service::get_all_relations(
			array( 'status' => 'active' ),
			false
		);

		if ( empty( $relations ) ) {
			return new \WP_REST_Response(
				array( 'relations' => array() ),
				200
			);
		}

		$relation_ids = array_map(
			function ( $relation ) {
				return (int) $relation['id'];
			},
			$relations
		);
		$relation_ids = array_filter( $relation_ids );

		$models_by_relation       = $this->batch_load_models_by_relations( $relation_ids );
		$i18n_configs_by_relation = $this->batch_load_i18n_configs( $relation_ids, $relations );
		$result                   = array();

		foreach ( $relations as $relation ) {
			$relation_id = (int) $relation['id'];
			$models      = $models_by_relation[ $relation_id ] ?? array();
			$model_list  = array();

			foreach ( $models as $model ) {
				$model_entry = array(
					'model_id'    => (int) $model['id'],
					'plugin_slug' => $model['plugin_slug'] ?? '',
					'plugin_name' => $model['plugin_name'] ?? '',
				);

				$post_types = json_decode( $model['post_types'] ?? '[]', true );
				if ( is_array( $post_types ) ) {
					$model_entry['post_types'] = array_map(
						function ( $post_type ) {
							return is_array( $post_type ) ? ( $post_type['name'] ?? $post_type ) : $post_type;
						},
						$post_types
					);
				} else {
					$model_entry['post_types'] = array();
				}

				$taxonomies = json_decode( $model['taxonomies'] ?? '[]', true );
				if ( is_array( $taxonomies ) ) {
					$model_entry['taxonomies'] = array_map(
						function ( $taxonomy ) {
							return is_array( $taxonomy ) ? ( $taxonomy['name'] ?? $taxonomy ) : $taxonomy;
						},
						$taxonomies
					);
				} else {
					$model_entry['taxonomies'] = array();
				}

				$model_list[] = $model_entry;
			}

			$relation_source_group_config = $this->derive_relation_source_group_config(
				$relation_id,
				$relation,
				$model_list,
				$i18n_configs_by_relation[ $relation_id ] ?? array(
					'translate_plugin_i18n' => false,
					'translate_theme_i18n'  => false,
					'translate_config_i18n' => false,
				)
			);

			$result[] = array(
				'id'               => $relation_id,
				'source_site_id'   => (int) $relation['source_site_id'],
				'source_lang'      => $relation['source_lang'] ?? '',
				'target_site_id'   => $relation['target_site_id'] ?? '',
				'target_site_type' => $relation['target_site_type'] ?? 'wp',
				'target_lang'      => $relation['target_lang'] ?? '',
				'sync_mode'        => $relation['sync_mode'] ?? 'new_only',
				'media_handling'   => $relation['media_handling'] ?? 'copy',
				'template'         => $relation['template'] ?? '',
				'models'           => $model_list,
				'i18n_config'      => $i18n_configs_by_relation[ $relation_id ] ?? array(
					'translate_plugin_i18n' => false,
					'translate_theme_i18n'  => false,
					'translate_config_i18n' => false,
					'translate_site_strings' => false,
					'translate_menu_strings' => false,
					'translate_widget_strings' => false,
					'gettext_domain_whitelist' => array(),
				),
				'source_group_config'     => $relation_source_group_config,
				'preflight_policy'        => (string) ( $i18n_configs_by_relation[ $relation_id ]['preflight_policy'] ?? 'warn' ),
				'missing_component_behavior' => (string) ( $i18n_configs_by_relation[ $relation_id ]['missing_component_behavior'] ?? 'confirm_continue' ),
			);
		}

		wptsall_log_info(
			'client-api',
			'Site relations discovery request',
			array( 'relations_count' => count( $result ) )
		);

		return new \WP_REST_Response(
			array( 'relations' => $result ),
			200
		);
	}

	/**
	 * GET /client/rules
	 *
	 * Returns translation rules for models in a given relation or a specific model.
	 * Rules define which fields to translate, which to map by ID, and which
	 * taxonomies are related.
	 *
	 * Parameters:
	 * - relation_id (int): Get rules for all models in a relation.
	 * - model_id (int): Get rules for a specific model.
	 * At least one of relation_id or model_id is required.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_rules( $request ) {
		$relation_id = absint( $request->get_param( 'relation_id' ) );
		$model_id    = absint( $request->get_param( 'model_id' ) );
		$client_version = (string) $request->get_header( 'X-Client-Version' );
		$require_storage_map = $this->should_require_storage_map_contract( $request );

		if ( $require_storage_map && ! $this->client_supports_content_format( $request ) ) {
			return new \WP_REST_Response(
				array(
					'success'              => false,
					'error'                => 'client_version_unsupported',
					'message'              => 'Client version 2.1.0 or newer is required for production rules discovery.',
					'required_min_version' => '2.1.0',
					'client_version'       => $client_version,
				),
				426
			);
		}

		if ( empty( $relation_id ) && empty( $model_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'missing_parameter',
					'message' => 'Either relation_id or model_id is required.',
				),
				400
			);
		}

		$model_ids = array();
		if ( ! empty( $relation_id ) ) {
			$relation = Site_Relation_Service::get_relation( $relation_id );
			if ( ! $relation ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'relation_not_found',
						'message' => 'Site relation not found.',
					),
					404
				);
			}
			if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'relation_inactive',
						'message' => 'Site relation is not active.',
					),
					409
				);
			}

			$models = Relation_Model_Service::get_models_by_relation( $relation_id );
			foreach ( $models as $model ) {
				$model_ids[] = (int) $model['id'];
			}

			if ( empty( $model_ids ) ) {
				return new \WP_REST_Response( array( 'rules' => array() ), 200 );
			}
		} else {
			$model_ids[] = $model_id;
		}

			$rules_output            = array();
			$skipped_disabled_rules  = 0;
			$supports_content_format = $this->client_supports_content_format( $request );

			foreach ( $model_ids as $model_id_item ) {
			$rules = Translation_Rule_Service::get_model_rules( $model_id_item );

			foreach ( $rules as $rule ) {
				$effective_relation_id = ! empty( $relation_id ) ? (int) $relation_id : null;
				$merged_config         = Translation_Rule_Service::get_merged_config(
					(int) $rule['id'],
					$effective_relation_id,
					array( 'suppress_warning_log' => true )
				);

				$field_caps = $rule['field_capabilities'] ?? array();
				if ( is_array( $merged_config ) && isset( $merged_config['fields'] ) && is_array( $merged_config['fields'] ) ) {
					$field_caps = $merged_config['fields'];
				}

				$rule_enabled = is_array( $merged_config )
					? (bool) ( $merged_config['enabled'] ?? ( $rule['is_active'] ?? true ) )
					: (bool) ( $rule['is_active'] ?? true );
				if ( ! $rule_enabled ) {
					++$skipped_disabled_rules;
					continue;
				}

				$rule_direction = is_array( $merged_config )
					? ( $merged_config['direction'] ?? ( $rule['direction'] ?? 'source_to_target' ) )
					: ( $rule['direction'] ?? 'source_to_target' );
				$rule_sync_mode = is_array( $merged_config )
					? ( $merged_config['sync_mode'] ?? ( $rule['sync_mode'] ?? 'new_only' ) )
					: ( $rule['sync_mode'] ?? 'new_only' );

				$translate_fields = array();
				foreach ( $field_caps as $field_name => $config ) {
					if ( is_array( $config ) && 'translate' === ( $config['type'] ?? '' ) && ( $config['enabled'] ?? true ) ) {
						$translate_fields[] = $field_name;
					}
				}

				$field_caps_output                     = $field_caps;
				$field_caps_output['translate_fields'] = array_values( $translate_fields );

				$rule_output = array(
					'id'                 => (int) $rule['id'],
					'model_id'           => (int) $rule['model_id'],
					'name'               => $rule['name'] ?? '',
					'data_type'          => $rule['data_type'] ?? '',
					'object_name'        => $rule['object_name'] ?? '',
					'url_type'           => $rule['url_type'] ?? '',
					'field_capabilities' => $field_caps_output,
					'related_taxonomies' => $rule['related_taxonomies'] ?? array(),
					'direction'          => (string) $rule_direction,
					'sync_mode'          => (string) $rule_sync_mode,
					'priority'           => (int) ( $rule['priority'] ?? 10 ),
					'is_active'          => true,
				);

				$formats  = array();
				$storages = array();
				$field_roles = array();
				$required_content_formats = array();
				$required_component_slots = array();
				foreach ( $field_caps as $field_name => $config ) {
					if ( ! is_array( $config ) ) {
						continue;
					}
					if ( ! empty( $config['content_format'] ) ) {
						$normalized_content_format = $this->normalize_discovery_content_format( (string) $config['content_format'] );
						$formats[ $field_name ]    = $normalized_content_format;
						$required_content_formats[] = $normalized_content_format;
						$required_component_slots[] = $this->map_required_component_slot_for_content_format(
							$field_name,
							$normalized_content_format
						);
						$field_roles[ $field_name ] = $this->derive_rule_field_source_role(
							$rule,
							$field_name,
							$normalized_content_format
						);
					}
					if ( ! empty( $config['storage'] ) ) {
						$storages[ $field_name ] = (string) $config['storage'];
					}
				}
					if ( $supports_content_format && ! empty( $formats ) ) {
						$rule_output['field_content_formats'] = $formats;
					}
					if ( $supports_content_format && ! empty( $storages ) ) {
						$rule_output['field_storage_map'] = $storages;
					}
				$rule_source_group                     = $this->derive_rule_source_group( $rule, $formats, $translate_fields );
				$rule_routing_profile                 = $this->derive_rule_routing_profile( $rule, $rule_source_group, $formats, $translate_fields );
				$rule_output['source_group']          = $rule_source_group;
				$rule_output['routing_profile']       = $rule_routing_profile;
				$rule_output['delivery_target']       = $this->derive_rule_delivery_target( $rule_source_group, $rule );
				$rule_output['required_component_slots'] = array_values( array_unique( array_filter( $required_component_slots ) ) );
				$rule_output['required_content_formats'] = array_values( array_unique( array_filter( $required_content_formats ) ) );
				if ( ! empty( $field_roles ) ) {
					$rule_output['field_source_roles'] = $field_roles;
				}

				$rules_output[] = $rule_output;
			}
		}

		wptsall_log_info(
			'client-api',
			'Rules discovery request',
			array(
				'relation_id'             => $relation_id,
				'model_id'                => $model_id,
				'rules_count'             => count( $rules_output ),
				'skipped_disabled_rules'  => $skipped_disabled_rules,
				'content_format_injected' => $supports_content_format,
				'client_version'          => $client_version,
				'storage_contract_gate'   => $require_storage_map,
			)
		);

		return new \WP_REST_Response(
			array( 'rules' => $rules_output ),
			200
		);
	}

	/**
	 * Batch-load models for multiple relation IDs in a single query.
	 *
	 * Replaces individual Relation_Model_Service::get_models_by_relation()
	 * calls with a single JOIN query to avoid N+1 query performance issues.
	 *
	 * @since 1.4.0
	 *
	 * @param array $relation_ids Array of relation IDs.
	 * @return array Keyed by relation_id, each value is an array of model rows.
	 */
	private function batch_load_models_by_relations( $relation_ids ) {
		global $wpdb;

		if ( empty( $relation_ids ) ) {
			return array();
		}

		$rm_table     = wptsall_table( 'relation_models' );
		$models_table = wptsall_table( 'models' );
		list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $relation_ids );

		$rows = wptsall_db_get_results(
			"SELECT m.*, rm.relation_id, rm.created_at as associated_at
				FROM %i m
				INNER JOIN %i rm ON m.id = rm.model_id
				WHERE rm.relation_id IN ($in_sql)
				ORDER BY m.plugin_name ASC",
			array_merge( array( $models_table, $rm_table ), $in_args ),
			ARRAY_A
		);

		$models_by_relation = array();
		foreach ( (array) $rows as $row ) {
			$relation_id = (int) $row['relation_id'];
			$models_by_relation[ $relation_id ][] = $row;
		}

		return $models_by_relation;
	}

	/**
	 * Batch-load i18n template configs for multiple relation IDs.
	 *
	 * Replaces individual get_i18n_config_for_relation() calls with a single
	 * query to the relation_post_type_configs table, avoiding N+1 queries.
	 *
	 * @since 1.4.0
	 *
	 * @param array $relation_ids Array of relation IDs.
	 * @param array $relations    Full relation data array (for target_site_type check).
	 * @return array Keyed by relation_id, each value is the i18n config array.
	 */
	private function batch_load_i18n_configs( $relation_ids, $relations ) {
		$defaults = array(
			'translate_plugin_i18n' => false,
			'translate_theme_i18n'  => false,
			'translate_config_i18n' => false,
			'translate_site_strings' => false,
			'translate_menu_strings' => false,
			'translate_widget_strings' => false,
			'gettext_domain_whitelist' => array(),
			'preflight_policy'      => 'warn',
			'missing_component_behavior' => 'confirm_continue',
		);

		if ( empty( $relation_ids ) || ! class_exists( '\WPTSALL\Sites\Services\Relation_Config_Service' ) ) {
			$result = array();
			foreach ( $relation_ids as $relation_id ) {
				$result[ $relation_id ] = $defaults;
			}
			return $result;
		}

		$type_by_relation = array();
		foreach ( $relations as $relation ) {
			$type_by_relation[ (int) $relation['id'] ] = $relation['target_site_type'] ?? '';
		}

		$virtual_relation_ids = array();
		foreach ( $relation_ids as $relation_id ) {
			if ( 'virtual' === ( $type_by_relation[ $relation_id ] ?? '' ) ) {
				$virtual_relation_ids[] = $relation_id;
			}
		}

		$configs = array();
		foreach ( $relation_ids as $relation_id ) {
			$configs[ $relation_id ] = $defaults;
		}

		if ( empty( $virtual_relation_ids ) ) {
			return $configs;
		}

		global $wpdb;
		$table = wptsall_table( 'relation_post_type_configs' );
		list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $virtual_relation_ids );

		$rows = wptsall_db_get_results(
			"SELECT relation_id, field_overrides FROM %i WHERE relation_id IN ($in_sql) AND post_type = '__templates__'",
			array_merge( array( $table ), $in_args ),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$relation_id = (int) $row['relation_id'];
			$overrides   = json_decode( $row['field_overrides'] ?? '', true );
			if ( is_array( $overrides ) ) {
				$configs[ $relation_id ] = array(
					'translate_plugin_i18n' => ! empty( $overrides['translate_plugin_i18n'] ),
					'translate_theme_i18n'  => ! empty( $overrides['translate_theme_i18n'] ),
					'translate_config_i18n' => ! empty( $overrides['translate_config_i18n'] ),
					'translate_site_strings' => ! empty( $overrides['translate_site_strings'] ),
					'translate_menu_strings' => ! empty( $overrides['translate_menu_strings'] ),
					'translate_widget_strings' => ! empty( $overrides['translate_widget_strings'] ),
					'gettext_domain_whitelist' => ! empty( $overrides['gettext_domain_whitelist'] ) ? array_values( (array) $overrides['gettext_domain_whitelist'] ) : array(),
					'preflight_policy'      => $this->normalize_relation_preflight_policy( $overrides['preflight_policy'] ?? '' ),
					'missing_component_behavior' => $this->normalize_relation_missing_component_behavior( $overrides['missing_component_behavior'] ?? '' ),
				);
			}
		}

		return $configs;
	}

	/**
	 * Normalize relation preflight policy exposed to Client discovery.
	 *
	 * @param string $raw Raw policy.
	 * @return string
	 */
	private function normalize_relation_preflight_policy( string $raw ): string {
		$policy = sanitize_key( strtolower( trim( $raw ) ) );
		return in_array( $policy, array( 'warn', 'block' ), true ) ? $policy : 'warn';
	}

	/**
	 * Normalize relation missing-component behavior exposed to Client discovery.
	 *
	 * @param string $raw Raw behavior.
	 * @return string
	 */
	private function normalize_relation_missing_component_behavior( string $raw ): string {
		$behavior = sanitize_key( strtolower( trim( $raw ) ) );
		return in_array( $behavior, array( 'confirm_continue', 'skip_unbound_fields', 'stop_task' ), true )
			? $behavior
			: 'confirm_continue';
	}

	/**
	 * Check if the client supports content_format in rules response.
	 *
	 * Reads `X-Client-Version` header and compares to minimum version 2.1.
	 * Returns false if the header is absent or the version is below 2.1,
	 * ensuring backward compatibility with older clients.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool True if client version >= 2.1.
	 */
	private function client_supports_content_format( $request ) {
		$version = (string) $request->get_header( 'X-Client-Version' );
		if ( empty( $version ) ) {
			return false;
		}

		$parts = explode( '.', $version );
		$major = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$minor = isset( $parts[1] ) ? (int) $parts[1] : 0;

		if ( $major > 2 ) {
			return true;
		}
		if ( 2 === $major && $minor >= 1 ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize content data_type aliases to canonical discovery names.
	 *
	 * Supports both old aliases (post/term) and canonical names
	 * (post_type/taxonomy) to keep client/server contracts forward-compatible.
	 *
	 * @since 1.6.1
	 *
	 * @param string $data_type Raw data_type.
	 * @return string Canonical data_type for content endpoint logic.
	 */
	private function normalize_content_data_type( string $data_type ): string {
		$data_type = sanitize_key( $data_type );
		if ( 'post_type' === $data_type ) {
			return 'post';
		}
		if ( 'taxonomy' === $data_type ) {
			return 'term';
		}
		return $data_type;
	}

	/**
	 * Decide whether production should enforce storage/content-format contract.
	 *
	 * @since 1.6.1
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool
	 */
	private function should_require_storage_map_contract( $request ): bool {
		$enabled = (bool) apply_filters( 'wptsall_require_client_storage_map_contract', true, $request );
		if ( ! $enabled ) {
			return false;
		}

		$env_type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '';
		return 'production' === $env_type;
	}

	/**
	 * Derive relation-level source group config for Client preflight/UI.
	 *
	 * @param int   $relation_id Relation ID.
	 * @param array $relation    Relation row.
	 * @param array $model_list  Relation models.
	 * @param array $i18n_config Relation i18n config.
	 * @return array
	 */
	private function derive_relation_source_group_config( int $relation_id, array $relation, array $model_list, array $i18n_config ): array {
		$has_content_objects = false;
		$has_taxonomies      = false;
		$has_config_objects  = false;
		$has_message_templates = false;

		foreach ( $model_list as $model ) {
			$post_types = $model['post_types'] ?? array();
			$taxonomies = $model['taxonomies'] ?? array();
			if ( ! empty( $post_types ) ) {
				$has_content_objects = true;
			}
			if ( ! empty( $taxonomies ) ) {
				$has_taxonomies = true;
			}

			$model_id = (int) ( $model['model_id'] ?? 0 );
			if ( $model_id <= 0 ) {
				continue;
			}

			$rules = Translation_Rule_Service::get_model_rules( $model_id );
			foreach ( $rules as $rule ) {
				$merged_config = Translation_Rule_Service::get_merged_config(
					(int) $rule['id'],
					$relation_id,
					array( 'suppress_warning_log' => true )
				);

				$rule_enabled = is_array( $merged_config )
					? (bool) ( $merged_config['enabled'] ?? ( $rule['is_active'] ?? true ) )
					: (bool) ( $rule['is_active'] ?? true );
				if ( ! $rule_enabled ) {
					continue;
				}

				$field_caps = $rule['field_capabilities'] ?? array();
				if ( is_array( $merged_config ) && isset( $merged_config['fields'] ) && is_array( $merged_config['fields'] ) ) {
					$field_caps = $merged_config['fields'];
				}

				$translate_fields = array();
				$formats          = array();
				foreach ( $field_caps as $field_name => $config ) {
					if ( ! is_array( $config ) ) {
						continue;
					}
					if ( 'translate' === ( $config['type'] ?? '' ) && ( $config['enabled'] ?? true ) ) {
						$translate_fields[] = $field_name;
					}
					if ( ! empty( $config['content_format'] ) ) {
						$formats[ $field_name ] = $this->normalize_discovery_content_format( (string) $config['content_format'] );
					}
				}

				if ( 'option' === sanitize_key( (string) ( $rule['data_type'] ?? '' ) ) ) {
					$has_config_objects = true;
				}

				$rule_source_group = $this->derive_rule_source_group( $rule, $formats, $translate_fields );
				if ( 'config_object' === $rule_source_group ) {
					$has_config_objects = true;
				}
				if ( 'message_template' === $rule_source_group ) {
					$has_message_templates = true;
				}
			}
		}

		return array(
			'translate_content_objects'   => $has_content_objects,
			'translate_taxonomies'        => $has_taxonomies,
			'translate_media_text'        => $has_content_objects,
			'translate_media_files'       => $has_content_objects,
			'translate_seo_meta'          => $has_content_objects,
			'translate_slug'              => $has_content_objects || $has_taxonomies,
			'translate_plugin_i18n'       => ! empty( $i18n_config['translate_plugin_i18n'] ),
			'translate_theme_i18n'        => ! empty( $i18n_config['translate_theme_i18n'] ),
			'translate_config_i18n'       => ! empty( $i18n_config['translate_config_i18n'] ) || $has_config_objects,
			'translate_message_templates' => $has_message_templates,
		);
	}

	/**
	 * Normalize discovery content_format into canonical values.
	 *
	 * @param string $raw Raw content format.
	 * @return string
	 */
	private function normalize_discovery_content_format( string $raw ): string {
		$lower = sanitize_key( strtolower( trim( $raw ) ) );
		switch ( $lower ) {
			case 'html':
			case 'rich_html':
				return 'rich_html';
			case 'json':
			case 'json_structured':
				return 'json_structured';
			case 'serialized':
			case 'serialized_php':
				return 'serialized_php';
			case 'media':
			case 'mediaref':
			case 'media_ref':
				return 'media_ref';
			case 'slug':
				return 'slug';
			case 'code':
				return 'plain_text';
			case 'plain':
			case 'text':
			case 'plain_text':
			default:
				return 'plain_text';
		}
	}

	/**
	 * Map canonical content_format to preflight component slot.
	 *
	 * @param string $field_name      Field name.
	 * @param string $content_format  Canonical content format.
	 * @return string
	 */
	private function map_required_component_slot_for_content_format( string $field_name, string $content_format ): string {
		if ( 'media_ref' === $content_format && ! $this->is_discovery_media_text_field( $field_name, null ) ) {
			return 'media_ref:' . $this->infer_discovery_media_task_type( $field_name );
		}

		if ( 'media_ref' === $content_format || 'slug' === $content_format ) {
			return 'plain_text';
		}

		return $content_format;
	}

	/**
	 * Derive source group for a rule.
	 *
	 * @param array $rule             Rule row.
	 * @param array $field_formats    Field content formats.
	 * @param array $translate_fields Translate fields.
	 * @return string
	 */
	private function derive_rule_source_group( array $rule, array $field_formats, array $translate_fields ): string {
		$declared_semantics = $this->get_rule_declared_semantics( $rule );
		if ( ! empty( $declared_semantics['source_group'] ) ) {
			return $declared_semantics['source_group'];
		}

		$data_type   = sanitize_key( (string) ( $rule['data_type'] ?? '' ) );
		$object_name = strtolower( (string) ( $rule['object_name'] ?? '' ) );
		$rule_name   = strtolower( (string) ( $rule['name'] ?? '' ) );
		// Only translated field signatures should influence semantic routing.
		// Including sync/id-mapping fields such as `menu_order` causes normal
		// post/page rules to be misclassified as config objects.
		$candidate_fields          = array_values(
			array_unique(
				array_merge(
					$translate_fields,
					array_keys( $field_formats )
				)
			)
		);
		$lower_fields              = array_map( 'strtolower', $candidate_fields );
		$signature                 = $object_name . ' ' . $rule_name . ' ' . implode( ' ', $lower_fields );
		$translate_field_signature = ' ' . implode( ' ', $lower_fields ) . ' ';

		if ( 'option' === $data_type ) {
			if ( $this->looks_like_message_template_signature( $signature, $translate_field_signature, $field_formats ) ) {
				return 'message_template';
			}

			return 'config_object';
		}

		if ( $this->looks_like_message_template_signature( $signature, $translate_field_signature, $field_formats ) ) {
			return 'message_template';
		}

		if ( $this->looks_like_config_object_signature( $signature, $data_type ) ) {
			return 'config_object';
		}

		$all_media_fields = ! empty( $translate_fields );
		foreach ( $translate_fields as $field_name ) {
			$format = $field_formats[ $field_name ] ?? 'plain_text';
			if ( 'media_ref' !== $format || $this->is_discovery_media_text_field( $field_name, $rule ) ) {
				$all_media_fields = false;
				break;
			}
		}
		if ( $all_media_fields ) {
			return 'media_asset';
		}

		return 'content_object';
	}

	/**
	 * Derive routing profile for a rule.
	 *
	 * @param array  $rule             Rule row.
	 * @param string $source_group     Derived source group.
	 * @param array  $field_formats    Field content formats.
	 * @param array  $translate_fields Translate fields.
	 * @return string
	 */
	private function derive_rule_routing_profile( array $rule, string $source_group, array $field_formats, array $translate_fields ): string {
		$declared_semantics = $this->get_rule_declared_semantics( $rule );
		if ( ! empty( $declared_semantics['routing_profile'] ) ) {
			return $declared_semantics['routing_profile'];
		}

		if ( 'message_template' === $source_group ) {
			$signature = strtolower( (string) ( $rule['object_name'] ?? '' ) . ' ' . (string) ( $rule['name'] ?? '' ) . ' ' . implode( ' ', $translate_fields ) );
			if ( false !== strpos( $signature, 'email' ) || in_array( 'rich_html', $field_formats, true ) ) {
				return 'notification_email';
			}
			return 'notification_message';
		}

		if ( 'config_object' === $source_group ) {
			return 'config_i18n';
		}

		if ( 'media_asset' === $source_group ) {
			return 'media_file';
		}

		if ( in_array( sanitize_key( (string) ( $rule['data_type'] ?? '' ) ), array( 'term', 'taxonomy' ), true ) ) {
			return 'taxonomy_default';
		}

		$seo_only = ! empty( $translate_fields );
		foreach ( $translate_fields as $field_name ) {
			if ( ! $this->is_discovery_seo_field( $field_name ) ) {
				$seo_only = false;
				break;
			}
		}
		if ( $seo_only ) {
			return 'seo_meta';
		}

		return 'post_content_default';
	}

	/**
	 * Derive delivery target for a source group.
	 *
	 * @param string $source_group Source group.
	 * @return string
	 */
	private function derive_rule_delivery_target( string $source_group, array $rule = array() ): string {
		$declared_semantics = $this->get_rule_declared_semantics( $rule );
		if ( ! empty( $declared_semantics['delivery_target'] ) ) {
			return $declared_semantics['delivery_target'];
		}

		switch ( $source_group ) {
			case 'media_asset':
				return 'media_mapping';
			case 'config_object':
				return 'config_writeback';
			case 'message_template':
				return 'message_template_writeback';
			case 'i18n_bundle':
				return 'i18n_deploy';
			case 'content_object':
			default:
				return 'object_writeback';
		}
	}

	/**
	 * Derive a source role for a field.
	 *
	 * @param array  $rule            Rule row.
	 * @param string $field_name      Field name.
	 * @param string $content_format  Canonical content format.
	 * @return string
	 */
	private function derive_rule_field_source_role( array $rule, string $field_name, string $content_format ): string {
		$lower       = strtolower( $field_name );
		$declared_semantics = $this->get_rule_declared_semantics( $rule );
		if ( ! empty( $declared_semantics['field_source_roles'][ $field_name ] ) ) {
			return $declared_semantics['field_source_roles'][ $field_name ];
		}
		$source_group = $this->derive_rule_source_group( $rule, array( $field_name => $content_format ), array( $field_name ) );

		if ( 'slug' === $content_format || 'post_name' === $lower || 'slug' === $lower ) {
			return 'slug';
		}

		if ( $this->is_discovery_seo_field( $field_name ) ) {
			if ( false !== strpos( $lower, 'title' ) ) {
				return 'seo_title';
			}
			return 'seo_description';
		}

		if ( 'message_template' === $source_group ) {
			if ( false !== strpos( $lower, 'subject' ) ) {
				return 'message_subject';
			}
			if ( false !== strpos( $lower, 'heading' ) ) {
				return 'message_heading';
			}
			return 'message_body';
		}

		if ( 'json_structured' === $content_format && false !== strpos( $lower, 'block' ) ) {
			return 'block_attrs';
		}

		if ( 'json_structured' === $content_format && false !== strpos( $lower, 'shortcode' ) ) {
			return 'shortcode_attrs';
		}

		if ( $this->is_discovery_media_text_field( $field_name, $rule ) ) {
			return 'media_text';
		}

		if ( false !== strpos( $lower, 'title' ) ) {
			return 'title';
		}

		if ( false !== strpos( $lower, 'excerpt' ) ) {
			return 'excerpt';
		}

		if ( 'config_object' === $source_group ) {
			if ( false !== strpos( $lower, 'label' ) || false !== strpos( $lower, 'name' ) ) {
				return 'config_label';
			}
			return 'config_value';
		}

		if ( false !== strpos( $lower, 'content' ) || false !== strpos( $lower, 'body' ) || false !== strpos( $lower, 'description' ) ) {
			return 'body';
		}

		return 'body';
	}

	/**
	 * Check whether a rule signature should be treated as message template.
	 *
	 * @param string $signature                Lower-cased object/rule signature.
	 * @param string $translate_field_signature Lower-cased translate field signature.
	 * @param array  $field_formats            Translate field formats.
	 * @return bool
	 */
	private function looks_like_message_template_signature( string $signature, string $translate_field_signature, array $field_formats ): bool {
		if ( false !== strpos( $signature, 'email' ) || false !== strpos( $signature, 'mail' ) || false !== strpos( $signature, 'notification' ) || false !== strpos( $signature, 'message' ) || false !== strpos( $signature, 'reminder' ) ) {
			return true;
		}

		foreach ( array( ' subject ', '_subject', ' heading ', '_heading', ' body ', '_body', ' template ' ) as $marker ) {
			if ( false !== strpos( $translate_field_signature, $marker ) ) {
				return true;
			}
		}

		return in_array( 'rich_html', $field_formats, true ) && false !== strpos( $translate_field_signature, 'subject' );
	}

	/**
	 * Check whether a rule signature should be treated as config object.
	 *
	 * @param string $signature Lower-cased object/rule signature.
	 * @param string $data_type Rule data type.
	 * @return bool
	 */
	private function looks_like_config_object_signature( string $signature, string $data_type ): bool {
		if ( 'option' === $data_type ) {
			return true;
		}

		return false !== strpos( $signature, 'option' )
			|| false !== strpos( $signature, 'setting' )
			|| false !== strpos( $signature, 'config' )
			|| false !== strpos( $signature, 'widget' )
			|| false !== strpos( $signature, 'menu' )
			|| false !== strpos( $signature, 'block' );
	}

	/**
	 * Load declared source semantics from model-object field metadata for a rule.
	 *
	 * @param array $rule Rule row.
	 * @return array{source_group:string,routing_profile:string,delivery_target:string,field_source_roles:array<string,string>}
	 */
	private function get_rule_declared_semantics( array $rule ): array {
		static $cache = array();

		$model_id    = (int) ( $rule['model_id'] ?? 0 );
		$object_name = sanitize_key( (string) ( $rule['object_name'] ?? '' ) );
		$object_type = $this->map_rule_data_type_to_model_object_type( (string) ( $rule['data_type'] ?? '' ) );

		$empty = array(
			'source_group'       => '',
			'routing_profile'    => '',
			'delivery_target'    => '',
			'field_source_roles' => array(),
		);

		if ( $model_id <= 0 || '' === $object_name || '' === $object_type || ! class_exists( '\WPTSALL\Models\Services\Model_Object_Service' ) ) {
			return $empty;
		}

		$cache_key = $model_id . ':' . $object_type . ':' . $object_name;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$object = Model_Object_Service::get_object_by_type( $model_id, $object_type, $object_name );
		if ( empty( $object['id'] ) ) {
			$cache[ $cache_key ] = $empty;
			return $empty;
		}

		$declared = $empty;
		$fields   = Model_Object_Service::get_fields_for_object( (int) $object['id'] );
		foreach ( $fields as $field ) {
			$field_key = sanitize_text_field( (string) ( $field['field_key'] ?? '' ) );
			if ( '' === $field_key ) {
				continue;
			}

			$semantic = $this->extract_field_semantic_declaration( $field );
			if ( '' === $declared['source_group'] && '' !== $semantic['source_group'] ) {
				$declared['source_group'] = $semantic['source_group'];
			}
			if ( '' === $declared['routing_profile'] && '' !== $semantic['routing_profile'] ) {
				$declared['routing_profile'] = $semantic['routing_profile'];
			}
			if ( '' === $declared['delivery_target'] && '' !== $semantic['delivery_target'] ) {
				$declared['delivery_target'] = $semantic['delivery_target'];
			}
			if ( '' !== $semantic['source_role'] ) {
				$declared['field_source_roles'][ $field_key ] = $semantic['source_role'];
			}
		}

		$cache[ $cache_key ] = $declared;
		return $declared;
	}

	/**
	 * Extract normalized semantic declaration from one model field row.
	 *
	 * @param array $field Field row.
	 * @return array{source_group:string,routing_profile:string,delivery_target:string,source_role:string}
	 */
	private function extract_field_semantic_declaration( array $field ): array {
		$extra = is_array( $field['extra'] ?? null ) ? $field['extra'] : array();
		$meta  = is_array( $extra['source_semantics'] ?? null ) ? $extra['source_semantics'] : array();
		$meta  = array_merge(
			array(
				'source_group'    => '',
				'routing_profile' => '',
				'delivery_target' => '',
				'source_role'     => '',
			),
			$meta
		);

		foreach ( array( 'source_group', 'routing_profile', 'delivery_target', 'source_role' ) as $key ) {
			if ( '' === (string) ( $meta[ $key ] ?? '' ) && array_key_exists( $key, $extra ) ) {
				$meta[ $key ] = $extra[ $key ];
			}
		}

		if ( '' === (string) ( $meta['routing_profile'] ?? '' ) && array_key_exists( 'source_profile', $extra ) ) {
			$meta['routing_profile'] = $extra['source_profile'];
		}

		if ( '' === (string) ( $meta['source_role'] ?? '' ) && array_key_exists( 'message_template_part', $extra ) ) {
			$part = sanitize_key( (string) $extra['message_template_part'] );
			if ( in_array( $part, array( 'subject', 'heading', 'body' ), true ) ) {
				$meta['source_role'] = 'message_' . $part;
			}
		}

		return array(
			'source_group'    => sanitize_key( (string) ( $meta['source_group'] ?? '' ) ),
			'routing_profile' => sanitize_key( (string) ( $meta['routing_profile'] ?? '' ) ),
			'delivery_target' => sanitize_key( (string) ( $meta['delivery_target'] ?? '' ) ),
			'source_role'     => sanitize_key( (string) ( $meta['source_role'] ?? '' ) ),
		);
	}

	/**
	 * Map rule data type to model object type.
	 *
	 * @param string $data_type Rule data type.
	 * @return string
	 */
	private function map_rule_data_type_to_model_object_type( string $data_type ): string {
		switch ( sanitize_key( $data_type ) ) {
			case 'post':
				return 'post_type';
			case 'term':
			case 'taxonomy':
				return 'taxonomy';
			case 'option':
				return 'option';
			default:
				return '';
		}
	}

	/**
	 * Check if a field should be treated as media text.
	 *
	 * @param string     $field_name Field name.
	 * @param array|null $rule       Optional rule row.
	 * @return bool
	 */
	private function is_discovery_media_text_field( string $field_name, ?array $rule ): bool {
		$lower = strtolower( $field_name );

		if ( in_array( $lower, array( '_wp_attachment_image_alt', 'post_title', 'post_excerpt', 'post_content' ), true ) ) {
			$object_name = strtolower( (string) ( $rule['object_name'] ?? '' ) );
			if ( '' === $object_name || 'attachment' === $object_name ) {
				return true;
			}
		}

		return false !== strpos( $lower, 'alt' )
			|| false !== strpos( $lower, 'caption' )
			|| false !== strpos( $lower, 'description' )
			|| false !== strpos( $lower, 'excerpt' );
	}

	/**
	 * Infer media task type from field name.
	 *
	 * @param string $field_name Field name.
	 * @return string
	 */
	private function infer_discovery_media_task_type( string $field_name ): string {
		$lower = strtolower( $field_name );
		if ( false !== strpos( $lower, 'video' ) || false !== strpos( $lower, 'movie' ) || false !== strpos( $lower, 'subtitle' ) ) {
			return 'video';
		}
		if ( false !== strpos( $lower, 'audio' ) || false !== strpos( $lower, 'sound' ) || false !== strpos( $lower, 'voice' ) ) {
			return 'audio';
		}
		if ( false !== strpos( $lower, 'document' ) || false !== strpos( $lower, 'file' ) || false !== strpos( $lower, 'pdf' ) || false !== strpos( $lower, 'doc' ) || false !== strpos( $lower, 'sheet' ) || false !== strpos( $lower, 'ppt' ) ) {
			return 'document';
		}
		return 'image';
	}

	/**
	 * Check if a field looks like SEO metadata.
	 *
	 * @param string $field_name Field name.
	 * @return bool
	 */
	private function is_discovery_seo_field( string $field_name ): bool {
		$lower = strtolower( $field_name );
		return false !== strpos( $lower, 'yoast' )
			|| false !== strpos( $lower, 'rank_math' )
			|| false !== strpos( $lower, 'seo' )
			|| false !== strpos( $lower, 'meta_description' )
			|| false !== strpos( $lower, 'og_title' )
			|| false !== strpos( $lower, 'og_description' )
			|| false !== strpos( $lower, 'twitter_title' )
			|| false !== strpos( $lower, 'twitter_description' );
	}
// phpcs:enable
}
