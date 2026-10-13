<?php
/**
 * Manual-isolation watchdog mu-plugin body (test-only).
 *
 * Installed by manual-isolation-watchdog.php into wp-content/mu-plugins for
 * the duration of a manual gate and removed afterwards. Blocks and records
 * any WordPress outbound HTTP request that is not the gate's own loopback /
 * self traffic. Never ships with the plugin.
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wptsall_mi_block_http' ) ) {
	/**
	 * Block + record non-local outbound HTTP during the manual gate window.
	 *
	 * @param mixed  $pre         Preemptive response (usually false).
	 * @param array  $parsed_args Request args.
	 * @param string $url         Request URL.
	 * @return mixed Blocked response or the untouched pre value.
	 */
	function wptsall_mi_block_http( $pre, $parsed_args, $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $pre;
		}
		$host   = (string) parse_url( $url, PHP_URL_HOST );
		$port   = (int) parse_url( $url, PHP_URL_PORT );
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		$host_lower = strtolower( $host );
		$is_loopback = in_array( $host_lower, array( '127.0.0.1', '::1', 'localhost' ), true );
		$self_host   = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
		$is_self     = '' !== $self_host && $host_lower === $self_host;

		$forbidden = false;
		if ( ! $is_loopback && ! $is_self ) {
			// All outbound traffic is blocked during the gate (fail-closed),
			// but only infrastructure targets fail the gate. Vendor update /
			// promo / telemetry traffic is blocked and logged as evidence.
			$forbidden = true;
		}
		if ( $is_loopback && 0 !== $port && in_array( $port, array( 8977, 9090, 8787 ), true ) ) {
			// Client / mock provider / control-plane ports even on loopback.
			$forbidden = true;
		}
		if ( 'https' === $scheme && ! $is_self && ! $is_loopback ) {
			$forbidden = true;
		}
		if ( ! $forbidden ) {
			return $pre;
		}

		// Severity: infrastructure targets fail the gate; vendor noise does
		// not (it is still blocked, so nothing leaves the machine).
		$severity = 'vendor';
		if ( $is_loopback && 0 !== $port && in_array( $port, array( 8977, 9090, 8787 ), true ) ) {
			$severity = 'forbidden_infra';
		} elseif ( preg_match( '/wptsall|wpmm/i', $host_lower ) ) {
			// Product-vendor endpoints: the plugin must not phone home to its
			// own vendor infrastructure during manual-only operation.
			$severity = 'forbidden_infra';
		}

		$log   = get_option( 'wptsall_mi_http_attempts', array() );
		$log[] = array(
			'time'       => gmdate( 'c' ),
			'method'     => isset( $parsed_args['method'] ) ? (string) $parsed_args['method'] : '',
			'host'       => $host,
			'port'       => $port,
			'path'       => (string) parse_url( $url, PHP_URL_PATH ),
			'severity'   => $severity,
			'attempted'  => true,
		);
		update_option( 'wptsall_mi_http_attempts', $log, false );

		// Fail closed: the request never leaves the WordPress process.
		return array(
			'body'     => '',
			'response' => array(
				'code'    => 403,
				'message' => 'WPTSALL manual isolation: outbound request blocked',
			),
		);
	}
	add_action( 'pre_http_request', 'wptsall_mi_block_http', 9999, 3 );
}
