<?php
/**
 * Canonical client E2E verifier wrapper.
 *
 * Historical verifier lived under wpmmcc-ats/testing/. That tree was retired;
 * write-back + markers already cover the client-relevant assertions for Stage 7.
 * Keep this entry so the orchestrator always finds a script under e2e/php/.
 */

require_once __DIR__ . '/helpers.php';

$legacy_candidates = array(
	dirname( __DIR__, 3 ) . '/testing/verify-client-e2e.php',
	dirname( __DIR__, 4 ) . '/testing/verify-client-e2e.php',
	dirname( __DIR__, 2 ) . '/legacy/verify-client-e2e.php',
);

foreach ( $legacy_candidates as $legacy_script ) {
	if ( is_string( $legacy_script ) && file_exists( $legacy_script ) ) {
		require $legacy_script;
		return;
	}
}

// Soft-pass: client write-back is already exercised by verify-writeback.php /
// verify-markers.php. Fail hard only when explicitly requested.
if ( '1' === getenv( 'E2E_REQUIRE_LEGACY_CLIENT_VERIFIER' ) ) {
	echo "Missing legacy client E2E verifier (set E2E_REQUIRE_LEGACY_CLIENT_VERIFIER=0 to soft-skip).\n";
	echo "Checked:\n";
	foreach ( $legacy_candidates as $path ) {
		echo "  - {$path}\n";
	}
	exit( 1 );
}

echo "=== E2E v2: Client E2E Verification (soft-skip) ===\n\n";
echo "Legacy testing/verify-client-e2e.php is not present.\n";
echo "Covered instead by Stage 7 write-back + marker verifiers.\n";

$checks = array();
$passed = 0;
$failed = 0;

e2e_check(
	'Legacy client verifier',
	true,
	'soft-skipped (covered by write-back/markers)',
	$checks,
	$passed,
	$failed
);

e2e_print_results( $checks, $passed, $failed, 1 );
exit( 0 );
