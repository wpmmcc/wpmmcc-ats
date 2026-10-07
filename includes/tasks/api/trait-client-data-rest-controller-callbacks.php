<?php
/**
 * Durable callback receipts and atomic entry write-back.
 *
 * @package WPTSALL\Tasks\API
 */
namespace WPTSALL\Tasks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable callback state uses prepared plugin-table queries, never cached reads.
trait Client_Data_REST_Controller_Callbacks_Trait {
	private function callback_failure( $code, $status = 500, $result_id = 0 ) {
		return new \WP_REST_Response(
			array( 'success' => false, 'error' => $code, 'result_id' => (int) $result_id ),
			$status
		);
	}

	private function callback_device_hash( $request ) {
		return hash( 'sha256', 'wptsall-callback-device-v1|' . sanitize_key( (string) $request->get_header( 'X-WPTSALL-Device-Id' ) ) );
	}

	/**
	 * An unresolved saved result is not permission to translate the object again.
	 */
	private function unresolved_content_callback( $relation_id, $object_type, $object_id ) {
		global $wpdb;
		if ( $object_id <= 0 ) {
			return null;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, status, translated_meta FROM %i WHERE relation_id = %d AND object_type = %s AND object_id = %d AND status IN (%s, %s, %s)',
				wptsall_table( 'translation_results' ), $relation_id, $object_type, $object_id,
				'pending', 'failed', 'applying'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return new \WP_Error( 'callback_receipt_lookup_failed', 'Unable to check saved callback effects.', array( 'status' => 500 ) );
		}
		foreach ( $rows as $row ) {
			$meta = json_decode( (string) $row['translated_meta'], true );
			$prepared = is_array( $meta ) ? ( $meta['_wptsall_callback_receipt'] ?? null ) : null;
			if ( 'applying' === $row['status'] || ! is_array( $prepared )
				|| 1 !== ( $prepared['version'] ?? null ) || 'prepared' !== ( $prepared['stage'] ?? '' ) ) {
				return new \WP_Error(
					'callback_effects_unresolved', 'Retain the original callback and review existing target effects before translating again.',
					array( 'status' => 409, 'result_id' => (int) $row['id'] )
				);
			}
		}
		return null;
	}

	/**
	 * The result row, not a transient cache or queue id, is the receipt authority.
	 */
	private function callback_result_receipt( array $result, $request, $idempotent = false ) {
		$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		if ( ! in_array( $status, self::ACCEPTED_RESULT_STATUSES, true ) ) {
			return $this->callback_failure( 'callback_not_replayable', 409, $result['id'] ?? 0 );
		}
		$receipt = array(
			'success' => true, 'result_id' => (int) $result['id'], 'result_status' => $status,
			'sync_task_id' => 0, 'queued' => false, 'protocol' => 'v2',
		);
		$meta = json_decode( (string) ( $result['translated_meta'] ?? '' ), true );
		$content_receipt = is_array( $meta ) ? ( $meta['_wptsall_callback_receipt'] ?? null ) : null;
		if ( is_array( $content_receipt ) ) {
			if ( 1 !== ( $content_receipt['version'] ?? null )
				|| 'terminal' !== ( $content_receipt['stage'] ?? '' )
				|| $status !== ( $content_receipt['result_status'] ?? '' )
				|| (int) ( $content_receipt['sync_task_id'] ?? 0 ) <= 0 ) {
				return $this->callback_failure( 'callback_receipt_unavailable', 409, $result['id'] );
			}
			if ( ! isset( $content_receipt['device_hash'] )
				|| ! hash_equals( (string) $content_receipt['device_hash'], $this->callback_device_hash( $request ) ) ) {
				return $this->callback_failure( 'callback_receipt_owner_mismatch', 403, $result['id'] );
			}
			$receipt['sync_task_id'] = (int) $content_receipt['sync_task_id'];
			$receipt['queued'] = true;
			$receipt['sync_result'] = array(
				'success' => true, 'target_id' => (int) ( $content_receipt['target_id'] ?? 0 ),
				'skipped' => 'cancelled' === $status,
			);
		}
		if ( in_array( (string) ( $result['object_type'] ?? '' ), array( 'i18n', 'site_string' ), true ) ) {
			$fields = json_decode( (string) ( $result['translated_fields'] ?? '' ), true );
			$stored = is_array( $fields ) ? ( $fields['callback_receipt'] ?? null ) : null;
			if ( ! is_array( $stored ) || ! isset( $stored['entries_updated'], $stored['entries_rejected'], $stored['device_hash'] ) ) {
				// Older rows only recorded the submitted count, not the applied
				// count. That is insufficient evidence to acknowledge a batch.
				return $this->callback_failure( 'callback_receipt_unavailable', 409, $result['id'] );
			}
			if ( ! hash_equals( (string) $stored['device_hash'], $this->callback_device_hash( $request ) ) ) {
				return $this->callback_failure( 'callback_receipt_owner_mismatch', 403, $result['id'] );
			}
			if ( (int) $stored['entries_rejected'] !== 0
				|| (int) $stored['entries_updated'] <= 0
				|| (int) $stored['entries_updated'] !== (int) ( $fields['entries_count'] ?? 0 ) ) {
				return $this->callback_failure( 'callback_receipt_unavailable', 409, $result['id'] );
			}
			$receipt['entries_updated'] = (int) $stored['entries_updated'];
			$receipt['entries_rejected'] = 0;
			$receipt['updated_count'] = (int) $stored['entries_updated'];
		}
		if ( $idempotent ) {
			$receipt['idempotent'] = true;
		}
		if ( 'partial' === $status ) {
			$receipt['partial'] = true;
			if ( isset( $receipt['sync_result'] ) ) {
				$receipt['sync_result']['partial'] = true;
			}
		} elseif ( 'cancelled' === $status ) {
			$receipt['skipped'] = true;
		}
		return new \WP_REST_Response( $receipt, 200 );
	}

