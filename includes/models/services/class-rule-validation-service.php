<?php
/**
 * Rule Validation Service
 *
 * Validates translation rule layer: structure, field coverage, content format,
 * strategy soundness, and cross-rule chain consistency.
 * Extracted from Simulation_Validator::do_validate_rule() and Rule_Chain_Validator::validate_l3_chain(),
 * enhanced with content_format and strategy soundness checks.
 *
 * @package WPTSALL
 * @since   1.4.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule Validation Service Class
 *
 * Provides comprehensive rule-layer validation:
 * 1. Rule structure (field_capabilities JSON, url_pattern, data_type)
 * 2. Field coverage gap detection (template fields vs capabilities)
 * 3. content_format completeness and reasonableness
 * 4. Translation strategy soundness (inappropriate translate assignments)
 * 5. Cross-rule chain consistency (related_taxonomies have term rules, id_mapping targets)
 *
 * @since 1.4.0
 */
class Rule_Validation_Service {

	/** Valid capability types for field_capabilities entries. */
	const VALID_CAP_TYPES = array( 'translate', 'sync', 'id_mapping', 'compute', 'skip' );

	/** Valid data_type enum values. */
	const VALID_DATA_TYPES = array(
		'text', 'html', 'numeric', 'id_ref', 'id_list', 'url',
		'datetime', 'boolean', 'enum', 'slug', 'serialized', 'json',
	);

	/** Data types that should not normally be marked as 'translate'. */
	const NON_TRANSLATABLE_TYPES = array( 'numeric', 'id_ref', 'id_list', 'boolean', 'datetime', 'code' );

	/**
	 * Rule layer full validation.
	 *
	 * Runs all rule checks and returns a unified result.
	 *
	 * @since 1.4.0
	 *
	 * @param int $rule_id Translation rule ID.
	 * @return array { valid: bool, errors: array[], warnings: array[], rule_id: int }
	 */
	public function validate_all( int $rule_id ): array {
		$errors   = array();
		$warnings = array();

		// Load the rule first; if it does not exist, return early.
		$rule = $this->load_rule( $rule_id );
		if ( null === $rule ) {
			$errors[] = array(
				'code'    => 'rule_not_found',
				'message' => "Translation rule #{$rule_id} not found.",
			);
			return array(
				'valid'    => false,
				'errors'   => $errors,
				'warnings' => $warnings,
				'rule_id'  => $rule_id,
			);
		}

		$structure_errors = $this->validate_rule_structure( $rule_id, $rule );
		$errors           = array_merge( $errors, $structure_errors );

		$coverage_errors = $this->validate_field_coverage( $rule_id, $rule );
		$errors          = array_merge( $errors, $coverage_errors );

		// Pre-fetch template object/field data once for content_format and strategy_soundness.
		$model_id    = (int) ( $rule['model_id'] ?? 0 );
		$object_name = $rule['object_name'] ?? ( $rule['post_type'] ?? '' );

		$prefetched_field_data_types = null;
		if ( $model_id > 0 ) {
			$objects = Model_Object_Service::get_objects_for_model( $model_id );
			$prefetched_field_data_types = array();
			foreach ( $objects as $obj ) {
				if ( ( $obj['object_name'] ?? '' ) !== $object_name ) {
					continue;
				}
				$template_fields = Model_Object_Service::get_fields_for_object( (int) $obj['id'] );
				foreach ( $template_fields as $tf ) {
					$key = $tf['field_key'] ?? '';
					if ( '' !== $key ) {
						$prefetched_field_data_types[ $key ] = $tf['data_type'] ?? '';
					}
				}
				break;
			}
		}

		$format_warnings = $this->validate_content_format( $rule_id, $rule, $prefetched_field_data_types );
		$warnings        = array_merge( $warnings, $format_warnings );

		$strategy_warnings = $this->validate_strategy_soundness( $rule_id, $rule, $prefetched_field_data_types );
		$warnings          = array_merge( $warnings, $strategy_warnings );

		$chain_errors = $this->validate_chain_consistency( $rule_id, $rule );
		$errors       = array_merge( $errors, $chain_errors );

		// L2 data coverage (warnings only — missing coverage does not block).
		$data_coverage = $this->validate_data_coverage( $rule_id, $rule );
		if ( ! empty( $data_coverage['uncovered_fields'] ) ) {
			foreach ( $data_coverage['uncovered_fields'] as $uncovered_key ) {
				$warnings[] = array(
					'code'    => 'uncovered_field',
					'message' => "Meta key '{$uncovered_key}' found in sample data but not in field_capabilities of rule #{$rule_id}.",
					'context' => array( 'field_key' => $uncovered_key ),
				);
			}
		}
		if ( ! empty( $data_coverage['uncovered_taxonomies'] ) ) {
			foreach ( $data_coverage['uncovered_taxonomies'] as $uncovered_tax ) {
				$warnings[] = array(
					'code'    => 'uncovered_taxonomy',
					'message' => "Taxonomy '{$uncovered_tax}' is active on sample posts but not in related_taxonomies of rule #{$rule_id}.",
					'context' => array( 'taxonomy' => $uncovered_tax ),
				);
			}
		}

		return array(
			'valid'         => empty( $errors ),
			'errors'        => $errors,
			'warnings'      => $warnings,
			'rule_id'       => $rule_id,
			'data_coverage' => $data_coverage,
		);
	}

