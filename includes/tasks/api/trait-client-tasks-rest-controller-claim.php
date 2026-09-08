<?php
/**
 * Client tasks REST controller claim trait.
 *
 * Extract lease ownership and heartbeat handling from the main controller.
 *
 * @package WPTSALL\Tasks\API
 */

namespace WPTSALL\Tasks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Client_Tasks_REST_Controller_Claim_Trait {

	/**
	 * Refresh an active task claim lease.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function heartbeat_task_claim( $request ) {
		global $wpdb;

		$table   = wptsall_table( 'tasks' );
		$task_id = absint( $request->get_param( 'id' ) );
		$now_ts  = time();
		$now     = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, status, meta, updated_at FROM %i WHERE id = %d', $table, $task_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new \WP_Error(
				'task_not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$current_status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		if ( $this->is_client_terminal_task_status( $current_status ) ) {
			return $this->build_client_terminal_task_conflict_error(
				'task_claim_conflict',
				__( 'Task is in terminal state, client cannot refresh lease', 'wpmmcc-ats' ),
				$current_status
			);
		}

		$meta_data = json_decode( (string) ( $row['meta'] ?? '' ), true );
		if ( ! is_array( $meta_data ) ) {
			$meta_data = array();
		}
		$claim_meta = $this->get_client_claim_meta( $meta_data );
		if ( empty( $claim_meta ) ) {
			return new \WP_Error(
				'task_claim_missing',
				__( 'Task does not have an active client claim', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}
		if ( $this->is_client_claim_expired( $claim_meta, $now_ts ) ) {
			return new \WP_Error(
				'task_claim_expired',
				__( 'Task claim has expired, please reclaim it before continuing', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}

		$worker_fingerprint = $this->get_client_worker_fingerprint( $request );
		if ( ! $this->can_worker_operate_claim( $claim_meta, $worker_fingerprint, $now_ts ) ) {
			return new \WP_Error(
				'task_claim_conflict',
				__( 'Task is occupied by another client, please retry later', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}

		$lease_seconds = $this->get_claim_lease_seconds();
		$claim_meta['worker_id']          = $worker_fingerprint;
		$claim_meta['worker_fingerprint'] = $worker_fingerprint;
		$claim_meta['last_heartbeat_at']  = $now;
		$claim_meta['lease_seconds']      = $lease_seconds;
		$claim_meta['lease_until_ts']     = $now_ts + $lease_seconds;
		$claim_meta['lease_until']        = gmdate( 'Y-m-d H:i:s', $now_ts + $lease_seconds );
		$claim_meta['heartbeat_count']    = (int) ( $claim_meta['heartbeat_count'] ?? 0 ) + 1;
		$meta_data['client_claim']        = $claim_meta;

		$where = array(
			'id'     => $task_id,
			'status' => $current_status,
		);
		$where_format = array( '%d', '%s' );
		$row_updated_at = sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) );
		if ( '' !== $row_updated_at ) {
			$where['updated_at'] = $row_updated_at;
			$where_format[]      = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'meta'       => wp_json_encode( $meta_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => $now,
			),
			$where,
			array( '%s', '%s' ),
			$where_format
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'task_claim_heartbeat_failed',
				__( 'Task claim heartbeat failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}
		if ( 0 === (int) $updated ) {
			return new \WP_Error(
				'task_claim_conflict',
				__( 'Task claim changed, please retry', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'task_id'           => $task_id,
					'schema_version'    => self::CLIENT_SCHEMA_VERSION,
					'lease_seconds'     => $lease_seconds,
					'lease_until_ts'    => (int) $claim_meta['lease_until_ts'],
					'lease_until'       => (string) $claim_meta['lease_until'],
					'last_heartbeat_at' => (string) $claim_meta['last_heartbeat_at'],
				),
			)
		);
	}

	/**
	 * Get stable client worker identity.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function get_client_worker_fingerprint( $request ) {
		$worker_id = (string) $request->get_header( 'X-WPTSALL-Worker-Id' );
		$worker_id = trim( $worker_id );
		if ( '' !== $worker_id ) {
			$worker_id = sanitize_text_field( $worker_id );
			if ( strlen( $worker_id ) > 128 ) {
				$worker_id = substr( $worker_id, 0, 128 );
			}
			return $worker_id;
		}

		$token = (string) $request->get_header( 'X-WPTSALL-Client-Token' );
		$token = trim( $token );
		if ( '' === $token ) {
			return '';
		}
		return substr( hash( 'sha256', $token ), 0, 24 );
	}

	/**
	 * Get claim lease seconds.
	 *
	 * @return int
	 */
	private function get_claim_lease_seconds() {
		$seconds = self::DEFAULT_CLIENT_CLAIM_LEASE_SECONDS;
		if ( defined( 'WPTSALL_CLIENT_CLAIM_LEASE_SECONDS' ) ) {
			$seconds = (int) WPTSALL_CLIENT_CLAIM_LEASE_SECONDS;
		}
		return max( 30, min( 3600, $seconds ) );
	}

	/**
	 * Read normalized claim metadata.
	 *
	 * @param array $meta_data Task meta.
	 * @return array
	 */
	private function get_client_claim_meta( $meta_data ) {
		$claim = is_array( $meta_data ) ? ( $meta_data['client_claim'] ?? array() ) : array();
		if ( ! is_array( $claim ) ) {
			return array();
		}
		return $claim;
	}

	/**
	 * Whether claim lease has expired.
	 *
	 * @param array $claim  Claim.
	 * @param int   $now_ts Current timestamp.
	 * @return bool
	 */
	private function is_client_claim_expired( $claim, $now_ts ) {
		$lease_until_ts = (int) ( $claim['lease_until_ts'] ?? 0 );
		if ( $lease_until_ts <= 0 && ! empty( $claim['lease_until'] ) ) {
			$lease_until_ts = strtotime( (string) $claim['lease_until'] );
		}
		if ( $lease_until_ts <= 0 ) {
			return true;
		}
		return $lease_until_ts < (int) $now_ts;
	}

	/**
	 * Check if current worker can operate on claimed task.
	 *
	 * @param array  $claim              Claim.
	 * @param string $worker_fingerprint Worker fingerprint.
	 * @param int    $now_ts             Current timestamp.
	 * @return bool
	 */
	private function can_worker_operate_claim( $claim, $worker_fingerprint, $now_ts ) {
		if ( empty( $claim ) || ! is_array( $claim ) ) {
			return true;
		}

		if ( $this->is_client_claim_expired( $claim, $now_ts ) ) {
			return true;
		}

		$owner = (string) ( $claim['worker_id'] ?? ( $claim['worker_fingerprint'] ?? '' ) );
		if ( '' === $owner ) {
			return true;
		}

		return hash_equals( $owner, (string) $worker_fingerprint );
	}
}
