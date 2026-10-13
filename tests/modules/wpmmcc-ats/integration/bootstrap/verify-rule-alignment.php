<?php
/**
 * Bootstrap: Verify Translation Rule Alignment
 *
 * Gate purpose:
 * 1) Ensure initialization scan has run against current seeded data.
 * 2) Compare discovered object fields (model_object_fields) with generated
 *    translation rule capabilities (translation_rules.field_capabilities).
 * 3) Fail fast if rule/object alignment quality is below threshold.
 *
 * This script intentionally blocks downstream flow tests when rule generation
 * quality is poor, because manual/automatic translation will become unreliable.
 *
 * @package WPTSALL\DevTools\Bootstrap
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

global $wpdb;

echo "=== verify-rule-alignment.php ===\n";
echo "Auditing translation rule alignment against scanned objects...\n\n";

/**
 * Read a boolean environment variable with fallback.
 *
 * @param string $name    Env var name.
 * @param bool   $default Default value.
 * @return bool
 */
function wptsall_rule_align_bool_env( $name, $default ) {
	$value = getenv( $name );
	if ( false === $value || '' === $value ) {
		return (bool) $default;
	}
	$value = strtolower( trim( (string) $value ) );
	return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * Read a float environment variable with range validation and fallback.
 *
 * @param string $name    Env var name.
 * @param float  $default Default value.
 * @param float  $min     Inclusive minimum value.
 * @param float  $max     Inclusive maximum value.
 * @return float
 */
function wptsall_rule_align_float_env( $name, $default, $min, $max ) {
	$value = getenv( $name );
	if ( false === $value || '' === $value ) {
		return (float) $default;
	}

	$parsed = (float) $value;
	if ( $parsed < $min || $parsed > $max ) {
		return (float) $default;
	}

	return $parsed;
}

/**
 * Normalize field capability type aliases.
 *
 * @param string $type Raw type.
 * @return string Normalized type.
 */
function wptsall_rule_align_normalize_type( $type ) {
	$type = strtolower( trim( (string) $type ) );
	if ( in_array( $type, array( 'mapping', 'id_map', 'id_mapping' ), true ) ) {
		return 'id_mapping';
	}
	if ( in_array( $type, array( 'no_sync', 'skip' ), true ) ) {
		return 'skip';
	}
	if ( in_array( $type, array( 'translate', 'sync', 'compute' ), true ) ) {
		return $type;
	}
	return 'sync';
}

/**
 * Determine expected rule type for a discovered field.
 *
 * @param array  $field   model_object_fields row.
 * @param string $context post|term context for classifier.
 * @return string Expected normalized type.
 */
function wptsall_rule_align_expected_type( array $field, $context ) {
	$data_type      = strtolower( (string) ( $field['data_type'] ?? '' ) );
	$reference_type = strtolower( (string) ( $field['reference_type'] ?? '' ) );
	$field_key      = (string) ( $field['field_key'] ?? '' );

	if ( '' === $field_key ) {
		return 'skip';
	}

	// Taxonomy core fields have well-defined capability types.
	if ( 'term' === $context ) {
		$taxonomy_core = array(
			'name'        => 'translate',
			'description' => 'translate',
			'slug'        => 'translate',
			'parent'      => 'id_mapping',
		);
		if ( isset( $taxonomy_core[ $field_key ] ) ) {
			return $taxonomy_core[ $field_key ];
		}
	}

	// Structural references should be id_mapping regardless of name.
	if ( ! empty( $reference_type ) || in_array( $data_type, array( 'id_ref', 'id_list', 'reference' ), true ) ) {
		return 'id_mapping';
	}

	$sample_values = array();
	if ( isset( $field['sample_value'] ) && '' !== (string) $field['sample_value'] ) {
		$sample_values[] = (string) $field['sample_value'];
	}

	if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
		$classification = \WPTSALL\Core\Smart_Field_Classifier::classify_for_chain( $field_key, $sample_values, $context );
		$type           = $classification['type'] ?? 'sync';
		return wptsall_rule_align_normalize_type( $type );
	}

	return 'sync';
}