	/**
	 * Rule structure validation.
	 *
	 * Checks:
	 * - field_capabilities JSON is valid and non-empty
	 * - Each capability type is in VALID_CAP_TYPES
	 * - id_mapping fields have reference_type
	 * - url_pattern format (starts with / or ?)
	 * - data_type is a recognized value
	 *
	 * @since 1.4.0
	 *
	 * @param int        $rule_id Rule ID.
	 * @param array|null $rule    Pre-loaded rule row (optional; loaded if null).
	 * @return array Array of error entries.
	 */
	public function validate_rule_structure( int $rule_id, ?array $rule = null ): array {
		$errors = array();

		if ( null === $rule ) {
			$rule = $this->load_rule( $rule_id );
			if ( null === $rule ) {
				$errors[] = array(
					'code'    => 'rule_not_found',
					'message' => "Translation rule #{$rule_id} not found.",
				);
				return $errors;
			}
		}

		$object_name = $rule['object_name'] ?? ( $rule['post_type'] ?? '' );

		// Parse field_capabilities.
		$field_caps = $this->parse_field_capabilities( $rule );

		if ( empty( $field_caps ) ) {
			$errors[] = array(
				'code'    => 'empty_field_capabilities',
				'message' => "Rule #{$rule_id} for '{$object_name}' has empty field_capabilities.",
			);
		}

		// Check each capability entry.
		foreach ( $field_caps as $field_key => $cap_value ) {
			$cap_type = $this->extract_cap_type( $cap_value );

			// Capability type validity.
			if ( ! empty( $cap_type ) && ! in_array( $cap_type, self::VALID_CAP_TYPES, true ) ) {
				$errors[] = array(
					'code'    => 'unknown_capability_type',
					'message' => "Field '{$field_key}' has unknown capability type '{$cap_type}' in rule #{$rule_id}.",
				);
			}

			// id_mapping must have reference_type.
			if ( 'id_mapping' === $cap_type && is_array( $cap_value ) && empty( $cap_value['reference_type'] ) ) {
				$errors[] = array(
					'code'    => 'id_mapping_missing_reference',
					'message' => "Field '{$field_key}' is id_mapping but missing reference_type in rule #{$rule_id}.",
				);
			}
		}

		// url_pattern format check.
		$url_pattern = $rule['url_pattern'] ?? '';
		if ( ! empty( $url_pattern ) ) {
			if ( strpos( $url_pattern, '/' ) !== 0 && strpos( $url_pattern, '?' ) !== 0 ) {
				$errors[] = array(
					'code'    => 'invalid_url_pattern',
					'message' => "Rule #{$rule_id} url_pattern must start with / or ?, got: '{$url_pattern}'.",
				);
			}
		}

		// data_type check (rule-level data_type, not field data_type).
		$rule_data_type    = $rule['data_type'] ?? '';
		$valid_rule_dtypes = array( 'post', 'term', 'user', 'comment', 'option', 'custom_table' );
		if ( ! empty( $rule_data_type ) && ! in_array( $rule_data_type, $valid_rule_dtypes, true ) ) {
			$errors[] = array(
				'code'    => 'unknown_rule_data_type',
				'message' => "Rule #{$rule_id} has unknown data_type '{$rule_data_type}'.",
			);
		}

		return $errors;
	}

