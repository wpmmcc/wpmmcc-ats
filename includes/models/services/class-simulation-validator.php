<?php
/**
 * Simulation Validator
 *
 * Core validation entry point for model templates and translation rules.
 * Validates logical consistency using simulated data, and optionally probes
 * real published content to assess template/rule field coverage.
 *
 * As of 1.4.0, template and rule validation logic is delegated to
 * Template_Validation_Service and Rule_Validation_Service respectively.
 * As of 1.5.0, probe_real_data() adds informational real-data coverage analysis.
 * This class retains its public API for backward compatibility.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Simulation_Validator {

	/** Valid data_type enum values (from CONTRACTS.md) */
	const VALID_DATA_TYPES = array(
		'text', 'html', 'numeric', 'id_ref', 'id_list', 'url',
		'datetime', 'boolean', 'enum', 'slug', 'serialized', 'json',
	);

	/** Valid field capability types */
	const VALID_CAP_TYPES = array( 'translate', 'sync', 'id_mapping', 'compute', 'skip' );

	/**
	 * Validate template layer logical consistency.
	 *
	 * Checks:
	 * 1. Field existence — field_key format valid, data_type in enum
	 * 2. data_type reasonability — simulated value type matches declared data_type
	 * 3. Cross-object reference closure — id_ref fields' reference_target has corresponding model_object
	 * 4. Relationship completeness — each model_object has fields
	 *
	 * @since 1.2.0
	 * @since 1.4.0 Delegates to Template_Validation_Service.
	 * @param int $model_id Model ID.
	 * @return array { valid: bool, errors: [], warnings: [], simulated_data: {} }
	 */
	public function validate_template( int $model_id ): array {
		if ( ! class_exists( Template_Validation_Service::class ) ) {
			return array(
				'valid'    => false,
				'errors'   => array( 'Template_Validation_Service not available' ),
				'warnings' => array(),
			);
		}

		$service                  = new Template_Validation_Service();
		$result                   = $service->validate_all( $model_id );
		$result['simulated_data'] = $this->build_mock_data( $model_id );
		return $result;
	}

	/**
	 * Validate rule layer logical consistency.
	 *
	 * Checks:
	 * 1. Field coverage gap — every active template field has a field_capabilities entry
	 * 2. Capability type validity — each field_capabilities value is in VALID_CAP_TYPES
	 * 3. id_mapping fields have reference_type
	 * 4. Related records completeness — related_records reference existing rules
	 *
	 * @since 1.2.0
	 * @since 1.4.0 Delegates to Rule_Validation_Service.
	 * @param int $rule_id Translation rule ID.
	 * @return array { valid: bool, errors: [], warnings: [] }
	 */
	public function validate_rule( int $rule_id ): array {
		$service = new Rule_Validation_Service();
		return $service->validate_all( $rule_id );
	}

	/**
	 * Full two-layer validation (template + all rules).
	 *
	 * @since 1.2.0
	 * @param int $model_id Model ID.
	 * @return array { template: {...}, rules: [{...},...], summary: { total_errors, total_warnings, valid } }
	 */
	public function validate_full( int $model_id ): array {
		$template_result = $this->validate_template( $model_id );

		// Get all rules for this model.
		$rules = self::get_rules_for_model_safe( $model_id );

		$rule_results = array();
		foreach ( $rules as $rule ) {
			$rule_id = (int) ( is_object( $rule ) ? $rule->id : ( $rule['id'] ?? 0 ) );
			if ( $rule_id > 0 ) {
				$rule_results[] = $this->validate_rule( $rule_id );
			}
		}

		$result = array(
			'template' => $template_result,
			'rules'    => $rule_results,
			'summary'  => $this->summarize( $template_result, $rule_results ),
		);

		// Real data probe: auto-detect for each post_type object.
		$objects          = Model_Object_Service::get_objects_for_model( $model_id );
		$real_data_probes = array();
		foreach ( $objects as $obj ) {
			if ( 'post_type' === ( $obj['object_type'] ?? '' ) ) {
				$probe = $this->probe_real_data( $model_id, $obj['object_name'] );
				if ( $probe['has_data'] ) {
					$real_data_probes[ $obj['object_name'] ] = $probe;
				}
			}
		}
		if ( ! empty( $real_data_probes ) ) {
			$result['real_data_probe'] = $real_data_probes;
		}

		return $result;
	}

	// =============================================
	// Real Data Probe (1.5.0)
	// =============================================

	/**
	 * WP post object properties that are NOT real DB columns.
	 *
	 * These are computed/virtual properties added by WP_Post and should not
	 * appear in field coverage analysis.
	 */
	const WP_POST_VIRTUAL_PROPS = array(
		'filter',
		'ancestors',
		'page_template',
		'post_category',
		'tags_input',
	);

	/**
	 * Regex pattern matching internal WP meta keys that should be excluded
	 * from real data probe analysis.
	 */
	const WP_INTERNAL_META_PATTERN = '/^_(edit_|wp_old_|wp_trash_|wp_attached_|encloseme|pingme)/';

	/**
	 * Probe real published data to assess template/rule field coverage.
	 *
	 * Finds one published post of the given post_type, collects all actual
	 * fields (post columns + meta keys), then compares against template fields
	 * and rule field_capabilities to calculate coverage rates and type warnings.
	 *
	 * This is purely informational — it never blocks any operation.
	 *
	 * @since 1.5.0
	 *
	 * @param int    $model_id  Model ID.
	 * @param string $post_type Post type slug.
	 * @return array {
	 *     @type bool  $has_data        Whether a published post was found.
	 *     @type int   $sample_post_id  ID of the sampled post (0 if none).
	 *     @type array $actual_fields   List of actual field keys found.
	 *     @type array $coverage        { template_matched, template_total, template_rate,
	 *                                    rule_matched, rule_total, rule_rate }
	 *     @type array $type_warnings   List of type reasonableness warning strings.
	 * }
	 */
	public function probe_real_data( int $model_id, string $post_type ): array {
		global $wpdb;

		$empty_result = array(
			'has_data'       => false,
			'sample_post_id' => 0,
			'actual_fields'  => array(),
			'coverage'       => array(
				'template_matched' => 0,
				'template_total'   => 0,
				'template_rate'    => 0.0,
				'rule_matched'     => 0,
				'rule_total'       => 0,
				'rule_rate'        => 0.0,
			),
			'type_warnings'  => array(),
		);

		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return $empty_result;
		}

		// Find one published post of this post_type.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = %s AND post_status = 'publish' ORDER BY ID DESC LIMIT 1",
				$wpdb->posts,
				$post_type
			)
		);

		if ( $post_id <= 0 ) {
			return $empty_result;
		}

		// Collect actual fields from the post.
		$actual_fields = $this->collect_actual_fields( $post_id );

		// Get template and rule fields for comparison.
		$template_fields = $this->get_template_fields_for_model( $model_id, $post_type );
		$rule_fields     = $this->get_rule_fields_for_model( $model_id, $post_type );

		// Calculate template coverage: how many actual fields are in the template.
		$template_field_keys = array_keys( $template_fields );
		$template_matched    = count( array_intersect( $actual_fields, $template_field_keys ) );
		$template_total      = count( $actual_fields );
		$template_rate       = $template_total > 0 ? round( $template_matched / $template_total, 4 ) : 0.0;

		// Calculate rule coverage: how many template fields have rule entries.
		$rule_field_keys = array_keys( $rule_fields );
		$rule_matched    = count( array_intersect( $template_field_keys, $rule_field_keys ) );
		$rule_total      = count( $template_field_keys );
		$rule_rate       = $rule_total > 0 ? round( $rule_matched / $rule_total, 4 ) : 0.0;

		// Check type reasonableness.
		$type_warnings = $this->check_type_reasonableness( $post_id, $rule_fields );

		return array(
			'has_data'       => true,
			'sample_post_id' => $post_id,
			'actual_fields'  => $actual_fields,
			'coverage'       => array(
				'template_matched' => $template_matched,
				'template_total'   => $template_total,
				'template_rate'    => $template_rate,
				'rule_matched'     => $rule_matched,
				'rule_total'       => $rule_total,
				'rule_rate'        => $rule_rate,
			),
			'type_warnings'  => $type_warnings,
		);
	}

	/**
	 * Collect all actual field keys from a post (columns + meta keys).
	 *
	 * Filters out WP internal meta keys and virtual post properties.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id Post ID.
	 * @return array Flat list of field key strings.
	 */
	private function collect_actual_fields( int $post_id ): array {
		$fields = array();

		// Get post object columns.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $fields;
		}

		$post_array = $post->to_array();
		foreach ( array_keys( $post_array ) as $key ) {
			// Skip virtual/computed WP_Post properties.
			if ( in_array( $key, self::WP_POST_VIRTUAL_PROPS, true ) ) {
				continue;
			}
			$fields[] = $key;
		}

		// Get all meta keys.
		$all_meta = get_post_meta( $post_id, '', true );
		if ( is_array( $all_meta ) ) {
			foreach ( array_keys( $all_meta ) as $meta_key ) {
				// Skip WP internal meta keys.
				if ( preg_match( self::WP_INTERNAL_META_PATTERN, $meta_key ) ) {
					continue;
				}
				$fields[] = $meta_key;
			}
		}

		return array_values( array_unique( $fields ) );
	}

	/**
	 * Get template field keys for a model + post_type.
	 *
	 * Reads from model_object_fields for the matching model_object.
	 *
	 * @since 1.5.0
	 *
	 * @param int    $model_id  Model ID.
	 * @param string $post_type Post type slug.
	 * @return array Associative array keyed by field_key, values are field row arrays.
	 */
	private function get_template_fields_for_model( int $model_id, string $post_type ): array {
		$object = Model_Object_Service::get_object_by_type( $model_id, 'post_type', $post_type );
		if ( ! $object ) {
			return array();
		}

		$object_id = (int) ( $object['id'] ?? 0 );
		if ( $object_id <= 0 ) {
			return array();
		}

		$fields = Model_Object_Service::get_fields_for_object( $object_id );
		$result = array();
		foreach ( $fields as $field ) {
			$key = $field['field_key'] ?? '';
			if ( '' !== $key ) {
				$result[ $key ] = $field;
			}
		}

		return $result;
	}

	/**
	 * Get rule field_capabilities for a model + post_type.
	 *
	 * Reads from the matching translation rule's field_capabilities column.
	 *
	 * @since 1.5.0
	 *
	 * @param int    $model_id  Model ID.
	 * @param string $post_type Post type slug.
	 * @return array Associative array keyed by field_name, values are config arrays.
	 */
	private function get_rule_fields_for_model( int $model_id, string $post_type ): array {
		$rule = Translation_Rule_Service::get_rule_by_post_type( $model_id, $post_type );
		if ( ! $rule ) {
			return array();
		}

		$capabilities = $rule['field_capabilities'] ?? array();
		if ( ! is_array( $capabilities ) ) {
			return array();
		}

		return $capabilities;
	}

	/**
	 * Check type reasonableness of rule field capabilities against actual data.
	 *
	 * Warns when:
	 * - A 'translate' field has a purely numeric actual value.
	 * - A 'sync' field has a long text value (>200 chars), suggesting it should be translated.
	 *
	 * @since 1.5.0
	 *
	 * @param int   $post_id     Post ID to check actual values.
	 * @param array $rule_fields Rule field_capabilities keyed by field_name.
	 * @return array List of warning strings.
	 */
	private function check_type_reasonableness( int $post_id, array $rule_fields ): array {
		$warnings = array();

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $warnings;
		}

		$post_array = $post->to_array();
		$all_meta   = get_post_meta( $post_id, '', true );
		$all_meta   = is_array( $all_meta ) ? $all_meta : array();

		foreach ( $rule_fields as $field_name => $config ) {
			$cap_type = $config['type'] ?? 'sync';

			// Get the actual value for this field.
			$actual_value = null;
			if ( isset( $post_array[ $field_name ] ) ) {
				$actual_value = (string) $post_array[ $field_name ];
			} elseif ( isset( $all_meta[ $field_name ] ) ) {
				// Meta values are stored as arrays; take the first.
				$meta_val     = $all_meta[ $field_name ];
				$actual_value = is_array( $meta_val ) ? (string) ( $meta_val[0] ?? '' ) : (string) $meta_val;
			}

			if ( null === $actual_value || '' === $actual_value ) {
				continue;
			}

			// translate field with purely numeric value.
			if ( 'translate' === $cap_type && is_numeric( $actual_value ) ) {
				$warnings[] = sprintf(
					'Field "%s" is set to translate but has a numeric value (%s) — consider using sync or id_mapping instead.',
					$field_name,
					$actual_value
				);
			}

			// sync field with long text.
			if ( 'sync' === $cap_type && mb_strlen( $actual_value ) > 200 ) {
				$warnings[] = sprintf(
					'Field "%s" is set to sync but has a long text value (%d chars) — consider using translate instead.',
					$field_name,
					mb_strlen( $actual_value )
				);
			}
		}

		return $warnings;
	}

	// =============================================
	// Private: Legacy validation methods (extracted to dedicated services in 1.4.0)
	// =============================================
	// Template validation logic → Template_Validation_Service
	// Rule validation logic → Rule_Validation_Service

	// =============================================
	// Private: Mock Data Builder
	// =============================================

	/**
	 * Build mock/simulated data for all objects in a model.
	 *
	 * @param int $model_id Model ID.
	 * @return array Keyed by object_name, each containing field_key => mock_value pairs.
	 */
	private function build_mock_data( int $model_id ): array {
		$mock    = array();
		$objects = Model_Object_Service::get_objects_for_model( $model_id );

		foreach ( $objects as $object ) {
			$object_id   = (int) ( $object['id'] ?? 0 );
			$object_name = $object['object_name'] ?? '';
			$fields      = Model_Object_Service::get_fields_for_object( $object_id );

			$mock_fields = array();
			foreach ( $fields as $field ) {
				$field_key               = $field['field_key'] ?? '';
				$data_type               = $field['data_type'] ?? 'text';
				$mock_fields[ $field_key ] = self::mock_value_for_type( $data_type );
			}

			$mock[ $object_name ] = $mock_fields;
		}

		return $mock;
	}

	/**
	 * Generate a mock value for a given data_type.
	 *
	 * @param string $data_type The data type to generate a mock value for.
	 * @return string The mock value.
	 */
	private static function mock_value_for_type( string $data_type ): string {
		$map = array(
			'text'       => 'Mock text value',
			'html'       => '<p>Mock rich text</p>',
			'numeric'    => '99',
			'id_ref'     => '42',
			'id_list'    => '42,43,44',
			'url'        => 'https://example.com/mock',
			'datetime'   => '2026-01-01 00:00:00',
			'boolean'    => '1',
			'enum'       => 'publish',
			'slug'       => 'mock-slug',
			'serialized' => serialize( array( 'key' => 'mock value' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			'json'       => wp_json_encode( array( 'key' => 'mock value' ) ),
		);

		return $map[ $data_type ] ?? 'mock-unknown';
	}

	// =============================================
	// Private: Summarize
	// =============================================

	/**
	 * Summarize validation results across template and rule layers.
	 *
	 * @param array $template_result Template validation result.
	 * @param array $rule_results    Array of rule validation results.
	 * @return array Summary with total_errors, total_warnings, valid, rules_checked.
	 */
	private function summarize( array $template_result, array $rule_results ): array {
		$total_errors   = count( $template_result['errors'] ?? array() );
		$total_warnings = count( $template_result['warnings'] ?? array() );

		foreach ( $rule_results as $rr ) {
			$total_errors   += count( $rr['errors'] ?? array() );
			$total_warnings += count( $rr['warnings'] ?? array() );
		}

		return array(
			'valid'          => ( 0 === $total_errors ),
			'total_errors'   => $total_errors,
			'total_warnings' => $total_warnings,
			'rules_checked'  => count( $rule_results ),
		);
	}

	// =============================================
	// Private: Safe helper
	// =============================================

	/**
	 * Get all translation rule IDs for a model (safe, returns empty array on failure).
	 *
	 * @param int $model_id Model ID.
	 * @return array Array of rule row objects with at least an `id` property.
	 */
	private static function get_rules_for_model_safe( int $model_id ): array {
		global $wpdb;
		$table = wptsall_table( 'translation_rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id FROM %i WHERE model_id = %d', $table, $model_id )
		);
		return is_array( $rows ) ? $rows : array();
	}
}