/**
 * Return true when object should participate in alignment audit.
 *
 * @param array $object model_objects row.
 * @return bool
 */
function wptsall_rule_align_is_auditable_object( array $object ) {
	$object_type = (string) ( $object['object_type'] ?? '' );
	if ( ! in_array( $object_type, array( 'post_type', 'taxonomy' ), true ) ) {
		return false;
	}

	$metadata = $object['metadata'] ?? null;
	if ( ! is_array( $metadata ) ) {
		return true;
	}

	// Public-object guard: if scanner explicitly marks object as non-public,
	// skip strict rule existence checks for it.
	if ( array_key_exists( 'public', $metadata ) && false === (bool) $metadata['public'] ) {
		return false;
	}
	if ( array_key_exists( 'publicly_queryable', $metadata ) && false === (bool) $metadata['publicly_queryable'] ) {
		return false;
	}

	return true;
}

/**
 * Ensure an admin user context for permissioned REST endpoints.
 *
 * @throws Exception When no admin user can be resolved.
 * @return int Admin user ID.
 */
function wptsall_rule_align_ensure_admin_user() {
	$current = get_current_user_id();
	if ( $current > 0 && user_can( $current, 'manage_options' ) ) {
		return $current;
	}

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => array( 'ID' ),
		)
	);
	if ( empty( $admins ) ) {
		throw new Exception( 'No administrator account found; cannot run initialize scan.' );
	}

	$admin_id = (int) $admins[0]->ID;
	wp_set_current_user( $admin_id );
	return $admin_id;
}

/**
 * Trigger initialization scan route (first-setup path) against current data.
 *
 * @throws Exception When initialize route is unavailable or fails.
 * @return array Response payload.
 */
function wptsall_rule_align_run_initialize_scan() {
	if ( ! did_action( 'rest_api_init' ) ) {
		do_action( 'rest_api_init' );
	}

	$request  = new WP_REST_Request( 'POST', '/wptsall/v2/initialize' );
	$response = rest_do_request( $request );
	$status   = $response->get_status();
	$data     = $response->get_data();

	if ( $status < 200 || $status >= 300 ) {
		$message = is_array( $data ) ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : (string) $data;
		throw new Exception( "Initialize scan failed (HTTP {$status}): {$message}" );
	}

	return is_array( $data ) ? $data : array();
}

$min_coverage      = wptsall_rule_align_float_env( 'WPTSALL_RULE_ALIGNMENT_MIN_COVERAGE', 0.80, 0.01, 1.0 );
$max_mismatch_rate = wptsall_rule_align_float_env( 'WPTSALL_RULE_ALIGNMENT_MAX_MISMATCH_RATE', 0.25, 0.0, 1.0 );
$fail_on_missing_rule = wptsall_rule_align_bool_env( 'WPTSALL_RULE_ALIGNMENT_FAIL_ON_MISSING_RULE', true );
$run_initialize_scan  = wptsall_rule_align_bool_env( 'WPTSALL_RULE_ALIGNMENT_RUN_INITIALIZE', true );
$max_examples         = (int) getenv( 'WPTSALL_RULE_ALIGNMENT_MAX_EXAMPLES' );
if ( $max_examples <= 0 ) {
	$max_examples = 12;
}

echo "Config:\n";
echo "  - min_coverage: {$min_coverage}\n";
echo "  - max_mismatch_rate: {$max_mismatch_rate}\n";
echo '  - fail_on_missing_rule: ' . ( $fail_on_missing_rule ? 'true' : 'false' ) . "\n";
echo '  - run_initialize_scan: ' . ( $run_initialize_scan ? 'true' : 'false' ) . "\n\n";

