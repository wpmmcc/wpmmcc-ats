<?php
/**
 * E2E v2 ISS-15 coverage verification.
 *
 * Focus:
 * - manual field write-back chain
 * - hook dual-plane governance + relation-update listeners
 * - media mappings + attachment(inherit) chain
 * - non-virtual term meta write-back
 * - REST/headless consistency checks
 * - mixed field-format evidence from callback protocol payloads
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-iss15-coverage.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: ISS-15 Coverage Verification ===\n\n";

$checks  = array();
$passed  = 0;
$failed  = 0;
$skipped = 0;

/**
 * Record one ISS-15 check.
 *
 * @param string $name   Check name.
 * @param string $status pass|fail|skip
 * @param string $detail Detail text.
 * @param array  $checks Output checks list.
 * @param int    $passed Passed count.
 * @param int    $failed Failed count.
 * @param int    $skipped Skipped count.
 * @return void
 */
function iss15_record( $name, $status, $detail, &$checks, &$passed, &$failed, &$skipped ) {
	$status = in_array( $status, array( 'pass', 'fail', 'skip' ), true ) ? $status : 'fail';
	$checks[] = array(
		'name'   => $name,
		'status' => $status,
		'detail' => $detail,
	);

	if ( 'pass' === $status ) {
		++$passed;
	} elseif ( 'skip' === $status ) {
		++$skipped;
	} else {
		++$failed;
	}
}

/**
 * Marker detection helper.
 *
 * @param mixed $value Text value.
 * @return bool
 */
function iss15_has_marker( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}
	return preg_match( E2E_MARKER_PATTERN, $value ) === 1
		|| ( false !== strpos( $value, '【' ) && false !== strpos( $value, '】' ) );
}

/**
 * Parse site id string (target_site_id) to numeric blog id.
 *
 * @param mixed $target_site_id Site id value.
 * @return int
 */
function iss15_parse_blog_id( $target_site_id ) {
	$raw = trim( (string) $target_site_id );
	return ctype_digit( $raw ) ? (int) $raw : 0;
}

/**
 * Evaluate REST/headless consistency for one post on current blog context.
 *
 * @param int    $post_id Post ID.
 * @param string $detail  Output detail.
 * @return bool
 */
function iss15_check_rest_consistency_for_post( $post_id, &$detail ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		$detail = 'skip: post not found';
		return false;
	}

	$post_type     = (string) $post->post_type;
	$post_type_obj = get_post_type_object( $post_type );
	if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) || empty( $post_type_obj->rest_base ) ) {
		$detail = 'skip: post type not exposed via REST (' . $post_type . ')';
		return false;
	}

	$rest_ns   = ! empty( $post_type_obj->rest_namespace ) ? (string) $post_type_obj->rest_namespace : 'wp/v2';
	$rest_base = (string) $post_type_obj->rest_base;
	$route     = '/' . trim( $rest_ns, '/' ) . '/' . trim( $rest_base, '/' ) . '/' . (int) $post_id;

	$rest_url = add_query_arg( 'context', 'view', rest_url( ltrim( $route, '/' ) ) );
	$response = wp_remote_get(
		$rest_url,
		array(
			'timeout'    => 10,
			'redirection'=> 3,
			'sslverify'  => false,
		)
	);

	if ( is_wp_error( $response ) ) {
		$detail = 'skip: rest request error=' . $response->get_error_code();
		return false;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $status ) {
		$detail = 'rest status=' . $status;
		return false;
	}

	$body = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		$detail = 'skip: rest payload invalid';
		return false;
	}

	$rest_title   = (string) ( $data['title']['rendered'] ?? '' );
	$rest_content = (string) ( $data['content']['rendered'] ?? '' );

	$db_title   = (string) $post->post_title;
	$db_content = (string) $post->post_content;

	$rest_title_plain = wp_strip_all_tags( html_entity_decode( $rest_title, ENT_QUOTES ) );
	$db_has_marker    = iss15_has_marker( $db_title . "\n" . $db_content );
	$rest_has_marker  = iss15_has_marker( $rest_title . "\n" . $rest_content );

	$title_match = ( '' === $db_title )
		|| ( false !== strpos( $rest_title_plain, $db_title ) )
		|| ( false !== strpos( $db_title, $rest_title_plain ) );

	$detail = sprintf(
		'title_match=%s, marker_consistent=%s, status=%d',
		$title_match ? 'yes' : 'no',
		( $db_has_marker === $rest_has_marker ) ? 'yes' : 'no',
		$status
	);

	return $title_match && ( $db_has_marker === $rest_has_marker );
}

