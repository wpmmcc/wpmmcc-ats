<?php
/**
 * Data Classifier
 *
 * Classifies data objects based on rules
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Data Classifier Class
 */
class Data_Classifier {

	/**
	 * Data classification rules
	 *
	 * @var array
	 */
	private $data_rules;

	/**
	 * Metadata classification rules
	 *
	 * @var array
	 */
	private $meta_rules;

	/**
	 * Sync configuration
	 *
	 * @var array
	 */
	private $sync_config;

	/**
	 * Constructor
	 *
	 * @param array $sync_config Optional sync configuration
	 */
	public function __construct( $sync_config = array() ) {
		$this->data_rules  = $this->load_data_rules();
		$this->meta_rules  = $this->load_meta_rules();
		$this->sync_config = $this->load_sync_config( $sync_config );
	}

	/**
	 * Load data classification rules
	 *
	 * @return array
	 */
	private function load_data_rules() {
		$rules = Classification_Rules::get_data_rules();
		return Classification_Rules::apply_filters( 'data', $rules );
	}

	/**
	 * Load metadata classification rules
	 *
	 * @return array
	 */
	private function load_meta_rules() {
		$rules = Classification_Rules::get_meta_rules();
		return Classification_Rules::apply_filters( 'meta', $rules );
	}

	/**
	 * Load sync configuration
	 *
	 * @param array $custom_config Custom configuration
	 * @return array
	 */
	private function load_sync_config( $custom_config = array() ) {
		$default_config = Classification_Rules::get_default_sync_config();
		return wp_parse_args( $custom_config, $default_config );
	}

	/**
	 * Classify a data object
	 *
	 * @param mixed  $object      Data object (WP_Post, WP_Comment, WP_User, etc.)
	 * @param string $object_type Object type
	 * @return Data_Classification
	 */
	public function classify( $object, $object_type = null ) {
		// Auto-detect object type
		if ( null === $object_type ) {
			$object_type = $this->detect_object_type( $object );
		}

		// Get classification rule
		$rule = $this->get_rule_for_type( $object_type );

		if ( ! $rule ) {
			// Unknown type, defaults to not syncable
			wptsall_log_debug( 'core-data', 'Unknown object type classified', array(
				'object_type' => $object_type,
				'syncable'    => false,
				'reason'      => 'unknown_type',
			) );

			return new Data_Classification(
				$object,
				$object_type,
				array(
					'data_type'   => Classification_Constants::DATA_TYPE_CONTENT,
					'privacy'     => Classification_Constants::DATA_PRIVACY_INTERNAL,
					'sync_policy' => Classification_Constants::SYNC_NEVER,
					'syncable'    => false,
					'reason'      => 'Unknown data type',
				)
			);
		}

		// Check conditions
		$conditions_met = $this->check_conditions( $object, $rule );

		// Determine if syncable
		$syncable = $this->determine_syncable( $rule, $conditions_met );

		wptsall_log_debug( 'core-data', 'Data object classified', array(
			'object_type'  => $object_type,
			'data_type'    => $rule['data_type'],
			'sync_policy'  => $rule['sync_policy'],
			'syncable'     => $syncable,
		) );

		return new Data_Classification(
			$object,
			$object_type,
			array(
				'data_type'      => $rule['data_type'],
				'privacy'        => $rule['privacy'],
				'sync_policy'    => $rule['sync_policy'],
				'syncable'       => $syncable,
				'reason'         => $syncable ? null : ( $rule['reason'] ?? 'Not syncable per rules' ),
				'conditions_met' => $conditions_met,
			)
		);
	}