if ( ! function_exists( 'wptsall_table' ) ) {
	throw new Exception( 'wptsall_table() not found.' );
}
if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
	throw new Exception( 'Translation_Rule_Service class not found.' );
}
if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Model_Object_Service' ) ) {
	throw new Exception( 'Model_Object_Service class not found.' );
}
if ( ! class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
	throw new Exception( 'Smart_Field_Classifier class not found.' );
}

$admin_id = wptsall_rule_align_ensure_admin_user();
echo "Admin context: user_id={$admin_id}\n";

$initialize_result = array();
if ( $run_initialize_scan ) {
	echo "Step: running initialize scan...\n";
	$initialize_result = wptsall_rule_align_run_initialize_scan();
	$scanned           = (int) ( $initialize_result['total_scanned'] ?? 0 );
	$rules_created     = (int) ( $initialize_result['rules_created'] ?? 0 );
	echo "  - total_scanned: {$scanned}\n";
	echo "  - rules_created: {$rules_created}\n\n";
}

$models_table = wptsall_table( 'models' );
$rules_table  = wptsall_table( 'translation_rules' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$models = $wpdb->get_results(
	"SELECT id, plugin_slug, plugin_name, is_content_plugin FROM {$models_table} ORDER BY id ASC",
	ARRAY_A
);

$summary = array(
	'models_total'             => count( $models ),
	'models_audited'           => 0,
	'objects_audited'          => 0,
	'missing_rules'            => 0,
	'fields_required'          => 0,
	'fields_missing_in_rule'   => 0,
	'fields_type_mismatch'     => 0,
	'translate_without_format' => 0,
	'orphan_rule_fields'       => 0,
);

$model_reports = array();

foreach ( $models as $model ) {
	$model_id    = (int) ( $model['id'] ?? 0 );
	$plugin_slug = (string) ( $model['plugin_slug'] ?? '' );
	if ( $model_id <= 0 || '' === $plugin_slug ) {
		continue;
	}

	$objects = \WPTSALL\Models\Services\Model_Object_Service::get_objects_for_model( $model_id );
	$objects = array_values(
		array_filter(
			$objects,
			function ( $object ) {
				return wptsall_rule_align_is_auditable_object( $object );
			}
		)
	);

	if ( empty( $objects ) ) {
		continue;
	}

	$rules = \WPTSALL\Models\Services\Translation_Rule_Service::get_model_rules( $model_id );

	$rule_map = array(
		'post' => array(),
		'term' => array(),
	);
	foreach ( $rules as $rule ) {
		$data_type   = (string) ( $rule['data_type'] ?? '' );
		$object_name = (string) ( $rule['object_name'] ?? '' );
		if ( '' === $object_name ) {
			continue;
		}
		if ( 'post' === $data_type ) {
			$rule_map['post'][ $object_name ] = $rule;
		} elseif ( 'term' === $data_type ) {
			$rule_map['term'][ $object_name ] = $rule;
		}
	}

	$model_result = array(
		'model_id'                  => $model_id,
		'plugin_slug'               => $plugin_slug,
		'plugin_name'               => (string) ( $model['plugin_name'] ?? $plugin_slug ),
		'objects'                   => count( $objects ),
		'rules_total'               => count( $rules ),
		'missing_rules'             => 0,
		'fields_required'           => 0,
		'fields_missing_in_rule'    => 0,
		'fields_type_mismatch'      => 0,
		'translate_without_format'  => 0,
		'orphan_rule_fields'        => 0,
		'issues'                    => array(),
	);

	$summary['models_audited'] += 1;

	foreach ( $objects as $object ) {
		$summary['objects_audited'] += 1;

		$object_type = (string) ( $object['object_type'] ?? '' );
		$object_name = (string) ( $object['object_name'] ?? '' );
		$rule_key    = 'post_type' === $object_type ? 'post' : 'term';
		$context     = 'post_type' === $object_type ? 'post' : 'term';

		$rule = $rule_map[ $rule_key ][ $object_name ] ?? null;
		if ( ! is_array( $rule ) ) {
			$summary['missing_rules'] += 1;
			$model_result['missing_rules'] += 1;
			$model_result['issues'][] = array(
				'object_type' => $object_type,
				'object_name' => $object_name,
				'issue'       => 'missing_rule',
				'severity'    => 'critical',
			);
			continue;
		}

		$field_caps = is_array( $rule['field_capabilities'] ?? null ) ? $rule['field_capabilities'] : array();

		$fields = \WPTSALL\Models\Services\Model_Object_Service::get_fields_for_object( (int) $object['id'] );
		$fields = array_values(
			array_filter(
				$fields,
				function ( $field ) {
					$status = strtolower( (string) ( $field['status'] ?? 'active' ) );
					return ! in_array( $status, array( 'deprecated', 'orphan' ), true );
				}
			)
		);

		$discovered_keys = array();
		foreach ( $fields as $field ) {
			$field_key = (string) ( $field['field_key'] ?? '' );
			if ( '' === $field_key ) {
				continue;
			}
			$discovered_keys[ $field_key ] = true;

			$expected = wptsall_rule_align_expected_type( $field, $context );
			if ( 'skip' === $expected ) {
				continue;
			}

			$summary['fields_required'] += 1;
			$model_result['fields_required'] += 1;

			if ( ! array_key_exists( $field_key, $field_caps ) ) {
				$summary['fields_missing_in_rule'] += 1;
				$model_result['fields_missing_in_rule'] += 1;
				if ( count( $model_result['issues'] ) < $max_examples ) {
					$model_result['issues'][] = array(
						'object_type'    => $object_type,
						'object_name'    => $object_name,
						'field_key'      => $field_key,
						'expected_type'  => $expected,
						'actual_type'    => null,
						'issue'          => 'missing_field_capability',
						'severity'       => 'high',
					);
				}
				continue;
			}

			$actual_type = wptsall_rule_align_normalize_type( $field_caps[ $field_key ]['type'] ?? 'sync' );
			if ( $actual_type !== $expected ) {
				$summary['fields_type_mismatch'] += 1;
				$model_result['fields_type_mismatch'] += 1;
				if ( count( $model_result['issues'] ) < $max_examples ) {
					$model_result['issues'][] = array(
						'object_type'    => $object_type,
						'object_name'    => $object_name,
						'field_key'      => $field_key,
						'expected_type'  => $expected,
						'actual_type'    => $actual_type,
						'issue'          => 'type_mismatch',
						'severity'       => 'high',
					);
				}
			}

			if ( 'translate' === $actual_type ) {
				$content_format = (string) ( $field_caps[ $field_key ]['content_format'] ?? '' );
				if ( '' === $content_format ) {
					$summary['translate_without_format'] += 1;
					$model_result['translate_without_format'] += 1;
					if ( count( $model_result['issues'] ) < $max_examples ) {
						$model_result['issues'][] = array(
							'object_type' => $object_type,
							'object_name' => $object_name,
							'field_key'   => $field_key,
							'issue'       => 'translate_missing_content_format',
							'severity'    => 'medium',
						);
					}
				}
			}
		}

		// Rule fields not present in current discovered object fields: warning only.
		foreach ( $field_caps as $field_key => $cap ) {
			if ( ! isset( $discovered_keys[ $field_key ] ) ) {
				++$summary['orphan_rule_fields'];
				++$model_result['orphan_rule_fields'];
				if ( count( $model_result['issues'] ) < $max_examples ) {
					$model_result['issues'][] = array(
						'object_type' => $object_type,
						'object_name' => $object_name,
						'field_key'   => $field_key,
						'issue'       => 'rule_field_not_in_scanned_object',
						'severity'    => 'low',
					);
				}
			}
		}
	}

	$model_required = max( 1, (int) $model_result['fields_required'] );
	$model_coverage = ( $model_result['fields_required'] - $model_result['fields_missing_in_rule'] ) / $model_required;
	$model_mismatch = $model_result['fields_type_mismatch'] / $model_required;

	$model_result['coverage']      = round( $model_coverage, 4 );
	$model_result['mismatch_rate'] = round( $model_mismatch, 4 );

	$model_reports[] = $model_result;
}

$fields_required_global = max( 1, (int) $summary['fields_required'] );
$global_coverage        = ( $summary['fields_required'] - $summary['fields_missing_in_rule'] ) / $fields_required_global;
$global_mismatch_rate   = $summary['fields_type_mismatch'] / $fields_required_global;

$gate_fail_reasons = array();
if ( 0 === $summary['models_audited'] ) {
	$gate_fail_reasons[] = 'No auditable models found after initialization scan.';
}
if ( $fail_on_missing_rule && $summary['missing_rules'] > 0 ) {
	$gate_fail_reasons[] = 'Missing translation rules for scanned public objects: ' . $summary['missing_rules'];
}
if ( $global_coverage < $min_coverage ) {
	$gate_fail_reasons[] = sprintf( 'Global coverage %.4f is below threshold %.4f', $global_coverage, $min_coverage );
}
if ( $global_mismatch_rate > $max_mismatch_rate ) {
	$gate_fail_reasons[] = sprintf( 'Global type mismatch rate %.4f exceeds threshold %.4f', $global_mismatch_rate, $max_mismatch_rate );
}

$report = array(
	'timestamp'          => gmdate( 'c' ),
	'config'             => array(
		'min_coverage'        => $min_coverage,
		'max_mismatch_rate'   => $max_mismatch_rate,
		'fail_on_missing_rule'=> $fail_on_missing_rule,
		'run_initialize_scan' => $run_initialize_scan,
		'max_examples'        => $max_examples,
	),
	'initialize_result'  => $initialize_result,
	'summary'            => array_merge(
		$summary,
		array(
			'global_coverage'      => round( $global_coverage, 4 ),
			'global_mismatch_rate' => round( $global_mismatch_rate, 4 ),
		)
	),
	'gate_failed'        => ! empty( $gate_fail_reasons ),
	'gate_fail_reasons'  => $gate_fail_reasons,
	'models'             => $model_reports,
);

$report_dir = dirname( __DIR__ ) . '/reports';
if ( ! is_dir( $report_dir ) ) {
	wp_mkdir_p( $report_dir );
}
$report_file = $report_dir . '/rule-alignment-' . gmdate( 'Ymd-His' ) . '.json';
file_put_contents( $report_file, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

echo "Summary:\n";
echo '  - models_audited: ' . $summary['models_audited'] . "\n";
echo '  - objects_audited: ' . $summary['objects_audited'] . "\n";
echo '  - missing_rules: ' . $summary['missing_rules'] . "\n";
echo '  - fields_required: ' . $summary['fields_required'] . "\n";
echo '  - fields_missing_in_rule: ' . $summary['fields_missing_in_rule'] . "\n";
echo '  - fields_type_mismatch: ' . $summary['fields_type_mismatch'] . "\n";
echo '  - global_coverage: ' . round( $global_coverage, 4 ) . "\n";
echo '  - global_mismatch_rate: ' . round( $global_mismatch_rate, 4 ) . "\n";
echo "Report: {$report_file}\n\n";

if ( ! empty( $gate_fail_reasons ) ) {
	echo "Rule alignment gate FAILED:\n";
	foreach ( $gate_fail_reasons as $reason ) {
		echo "  - {$reason}\n";
	}
	echo "\n";
	throw new Exception( 'Rule alignment gate failed. See report: ' . $report_file );
}

echo "Rule alignment gate PASSED.\n";
echo "=== verify-rule-alignment.php DONE ===\n\n";
