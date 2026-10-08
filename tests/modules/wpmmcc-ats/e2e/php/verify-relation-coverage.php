<?php
/**
 * E2E v2 relation coverage verification (Stage 5 hard gate).
 *
 * Enforces full coverage policy:
 * - all current plugins must be test-covered per active relation
 * - WP core objects must be test-covered per active relation
 * - relations include normal targets only: wp + virtual
 *
 * Outputs:
 * - tests/modules/wpmmcc-ats/e2e/runtime/relation-plugin-coverage.json
 * - tests/modules/wpmmcc-ats/e2e/runtime/relation-core-coverage.json
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-relation-coverage.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Verify Relation Coverage (Plugins + Core) ===\n\n";

/**
 * Decode rule field capabilities.
 *
 * @param mixed $raw Raw DB value.
 * @return array
 */
function e2e_cov_decode_caps( $raw ) {
	if ( is_array( $raw ) ) {
		return $raw;
	}
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return array();
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Whether capabilities contain at least one contract-like field.
 *
 * @param array $caps Field capabilities.
 * @return bool
 */
function e2e_cov_has_field_contract( array $caps ) {
	foreach ( $caps as $field_key => $cfg ) {
		if ( ! is_array( $cfg ) ) {
			continue;
		}
		$type   = sanitize_key( (string) ( $cfg['type'] ?? '' ) );
		$format = sanitize_key( (string) ( $cfg['content_format'] ?? '' ) );
		if ( '' !== $type || '' !== $format ) {
			return true;
		}
	}
	return false;
}

/**
 * Detect plugin signature on capabilities.
 *
 * @param string $plugin Plugin slug.
 * @param array  $caps   Field capabilities.
 * @return bool
 */
function e2e_cov_caps_match_plugin_signature( $plugin, array $caps ) {
	foreach ( $caps as $field_key => $cfg ) {
		$key = (string) $field_key;

		if ( 'wordpress-seo' === $plugin ) {
			if ( false !== strpos( $key, '_yoast_wpseo_' ) ) {
				return true;
			}
			continue;
		}

		if ( 'advanced-custom-fields' === $plugin ) {
			if ( 'brand' === $key || 0 === strpos( $key, 'acf_' ) || false !== strpos( $key, 'field_' ) ) {
				return true;
			}
			continue;
		}
	}

	return false;
}

/**
 * Check if capabilities include a media_ref field.
 *
 * @param array $caps Field capabilities.
 * @return bool
 */
function e2e_cov_caps_has_media_ref( array $caps ) {
	foreach ( $caps as $cfg ) {
		if ( ! is_array( $cfg ) ) {
			continue;
		}
		$format = sanitize_key( (string) ( $cfg['content_format'] ?? '' ) );
		if ( 'media_ref' === $format ) {
			return true;
		}
	}
	return false;
}

/**
 * Build stable JSON writer helper.
 *
 * @param string $filename Output file name under runtime dir.
 * @param array  $payload  JSON payload.
 * @return void
 */
function e2e_cov_save_runtime_json( $filename, array $payload ) {
	$dir = e2e_runtime_dir();
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	file_put_contents(
		$dir . '/' . ltrim( $filename, '/' ),
		wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
	);
}

$checks  = array();
$passed  = 0;
$failed  = 0;
$skipped = 0;

$relations_table = e2e_table( 'site_relations' );
$models_table    = e2e_table( 'models' );
$rm_table        = e2e_table( 'relation_models' );
$rules_table     = e2e_table( 'translation_rules' );
$ptc_table       = e2e_table( 'relation_post_type_configs' );

foreach ( array( $relations_table, $models_table, $rm_table, $rules_table, $ptc_table ) as $table_name ) {
	if ( ! e2e_table_exists( $table_name ) ) {
		echo "ERROR: Table {$table_name} does not exist.\n";
		exit( 1 );
	}
}

// Resolve source site from runtime relation IDs (fallback: 1).
$runtime_relations = e2e_load_relation_ids();
$active_runtime_relation_ids = array_values( e2e_active_relation_ids( $runtime_relations ) );
$active_runtime_relation_ids = array_map( 'intval', $active_runtime_relation_ids );
$active_runtime_relation_ids = array_values(
	array_filter(
		$active_runtime_relation_ids,
		static function ( int $relation_id ): bool {
			return $relation_id > 0;
		}
	)
);
$source_site_id    = 1;
foreach ( array( 'wp', 'virtual', 'self' ) as $type_key ) {
	$rid = (int) ( $runtime_relations[ $type_key ] ?? 0 );
	if ( $rid <= 0 ) {
		continue;
	}
	$tmp_source = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT source_site_id FROM {$relations_table} WHERE id = %d LIMIT 1",
			$rid
		)
	);
	if ( $tmp_source > 0 ) {
		$source_site_id = $tmp_source;
		break;
	}
}

