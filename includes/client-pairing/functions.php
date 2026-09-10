<?php
/**
 * Client pairing helper functions.
 *
 * All features are fully functional in the wp.org version.
 * The client API token is used for communication with the
 * standalone translation client, not for license gating.
 *
 * @package WPTSALL
 * @since 1.0.0
 * @updated 2.1.2 Renamed from the historical `enterprise` module
 *               (wptsall_license_service -> wptsall_client_token_service).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the client token service instance.
 *
 * @return \WPTSALL\Client_Pairing\Services\Client_Token_Service
 */
function wptsall_client_token_service() {
	return \WPTSALL\Client_Pairing\Services\Client_Token_Service::instance();
}

/**
 * All features are always enabled.
 *
 * @return bool Always true.
 */
function wptsall_is_pro_enabled() {
	return true;
}

/**
 * All features are always available.
 *
 * @return bool Always true.
 */
/**
 * Client API endpoints are always enabled.
 *
 * @since 1.2.0
 * @return bool Always true.
 */
function wptsall_is_client_api_enabled() {
	return true;
}

/**
 * Get or regenerate client API token.
 *
 * This token is used for authentication between WP plugin and the
 * standalone translation client (a separate Rust application).
 * It is NOT a license key — all features are free.
 *
 * @param bool $regenerate Regenerate token.
 * @return string
 */
function wptsall_get_client_api_token( $regenerate = false ) {
	return wptsall_client_token_service()->get_client_token( (bool) $regenerate );
}

/**
 * Verify client API token (device-scoped only).
 *
 * @param string $token Provided token.
 * @return bool
 */
function wptsall_verify_client_api_token( $token ) {
	$device_id = '';
	if ( isset( $_SERVER['HTTP_X_WPTSALL_DEVICE_ID'] ) ) {
		$device_id = sanitize_key( wp_unslash( (string) $_SERVER['HTTP_X_WPTSALL_DEVICE_ID'] ) );
	}
	return wptsall_client_token_service()->verify_client_token( (string) $token, $device_id, true );
}

/**
 * Issue a device-scoped client token (plaintext returned once).
 *
 * @since 2.0.1
 * @param string $device_id Device id.
 * @param string   $label       Label.
 * @param int|null $ttl_seconds Token lifetime in seconds.
 * @return array{device_id:string,token:string,created_at:string,expires_at:int,expires_in:int}
 */
function wptsall_issue_client_device_token( $device_id = '', $label = '', $ttl_seconds = null ) {
	return wptsall_client_token_service()->issue_device_token( (string) $device_id, (string) $label, $ttl_seconds );
}

/**
 * Revoke a device-scoped client token.
 *
 * @since 2.0.1
 * @param string $device_id Device id.
 * @return bool
 */
function wptsall_revoke_client_device_token( $device_id ) {
	return wptsall_client_token_service()->revoke_device_token( (string) $device_id );
}

/**
 * Default scopes granted by a standalone site pairing code.
 *
 * Pairing is intentionally limited to the translation data plane. Config
 * import/export remains an admin/config-scope operation.
 *
 * @since 2.1.2
 * @return array<int,string>
 */
function wptsall_default_client_pairing_scopes() {
	return array(
		'site.validate',
		'translate.read',
		'translate.claim',
		'translate.write_callback',
		'media.upload',
	);
}

/**
 * Return the option key for hash-at-rest pairing codes.
 *
 * @since 2.1.2
 * @return string
 */
function wptsall_client_pairing_codes_option() {
	return 'wptsall_client_pairing_codes';
}

/**
 * Generate a human-copyable one-time pairing code.
 *
 * @since 2.1.2
 * @return string
 */
function wptsall_generate_client_pairing_code() {
	return 'WPTS-' . strtoupper( bin2hex( random_bytes( 8 ) ) );
}

/**
 * Hash a pairing code before persistence.
 *
 * @since 2.1.2
 * @param string $code Pairing code.
 * @return string
 */
function wptsall_hash_client_pairing_code( $code ) {
	return hash_hmac( 'sha256', strtoupper( trim( (string) $code ) ), wp_salt( 'auth' ) );
}

/**
 * Load non-expired pairing records.
 *
 * @since 2.1.2
 * @return array<int,array<string,mixed>>
 */
function wptsall_load_client_pairing_codes() {
	$records = get_option( wptsall_client_pairing_codes_option(), array() );
	if ( ! is_array( $records ) ) {
		return array();
	}
	$now = time();
	$out = array();
	foreach ( $records as $record ) {
		if ( ! is_array( $record ) ) {
			continue;
		}
		$expires_at = isset( $record['expires_at'] ) ? (int) $record['expires_at'] : 0;
		if ( $expires_at > 0 && $expires_at <= $now ) {
			continue;
		}
		if ( ! empty( $record['claimed_at'] ) ) {
			continue;
		}
		$out[] = $record;
	}
	return $out;
}

/**
 * Save pairing records with autoload disabled.
 *
 * @since 2.1.2
 * @param array<int,array<string,mixed>> $records Records.
 * @return void
 */
function wptsall_save_client_pairing_codes( $records ) {
	update_option( wptsall_client_pairing_codes_option(), array_values( (array) $records ), false );
	if ( function_exists( 'wptsall_set_option_autoload' ) ) {
		wptsall_set_option_autoload( wptsall_client_pairing_codes_option(), false );
	}
}

/**
 * Build the public connection-pack fields for this WP site.
 *
 * @since 2.1.2
 * @param string $device_id Optional client device id.
 * @param array<int,string> $scopes Scopes.
 * @param int    $expires_at Expiry timestamp.
 * @return array<string,mixed>
 */
