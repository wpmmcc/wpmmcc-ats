<?php
/**
 * Client Tasks REST Controller snapshot trait.
 *
 * Extracted from Client_Tasks_REST_Controller to isolate source snapshot hash
 * validation from task routing and write-back orchestration.
 *
 * @package WPTSALL\Tasks\API
 */

namespace WPTSALL\Tasks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Client_Tasks_REST_Controller_Snapshot_Trait {

	/**
	 * Validate that the callback still targets the same source snapshot.
	 *
	 * @param array $result        Client callback payload.
	 * @param array $complete_data Current authoritative source data.
	 * @return true|\WP_Error
	 */
	private function validate_client_result_snapshot_hash( $result, $complete_data ) {
		$provided_hash = sanitize_text_field( (string) ( $result['object_snapshot_hash'] ?? '' ) );
		if ( '' === $provided_hash ) {
			return true;
		}

		$current_hash = $this->compute_client_result_snapshot_hash( $complete_data );
		if ( '' === $current_hash || hash_equals( $current_hash, $provided_hash ) ) {
			return true;
		}

		wptsall_log_warning(
			'tasks-sync',
			'Client callback rejected because source snapshot hash changed before write-back',
			array(
				'provided_hash' => $provided_hash,
				'current_hash'  => $current_hash,
			)
		);

		return new \WP_Error(
			'task_source_snapshot_mismatch',
			__( 'Source content changed before callback write-back; please resync and retry', 'wpmmcc-ats' ),
			array(
				'status'        => 409,
				'provided_hash' => $provided_hash,
				'expected_hash' => $current_hash,
			)
		);
	}

	/**
	 * Compute the canonical snapshot hash used by the Rust client callback.
	 *
	 * @param mixed $payload Payload to hash.
	 * @return string
	 */
	private function compute_client_result_snapshot_hash( $payload ) {
		$canonical = $this->canonicalize_client_result_snapshot_value( $payload );
		$encoded   = wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return '';
		}

		return hash( 'sha256', $encoded );
	}

	/**
	 * Canonicalize nested arrays so associative keys are stable before hashing.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private function canonicalize_client_result_snapshot_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( $this->is_sequential_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$out[] = $this->canonicalize_client_result_snapshot_value( $item );
			}
			return $out;
		}

		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize_client_result_snapshot_value( $item );
		}

		return $value;
	}

	/**
	 * Check whether an array uses sequential numeric indexes.
	 *
	 * @param mixed $value Candidate array.
	 * @return bool
	 */
	private function is_sequential_array( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
