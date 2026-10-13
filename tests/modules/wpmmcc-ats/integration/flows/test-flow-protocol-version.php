<?php
/**
 * X-WPTSALL-Protocol-Version Negotiation Test
 *
 * Verifies that the wptsall_check_client_protocol_version() helper
 * correctly accepts the current protocol version (2) and rejects
 * unsupported or malformed values with a structured 400 response.
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

// Check 1: helper functions exist
check(
    'wptsall_get_supported_client_protocol_versions() exists',
    function_exists( 'wptsall_get_supported_client_protocol_versions' )
);
check(
    'wptsall_check_client_protocol_version() exists',
    function_exists( 'wptsall_check_client_protocol_version' )
);

// Check 2: supported versions list contains 2
$versions = wptsall_get_supported_client_protocol_versions();
check(
    'Supported versions include v2',
    in_array( 2, $versions, true ),
    'versions=' . json_encode( $versions )
);

// Check 3: missing header is rejected (§0.2 single truth: Protocol v2 is
// mandatory — 缺头 → 400; the old "backward compat" allowance was retired)
$req = new \WP_REST_Request( 'GET', '/wptsall/v2/abc/client/ping' );
$result = wptsall_check_client_protocol_version( $req );
$err_data = is_wp_error( $result ) ? $result->get_error_data() : null;
check(
    'Missing X-WPTSALL-Protocol-Version header is rejected (400, missing_protocol_version)',
    is_wp_error( $result )
        && is_array( $err_data )
        && (int) $err_data['status'] === 400
        && 'missing_protocol_version' === $result->get_error_code(),
    'is_wp_error=' . ( is_wp_error( $result ) ? '1' : '0' ) . ' code=' . ( is_wp_error( $result ) ? $result->get_error_code() : 'n/a' )
);

// Check 4: valid version 2 is allowed
$req2 = new \WP_REST_Request( 'GET', '/wptsall/v2/abc/client/ping' );
$req2->set_header( 'X-WPTSALL-Protocol-Version', '2' );
$result2 = wptsall_check_client_protocol_version( $req2 );
check(
    'X-WPTSALL-Protocol-Version: 2 is allowed',
    $result2 === true,
    'is_wp_error=' . ( is_wp_error( $result2 ) ? '1' : '0' )
);

// Check 5: unsupported version 99 returns WP_Error with status=400
$req3 = new \WP_REST_Request( 'GET', '/wptsall/v2/abc/client/ping' );
$req3->set_header( 'X-WPTSALL-Protocol-Version', '99' );
$result3 = wptsall_check_client_protocol_version( $req3 );
$err_data3 = is_wp_error( $result3 ) ? $result3->get_error_data() : null;
check(
    'X-WPTSALL-Protocol-Version: 99 is rejected with WP_Error',
    is_wp_error( $result3 ) && is_array( $err_data3 ) && (int) $err_data3['status'] === 400,
    'is_wp_error=' . ( is_wp_error( $result3 ) ? '1' : '0' ) . ' status=' . ( is_array( $err_data3 ) ? $err_data3['status'] : 'n/a' )
);

// Check 6: error data includes received and supported_versions
if ( is_wp_error( $result3 ) ) {
    $data = $result3->get_error_data();
    check(
        'Rejection error data includes received and supported_versions',
        is_array( $data ) && isset( $data['received'] ) && isset( $data['supported_versions'] ),
        'data=' . json_encode( $data )
    );
}

// Check 7: non-numeric version is rejected
$req4 = new \WP_REST_Request( 'GET', '/wptsall/v2/abc/client/ping' );
$req4->set_header( 'X-WPTSALL-Protocol-Version', 'abc' );
$result4 = wptsall_check_client_protocol_version( $req4 );
$err_data4 = is_wp_error( $result4 ) ? $result4->get_error_data() : null;
check(
    'Non-numeric X-WPTSALL-Protocol-Version is rejected',
    is_wp_error( $result4 ) && is_array( $err_data4 ) && (int) $err_data4['status'] === 400,
    'is_wp_error=' . ( is_wp_error( $result4 ) ? '1' : '0' )
);

// Check 8: empty string is treated as missing (rejected, 400)
$req5 = new \WP_REST_Request( 'GET', '/wptsall/v2/abc/client/ping' );
$req5->set_header( 'X-WPTSALL-Protocol-Version', '' );
$result5 = wptsall_check_client_protocol_version( $req5 );
$err_data5 = is_wp_error( $result5 ) ? $result5->get_error_data() : null;
check(
    'Empty X-WPTSALL-Protocol-Version is rejected (treated as missing, 400)',
    is_wp_error( $result5 )
        && is_array( $err_data5 )
        && (int) $err_data5['status'] === 400
        && 'missing_protocol_version' === $result5->get_error_code()
);

// Check 9: integration - both controllers call the check
$ctrl_data = '\\WPTSALL\\Tasks\\API\\Client_Data_REST_Controller';
$ctrl_tasks = '\\WPTSALL\\Tasks\\API\\Client_Tasks_REST_Controller';
$data_src = file_get_contents( WPTSALL_PATH . 'includes/tasks/api/class-client-data-rest-controller.php' );
$tasks_src = file_get_contents( WPTSALL_PATH . 'includes/tasks/api/class-client-tasks-rest-controller.php' );
check(
    'Client_Data_REST_Controller::check_client_permission() calls protocol check',
    strpos( $data_src, 'wptsall_check_client_protocol_version' ) !== false
);
check(
    'Client_Tasks_REST_Controller::check_client_permission() calls protocol check',
    strpos( $tasks_src, 'wptsall_check_client_protocol_version' ) !== false
);

echo "\n=== Protocol Version Negotiation Test ===\n";
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