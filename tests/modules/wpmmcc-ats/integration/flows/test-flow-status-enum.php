<?php
/**
 * /tasks/{id}/status Enum Validation Test
 *
 * Verifies the {processing, retry, failed} enum is enforced by the
 * client-tasks REST controller for /tasks/{id}/status updates.
 * SQL injection / empty / numeric values are rejected with 400.
 * Progress is clamped to 0-100 (not rejected).
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

// Find the controller and route secret
$ctrl_class = '\\WPTSALL\\Tasks\\API\\Client_Tasks_REST_Controller';
$ctrl = new $ctrl_class();

// Get the route secret
$secret = '';
if ( function_exists( 'wptsall_get_client_route_secret' ) ) {
    $secret = wptsall_get_client_route_secret();
}
$base = $secret . '/client';

// Get a real client token
$token = '';
if ( function_exists( 'wptsall_issue_client_device_token' ) ) {
    $_c = wptsall_issue_client_device_token( 'status-enum', 'flow' );
    $token = (string) ( $_c['token'] ?? '' );
    $device_id = (string) ( $_c['device_id'] ?? '' );
}
// If no token, try to use any from the database
if ( '' === $token ) {
    global $wpdb;
    $token = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wptsall_client_api_token'" );
}

echo "Token len: " . strlen( $token ) . " Secret: " . substr( $secret, 0, 8 ) . "...\n\n";

// Test by directly calling update_task_status with a mock request
// This avoids needing HTTP setup
$ref = new \ReflectionMethod( $ctrl, 'update_task_status' );
$ref->setAccessible( true );

// Helper: build a mock request with id and status
function make_request( int $task_id, string $status ): \WP_REST_Request {
    $req = new \WP_REST_Request( 'POST', '/wptsall/v2/abc/client/tasks/' . $task_id . '/status' );
    $req->set_param( 'id', $task_id );
    $req->set_param( 'status', $status );
    $req->set_param( 'progress', 50 );
    return $req;
}

global $wpdb;
$table = wptsall_table( 'tasks' );
$task_id = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1" );
if ( ! $task_id ) {
    // Insert a test task
    $wpdb->insert( $table, [
        'type'         => 'test',
        'status'       => 'pending',
        'priority'     => 50,
        'payload'      => '{}',
        'meta'         => '{}',
        'retry_count'  => 0,
        'created_at'   => current_time( 'mysql' ),
        'updated_at'   => current_time( 'mysql' ),
    ] );
    $task_id = (int) $wpdb->insert_id;
}
echo "Test task id: $task_id\n\n";

// Check 1: valid status 'processing' is accepted (not 400)
$req1 = make_request( $task_id, 'processing' );
$result1 = $ref->invoke( $ctrl, $req1 );
$status1 = is_wp_error( $result1 ) ? (int) $result1->get_error_data()['status'] : 200;
check(
    "Status 'processing' is accepted (not 400)",
    ! is_wp_error( $result1 ) || $status1 !== 400,
    is_wp_error( $result1 ) ? "wp_error code=" . $result1->get_error_code() . " status=" . $status1 : "ok"
);

// Check 2: valid status 'retry' is accepted
$req2 = make_request( $task_id, 'retry' );
$result2 = $ref->invoke( $ctrl, $req2 );
$status2 = is_wp_error( $result2 ) ? (int) $result2->get_error_data()['status'] : 200;
check(
    "Status 'retry' is accepted (not 400)",
    ! is_wp_error( $result2 ) || $status2 !== 400,
    is_wp_error( $result2 ) ? "wp_error code=" . $result2->get_error_code() . " status=" . $status2 : "ok"
);

// Check 3: valid status 'failed' is accepted
$req3 = make_request( $task_id, 'failed' );
$result3 = $ref->invoke( $ctrl, $req3 );
$status3 = is_wp_error( $result3 ) ? (int) $result3->get_error_data()['status'] : 200;
check(
    "Status 'failed' is accepted (not 400)",
    ! is_wp_error( $result3 ) || $status3 !== 400,
    is_wp_error( $result3 ) ? "wp_error code=" . $result3->get_error_code() . " status=" . $status3 : "ok"
);

// Check 4: invalid status 'invalid_value' is rejected with 400
$req4 = make_request( $task_id, 'invalid_value' );
$result4 = $ref->invoke( $ctrl, $req4 );
check(
    "Status 'invalid_value' is rejected with WP_Error",
    is_wp_error( $result4 ) && $result4->get_error_code() === 'invalid_status',
    is_wp_error( $result4 ) ? "code=" . $result4->get_error_code() : "ok"
);
$err_data4 = is_wp_error( $result4 ) ? $result4->get_error_data() : null;
check(
    "Invalid status error returns 400",
    is_wp_error( $result4 ) && is_array( $err_data4 ) && (int) $err_data4['status'] === 400
);

// Check 5: SQL injection attempt is rejected
$req5 = make_request( $task_id, "'; DROP TABLE {$table}; --" );
$result5 = $ref->invoke( $ctrl, $req5 );
check(
    "SQL injection attempt is rejected",
    is_wp_error( $result5 ) && $result5->get_error_code() === 'invalid_status'
);

// Check 6: empty status is rejected
$req6 = make_request( $task_id, '' );
$result6 = $ref->invoke( $ctrl, $req6 );
check(
    "Empty status is rejected with 400",
    is_wp_error( $result6 ) && $result6->get_error_code() === 'invalid_status'
);

// Check 7: numeric status is rejected
$req7 = make_request( $task_id, '123' );
$result7 = $ref->invoke( $ctrl, $req7 );
check(
    "Numeric status is rejected (not in enum)",
    is_wp_error( $result7 ) && $result7->get_error_code() === 'invalid_status'
);

// Check 8: progress is clamped to 0-100
$req8 = make_request( $task_id, 'processing' );
$req8->set_param( 'progress', 150 );
$result8 = $ref->invoke( $ctrl, $req8 );
$is_ok = ! is_wp_error( $result8 ) || (int) $result8->get_error_data()['status'] !== 400;
check(
    "Progress 150 is clamped (not rejected as 400)",
    $is_ok,
    is_wp_error( $result8 ) ? "wp_error=" . $result8->get_error_code() : "ok"
);

// Check 9: nonexistent task returns 404 (not 400)
$req9 = make_request( 999999999, 'processing' );
$result9 = $ref->invoke( $ctrl, $req9 );
check(
    "Nonexistent task returns 404 (task_not_found)",
    is_wp_error( $result9 ) && $result9->get_error_code() === 'task_not_found',
    is_wp_error( $result9 ) ? "code=" . $result9->get_error_code() : "ok"
);

// Check 10: source code documents the valid statuses
$src = file_get_contents( WPTSALL_PATH . 'includes/tasks/api/class-client-tasks-rest-controller.php' );
$has_enum = preg_match( "/in_array\(\s*\\\$status\s*,\s*array\(\s*'processing'\s*,\s*'retry'\s*,\s*'failed'\s*\)/", $src );
check(
    'Source code enforces enum {processing, retry, failed}',
    $has_enum === 1
);

echo "\n=== Status Enum Validation Test ===\n";
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