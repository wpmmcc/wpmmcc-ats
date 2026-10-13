<?php
/**
 * Ensure the live WP client API runtime is usable for E2E support lanes.
 *
 * Must run via `wp eval-file`.
 *
 * @package WPTSALL\E2E
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

$settings = get_option( 'wptsall_settings', array() );
$opted_in = ! empty( $settings['client_api_enabled'] );

if ( ! $opted_in ) {
	$settings['client_api_enabled'] = true;
	update_option( 'wptsall_settings', $settings );
	$opted_in = true;
}

if ( function_exists( 'wptsall_client_token_service' ) ) {
	$service = wptsall_client_token_service();
	$key     = method_exists( $service, 'get_license_key' ) ? (string) $service->get_license_key() : '';
	if ( '' !== $key && function_exists( 'wptsall_validate_license' ) ) {
		wptsall_validate_license( true );
	} elseif ( function_exists( 'wptsall_maybe_refresh_license_status' ) ) {
		wptsall_maybe_refresh_license_status();
	}
} elseif ( function_exists( 'wptsall_maybe_refresh_license_status' ) ) {
	wptsall_maybe_refresh_license_status();
}

$license_status = function_exists( 'wptsall_get_license_status' ) ? wptsall_get_license_status() : array();
$client_api_enabled = function_exists( 'wptsall_is_client_api_enabled' ) && wptsall_is_client_api_enabled();
$route_secret       = function_exists( 'wptsall_get_client_route_secret' ) ? (string) wptsall_get_client_route_secret() : '';
$client_token       = '';
$device_id          = '';

if ( $client_api_enabled && function_exists( 'wptsall_issue_client_device_token' ) ) {
	$issued       = wptsall_issue_client_device_token( 'e2e-runtime', 'e2e-runtime' );
	$client_token = (string) ( $issued['token'] ?? '' );
	$device_id    = (string) ( $issued['device_id'] ?? '' );
}

$runtime_status = array(
	'valid'            => $opted_in && $client_api_enabled && '' !== $route_secret && '' !== $client_token && '' !== $device_id,
	'pro_enabled'      => ! empty( $license_status['pro_enabled'] ),
	'license_status'   => is_scalar( $license_status['license_status'] ?? null ) ? (string) $license_status['license_status'] : '',
	'domain_status'    => is_scalar( $license_status['domain_status'] ?? null ) ? (string) $license_status['domain_status'] : '',
	'has_route_secret' => '' !== $route_secret,
	'has_client_token' => '' !== $client_token,
	'has_device_id'    => '' !== $device_id,
);

$payload = array(
	'client_api_enabled' => $client_api_enabled,
	'opted_in'           => $opted_in,
	'runtime_status'     => $runtime_status,
	'client_token'       => $client_token,
	'device_id'          => $device_id,
	'route_secret'       => $route_secret,
);

echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;

if ( ! $runtime_status['valid'] ) {
	fwrite( STDERR, "WPTSALL client API runtime is not valid for E2E.\n" );
	exit( 1 );
}
