<?php
/**
 * E2E v2 ISS-16: snapshot release/stability flags.
 *
 * Produces runtime/iss16-release-flags.json for rollback and audit.
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/snapshot-release-flags.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Snapshot Release Flags (ISS-16) ===\n\n";

/**
 * Build compact single-line preview for CLI output.
 *
 * @param mixed $value Option value.
 * @return string
 */
function iss16_snapshot_preview( $value ) {
	if ( null === $value ) {
		return 'null';
	}
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( is_scalar( $value ) ) {
		return (string) $value;
	}

	$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $encoded ) ) {
		$encoded = '[unserializable]';
	}

	if ( strlen( $encoded ) > 180 ) {
		return substr( $encoded, 0, 177 ) . '...';
	}

	return $encoded;
}

$flag_names = array(
	'wptsall_ssot_read_source',
	'wptsall_require_protocol_v2',
	'wptsall_enable_translation_simulation',
	'wptsall_hooks_auto_corrections',
	'wptsall_translation_marker_mode',
	'wptsall_settings',
	'wptsall_security_settings',
);

$flags = array();
foreach ( $flag_names as $name ) {
	$option_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$name
		)
	);
	$exists    = null !== $option_id;
	$value     = $exists ? get_option( $name ) : null;

	$flags[ $name ] = array(
		'exists' => $exists,
		'value'  => $value,
		'type'   => gettype( $value ),
	);

	echo sprintf(
		"[%s] %-38s %s\n",
		$exists ? 'KEEP' : 'MISS',
		$name,
		iss16_snapshot_preview( $value )
	);
}

$ssot = function_exists( 'wptsall_get_ssot_read_source' )
	? wptsall_get_ssot_read_source()
	: (string) get_option( 'wptsall_ssot_read_source', 'model_objects' );

$rollout_profile = 'unknown';
if ( 'dual' === $ssot ) {
	$rollout_profile = 'canary';
} elseif ( 'model_objects' === $ssot ) {
	$rollout_profile = 'full_rollout';
} elseif ( 'scan_result' === $ssot ) {
	$rollout_profile = 'rollback_mode';
}

$runtime_payload = array(
	'version'         => 1,
	'generated_at'    => gmdate( 'c' ),
	'blog_id'         => get_current_blog_id(),
	'ssot_read_source'=> $ssot,
	'rollout_profile' => $rollout_profile,
	'flags'           => $flags,
);

$runtime_dir = e2e_runtime_dir();
if ( ! is_dir( $runtime_dir ) ) {
	mkdir( $runtime_dir, 0755, true );
}

$runtime_file = $runtime_dir . '/iss16-release-flags.json';
file_put_contents(
	$runtime_file,
	wp_json_encode( $runtime_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
);

echo "\nSnapshot file: {$runtime_file}\n";
echo "SSOT source: {$ssot}\n";
echo "Rollout profile: {$rollout_profile}\n";