	/**
	 * Classify a metadata field
	 *
	 * @param string $meta_key   Meta key name
	 * @param mixed  $meta_value Meta value (optional)
	 * @param string $object_type Object type (optional)
	 * @return array Classification result
	 */
	public function classify_meta_field( $meta_key, $meta_value = null, $object_type = 'post' ) {
		// Check if PII field
		if ( $this->is_pii_field( $meta_key ) ) {
			wptsall_log_debug( 'core-data', 'Meta field classified as PII', array(
				'meta_key'    => $meta_key,
				'object_type' => $object_type,
				'category'    => 'pii',
				'syncable'    => false,
			) );

			return array(
				'meta_key'    => $meta_key,
				'syncable'    => false,
				'category'    => 'pii',
				'privacy'     => Classification_Constants::DATA_PRIVACY_PII,
				'reason'      => 'Contains personally identifiable information (PII)',
			);
		}

		// Check if never-sync field
		if ( $this->is_never_sync_field( $meta_key ) ) {
			wptsall_log_debug( 'core-data', 'Meta field classified as never_sync', array(
				'meta_key'    => $meta_key,
				'object_type' => $object_type,
				'category'    => 'never_sync',
				'syncable'    => false,
			) );

			return array(
				'meta_key'    => $meta_key,
				'syncable'    => false,
				'category'    => 'never_sync',
				'privacy'     => Classification_Constants::DATA_PRIVACY_INTERNAL,
				'reason'      => 'System reserved field, not syncable',
			);
		}

		// Check if configurable field
		if ( $this->is_configurable_field( $meta_key ) ) {
			$mode     = $this->sync_config['meta_sync_mode'] ?? 'whitelist';
			$syncable = ( 'whitelist' === $mode );

			wptsall_log_debug( 'core-data', 'Meta field classified as configurable', array(
				'meta_key'    => $meta_key,
				'object_type' => $object_type,
				'category'    => 'configurable',
				'mode'        => $mode,
				'syncable'    => $syncable,
			) );

			return array(
				'meta_key'    => $meta_key,
				'syncable'    => $syncable,
				'category'    => 'configurable',
				'privacy'     => Classification_Constants::DATA_PRIVACY_PUBLIC,
				'reason'      => null,
			);
		}

		// Default handling: based on metadata sync mode
		$mode = $this->sync_config['meta_sync_mode'] ?? 'whitelist';

		if ( 'whitelist' === $mode ) {
			// Whitelist mode: not synced by default
			wptsall_log_debug( 'core-data', 'Meta field classified as unknown (whitelist mode)', array(
				'meta_key'    => $meta_key,
				'object_type' => $object_type,
				'category'    => 'unknown',
				'mode'        => 'whitelist',
				'syncable'    => false,
			) );

			return array(
				'meta_key'    => $meta_key,
				'syncable'    => false,
				'category'    => 'unknown',
				'privacy'     => Classification_Constants::DATA_PRIVACY_INTERNAL,
				'reason'      => 'Whitelist mode: not in whitelist',
			);
		} else {
			// Blacklist mode: synced by default
			wptsall_log_debug( 'core-data', 'Meta field classified as default (blacklist mode)', array(
				'meta_key'    => $meta_key,
				'object_type' => $object_type,
				'category'    => 'default',
				'mode'        => 'blacklist',
				'syncable'    => true,
			) );

			return array(
				'meta_key'    => $meta_key,
				'syncable'    => true,
				'category'    => 'default',
				'privacy'     => Classification_Constants::DATA_PRIVACY_PUBLIC,
				'reason'      => null,
			);
		}
	}

	/**
	 * Filter metadata array
	 *
	 * @param array  $meta_data   Metadata array
	 * @param string $object_type Object type
	 * @return array Filtered metadata
	 */
	public function filter_meta_fields( $meta_data, $object_type = 'post' ) {
		$filtered = array();

		foreach ( $meta_data as $meta_key => $meta_value ) {
			$classification = $this->classify_meta_field( $meta_key, $meta_value, $object_type );

			if ( $classification['syncable'] ) {
				$filtered[ $meta_key ] = $meta_value;
			}
		}

		return $filtered;
	}

	/**
	 * Auto-detect object type
	 *
	 * @param mixed $object Data object
	 * @return string
	 */
	private function detect_object_type( $object ) {
		if ( $object instanceof \WP_Post ) {
			return $object->post_type;
		} elseif ( $object instanceof \WP_Comment ) {
			return 'comment';
		} elseif ( $object instanceof \WP_User ) {
			return 'user';
		} elseif ( $object instanceof \WP_Term ) {
			return 'taxonomy';
		}

		return 'unknown';
	}

	/**
	 * Get rule for a given type
	 *
	 * @param string $object_type Object type
	 * @return array|null
	 */
	private function get_rule_for_type( $object_type ) {
		return $this->data_rules[ $object_type ] ?? null;
	}

	/**
	 * Check if conditions are met
	 *
	 * @param mixed $object Data object
	 * @param array $rule   Rule
	 * @return array Condition check results
	 */
	private function check_conditions( $object, $rule ) {
		$conditions     = $rule['conditions'] ?? array();
		$conditions_met = array();

		// Check post_status condition
		if ( isset( $conditions['post_status'] ) && $object instanceof \WP_Post ) {
			$allowed_statuses          = $conditions['post_status'];
			$conditions_met['status']  = in_array( $object->post_status, $allowed_statuses, true );
		}

		return $conditions_met;
	}