	private function finish_callback_outbox( $outbox_id, $owner_hash, $response ) {
		if ( $outbox_id <= 0 || ! class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
			return $response;
		}
		$status = $response instanceof \WP_REST_Response ? (int) $response->get_status() : 500;
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		if ( $status >= 200 && $status < 300 && true === ( $data['success'] ?? false ) ) {
			if ( ! \WPTSALL\Hooks\Content_Change_Dispatcher::complete_outbox( $outbox_id, $owner_hash ) ) {
				return $this->callback_failure( 'outbox_acknowledgement_failed', 500, $data['result_id'] ?? 0 );
			}
		} elseif ( $status >= 400 && 'outbox_acknowledgement_failed' !== ( $data['error'] ?? '' ) ) {
			\WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $outbox_id, 'translation_callback_' . $status, $owner_hash );
		}
		return $response;
	}

	/**
	 * Commit entries, their original claims, task projections and receipt together.
	 * Notifications run only after commit; a lost HTTP response replays this row.
	 */
	private function commit_entry_callback( array $body, $client_task_id, $relation_id, $object_type, $request, $apply ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->callback_failure( 'callback_transaction_unavailable' );
		}
		$committed = false;
		try {
			$fields = array( 'entries_count' => count( $body['entries'] ) );
			$result_id = wptsall_insert_translation_result(
				array(
					'relation_id' => $relation_id, 'object_type' => $object_type, 'object_id' => 0,
					'translated_fields' => $fields, 'client_task_id' => $client_task_id,
					'request_hash' => (string) ( $body['_wptsall_request_hash'] ?? '' ),
					'source_lang' => sanitize_text_field( (string) ( $body['source_lang'] ?? '' ) ),
					'target_lang' => sanitize_text_field( (string) ( $body['target_lang'] ?? '' ) ),
				)
			);
			if ( ! $result_id ) {
				return $this->callback_failure( 'callback_receipt_insert_failed' );
			}
			$result = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', wptsall_table( 'translation_results' ), $result_id ),
				ARRAY_A
			);
			if ( ! is_array( $result ) || '' !== $wpdb->last_error ) {
				return $this->callback_failure( 'callback_receipt_lookup_failed' );
			}
			if ( ! hash_equals( (string) ( $result['request_hash'] ?? '' ), (string) ( $body['_wptsall_request_hash'] ?? '' ) ) ) {
				return $this->callback_failure( 'idempotency_conflict', 409, $result_id );
			}
			// A competing INSERT may have waited for the original batch to
			// commit. Its terminal row is replayed, never applied a second time.
			if ( 'pending' !== $result['status'] ) {
				return $this->callback_result_receipt( $result, $request, true );
			}
			$outcome = call_user_func( $apply );
			if ( is_wp_error( $outcome ) ) {
				return $this->callback_failure( $outcome->get_error_code(), (int) ( $outcome->get_error_data()['status'] ?? 500 ) );
			}
			if ( ! is_array( $outcome ) || (int) ( $outcome['updated'] ?? 0 ) !== count( $body['entries'] ) ) {
				return $this->callback_failure( 'claim_lost_or_entry_rejected', 409 );
			}
			$fields['callback_receipt'] = array(
				'entries_updated' => (int) $outcome['updated'], 'entries_rejected' => 0,
				'device_hash' => $this->callback_device_hash( $request ),
			);
			$written = $wpdb->update(
				wptsall_table( 'translation_results' ),
				array( 'translated_fields' => wp_json_encode( $fields ), 'status' => 'synced', 'synced_at' => current_time( 'mysql', true ) ),
				array( 'id' => $result_id, 'status' => 'pending' ),
				array( '%s', '%s', '%s' ), array( '%d', '%s' )
			);
			if ( 1 !== $written ) {
				return $this->callback_failure( 'callback_receipt_write_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return $this->callback_failure( 'callback_transaction_commit_failed' );
			}
			$committed = true;
			foreach ( (array) ( $outcome['notifications'] ?? array() ) as $notification ) {
				try {
					do_action_ref_array( $notification['hook'], $notification['args'] );
				} catch ( \Throwable $error ) {
					// Notification failures cannot erase an already durable receipt.
					wptsall_log_warning( 'client-api', 'Callback committed; post-commit notification failed', array( 'result_id' => $result_id ) );
				}
			}
			$result['status'] = 'synced';
			$result['translated_fields'] = wp_json_encode( $fields );
			return $this->callback_result_receipt( $result, $request );
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	private function apply_i18n_callback_entries( array $body, $relation_id, $source_type, $target_lang, $owner_hash ) {
		global $wpdb;
		$updated = 0;
		$seen = array();
		$template_ids = array();
		$notifications = array();
		$entries_table = wptsall_table( 'template_entries' );
		$templates_table = wptsall_table( 'templates' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
		$now = current_time( 'mysql', true );
		foreach ( $body['entries'] as $entry ) {
			$id = is_array( $entry ) ? (int) ( $entry['entry_id'] ?? 0 ) : 0;
			$msgstr = is_array( $entry ) ? ( $entry['msgstr'] ?? null ) : null;
			if ( $id <= 0 || isset( $seen[ $id ] ) || ! is_string( $msgstr ) || '' === $msgstr ) {
				return new \WP_Error( 'claim_lost_or_entry_rejected', '', array( 'status' => 409 ) );
			}
			$seen[ $id ] = true;
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT e.template_id, e.claimed_at, e.claim_owner_hash, t.relation_id, t.source_type, t.target_language, t.text_domain FROM %i e INNER JOIN %i t ON t.id = e.template_id WHERE e.id = %d FOR UPDATE',
					$entries_table, $templates_table, $id
				),
				ARRAY_A
			);
			if ( '' !== $wpdb->last_error ) {
				return new \WP_Error( 'callback_entry_read_failed', '', array( 'status' => 500 ) );
			}
			if ( ! is_array( $row ) || (int) $row['relation_id'] !== (int) $relation_id
				|| $row['source_type'] !== $source_type
				|| ( '' !== (string) $row['target_language'] && $row['target_language'] !== $target_lang )
				|| '' === (string) $row['claimed_at'] || $row['claimed_at'] < $cutoff
				|| ! hash_equals( $owner_hash, (string) $row['claim_owner_hash'] ) ) {
				return new \WP_Error( 'claim_lost_or_entry_rejected', '', array( 'status' => 409 ) );
			}
			$written = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i e INNER JOIN %i t ON t.id = e.template_id SET e.msgstr = %s, e.status = %s, e.claimed_at = NULL, e.claim_owner_hash = NULL, e.updated_at = %s WHERE e.id = %d AND t.relation_id = %d AND t.source_type = %s AND (t.target_language = %s OR t.target_language = %s OR t.target_language IS NULL) AND e.claimed_at >= %s AND e.claim_owner_hash = %s',
					$entries_table, $templates_table, $msgstr, 'translated', $now, $id,
					$relation_id, $source_type, $target_lang, '', $cutoff, $owner_hash
				)
			);
			if ( false === $written ) {
				return new \WP_Error( 'callback_entry_write_failed', '', array( 'status' => 500 ) );
			}
			if ( 1 !== $written ) {
				return new \WP_Error( 'claim_lost_or_entry_rejected', '', array( 'status' => 409 ) );
			}
			++$updated;
			$template_ids[] = (int) $row['template_id'];
			$notifications[] = array( 'hook' => 'wptsall_entry_updated', 'args' => array( $id, array( 'text_domain' => (string) $row['text_domain'] ) ) );
		}
		foreach ( array_unique( $template_ids ) as $template_id ) {
			$remaining = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE template_id = %d AND status <> %s', $entries_table, $template_id, 'translated' )
			);
			if ( null === $remaining || '' !== $wpdb->last_error ) {
				return new \WP_Error( 'callback_task_read_failed', '', array( 'status' => 500 ) );
			}
			if ( (int) $remaining > 0 ) {
				continue;
			}
			$written = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = %s, updated_at = %s WHERE relation_id = %d AND object_type = %s AND subtype = %s AND CASE WHEN JSON_VALID(meta) THEN JSON_EXTRACT(meta, '$.template_id') END = %d AND status IN (%s, %s, %s)",
					wptsall_table( 'tasks' ), 'completed', $now, $relation_id, 'language_pack',
					$source_type, $template_id, 'pending', 'active', 'processing'
				)
			);
			if ( false === $written ) {
				return new \WP_Error( 'callback_task_write_failed', '', array( 'status' => 500 ) );
			}
		}
		return array( 'updated' => $updated, 'notifications' => $notifications );
	}
}