$baseline_file = e2e_runtime_file( 'baseline-fixtures.json' );
$baseline      = array();
if ( file_exists( $baseline_file ) ) {
	$baseline = json_decode( file_get_contents( $baseline_file ), true );
}
if ( ! is_array( $baseline ) || empty( $baseline ) ) {
	iss15_record(
		'Baseline fixtures runtime',
		'fail',
		'Missing or invalid runtime/baseline-fixtures.json',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	iss15_record(
		'Baseline fixtures runtime',
		'pass',
		'baseline-fixtures.json loaded',
		$checks,
		$passed,
		$failed,
		$skipped
	);
}

$fixture_post_id  = (int) ( $baseline['fixture_post_id'] ?? 0 );
$fixture_media_id = (int) ( $baseline['fixture_media_id'] ?? 0 );
$relation_ids     = (array) ( $baseline['relation_ids'] ?? e2e_load_relation_ids() );

$tasks_table        = e2e_table( 'tasks' );
$rules_table        = e2e_table( 'translation_rules' );
$post_mappings      = e2e_table( 'post_mappings' );
$media_mappings     = e2e_table( 'media_mappings' );
$term_mappings      = e2e_table( 'term_mappings' );

// ---------------------------------------------------------------------------
// 1) Manual field chain (add -> sync -> callback -> write-back)
// ---------------------------------------------------------------------------
echo "--- 1) Manual field chain ---\n";

	if ( $fixture_post_id <= 0 || ! e2e_table_exists( $post_mappings ) ) {
		iss15_record(
			'Manual field write-back chain',
			'skip',
			'fixture_post_id or post_mappings unavailable',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT target_post_id, target_site_id FROM {$post_mappings} WHERE source_post_id = %d ORDER BY id DESC LIMIT 100",
			$fixture_post_id
		),
		ARRAY_A
	);

	$mapped_posts        = 0;
	$manual_marker_hits  = 0;
	$manual_mediaid_hits = 0;

	foreach ( (array) $rows as $row ) {
		$target_post_id = (int) ( $row['target_post_id'] ?? 0 );
		if ( $target_post_id <= 0 ) {
			continue;
		}

		$target_blog = iss15_parse_blog_id( $row['target_site_id'] ?? '' );
		$switched    = false;
		if ( $target_blog > 0 && is_multisite() && $target_blog !== get_current_blog_id() && get_blog_details( $target_blog ) ) {
			switch_to_blog( $target_blog );
			$switched = true;
		}

		$post = get_post( $target_post_id );
		if ( $post ) {
			++$mapped_posts;
			$manual_text = (string) get_post_meta( $target_post_id, '_e2e_manual_text', true );
			$manual_ref  = (int) get_post_meta( $target_post_id, '_e2e_media_ref_id', true );
			if ( '' !== $manual_text && iss15_has_marker( $manual_text ) ) {
				++$manual_marker_hits;
			}
			if ( $manual_ref > 0 ) {
				++$manual_mediaid_hits;
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	if ( $mapped_posts <= 0 ) {
		iss15_record(
			'Manual field mapped targets',
			'skip',
			'fixture source post not translated in current dataset',
			$checks,
			$passed,
			$failed,
			$skipped
		);
		iss15_record(
			'Manual text translated marker',
			'skip',
			'no mapped fixture targets to inspect',
			$checks,
			$passed,
			$failed,
			$skipped
		);
		iss15_record(
			'Manual media ref write-back',
			'skip',
			'no mapped fixture targets to inspect',
			$checks,
			$passed,
			$failed,
			$skipped
		);
	} else {
		iss15_record(
			'Manual field mapped targets',
			'pass',
			"mapped_posts={$mapped_posts}",
			$checks,
			$passed,
			$failed,
			$skipped
		);
		iss15_record(
			'Manual text translated marker',
			$manual_marker_hits > 0 ? 'pass' : 'fail',
			"marker_hits={$manual_marker_hits}",
			$checks,
			$passed,
			$failed,
			$skipped
		);
		iss15_record(
			'Manual media ref write-back',
			$manual_mediaid_hits > 0 ? 'pass' : 'fail',
			"media_ref_hits={$manual_mediaid_hits}",
			$checks,
			$passed,
			$failed,
			$skipped
		);
	}
}

// ---------------------------------------------------------------------------
// 2) language_pack subtype split (plugin/theme/config)
// ---------------------------------------------------------------------------
echo "--- 2) language_pack subtype split ---\n";

if ( ! e2e_table_exists( $tasks_table ) ) {
	iss15_record(
		'language_pack subtype split',
		'skip',
		'tasks table missing',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$lp_rows = $wpdb->get_results(
		"SELECT subtype, COUNT(*) AS total,
		 SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
		 FROM {$tasks_table}
		 WHERE object_type = 'language_pack'
		 GROUP BY subtype",
		ARRAY_A
	);

	$lp_map = array();
	foreach ( (array) $lp_rows as $row ) {
		$subtype            = (string) ( $row['subtype'] ?? '' );
		$lp_map[ $subtype ] = array(
			'total'     => (int) ( $row['total'] ?? 0 ),
			'completed' => (int) ( $row['completed'] ?? 0 ),
		);
	}

		// Backward-compatible subtype mapping:
		// old: plugin_i18n/theme_i18n/config_i18n
		// new: plugin/theme/config
		$plugin_total = (int) ( $lp_map['plugin_i18n']['total'] ?? 0 )
			+ (int) ( $lp_map['plugin']['total'] ?? 0 );
		$theme_total  = (int) ( $lp_map['theme_i18n']['total'] ?? 0 )
			+ (int) ( $lp_map['theme']['total'] ?? 0 );
		$config_total = (int) ( $lp_map['config_i18n']['total'] ?? 0 )
			+ (int) ( $lp_map['config']['total'] ?? 0 );
		$config_completed = (int) ( $lp_map['config_i18n']['completed'] ?? 0 )
			+ (int) ( $lp_map['config']['completed'] ?? 0 );

	$covered_sources = 0;
	foreach ( array( $plugin_total, $theme_total, $config_total ) as $source_total ) {
		if ( $source_total > 0 ) {
			++$covered_sources;
		}
	}
	$source_detail = "plugin_i18n={$plugin_total}, theme_i18n={$theme_total}, config_i18n={$config_total}";

	if ( $covered_sources < 2 ) {
		$baseline_sources = (array) ( $baseline['language_pack']['entries_by_source'] ?? array() );
		foreach ( array( 'plugin', 'theme', 'config' ) as $source_key ) {
			if ( (int) ( $baseline_sources[ $source_key ] ?? 0 ) > 0 ) {
				++$covered_sources;
			}
		}
		if ( ! empty( $baseline_sources ) ) {
			$source_detail .= ', baseline_sources=' . wp_json_encode( $baseline_sources );
		}
	}

	iss15_record(
		'language_pack source split',
		( $covered_sources >= 2 )
			? 'pass'
			: ( ( getenv( 'WPTSALL_LAB' ) === '1' ) ? 'skip' : 'fail' ),
		$source_detail . ( ( getenv( 'WPTSALL_LAB' ) === '1' && $covered_sources < 2 )
			? '; lab soft-skip (content-matrix lane does not require language_pack source split)'
			: '' ),
		$checks,
		$passed,
		$failed,
		$skipped
	);

		if ( $config_total > 0 ) {
			iss15_record(
				'language_pack config_i18n processing',
				$config_completed > 0 ? 'pass' : 'fail',
				'config_i18n_total=' . (int) ( $lp_map['config_i18n']['total'] ?? 0 )
					. ', config_total=' . (int) ( $lp_map['config']['total'] ?? 0 )
					. ', completed=' . $config_completed,
				$checks,
				$passed,
				$failed,
			$skipped
		);
	} else {
		iss15_record(
			'language_pack config_i18n processing',
			'skip',
			'no config_i18n tasks in current fixture set',
			$checks,
			$passed,
			$failed,
			$skipped
		);
	}
}

// ---------------------------------------------------------------------------
// 3) Hook governance + relation update listeners
// ---------------------------------------------------------------------------
echo "--- 3) Hook governance ---\n";

$hook_manager_class = 'WPTSALL\\Hooks\\Hook_Manager';
$hook_api_ready     = class_exists( $hook_manager_class )
	&& method_exists( $hook_manager_class, 'register_dynamic_hooks' )
	&& method_exists( $hook_manager_class, 'generate_auto_hooks' )
	&& method_exists( $hook_manager_class, 'regenerate_hooks_for_relation' )
	&& function_exists( 'wptsall_register_dynamic_hooks' )
	&& function_exists( 'wptsall_generate_auto_hooks' );

if ( ! $hook_api_ready ) {
	iss15_record(
		'Hook dual-plane governance profile',
		'fail',
		'Hook_Manager class or current wrapper API unavailable',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	iss15_record(
		'Hook dual-plane governance profile',
		'pass',
		'current Hook_Manager API present; tableless auto-hook governance active',
		$checks,
		$passed,
		$failed,
		$skipped
	);

	$site_update_listener   = has_action( 'wptsall_relation_updated', array( $hook_manager_class, 'regenerate_hooks_for_relation' ) );
	$models_update_listener = has_action( 'wptsall_relation_models_updated', array( $hook_manager_class, 'regenerate_hooks_for_relation' ) );

	iss15_record(
		'Hook relation update listeners',
		( false !== $site_update_listener && false !== $models_update_listener ) ? 'pass' : 'fail',
		"relation_updated={$site_update_listener}, relation_models_updated={$models_update_listener}",
		$checks,
		$passed,
		$failed,
		$skipped
	);

	$test_relation_id = (int) ( $relation_ids['virtual'] ?? ( $relation_ids['wp'] ?? 0 ) );
	if ( $test_relation_id <= 0 ) {
		iss15_record(
			'Hook relation update immediate effect',
			'skip',
			'no relation id for action trigger',
			$checks,
			$passed,
			$failed,
			$skipped
		);
	} else {
		try {
			$ref  = new ReflectionClass( $hook_manager_class );
			$prop = $ref->getProperty( 'regenerated_relations' );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );

			do_action( 'wptsall_relation_updated', $test_relation_id );

			$regenerated = (array) $prop->getValue();
			$immediate   = isset( $regenerated[ $test_relation_id ] );

			iss15_record(
				'Hook relation update immediate effect',
				$immediate ? 'pass' : 'fail',
				$immediate ? 'relation marked regenerated in-request' : 'relation not marked in regenerated_relations',
				$checks,
				$passed,
				$failed,
				$skipped
			);
		} catch ( Throwable $e ) {
			iss15_record(
				'Hook relation update immediate effect',
				'fail',
				'reflection error: ' . $e->getMessage(),
				$checks,
				$passed,
				$failed,
				$skipped
			);
		}
	}
}

// ---------------------------------------------------------------------------
// 4) Mixed-format evidence (plain_text + json/serialized/rich_text)
// ---------------------------------------------------------------------------
echo "--- 4) Mixed-format evidence ---\n";

$mixed_ok     = false;
$mixed_detail = 'no mixed format evidence';

if ( e2e_table_exists( $tasks_table ) ) {
	$task_rows = $wpdb->get_results(
		"SELECT id, payload FROM {$tasks_table}
		 WHERE payload LIKE '%\"sync_source\"%'
		   AND payload LIKE '%\"translation_callback\"%'
		 ORDER BY id DESC
		 LIMIT 300",
		ARRAY_A
	);

	foreach ( (array) $task_rows as $task_row ) {
		$payload = json_decode( (string) ( $task_row['payload'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			continue;
		}
		$field_results = $payload['field_results'] ?? ( $payload['protocol']['field_results'] ?? array() );
		if ( ! is_array( $field_results ) || empty( $field_results ) ) {
			continue;
		}

		$formats = array();
		foreach ( $field_results as $fr ) {
			if ( ! is_array( $fr ) ) {
				continue;
			}
			$fmt = sanitize_key( (string) ( $fr['content_format'] ?? '' ) );
			if ( '' !== $fmt ) {
				$formats[ $fmt ] = true;
			}
		}

		$format_keys = array_keys( $formats );
		$has_plain   = in_array( 'plain_text', $format_keys, true );
		$has_struct  = in_array( 'json', $format_keys, true )
			|| in_array( 'serialized', $format_keys, true )
			|| in_array( 'rich_text', $format_keys, true )
			|| in_array( 'html', $format_keys, true );

		if ( $has_plain && $has_struct && count( $format_keys ) >= 2 ) {
			$mixed_ok     = true;
			$mixed_detail = 'task_id=' . (int) $task_row['id'] . ', formats=' . implode( ',', $format_keys );
			break;
		}
	}
}

if ( ! $mixed_ok && e2e_table_exists( $rules_table ) ) {
	$rule_rows = $wpdb->get_results(
		"SELECT id, field_capabilities FROM {$rules_table} ORDER BY id DESC LIMIT 300",
		ARRAY_A
	);

	foreach ( (array) $rule_rows as $rule_row ) {
		$caps = json_decode( (string) ( $rule_row['field_capabilities'] ?? '' ), true );
		if ( ! is_array( $caps ) ) {
			continue;
		}

		$formats = array();
		foreach ( $caps as $cap ) {
			if ( is_array( $cap ) ) {
				$fmt = sanitize_key( (string) ( $cap['content_format'] ?? '' ) );
				if ( '' !== $fmt ) {
					$formats[ $fmt ] = true;
				}
			}
		}

		$format_keys = array_keys( $formats );
		$has_plain   = in_array( 'plain_text', $format_keys, true );
		$has_struct  = in_array( 'json', $format_keys, true )
			|| in_array( 'serialized', $format_keys, true )
			|| in_array( 'rich_text', $format_keys, true )
			|| in_array( 'html', $format_keys, true );

		if ( $has_plain && $has_struct && count( $format_keys ) >= 2 ) {
			$mixed_ok     = true;
			$mixed_detail = 'rule_id=' . (int) $rule_row['id'] . ', formats=' . implode( ',', $format_keys );
			break;
		}
	}
}

iss15_record(
	'Mixed plain_text + structured format coverage',
	$mixed_ok ? 'pass' : 'skip',
	$mixed_detail,
	$checks,
	$passed,
	$failed,
	$skipped
);

// ---------------------------------------------------------------------------
// 5) Attachment inherit + media metadata write-back
// ---------------------------------------------------------------------------
echo "--- 5) Attachment inherit + metadata ---\n";

if ( ! e2e_table_exists( $media_mappings ) ) {
	iss15_record(
		'Attachment inherit discovery/write-back',
		'skip',
		'media_mappings table missing',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$inherit_rows = $wpdb->get_results(
		"SELECT mm.source_media_id, mm.target_media_id, mm.target_site_id
		 FROM {$media_mappings} mm
		 INNER JOIN {$wpdb->posts} src ON src.ID = mm.source_media_id
		 WHERE src.post_type = 'attachment' AND src.post_status = 'inherit'
		 ORDER BY mm.id DESC
		 LIMIT 120",
		ARRAY_A
	);

	$attachment_tasks = 0;
	if ( e2e_table_exists( $tasks_table ) ) {
		$attachment_tasks = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$tasks_table}
			 WHERE subtype = 'attachment'
			   AND payload LIKE '%\"sync_source\"%'
			   AND payload LIKE '%\"translation_callback\"%'"
		);
	}

	$inspected_media = 0;
	$metadata_hits   = 0;
	$marker_hits     = 0;

	foreach ( (array) $inherit_rows as $row ) {
		$target_media_id = (int) ( $row['target_media_id'] ?? 0 );
		if ( $target_media_id <= 0 ) {
			continue;
		}

		$target_blog = iss15_parse_blog_id( $row['target_site_id'] ?? '' );
		$switched    = false;
		if ( $target_blog > 0 && is_multisite() && $target_blog !== get_current_blog_id() && get_blog_details( $target_blog ) ) {
			switch_to_blog( $target_blog );
			$switched = true;
		}

		$attachment = get_post( $target_media_id );
		if ( $attachment && 'attachment' === $attachment->post_type ) {
			++$inspected_media;
			$alt  = (string) get_post_meta( $target_media_id, '_wp_attachment_image_alt', true );
			$cap  = (string) $attachment->post_excerpt;
			$desc = (string) $attachment->post_content;

			if ( '' !== trim( $alt . $cap . $desc ) ) {
				++$metadata_hits;
			}
			if ( iss15_has_marker( $alt ) || iss15_has_marker( $cap ) || iss15_has_marker( $desc ) ) {
				++$marker_hits;
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	iss15_record(
		'Attachment(inherit) discovered by callback tasks',
		$attachment_tasks > 0 ? 'pass' : 'skip',
		"attachment_sync_tasks={$attachment_tasks}",
		$checks,
		$passed,
		$failed,
		$skipped
	);
	iss15_record(
		'Attachment mappings from inherit sources',
		$inspected_media > 0 ? 'pass' : 'skip',
		"inspected_target_media={$inspected_media}",
		$checks,
		$passed,
		$failed,
		$skipped
	);
	if ( $inspected_media <= 0 ) {
		iss15_record(
			'Attachment alt/caption/description write-back',
			'skip',
			'no mapped attachment targets to inspect',
			$checks,
			$passed,
			$failed,
			$skipped
		);
	} else {
		iss15_record(
			'Attachment alt/caption/description write-back',
			$metadata_hits > 0 ? 'pass' : 'fail',
			"metadata_hits={$metadata_hits}, marker_hits={$marker_hits}",
			$checks,
			$passed,
			$failed,
			$skipped
		);
	}
}

// ---------------------------------------------------------------------------
// 6) media_mappings used for URL/ID replacement
// ---------------------------------------------------------------------------
echo "--- 6) media mapping URL/ID replacement ---\n";

if ( $fixture_post_id <= 0 || $fixture_media_id <= 0 || ! e2e_table_exists( $post_mappings ) || ! e2e_table_exists( $media_mappings ) ) {
	iss15_record(
		'Media URL/ID replacement usage',
		'skip',
		'fixture ids or mapping tables unavailable',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$media_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT target_site_id, target_media_id, source_file_url, target_file_url
			 FROM {$media_mappings}
			 WHERE source_media_id = %d
			 ORDER BY id DESC",
			$fixture_media_id
		),
		ARRAY_A
	);
	$post_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT target_post_id, target_site_id
			 FROM {$post_mappings}
			 WHERE source_post_id = %d
			 ORDER BY id DESC",
			$fixture_post_id
		),
		ARRAY_A
	);

	$media_by_target = array();
	foreach ( (array) $media_rows as $mr ) {
		$key = (string) ( $mr['target_site_id'] ?? '' );
		if ( '' === $key || isset( $media_by_target[ $key ] ) ) {
			continue;
		}
		$media_by_target[ $key ] = $mr;
	}

	$id_hits     = 0;
	$url_hits    = 0;
	$source_leak = 0;
	$checked     = 0;

	foreach ( (array) $post_rows as $pr ) {
		$target_site = (string) ( $pr['target_site_id'] ?? '' );
		$target_post = (int) ( $pr['target_post_id'] ?? 0 );
		if ( $target_post <= 0 || empty( $media_by_target[ $target_site ] ) ) {
			continue;
		}

		$map        = $media_by_target[ $target_site ];
		$target_mid = (int) ( $map['target_media_id'] ?? 0 );
		$source_url = (string) ( $map['source_file_url'] ?? '' );
		$target_url = (string) ( $map['target_file_url'] ?? '' );

		$target_blog = iss15_parse_blog_id( $target_site );
		$switched    = false;
		if ( $target_blog > 0 && is_multisite() && $target_blog !== get_current_blog_id() && get_blog_details( $target_blog ) ) {
			switch_to_blog( $target_blog );
			$switched = true;
		}

		$post = get_post( $target_post );
		if ( $post ) {
			++$checked;
			$meta_ref   = (int) get_post_meta( $target_post, '_e2e_media_ref_id', true );
			$thumb_id   = (int) get_post_meta( $target_post, '_thumbnail_id', true );
			$content    = (string) $post->post_content;

			if ( $target_mid > 0 && ( $meta_ref === $target_mid || $thumb_id === $target_mid ) ) {
				++$id_hits;
			}
			if ( '' !== $target_url && false !== strpos( $content, $target_url ) ) {
				++$url_hits;
			}
			if ( '' !== $source_url && false !== strpos( $content, $source_url ) ) {
				++$source_leak;
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	if ( $checked <= 0 ) {
		iss15_record(
			'Media ID replacement in translated targets',
			'skip',
			'no fixture mapped target posts with media mappings',
			$checks,
			$passed,
			$failed,
			$skipped
		);
		iss15_record(
			'Media URL replacement in translated content',
			'skip',
			'no fixture mapped target posts with media mappings',
			$checks,
			$passed,
			$failed,
			$skipped
		);
	} else {
		iss15_record(
			'Media ID replacement in translated targets',
			$id_hits > 0 ? 'pass' : 'fail',
			"checked={$checked}, id_hits={$id_hits}",
			$checks,
			$passed,
			$failed,
			$skipped
		);
		iss15_record(
			'Media URL replacement in translated content',
			( $url_hits > 0 && 0 === $source_leak ) ? 'pass' : 'fail',
			"checked={$checked}, url_hits={$url_hits}, source_url_leak={$source_leak}",
			$checks,
			$passed,
			$failed,
			$skipped
		);
	}
}

// ---------------------------------------------------------------------------
// 7) Non-virtual term meta write-back consistency
// ---------------------------------------------------------------------------
echo "--- 7) Term meta consistency ---\n";

if ( ! e2e_table_exists( $term_mappings ) ) {
	iss15_record(
		'Non-virtual term meta write-back',
		'skip',
		'term_mappings table missing',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$term_rows = $wpdb->get_results(
		"SELECT source_term_id, source_site_id, target_term_id, target_site_id
		 FROM {$term_mappings}
		 WHERE target_site_id REGEXP '^[0-9]+$'
		 ORDER BY id DESC
		 LIMIT 120",
		ARRAY_A
	);

	$rows_with_source_meta = 0;
	$mirrored_keys         = 0;
	$compared_keys         = 0;

	foreach ( (array) $term_rows as $row ) {
		$source_term_id = (int) ( $row['source_term_id'] ?? 0 );
		$source_site_id = (int) ( $row['source_site_id'] ?? 0 );
		$target_term_id = (int) ( $row['target_term_id'] ?? 0 );
		$target_site_id = (int) ( $row['target_site_id'] ?? 0 );

		if ( $source_term_id <= 0 || $target_term_id <= 0 || $target_site_id <= 0 ) {
			continue;
		}

		$source_switched = false;
		if ( is_multisite() && $source_site_id > 0 && $source_site_id !== get_current_blog_id() && get_blog_details( $source_site_id ) ) {
			switch_to_blog( $source_site_id );
			$source_switched = true;
		}

		$src_meta_all = (array) get_term_meta( $source_term_id );
		$scalar_keys  = array();
		foreach ( $src_meta_all as $meta_key => $meta_values ) {
			if ( str_starts_with( (string) $meta_key, '_' ) ) {
				continue;
			}
			$first = '';
			if ( is_array( $meta_values ) ) {
				$first = (string) ( $meta_values[0] ?? '' );
			} else {
				$first = (string) $meta_values;
			}
			if ( '' !== trim( $first ) ) {
				$scalar_keys[ (string) $meta_key ] = $first;
			}
		}

		if ( $source_switched ) {
			restore_current_blog();
		}

		if ( empty( $scalar_keys ) ) {
			continue;
		}

		++$rows_with_source_meta;

		$target_switched = false;
		if ( is_multisite() && $target_site_id !== get_current_blog_id() && get_blog_details( $target_site_id ) ) {
			switch_to_blog( $target_site_id );
			$target_switched = true;
		}

		foreach ( $scalar_keys as $meta_key => $src_val ) {
			$target_val = get_term_meta( $target_term_id, $meta_key, true );
			++$compared_keys;
			if ( '' !== trim( (string) $target_val ) ) {
				++$mirrored_keys;
			}
		}

		if ( $target_switched ) {
			restore_current_blog();
		}
	}

	if ( $rows_with_source_meta <= 0 ) {
		iss15_record(
			'Non-virtual term meta write-back',
			'skip',
			'no mapped terms with scalar source meta in current dataset',
			$checks,
			$passed,
			$failed,
			$skipped
		);
	} else {
		iss15_record(
			'Non-virtual term meta write-back',
			$mirrored_keys > 0 ? 'pass' : 'fail',
			"rows_with_source_meta={$rows_with_source_meta}, mirrored_keys={$mirrored_keys}/{$compared_keys}",
			$checks,
			$passed,
			$failed,
			$skipped
		);
	}
}

// ---------------------------------------------------------------------------
// 8) REST/headless consistency (WP target + virtual target)
// ---------------------------------------------------------------------------
echo "--- 8) REST/headless consistency ---\n";

$wp_target_status = 'skip';
$wp_target_detail = 'no mapped WP target post found';

if ( e2e_table_exists( $post_mappings ) ) {
	$wp_target_rows = $wpdb->get_results(
		"SELECT target_post_id, target_site_id, target_post_type
		 FROM {$post_mappings}
		 WHERE target_site_id REGEXP '^[0-9]+$'
		   AND target_post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
		 ORDER BY id DESC
		 LIMIT 60",
		ARRAY_A
	);

	foreach ( (array) $wp_target_rows as $row ) {
		$target_post_id = (int) ( $row['target_post_id'] ?? 0 );
		$target_blog_id = (int) ( $row['target_site_id'] ?? 0 );
		if ( $target_post_id <= 0 || $target_blog_id <= 0 ) {
			continue;
		}
		if ( is_multisite() && ! get_blog_details( $target_blog_id ) ) {
			continue;
		}

		$switched = false;
		if ( is_multisite() && $target_blog_id !== get_current_blog_id() ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		$detail = '';
		$ok     = iss15_check_rest_consistency_for_post( $target_post_id, $detail );
		$candidate_status = str_starts_with( $detail, 'skip:' ) ? 'skip' : ( $ok ? 'pass' : 'fail' );
		$wp_target_detail = "blog={$target_blog_id}, post={$target_post_id}, {$detail}";

		if ( $switched ) {
			restore_current_blog();
		}

		if ( 'skip' === $candidate_status ) {
			continue;
		}

		$wp_target_status = $candidate_status;
		break;
	}
}

iss15_record(
	'WP target REST/headless consistency',
	$wp_target_status,
	$wp_target_detail,
	$checks,
	$passed,
	$failed,
	$skipped
);

$virtual_probe = 'meta';
$virtual_post_id = (int) $wpdb->get_var(
	"SELECT pm1.post_id
	 FROM {$wpdb->postmeta} pm1
	 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
	 WHERE pm1.meta_key = '_wptsall_virtual_site_id'
	   AND pm2.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
	 ORDER BY pm1.post_id DESC
	 LIMIT 1"
);

if ( $virtual_post_id <= 0 && e2e_table_exists( $post_mappings ) ) {
	$virtual_rows = $wpdb->get_results(
		"SELECT target_post_id, target_site_id
		 FROM {$post_mappings}
		 WHERE target_site_id <> ''
		   AND target_site_id NOT REGEXP '^[0-9]+$'
		 ORDER BY id DESC
		 LIMIT 80",
		ARRAY_A
	);
	foreach ( (array) $virtual_rows as $virtual_row ) {
		$candidate_id = (int) ( $virtual_row['target_post_id'] ?? 0 );
		if ( $candidate_id <= 0 ) {
			continue;
		}
		$candidate = get_post( $candidate_id );
		if ( ! $candidate ) {
			continue;
		}
		$virtual_post_id = $candidate_id;
		$virtual_probe   = 'post_mappings(target_site_id=' . (string) ( $virtual_row['target_site_id'] ?? '' ) . ')';
		break;
	}
}

if ( $virtual_post_id <= 0 ) {
	iss15_record(
		'Virtual target REST/headless consistency',
		'skip',
		'no virtual translated post found via meta/post_mappings',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$virtual_detail = '';
	$virtual_ok     = iss15_check_rest_consistency_for_post( $virtual_post_id, $virtual_detail );
	$virtual_status = str_starts_with( $virtual_detail, 'skip:' ) ? 'skip' : ( $virtual_ok ? 'pass' : 'fail' );
	iss15_record(
		'Virtual target REST/headless consistency',
		$virtual_status,
		'post=' . $virtual_post_id . ', probe=' . $virtual_probe . ', ' . $virtual_detail,
		$checks,
		$passed,
		$failed,
		$skipped
	);
}

// ---------------------------------------------------------------------------
// Output + runtime report
// ---------------------------------------------------------------------------
echo "\n" . str_repeat( '-', 86 ) . "\n";
foreach ( $checks as $check ) {
	$icon = 'FAIL';
	if ( 'pass' === $check['status'] ) {
		$icon = 'PASS';
	} elseif ( 'skip' === $check['status'] ) {
		$icon = 'SKIP';
	}
	echo sprintf( "[%s] %-50s %s\n", $icon, $check['name'], $check['detail'] );
}
echo str_repeat( '-', 86 ) . "\n";
echo sprintf(
	"\nResult: %d passed, %d failed, %d skipped out of %d checks\n",
	$passed,
	$failed,
	$skipped,
	count( $checks )
);

$runtime_payload = array(
	'version'      => 1,
	'generated_at' => gmdate( 'c' ),
	'passed'       => $passed,
	'failed'       => $failed,
	'skipped'      => $skipped,
	'checks'       => $checks,
);
$runtime_dir = e2e_runtime_dir();
if ( ! is_dir( $runtime_dir ) ) {
	mkdir( $runtime_dir, 0755, true );
}
$runtime_file = $runtime_dir . '/iss15-coverage.json';
file_put_contents(
	$runtime_file,
	json_encode( $runtime_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
);
echo "Runtime report: {$runtime_file}\n";

if ( $failed > 0 ) {
	exit( 1 );
}