// Relation scope: prefer current runtime relation IDs for this lane (wp + virtual).
$relations = array();
if ( ! empty( $active_runtime_relation_ids ) ) {
	$placeholders = implode( ',', array_fill( 0, count( $active_runtime_relation_ids ), '%d' ) );
	$sql          = "SELECT id, source_site_id, target_site_type, target_site_id, target_lang, status
		 FROM {$relations_table}
		 WHERE id IN ({$placeholders})
		   AND status = 'active'
		   AND target_site_type IN ('wp', 'virtual')
		 ORDER BY id ASC";
	$relations = $wpdb->get_results(
		$wpdb->prepare( $sql, $active_runtime_relation_ids ),
		ARRAY_A
	);
}

if ( empty( $relations ) ) {
	// Fallback: if runtime relation IDs are absent/stale, detect all active normal relations by source site.
	// Never widen to "all active" or another lane's incomplete relations poison
	// coverage. Isolated slots normally have their own DB; shared/legacy slots
	// still fail closed so Stage 5 can recreate the relation set.
	$parallel = (string) getenv( 'E2E_MATRIX_PARALLEL' );
	$slot     = e2e_slot();
	if ( '1' === $parallel || ( 'shared' !== $slot ) ) {
		e2e_check(
			'Active normal relations',
			false,
			'No active wp/virtual relations matched runtime relation-ids.json for this lane (parallel/slot mode refuses global fallback).',
			$checks,
			$passed,
			$failed
		);
		e2e_print_results( $checks, $passed, $failed, $skipped );
		exit( 1 );
	}
	$relations = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, source_site_id, target_site_type, target_site_id, target_lang, status
			 FROM {$relations_table}
			 WHERE source_site_id = %d
			   AND status = 'active'
			   AND target_site_type IN ('wp', 'virtual')
			 ORDER BY id ASC",
			$source_site_id
		),
		ARRAY_A
	);
}

if ( empty( $relations ) ) {
	// Last fallback: detect all active normal relations (serial/shared runs only).
	$parallel = (string) getenv( 'E2E_MATRIX_PARALLEL' );
	$slot     = e2e_slot();
	if ( '1' === $parallel || ( 'shared' !== $slot ) ) {
		e2e_check(
			'Active normal relations',
			false,
			'No active wp/virtual relations for this lane; parallel/slot mode refuses scanning all relations.',
			$checks,
			$passed,
			$failed
		);
		e2e_print_results( $checks, $passed, $failed, $skipped );
		exit( 1 );
	}
	$relations = $wpdb->get_results(
		"SELECT id, source_site_id, target_site_type, target_site_id, target_lang, status
		 FROM {$relations_table}
		 WHERE status = 'active'
		   AND target_site_type IN ('wp', 'virtual')
		 ORDER BY source_site_id ASC, id ASC",
		ARRAY_A
	);
}

