<?php
/**
 * WPTSALL Field Sync Service
 *
 * Synchronizes discovered field capabilities to translation rules.
 * Used by the REST API and E2E seeding to ensure fields are properly
 * registered in translation rules.
 *
 * @package WPTSALL
 * @since   1.1.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field Sync Service Class
 *
 * Handles syncing field capabilities from discovery / manual input
 * into translation_rules.field_capabilities JSON column.
 *
 * @since 1.1.0
 */
class Field_Sync_Service {

	/**
	 * Sync a list of fields to a specific translation rule.
	 *
	 * Each entry: [ 'field_key' => string, 'capability' => array ]
	 *
	 * @param int   $rule_id Rule ID.
	 * @param array $fields  Array of field entries.
	 * @return array{synced:int, skipped:int, errors:string[], relationship_warnings:string[]}
	 */
	public static function sync_fields_to_rule( int $rule_id, array $fields ): array {
		global $wpdb;

		$result = array(
			'synced'                => 0,
			'skipped'              => 0,
			'errors'               => array(),
			'relationship_warnings' => array(),
		);

		$rules_table = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, field_capabilities, model_id FROM %i WHERE id = %d', $rules_table, $rule_id ),
			ARRAY_A
		);

		if ( ! $rule ) {
			$result['errors'][] = sprintf( 'Rule %d not found.', $rule_id );
			return $result;
		}

		$caps = json_decode( $rule['field_capabilities'] ?? '{}', true );
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}

		$changed = false;
		foreach ( $fields as $entry ) {
			$field_key  = sanitize_key( $entry['field_key'] ?? '' );
			$capability = $entry['capability'] ?? array();

			if ( '' === $field_key ) {
				$result['errors'][] = 'Empty field_key, skipping.';
				++$result['skipped'];
				continue;
			}

			if ( ! is_array( $capability ) ) {
				$capability = array();
			}

			if ( isset( $caps[ $field_key ] ) ) {
				++$result['skipped'];
				continue;
			}

			$caps[ $field_key ] = array_merge(
				$capability,
				array( 'source' => $capability['source'] ?? 'manual' )
			);
			++$result['synced'];
			$changed = true;
		}

		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$rules_table,
				array( 'field_capabilities' => wp_json_encode( $caps ) ),
				array( 'id' => $rule_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $result;
	}

	/**
	 * Sync all unsynced fields for a given model.
	 *
	 * Discovers fields from Field_Discovery_Service, then syncs each
	 * to the appropriate rule based on data_type + object_name matching.
	 *
	 * @param int $model_id Model ID.
	 * @return array{total_synced:int, total_skipped:int, errors:string[], per_rule:array}
	 */
	public static function sync_all_unsynced( int $model_id ): array {
		global $wpdb;

		$result = array(
			'total_synced'  => 0,
			'total_skipped' => 0,
			'errors'        => array(),
			'per_rule'      => array(),
		);

		$rules_table = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, field_capabilities FROM %i WHERE model_id = %d', $rules_table, $model_id ),
			ARRAY_A
		);

		if ( empty( $rules ) ) {
			$result['errors'][] = sprintf( 'No rules found for model %d.', $model_id );
			return $result;
		}

		foreach ( $rules as $rule ) {
			$rule_id = (int) $rule['id'];
			$caps    = json_decode( $rule['field_capabilities'] ?? '{}', true );

			if ( ! is_array( $caps ) || empty( $caps ) ) {
				continue;
			}

			$unsynced = array();
			foreach ( $caps as $field_key => $capability ) {
				if ( ! is_array( $capability ) ) {
					continue;
				}
				if ( empty( $capability['source'] ) ) {
					$unsynced[] = array(
						'field_key'  => $field_key,
						'capability' => $capability,
					);
				}
			}

			if ( ! empty( $unsynced ) ) {
				$sync_res = self::sync_fields_to_rule( $rule_id, $unsynced );
				$result['total_synced']  += $sync_res['synced'];
				$result['total_skipped'] += $sync_res['skipped'];
				$result['errors']         = array_merge( $result['errors'], $sync_res['errors'] );
				$result['per_rule'][ $rule_id ] = $sync_res;
			}
		}

		return $result;
	}

	/**
	 * Get sync status: which fields are in the rule vs discovered but not yet synced.
	 *
	 * @param int $model_id Model ID.
	 * @return array Array of field status entries.
	 */
	public static function get_sync_status( int $model_id ): array {
		global $wpdb;

		$status      = array();
		$rules_table = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, data_type, object_name, field_capabilities FROM %i WHERE model_id = %d', $rules_table, $model_id ),
			ARRAY_A
		);

		foreach ( $rules as $rule ) {
			$rule_id = (int) $rule['id'];
			$caps    = json_decode( $rule['field_capabilities'] ?? '{}', true );
			if ( ! is_array( $caps ) ) {
				$caps = array();
			}

			foreach ( $caps as $field_key => $capability ) {
				$status[] = array(
					'rule_id'   => $rule_id,
					'field_key' => $field_key,
					'in_rule'   => true,
					'type'      => $capability['type'] ?? 'unknown',
					'source'    => $capability['source'] ?? 'auto',
				);
			}
		}

		return $status;
	}

	/**
	 * Check the impact of deleting a field from a rule.
	 *
	 * @param int    $rule_id   Rule ID.
	 * @param string $field_key Field key to check.
	 * @return array Impact analysis.
	 */
	public static function check_field_deletion_impact( int $rule_id, string $field_key ): array {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule = $wpdb->get_row(
			$wpdb->prepare( 'SELECT field_capabilities FROM %i WHERE id = %d', $rules_table, $rule_id ),
			ARRAY_A
		);

		if ( ! $rule ) {
			return array(
				'success' => false,
				'error'   => 'Rule not found.',
			);
		}

		$caps = json_decode( $rule['field_capabilities'] ?? '{}', true );
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}

		$exists     = isset( $caps[ $field_key ] );
		$capability = $exists ? $caps[ $field_key ] : null;

		$results_table = wptsall_table( 'translation_results' );
		$affected      = 0;

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE rule_id = %d AND field_key = %s",
					$results_table,
					$rule_id,
					$field_key
				)
			);
		}

		return array(
			'success'          => true,
			'field_key'        => $field_key,
			'exists_in_rule'   => $exists,
			'capability'       => $capability,
			'affected_results' => $affected,
			'safe_to_delete'   => $affected === 0,
		);
	}
}
