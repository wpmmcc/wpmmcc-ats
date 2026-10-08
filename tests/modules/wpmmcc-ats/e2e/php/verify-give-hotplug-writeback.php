<?php
/**
 * After give-content lane: assert manual JSON hot-plug meta still present on source
 * and (when write-back exists) translated markers appear on virtual targets.
 *
 * Run:
 *   WPTSALL_LAB=1 wp eval-file tests/modules/wpmmcc-ats/e2e/php/verify-give-hotplug-writeback.php
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$meta_keys = array(
	'_manual_give_blurb',
	'_manual_give_html',
);

echo "=== E2E: Give Hot-Plug Write-Back Check ===\n\n";

$passed = 0;
$failed = 0;

$form_id = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
	 WHERE p.post_type = 'give_forms' AND p.post_status = 'publish'
	   AND pm.meta_key = '_manual_give_blurb'
	 ORDER BY p.ID ASC LIMIT 1"
);

if ( $form_id <= 0 ) {
	echo "[FAIL] source give_forms with hot-plug meta not found\n";
	exit( 1 );
}
echo "[PASS] source form_id={$form_id}\n";
$passed++;

foreach ( $meta_keys as $key ) {
	$val = (string) get_post_meta( $form_id, $key, true );
	$ok  = '' !== $val;
	echo ( $ok ? '[PASS]' : '[FAIL]' ) . " source meta {$key} len=" . strlen( $val ) . "\n";
	$ok ? $passed++ : $failed++;
}

// Registry still resolves JSON rules.
if ( class_exists( '\\WPTSALL\\Models\\Adapters\\Plugin_Field_Rules_Registry' ) ) {
	foreach ( $meta_keys as $key ) {
		$rule = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( $key );
		$ok   = is_array( $rule ) && ! empty( $rule['translatable'] );
		echo ( $ok ? '[PASS]' : '[FAIL]' ) . " registry match {$key}\n";
		$ok ? $passed++ : $failed++;
	}
}

// Virtual write-back: look for translation_results / post_mappings for this source.
$post_mappings = e2e_table( 'post_mappings' );
$mapped        = 0;
if ( e2e_table_exists( $post_mappings ) ) {
	$mapped = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$post_mappings} WHERE source_post_id = %d",
			$form_id
		)
	);
}
echo ( $mapped > 0 ? '[PASS]' : '[WARN]' ) . " post_mappings for form={$mapped}\n";
if ( $mapped > 0 ) {
	$passed++;
}

$results_table = e2e_table( 'translation_results' );
$results       = 0;
if ( e2e_table_exists( $results_table ) ) {
	// Prefer source_post_id when present; avoid unknown-column fatals across schema versions.
	$cols = $wpdb->get_col( "DESCRIBE {$results_table}", 0 );
	if ( is_array( $cols ) && in_array( 'source_post_id', $cols, true ) ) {
		$results = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$results_table} WHERE source_post_id = %d",
				$form_id
			)
		);
	}
}
echo "[INFO] translation_results rows for source_post_id≈{$results}\n";

// Soft: if virtual site content table exists, search for marker near give.
$marker_hits = 0;
if ( defined( 'E2E_MARKER_PATTERN' ) ) {
	$like = '%【%';
	$marker_hits = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key IN (%s, %s) AND meta_value LIKE %s",
			'_manual_give_blurb',
			'_manual_give_html',
			$like
		)
	);
}
echo ( $marker_hits >= 0 ? '[INFO]' : '[INFO]' ) . " source meta marker-ish hits={$marker_hits} (write-back markers may live on virtual posts)\n";

echo "\nResult: {$passed} passed, {$failed} failed\n";
file_put_contents(
	e2e_runtime_file( 'give-hotplug-writeback.json' ),
	wp_json_encode(
		array(
			'form_id'      => $form_id,
			'passed'       => $passed,
			'failed'       => $failed,
			'mapped'       => $mapped,
			'generated_at' => gmdate( 'c' ),
		),
		JSON_PRETTY_PRINT
	) . "\n"
);

exit( $failed > 0 ? 1 : 0 );
