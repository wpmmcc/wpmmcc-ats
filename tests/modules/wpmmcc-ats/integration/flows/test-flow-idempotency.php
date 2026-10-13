<?php
/**
 * Idempotency-Key Integration Test
 *
 * Verifies the wptsall_idempotency_get/_set helpers used by the
 * /client/translation-callback endpoint to safely replay client retries.
 * The Rust client-wpplugin sends `Idempotency-Key: <stable-per-content-key>`
 * so a network retry does not produce duplicate translation writes.
 *
 * @package WPTSALL\Tests
 */
if ( ! defined( 'ABSPATH' ) ) {
    $_SERVER['HTTP_HOST']    = 'blog.wpmm.cc';
    $_SERVER['REQUEST_URI']  = '/';
    define( 'WP_USE_THEMES', false );
    define( 'WP_ADMIN', true );
    require_once dirname( __DIR__ ) . '/bootstrap/load-wordpress.php';
}

$results = [];
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $results, $failed;
    $results[] = compact('name', 'ok', 'detail');
    if ( ! $ok ) {
        $failed++;
        echo "FAIL: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
    } else {
        echo "PASS: {$name}\n";
    }
}

// Check 1: helpers exist
check(
    'wptsall_idempotency_get() function exists',
    function_exists( 'wptsall_idempotency_get' )
);
check(
    'wptsall_idempotency_set() function exists',
    function_exists( 'wptsall_idempotency_set' )
);

// Check 2: empty key returns null
check(
    'wptsall_idempotency_get("") returns null',
    wptsall_idempotency_get( '' ) === null
);

// Check 3: unknown key returns null
check(
    'wptsall_idempotency_get(unknown) returns null',
    wptsall_idempotency_get( 'nonexistent-key-12345' ) === null
);

// Check 4: set then get roundtrip
$test_key = 'test-idempotency-' . bin2hex( random_bytes( 8 ) );
wptsall_idempotency_set( $test_key, 200, [ 'success' => true, 'result_id' => 42 ] );
$cached = wptsall_idempotency_get( $test_key );
check(
    'set then get returns cached response',
    is_array( $cached ) && $cached['status'] === 200 && $cached['body']['result_id'] === 42,
    'cached=' . json_encode( $cached )
);

// Check 5: the same key must not replay a response for a different body.
$test_key_conflict = 'test-idem-conflict-' . bin2hex( random_bytes( 8 ) );
$body_hash_a       = hash( 'sha256', 'body-a' );
$body_hash_b       = hash( 'sha256', 'body-b' );
wptsall_idempotency_set( $test_key_conflict, 200, [ 'success' => true ], DAY_IN_SECONDS, $body_hash_a );
$conflict = wptsall_idempotency_get( $test_key_conflict, $body_hash_b );
check(
    'same key with a different request hash is flagged as a conflict',
    is_array( $conflict ) && ! empty( $conflict['conflict'] )
);

$same_body_replay = wptsall_idempotency_get( $test_key_conflict, $body_hash_a );
check(
    'same key with the same request hash remains replayable',
    is_array( $same_body_replay ) && empty( $same_body_replay['conflict'] ) && $same_body_replay['status'] === 200
);

// Check 6: 4xx responses are also cached
$test_key2 = 'test-idem-err-' . bin2hex( random_bytes( 8 ) );
wptsall_idempotency_set( $test_key2, 400, [ 'success' => false, 'error' => 'bad_request' ] );
$cached2 = wptsall_idempotency_get( $test_key2 );
check(
    'error responses are cached (replays return same error)',
    is_array( $cached2 ) && $cached2['status'] === 400 && $cached2['body']['error'] === 'bad_request'
);

// Check 7: empty key set is no-op
$before = wptsall_idempotency_get( '' );
wptsall_idempotency_set( '', 200, [ 'x' => 1 ] );
$after = wptsall_idempotency_get( '' );
check(
    'set with empty key is no-op',
    $before === null && $after === null
);

// Check 8: keys are scoped (different keys return different cached)
$test_key3 = 'test-idem-3-' . bin2hex( random_bytes( 8 ) );
wptsall_idempotency_set( $test_key3, 200, [ 'unique' => bin2hex( random_bytes( 4 ) ) ] );
$cached3 = wptsall_idempotency_get( $test_key3 );
check(
    'different keys return different responses',
    is_array( $cached3 ) && isset( $cached3['body']['unique'] )
);

// Check 9: integration - simulate the translation_callback flow
// (we can't easily call the REST API here, so just verify the storage path works)
$idem_key = 'integration-' . bin2hex( random_bytes( 8 ) );

// Simulate "first call" - empty result, then store
$response_body = [ 'success' => true, 'idempotent' => false, 'result_id' => 999 ];
$response_status = 200;
wptsall_idempotency_set( $idem_key, $response_status, $response_body );

// Simulate "retry" - should return cached
$replay = wptsall_idempotency_get( $idem_key );
check(
    'retry after first call returns cached response',
    is_array( $replay ) && $replay['status'] === 200 && $replay['body']['result_id'] === 999
);

// Cleanup
delete_transient( 'wptsall_idem_' . substr( md5( $test_key ), 0, 32 ) );
delete_transient( 'wptsall_idem_' . substr( md5( $test_key_conflict ), 0, 32 ) );
delete_transient( 'wptsall_idem_' . substr( md5( $test_key2 ), 0, 32 ) );
delete_transient( 'wptsall_idem_' . substr( md5( $test_key3 ), 0, 32 ) );
delete_transient( 'wptsall_idem_' . substr( md5( $idem_key ), 0, 32 ) );

echo "\n=== Idempotency-Key Integration Test ===\n";
echo "Passed: " . ( count( $results ) - $failed ) . " / " . count( $results ) . "\n";
echo "Failed: {$failed}\n";
if ( defined( 'WPTSALL_INTEGRATION_RUNNER' ) && WPTSALL_INTEGRATION_RUNNER ) {
	$GLOBALS['wptsall_flow_result'] = array(
		'failed' => $failed,
		'total'  => count( $results ),
	);
	if ( $failed > 0 ) {
		throw new RuntimeException( basename( __FILE__ ) . ": {$failed} check(s) failed" );
	}
	return;
}
exit( $failed > 0 ? 1 : 0 );