	/**
	 * Field coverage gap detection.
	 *
	 * Compares model_object_fields (active, non-orphan) against field_capabilities
	 * to find template fields that lack a rule entry.
	 *
	 * @since 1.4.0
	 *
	 * @param int        $rule_id Rule ID.
	 * @param array|null $rule    Pre-loaded rule row (optional).
	 * @return array Array of error entries (gaps are errors since they indicate incomplete rules).
	 */
	public function validate_field_coverage( int $rule_id, ?array $rule = null ): array {
		$errors = array();

		if ( null === $rule ) {
			$rule = $this->load_rule( $rule_id );
			if ( null === $rule ) {
				return $errors;
			}
		}

		$model_id    = (int) ( $rule['model_id'] ?? 0 );
		$object_name = $rule['object_name'] ?? ( $rule['post_type'] ?? '' );

		if ( $model_id <= 0 ) {
			return $errors;
		}

		$field_caps = $this->parse_field_capabilities( $rule );

		$objects = Model_Object_Service::get_objects_for_model( $model_id );
		foreach ( $objects as $obj ) {
			if ( ( $obj['object_name'] ?? '' ) !== $object_name ) {
				continue;
			}

			$template_fields = Model_Object_Service::get_fields_for_object( (int) $obj['id'] );
			$active_fields   = array_filter(
				$template_fields,
				function ( $f ) {
					return ( $f['status'] ?? 'active' ) !== 'orphan';
				}
			);

			foreach ( $active_fields as $tf ) {
				$tf_key = $tf['field_key'] ?? '';
				if ( '' !== $tf_key && ! isset( $field_caps[ $tf_key ] ) ) {
					$errors[] = array(
						'code'    => 'field_not_in_capabilities',
						'message' => "Template field '{$tf_key}' not found in rule #{$rule_id} field_capabilities.",
						'context' => array( 'object_name' => $object_name ),
					);
				}
			}
			break; // Only check the matching object.
		}

		return $errors;
	}

