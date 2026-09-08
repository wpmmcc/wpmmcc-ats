<?php
/**
 * Relation Config Service
 *
 * Manage site relation level Post Type configuration overrides
 *
 * @package WPTSALL\Sites\Services
 * @since 0.8.0
  * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers from internal helpers (wptsall_table / \$wpdb->prefix . 'wptsall_*'); user values use prepare placeholders.
 */
namespace WPTSALL\Sites\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Site relation config service class
 *
 * Process site relation level Post Type configuration overrides
 * Allow overriding model default configuration at site relation level
 */
class Relation_Config_Service {

	/**
	 * Allowed relation-level preflight policies for missing-component checks.
	 *
	 * @var string[]
	 */
	const TEMPLATE_PREFLIGHT_POLICIES = array( 'warn', 'block' );

	/**
	 * Allowed relation-level missing-component behaviors.
	 *
	 * @var string[]
	 */
	const TEMPLATE_MISSING_COMPONENT_BEHAVIORS = array( 'confirm_continue', 'skip_unbound_fields', 'stop_task' );

	/**
	 * Reserved key inside field_overrides for relation-level rule overrides.
	 *
	 * @var string
	 */
	const RULE_OVERRIDES_KEY = '__rule_overrides';

	public static function build_object_key( $object_name, $data_type = '' ) {
		$object_name = sanitize_key( (string) $object_name );
		$data_type   = sanitize_key( (string) $data_type );

		if ( '' === $data_type ) {
			return $object_name;
		}

		return $data_type . ':' . $object_name;
	}

