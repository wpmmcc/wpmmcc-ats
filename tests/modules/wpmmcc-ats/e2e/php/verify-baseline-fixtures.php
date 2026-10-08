<?php
/**
 * E2E v2 baseline fixture verification (ISS-00).
 *
 * Verifies the deterministic baseline produced by seed-baseline-fixtures.php:
 * - relation IDs are active
 * - fixture post/media exist
 * - manual fields exist in model_object_fields
 * - manual fields are present in translation_rules.field_capabilities
 * - language_pack templates/entries exist for virtual relation
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-baseline-fixtures.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Verify Baseline Fixtures ===\n\n";

$checks  = array();
$passed  = 0;
$failed  = 0;
$skipped = 0;

$runtime_file = e2e_runtime_file( 'baseline-fixtures.json' );
if ( ! file_exists( $runtime_file ) ) {
	echo "ERROR: baseline fixture file not found: {$runtime_file}\n";
	exit( 1 );
}

$payload = json_decode( file_get_contents( $runtime_file ), true );
if ( ! is_array( $payload ) ) {
	echo "ERROR: invalid JSON in baseline fixture file.\n";
	exit( 1 );
}

$relation_ids = e2e_relation_ids_only( (array) ( $payload['relation_ids'] ?? array() ) );
$post_id      = (int) ( $payload['fixture_post_id'] ?? 0 );
$media_id     = (int) ( $payload['fixture_media_id'] ?? 0 );
$media_map    = (array) ( $payload['fixture_media'] ?? array() );
$model_id     = (int) ( $payload['fixture_model_id'] ?? 0 );
$rule_id      = (int) ( $payload['fixture_rule_id'] ?? 0 );
$manual_keys  = array_values( (array) ( $payload['manual_fields'] ?? array() ) );

// ---------------------------------------------------------------------------
// Check 1: relation IDs active
// ---------------------------------------------------------------------------
echo "--- Check 1: Relation IDs ---\n";

$relations_table = e2e_table( 'site_relations' );
foreach ( $relation_ids as $type => $relation_id ) {
	$relation_id = (int) $relation_id;
	if ( $relation_id <= 0 ) {
		if ( 'wp' === $type && ! is_multisite() ) {
			echo "  Relation {$type}: skipped (single-site, no multisite target)\n";
			++$skipped;
			continue;
		}
		e2e_check(
			"Relation {$type}",
			false,
			'missing relation_id',
			$checks,
			$passed,
			$failed
		);
		continue;
	}

	$status = (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT status FROM {$relations_table} WHERE id = %d LIMIT 1",
			$relation_id
		)
	);

	e2e_check(
		"Relation {$type}",
		'active' === $status,
		'status=' . ( '' === $status ? 'not_found' : $status ) . ", id={$relation_id}",
		$checks,
		$passed,
		$failed
	);
}

// ---------------------------------------------------------------------------
// Check 2: fixture post + media
// ---------------------------------------------------------------------------
echo "\n--- Check 2: Fixture Post + Media ---\n";

$post = $post_id > 0 ? get_post( $post_id ) : null;
e2e_check(
	'Fixture post exists',
	( $post && 'post' === $post->post_type ),
	$post ? "id={$post_id}, status={$post->post_status}" : "id={$post_id}",
	$checks,
	$passed,
	$failed
);

$post_meta_flag = $post_id > 0 ? get_post_meta( $post_id, '_wptsall_e2e_fixture_post', true ) : '';
e2e_check(
	'Fixture post marker meta',
	'1' === (string) $post_meta_flag,
	'meta=' . var_export( $post_meta_flag, true ),
	$checks,
	$passed,
	$failed
);

$manual_text = $post_id > 0 ? (string) get_post_meta( $post_id, '_e2e_manual_text', true ) : '';
e2e_check(
	'Fixture manual text meta',
	'' !== $manual_text,
	'' !== $manual_text ? 'present' : 'missing',
	$checks,
	$passed,
	$failed
);

$media_ref = $post_id > 0 ? (int) get_post_meta( $post_id, '_e2e_media_ref_id', true ) : 0;
e2e_check(
	'Fixture media ref meta',
	$media_ref > 0,
	'media_ref=' . $media_ref,
	$checks,
	$passed,
	$failed
);

if ( empty( $media_map ) && $media_id > 0 ) {
	$media_map = array(
		'image' => array(
			'id' => $media_id,
		),
	);
}

$media_expect = array(
	'image'    => array(
		'meta_key'     => '_e2e_media_ref_image',
		'mime_prefix'  => 'image/',
	),
	'video'    => array(
		'meta_key'     => '_e2e_media_ref_video',
		'mime_prefix'  => 'video/',
	),
	'audio'    => array(
		'meta_key'     => '_e2e_media_ref_audio',
		'mime_prefix'  => 'audio/',
	),
	'document' => array(
		'meta_key'     => '_e2e_media_ref_document',
		'mime_prefix'  => 'application/',
	),
);

foreach ( $media_expect as $type => $expect ) {
	$current_id = (int) ( $media_map[ $type ]['id'] ?? 0 );
	$current    = $current_id > 0 ? get_post( $current_id ) : null;

	e2e_check(
		"Fixture media exists ({$type})",
		( $current && 'attachment' === $current->post_type ),
		$current ? "id={$current_id}" : "id={$current_id}",
		$checks,
		$passed,
		$failed
	);

	if ( $post && $current ) {
		$post_meta_ref = (int) get_post_meta( $post_id, (string) $expect['meta_key'], true );
		e2e_check(
			"Fixture post references {$type} media",
			$post_meta_ref === $current_id,
			"meta_ref={$post_meta_ref}, fixture_media_id={$current_id}",
			$checks,
			$passed,
			$failed
		);

		$current_mime = (string) get_post_mime_type( $current_id );
		e2e_check(
			"Fixture {$type} mime type",
			0 === strpos( $current_mime, (string) $expect['mime_prefix'] ),
			$current_mime,
			$checks,
			$passed,
			$failed
		);
	}
}

if ( $post ) {
	$image_id = (int) ( $media_map['image']['id'] ?? 0 );
	e2e_check(
		'Fixture post references fixture media',
		$media_ref === $image_id,
		"post_meta_media_ref={$media_ref}, fixture_media_id={$image_id}",
		$checks,
		$passed,
		$failed
	);
}

// ---------------------------------------------------------------------------
// Check 3: manual fields in model_object_fields + translation_rules
// ---------------------------------------------------------------------------
echo "\n--- Check 3: Manual Field Fixture ---\n";

$fields_table  = e2e_table( 'model_object_fields' );
$objects_table = e2e_table( 'model_objects' );
$rules_table   = e2e_table( 'translation_rules' );

if ( $model_id <= 0 || empty( $manual_keys ) ) {
	e2e_check(
		'Manual fixture metadata',
		false,
		'model_id/manual_keys missing in baseline file',
		$checks,
		$passed,
		$failed
	);
} else {
	$placeholders = implode( ',', array_fill( 0, count( $manual_keys ), '%s' ) );
	$args         = array_merge( array( $model_id ), $manual_keys );
	$sql          = "
		SELECT COUNT(*) FROM {$fields_table} f
		INNER JOIN {$objects_table} o ON f.object_id = o.id
		WHERE o.model_id = %d
		AND f.source = 'manual'
		AND f.field_key IN ({$placeholders})
	";
	$manual_count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );

	e2e_check(
		'Manual fields exist in model_object_fields',
		$manual_count >= count( $manual_keys ),
		"found={$manual_count}, expected>=" . count( $manual_keys ),
		$checks,
		$passed,
		$failed
	);

	$caps_json = (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT field_capabilities FROM {$rules_table} WHERE id = %d LIMIT 1",
			$rule_id
		)
	);
	$caps = json_decode( $caps_json, true );
	$caps = is_array( $caps ) ? $caps : array();

	foreach ( $manual_keys as $field_key ) {
		$in_rule = ! empty( $caps[ $field_key ] ) && is_array( $caps[ $field_key ] );
		e2e_check(
			"Rule contains {$field_key}",
			$in_rule,
			$in_rule ? 'present' : "rule_id={$rule_id}",
			$checks,
			$passed,
			$failed
		);
	}
}

// ---------------------------------------------------------------------------
// Check 4: language_pack fixture
// ---------------------------------------------------------------------------
echo "\n--- Check 4: language_pack Fixture ---\n";

$virtual_relation_id = (int) ( $payload['language_pack']['virtual_relation_id'] ?? ( $relation_ids['virtual'] ?? 0 ) );
$templates_table     = e2e_table( 'templates' );
$entries_table       = e2e_table( 'template_entries' );

if ( $virtual_relation_id <= 0 ) {
	e2e_check(
		'Virtual relation for language_pack',
		false,
		'missing virtual relation id',
		$checks,
		$passed,
		$failed
	);
} else {
	$template_total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$templates_table} WHERE relation_id = %d",
			$virtual_relation_id
		)
	);
	$entry_total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$entries_table} e
			 INNER JOIN {$templates_table} t ON e.template_id = t.id
			 WHERE t.relation_id = %d",
			$virtual_relation_id
		)
	);

	e2e_check(
		'language_pack templates exist',
		$template_total > 0,
		"templates={$template_total}",
		$checks,
		$passed,
		$failed
	);
	e2e_check(
		'language_pack entries exist',
		$entry_total > 0,
		"entries={$entry_total}",
		$checks,
		$passed,
		$failed
	);

	$entry_source_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.source_type, COUNT(*) AS cnt
			 FROM {$entries_table} e
			 INNER JOIN {$templates_table} t ON e.template_id = t.id
			 WHERE t.relation_id = %d
			 GROUP BY t.source_type
			 ORDER BY t.source_type ASC",
			$virtual_relation_id
		),
		ARRAY_A
	);

	if ( empty( $entry_source_rows ) ) {
		++$skipped;
	} else {
		$source_summary = array();
		foreach ( $entry_source_rows as $row ) {
			$source_summary[] = ( $row['source_type'] ?? 'unknown' ) . ':' . (int) ( $row['cnt'] ?? 0 );
		}
		e2e_check(
			'language_pack source types',
			true,
			implode( ', ', $source_summary ),
			$checks,
			$passed,
			$failed
		);
	}
}

e2e_print_results( $checks, $passed, $failed, $skipped );

if ( $failed > 0 ) {
	exit( 1 );
}