if ( empty( $relations ) ) {
	e2e_check(
		'Active normal relations',
		false,
		'No active wp/virtual relations found.',
		$checks,
		$passed,
		$failed
	);
	e2e_print_results( $checks, $passed, $failed, $skipped );
	exit( 1 );
}

// Active models index.
$models = $wpdb->get_results(
	"SELECT id, plugin_slug, plugin_name, status FROM {$models_table} WHERE status = 'active' ORDER BY id",
	ARRAY_A
);
$models_by_id = array();
foreach ( $models as $row ) {
	$mid = (int) ( $row['id'] ?? 0 );
	if ( $mid <= 0 ) {
		continue;
	}
	$models_by_id[ $mid ] = $row;
}

$plugin_requirements = e2e_plugin_slugs(); // 15 plugins, mandatory.
$core_objects        = array( 'post', 'page', 'category', 'post_tag', 'attachment' );

$plugin_report = array(
	'generated_at'      => gmdate( 'c' ),
	'source_site_id'    => $source_site_id,
	'relation_count'    => count( $relations ),
	'required_plugins'  => $plugin_requirements,
	'relations'         => array(),
	'summary'           => array(
		'total_checks' => 0,
		'passed'       => 0,
		'failed'       => 0,
	),
);

$core_report = array(
	'generated_at'      => gmdate( 'c' ),
	'source_site_id'    => $source_site_id,
	'relation_count'    => count( $relations ),
	'required_objects'  => $core_objects,
	'relations'         => array(),
	'summary'           => array(
		'total_checks' => 0,
		'passed'       => 0,
		'failed'       => 0,
	),
);

echo "Active normal relations: " . count( $relations ) . "\n";