	/**
	 * Validate content_format completeness and reasonableness.
	 *
	 * Checks:
	 * - translate fields should have content_format set
	 * - content_format should match the field's data_type when template data is available
	 *   (e.g., 'html' data_type should use 'rich_html' content_format)
	 *
	 * @since 1.4.0
	 *
	 * @param int        $rule_id          Rule ID.
	 * @param array|null $rule             Pre-loaded rule row (optional).
	 * @param array|null $field_data_types Pre-fetched field_key => data_type map (optional, fetched if null).
	 * @return array Array of warning entries.
	 */
	public function validate_content_format( int $rule_id, ?array $rule = null, ?array $field_data_types = null ): array {
		$warnings = array();

		if ( null === $rule ) {
			$rule = $this->load_rule( $rule_id );
			if ( null === $rule ) {
				return $warnings;
			}
		}

		$model_id    = (int) ( $rule['model_id'] ?? 0 );
		$object_name = $rule['object_name'] ?? ( $rule['post_type'] ?? '' );
		$field_caps  = $this->parse_field_capabilities( $rule );

		// Build a data_type lookup from model_object_fields if not pre-fetched.
		if ( null === $field_data_types ) {
			$field_data_types = array();
			if ( $model_id > 0 ) {
				$objects = Model_Object_Service::get_objects_for_model( $model_id );
				foreach ( $objects as $obj ) {
					if ( ( $obj['object_name'] ?? '' ) !== $object_name ) {
						continue;
					}
					$template_fields = Model_Object_Service::get_fields_for_object( (int) $obj['id'] );
					foreach ( $template_fields as $tf ) {
						$key = $tf['field_key'] ?? '';
						if ( '' !== $key ) {
							$field_data_types[ $key ] = $tf['data_type'] ?? '';
						}
					}
					break;
				}
			}
		}

		foreach ( $field_caps as $field_key => $cap_value ) {
			$cap_type = $this->extract_cap_type( $cap_value );

			if ( 'translate' !== $cap_type ) {
				continue;
			}

			// Check: translate fields should have content_format.
			$content_format = is_array( $cap_value ) ? ( $cap_value['content_format'] ?? '' ) : '';

			if ( empty( $content_format ) ) {
				$warnings[] = array(
					'code'    => 'missing_content_format',
					'message' => "Translate field '{$field_key}' in rule #{$rule_id} is missing content_format.",
					'context' => array( 'field_key' => $field_key ),
				);
				continue;
			}

			if ( 'code' === sanitize_key( (string) $content_format ) ) {
				$warnings[] = array(
					'code'    => 'non_translatable_content_format',
					'message' => "Field '{$field_key}' uses disallowed content_format 'code' in rule #{$rule_id}.",
					'context' => array(
						'field_key'      => $field_key,
						'content_format' => $content_format,
						'rule_id'        => $rule_id,
					),
				);
				continue;
			}

			// Check: content_format vs data_type reasonableness.
			$data_type = $field_data_types[ $field_key ] ?? '';
			if ( '' !== $data_type ) {
				$mismatch = $this->check_format_type_mismatch( $content_format, $data_type );
				if ( $mismatch ) {
					$warnings[] = array(
						'code'    => 'content_format_mismatch',
						'message' => "Field '{$field_key}' has content_format '{$content_format}' but data_type '{$data_type}' -- {$mismatch}.",
						'context' => array(
							'field_key'      => $field_key,
							'content_format' => $content_format,
							'data_type'      => $data_type,
						),
					);
				}
			}
		}

		return $warnings;
	}

	/**
	 * Translation strategy soundness check.
	 *
	 * Produces warnings when fields with non-translatable data types
	 * (numeric, id_ref, id_list, boolean, datetime) are marked as 'translate'.
	 *
	 * @since 1.4.0
	 *
	 * @param int        $rule_id          Rule ID.
	 * @param array|null $rule             Pre-loaded rule row (optional).
	 * @param array|null $field_data_types Pre-fetched field_key => data_type map (optional, fetched if null).
	 * @return array Array of warning entries.
	 */
	public function validate_strategy_soundness( int $rule_id, ?array $rule = null, ?array $field_data_types = null ): array {
		$warnings = array();

		if ( null === $rule ) {
			$rule = $this->load_rule( $rule_id );
			if ( null === $rule ) {
				return $warnings;
			}
		}

		$model_id    = (int) ( $rule['model_id'] ?? 0 );
		$object_name = $rule['object_name'] ?? ( $rule['post_type'] ?? '' );
		$field_caps  = $this->parse_field_capabilities( $rule );

		// Build data_type lookup from template fields if not pre-fetched.
		if ( null === $field_data_types ) {
			$field_data_types = array();
			if ( $model_id > 0 ) {
				$objects = Model_Object_Service::get_objects_for_model( $model_id );
				foreach ( $objects as $obj ) {
					if ( ( $obj['object_name'] ?? '' ) !== $object_name ) {
						continue;
					}
					$template_fields = Model_Object_Service::get_fields_for_object( (int) $obj['id'] );
					foreach ( $template_fields as $tf ) {
						$key = $tf['field_key'] ?? '';
						if ( '' !== $key ) {
							$field_data_types[ $key ] = $tf['data_type'] ?? '';
						}
					}
					break;
				}
			}
		}

		foreach ( $field_caps as $field_key => $cap_value ) {
			$cap_type  = $this->extract_cap_type( $cap_value );
			$data_type = $field_data_types[ $field_key ] ?? '';
			$content_format = is_array( $cap_value ) ? sanitize_key( (string) ( $cap_value['content_format'] ?? '' ) ) : '';

			if ( 'translate' !== $cap_type || '' === $data_type ) {
				if ( 'translate' === $cap_type && 'code' === $content_format ) {
					$warnings[] = array(
						'code'    => 'non_translatable_marked_translate',
						'message' => "Field '{$field_key}' uses disallowed content_format 'code' but is marked as 'translate' in rule #{$rule_id}.",
						'context' => array(
							'field_key' => $field_key,
							'rule_id'   => $rule_id,
						),
					);
				}
				continue;
			}

			if ( in_array( $data_type, self::NON_TRANSLATABLE_TYPES, true ) ) {
				$warnings[] = array(
					'code'    => 'non_translatable_marked_translate',
					'message' => "Field '{$field_key}' has data_type '{$data_type}' but is marked as 'translate' in rule #{$rule_id}. Consider 'sync', 'id_mapping', or 'skip' instead.",
					'context' => array(
						'field_key' => $field_key,
						'data_type' => $data_type,
						'rule_id'   => $rule_id,
					),
				);
			}
		}

		return $warnings;
	}

