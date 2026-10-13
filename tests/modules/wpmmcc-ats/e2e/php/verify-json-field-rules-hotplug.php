<?php
/**
 * Verify wptsall-field-rules.json hot-plug for a plugin without PHP adapter.
 *
 * Default target: give (no Elementor/Yoast-style PHP Field_Rules_Adapter).
 *
 * Run:
 *   E2E_HOTPLUG_PLUGIN=give wp eval-file tests/modules/wpmmcc-ats/e2e/php/verify-json-field-rules-hotplug.php
 */

require_once __DIR__ . '/helpers.php';

use WPTSALL\Models\Adapters\Adapter_Manifest;
use WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

$plugin_slug = getenv( 'E2E_HOTPLUG_PLUGIN' );
if ( ! is_string( $plugin_slug ) || '' === $plugin_slug ) {
	$plugin_slug = 'give';
}
$plugin_slug = sanitize_key( $plugin_slug );

$expected_keys = array(
	'_manual_give_blurb',
	'_manual_give_html',
);

echo "=== E2E: JSON Field Rules Hot-Plug Verification ===\n\n";
echo "Target plugin_slug: {$plugin_slug}\n";

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
$GLOBALS['checks'] = array();

/**
 * @param string $label Label.
 * @param bool   $ok    Pass?
 * @param string $detail Detail.
 * @return void
 */
function e2e_hotplug_check( string $label, bool $ok, string $detail = '' ): void {
	$checks = &$GLOBALS['checks'];
	if ( $ok ) {
		$GLOBALS['passed']++;
		echo "[PASS] {$label}" . ( '' !== $detail ? "  {$detail}" : '' ) . "\n";
	} else {
		$GLOBALS['failed']++;
		echo "[FAIL] {$label}" . ( '' !== $detail ? "  {$detail}" : '' ) . "\n";
	}
	$checks[] = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail );
}

$discovered = Adapter_Manifest::discover_json_manifest_files();
$match_entry = null;
foreach ( $discovered as $entry ) {
	if ( (string) ( $entry['plugin_slug'] ?? '' ) === $plugin_slug ) {
		$match_entry = $entry;
		break;
	}
}

e2e_hotplug_check(
	'JSON manifest discovered for plugin',
	is_array( $match_entry ),
	is_array( $match_entry ) ? (string) ( $match_entry['manifest_path'] ?? '' ) : 'not found'
);

if ( is_array( $match_entry ) ) {
	$rules = (array) ( $match_entry['field_rules'] ?? array() );
	foreach ( $expected_keys as $meta_key ) {
		e2e_hotplug_check(
			"manifest declares {$meta_key}",
			isset( $rules[ $meta_key ] ),
			isset( $rules[ $meta_key ]['content_format'] )
				? (string) $rules[ $meta_key ]['content_format']
				: 'missing'
		);
	}
}

$json_adapters = Plugin_Field_Rules_Registry::get_json_adapters();
$has_adapter   = false;
foreach ( $json_adapters as $adapter ) {
	if ( $adapter->get_plugin_slug() === $plugin_slug ) {
		$has_adapter = true;
		break;
	}
}
e2e_hotplug_check( 'Registry exposes JSON adapter for plugin', $has_adapter );

foreach ( $expected_keys as $meta_key ) {
	$matched = Plugin_Field_Rules_Registry::match_meta_key( $meta_key );
	$ok      = is_array( $matched )
		&& ! empty( $matched['translatable'] )
		&& ( 'manual_json' === (string) ( $matched['source'] ?? '' ) || true === (bool) ( $matched['translatable'] ?? false ) );
	e2e_hotplug_check(
		"match_meta_key resolves {$meta_key}",
		$ok,
		is_array( $matched ) ? wp_json_encode( $matched ) : 'null'
	);
}

$manifests = Adapter_Manifest::all();
e2e_hotplug_check(
	'Adapter_Manifest includes plugin',
	isset( $manifests[ $plugin_slug ] ),
	isset( $manifests[ $plugin_slug ] )
		? 'formats=' . implode( ',', (array) ( $manifests[ $plugin_slug ]['content_formats'] ?? array() ) )
		: 'missing'
);

// Optional: seed demo meta onto one give form so content axis can see it later.
global $wpdb;
$form_id = (int) $wpdb->get_var(
	"SELECT ID FROM {$wpdb->posts}
	 WHERE post_type = 'give_forms' AND post_status = 'publish'
	 ORDER BY ID ASC LIMIT 1"
);
if ( $form_id > 0 ) {
	$seed_meta = array(
		'_manual_give_blurb' => 'Manual JSON hot-plug blurb for Give form translation.',
		'_manual_give_html'  => '<p>Manual JSON <strong>rich HTML</strong> blurb.</p>',
	);
	foreach ( $seed_meta as $meta_key => $meta_value ) {
		if ( function_exists( 'give_update_meta' ) ) {
			give_update_meta( $form_id, $meta_key, $meta_value );
		} else {
			update_post_meta( $form_id, $meta_key, $meta_value );
		}
	}
	wp_cache_delete( $form_id, 'post_meta' );
	$persisted = function_exists( 'give_get_meta' )
		? (string) give_get_meta( $form_id, '_manual_give_blurb', true )
		: (string) get_post_meta( $form_id, '_manual_give_blurb', true );
	e2e_hotplug_check( 'Seeded demo meta on give_forms', '' !== $persisted, "post_id={$form_id}" );
} else {
	e2e_hotplug_check( 'Seeded demo meta on give_forms', false, 'no published give_forms' );
}

echo "\n----------------------------------------------------------------------\n";
$passed = (int) ( $GLOBALS['passed'] ?? 0 );
$failed = (int) ( $GLOBALS['failed'] ?? 0 );
$checks = (array) ( $GLOBALS['checks'] ?? array() );
echo "Result: {$passed} passed, {$failed} failed out of " . count( $checks ) . " checks\n";

$runtime = e2e_runtime_dir();
if ( ! is_dir( $runtime ) ) {
	wp_mkdir_p( $runtime );
}
file_put_contents(
	e2e_runtime_file( 'json-field-rules-hotplug.json' ),
	wp_json_encode(
		array(
			'plugin_slug'  => $plugin_slug,
			'passed'       => $passed,
			'failed'       => $failed,
			'checks'       => $checks,
			'form_id'      => $form_id ?? 0,
			'generated_at' => gmdate( 'c' ),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n"
);

exit( $failed > 0 ? 1 : 0 );