foreach ( $relations as $relation ) {
	$relation_id   = (int) ( $relation['id'] ?? 0 );
	$target_type   = (string) ( $relation['target_site_type'] ?? '' );
	$target_id     = (string) ( $relation['target_site_id'] ?? '' );
	$target_lang   = (string) ( $relation['target_lang'] ?? '' );

	echo "\n--- Relation #{$relation_id} ({$target_type}:{$target_id}, {$target_lang}) ---\n";

	// 1) relation_models (only valid active model joins).
	$rm_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT rm.model_id, m.plugin_slug
			 FROM {$rm_table} rm
			 INNER JOIN {$models_table} m ON rm.model_id = m.id
			 WHERE rm.relation_id = %d
			   AND m.status = 'active'",
			$relation_id
		),
		ARRAY_A
	);

	$relation_model_ids      = array();
	$relation_model_slug_map = array(); // slug => [model ids].
	foreach ( $rm_rows as $row ) {
		$mid = (int) ( $row['model_id'] ?? 0 );
		if ( $mid <= 0 ) {
			continue;
		}
		$slug = sanitize_key( (string) ( $row['plugin_slug'] ?? '' ) );
		$relation_model_ids[ $mid ] = true;
		if ( '' !== $slug ) {
			if ( ! isset( $relation_model_slug_map[ $slug ] ) ) {
				$relation_model_slug_map[ $slug ] = array();
			}
			$relation_model_slug_map[ $slug ][] = $mid;
		}
	}
	$relation_model_ids = array_map( 'intval', array_keys( $relation_model_ids ) );

	// 2) relation enabled object configs.
	$cfg_rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_type
			 FROM {$ptc_table}
			 WHERE relation_id = %d
			   AND enabled = 1",
			$relation_id
		)
	);
	$cfg_objects = array();
	foreach ( (array) $cfg_rows as $obj_name ) {
		$key = sanitize_key( (string) $obj_name );
		if ( '' !== $key ) {
			$cfg_objects[ $key ] = true;
		}
	}

	// 3) active rules loaded through bound models.
	$relation_rules = array();
	if ( ! empty( $relation_model_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $relation_model_ids ), '%d' ) );
		$sql          = "SELECT id, model_id, object_name, data_type, is_active, field_capabilities
			FROM {$rules_table}
			WHERE is_active = 1
			  AND model_id IN ({$placeholders})
			ORDER BY id ASC";
		$relation_rules = $wpdb->get_results(
			$wpdb->prepare( $sql, $relation_model_ids ),
			ARRAY_A
		);
	}

	// Prepare decoded caps and grouping caches.
	$rules_with_caps = array();
	foreach ( $relation_rules as $rule_row ) {
		$rule_row['caps'] = e2e_cov_decode_caps( $rule_row['field_capabilities'] ?? array() );
		$rules_with_caps[] = $rule_row;
	}

	// ---------------------------------------------------------------------
	// Plugin coverage matrix
	// ---------------------------------------------------------------------
	$plugin_entry = array(
		'relation_id'      => $relation_id,
		'target_site_type' => $target_type,
		'target_site_id'   => $target_id,
		'target_lang'      => $target_lang,
		'plugins'          => array(),
		'summary'          => array(
			'total'  => 0,
			'passed' => 0,
			'failed' => 0,
		),
	);

	foreach ( $plugin_requirements as $plugin_slug ) {
		$candidate_model_slugs = array( $plugin_slug );
		if ( 'wptsall' === $plugin_slug ) {
			$candidate_model_slugs[] = 'wordpress-blog';
		}

		$bound_model_ids = array();
		foreach ( $candidate_model_slugs as $candidate_slug ) {
			$candidate_slug = sanitize_key( (string) $candidate_slug );
			if ( '' === $candidate_slug || empty( $relation_model_slug_map[ $candidate_slug ] ) ) {
				continue;
			}
			foreach ( $relation_model_slug_map[ $candidate_slug ] as $mid ) {
				$bound_model_ids[ (int) $mid ] = true;
			}
		}
		$bound_model_ids = array_map( 'intval', array_keys( $bound_model_ids ) );

		$plugin_rules = array();
		if ( ! empty( $bound_model_ids ) ) {
			foreach ( $rules_with_caps as $rule_row ) {
				$model_id = (int) ( $rule_row['model_id'] ?? 0 );
				if ( in_array( $model_id, $bound_model_ids, true ) ) {
					$plugin_rules[] = $rule_row;
				}
			}
		}

		// Signature fallback for plugins that are often merged into core rules.
		$signature_rules = array();
		if ( in_array( $plugin_slug, array( 'wordpress-seo', 'advanced-custom-fields' ), true ) ) {
			foreach ( $rules_with_caps as $rule_row ) {
				if ( e2e_cov_caps_match_plugin_signature( $plugin_slug, (array) ( $rule_row['caps'] ?? array() ) ) ) {
					$signature_rules[] = $rule_row;
				}
			}
		}

		$model_bound_pass = ! empty( $bound_model_ids ) || ! empty( $signature_rules );

		$effective_rules = ! empty( $plugin_rules ) ? $plugin_rules : $signature_rules;
		$rule_exists_pass = ! empty( $effective_rules );

		$expected_objects = array();
		$objects_with_contract = array();
		foreach ( $effective_rules as $rule_row ) {
			$object_name = sanitize_key( (string) ( $rule_row['object_name'] ?? '' ) );
			if ( '' !== $object_name ) {
				$expected_objects[ $object_name ] = true;
			}

			$caps = (array) ( $rule_row['caps'] ?? array() );
			if ( e2e_cov_has_field_contract( $caps ) && '' !== $object_name ) {
				$objects_with_contract[ $object_name ] = true;
			}
		}

		$expected_object_names = array_keys( $expected_objects );
		sort( $expected_object_names );

		$missing_cfg_objects = array();
		foreach ( $expected_object_names as $obj ) {
			if ( empty( $cfg_objects[ $obj ] ) ) {
				$missing_cfg_objects[] = $obj;
			}
		}
		$object_coverage_pass = ! empty( $expected_object_names ) && empty( $missing_cfg_objects );

		$missing_contract_objects = array();
		foreach ( $expected_object_names as $obj ) {
			if ( empty( $objects_with_contract[ $obj ] ) ) {
				$missing_contract_objects[] = $obj;
			}
		}
		$field_contract_pass = ! empty( $expected_object_names ) && empty( $missing_contract_objects );

		$plugin_pass = $model_bound_pass && $rule_exists_pass && $object_coverage_pass && $field_contract_pass;

		$plugin_result = array(
			'pass' => $plugin_pass,
			'dimensions' => array(
				'model_bound' => array(
					'pass'   => $model_bound_pass,
					'detail' => ! empty( $bound_model_ids ) ? ( 'model_ids=' . implode( ',', $bound_model_ids ) ) : ( ! empty( $signature_rules ) ? 'signature-derived' : 'no bound model/signature' ),
				),
				'rule_exists' => array(
					'pass'   => $rule_exists_pass,
					'detail' => 'rules=' . count( $effective_rules ),
				),
				'object_coverage' => array(
					'pass'   => $object_coverage_pass,
					'detail' => empty( $missing_cfg_objects ) ? 'ok' : ( 'missing_cfg=' . implode( ',', $missing_cfg_objects ) ),
				),
				'field_contract' => array(
					'pass'   => $field_contract_pass,
					'detail' => empty( $missing_contract_objects ) ? 'ok' : ( 'missing_contract=' . implode( ',', $missing_contract_objects ) ),
				),
			),
			'expected_objects' => $expected_object_names,
			'missing_cfg_objects' => $missing_cfg_objects,
			'missing_contract_objects' => $missing_contract_objects,
		);

		$plugin_entry['plugins'][ $plugin_slug ] = $plugin_result;
		++$plugin_entry['summary']['total'];
		++$plugin_report['summary']['total_checks'];
		if ( $plugin_pass ) {
			++$plugin_entry['summary']['passed'];
			++$plugin_report['summary']['passed'];
		} else {
			++$plugin_entry['summary']['failed'];
			++$plugin_report['summary']['failed'];
		}

		e2e_check(
			"Plugin coverage #{$relation_id}: {$plugin_slug}",
			$plugin_pass,
			$plugin_pass ? 'pass' : 'failed',
			$checks,
			$passed,
			$failed
		);
	}

	$plugin_report['relations'][] = $plugin_entry;

	// ---------------------------------------------------------------------
	// Core coverage matrix
	// ---------------------------------------------------------------------
	$core_entry = array(
		'relation_id'      => $relation_id,
		'target_site_type' => $target_type,
		'target_site_id'   => $target_id,
		'target_lang'      => $target_lang,
		'objects'          => array(),
		'summary'          => array(
			'total'  => 0,
			'passed' => 0,
			'failed' => 0,
		),
	);

	$core_model_ids = array();
	foreach ( array( 'wordpress-blog', 'wptsall' ) as $core_slug ) {
		if ( empty( $relation_model_slug_map[ $core_slug ] ) ) {
			continue;
		}
		foreach ( $relation_model_slug_map[ $core_slug ] as $mid ) {
			$core_model_ids[ (int) $mid ] = true;
		}
	}
	$core_model_ids  = array_map( 'intval', array_keys( $core_model_ids ) );
	$core_model_bound = ! empty( $core_model_ids );

	foreach ( $core_objects as $core_object ) {
		$object_rules = array();
		$model_bound_pass = $core_model_bound;

		if ( 'attachment' === $core_object ) {
			foreach ( $rules_with_caps as $rule_row ) {
				$caps = (array) ( $rule_row['caps'] ?? array() );
				if ( e2e_cov_caps_has_media_ref( $caps ) ) {
					$object_rules[] = $rule_row;
				}
			}
			if ( ! $model_bound_pass ) {
				$model_bound_pass = ! empty( $object_rules );
			}
		} else {
			foreach ( $rules_with_caps as $rule_row ) {
				$object_name = sanitize_key( (string) ( $rule_row['object_name'] ?? '' ) );
				if ( $core_object === $object_name ) {
					$object_rules[] = $rule_row;
				}
			}
		}

		$rule_exists_pass = ! empty( $object_rules );

		if ( 'attachment' === $core_object ) {
			$rule_object_names = array();
			foreach ( $object_rules as $rule_row ) {
				$object_name = sanitize_key( (string) ( $rule_row['object_name'] ?? '' ) );
				if ( '' !== $object_name ) {
					$rule_object_names[ $object_name ] = true;
				}
			}
			$missing_cfg_objects = array();
			foreach ( array_keys( $rule_object_names ) as $obj ) {
				if ( empty( $cfg_objects[ $obj ] ) ) {
					$missing_cfg_objects[] = $obj;
				}
			}
			$object_coverage_pass = ! empty( $rule_object_names ) && empty( $missing_cfg_objects );
		} else {
			$missing_cfg_objects = array();
			$object_coverage_pass = ! empty( $cfg_objects[ $core_object ] );
			if ( ! $object_coverage_pass ) {
				$missing_cfg_objects[] = $core_object;
			}
		}

		$field_contract_pass = false;
		foreach ( $object_rules as $rule_row ) {
			$caps = (array) ( $rule_row['caps'] ?? array() );
			if ( 'attachment' === $core_object ) {
				// Attachment path requires media_ref field contract.
				if ( e2e_cov_caps_has_media_ref( $caps ) ) {
					$field_contract_pass = true;
					break;
				}
				continue;
			}
			if ( e2e_cov_has_field_contract( $caps ) ) {
				$field_contract_pass = true;
				break;
			}
		}

		$core_pass = $model_bound_pass && $rule_exists_pass && $object_coverage_pass && $field_contract_pass;

		$core_result = array(
			'pass' => $core_pass,
			'dimensions' => array(
				'model_bound' => array(
					'pass'   => $model_bound_pass,
					'detail' => $model_bound_pass ? ( 'core_model_ids=' . implode( ',', $core_model_ids ) ) : 'core model not bound',
				),
				'rule_exists' => array(
					'pass'   => $rule_exists_pass,
					'detail' => 'rules=' . count( $object_rules ),
				),
				'object_coverage' => array(
					'pass'   => $object_coverage_pass,
					'detail' => empty( $missing_cfg_objects ) ? 'ok' : ( 'missing_cfg=' . implode( ',', $missing_cfg_objects ) ),
				),
				'field_contract' => array(
					'pass'   => $field_contract_pass,
					'detail' => $field_contract_pass ? 'ok' : 'no field contract',
				),
			),
		);

		$core_entry['objects'][ $core_object ] = $core_result;
		++$core_entry['summary']['total'];
		++$core_report['summary']['total_checks'];
		if ( $core_pass ) {
			++$core_entry['summary']['passed'];
			++$core_report['summary']['passed'];
		} else {
			++$core_entry['summary']['failed'];
			++$core_report['summary']['failed'];
		}

		e2e_check(
			"Core coverage #{$relation_id}: {$core_object}",
			$core_pass,
			$core_pass ? 'pass' : 'failed',
			$checks,
			$passed,
			$failed
		);
	}

	$core_report['relations'][] = $core_entry;
}

e2e_cov_save_runtime_json( 'relation-plugin-coverage.json', $plugin_report );
e2e_cov_save_runtime_json( 'relation-core-coverage.json', $core_report );

echo "\nCoverage artifacts saved:\n";
echo "  - runtime/relation-plugin-coverage.json\n";
echo "  - runtime/relation-core-coverage.json\n";

e2e_print_results( $checks, $passed, $failed, $skipped );

if ( $failed > 0 ) {
	exit( 1 );
}
