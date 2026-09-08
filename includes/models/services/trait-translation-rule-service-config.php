<?php
/**
 * Translation Rule Service config/capability helpers.
 *
 * @package WPTSALL
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Translation_Rule_Service_Config_Trait {

	// ========================================
	// v0.8.0 configuration merge methods
	// ========================================

	/**
	 * Get merged field configuration (cross-module API contract method)
	 *
	 * Gets merged configuration by site relation ID and post_type.
	 * This is the main interface called by the Tasks module, conforming to MODULE-CHAINS.md Contract 1.
	 *
	 * Returns capabilities format with flat arrays plus enrichment keys:
	 *   - translate_fields   (string[])
	 *   - sync_fields        (string[])  — WPML "Copy" (always)
	 *   - copy_once_fields   (string[])  — WPML "Copy once"
	 *   - id_mapping_fields  (string[])
	 *   - compute_fields     (string[])
	 *   - skip_fields        (string[])  — WPML "Don't translate"
	 *   - id_mapping_details (array<string,array>)
	 *   - field_capabilities (array<string,array>) Raw per-field detail from internal config.
	 *   - related_taxonomies (string[]) Taxonomy names associated with this rule.
	 *
	 * @since 0.8.1
	 * @since 1.3.0 Added field_capabilities and related_taxonomies to output (B4).
	 *
	 * @param int    $relation_id Site relation ID.
	 * @param string $post_type   Post type.
	 * @return array Merged field_capabilities format configuration.
	 */
	public static function get_merged_config_for_relation( int $relation_id, string $post_type, string $data_type = 'post' ): array {
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) ) {
			wptsall_log_error(
				'models',
				'Relation_Model_Service not available',
				array( 'relation_id' => $relation_id )
			);
			return self::get_default_config( $post_type );
		}

		$models = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );

		if ( empty( $models ) ) {
			wptsall_log_debug(
				'models',
				'No models associated with relation',
				array( 'relation_id' => $relation_id, 'post_type' => $post_type, 'data_type' => $data_type )
			);
			return self::get_default_config( $post_type );
		}

		foreach ( $models as $model ) {
			$model_id = (int) $model['id'];

			if ( 'term' === $data_type ) {
				$rule = self::get_rule_by_taxonomy( $post_type, $model_id );
			} else {
				$rule = self::get_rule_by_post_type( $model_id, $post_type );
			}

			if ( $rule ) {
				$config = self::get_merged_config_internal( (int) $rule['id'], $relation_id );

				if ( $config ) {
					if ( ! empty( $config['fields'] ) ) {
						$config['fields'] = self::ensure_v3_format( $config['fields'] );
					}
					$config['related_taxonomies'] = $rule['related_taxonomies'] ?? array();
					return self::convert_to_capabilities_format( $config );
				}
			}
		}

		wptsall_log_debug(
			'models',
			'No rule found, using default config',
			array( 'relation_id' => $relation_id, 'post_type' => $post_type, 'data_type' => $data_type )
		);
		return self::get_default_config( $post_type );
	}

	/**
	 * Get default field configuration.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	private static function get_default_config( string $post_type ): array {
		return array(
			'translate_fields'   => array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ),
			'sync_fields'        => array( 'post_date', 'post_status', 'menu_order', 'post_parent' ),
			'copy_once_fields'   => array(),
			'id_mapping_fields'  => array( '_thumbnail_id', 'post_author' ),
			'compute_fields'     => array( 'guid', 'comment_count' ),
			'skip_fields'        => array( '_edit_lock', '_edit_last' ),
			'id_mapping_details' => array(
				'_thumbnail_id' => array( 'reference_type' => 'media', 'value_format' => 'scalar' ),
				'post_author'   => array( 'reference_type' => 'user', 'value_format' => 'scalar' ),
				'post_parent'   => array( 'reference_type' => 'post', 'value_format' => 'scalar' ),
			),
			'field_capabilities' => array(),
			'related_taxonomies' => array(),
		);
	}

	/**
	 * Convert internal config format to field_capabilities output format.
	 *
	 * @param array $config Internal config.
	 * @return array
	 */
	private static function convert_to_capabilities_format( array $config ): array {
		$result = array(
			'translate_fields'   => array(),
			'sync_fields'        => array(),
			'copy_once_fields'   => array(),
			'id_mapping_fields'  => array(),
			'compute_fields'     => array(),
			'skip_fields'        => array(),
			'id_mapping_details' => array(),
			'field_capabilities' => $config['fields'] ?? array(),
			'related_taxonomies' => $config['related_taxonomies'] ?? array(),
		);

		foreach ( $config['fields'] ?? array() as $field_name => $field_config ) {
			if ( ! ( $field_config['enabled'] ?? true ) ) {
				continue;
			}

			$type = Field_Capability::normalize_type( (string) ( $field_config['type'] ?? 'sync' ) );

			switch ( $type ) {
				case 'translate':
					$result['translate_fields'][] = $field_name;
					break;
				case 'sync':
					$result['sync_fields'][] = $field_name;
					break;
				case 'copy_once':
					$result['copy_once_fields'][] = $field_name;
					break;
				case 'mapping':
				case 'id_mapping':
					$result['id_mapping_fields'][] = $field_name;
					$detail = array();
					if ( ! empty( $field_config['reference_type'] ) ) {
						$detail['reference_type'] = $field_config['reference_type'];
					}
					if ( ! empty( $field_config['value_format'] ) ) {
						$detail['value_format'] = $field_config['value_format'];
					}
					if ( ! empty( $detail ) ) {
						$result['id_mapping_details'][ $field_name ] = $detail;
					}
					break;
				case 'compute':
					$result['compute_fields'][] = $field_name;
					break;
				case 'no_sync':
				case 'skip':
					$result['skip_fields'][] = $field_name;
					break;
			}
		}

		return $result;
	}

	/**
	 * Get merged configuration (internal method).
	 *
	 * @param int      $rule_id Rule ID.
	 * @param int|null $relation_id Relation ID.
	 * @param array    $options Options.
	 * @return array|null
	 */
	private static function get_merged_config_internal( $rule_id, $relation_id = null, $options = array() ) {
		$options              = is_array( $options ) ? $options : array();
		$suppress_warning_log = ! empty( $options['suppress_warning_log'] );
		$rule                 = self::get_rule( $rule_id );

		if ( ! $rule ) {
			return null;
		}

		wptsall_log_debug(
			'models-config',
			'[ISS-TSK-030] Rule is_active raw value',
			array(
				'rule_id'        => $rule_id,
				'relation_id'    => $relation_id,
				'object_name'    => $rule['object_name'] ?? '',
				'is_active_raw'  => $rule['is_active'] ?? 'NOT_SET',
				'is_active_type' => gettype( $rule['is_active'] ?? null ),
			)
		);

		$field_capabilities = ! empty( $rule['field_capabilities'] )
			? $rule['field_capabilities']
			: self::build_capabilities_from_legacy( $rule );

		$base_config = array(
			'enabled'   => (bool) ( $rule['is_active'] ?? true ),
			'direction' => $rule['direction'] ?? 'one_way',
			'sync_mode' => 'new_only',
			'fields'    => $field_capabilities,
			'rule_id'   => (int) $rule['id'],
			'model_id'  => (int) $rule['model_id'],
			'post_type' => $rule['object_name'] ?? '',
			'data_type' => $rule['data_type'] ?? 'post',
		);

		wptsall_log_debug(
			'models-config',
			'[ISS-TSK-030] Base config enabled (from Model layer)',
			array(
				'rule_id'      => $rule_id,
				'relation_id'  => $relation_id,
				'post_type'    => $base_config['post_type'],
				'base_enabled' => $base_config['enabled'],
			)
		);

		if ( ! $relation_id ) {
			return $base_config;
		}

		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Config_Service' ) ) {
			return $base_config;
		}

		$override          = \WPTSALL\Sites\Services\Relation_Config_Service::get(
			$relation_id,
			$rule['object_name'],
			$rule['data_type'] ?? ''
		);
		$relation_defaults = null;
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			$relation_defaults = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( (int) $relation_id );
		}

		if ( ! $override ) {
			wptsall_log_debug(
				'models-config',
				'[ISS-TSK-030] No post_type override config, using base_config + relation defaults',
				array(
					'rule_id'             => $rule_id,
					'relation_id'         => $relation_id,
					'post_type'           => $rule['object_name'] ?? '',
					'base_enabled'        => $base_config['enabled'],
					'relation_sync_mode'  => $relation_defaults['sync_mode'] ?? null,
					'relation_direction'  => $relation_defaults['direction'] ?? null,
				)
			);
			$override = array();
		}

		$rule_override = array();
		if ( ! empty( $override['rule_overrides'] ) && is_array( $override['rule_overrides'] ) ) {
			$candidate = $override['rule_overrides'][ (string) $rule_id ] ?? array();
			if ( is_array( $candidate ) ) {
				$rule_override = $candidate;
			}
		}

		wptsall_log_debug(
			'models-config',
			'[ISS-TSK-030] Override config found (Site layer)',
			array(
				'rule_id'                => $rule_id,
				'relation_id'            => $relation_id,
				'post_type'              => $rule['object_name'] ?? '',
				'override_enabled_isset' => isset( $override['enabled'] ),
				'override_enabled_value' => $override['enabled'] ?? 'NOT_SET',
				'override_enabled_type'  => gettype( $override['enabled'] ?? null ),
			)
		);

		$final_config                = $base_config;
		$final_config['relation_id'] = (int) $relation_id;
		$warnings                    = array();

		if ( isset( $override['enabled'] ) && null !== $override['enabled'] ) {
			if ( ! $base_config['enabled'] && $override['enabled'] ) {
				$warnings[] = __( 'Site Relation cannot enable a disabled Model rule', 'wpmmcc-ats' );
			} else {
				$final_config['enabled'] = (bool) $override['enabled'];
			}
		}
		if ( isset( $rule_override['enabled'] ) && null !== $rule_override['enabled'] ) {
			if ( ! $base_config['enabled'] && $rule_override['enabled'] ) {
				$warnings[] = __( 'Rule override cannot enable a disabled Model rule', 'wpmmcc-ats' );
			} else {
				$final_config['enabled'] = (bool) $rule_override['enabled'];
			}
		}

		wptsall_log_debug(
			'models-config',
			'[ISS-TSK-030] Final enabled after merge',
			array(
				'rule_id'        => $rule_id,
				'relation_id'    => $relation_id,
				'post_type'      => $rule['object_name'] ?? '',
				'base_enabled'   => $base_config['enabled'],
				'final_enabled'  => $final_config['enabled'],
				'enabled_source' => ( $final_config['enabled'] === $base_config['enabled'] ) ? 'model' : 'override',
			)
		);

		if ( ! empty( $relation_defaults['direction'] ?? null ) ) {
			$model_dir      = $base_config['direction'];
			$site_dir       = $relation_defaults['direction'];
			$is_model_oneway = in_array( $model_dir, array( 'one_way', 'forward', 'source_to_target' ), true );
			$is_site_bidir   = in_array( $site_dir, array( 'bidirectional', 'both', 'two_way' ), true );

			if ( $is_model_oneway && $is_site_bidir ) {
				$warnings[] = __( 'Site Relation cannot upgrade one_way to bidirectional', 'wpmmcc-ats' );
			} else {
				$final_config['direction'] = $site_dir;
			}
		}

		if ( ! empty( $override['direction'] ) ) {
			$model_dir       = $base_config['direction'];
			$site_dir        = $override['direction'];
			$is_model_oneway = in_array( $model_dir, array( 'one_way', 'forward', 'source_to_target' ), true );
			$is_site_bidir   = in_array( $site_dir, array( 'bidirectional', 'both', 'two_way' ), true );

			if ( $is_model_oneway && $is_site_bidir ) {
				$warnings[] = __( 'Site Relation cannot upgrade one_way to bidirectional', 'wpmmcc-ats' );
			} else {
				$final_config['direction'] = $site_dir;
			}
		}
		if ( ! empty( $rule_override['direction'] ) ) {
			$model_dir       = $base_config['direction'];
			$site_dir        = $rule_override['direction'];
			$is_model_oneway = in_array( $model_dir, array( 'one_way', 'forward', 'source_to_target' ), true );
			$is_site_bidir   = in_array( $site_dir, array( 'bidirectional', 'both', 'two_way' ), true );

			if ( $is_model_oneway && $is_site_bidir ) {
				$warnings[] = __( 'Rule override cannot upgrade one_way to bidirectional', 'wpmmcc-ats' );
			} else {
				$final_config['direction'] = $site_dir;
			}
		}

		$final_config['sync_mode'] = 'new_only';

		if ( ! empty( $override['field_overrides'] ) && is_array( $override['field_overrides'] ) ) {
			foreach ( $override['field_overrides'] as $field_key => $settings ) {
				if ( ! is_array( $settings ) ) {
					continue;
				}

				$base_field = $final_config['fields'][ $field_key ] ?? null;

				if ( ! $base_field ) {
					$warnings[] = sprintf(
						/* translators: %s: <value> */
						__( "Field '%s' not defined in Model, Site Relation config ignored", 'wpmmcc-ats' ),
						$field_key
					);
					continue;
				}

				$base_type = $base_field['type'] ?? '';
				$site_type = $settings['type'] ?? $base_type;

				if ( 'no_sync' === $base_type && 'no_sync' !== $site_type ) {
					/* translators: field key identifier */
					$warnings[] = sprintf(
						/* translators: %s: <value> */
						__( "Field '%s' is no_sync in Model, Site Relation cannot enable sync", 'wpmmcc-ats' ),
						$field_key
					);
					unset( $settings['type'] );
				}

				$base_field_dir = $base_field['direction'] ?? 'one_way';
				$site_field_dir = $settings['direction'] ?? $base_field_dir;
				$is_field_oneway = in_array( $base_field_dir, array( 'one_way', 'forward' ), true );
				$is_site_bidir   = in_array( $site_field_dir, array( 'bidirectional', 'two_way' ), true );

				/* translators: field key identifier */
				if ( $is_field_oneway && $is_site_bidir ) {
					$warnings[] = sprintf(
						/* translators: %s: <value> */
						__( "Field '%s' is one_way in Model, Site Relation cannot set bidirectional", 'wpmmcc-ats' ),
						$field_key
					);
					unset( $settings['direction'] );
				}

				$base_enabled = $base_field['enabled'] ?? true;
				$site_enabled = $settings['enabled'] ?? $base_enabled;

/* translators: field key identifier */

				if ( ! $base_enabled && $site_enabled ) {
					$warnings[] = sprintf(
						/* translators: %s: <value> */
						__( "Field '%s' is disabled in Model, Site Relation cannot enable", 'wpmmcc-ats' ),
						$field_key
					);
					unset( $settings['enabled'] );
				}

				if ( ! empty( $settings ) ) {
					$final_config['fields'][ $field_key ] = array_merge(
						$final_config['fields'][ $field_key ],
						$settings
					);
				}
			}
		}

		if ( ! empty( $warnings ) ) {
			if ( ! $suppress_warning_log ) {
				wptsall_log_warning(
					'models',
					'Config validation: Site Relation conflicts with Model (using Model values)',
					array(
						'rule_id'     => $rule_id,
						'relation_id' => $relation_id,
						'conflicts'   => $warnings,
					)
				);
			}
			$final_config['config_warnings'] = $warnings;
		}

		return $final_config;
	}

	/**
	 * Public merged config API.
	 *
	 * @param int      $rule_id Rule ID.
	 * @param int|null $relation_id Relation ID.
	 * @param array    $options Options.
	 * @return array|null
	 */
	public static function get_merged_config( $rule_id, $relation_id = null, $options = array() ) {
		$config = self::get_merged_config_internal( $rule_id, $relation_id, $options );

		if ( is_array( $config ) && ! empty( $config['fields'] ) ) {
			$config['fields'] = self::ensure_v3_format( $config['fields'] );
		}

		return $config;
	}

	/**
	 * Build field_capabilities from legacy fields.
	 *
	 * @param array $rule Rule data.
	 * @return array
	 */
	private static function build_capabilities_from_legacy( $rule ) {
		$capabilities = array();

		$translate = $rule['translate_fields'] ?? array();
		if ( is_string( $translate ) ) {
			$translate = json_decode( $translate, true ) ?: array();
		}
		foreach ( self::extract_field_names( $translate ) as $field ) {
			$capabilities[ $field ] = array(
				'type'      => 'translate',
				'direction' => 'one_way',
				'enabled'   => true,
			);
		}

		$sync = $rule['sync_fields'] ?? array();
		if ( is_string( $sync ) ) {
			$sync = json_decode( $sync, true ) ?: array();
		}
		foreach ( self::extract_field_names( $sync ) as $field ) {
			$capabilities[ $field ] = array(
				'type'      => 'sync',
				'direction' => 'one_way',
				'enabled'   => true,
			);
		}

		$mappings = $rule['field_mappings'] ?? array();
		if ( is_string( $mappings ) ) {
			$mappings = json_decode( $mappings, true ) ?: array();
		}
		if ( is_array( $mappings ) ) {
			foreach ( $mappings as $field => $type ) {
				$capabilities[ $field ] = array(
					'type'      => 'id_mapping',
					'direction' => 'one_way',
					'enabled'   => true,
				);
			}
		}

		$compute = $rule['compute_fields'] ?? array();
		if ( is_string( $compute ) ) {
			$compute = json_decode( $compute, true ) ?: array();
		}
		foreach ( self::extract_field_names( $compute ) as $field ) {
			$capabilities[ $field ] = array(
				'type'    => 'compute',
				'enabled' => true,
			);
		}

		return $capabilities;
	}

	/**
	 * Extract field names from field array.
	 *
	 * @param array $fields Field array.
	 * @return array
	 */
	private static function extract_field_names( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$names = array();
		foreach ( $fields as $field ) {
			if ( is_string( $field ) ) {
				$names[] = $field;
			} elseif ( is_array( $field ) && isset( $field['name'] ) ) {
				$names[] = $field['name'];
			}
		}

		return $names;
	}

	/**
	 * Log configuration mismatch warnings.
	 *
	 * @param array $base Base config.
	 * @param array $final Final config.
	 * @param int   $rule_id Rule ID.
	 * @param int   $relation_id Relation ID.
	 * @return void
	 */
	private static function log_config_warnings( $base, $final, $rule_id, $relation_id ) {
		$warnings = array();

		foreach ( $final['fields'] as $field => $config ) {
			if ( ! isset( $base['fields'][ $field ] ) ) {
				continue;
			}

/* translators: field key identifier */

			$base_field = $base['fields'][ $field ];

			if ( ( $base_field['type'] ?? '' ) === 'no_sync' && ( $config['type'] ?? '' ) !== 'no_sync' ) {
				$warnings[] = sprintf(
					/* translators: %s: <value> */
					__( "Field '%s' is marked as no_sync in model but overridden in relation", 'wpmmcc-ats' ),
					$field
				);
			}

/* translators: value */

			if ( isset( $base_field['direction'] ) && isset( $config['direction'] ) && $base_field['direction'] === 'one_way' && $config['direction'] === 'two_way' ) {
				$warnings[] = sprintf(
					/* translators: %s: <value> */
					__( "Field '%s' is configured as one_way in model but two_way in relation", 'wpmmcc-ats' ),
					$field
				);
			}
		}

		if ( ! empty( $warnings ) ) {
			wptsall_log_warning(
				'models',
				'Config merge warnings',
				array(
					'rule_id'     => $rule_id,
					'relation_id' => $relation_id,
					'warnings'    => $warnings,
				)
			);
		}
	}

	/**
	 * Group config fields by capability type.
	 *
	 * @param array $config Merged config.
	 * @return array
	 */
	public static function get_fields_by_type( $config ) {
		$grouped = array(
			'translate'  => array(),
			'sync'       => array(),
			'id_mapping' => array(),
			'compute'    => array(),
		);

		if ( empty( $config['fields'] ) || ! is_array( $config['fields'] ) ) {
			return $grouped;
		}

		foreach ( $config['fields'] as $field_key => $field_config ) {
			if ( ! ( $field_config['enabled'] ?? true ) ) {
				continue;
			}

			$type = $field_config['type'] ?? 'sync';
			if ( 'no_sync' === $type || 'skip' === $type ) {
				continue;
			}
			if ( 'mapping' === $type ) {
				$type = 'id_mapping';
			}

			if ( isset( $grouped[ $type ] ) ) {
				$grouped[ $type ][] = $field_key;
			}
		}

		return $grouped;
	}

	/**
	 * Get default term field capabilities.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	public static function get_default_term_field_capabilities( $taxonomy ) {
		$core_defaults = self::get_core_field_defaults();
		$term_fields   = array( 'name', 'description', 'slug', 'parent' );
		$capabilities  = array();

		foreach ( $term_fields as $field_name ) {
			if ( isset( $core_defaults[ $field_name ] ) ) {
				$capabilities[ $field_name ] = $core_defaults[ $field_name ];
			}
		}

		return $capabilities;
	}

	/**
	 * Ensure field_capabilities are in v3 format.
	 *
	 * @param array  $field_capabilities Field capabilities.
	 * @param string $data_type Rule data type.
	 * @return array
	 */
	public static function ensure_v3_format( array $field_capabilities, string $data_type = 'post' ): array {
		$core_defaults = self::get_core_field_defaults();
		$post_columns  = self::get_post_column_fields();
		$term_columns  = self::get_term_column_fields();

		foreach ( $field_capabilities as $field_key => &$field_config ) {
			if ( ! is_array( $field_config ) ) {
				continue;
			}

			if ( 'translate' !== ( $field_config['type'] ?? '' ) ) {
				continue;
			}

			if ( empty( $field_config['content_format'] ) ) {
				if ( isset( $core_defaults[ $field_key ] ) && ! empty( $core_defaults[ $field_key ]['content_format'] ) ) {
					$field_config['content_format'] = $core_defaults[ $field_key ]['content_format'];
				} elseif ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
					$field_config['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::infer_content_format( $field_key );
				} else {
					$field_config['content_format'] = 'plain_text';
				}
			}

			if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
				$field_config['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::normalize_content_format(
					(string) ( $field_config['content_format'] ?? '' ),
					'plain_text'
				);
			}

			if ( empty( $field_config['storage'] ) ) {
				if ( 'term' === $data_type ) {
					$field_config['storage'] = in_array( $field_key, $term_columns, true ) ? 'term_column' : 'term_meta';
				} else {
					$field_config['storage'] = in_array( $field_key, $post_columns, true ) ? 'post_column' : 'post_meta';
				}
			}
		}
		unset( $field_config );

		return $field_capabilities;
	}

	/**
	 * Get post column fields.
	 *
	 * @return string[]
	 */
	public static function get_post_column_fields(): array {
		return array(
			'post_title', 'post_content', 'post_excerpt', 'post_name',
			'post_status', 'post_date', 'post_date_gmt', 'post_modified',
			'post_modified_gmt', 'post_author', 'post_parent', 'post_type',
			'post_mime_type', 'post_password', 'post_content_filtered',
			'guid', 'menu_order', 'comment_status', 'ping_status',
			'to_ping', 'pinged', 'comment_count',
		);
	}

	/**
	 * Get term column fields.
	 *
	 * @return string[]
	 */
	public static function get_term_column_fields(): array {
		return array( 'name', 'slug', 'description', 'parent', 'count' );
	}

	/**
	 * Normalize field_capabilities from v2/v3 input into v3 format.
	 *
	 * @param array  $field_caps Raw field_capabilities array.
	 * @param string $data_type Rule data type.
	 * @return array
	 */
	public static function normalize_field_capabilities( array $field_caps, string $data_type = 'post' ): array {
		$metadata_keys = array( 'translate_fields', 'sync_fields', 'id_mapping_fields', 'compute_fields' );
		$normalized    = array();

		foreach ( $field_caps as $key => $value ) {
			if ( in_array( $key, $metadata_keys, true ) ) {
				$normalized[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$type = 'mapping' === $value ? 'id_mapping' : $value;

				$field_config = array(
					'type'    => $type,
					'enabled' => true,
				);

				if ( 'translate' === $type ) {
					if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
						$field_config['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::normalize_content_format(
							\WPTSALL\Core\Smart_Field_Classifier::infer_content_format( (string) $key ),
							'plain_text'
						);
					} else {
						$field_config['content_format'] = 'plain_text';
					}
				}

				$normalized[ $key ] = $field_config;
				continue;
			}

			if ( is_array( $value ) ) {
				$field_config = $value;

				if ( isset( $field_config['type'] ) && 'mapping' === $field_config['type'] ) {
					$field_config['type'] = 'id_mapping';
				}

				if ( 'translate' === ( $field_config['type'] ?? '' ) ) {
					if ( empty( $field_config['content_format'] ) ) {
						if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
							$field_config['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::infer_content_format( (string) $key );
						} else {
							$field_config['content_format'] = 'plain_text';
						}
					}
					if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
						$field_config['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::normalize_content_format(
							(string) $field_config['content_format'],
							'plain_text'
						);
					}
				}

				$normalized[ $key ] = $field_config;
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return self::ensure_v3_format( $normalized, $data_type );
	}

	/**
	 * Save normalized field_capabilities for a rule.
	 *
	 * @param int   $rule_id Rule ID.
	 * @param array $field_caps Field capabilities.
	 * @return void
	 */
	public static function save_field_capabilities( int $rule_id, array $field_caps ): void {
		self::update_rule(
			$rule_id,
			array( 'field_capabilities' => self::normalize_field_capabilities( $field_caps ) )
		);
	}

	/**
	 * Infer value_format from a data_type string.
	 *
	 * @param string $data_type Data type.
	 * @return string
	 */
	private static function infer_value_format( string $data_type ): string {
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
	 * Authoritative core field defaults mapping table.
	 *
	 * @return array
	 */
	public static function get_core_field_defaults(): array {
		$defaults = array(
			'post_title'   => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'core',
			),
			'post_content' => array(
				'type'           => 'translate',
				'content_format' => 'rich_html',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'core',
			),
			'post_excerpt' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'core',
			),
			'post_name'    => array(
				'type'           => 'translate',
				'content_format' => 'slug',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'core',
			),
			'guid'         => array(
				'type'    => 'compute',
				'enabled' => true,
				'source'  => 'core',
			),
			'post_status'  => array(
				'type'      => 'sync',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'post_date'    => array(
				'type'      => 'sync',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'post_author'  => array(
				'type'             => 'id_mapping',
				'direction'        => 'one_way',
				'enabled'          => true,
				'source'           => 'core',
				'reference_type'   => 'user',
				'reference_target' => 'user',
				'value_format'     => 'scalar',
			),
			'post_parent'  => array(
				'type'             => 'id_mapping',
				'direction'        => 'one_way',
				'enabled'          => true,
				'source'           => 'core',
				'reference_type'   => 'post',
				'reference_target' => 'self',
				'value_format'     => 'scalar',
			),
			'_thumbnail_id' => array(
				'type'             => 'id_mapping',
				'direction'        => 'one_way',
				'enabled'          => true,
				'source'           => 'core',
				'reference_type'   => 'media',
				'reference_target' => 'attachment',
				'value_format'     => 'scalar',
			),
			'name'         => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'core',
			),
			'description'  => array(
				'type'           => 'translate',
				'content_format' => 'rich_html',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'core',
			),
			'slug'         => array(
				'type'           => 'translate',
				'content_format' => 'slug',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'core',
			),
			'parent'       => array(
				'type'             => 'id_mapping',
				'direction'        => 'one_way',
				'enabled'          => true,
				'source'           => 'core',
				'reference_type'   => 'term',
				'reference_target' => 'self',
				'value_format'     => 'scalar',
			),
			'_wptsall_source_post_id' => array(
				'type'             => 'id_mapping',
				'direction'        => 'one_way',
				'enabled'          => true,
				'source'           => 'core',
				'reference_type'   => 'post',
				'reference_target' => 'self',
				'value_format'     => 'scalar',
			),
			'_wptsall_source_term_id' => array(
				'type'             => 'id_mapping',
				'direction'        => 'one_way',
				'enabled'          => true,
				'source'           => 'core',
				'reference_type'   => 'taxonomy',
				'reference_target' => 'self',
				'value_format'     => 'scalar',
			),
			'_wptsall_source_blog_id' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'_wptsall_relation_id' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'_wptsall_origin_object_id' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'_wptsall_origin_site_id' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			// Virtual/runtime bookkeeping written during writeback & term sync.
			// These must stay skip so auto rule creation is not blocked by
			// incomplete id_mapping dependency validation (e.g. category term).
			'_wptsall_virtual_site_id' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'_wptsall_last_synced' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'_wptsall_origin_site_type' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'_wptsall_origin_object_type' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			'_wptsall_origin_subtype' => array(
				'type'      => 'skip',
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'core',
			),
			// Plugin field rules (Yoast / Rank Math / Elementor) are no longer
			// hardcoded here. See self::get_plugin_field_defaults() and the
			// WPTSALL\Models\Adapters\Plugin_Field_Rules_Adapter interface —
			// adding a new SEO/Builder plugin is config-driven, not code-driven.
			'_wp_attachment_image_alt' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'core',
			),
		);

		return apply_filters( 'wptsall_core_field_defaults', $defaults );
	}

	/**
	 * Plugin field defaults — SEO/Builder meta keys with plugin-specific
	 * content format and type rules.
	 *
	 * This is Phase 2B step 1: the rules are now in a dedicated method so
	 * the next refactor (named adapter classes per plugin) is mechanical.
	 * Each plugin's rules are grouped under a 'plugin' key so callers can
	 * find them; the flat list is the actual return value.
	 *
	 * Adding a new SEO/Builder plugin:
	 *   1. Drop a new method get_{plugin_slug}_field_rules() returning the array.
	 *   2. Add a case to the switch in this method.
	 * Future: each method becomes a Plugin_Field_Rules_Adapter implementation.
	 *
	 * @return array
	 */
	public static function get_plugin_field_defaults(): array {
		// Phase 2B step 2: rules come from Plugin_Field_Rules_Registry
		// (per-plugin adapter classes). The legacy self::get_*_field_rules()
		// methods below are kept as thin shims for backward compat and
		// for tests that target one plugin in isolation.
		$rules = class_exists( '\\WPTSALL\\Models\\Adapters\\Plugin_Field_Rules_Registry' )
			? \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::get_all_field_rules()
			: array_merge(
				self::get_yoast_field_rules(),
				self::get_rank_math_field_rules(),
				self::get_elementor_field_rules()
			);
		return apply_filters( 'wptsall_plugin_field_defaults', $rules );
	}

	/**
	 * Yoast SEO (wordpress-seo) meta key field rules.
	 *
	 * @return array
	 */
	public static function get_yoast_field_rules(): array {
		if ( class_exists( '\\WPTSALL\\Models\\Adapters\\Yoast_Field_Rules_Adapter' ) ) {
			return ( new \WPTSALL\Models\Adapters\Yoast_Field_Rules_Adapter() )->get_field_rules();
		}
		return array();
	}

	/**
	 * Rank Math SEO (seo-by-rank-math) meta key field rules.
	 *
	 * @return array
	 */
	public static function get_rank_math_field_rules(): array {
		if ( class_exists( '\\WPTSALL\\Models\\Adapters\\Rank_Math_Field_Rules_Adapter' ) ) {
			return ( new \WPTSALL\Models\Adapters\Rank_Math_Field_Rules_Adapter() )->get_field_rules();
		}
		return array();
	}

	/**
	 * Elementor (_elementor_data) field rules.
	 *
	 * @return array
	 */
	public static function get_elementor_field_rules(): array {
		if ( class_exists( '\\WPTSALL\\Models\\Adapters\\Elementor_Field_Rules_Adapter' ) ) {
			return ( new \WPTSALL\Models\Adapters\Elementor_Field_Rules_Adapter() )->get_field_rules();
		}
		return array();
	}

	/**
	 * Get default field capabilities for a post_type.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function get_default_field_capabilities( $post_type ) {
		$core_defaults = self::get_core_field_defaults();
		$post_fields   = array(
			'post_title', 'post_content', 'post_excerpt',
			'post_name', 'guid',
			'post_status', 'post_date',
			'post_author', 'post_parent',
		);

		$capabilities = array();
		foreach ( $post_fields as $field_name ) {
			if ( isset( $core_defaults[ $field_name ] ) ) {
				$capabilities[ $field_name ] = $core_defaults[ $field_name ];
			}
		}

		$capabilities['menu_order'] = array(
			'type'      => 'sync',
			'direction' => 'one_way',
			'enabled'   => true,
			'source'    => 'core',
		);

		if ( post_type_supports( $post_type, 'thumbnail' ) && isset( $core_defaults['_thumbnail_id'] ) ) {
			$capabilities['_thumbnail_id'] = $core_defaults['_thumbnail_id'];
		}

		foreach ( array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' ) as $field ) {
			$capabilities[ $field ] = array(
				'type'    => 'no_sync',
				'enabled' => false,
				'source'  => 'core',
			);
		}

		return $capabilities;
	}

	/**
	 * Build field capabilities from scan result data.
	 *
	 * @param string $post_type Post type.
	 * @param array  $scan_result Scan result.
	 * @return array
	 */
	private static function build_field_capabilities_v2( $post_type, $scan_result ) {
		$capabilities  = self::get_default_field_capabilities( $post_type );
		$scanned_fields = array();

		if ( ! empty( $scan_result['fields'] ) && is_array( $scan_result['fields'] ) ) {
			$scanned_fields = $scan_result['fields'];
		} elseif ( ! empty( $scan_result['meta_fields'] ) && is_array( $scan_result['meta_fields'] ) ) {
			$scanned_fields = $scan_result['meta_fields'];
		}

		if ( empty( $scanned_fields ) ) {
			return $capabilities;
		}

		foreach ( $scanned_fields as $field_name => $field_data ) {
			if ( is_string( $field_data ) ) {
				$field_data = array( 'classification' => $field_data );
			}

			if ( ! is_array( $field_data ) ) {
				continue;
			}

			if ( isset( $capabilities[ $field_name ] ) && 'core' === ( $capabilities[ $field_name ]['source'] ?? '' ) ) {
				continue;
			}

			$classification = $field_data['classification'] ?? $field_data['type'] ?? 'sync';
			$cap_type       = self::scan_classification_to_capability_type( $classification );
			$field_cap      = array(
				'type'      => $cap_type,
				'direction' => 'one_way',
				'enabled'   => true,
				'source'    => 'scan',
			);

			if ( 'translate' === $cap_type ) {
				$core_defaults     = self::get_core_field_defaults();
				$plugin_defaults   = self::get_plugin_field_defaults();
				$combined_defaults = array_merge( $core_defaults, $plugin_defaults );
				if ( isset( $combined_defaults[ $field_name ] ) && is_array( $combined_defaults[ $field_name ] ) ) {
					foreach ( array( 'content_format', 'task_type', 'storage', 'direction' ) as $copy_key ) {
						if ( ! empty( $combined_defaults[ $field_name ][ $copy_key ] ) ) {
							$field_cap[ $copy_key ] = $combined_defaults[ $field_name ][ $copy_key ];
						}
					}
				} elseif ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
					$field_cap['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::infer_content_format( $field_name );
				} else {
					$field_cap['content_format'] = 'plain_text';
				}
				if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
					$field_cap['content_format'] = \WPTSALL\Core\Smart_Field_Classifier::normalize_content_format(
						(string) ( $field_cap['content_format'] ?? '' ),
						'plain_text'
					);
				}
				if ( 'media_ref' === ( $field_cap['content_format'] ?? '' ) && empty( $field_cap['task_type'] ) ) {
					$key = strtolower( (string) $field_name );
					if ( preg_match( '/(video|mp4|webm|movie|trailer)/', $key ) ) {
						$field_cap['task_type'] = 'video';
					} elseif ( preg_match( '/(audio|mp3|podcast|sound|voice|wav)/', $key ) ) {
						$field_cap['task_type'] = 'audio';
					} elseif ( preg_match( '/(pdf|document|docx?|xlsx?|file|download|attachment)/', $key ) ) {
						$field_cap['task_type'] = 'document';
					} else {
						$field_cap['task_type'] = 'image';
					}
				}
			}

			if ( 'id_mapping' === $cap_type ) {
				$reference_type = $field_data['reference_type'] ?? null;
				if ( empty( $reference_type ) ) {
					$reference_type = Id_Mapping_Resolver::infer_reference_type( $field_name );
				}

				$reference_target = $field_data['reference_target'] ?? null;
				if ( '' === (string) $reference_target ) {
					$reference_target = self::infer_reference_target( $reference_type, $field_name );
				}

				$field_cap['reference_type']   = $reference_type;
				$field_cap['reference_target'] = $reference_target;
				$field_cap['value_format']     = $field_data['value_format'] ?? 'scalar';
			}

			$capabilities[ $field_name ] = $field_cap;
		}

		return $capabilities;
	}

	/**
	 * Infer reference_target from reference_type and field name.
	 *
	 * @param string $reference_type Reference type.
	 * @param string $field_name Field name.
	 * @return string
	 */
	private static function infer_reference_target( $reference_type, $field_name ) {
		switch ( $reference_type ) {
			case 'media':
				return 'attachment';
			case 'user':
				return 'user';
			case 'term':
				return 'term';
			case 'post':
				if ( 'post_parent' === $field_name || preg_match( '/_parent$/i', $field_name ) ) {
					return 'self';
				}
				return 'post';
			default:
				return 'generic';
		}
	}

	/**
	 * Convert scan classification string to capability type.
	 *
	 * @param string $classification Classification.
	 * @return string
	 */
	private static function scan_classification_to_capability_type( $classification ) {
		$map = array(
			'translatable' => 'translate',
			'translate'    => 'translate',
			'sync'         => 'sync',
			'id_mapping'   => 'id_mapping',
			'mapping'      => 'id_mapping',
			'compute'      => 'compute',
			'skip'         => 'no_sync',
			'no_sync'      => 'no_sync',
		);

		return $map[ $classification ] ?? 'sync';
	}

	/**
	 * Discover related taxonomies for a post_type.
	 *
	 * @param string $post_type Post type.
	 * @param array  $model_tax_names Taxonomy names from the model.
	 * @return array
	 */
	private static function discover_related_taxonomies( string $post_type, array $model_tax_names ): array {
		$related = array();
		foreach ( get_object_taxonomies( $post_type, 'names' ) as $tax_name ) {
			if ( in_array( $tax_name, $model_tax_names, true ) ) {
				$related[] = $tax_name;
			}
		}

		if ( empty( $related ) ) {
			wptsall_log_debug(
				'models-service',
				'No runtime taxonomies found for post_type',
				array(
					'post_type'       => $post_type,
					'model_tax_names' => $model_tax_names,
				)
			);
		}

		return array_unique( $related );
	}
}
