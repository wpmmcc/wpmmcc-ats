<?php
/**
 * Device token issue/revoke flow.
 */
require_once dirname( __DIR__ ) . '/bootstrap/check.php';
use WPTSALL\Client_Pairing\Services\Client_Token_Service;

$svc = Client_Token_Service::instance();
delete_option( Client_Token_Service::OPTION_CLIENT_DEVICES );
$issued = $svc->issue_device_token( 'flow-rot-1', 'flow' );
check( 'issue device token', ! empty( $issued['token'] ) );
check( 'verify active', $svc->verify_client_token( $issued['token'], $issued['device_id'] ) );
check( 'revoke', $svc->revoke_device_token( $issued['device_id'] ) );
check( 'revoked rejected', ! $svc->verify_client_token( $issued['token'], $issued['device_id'] ) );
$snap = $svc->get_client_token_snapshot();
check( 'snapshot device_scoped', ! empty( $snap['device_scoped'] ) );
