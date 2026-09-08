<?php
/**
 * Client tasks REST controller idempotency trait.
 *
 * @package WPTSALL\Tasks\API
 */

namespace WPTSALL\Tasks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Client_Tasks_REST_Controller_Idempotency_Trait {

	/**
	 * Parse Idempotency-Key header.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	private function parse_client_idempotency_key( $request ) {
		$key = (string) $request->get_header( 'Idempotency-Key' );
		$key = trim( $key );
		if ( '' === $key ) {
			return '';
		}
		if ( strlen( $key ) > 128 || 1 !== preg_match( '/^[\x20-\x7E]+$/', $key ) ) {
			return new \WP_Error(
				'client_idempotency_key_invalid',
				__( 'Invalid Idempotency-Key (must be ASCII and not exceed 128 characters)', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		return $key;
	}

	/**
	 * Build idempotency fingerprint.
	 *
	 * @param mixed $payload Payload.
	 * @return string
	 */
	private function build_client_idempotency_fingerprint( $payload ) {
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			$encoded = serialize( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}
		return hash( 'sha256', (string) $encoded );
	}

	/**
	 * Read client idempotency record.
	 *
	 * @param array  $meta_data Task meta.
	 * @param string $scope     Scope.
	 * @param string $key       Idempotency key.
	 * @return array
	 */
	private function get_client_idempotency_record( $meta_data, $scope, $key ) {
		$scope = sanitize_key( (string) $scope );
		$key   = (string) $key;
		if ( '' === $scope || '' === $key || ! is_array( $meta_data ) ) {
			return array();
		}
		$pool = $meta_data['client_idempotency'][ $scope ] ?? array();
		if ( ! is_array( $pool ) ) {
			return array();
		}
		$record = $pool[ $key ] ?? array();
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Remember client idempotency record and keep bounded size.
	 *
	 * @param array  $meta_data    Task meta.
	 * @param string $scope        Scope.
	 * @param string $key          Idempotency key.
	 * @param string $fingerprint  Request fingerprint.
	 * @param array  $response     Response payload.
	 * @return void
	 */
	private function remember_client_idempotency_record( &$meta_data, $scope, $key, $fingerprint, $response ) {
		$scope = sanitize_key( (string) $scope );
		$key   = (string) $key;
		if ( '' === $scope || '' === $key ) {
			return;
		}
		if ( ! isset( $meta_data['client_idempotency'] ) || ! is_array( $meta_data['client_idempotency'] ) ) {
			$meta_data['client_idempotency'] = array();
		}
		if ( ! isset( $meta_data['client_idempotency'][ $scope ] ) || ! is_array( $meta_data['client_idempotency'][ $scope ] ) ) {
			$meta_data['client_idempotency'][ $scope ] = array();
		}

		$records         = $meta_data['client_idempotency'][ $scope ];
		$records[ $key ] = array(
			'fingerprint' => (string) $fingerprint,
			'response'    => is_array( $response ) ? $response : array(),
			'updated_at'  => current_time( 'mysql', true ),
		);
		if ( count( $records ) > 50 ) {
			$records = array_slice( $records, -50, null, true );
		}
		$meta_data['client_idempotency'][ $scope ] = $records;
	}
}