	/**
	 * Cross-rule chain consistency check.
	 *
	 * Validates:
	 * - related_taxonomies entries have corresponding term rules in the same model
	 * - id_mapping fields with reference_type=term have matching term rules
	 *
	 * Adapted from Rule_Chain_Validator::validate_l3_chain().
	 *
	 * @since 1.4.0
	 *
	 * @param int        $rule_id Rule ID.
	 * @param array|null $rule    Pre-loaded rule row (optional).
	 * @return array Array of error entries.
	 */
	public function validate_chain_consistency( int $rule_id, ?array $rule = null ): array {
		global $wpdb;

		$errors = array();

		if ( null === $rule ) {
			$rule = $this->load_rule( $rule_id );
			if ( null === $rule ) {
				return $errors;
			}
		}

		$model_id    = (int) ( $rule['model_id'] ?? 0 );
		$object_name = $rule['object_name'] ?? '';

		if ( $model_id <= 0 ) {
			return $errors;
		}

		// Only run chain checks for post-type rules (term rules don't have related_taxonomies).
		$rule_data_type = $rule['data_type'] ?? '';
		if ( 'post' !== $rule_data_type ) {
			return $errors;
		}

		// Check related_taxonomies have corresponding term rules.
		$taxonomies = json_decode( $rule['related_taxonomies'] ?? '[]', true );
		if ( ! is_array( $taxonomies ) ) {
			$taxonomies = array();
		}

		$rules_table = wptsall_table( 'translation_rules' );

		foreach ( $taxonomies as $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$term_rule_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE model_id = %d AND data_type = %s AND object_name = %s LIMIT 1',
					$rules_table,
					$model_id,
					'term',
					$taxonomy
				)
			);