function wptsall_build_site_connection_pack_base( $device_id = '', $scopes = array(), $expires_at = 0 ) {
	$route_secret = function_exists( 'wptsall_get_client_route_secret' ) ? (string) wptsall_get_client_route_secret() : '';
	$site_url     = home_url();
	$client_base  = trailingslashit( $site_url ) . 'wp-json/wptsall/v2/' . rawurlencode( $route_secret ) . '/client';
	$issued_at    = gmdate( 'c' );
	$capabilities = array(
		'validate_token',
		'site_relations',
		'rules',
		'content_discovery',
		'content_claim',
		'content_changes',
		'translation_callback',
		'media_upload',
		'pairing_claim',
	);

	return array(
		'schema'              => 'wptsall-site-connection.v1',
		'site_id'             => md5( untrailingslashit( $site_url ) ),
		'site_url'            => $site_url,
		'wp_client_base'      => $client_base,
		'route_secret'        => $route_secret,
		'device_id'           => sanitize_key( (string) $device_id ),
		'protocol_version'    => 2,
		'scopes'              => ! empty( $scopes ) ? array_values( array_map( 'sanitize_key', (array) $scopes ) ) : wptsall_default_client_pairing_scopes(),
		'issued_at'           => $issued_at,
		'expires_at'          => (int) $expires_at,
		'wp_plugin_version'   => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '',
		'wordpress_version'   => get_bloginfo( 'version' ),
		'capabilities'        => $capabilities,
	);
}

/**
 * Create a short-lived one-time site connection pack.
 *
 * The plaintext pairing code is returned once in the pack; only its hash is
 * stored. The pack may be copied into the standalone client without contacting
 * the official site.
 *
 * @since 2.1.2
 * @param string          $device_id Client device id.
 * @param string          $device_label Label.
 * @param int|null        $ttl_seconds Pairing-code lifetime.
 * @param array<int,string> $scopes Scopes.
 * @return array<string,mixed>
 */
function wptsall_create_site_connection_pack( $device_id = '', $device_label = '', $ttl_seconds = null, $scopes = array() ) {
	$device_id = sanitize_key( (string) $device_id );
	$ttl       = null === $ttl_seconds ? 300 : max( 30, min( 3600, (int) $ttl_seconds ) );
	$expires   = time() + $ttl;
	$scopes    = ! empty( $scopes ) ? array_values( array_map( 'sanitize_key', (array) $scopes ) ) : wptsall_default_client_pairing_scopes();
	$code      = wptsall_generate_client_pairing_code();

	$records   = wptsall_load_client_pairing_codes();
	$records[] = array(
		'code_hash'    => wptsall_hash_client_pairing_code( $code ),
		'device_id'    => $device_id,
		'device_label' => sanitize_text_field( (string) $device_label ),
		'scopes'       => $scopes,
		'created_at'   => current_time( 'mysql' ),
		'expires_at'   => $expires,
		'claimed_at'   => null,
	);
	wptsall_save_client_pairing_codes( $records );

	$pack                 = wptsall_build_site_connection_pack_base( $device_id, $scopes, $expires );
	$pack['pairing_code'] = $code;
	$pack['device_label'] = sanitize_text_field( (string) $device_label );
	$pack['expires_in']   = $ttl;
	return $pack;
}

/**
 * Claim a one-time pairing code and issue a device-scoped client token.
 *
 * @since 2.1.2
 * @param string $device_id Client device id.
 * @param string $pairing_code Pairing code.
 * @param string $device_label Optional label override.
 * @return array<string,mixed>|\WP_Error
 */
function wptsall_claim_client_pairing_code( $device_id, $pairing_code, $device_label = '' ) {
	$device_id = sanitize_key( (string) $device_id );
	$code_hash = wptsall_hash_client_pairing_code( (string) $pairing_code );
	if ( '' === $device_id || '' === trim( (string) $pairing_code ) ) {
		return new \WP_Error( 'pairing_invalid_request', __( 'device_id and pairing_code are required.', 'wpmmcc-ats' ), array( 'status' => 400 ) );
	}

	$records = wptsall_load_client_pairing_codes();
	$matched = null;
	$kept    = array();
	foreach ( $records as $record ) {
		$record_hash = (string) ( $record['code_hash'] ?? '' );
		$is_match    = '' !== $record_hash && hash_equals( $record_hash, $code_hash );
		if ( $is_match ) {
			$expected_device = sanitize_key( (string) ( $record['device_id'] ?? '' ) );
			if ( '' !== $expected_device && $expected_device !== $device_id ) {
				$kept[] = $record;
				continue;
			}
			$matched = $record;
			continue;
		}
		$kept[] = $record;
	}

	if ( null === $matched ) {
		wptsall_save_client_pairing_codes( $kept );
		return new \WP_Error( 'pairing_code_invalid', __( 'Pairing code is invalid, expired, already claimed, or bound to another device.', 'wpmmcc-ats' ), array( 'status' => 403 ) );
	}

	wptsall_save_client_pairing_codes( $kept );
	$label  = '' !== trim( (string) $device_label ) ? (string) $device_label : (string) ( $matched['device_label'] ?? 'paired-client' );
	$issued = wptsall_issue_client_device_token( $device_id, $label, null );
	$scopes = ! empty( $matched['scopes'] ) && is_array( $matched['scopes'] ) ? array_values( $matched['scopes'] ) : wptsall_default_client_pairing_scopes();

	return array(
		'device_id'    => (string) ( $issued['device_id'] ?? $device_id ),
		'client_token' => (string) ( $issued['token'] ?? '' ),
		'expires_at'   => (int) ( $issued['expires_at'] ?? 0 ),
		'expires_in'   => (int) ( $issued['expires_in'] ?? 0 ),
		'scopes'       => $scopes,
	);
}