	/**
	 * Get specific Post Type configuration for a relation
	 *
	 * @param int    $relation_id site relation ID.
	 * @param string $post_type   Post Type name.
	 * @return array|null Configuration array or null.
	 */
	public static function get( $relation_id, $post_type, $data_type = '' ) {
		global $wpdb;
		$table = wptsall_table( 'relation_post_type_configs' );
		$object_key = self::build_object_key( $post_type, $data_type );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE relation_id = %d AND post_type = %s',
				$table,
				$relation_id,
				$object_key
			),
			ARRAY_A
		);

		if ( ! $result && '' !== $data_type ) {
			$result = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE relation_id = %d AND post_type = %s',
					$table,
					$relation_id,
					sanitize_key( (string) $post_type )
				),
				ARRAY_A
			);
		}

		if ( $result && ! empty( $result['field_overrides'] ) ) {
			$result['field_overrides'] = json_decode( $result['field_overrides'], true );
		}
		if ( $result && is_array( $result ) ) {
			if ( ! empty( $result['post_type'] ) && strpos( (string) $result['post_type'], ':' ) !== false ) {
				$parts = explode( ':', (string) $result['post_type'], 2 );
				$result['data_type']   = sanitize_key( (string) $parts[0] );
				$result['object_name'] = sanitize_key( (string) $parts[1] );
			} else {
				$result['data_type']   = '';
				$result['object_name'] = sanitize_key( (string) ( $result['post_type'] ?? '' ) );
			}
			self::hydrate_special_overrides( $result );
		}

		return $result;
	}

	/**
	 * Get all Post Type configs for a relation
	 *
	 * @param int $relation_id site relation ID.
	 * @return array List of config arrays.
	 */
	public static function get_all_by_relation( $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'relation_post_type_configs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE relation_id = %d ORDER BY post_type ASC',
				$table,
				$relation_id
			),
			ARRAY_A
		);

		if ( ! $results ) {
			return array();
		}

		// parse JSON Field.
		foreach ( $results as &$result ) {
			if ( ! empty( $result['field_overrides'] ) ) {
				$result['field_overrides'] = json_decode( $result['field_overrides'], true );
			}
				if ( is_array( $result ) ) {
					if ( ! empty( $result['post_type'] ) && strpos( (string) $result['post_type'], ':' ) !== false ) {
						$parts = explode( ':', (string) $result['post_type'], 2 );
						$result['data_type']   = sanitize_key( (string) $parts[0] );
						$result['object_name'] = sanitize_key( (string) $parts[1] );
					} else {
						$result['data_type']   = '';
						$result['object_name'] = sanitize_key( (string) ( $result['post_type'] ?? '' ) );
					}
					self::hydrate_special_overrides( $result );
				}
			}

		return $results;
	}

	/**
	 * Save config (supports upsert)
	 *
	 * @param int    $relation_id site relation ID.
	 * @param string $post_type   Post Type name.
	 * @param array  $config      Config array.
	 * @return int|false On success returns ID, on failure returns false.
	 */
	public static function save( $relation_id, $post_type, $config, $data_type = '' ) {
		global $wpdb;
		$table = wptsall_table( 'relation_post_type_configs' );
		$now   = current_time( 'mysql' );
		$object_key = self::build_object_key(
			$post_type,
			'' !== sanitize_key( (string) $data_type ) ? $data_type : ( $config['data_type'] ?? '' )
		);

		$sync_mode = isset( $config['sync_mode'] ) ? sanitize_key( $config['sync_mode'] ) : null;
		if ( null !== $sync_mode ) {
			// Product decision (2026-01-29): sync_mode is fixed to "new_only".
			// Accept legacy values for backward compatibility, but normalize on write.
			if ( 'new_only' !== $sync_mode ) {
				wptsall_log_warning(
					'sites-config',
					'Deprecated sync_mode provided in relation_post_type_configs, forced to new_only',
					array(
						'relation_id' => (int) $relation_id,
							'post_type'   => (string) $object_key,
						'provided'    => (string) $sync_mode,
					)
				);
			}
			$sync_mode = 'new_only';
		}

		$direction = isset( $config['direction'] ) ? sanitize_key( $config['direction'] ) : null;
		if ( null !== $direction && ! in_array( $direction, array( 'one_way', 'source_to_target', 'forward', 'bidirectional', 'both', 'two_way' ), true ) ) {
			$direction = null;
		}

		$field_overrides = self::compose_field_overrides_for_storage( $config );

		$data = array(
			'relation_id'     => $relation_id,
			'post_type'       => $object_key,
			'enabled'         => isset( $config['enabled'] ) ? ( $config['enabled'] ? 1 : 0 ) : null,
			'direction'       => $direction,
			'sync_mode'       => $sync_mode,
			'field_overrides' => null === $field_overrides
				? null
				: wp_json_encode( $field_overrides ),
			'updated_at'      => $now,
		);

		$existing = self::get( $relation_id, $post_type, $data_type );

		if ( $existing ) {
			// Update existing config.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				$data,
				array(
					'relation_id' => $relation_id,
					'post_type'   => $object_key,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			if ( false === $result ) {
				wptsall_log_error(
					'sites-config',
					'Failed to update relation config',
					array(
						'relation_id' => $relation_id,
						'post_type'   => $post_type,
						'error'       => $wpdb->last_error,
					)
				);
				return false;
			}

			wptsall_log_info(
				'sites-config',
				'Relation config updated',
				array(
					'relation_id' => $relation_id,
					'post_type'   => $object_key,
				)
			);

			/**
			 * Fires when a relation config is updated.
			 *
			 * @since 1.0.0
			 * @param int $relation_id The relation ID.
			 */
			do_action( 'wptsall_relation_updated', $relation_id );

			return (int) $existing['id'];
		}

		// Insert new config.
		$data['created_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			$data,
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			wptsall_log_error(
				'sites-config',
				'Failed to insert relation config',
				array(
					'relation_id' => $relation_id,
					'post_type'   => $post_type,
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		wptsall_log_info(
			'sites-config',
			'Relation config created',
			array(
				'relation_id' => $relation_id,
				'post_type'   => $post_type,
				'id'          => $wpdb->insert_id,
			)
		);

		/**
		 * Fires when a relation config is updated.
		 *
		 * @since 1.0.0
		 * @param int $relation_id The relation ID.
		 */
		do_action( 'wptsall_relation_updated', $relation_id );

		return $wpdb->insert_id;
	}

	/**
	 * Hydrate reserved overrides from field_overrides into top-level fields.
	 *
	 * @param array $row Config row (passed by reference).
	 * @return void
	 */
	private static function hydrate_special_overrides( array &$row ) {
		if ( empty( $row['field_overrides'] ) || ! is_array( $row['field_overrides'] ) ) {
			$row['rule_overrides'] = array();
			return;
		}

		$raw_rule_overrides = $row['field_overrides'][ self::RULE_OVERRIDES_KEY ] ?? array();
		unset( $row['field_overrides'][ self::RULE_OVERRIDES_KEY ] );
		$row['rule_overrides'] = self::normalize_rule_overrides( $raw_rule_overrides );
	}

	/**
	 * Build persisted field_overrides payload from API config.
	 *
	 * @param array $config Raw config payload.
	 * @return array|null
	 */
	private static function compose_field_overrides_for_storage( array $config ) {
		$field_overrides = array();
		if ( isset( $config['field_overrides'] ) && is_array( $config['field_overrides'] ) ) {
			$field_overrides = $config['field_overrides'];
			unset( $field_overrides[ self::RULE_OVERRIDES_KEY ] );
		}

		$rule_overrides = self::normalize_rule_overrides( $config['rule_overrides'] ?? array() );
		if ( ! empty( $rule_overrides ) ) {
			$field_overrides[ self::RULE_OVERRIDES_KEY ] = $rule_overrides;
		}

		return empty( $field_overrides ) ? null : $field_overrides;
	}

	/**
	 * Normalize relation-level per-rule overrides.
	 *
	 * Supported keys:
	 * - enabled(bool): disable/enable a specific rule in this relation.
	 * - direction(string): optional direction override with coexistence checks.
	 *
	 * @param mixed $raw Raw overrides.
	 * @return array
	 */
	private static function normalize_rule_overrides( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed_directions = array( 'one_way', 'source_to_target', 'forward', 'bidirectional', 'both', 'two_way' );
		$normalized         = array();

		foreach ( $raw as $rule_id_raw => $override_raw ) {
			$rule_id = absint( $rule_id_raw );
			if ( $rule_id <= 0 || ! is_array( $override_raw ) ) {
				continue;
			}

			$item = array();
			if ( array_key_exists( 'enabled', $override_raw ) ) {
				$item['enabled'] = (bool) $override_raw['enabled'];
			}

			if ( isset( $override_raw['direction'] ) ) {
				$direction = sanitize_key( (string) $override_raw['direction'] );
				if ( in_array( $direction, $allowed_directions, true ) ) {
					$item['direction'] = $direction;
				}
			}

			if ( ! empty( $item ) ) {
				$normalized[ (string) $rule_id ] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Delete config
	 *
	 * @param int         $relation_id site relation ID.
	 * @param string|null $post_type   Post Type name (optional; when null, delete all).
	 * @return bool Returns true on success.
	 */
	public static function delete( $relation_id, $post_type = null ) {
		global $wpdb;
		$table = wptsall_table( 'relation_post_type_configs' );

		if ( $post_type ) {
			// Delete specific Post Type configuration.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->delete(
				$table,
				array(
					'relation_id' => $relation_id,
					'post_type'   => $post_type,
				),
				array( '%d', '%s' )
			);
		} else {
			// Delete all configs for this relation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->delete(
				$table,
				array( 'relation_id' => $relation_id ),
				array( '%d' )
			);
		}

		if ( false === $result ) {
			wptsall_log_error(
				'sites-config',
				'Failed to delete relation config',
				array(
					'relation_id' => $relation_id,
					'post_type'   => $post_type,
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		wptsall_log_info(
			'sites-config',
			'Relation config deleted',
			array(
				'relation_id' => $relation_id,
				'post_type'   => $post_type,
			)
		);

		return true;
	}

	/**
	 * Bulk save configs
	 *
	 * @param int   $relation_id site relation ID.
	 * @param array $configs     Config array keyed by post_type.
	 * @return bool Returns true on success.
	 */
	public static function save_batch( $relation_id, $configs ) {
		global $wpdb;

		// Start transaction.
		$wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( $configs as $post_type => $config ) {
				$result = self::save( $relation_id, $post_type, $config );
				if ( false === $result ) {
					throw new \Exception( "Failed to save config for post_type: {$post_type}" );
				}
			}

			$wpdb->query( 'COMMIT' );

			wptsall_log_info(
				'sites-config',
				'Batch relation configs saved',
				array(
					'relation_id' => $relation_id,
					'count'       => count( $configs ),
				)
			);

			/**
			 * Fires when a relation config is updated.
			 *
			 * @since 1.0.0
			 * @param int $relation_id The relation ID.
			 */
			do_action( 'wptsall_relation_updated', $relation_id );

			return true;
		} catch ( \Exception $e ) {
			$wpdb->query( 'ROLLBACK' );

			wptsall_log_error(
				'sites-config',
				'Failed to save batch relation configs',
				array(
					'relation_id' => $relation_id,
					'error'       => $e->getMessage(),
				)
			);

			return false;
		}
	}

	/**
	 * Check if config exists
	 *
	 * @param int    $relation_id site relation ID.
	 * @param string $post_type   Post Type name.
	 * @return bool Whether config exists.
	 */
	public static function exists( $relation_id, $post_type ) {
		global $wpdb;
		$table = wptsall_table( 'relation_post_type_configs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE relation_id = %d AND post_type = %s',
				$table,
				$relation_id,
				$post_type
			)
		);

		return (bool) $exists;
	}

	/**
	 * Get config statistics
	 *
	 * @param int $relation_id site relation ID.
	 * @return array Statistics.
	 */
	public static function get_stats( $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'relation_post_type_configs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE relation_id = %d',
				$table,
				$relation_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$enabled = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE relation_id = %d AND enabled = 1',
				$table,
				$relation_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$disabled = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE relation_id = %d AND enabled = 0',
				$table,
				$relation_id
			)
		);

		return array(
			'total'    => $total,
			'enabled'  => $enabled,
			'disabled' => $disabled,
		);
	}

	/**
	 * Copy config to another relation
	 *
	 * @param int $source_relation_id Source site relation ID.
	 * @param int $target_relation_id Target site relation ID.
	 * @return bool Returns true on success.
	 */
	public static function copy_to_relation( $source_relation_id, $target_relation_id ) {
		$configs = self::get_all_by_relation( $source_relation_id );

		if ( empty( $configs ) ) {
			return true; // No configs to copy.
		}

		$configs_to_save = array();
		foreach ( $configs as $config ) {
			$configs_to_save[ $config['post_type'] ] = array(
				'enabled'         => $config['enabled'],
				'direction'       => $config['direction'],
				'sync_mode'       => $config['sync_mode'],
				'field_overrides' => $config['field_overrides'],
				'rule_overrides'  => $config['rule_overrides'] ?? array(),
			);
		}

		return self::save_batch( $target_relation_id, $configs_to_save );
	}

	// ========================================
	// Template (i18n) translation config
	// ========================================

	/**
	 * Special post_type key for template translation config.
	 *
	 * @var string
	 */
	const TEMPLATE_CONFIG_KEY = '__templates__';

	/**
	 * Get template translation config for a relation.
	 *
	 * Returns whether plugin_i18n/theme_i18n/config_i18n translation is enabled
	 * for this relation.
	 *
	 * @since 1.1.0
	 * @param int $relation_id Site relation ID.
	 * @return array {
	 *     @type bool $translate_plugin_i18n Whether plugin i18n translation is enabled.
	 *     @type bool $translate_theme_i18n  Whether theme i18n translation is enabled.
	 *     @type bool $translate_config_i18n Whether config i18n translation is enabled.
	 * }
	 */
	public static function get_template_config( $relation_id ) {
		$defaults = array(
			'translate_plugin_i18n' => false,
			'translate_theme_i18n'  => false,
			'translate_config_i18n' => false,
			'translate_site_strings' => false,
			'translate_menu_strings' => false,
			'translate_widget_strings' => false,
			'gettext_domain_whitelist' => array(),
			'plugin_slugs'          => array(),
			'preflight_policy'      => 'warn',
			'missing_component_behavior' => 'confirm_continue',
		);

		// Non-virtual site targets do not support i18n config.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( $relation && 'virtual' !== ( $relation['target_site_type'] ?? '' ) ) {
			return $defaults;
		}

		$row = self::get( $relation_id, self::TEMPLATE_CONFIG_KEY );
		if ( ! $row ) {
			return $defaults;
		}

		$overrides = $row['field_overrides'];
		if ( ! is_array( $overrides ) ) {
			return $defaults;
		}

		return array(
			'translate_plugin_i18n' => ! empty( $overrides['translate_plugin_i18n'] ),
			'translate_theme_i18n'  => ! empty( $overrides['translate_theme_i18n'] ),
			'translate_config_i18n' => ! empty( $overrides['translate_config_i18n'] ),
			'translate_site_strings' => ! empty( $overrides['translate_site_strings'] ),
			'translate_menu_strings' => ! empty( $overrides['translate_menu_strings'] ),
			'translate_widget_strings' => ! empty( $overrides['translate_widget_strings'] ),
			'gettext_domain_whitelist' => ! empty( $overrides['gettext_domain_whitelist'] ) ? array_values( (array) $overrides['gettext_domain_whitelist'] ) : array(),
			'plugin_slugs'          => ! empty( $overrides['plugin_slugs'] ) ? (array) $overrides['plugin_slugs'] : array(),
			'preflight_policy'      => self::normalize_template_preflight_policy( $overrides['preflight_policy'] ?? '' ),
			'missing_component_behavior' => self::normalize_template_missing_component_behavior( $overrides['missing_component_behavior'] ?? '' ),
		);
	}

	/**
	 * Save template translation config for a relation.
	 *
	 * @since 1.1.0
	 * @param int   $relation_id Site relation ID.
	 * @param array $config {
	 *     @type bool $translate_plugin_i18n Whether plugin i18n translation is enabled.
	 *     @type bool $translate_theme_i18n  Whether theme i18n translation is enabled.
	 *     @type bool $translate_config_i18n Whether config i18n translation is enabled.
	 * }
	 * @return int|false Config row ID or false on failure.
	 */
	public static function save_template_config( $relation_id, $config ) {
		// Only virtual site targets support i18n config.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation || 'virtual' !== ( $relation['target_site_type'] ?? '' ) ) {
			return new \WP_Error(
				'invalid_target_type',
				__( 'Only virtual site type targets support theme/plugin translation configuration', 'wpmmcc-ats' )
			);
		}

		$overrides = array(
			'translate_plugin_i18n' => ! empty( $config['translate_plugin_i18n'] ),
			'translate_theme_i18n'  => ! empty( $config['translate_theme_i18n'] ),
			'translate_config_i18n' => ! empty( $config['translate_config_i18n'] ),
			'translate_site_strings' => ! empty( $config['translate_site_strings'] ),
			'translate_menu_strings' => ! empty( $config['translate_menu_strings'] ),
			'translate_widget_strings' => ! empty( $config['translate_widget_strings'] ),
			'gettext_domain_whitelist' => ! empty( $config['gettext_domain_whitelist'] ) ? array_values( array_filter( (array) $config['gettext_domain_whitelist'] ) ) : array(),
			'plugin_slugs'          => ! empty( $config['plugin_slugs'] ) ? array_values( array_filter( (array) $config['plugin_slugs'] ) ) : array(),
			'preflight_policy'      => self::normalize_template_preflight_policy( $config['preflight_policy'] ?? '' ),
			'missing_component_behavior' => self::normalize_template_missing_component_behavior( $config['missing_component_behavior'] ?? '' ),
		);

		return self::save( $relation_id, self::TEMPLATE_CONFIG_KEY, array(
			'enabled'         => 1,
			'field_overrides' => $overrides,
		) );
	}

	/**
	 * Normalize template-config preflight policy.
	 *
	 * @param mixed $raw Raw policy.
	 * @return string
	 */
	private static function normalize_template_preflight_policy( $raw ) {
		$policy = sanitize_key( (string) $raw );
		if ( ! in_array( $policy, self::TEMPLATE_PREFLIGHT_POLICIES, true ) ) {
			return 'warn';
		}
		return $policy;
	}

	/**
	 * Normalize template-config missing-component behavior.
	 *
	 * @param mixed $raw Raw behavior.
	 * @return string
	 */
	private static function normalize_template_missing_component_behavior( $raw ) {
		$behavior = sanitize_key( (string) $raw );
		if ( ! in_array( $behavior, self::TEMPLATE_MISSING_COMPONENT_BEHAVIORS, true ) ) {
			return 'confirm_continue';
		}
		return $behavior;
	}
}
