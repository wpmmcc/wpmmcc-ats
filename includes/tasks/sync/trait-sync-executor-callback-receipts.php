<?php
/**
 * Checked source-side boundaries around non-atomic target effects.
 *
 * @package WPTSALL\Tasks\Sync
 */
namespace WPTSALL\Tasks\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared durable receipt queries cannot use cached state.
trait Sync_Executor_Callback_Receipts_Trait {
	/**
	 * Only one executor may cross the target-effect boundary for a result.
	 * A crash afterwards stays applying, rather than becoming an age-based retry.
	 */
	private static function begin_translation_callback_sync( $task_id, $result_id ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'callback_transaction_unavailable', 'Unable to persist the target-effect boundary.' );
		}
		$committed = false;
		try {
			$task = $wpdb->get_row(
				$wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d FOR UPDATE', wptsall_table( 'tasks' ), $task_id ),
				ARRAY_A
			);
			$payload = is_array( $task ) ? json_decode( (string) $task['payload'], true ) : null;
			if ( '' !== $wpdb->last_error || ! is_array( $payload ) || (int) ( $payload['translation_result_id'] ?? 0 ) !== (int) $result_id ) {
				return new \WP_Error( 'callback_task_lookup_failed', 'The callback task association is unavailable.' );
			}
			$result = $wpdb->get_row(
				$wpdb->prepare( 'SELECT status, translated_meta FROM %i WHERE id = %d FOR UPDATE', wptsall_table( 'translation_results' ), $result_id ),
				ARRAY_A
			);
			$meta = is_array( $result ) ? json_decode( (string) $result['translated_meta'], true ) : null;
			$prepared = is_array( $meta ) ? ( $meta['_wptsall_callback_receipt'] ?? null ) : null;
			if ( '' !== $wpdb->last_error || ! is_array( $result ) || 'pending' !== $result['status']
				|| ! is_array( $prepared ) || 1 !== ( $prepared['version'] ?? null )
				|| 'prepared' !== ( $prepared['stage'] ?? '' ) ) {
				return new \WP_Error( 'callback_effects_not_replayable', 'Target effects may already exist; retain the original callback for review.' );
			}
			$meta['_wptsall_callback_receipt']['stage'] = 'applying';
			$meta['_wptsall_callback_receipt']['sync_task_id'] = (int) $task_id;
			$written = $wpdb->update(
				wptsall_table( 'translation_results' ),
				array( 'status' => 'applying', 'translated_meta' => wp_json_encode( $meta ) ),
				array( 'id' => $result_id, 'status' => 'pending' ), array( '%s', '%s' ), array( '%d', '%s' )
			);
			if ( 1 !== $written ) {
				return new \WP_Error( 'callback_effects_not_replayable', 'Target effects may already exist; retain the original callback for review.' );
			}
			if ( ! self::update_task_status( $task_id, 'processing', array( 'status_note' => 'sync_started' ) ) ) {
				return new \WP_Error( 'callback_task_write_failed', 'Unable to persist callback execution.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new \WP_Error( 'callback_transaction_commit_failed', 'Unable to commit callback execution.' );
			}
			$committed = true;
			return true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/**
	 * A target write is not an acknowledgement until task and receipt commit.
	 */
	private static function finish_translation_callback_sync( $task_id, $result_id, $task_status, $result_status, array $status_meta ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'callback_transaction_unavailable', 'Unable to persist the terminal callback receipt.' );
		}
		$committed = false;
		try {
			$task = $wpdb->get_row(
				$wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d FOR UPDATE', wptsall_table( 'tasks' ), $task_id ),
				ARRAY_A
			);
			$payload = is_array( $task ) ? json_decode( (string) $task['payload'], true ) : null;
			if ( '' !== $wpdb->last_error || ! is_array( $payload ) || (int) ( $payload['translation_result_id'] ?? 0 ) !== (int) $result_id ) {
				return new \WP_Error( 'callback_task_lookup_failed', 'The callback task association is unavailable.' );
			}
			$result = $wpdb->get_row(
				$wpdb->prepare( 'SELECT status, translated_meta FROM %i WHERE id = %d FOR UPDATE', wptsall_table( 'translation_results' ), $result_id ),
				ARRAY_A
			);
			if ( '' !== $wpdb->last_error || ! is_array( $result )
				|| ! in_array( $result['status'], array( 'pending', 'applying' ), true ) ) {
				return new \WP_Error( 'callback_receipt_unavailable', 'The original callback receipt cannot be completed.' );
			}
			if ( ! self::update_task_status( $task_id, $task_status, $status_meta ) ) {
				return new \WP_Error( 'callback_task_write_failed', 'Unable to persist the terminal callback task.' );
			}
			$meta = json_decode( (string) $result['translated_meta'], true );
			$data = array( 'status' => $result_status, 'synced_at' => current_time( 'mysql', true ) );
			$formats = array( '%s', '%s' );
			if ( is_array( $meta ) && isset( $meta['_wptsall_callback_receipt'] ) ) {
				$meta['_wptsall_callback_receipt'] = array_merge(
					(array) $meta['_wptsall_callback_receipt'],
					array(
						'stage' => 'terminal', 'sync_task_id' => (int) $task_id,
						'task_status' => $task_status, 'result_status' => $result_status,
						'target_id' => (int) ( $status_meta['target_id'] ?? 0 ),
					)
				);
				$data['translated_meta'] = wp_json_encode( $meta );
				$formats[] = '%s';
			}
			$written = $wpdb->update(
				wptsall_table( 'translation_results' ), $data,
				array( 'id' => $result_id, 'status' => $result['status'] ), $formats, array( '%d', '%s' )
			);
			if ( 1 !== $written ) {
				return new \WP_Error( 'callback_receipt_write_failed', 'Unable to persist the terminal callback receipt.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new \WP_Error( 'callback_transaction_commit_failed', 'Unable to commit the terminal callback receipt.' );
			}
			$committed = true;
			return true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}
}