			if ( ! $term_rule_id ) {
				$errors[] = array(
					'code'    => 'missing_term_rule',
					'message' => "Rule #{$rule_id} for '{$object_name}' declares related taxonomy '{$taxonomy}' but no term rule exists in model #{$model_id}.",
					'context' => array(
						'taxonomy' => $taxonomy,
						'model_id' => $model_id,
					),
				);
			}
		}

		// Check id_mapping fields with reference_type=term have matching term rules.
		$field_caps = $this->parse_field_capabilities( $rule );

		foreach ( $field_caps as $field_key => $cap_value ) {
			$cap_type = $this->extract_cap_type( $cap_value );

			if ( 'id_mapping' !== $cap_type || ! is_array( $cap_value ) ) {
				continue;
			}

			$ref_type = $cap_value['reference_type'] ?? '';
			if ( 'term' !== $ref_type ) {
				continue;
			}

			$ref_taxonomy = $cap_value['taxonomy'] ?? ( $cap_value['reference_taxonomy'] ?? '' );
			if ( empty( $ref_taxonomy ) ) {
				$errors[] = array(
					'code'    => 'id_mapping_term_no_taxonomy',
					'message' => "id_mapping field '{$field_key}' in rule #{$rule_id} has reference_type=term but no taxonomy specified.",
					'context' => array( 'field_key' => $field_key ),
				);
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$term_rule_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE model_id = %d AND data_type = %s AND object_name = %s LIMIT 1',
					$rules_table,
					$model_id,
					'term',
					$ref_taxonomy
				)
			);

			if ( ! $term_rule_id ) {
				$errors[] = array(
					'code'    => 'id_mapping_missing_term_rule',
					'message' => "id_mapping field '{$field_key}' in rule #{$rule_id} references taxonomy '{$ref_taxonomy}' but no term rule exists in model #{$model_id}.",
					'context' => array(
						'field_key' => $field_key,
						'taxonomy'  => $ref_taxonomy,
						'model_id'  => $model_id,
					),
				);
			}
		}

		return $errors;
	}

	/**
	 * L2 data coverage validation (Golden Record approach).
	 *
	 * Finds the posts with the most meta keys for the rule's post_type,
	 * then checks what percentage of actual meta keys are covered by
	 * field_capabilities, and what percentage of active taxonomies are
	 * declared in related_taxonomies.
	 *
	 * @since 1.5.0
	 *
	 * @param int        $rule_id     Rule ID.
	 * @param array|null $rule        Pre-loaded rule row (optional).
	 * @param int        $sample_size Number of Golden Record posts to sample.
	 * @return array {
	 *     field_coverage_percent:    int,
	 *     taxonomy_coverage_percent: int,
	 *     uncovered_fields:          string[],
	 *     uncovered_taxonomies:      string[],
	 *     sample_posts:              int[],
	 * }
	 */
	public function validate_data_coverage( int $rule_id, ?array $rule = null, int $sample_size = 3 ): array {
		global $wpdb;

		$empty_result = array(
			'field_coverage_percent'    => 100,
			'taxonomy_coverage_percent' => 100,
			'uncovered_fields'          => array(),
			'uncovered_taxonomies'      => array(),
			'sample_posts'              => array(),
		);

		if ( null === $rule ) {
			$rule = $this->load_rule( $rule_id );
			if ( null === $rule ) {
				return $empty_result;
			}
		}

		$object_name    = $rule['object_name'] ?? ( $rule['post_type'] ?? '' );
		$rule_data_type = $rule['data_type'] ?? '';

		// Only applicable to post-type rules.
		if ( 'post' !== $rule_data_type || '' === $object_name ) {
			return $empty_result;
		}

		$field_caps    = $this->parse_field_capabilities( $rule );
		$declared_keys = array_keys( $field_caps );

		// 1. Find Golden Records — posts with the most meta keys.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT p.ID
				FROM %i p
				LEFT JOIN %i pm ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status = %s
				GROUP BY p.ID
				ORDER BY COUNT(pm.meta_id) DESC
				LIMIT %d',
				$wpdb->posts,
				$wpdb->postmeta,
				$object_name,
				'publish',
				$sample_size
			)
		);

		if ( empty( $post_ids ) ) {
			return $empty_result;
		}

		$sample_post_ids = array_map( 'absint', $post_ids );

		// 2. Collect all meta keys from sampled posts.
		$all_meta_keys = array();
		foreach ( $sample_post_ids as $post_id ) {
			$post_meta = get_post_meta( $post_id );
			if ( is_array( $post_meta ) ) {
				$all_meta_keys = array_merge( $all_meta_keys, array_keys( $post_meta ) );
			}
		}
		$all_meta_keys = array_unique( $all_meta_keys );

		// Filter out internal WP meta keys.
		$excluded_prefixes  = array( '_wp_', '_edit_', '_encloseme', '_pingme', '_wp_trash_' );
		$filtered_meta_keys = array_filter(
			$all_meta_keys,
			function ( $key ) use ( $excluded_prefixes ) {
				foreach ( $excluded_prefixes as $prefix ) {
					if ( 0 === strpos( $key, $prefix ) ) {
						return false;
					}
				}
				return true;
			}
		);

		$uncovered_fields = array_values( array_diff( $filtered_meta_keys, $declared_keys ) );
		$total_relevant   = count( $filtered_meta_keys );
		$covered_count    = count( array_intersect( $filtered_meta_keys, $declared_keys ) );
		$field_coverage   = $total_relevant > 0
			? (int) round( ( $covered_count / $total_relevant ) * 100 )
			: 100;

		// 3. Taxonomy coverage.
		$taxonomies      = json_decode( $rule['related_taxonomies'] ?? '[]', true );
		$declared_taxos  = is_array( $taxonomies ) ? $taxonomies : array();
		$post_taxonomies = get_object_taxonomies( $object_name );

		// Only check public taxonomies that are actually used by sample posts.
		$uncovered_taxonomies = array();
		foreach ( $post_taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, $declared_taxos, true ) ) {
				continue;
			}
			$tax_obj = get_taxonomy( $taxonomy );
			if ( ! $tax_obj || ! $tax_obj->public ) {
				continue;
			}
			foreach ( $sample_post_ids as $post_id ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$uncovered_taxonomies[] = $taxonomy;
					break;
				}
			}
		}

		$public_tax_count    = count( array_filter( $post_taxonomies, function ( $t ) {
			$tax_obj = get_taxonomy( $t );
			return $tax_obj && $tax_obj->public;
		} ) );
		$taxonomy_coverage   = $public_tax_count > 0
			? (int) min( 100, round( ( count( $declared_taxos ) / $public_tax_count ) * 100 ) )
			: 100;

		return array(
			'field_coverage_percent'    => $field_coverage,
			'taxonomy_coverage_percent' => $taxonomy_coverage,
			'uncovered_fields'          => $uncovered_fields,
			'uncovered_taxonomies'      => $uncovered_taxonomies,
			'sample_posts'              => $sample_post_ids,
		);
	}

	// =============================================
	// Private helpers
	// =============================================

	/**
	 * Load a translation rule by ID.
	 *
	 * @param int $rule_id Rule ID.
	 * @return array|null Rule row as associative array, or null if not found.
	 */
	private function load_rule( int $rule_id ): ?array {
		global $wpdb;

		$rules_table = wptsall_table( 'translation_rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $rules_table, $rule_id ),
			ARRAY_A
		);

		return $rule ?: null;
	}

	/**
	 * Parse field_capabilities from a rule row.
	 *
	 * @param array $rule Rule row.
	 * @return array Parsed field capabilities (field_key => config).
	 */
	private function parse_field_capabilities( array $rule ): array {
		$raw  = $rule['field_capabilities'] ?? '';
		$caps = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $caps ) ? $caps : array();
	}

	/**
	 * Extract the capability type string from a field_capabilities entry.
	 *
	 * Handles both v1 string format ("translate") and v2 object format ({"type": "translate"}).
	 *
	 * @param string|array $cap_value The capability value.
	 * @return string The capability type.
	 */
	private function extract_cap_type( $cap_value ): string {
		if ( is_string( $cap_value ) ) {
			return $cap_value;
		}
		if ( is_array( $cap_value ) ) {
			return (string) ( $cap_value['type'] ?? ( $cap_value['capability'] ?? '' ) );
		}
		return '';
	}

	/**
	 * Check for content_format vs data_type mismatches.
	 *
	 * @param string $content_format The content format (e.g., 'rich_html', 'plain_text').
	 * @param string $data_type      The field data type (e.g., 'html', 'text').
	 * @return string Mismatch description, or empty string if no mismatch.
	 */
	private function check_format_type_mismatch( string $content_format, string $data_type ): string {
		// html data_type should use rich_html or html content_format.
		if ( 'html' === $data_type && ! in_array( $content_format, array( 'rich_html', 'html', 'block_html' ), true ) ) {
			return "html data_type typically uses 'rich_html' content_format";
		}

		// text data_type should use plain_text.
		if ( 'text' === $data_type && 'rich_html' === $content_format ) {
			return "text data_type with 'rich_html' content_format may cause unexpected markup handling";
		}

		// slug data_type should not be translated with rich_html.
		if ( 'slug' === $data_type && in_array( $content_format, array( 'rich_html', 'html', 'block_html' ), true ) ) {
			return "slug data_type should not use HTML content_format";
		}

		return '';
	}
}
