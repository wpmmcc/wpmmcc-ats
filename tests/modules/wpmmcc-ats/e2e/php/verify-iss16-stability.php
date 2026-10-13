<?php
/**
 * E2E v2 ISS-16 release stabilization verification.
 *
 * Focus:
 * - ISS-15 baseline is green before release
 * - feature-flag snapshot exists (for rollback)
 * - rollout flags are coherent (SSOT/protocol/simulation/marker)
 * - rollback primitives are available
 * - sync_mode migration guard remains stable (new_only)
 * - release documentation + rollback script exist
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-iss16-stability.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: ISS-16 Release Stabilization Verification ===\n\n";

$checks  = array();
$passed  = 0;
$failed  = 0;
$skipped = 0;

/**
 * Record one ISS-16 check.
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
function iss16_record( $name, $status, $detail, &$checks, &$passed, &$failed, &$skipped ) {
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
 * Load JSON runtime file safely.
 *
 * @param string $file Runtime file path.
 * @return array|null
 */
function iss16_load_json_file( $file ) {
	if ( ! file_exists( $file ) ) {
		return null;
	}

	$data = json_decode( file_get_contents( $file ), true );
	return is_array( $data ) ? $data : null;
}

$runtime_dir = e2e_runtime_dir();

// ---------------------------------------------------------------------------
// 1) Baseline + ISS-15 prerequisite checks
// ---------------------------------------------------------------------------
echo "--- 1) Prerequisites ---\n";

$baseline_file = $runtime_dir . '/baseline-fixtures.json';
$baseline      = iss16_load_json_file( $baseline_file );
iss16_record(
	'Baseline fixture runtime present',
	is_array( $baseline ) ? 'pass' : 'fail',
	is_array( $baseline ) ? basename( $baseline_file ) . ' loaded' : 'missing baseline-fixtures.json',
	$checks,
	$passed,
	$failed,
	$skipped
);

