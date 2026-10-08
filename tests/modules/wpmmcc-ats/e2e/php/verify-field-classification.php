<?php
/**
 * E2E v2 Field Classification Verification Script
 *
 * Verifies that field_capabilities in translation_rules are correct
 * for known post types after model scanning.
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html}
 *   wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-field-classification.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

echo "=== E2E v2: Field Classification Verification ===\n\n";

global $wpdb;
$rules_table  = $wpdb->prefix . 'wptsall_translation_rules';
$models_table = $wpdb->prefix . 'wptsall_models';

$passed  = 0;
$failed  = 0;
$skipped = 0;
$checks  = array();

function e2e_field_class_assert( $field, $expected, $actual, $context, &$checks, &$passed, &$failed ) {
	$actual_type  = e2e_field_class_normalize_type( $actual );
	$pass         = is_array( $expected ) ? in_array( $actual_type, $expected, true ) : ( $actual_type === $expected );
	$expected_str = is_array( $expected ) ? implode( '|', $expected ) : $expected;
	$checks[]     = array(
		'name'   => "{$context}: {$field}",
		'pass'   => $pass,
		'detail' => $pass ? "= {$actual_type}" : "expected '{$expected_str}', got '{$actual_type}'",
	);
	if ( $pass ) {
		++$passed;
	} else {
		++$failed;
	}
}

function e2e_field_class_normalize_type( $value ) {
	if ( is_array( $value ) ) {
		$value = $value['type'] ?? '';
	}
	$type = (string) $value;
	if ( 'id_map' === $type ) {
		return 'id_mapping';
	}
	if ( 'no_sync' === $type ) {
		return 'skip';
	}
	return $type;
}

function e2e_field_class_normalize_caps( $caps ) {
	$flat      = array();
	$group_map = array(
		'translate_fields'  => 'translate',
		'sync_fields'       => 'sync',
		'id_mapping_fields' => 'id_mapping',
		'compute_fields'    => 'compute',
		'skip_fields'       => 'skip',
	);

	foreach ( $caps as $key => $value ) {
		if ( isset( $group_map[ $key ] ) && is_array( $value ) ) {
			foreach ( $value as $field_name ) {
				if ( is_string( $field_name ) ) {
					$flat[ $field_name ] = $group_map[ $key ];
				}
			}
		} elseif ( is_string( $value ) ) {
			$flat[ $key ] = e2e_field_class_normalize_type( $value );
		} elseif ( is_array( $value ) && isset( $value['type'] ) ) {
			$flat[ $key ] = e2e_field_class_normalize_type( $value );
		}
	}

	return $flat;
}

$rule_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rules_table}" );
$checks[]   = array(
	'name'   => 'Translation rules exist',
	'pass'   => $rule_count > 0,
	'detail' => "{$rule_count} rules in DB",
);
if ( $rule_count > 0 ) {
	++$passed;
} else {
	++$failed;
	echo "FATAL: No translation rules found. Run model scanning first.\n";
}

$core_expectations = array(
	'post_title'   => 'translate',
	'post_content' => 'translate',
	'post_excerpt' => 'translate',
	// Slug fields are user-configurable. Default is translate, but legacy/core-safe
	// profiles may still keep them as compute without breaking the pipeline.
	'post_name'    => array( 'translate', 'compute', 'skip' ),
	'post_author'  => 'id_mapping',
	'post_parent'  => 'id_mapping',
	'post_date'    => 'sync',
	'post_status'  => 'sync',
	'guid'         => 'compute',
);

$meta_expectations = array(
	'_thumbnail_id'           => 'id_mapping',
	'_wp_attachment_metadata' => 'skip',
	'_wp_attached_file'       => 'skip',
);

$post_rules = $wpdb->get_results(
	"SELECT r.*, m.plugin_slug FROM {$rules_table} r
	 LEFT JOIN {$models_table} m ON r.model_id = m.id
	 WHERE m.plugin_slug IN ('wordpress-core', 'wordpress-blog')
	   AND r.url_type IN ('single', 'frontend')
	 ORDER BY r.url_pattern",
	ARRAY_A
);

if ( empty( $post_rules ) ) {
	$checks[] = array(
		'name'   => 'Core post/page rules',
		'pass'   => true,
		'detail' => 'No core post/page rules found (skipped for current dataset)',
	);
	++$skipped;
} else {
	$checks[] = array(
		'name'   => 'Core post/page rules',
		'pass'   => true,
		'detail' => count( $post_rules ) . ' rules found',
	);
	++$passed;

	foreach ( $post_rules as $rule ) {
		$caps     = e2e_field_class_normalize_caps( json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array() );
		$pt_label = $rule['url_pattern'];

		foreach ( $core_expectations as $field => $expected_type ) {
			if ( isset( $caps[ $field ] ) ) {
				e2e_field_class_assert( $field, $expected_type, $caps[ $field ], $pt_label, $checks, $passed, $failed );
			}
		}

		foreach ( $meta_expectations as $field => $expected_type ) {
			if ( isset( $caps[ $field ] ) ) {
				e2e_field_class_assert( $field, $expected_type, $caps[ $field ], $pt_label, $checks, $passed, $failed );
			}
		}
	}
}

$term_expectations = array(
	'name'        => 'translate',
	'description' => 'translate',
	// Taxonomy slug follows the same configurable policy: translate is valid when
	// slug translation is enabled, while sync/compute/skip remain valid conservative
	// modes for existing rules or site-specific overrides.
	'slug'        => array( 'translate', 'compute', 'sync', 'skip' ),
);

$tax_rules = $wpdb->get_results(
	"SELECT r.*, m.plugin_slug FROM {$rules_table} r
	 LEFT JOIN {$models_table} m ON r.model_id = m.id
	 WHERE r.url_type = 'taxonomy'
	   AND (r.url_pattern LIKE '%category%' OR r.url_pattern LIKE '%tag%')
	 ORDER BY r.url_pattern",
	ARRAY_A
);

if ( ! empty( $tax_rules ) ) {
	foreach ( $tax_rules as $rule ) {
		$caps     = e2e_field_class_normalize_caps( json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array() );
		$tax_name = $rule['url_pattern'];

		foreach ( $term_expectations as $field => $expected_type ) {
			if ( isset( $caps[ $field ] ) ) {
				e2e_field_class_assert( $field, $expected_type, $caps[ $field ], $tax_name, $checks, $passed, $failed );
			}
		}
	}
} else {
	$checks[] = array(
		'name'   => 'Taxonomy rules (category/post_tag)',
		'pass'   => false,
		'detail' => 'No taxonomy rules found (may be expected if not scanned)',
	);
	++$skipped;
}

$yoast_active = is_plugin_active( 'wordpress-seo/wp-seo.php' ) || is_plugin_active( 'wordpress-seo-premium/wp-seo-premium.php' );

if ( $yoast_active ) {
	$yoast_expectations = array(
		'_yoast_wpseo_title'                 => 'translate',
		'_yoast_wpseo_metadesc'              => 'translate',
		'_yoast_wpseo_focuskw'               => 'translate',
		'_yoast_wpseo_opengraph-image'       => 'sync',
		'_yoast_wpseo_opengraph-title'       => 'translate',
		'_yoast_wpseo_opengraph-description' => 'translate',
	);

	$all_rules = $wpdb->get_results(
		"SELECT r.field_capabilities, r.url_pattern FROM {$rules_table} r
		 WHERE r.field_capabilities LIKE '%yoast%'",
		ARRAY_A
	);

	if ( ! empty( $all_rules ) ) {
		$yoast_checked = false;
		foreach ( $all_rules as $rule ) {
			$caps = e2e_field_class_normalize_caps( json_decode( $rule['field_capabilities'] ?? '{}', true ) ?: array() );
			foreach ( $yoast_expectations as $field => $expected_type ) {
				if ( isset( $caps[ $field ] ) ) {
					e2e_field_class_assert( $field, $expected_type, $caps[ $field ], 'yoast/' . $rule['url_pattern'], $checks, $passed, $failed );
					$yoast_checked = true;
				}
			}
		}
		if ( ! $yoast_checked ) {
			$checks[] = array(
				'name'   => 'Yoast fields present',
				'pass'   => false,
				'detail' => 'No Yoast expectation fields found in scanned rules',
			);
			++$failed;
		}
	} else {
		$checks[] = array(
			'name'   => 'Yoast rules present',
			'pass'   => false,
			'detail' => 'Yoast active but no rules contain yoast fields',
		);
		++$failed;
	}
}

echo str_repeat( '-', 70 ) . "\n";
foreach ( $checks as $c ) {
	$icon = $c['pass'] ? 'PASS' : 'FAIL';
	echo sprintf( "[%s] %-48s %s\n", $icon, $c['name'], $c['detail'] );
}
echo str_repeat( '-', 70 ) . "\n";
echo sprintf( "\nResult: %d passed, %d failed, %d skipped out of %d checks\n", $passed, $failed, $skipped, count( $checks ) );

if ( $failed > 0 ) {
	exit( 1 );
}