	/**
	 * Determine if syncable
	 *
	 * @param array $rule           Rule
	 * @param array $conditions_met Condition check results
	 * @return bool
	 */
	private function determine_syncable( $rule, $conditions_met ) {
		$sync_policy = $rule['sync_policy'];

		// Never sync
		if ( Classification_Constants::SYNC_NEVER === $sync_policy ) {
			return false;
		}

		// Always sync: but conditions must be checked
		if ( Classification_Constants::SYNC_ALWAYS === $sync_policy ) {
			// If there are conditions, all must be met
			if ( ! empty( $conditions_met ) ) {
				foreach ( $conditions_met as $result ) {
					if ( ! $result ) {
						return false;
					}
				}
			}
			return true;
		}

		// Configurable sync: determined by configuration
		if ( Classification_Constants::SYNC_CONFIGURABLE === $sync_policy ) {
			$data_type = $rule['data_type'];
			$enabled   = $this->sync_config['data_types'][ $data_type ] ?? ( $rule['default'] ?? false );

			// If enabled, conditions still need to be checked
			if ( $enabled && ! empty( $conditions_met ) ) {
				foreach ( $conditions_met as $result ) {
					if ( ! $result ) {
						return false;
					}
				}
			}

			return $enabled;
		}

		// Conditional sync
		if ( Classification_Constants::SYNC_CONDITIONAL === $sync_policy ) {
			// All conditions must be met
			foreach ( $conditions_met as $result ) {
				if ( ! $result ) {
					return false;
				}
			}
			return ! empty( $conditions_met );
		}

		return false;
	}

	/**
	 * Check if field is PII
	 *
	 * @param string $meta_key Meta key name
	 * @return bool
	 */
	private function is_pii_field( $meta_key ) {
		$pii_patterns = $this->meta_rules['pii_fields'] ?? array();
		return $this->match_patterns( $meta_key, $pii_patterns );
	}

	/**
	 * Check if field is never-sync
	 *
	 * @param string $meta_key Meta key name
	 * @return bool
	 */
	private function is_never_sync_field( $meta_key ) {
		$never_sync_patterns = $this->meta_rules['never_sync'] ?? array();
		return $this->match_patterns( $meta_key, $never_sync_patterns );
	}

	/**
	 * Check if field is configurable
	 *
	 * @param string $meta_key Meta key name
	 * @return bool
	 */
	private function is_configurable_field( $meta_key ) {
		$configurable_patterns = $this->meta_rules['configurable_sync'] ?? array();
		return $this->match_patterns( $meta_key, $configurable_patterns );
	}

	/**
	 * Match against pattern list
	 *
	 * @param string $meta_key Meta key name
	 * @param array  $patterns Pattern list
	 * @return bool
	 */
	private function match_patterns( $meta_key, $patterns ) {
		foreach ( $patterns as $pattern ) {
			// Convert wildcard * to regex pattern
			$regex_pattern = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';

			if ( preg_match( $regex_pattern, $meta_key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get data statistics
	 *
	 * @param array $objects Array of objects
	 * @return array Statistics result
	 */
	public function get_statistics( $objects ) {
		$stats = array(
			'total'       => count( $objects ),
			'syncable'    => 0,
			'blocked'     => 0,
			'by_type'     => array(),
			'by_privacy'  => array(),
			'by_policy'   => array(),
		);

		foreach ( $objects as $object ) {
			$classification = $this->classify( $object );

			if ( $classification->syncable ) {
				++$stats['syncable'];
			} else {
				++$stats['blocked'];
			}

			// Count by data type
			if ( ! isset( $stats['by_type'][ $classification->data_type ] ) ) {
				$stats['by_type'][ $classification->data_type ] = 0;
			}
			++$stats['by_type'][ $classification->data_type ];

			// Count by privacy level
			if ( ! isset( $stats['by_privacy'][ $classification->privacy ] ) ) {
				$stats['by_privacy'][ $classification->privacy ] = 0;
			}
			++$stats['by_privacy'][ $classification->privacy ];

			// Count by sync policy
			if ( ! isset( $stats['by_policy'][ $classification->sync_policy ] ) ) {
				$stats['by_policy'][ $classification->sync_policy ] = 0;
			}
			++$stats['by_policy'][ $classification->sync_policy ];
		}

		return $stats;
	}
}