$iss15_file = $runtime_dir . '/iss15-coverage.json';
$iss15      = iss16_load_json_file( $iss15_file );
if ( ! is_array( $iss15 ) ) {
	iss16_record(
		'ISS-15 runtime present',
		'fail',
		'missing iss15-coverage.json',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$iss15_failed = (int) ( $iss15['failed'] ?? 0 );
	$iss15_passed = (int) ( $iss15['passed'] ?? 0 );
	$iss15_skips  = (int) ( $iss15['skipped'] ?? 0 );
	iss16_record(
		'ISS-15 prerequisite health',
		( $iss15_failed === 0 ) ? 'pass' : 'fail',
		"passed={$iss15_passed}, failed={$iss15_failed}, skipped={$iss15_skips}",
		$checks,
		$passed,
		$failed,
		$skipped
	);
}

// ---------------------------------------------------------------------------
// 2) Release-flag snapshot + SSOT rollout profile
// ---------------------------------------------------------------------------
echo "\n--- 2) Feature Flags / Rollout ---\n";

$snapshot_file = $runtime_dir . '/iss16-release-flags.json';
$snapshot      = iss16_load_json_file( $snapshot_file );

$required_flags = array(
	'wptsall_ssot_read_source',
	'wptsall_require_protocol_v2',
	'wptsall_enable_translation_simulation',
	'wptsall_hooks_auto_corrections',
	'wptsall_translation_marker_mode',
);

if ( ! is_array( $snapshot ) || empty( $snapshot['flags'] ) || ! is_array( $snapshot['flags'] ) ) {
	iss16_record(
		'Release flag snapshot exists',
		'fail',
		'missing or invalid iss16-release-flags.json',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$missing = array();
	foreach ( $required_flags as $flag_name ) {
		if ( ! array_key_exists( $flag_name, $snapshot['flags'] ) ) {
			$missing[] = $flag_name;
		}
	}

	iss16_record(
		'Release flag snapshot exists',
		empty( $missing ) ? 'pass' : 'fail',
		empty( $missing ) ? 'all required flags captured' : 'missing: ' . implode( ', ', $missing ),
		$checks,
		$passed,
		$failed,
		$skipped
	);
}

$ssot   = function_exists( 'wptsall_get_ssot_read_source' )
	? wptsall_get_ssot_read_source()
	: (string) get_option( 'wptsall_ssot_read_source', 'model_objects' );
$valid  = array( 'scan_result', 'model_objects', 'dual' );
$profile = 'unknown';
if ( 'dual' === $ssot ) {
	$profile = 'canary';
} elseif ( 'model_objects' === $ssot ) {
	$profile = 'full_rollout';
} elseif ( 'scan_result' === $ssot ) {
	$profile = 'rollback_mode';
}

iss16_record(
	'SSOT read-source validity',
	in_array( $ssot, $valid, true ) ? 'pass' : 'fail',
	"ssot={$ssot}, profile={$profile}",
	$checks,
	$passed,
	$failed,
	$skipped
);

$option_require_v2   = (bool) get_option( 'wptsall_require_protocol_v2', false );
$security_settings   = get_option( 'wptsall_security_settings', array() );
$security_require_v2 = is_array( $security_settings ) && ! empty( $security_settings['require_protocol_v2'] );

iss16_record(
	'Protocol-v2 gate coherence',
	$option_require_v2 === $security_require_v2 ? 'pass' : 'fail',
	'option=' . ( $option_require_v2 ? 'true' : 'false' ) . ', settings.require_protocol_v2=' . ( $security_require_v2 ? 'true' : 'false' ),
	$checks,
	$passed,
	$failed,
	$skipped
);

// ---------------------------------------------------------------------------
// 3) Production-safety flags
// ---------------------------------------------------------------------------
echo "\n--- 3) Production Safety ---\n";

$sim_constant = defined( 'WPTSALL_ENABLE_TRANSLATION_SIMULATION' ) ? (bool) WPTSALL_ENABLE_TRANSLATION_SIMULATION : false;
$sim_option   = (bool) get_option( 'wptsall_enable_translation_simulation', false );
$sim_service  = null;
if ( class_exists( '\WPTSALL\Tasks\Services\Translation_Simulation_Service' ) ) {
	$sim_service = \WPTSALL\Tasks\Services\Translation_Simulation_Service::is_enabled();
}
$sim_enabled = $sim_constant || $sim_option || ( true === $sim_service );

iss16_record(
	'Translation simulation disabled',
	$sim_enabled ? 'fail' : 'pass',
	'constant=' . ( $sim_constant ? 'true' : 'false' )
	. ', option=' . ( $sim_option ? 'true' : 'false' )
	. ', service=' . ( null === $sim_service ? 'n/a' : ( $sim_service ? 'true' : 'false' ) ),
	$checks,
	$passed,
	$failed,
	$skipped
);

$marker_mode  = (string) get_option( 'wptsall_translation_marker_mode', 'unified' );
$valid_markers = array( 'unified', 'legacy_prefix', 'none' );
$marker_ok    = in_array( $marker_mode, $valid_markers, true ) && 'legacy_prefix' !== $marker_mode;

iss16_record(
	'Marker mode release-safe',
	$marker_ok ? 'pass' : 'fail',
	'mode=' . $marker_mode . ' (expected unified|none)',
	$checks,
	$passed,
	$failed,
	$skipped
);

// ---------------------------------------------------------------------------
// 4) Rollback primitives + migration guards
// ---------------------------------------------------------------------------
echo "\n--- 4) Rollback / Migration Guards ---\n";

$rollback_func   = function_exists( 'wptsall_get_ssot_read_source' );
$rollback_method = class_exists( '\WPTSALL\Models\Services\Translation_Rule_Service' )
	&& method_exists( '\WPTSALL\Models\Services\Translation_Rule_Service', 'recreate_auto_rules' );

iss16_record(
	'Rollback primitives available',
	( $rollback_func && $rollback_method ) ? 'pass' : 'fail',
	'get_ssot=' . ( $rollback_func ? 'yes' : 'no' ) . ', recreate_auto_rules=' . ( $rollback_method ? 'yes' : 'no' ),
	$checks,
	$passed,
	$failed,
	$skipped
);

$cfg_table = e2e_table( 'relation_post_type_configs' );
if ( ! e2e_table_exists( $cfg_table ) ) {
	iss16_record(
		'sync_mode migration guard',
		'skip',
		'relation_post_type_configs table missing',
		$checks,
		$passed,
		$failed,
		$skipped
	);
} else {
	$has_sync_mode = (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$cfg_table} LIKE 'sync_mode'" );
	if ( ! $has_sync_mode ) {
		iss16_record(
			'sync_mode migration guard',
			'skip',
			'sync_mode column missing',
			$checks,
			$passed,
			$failed,
			$skipped
		);
	} else {
		$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cfg_table}" );
		$bad_rows   = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$cfg_table}
			 WHERE COALESCE(sync_mode, '') NOT IN ('', 'new_only')"
		);
		iss16_record(
			'sync_mode migration guard',
			$bad_rows === 0 ? 'pass' : 'fail',
			"rows={$total_rows}, non_new_only={$bad_rows}",
			$checks,
			$passed,
			$failed,
			$skipped
		);
	}
}

// ---------------------------------------------------------------------------
// 5) Release assets
// ---------------------------------------------------------------------------
echo "\n--- 5) Release Assets ---\n";

$doc_file       = dirname( __DIR__ ) . '/ISS-16-STABILIZATION.md';
$rollback_shell = dirname( __DIR__ ) . '/release-rollback.sh';

iss16_record(
	'ISS-16 runbook present',
	file_exists( $doc_file ) ? 'pass' : 'fail',
	file_exists( $doc_file ) ? basename( $doc_file ) : 'missing ISS-16-STABILIZATION.md',
	$checks,
	$passed,
	$failed,
	$skipped
);

iss16_record(
	'Rollback script present',
	file_exists( $rollback_shell ) ? 'pass' : 'fail',
	file_exists( $rollback_shell ) ? basename( $rollback_shell ) : 'missing release-rollback.sh',
	$checks,
	$passed,
	$failed,
	$skipped
);

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
	'version'          => 1,
	'generated_at'     => gmdate( 'c' ),
	'ssot_read_source' => $ssot,
	'rollout_profile'  => $profile,
	'passed'           => $passed,
	'failed'           => $failed,
	'skipped'          => $skipped,
	'checks'           => $checks,
);

$runtime_file = $runtime_dir . '/iss16-stability.json';
file_put_contents(
	$runtime_file,
	wp_json_encode( $runtime_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
);
echo "Runtime report: {$runtime_file}\n";

if ( $failed > 0 ) {
	exit( 1 );
}

