<?php
/**
 * License / device token flow (pre-release single truth).
 */
require_once dirname( __DIR__ ) . '/bootstrap/check.php';

check( 'wptsall_issue_client_device_token exists', function_exists( 'wptsall_issue_client_device_token' ) );
if ( ! function_exists( 'wptsall_issue_client_device_token' ) ) {
	exit( 1 );
}
$a = wptsall_issue_client_device_token( 'flow-lic-a', 'flow' );
$b = wptsall_issue_client_device_token( 'flow-lic-b', 'flow' );
check( 'issue returns token', ! empty( $a['token'] ) && ! empty( $a['device_id'] ) );
check( 'tokens differ per device', $a['token'] !== $b['token'] );
check( 'verify a', wptsall_client_token_service()->verify_client_token( $a['token'], $a['device_id'] ) );
check( 'cross-device rejected', ! wptsall_client_token_service()->verify_client_token( $a['token'], $b['device_id'] ) );
check( 'install getter cleared', '' === (string) wptsall_get_client_api_token( false ) );
