<?php
/**
 * Client Tasks REST Controller
 *
 * Dedicated endpoints for external Rust client.
 *
 * @package WPTSALL\Tasks\API
 * @since 1.0.0
 */

namespace WPTSALL\Tasks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client tasks REST controller.
 */
class Client_Tasks_REST_Controller {

	/**
	 * Default client claim lease seconds.
	 *
	 * @var int
	 */
	const DEFAULT_CLIENT_CLAIM_LEASE_SECONDS = 600;

	/**
	 * Client protocol schema version.
	 *
	 * @var int
	 */
	const CLIENT_SCHEMA_VERSION = 3;

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'client';

	/**
	 * Request id header name.
	 *
	 * @var string
	 */
	protected $request_id_header = 'X-Request-Id';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'rest_post_dispatch', array( $this, 'append_request_id_header' ), 10, 3 );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$secrets = function_exists( 'wptsall_get_active_client_route_secrets' )
			? wptsall_get_active_client_route_secrets()
			: array( function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '' );
		if ( empty( $secrets ) ) {
			$secrets = array( '' );
		}

		foreach ( $secrets as $secret ) {
			$secret_prefix = '' !== (string) $secret ? (string) $secret . '/' : '';
			$base          = $secret_prefix . $this->rest_base;

		register_rest_route(
			$this->namespace,
			'/' . $base . '/ping',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $base . '/tasks',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tasks' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
				'args'                => array(
					'status' => array(
						'type'              => 'string',
						'default'           => 'pending',
						'sanitize_callback' => 'sanitize_key',
					),
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Legacy /tasks/{id}/status and /result removed (PRE-RELEASE-SINGLE-TRUTH).
		// Canonical write-back: /translation-callback only.

		} // end foreach secrets
	}

	/**
	 * Append request id header for client routes.
	 *
	 * @param mixed             $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return mixed
	 */
	public function append_request_id_header( $response, $server, $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) ) {
			return $response;
		}

		$secrets = function_exists( 'wptsall_get_active_client_route_secrets' )
			? wptsall_get_active_client_route_secrets()
			: array( function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '' );
		$route = (string) $request->get_route();
		$matched = false;
		foreach ( $secrets as $secret ) {
			$secret_prefix = '' !== (string) $secret ? (string) $secret . '/' : '';
			$route_prefix  = '/' . $this->namespace . '/' . $secret_prefix . $this->rest_base;
			if ( 0 === strpos( $route, $route_prefix ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			return $response;
		}

		$request_id = $this->resolve_request_id( $request );

		if ( $response instanceof \WP_REST_Response ) {
			$response->header( $this->request_id_header, $request_id );
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			foreach ( $response->get_error_codes() as $code ) {
				$data = $response->get_error_data( $code );
				if ( ! is_array( $data ) ) {
					$data = array( 'status' => (int) $data );
				}
				$data['request_id'] = $request_id;
				$response->add_data( $data, $code );
			}
		}

		return $response;
	}

	/**
	 * Check client auth and Pro feature gate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function check_client_permission( $request ) {
		// Pro gate removed: free users can access client task REST.
		// Only official template download requires Pro (enforced server-side).

		// Protocol version negotiation: clients that send X-WPTSALL-Protocol-Version
		// must declare a version this plugin understands.
		if ( function_exists( 'wptsall_check_client_protocol_version' ) ) {
			$proto = wptsall_check_client_protocol_version( $request );
			if ( is_wp_error( $proto ) ) {
				return $proto;
			}
		}

		$token = (string) $request->get_header( 'X-WPTSALL-Client-Token' );
		$device = sanitize_key( (string) $request->get_header( 'X-WPTSALL-Device-Id' ) );
		if ( empty( $token ) || ! function_exists( 'wptsall_client_token_service' ) ) {
			return new \WP_Error(
				'client_unauthorized',
				__( 'Client authentication failed', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}
		if ( '' === $device ) {
			return new \WP_Error(
				'client_device_required',
				__( 'Protocol v2 requests must declare a device id.', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}
		if ( ! wptsall_client_token_service()->verify_client_token( $token, $device, true ) ) {
			return new \WP_Error(
				'client_unauthorized',
				__( 'Client authentication failed', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Ping endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	public function ping() {
		$task_params = function_exists( 'wptsall_get_task_parameters' ) ? wptsall_get_task_parameters() : array();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'plugin_version'         => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '',
					'encryption_supported'   => true,
					'sync_execution_mode'    => $task_params['sync_execution_mode'] ?? 'client',
					'local_executor_enabled' => (bool) ( $task_params['local_executor_enabled'] ?? false ),
				),
			)
		);
	}

	/**
	 * Get pending/retry tasks for client execution.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_tasks( $request ) {
		// Auto-discover i18n tasks from pending template entries.
		$this->auto_discover_i18n_tasks();

		global $wpdb;

		$table  = wptsall_table( 'tasks' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$limit  = (int) $request->get_param( 'limit' );
		$limit  = max( 1, min( 100, $limit ) );

		if ( ! in_array( $status, array( 'pending', 'retry' ), true ) ) {
			$status = 'pending';
		}

		$worker_fingerprint = $this->get_client_worker_fingerprint( $request );
		$lease_seconds      = $this->get_claim_lease_seconds();
		$now_ts             = time();
		$now_mysql          = current_time( 'mysql', true );
		$claimed_ids = array();

		// Step 1: find claimable tasks in FIFO order.
		// Include processing tasks with expired lease for reclaim.
		$candidate_limit = max( 20, min( 1000, $limit * 8 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$candidate_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, status, meta, updated_at
					 FROM %i
					 WHERE status IN (%s, %s, %s, %s)
					 AND type != %s
					 ORDER BY created_at ASC
					 LIMIT %d',
					$table,
					'pending',
					'retry',
					'processing',
					'active',
					'monitoring',
					$candidate_limit
				),
				ARRAY_A
			);

		foreach ( (array) $candidate_rows as $row ) {
			if ( count( $claimed_ids ) >= $limit ) {
				break;
			}

			$task_id       = (int) ( $row['id'] ?? 0 );
			$current_status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$row_updated_at = sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) );
			if ( $task_id <= 0 ) {
				continue;
			}
			if ( ! in_array( $current_status, array( 'pending', 'retry', 'processing', 'active' ), true ) ) {
				continue;
			}
			// Respect requested status for new claims, but still allow expired processing reclaim.
			if ( in_array( $current_status, array( 'pending', 'retry' ), true ) && $current_status !== $status ) {
				continue;
			}

			$meta_data = json_decode( (string) ( $row['meta'] ?? '' ), true );
			if ( ! is_array( $meta_data ) ) {
				$meta_data = array();
			}

			$is_reclaim = false;
			if ( in_array( $current_status, array( 'processing', 'active' ), true ) ) {
				$claim = $this->get_client_claim_meta( $meta_data );
				if ( empty( $claim ) || $this->is_client_claim_expired( $claim, $now_ts ) ) {
					$is_reclaim = true;
				} else {
					continue;
				}
			}

			$claim_id = wp_generate_uuid4();
			$meta_data['client_claim'] = array(
				'claim_id'            => $claim_id,
				'worker_id'           => $worker_fingerprint,
				'worker_fingerprint'  => $worker_fingerprint,
				'claimed_at'          => $now_mysql,
				'lease_seconds'       => $lease_seconds,
				'lease_until_ts'      => $now_ts + $lease_seconds,
				'lease_until'         => gmdate( 'Y-m-d H:i:s', $now_ts + $lease_seconds ),
				'claimed_from_status' => $current_status,
				'is_reclaim'          => $is_reclaim,
			);
			if ( $is_reclaim ) {
				$meta_data['recovery_count'] = (int) ( $meta_data['recovery_count'] ?? 0 ) + 1;
			}
			$this->append_client_status_history(
				$meta_data,
				array(
					'source'      => 'client_claim',
					'status'      => 'processing',
					'progress'    => 0,
					'message'     => $is_reclaim ? 'claim_recovered' : 'claim_new',
					'retry_count' => 0,
					'at'          => $now_mysql,
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$where = array(
				'id'     => $task_id,
				'status' => $current_status,
			);
			$where_format = array( '%d', '%s' );
			if ( '' !== $row_updated_at ) {
				$where['updated_at'] = $row_updated_at;
				$where_format[]      = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$table,
				array(
					'status'      => 'processing',
					'status_note' => $is_reclaim
						? sprintf(
							/* translators: 1: datetime, 2: claim id */
							__( 'Recovered by client at %1$s (%2$s)', 'wpmmcc-ats' ),
							$now_mysql,
							$claim_id
						)
						: sprintf(
							/* translators: 1: datetime, 2: claim id */
							__( 'Claimed by client at %1$s (%2$s)', 'wpmmcc-ats' ),
							$now_mysql,
							$claim_id
						),
					'meta'        => wp_json_encode( $meta_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'updated_at'  => $now_mysql,
				),
				$where,
				array( '%s', '%s', '%s', '%s' ),
				$where_format
			);

			if ( $updated ) {
				$claimed_ids[] = $task_id;
			}
		}

		if ( empty( $claimed_ids ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'schema_version' => self::CLIENT_SCHEMA_VERSION,
						'items'          => array(),
					),
				)
			);
		}

		list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $claimed_ids );

		$rows = wptsall_db_get_results(
			"SELECT id, type, blog_id, target_blog, target_type, target_identifier, site_id, template, object_type, subtype, object_id, lang_from, lang_to, priority, retry_count, updated_at, payload, created_at
				 FROM %i
				 WHERE id IN ($in_sql)
				 ORDER BY created_at ASC",
			array_merge( array( $table ), $in_args ),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
			$payload = $this->normalize_client_task_payload( $payload, $row );

				$items[] = array(
					'task_id'     => (int) $row['id'],
					'type'        => sanitize_key( (string) ( $row['type'] ?? 'sync' ) ),
					'job_id'      => sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) ),
					'business_line' => sanitize_key( (string) ( $payload['business_line'] ?? 'custom_model' ) ),
					'task_type'   => sanitize_key( (string) ( $payload['task_type'] ?? 'text' ) ),
					'object_type' => sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
					'subtype'     => sanitize_key( (string) ( $row['subtype'] ?? '' ) ),
				'object_id'   => (int) ( $row['object_id'] ?? 0 ),
				'relation_id' => (int) ( $row['site_id'] ?? 0 ),
				'target_type' => sanitize_key( (string) ( $row['target_type'] ?? '' ) ),
				'source_lang' => sanitize_text_field( (string) ( $row['lang_from'] ?? '' ) ),
				'target_lang' => sanitize_text_field( (string) ( $row['lang_to'] ?? '' ) ),
				'priority'    => sanitize_key( (string) ( $row['priority'] ?? 'normal' ) ),
				'retry_count' => (int) ( $row['retry_count'] ?? 0 ),
				'template'    => sanitize_text_field( (string) ( $row['template'] ?? '' ) ),
				'updated_at'  => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
				'payload'     => $payload,
				'created_at'  => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'schema_version' => self::CLIENT_SCHEMA_VERSION,
					'items'          => $items,
				),
			)
		);
	}

	/**
	 * Update task status from client.
	 *
	 * @deprecated Use /client/translation-callback instead.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_task_status( $request ) {
		wptsall_log_warning( 'tasks', 'Deprecated endpoint called: update_task_status. Use /client/translation-callback instead.' );
		global $wpdb;

		$table    = wptsall_table( 'tasks' );
		$task_id  = absint( $request->get_param( 'id' ) );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$progress = (int) $request->get_param( 'progress' );
		$message  = sanitize_text_field( (string) ( $request->get_param( 'message' ) ?? '' ) );

		if ( ! in_array( $status, array( 'processing', 'retry', 'failed' ), true ) ) {
			return new \WP_Error(
				'invalid_status',
				__( 'Invalid status', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$progress = max( 0, min( 100, $progress ) );
		$now      = current_time( 'mysql', true );

		// Ensure task exists before update.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, status, retry_count, payload, meta, updated_at FROM %i WHERE id = %d', $table, $task_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new \WP_Error(
				'task_not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$retry_count = (int) ( $row['retry_count'] ?? 0 );
		$meta_data   = json_decode( (string) ( $row['meta'] ?? '' ), true );
		if ( ! is_array( $meta_data ) ) {
			$meta_data = array();
		}
		$idempotency_key = $this->parse_client_idempotency_key( $request );
		if ( is_wp_error( $idempotency_key ) ) {
			return $idempotency_key;
		}
		$status_fingerprint = $this->build_client_idempotency_fingerprint(
			array(
				'task_id'  => $task_id,
				'status'   => $status,
				'progress' => $progress,
				'message'  => $message,
			)
		);
		if ( '' !== $idempotency_key ) {
			$idem_record = $this->get_client_idempotency_record( $meta_data, 'status', $idempotency_key );
			if ( ! empty( $idem_record ) ) {
				$record_fingerprint = (string) ( $idem_record['fingerprint'] ?? '' );
				if ( '' !== $record_fingerprint && ! hash_equals( $record_fingerprint, $status_fingerprint ) ) {
					return new \WP_Error(
						'task_status_idempotency_conflict',
						__( 'Request conflict: same idempotency key maps to different status payload', 'wpmmcc-ats' ),
						array( 'status' => 409 )
					);
				}
				$response_data = is_array( $idem_record['response'] ?? null ) ? $idem_record['response'] : array(
					'task_id'        => $task_id,
					'status'         => $status,
					'schema_version' => self::CLIENT_SCHEMA_VERSION,
				);
				$response_data['idempotent'] = true;
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $response_data,
					)
				);
			}
		}
		$current_status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		$row_updated_at = sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) );
		if ( $this->is_client_terminal_task_status( $current_status ) ) {
			return $this->build_client_terminal_task_conflict_error(
				'task_status_conflict',
				__( 'Task is in terminal state, client cannot update status', 'wpmmcc-ats' ),
				$current_status
			);
		}
		$worker_fingerprint = $this->get_client_worker_fingerprint( $request );
		$claim_meta         = $this->get_client_claim_meta( $meta_data );
		$now_ts             = time();
		if ( ! $this->can_worker_operate_claim( $claim_meta, $worker_fingerprint, $now_ts ) ) {
			return new \WP_Error(
				'task_status_conflict',
				__( 'Task is occupied by another client, please retry later', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}

		if ( in_array( $status, array( 'retry', 'failed' ), true ) ) {
			++$retry_count;
		}

		$progress_data = array(
			'status'      => $status,
			'progress'    => $progress,
			'message'     => $message,
			'retry_count' => $retry_count,
			'updated_at'  => $now,
		);

		if ( 'retry' === $status ) {
			$meta_data['last_retry'] = array(
				'at'          => $now,
				'message'     => $message,
				'progress'    => $progress,
				'retry_count' => $retry_count,
				'source'      => 'client_status',
			);
		} elseif ( 'failed' === $status ) {
			$meta_data['last_failed'] = array(
				'at'          => $now,
				'message'     => $message,
				'progress'    => $progress,
				'retry_count' => $retry_count,
				'source'      => 'client_status',
			);
		}
		$meta_data['client_last_status'] = array(
			'status'      => $status,
			'progress'    => $progress,
			'message'     => $message,
			'retry_count' => $retry_count,
			'at'          => $now,
		);
		$callback_error_code = $this->extract_callback_failed_error_code( $message );
		if ( '' !== $callback_error_code && in_array( $status, array( 'retry', 'failed' ), true ) ) {
			if ( ! isset( $meta_data['client_result_envelope'] ) || ! is_array( $meta_data['client_result_envelope'] ) ) {
				$meta_data['client_result_envelope'] = array(
					'schema_version' => self::CLIENT_SCHEMA_VERSION,
				);
			}
			$meta_data['client_result_envelope']['final_status']   = $status;
			$meta_data['client_result_envelope']['retryable']      = 'retry' === $status;
			$meta_data['client_result_envelope']['error_category'] = 'callback_failed';
			$meta_data['client_result_envelope']['error_code']     = $callback_error_code;
			$meta_data['client_result_envelope']['error_message']  = $message;
			$meta_data['client_result_last_error'] = array(
				'category'    => 'callback_failed',
				'code'        => $callback_error_code,
				'message'     => $message,
				'retryable'   => 'retry' === $status,
				'received_at' => $now,
			);
		}
		if ( 'processing' === $status ) {
			$lease_seconds = $this->get_claim_lease_seconds();
			if ( empty( $claim_meta ) ) {
				$claim_meta = array(
					'claim_id'           => wp_generate_uuid4(),
					'worker_id'          => $worker_fingerprint,
					'worker_fingerprint' => $worker_fingerprint,
					'claimed_at'         => $now,
				);
			}
			$claim_meta['worker_id']          = $worker_fingerprint;
			$claim_meta['worker_fingerprint'] = $worker_fingerprint;
			$claim_meta['last_heartbeat_at']  = $now;
			$claim_meta['lease_seconds']      = $lease_seconds;
			$claim_meta['lease_until_ts']     = $now_ts + $lease_seconds;
			$claim_meta['lease_until']        = gmdate( 'Y-m-d H:i:s', $now_ts + $lease_seconds );
			$meta_data['client_claim']        = $claim_meta;
		} else {
			if ( ! empty( $claim_meta ) ) {
				$claim_meta['released_at']      = $now;
				$claim_meta['released_reason']  = $status;
				$meta_data['client_claim_last'] = $claim_meta;
			}
			unset( $meta_data['client_claim'] );
		}
		$this->append_client_status_history(
			$meta_data,
			array(
				'source'      => 'client_status',
				'status'      => $status,
				'progress'    => $progress,
				'message'     => $message,
				'retry_count' => $retry_count,
				'at'          => $now,
			)
		);
		$response_data = array(
			'task_id'        => $task_id,
			'status'         => $status,
			'schema_version' => self::CLIENT_SCHEMA_VERSION,
		);
		if ( '' !== $callback_error_code ) {
			$response_data['result_error_category'] = 'callback_failed';
			$response_data['result_error_code']     = $callback_error_code;
		}
		if ( '' !== $idempotency_key ) {
			$this->remember_client_idempotency_record(
				$meta_data,
				'status',
				$idempotency_key,
				$status_fingerprint,
				$response_data
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$where = array(
			'id'     => $task_id,
			'status' => $current_status,
		);
		$where_format = array( '%d', '%s' );
		if ( '' !== $row_updated_at ) {
			$where['updated_at'] = $row_updated_at;
			$where_format[]      = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'status'      => $status,
				'status_note' => $message,
				'retry_count' => $retry_count,
				'progress'    => wp_json_encode( $progress_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'meta'        => wp_json_encode( $meta_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at'  => $now,
			),
			$where,
			array( '%s', '%s', '%d', '%s', '%s', '%s' ),
			$where_format
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'task_update_failed',
				__( 'Task status update failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}
		if ( 0 === (int) $updated ) {
			return new \WP_Error(
				'task_status_conflict',
				__( 'Task status has changed, please retry', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}
		$job_snapshot = $this->refresh_job_aggregate_snapshot( $table, $row, $status );
		if ( ! empty( $job_snapshot ) ) {
			$response_data['job'] = $job_snapshot;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $response_data,
			)
		);
	}

	/**
	 * Submit final task result from client.
	 *
	 * @deprecated Use /client/translation-callback instead.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit_task_result( $request ) {
		wptsall_log_warning( 'tasks', 'Deprecated endpoint called: submit_task_result. Use /client/translation-callback instead.' );

		global $wpdb;

		$table   = wptsall_table( 'tasks' );
		$task_id = absint( $request->get_param( 'id' ) );
		$params  = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$status = sanitize_key( (string) ( $params['status'] ?? 'completed' ) );
		if ( ! in_array( $status, array( 'completed', 'failed' ), true ) ) {
			$status = 'completed';
		}

		$result = $params['result'] ?? array();
		$meta   = $params['meta'] ?? array();
		if ( ! is_array( $result ) ) {
			$result = array( 'value' => $result );
		}
		if ( ! is_array( $meta ) ) {
			$meta = array( 'value' => $meta );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, blog_id, target_blog, target_type, target_identifier, site_id, site_mode, template, object_type, subtype, object_id, lang_from, lang_to, retry_count, status, payload, meta, updated_at
				 FROM %i
				 WHERE id = %d',
				$table,
				$task_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return new \WP_Error(
				'task_not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$meta_data = json_decode( (string) ( $row['meta'] ?? '' ), true );
		if ( ! is_array( $meta_data ) ) {
			$meta_data = array();
		}
		$row_payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $row_payload ) ) {
			$row_payload = array();
		}
		$payload_dirty = false;
		$idempotency_key = $this->parse_client_idempotency_key( $request );
		if ( is_wp_error( $idempotency_key ) ) {
			return $idempotency_key;
		}
		$result_fingerprint = $this->build_client_idempotency_fingerprint(
			array(
				'task_id' => $task_id,
				'status'  => $status,
				'result'  => $result,
				'meta'    => $meta,
			)
		);
		if ( '' !== $idempotency_key ) {
			$idem_record = $this->get_client_idempotency_record( $meta_data, 'result', $idempotency_key );
			if ( ! empty( $idem_record ) ) {
				$record_fingerprint = (string) ( $idem_record['fingerprint'] ?? '' );
				if ( '' !== $record_fingerprint && ! hash_equals( $record_fingerprint, $result_fingerprint ) ) {
					return new \WP_Error(
						'task_result_idempotency_conflict',
						__( 'Request conflict: same idempotency key maps to different result payload', 'wpmmcc-ats' ),
						array( 'status' => 409 )
					);
				}
				$response_data = is_array( $idem_record['response'] ?? null ) ? $idem_record['response'] : array(
					'task_id'        => $task_id,
					'status'         => $status,
					'target_id'      => 0,
					'schema_version' => self::CLIENT_SCHEMA_VERSION,
				);
				$response_data['idempotent'] = true;
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $response_data,
					)
				);
			}
		}
		$current_status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		$row_updated_at = sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) );
		if ( $this->is_client_terminal_task_status( $current_status ) ) {
			$stored_result_fingerprint = $this->extract_client_result_fingerprint( $meta_data );
			if ( '' !== $stored_result_fingerprint && hash_equals( $stored_result_fingerprint, $result_fingerprint ) ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => array(
							'task_id'        => $task_id,
							'status'         => $current_status,
							'schema_version' => self::CLIENT_SCHEMA_VERSION,
							'idempotent'     => true,
						),
					)
				);
			}
			if ( 'completed' === $current_status && 'completed' === $status && '' === $stored_result_fingerprint ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => array(
							'task_id'        => $task_id,
							'status'         => 'completed',
							'schema_version' => self::CLIENT_SCHEMA_VERSION,
							'idempotent'     => true,
						),
					)
				);
			}
			return $this->build_client_terminal_task_conflict_error(
				'task_result_conflict',
				__( 'Task is in terminal state, client cannot submit results', 'wpmmcc-ats' ),
				$current_status
			);
		}
		$worker_fingerprint = $this->get_client_worker_fingerprint( $request );
		$claim_meta         = $this->get_client_claim_meta( $meta_data );
		if ( ! $this->can_worker_operate_claim( $claim_meta, $worker_fingerprint, time() ) ) {
			return new \WP_Error(
				'task_result_conflict',
				__( 'Task is occupied by another client, please retry later', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}
		$meta_data['client_result'] = $result;
		$meta_data['client_meta']   = $meta;
		$meta_data['completed_at']  = current_time( 'mysql', true );
		$meta_data['client_result_aggregate'] = $this->build_client_result_aggregate( $result );
		$meta_data['client_result_envelope']  = array(
			'schema_version' => self::CLIENT_SCHEMA_VERSION,
			'status'         => $status,
			'result_fingerprint' => $result_fingerprint,
			'component_id'   => sanitize_text_field( (string) ( $meta['component_id'] ?? '' ) ),
			'elapsed_ms'     => isset( $meta['elapsed_ms'] ) ? (int) $meta['elapsed_ms'] : 0,
			'received_at'    => current_time( 'mysql', true ),
			'aggregate'      => $meta_data['client_result_aggregate'],
		);
		$this->append_client_status_history(
			$meta_data,
			array(
				'source'      => 'client_result',
				'status'      => $status,
				'progress'    => 100,
				'message'     => 'completed' === $status ? 'result received' : 'result failed',
				'retry_count' => (int) ( $row['retry_count'] ?? 0 ),
				'at'          => current_time( 'mysql', true ),
			)
		);

		$target_id              = 0;
		$status_note            = 'completed' === $status
			? __( 'Client has submitted results', 'wpmmcc-ats' )
			: __( 'Client submission failed', 'wpmmcc-ats' );
		$retry_count            = (int) ( $row['retry_count'] ?? 0 );
		$result_error_category  = '';
		$result_error_code      = '';
		$result_error_message   = '';
		$client_retryable       = ! array_key_exists( 'retryable', $meta ) || (bool) $meta['retryable'];
		$client_error_code_raw  = (string) ( $result['error_code'] ?? ( $meta['error_code'] ?? '' ) );
		$client_error_msg_raw   = (string) ( $result['error_message'] ?? ( $meta['error_message'] ?? '' ) );
		$client_error_message   = sanitize_text_field( $client_error_msg_raw );

		if ( 'completed' === $status ) {
			$sync_result = $this->apply_client_result_to_target( $row, $result, $meta_data );
			if ( is_wp_error( $sync_result ) ) {
				$sync_error_data = $sync_result->get_error_data();
				$retry_fragment_keys = array();
				if ( is_array( $sync_error_data['retry_fragment_keys'] ?? null ) ) {
					foreach ( $sync_error_data['retry_fragment_keys'] as $raw_fragment_key ) {
						$fragment_key = sanitize_text_field( (string) $raw_fragment_key );
						if ( '' !== $fragment_key ) {
							$retry_fragment_keys[] = $fragment_key;
						}
					}
					$retry_fragment_keys = array_values( array_unique( $retry_fragment_keys ) );
				}
					if ( ! empty( $retry_fragment_keys ) ) {
						$retry_filter_limit   = 200;
						$existing_retry_filter = is_array( $row_payload['manual_retry_only_subtasks'] ?? null )
							? $row_payload['manual_retry_only_subtasks']
							: array();
					$existing_retry_filter = array_values(
						array_filter(
							array_map(
								static function ( $value ) {
									return sanitize_text_field( (string) $value );
								},
								$existing_retry_filter
							),
							static function ( $value ) {
								return '' !== $value;
							}
						)
					);
						$next_retry_filter = array_values(
							array_unique( array_merge( $existing_retry_filter, $retry_fragment_keys ) )
						);
						if ( count( $next_retry_filter ) > $retry_filter_limit ) {
							$next_retry_filter = array_slice( $next_retry_filter, -1 * $retry_filter_limit );
						}
						$row_payload['manual_retry_only_subtasks'] = $next_retry_filter;
						$payload_dirty = true;
					}
				$status                = 'retry';
				$result_error_category = 'patch_apply_failed';
				$result_error_code     = $this->normalize_client_error_code(
					$sync_result->get_error_code(),
					'PATCH_APPLY_FAILED_UNKNOWN'
				);
				if ( 0 !== strpos( $result_error_code, 'PATCH_APPLY_FAILED_' ) ) {
					$result_error_code = 'PATCH_APPLY_FAILED_' . $result_error_code;
				}
				$result_error_message = sanitize_text_field( $sync_result->get_error_message() );
				$status_note          = sprintf(
					'%s: %s',
					$result_error_code,
					$result_error_message ? $result_error_message : __( 'Write-back failed', 'wpmmcc-ats' )
				);
				$retry_count++;
			} else {
				$target_id                  = (int) ( $sync_result['target_id'] ?? 0 );
				$meta_data['sync_result']   = $sync_result;
				$meta_data['patch_summary'] = is_array( $sync_result['patch_summary'] ?? null )
					? $sync_result['patch_summary']
					: array();
				if ( isset( $row_payload['manual_retry_only_subtasks'] ) ) {
					unset( $row_payload['manual_retry_only_subtasks'] );
					$payload_dirty = true;
				}
				// For i18n tasks, results are already written to template_entries.
				// Remove the full client_result from meta to keep it lightweight.
				$object_type = sanitize_key( (string) ( $row['object_type'] ?? '' ) );
				if ( 'language_pack' === $object_type ) {
					unset( $meta_data['client_result'] );
				}
			}
		} elseif ( 'failed' === $status ) {
			$result_error_category = 'execution_failed';
			$result_error_code     = $this->normalize_client_error_code(
				$client_error_code_raw,
				'EXECUTION_FAILED_UNKNOWN'
			);
			if ( 0 !== strpos( $result_error_code, 'EXECUTION_FAILED_' ) ) {
				$result_error_code = 'EXECUTION_FAILED_' . $result_error_code;
			}
			$result_error_message = '' !== $client_error_message
				? $client_error_message
				: __( 'Client execution failed', 'wpmmcc-ats' );
			$status               = $client_retryable ? 'retry' : 'failed';
			if ( in_array( $status, array( 'retry', 'failed' ), true ) ) {
				$retry_count++;
			}
			$status_note = sprintf(
				'%s: %s',
				$result_error_code,
				$result_error_message
			);
		}

		$meta_data['client_result_envelope']['final_status']    = $status;
		$meta_data['client_result_envelope']['retryable']       = (bool) $client_retryable;
		$meta_data['client_result_envelope']['error_category']  = $result_error_category;
		$meta_data['client_result_envelope']['error_code']      = $result_error_code;
		$meta_data['client_result_envelope']['error_message']   = $result_error_message;
		if ( '' !== $result_error_category ) {
			$meta_data['client_result_last_error'] = array(
				'category'   => $result_error_category,
				'code'       => $result_error_code,
				'message'    => $result_error_message,
				'retryable'  => (bool) $client_retryable,
				'received_at'=> current_time( 'mysql', true ),
			);
		} else {
			unset( $meta_data['client_result_last_error'] );
		}

		$status_note = 'completed' === $status
			? ( $status_note ?: __( 'Client has submitted results', 'wpmmcc-ats' ) )
			: ( $status_note ?: __( 'Client submission failed', 'wpmmcc-ats' ) );
		if ( ! empty( $claim_meta ) ) {
			$claim_meta['released_at']      = current_time( 'mysql', true );
			$claim_meta['released_reason']  = 'result_' . $status;
			$meta_data['client_claim_last'] = $claim_meta;
		}
		unset( $meta_data['client_claim'] );
		$response_data = array(
			'task_id'               => $task_id,
			'status'                => $status,
			'target_id'             => $target_id,
			'result_error_category' => $result_error_category,
			'result_error_code'     => $result_error_code,
			'schema_version'        => self::CLIENT_SCHEMA_VERSION,
		);
		if ( '' !== $idempotency_key ) {
			$this->remember_client_idempotency_record(
				$meta_data,
				'result',
				$idempotency_key,
				$result_fingerprint,
				$response_data
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$where = array(
			'id'     => $task_id,
			'status' => $current_status,
		);
		$where_format = array( '%d', '%s' );
		if ( '' !== $row_updated_at ) {
			$where['updated_at'] = $row_updated_at;
			$where_format[]      = '%s';
		}

		$update_data = array(
			'status'      => $status,
			'status_note' => $status_note,
			'retry_count' => $retry_count,
			'meta'        => wp_json_encode( $meta_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'updated_at'  => current_time( 'mysql', true ),
		);
		$update_format = array( '%s', '%s', '%d', '%s', '%s' );
		if ( $payload_dirty ) {
			$update_data['payload'] = wp_json_encode( $row_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$update_format[]        = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			$update_data,
			$where,
			$update_format,
			$where_format
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'task_result_update_failed',
				__( 'Task result write failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}
		if ( 0 === (int) $updated ) {
			return new \WP_Error(
				'task_result_conflict',
				__( 'Task result submission conflict, please retry', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}
		$job_snapshot = $this->refresh_job_aggregate_snapshot( $table, $row, $status );
		if ( ! empty( $job_snapshot ) ) {
			$response_data['job'] = $job_snapshot;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $response_data,
			)
		);
	}

	/**
	 * Apply client translated fields and execute sync to target.
	 *
	 * @param array $row       Task row.
	 * @param array $result    Client result.
	 * @param array $meta_data Task meta (by ref).
	 * @return array|\WP_Error
	 */
	private function apply_client_result_to_target( $row, $result, &$meta_data = array() ) {
		$task            = $this->build_task_data_from_row( $row );
		$patch_context   = $this->normalize_client_result_patch( $result );
		$patch_key       = (string) ( $patch_context['idempotency_key'] ?? '' );
		$patch_fingerprint = $this->build_client_idempotency_fingerprint( $patch_context['fragments'] ?? array() );
		if ( '' !== $patch_key ) {
			$patch_record = $this->get_client_idempotency_record( $meta_data, 'patch', $patch_key );
			if ( ! empty( $patch_record ) ) {
				$record_fingerprint = (string) ( $patch_record['fingerprint'] ?? '' );
				if ( '' !== $record_fingerprint && ! hash_equals( $record_fingerprint, $patch_fingerprint ) ) {
					return new \WP_Error(
						'patch_idempotency_conflict',
						__( 'Patch request conflict: same idempotency key maps to different patch payload', 'wpmmcc-ats' ),
						array( 'status' => 409 )
					);
				}
				$record_response = is_array( $patch_record['response'] ?? null ) ? $patch_record['response'] : array();
				if ( ! empty( $record_response ) ) {
					$record_response['idempotent_patch'] = true;
					return $record_response;
				}
			}
		}

		// i18n task shortcut: when object_type is 'language_pack', write results
		// back to template_entries instead of the normal content sync path.
		$object_type = sanitize_key( (string) ( $row['object_type'] ?? '' ) );
		if ( 'language_pack' === $object_type ) {
			return $this->apply_i18n_result_to_template_entries( $row, $result, $meta_data );
		}

		$subtask_summary = $this->summarize_client_subtasks( $result );
		$translated_fields = $this->normalize_translated_fields( $result );
		if ( empty( $translated_fields ) && ! empty( $patch_context['translated_fields'] ) ) {
			$translated_fields = $patch_context['translated_fields'];
		}
		$patch_has_completed = (int) ( $patch_context['summary']['completed'] ?? 0 ) > 0;
		if ( empty( $translated_fields ) && ! $patch_has_completed ) {
			if ( $subtask_summary['total'] > 0 && ( $subtask_summary['completed'] + $subtask_summary['skipped'] ) > 0 ) {
				if ( is_array( $meta_data ) ) {
					if ( ! isset( $meta_data['client_patch_manual_queue'] ) || ! is_array( $meta_data['client_patch_manual_queue'] ) ) {
						$meta_data['client_patch_manual_queue'] = array();
					}
					$meta_data['client_patch_manual_queue'][] = array(
						'reason'        => 'non_text_without_target_mapping',
						'subtasks'      => $subtask_summary,
						'patch_summary' => is_array( $patch_context['summary'] ?? null ) ? $patch_context['summary'] : array(),
						'recorded_at'   => current_time( 'mysql', true ),
					);
					if ( count( $meta_data['client_patch_manual_queue'] ) > 50 ) {
						$meta_data['client_patch_manual_queue'] = array_slice( $meta_data['client_patch_manual_queue'], -50 );
					}
				}
				$retry_fragment_keys = $this->collect_fragment_keys_from_fragments(
					is_array( $patch_context['fragments'] ?? null ) ? $patch_context['fragments'] : array()
				);
				return new \WP_Error(
					'patch_apply_pending_manual_review',
					__( 'Non-text result recorded but missing applicable target path mapping', 'wpmmcc-ats' ),
					array(
						'status' => 409,
						'retry_fragment_keys' => $retry_fragment_keys,
					)
				);
			}
			return new \WP_Error(
				'task_result_invalid',
				__( 'Client result missing usable translation fields', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$source_blog = (int) ( $task['blog_id'] ?? get_current_blog_id() );
		$switched    = false;
		if ( $source_blog > 0 && is_multisite() && $source_blog !== get_current_blog_id() ) {
			switch_to_blog( $source_blog );
			$switched = true;
		}

		$complete_data = wptsall_get_complete_object_data(
			$task['object_type'],
			$task['subtype'],
			(int) $task['object_id']
		);

		if ( $switched ) {
			restore_current_blog();
		}

		if ( empty( $complete_data ) || ! is_array( $complete_data ) ) {
			return new \WP_Error(
				'task_source_data_missing',
				__( 'Unable to retrieve source object data', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		$complete_data = $this->patch_complete_data_with_translations( $task, $complete_data, $translated_fields );
		$patch_apply   = $this->apply_patch_fragments_to_complete_data(
			$task,
			$complete_data,
			$patch_context['fragments']
		);
		if ( is_wp_error( $patch_apply ) ) {
			return $patch_apply;
		}
		$complete_data = $patch_apply['complete_data'];
		$patch_summary = $patch_apply['summary'];
		if ( is_array( $meta_data ) ) {
			$meta_data['client_patch'] = array(
				'idempotency_key' => (string) ( $patch_context['idempotency_key'] ?? '' ),
				'summary'         => $patch_summary,
				'applied_at'      => current_time( 'mysql', true ),
			);
			if ( (int) ( $patch_summary['unapplied'] ?? 0 ) > 0 ) {
				if ( ! isset( $meta_data['client_patch_manual_queue'] ) || ! is_array( $meta_data['client_patch_manual_queue'] ) ) {
					$meta_data['client_patch_manual_queue'] = array();
				}
				$meta_data['client_patch_manual_queue'][] = array(
					'reason'              => 'patch_fragment_unapplied',
					'unapplied'           => (int) ( $patch_summary['unapplied'] ?? 0 ),
					'unapplied_fragments' => is_array( $patch_summary['unapplied_fragments'] ?? null ) ? $patch_summary['unapplied_fragments'] : array(),
					'recorded_at'         => current_time( 'mysql', true ),
				);
				if ( count( $meta_data['client_patch_manual_queue'] ) > 50 ) {
					$meta_data['client_patch_manual_queue'] = array_slice( $meta_data['client_patch_manual_queue'], -50 );
				}
			}
			if ( (int) ( $patch_summary['recorded_only'] ?? 0 ) > 0 ) {
				if ( ! isset( $meta_data['client_patch_manual_queue'] ) || ! is_array( $meta_data['client_patch_manual_queue'] ) ) {
					$meta_data['client_patch_manual_queue'] = array();
				}
				$meta_data['client_patch_manual_queue'][] = array(
					'reason'              => 'patch_fragment_recorded_only',
					'recorded_only'       => (int) ( $patch_summary['recorded_only'] ?? 0 ),
					'unapplied_fragments' => is_array( $patch_summary['recorded_only_fragments'] ?? null ) ? $patch_summary['recorded_only_fragments'] : array(),
					'recorded_at'         => current_time( 'mysql', true ),
				);
				if ( count( $meta_data['client_patch_manual_queue'] ) > 50 ) {
					$meta_data['client_patch_manual_queue'] = array_slice( $meta_data['client_patch_manual_queue'], -50 );
				}
			}
		}
		if ( (int) ( $patch_summary['completed'] ?? 0 ) > 0 && (int) ( $patch_summary['applied_business'] ?? 0 ) <= 0 ) {
			$retry_fragment_keys = $this->collect_patch_retry_fragment_keys( $patch_summary );
			return new \WP_Error(
				'patch_apply_pending_manual_review',
				__( 'Patch fragment recorded but failed to apply to target object field', 'wpmmcc-ats' ),
				array(
					'status' => 409,
					'retry_fragment_keys' => $retry_fragment_keys,
				)
			);
		}
		if ( (int) ( $patch_summary['recorded_only'] ?? 0 ) > 0 && (int) ( $patch_summary['applied_business'] ?? 0 ) <= 0 ) {
			$retry_fragment_keys = $this->collect_patch_retry_fragment_keys( $patch_summary );
			return new \WP_Error(
				'patch_apply_pending_manual_review',
				__( 'Non-text fragments exist that only recorded without applying to business fields, requires manual processing', 'wpmmcc-ats' ),
				array(
					'status' => 409,
					'retry_fragment_keys' => $retry_fragment_keys,
				)
			);
		}
		if ( is_array( $meta_data ) ) {
			$this->cleanup_manual_queue_after_patch_apply( $meta_data, $patch_summary );
		}
		$task['complete_data'] = $complete_data;

		$sync_result = wptsall_process_task( $task );
		if ( ! is_array( $sync_result ) || empty( $sync_result['success'] ) ) {
			$note = is_array( $sync_result ) ? (string) ( $sync_result['note'] ?? '' ) : '';
			return new \WP_Error(
				'task_apply_failed',
				$note ? $note : __( 'Client result application failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		// Dispatch non-text items through Write_Back_Dispatcher.
		$non_text_dispatch_items = $this->extract_non_text_dispatch_items( $result, $patch_context );
		$write_back_summary     = array();
		if ( ! empty( $non_text_dispatch_items ) ) {
			$dispatch_context = $this->build_write_back_dispatch_context( $task, $row );

			$write_back_summary = \WPTSALL\Tasks\Sync\Write_Back_Dispatcher::dispatch_batch(
				$non_text_dispatch_items,
				$dispatch_context
			);

			wptsall_log_info( 'tasks-sync', 'Client result non-text write-back dispatch completed', array(
				'task_id' => $task['id'] ?? 0,
				'total'   => $write_back_summary['total'] ?? 0,
				'applied' => $write_back_summary['applied'] ?? 0,
				'queued'  => $write_back_summary['queued'] ?? 0,
				'failed'  => $write_back_summary['failed'] ?? 0,
			) );

			if ( is_array( $meta_data ) ) {
				$meta_data['client_write_back'] = array(
					'total'   => $write_back_summary['total'] ?? 0,
					'applied' => $write_back_summary['applied'] ?? 0,
					'queued'  => $write_back_summary['queued'] ?? 0,
					'failed'  => $write_back_summary['failed'] ?? 0,
				);
			}
		}

		$response = array(
			'applied'       => true,
			'target_id'     => (int) ( $sync_result['target_id'] ?? 0 ),
			'note'          => (string) ( $sync_result['note'] ?? '' ),
			'subtasks'      => $subtask_summary,
			'patch_summary' => $patch_summary,
		);
		if ( ! empty( $write_back_summary ) ) {
			$response['write_back'] = $write_back_summary;
		}
		if ( '' !== $patch_key ) {
			$this->remember_client_idempotency_record(
				$meta_data,
				'patch',
				$patch_key,
				$patch_fingerprint,
				$response
			);
		}
		return $response;
	}

	/**
	 * Extract non-text items from client result for Write_Back_Dispatcher.
	 *
	 * Collects items from:
	 * 1. result['non_text_items'] (direct array matching Sync_Executor format)
	 * 2. Typed arrays: result['images'], result['videos'], result['audios'], result['documents']
	 * 3. Patch fragments with non-text type that have translated_ref data
	 *
	 * @param array $result        Client result payload.
	 * @param array $patch_context Normalized patch context from normalize_client_result_patch().
	 * @return array Items formatted for Write_Back_Dispatcher::dispatch_batch().
	 */
	private function extract_non_text_dispatch_items( $result, $patch_context ) {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$items = array();
		$seen  = array();

		// 1. Direct non_text_items array (same format as Sync_Executor expects).
		$direct_items = is_array( $result['non_text_items'] ?? null ) ? $result['non_text_items'] : array();
		foreach ( $direct_items as $item ) {
			if ( ! is_array( $item ) || empty( $item['entity_type'] ) ) {
				continue;
			}
			$dedupe_key = $this->build_non_text_item_dedupe_key( $item );
			if ( isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}
			$seen[ $dedupe_key ] = true;
			$items[] = $item;
		}

		// 2. Typed arrays: images, videos, audios, documents.
		$type_map = array(
			'images'    => 'image',
			'videos'    => 'video',
			'audios'    => 'audio',
			'documents' => 'document',
		);
		foreach ( $type_map as $list_key => $entity_type ) {
			$typed_items = is_array( $result[ $list_key ] ?? null ) ? $result[ $list_key ] : array();
			foreach ( $typed_items as $typed_item ) {
				if ( ! is_array( $typed_item ) ) {
					continue;
				}
				$item = $this->normalize_typed_non_text_item( $typed_item, $entity_type );
				if ( empty( $item ) ) {
					continue;
				}
				$dedupe_key = $this->build_non_text_item_dedupe_key( $item );
				if ( isset( $seen[ $dedupe_key ] ) ) {
					continue;
				}
				$seen[ $dedupe_key ] = true;
				$items[] = $item;
			}
		}

		// 3. Non-text patch fragments with translated_ref data.
		$fragments = is_array( $patch_context['fragments'] ?? null ) ? $patch_context['fragments'] : array();
		foreach ( $fragments as $fragment ) {
			if ( ! is_array( $fragment ) ) {
				continue;
			}
			if ( ! $this->is_non_text_patch_fragment( $fragment ) ) {
				continue;
			}
			if ( 'completed' !== ( $fragment['status'] ?? '' ) ) {
				continue;
			}
			$item = $this->build_dispatch_item_from_fragment( $fragment );
			if ( empty( $item ) ) {
				continue;
			}
			$dedupe_key = $this->build_non_text_item_dedupe_key( $item );
			if ( isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}
			$seen[ $dedupe_key ] = true;
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Build dispatch context for Write_Back_Dispatcher from task data.
	 *
	 * @param array $task Task data.
	 * @param array $row  Task DB row.
	 * @return array Context for Write_Back_Dispatcher::dispatch_batch().
	 */
	private function build_write_back_dispatch_context( $task, $row ) {
		global $wpdb;

		$context = array(
			'relation_id' => 0,
			'task_id'     => (int) ( $task['id'] ?? 0 ),
			'source_blog' => (int) ( $task['blog_id'] ?? get_current_blog_id() ),
			'target_blog' => (int) ( $task['target_blog'] ?? 0 ),
			'target_type' => sanitize_key( (string) ( $task['target_type'] ?? 'wp' ) ),
			'lang_to'     => sanitize_text_field( (string) ( $task['lang_to'] ?? '' ) ),
		);

		// Resolve relation_id from site_id or template.
		$site_id  = (int) ( $task['site_id'] ?? 0 );
		$template = sanitize_key( (string) ( $task['template'] ?? '' ) );

		if ( $site_id > 0 ) {
			$context['relation_id'] = $site_id;
		} elseif ( '' !== $template ) {
			$rel_table = wptsall_table( 'site_relations' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rel_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE template = %s LIMIT 1',
					$rel_table,
					$template
				)
			);
			if ( $rel_id ) {
				$context['relation_id'] = (int) $rel_id;
			}
		}

		return $context;
	}

	/**
	 * Normalize a typed non-text item into dispatch format.
	 *
	 * @param array  $item        Raw item from typed array (images/videos/audios/documents).
	 * @param string $entity_type The entity type (image, video, audio, document).
	 * @return array Normalized item for dispatch, or empty array if invalid.
	 */
	private function normalize_typed_non_text_item( $item, $entity_type ) {
		if ( ! is_array( $item ) ) {
			return array();
		}

		// Accept items already in dispatch format.
		if ( ! empty( $item['entity_type'] ) && ! empty( $item['translated_ref'] ) ) {
			return $item;
		}

		$source_id = (int) ( $item['source_id'] ?? ( $item['id'] ?? ( $item['attachment_id'] ?? 0 ) ) );

		// Build translated_ref from various possible structures.
		$translated_ref = is_array( $item['translated_ref'] ?? null ) ? $item['translated_ref'] : array();
		if ( empty( $translated_ref ) ) {
			$ref_type  = sanitize_key( (string) ( $item['ref_type'] ?? '' ) );
			$ref_value = $item['ref_value'] ?? ( $item['url'] ?? ( $item['path'] ?? '' ) );

			if ( '' === $ref_type && ! empty( $item['url'] ) ) {
				$ref_type  = 'url';
				$ref_value = $item['url'];
			} elseif ( '' === $ref_type && ! empty( $item['path'] ) ) {
				$ref_type  = 'path';
				$ref_value = $item['path'];
			} elseif ( '' === $ref_type && ! empty( $item['target_id'] ) ) {
				$ref_type  = 'id';
				$ref_value = $item['target_id'];
			}

			if ( '' === $ref_type || '' === (string) $ref_value ) {
				return array();
			}

			$translated_ref = array(
				'ref_type'  => $ref_type,
				'ref_value' => $ref_value,
			);
		}

		$metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();
		if ( ! empty( $item['filename'] ) ) {
			$metadata['filename'] = sanitize_file_name( $item['filename'] );
		}
		if ( ! empty( $item['mime_type'] ) ) {
			$metadata['mime_type'] = sanitize_mime_type( $item['mime_type'] );
		}
		if ( ! empty( $item['title'] ) ) {
			$metadata['title'] = sanitize_text_field( $item['title'] );
		}

		// Map entity_type to adapter-compatible type.
		$adapter_entity_type = $entity_type;
		if ( 'image' === $entity_type || 'video' === $entity_type || 'audio' === $entity_type ) {
			$adapter_entity_type = ! empty( $item['entity_type'] )
				? sanitize_key( $item['entity_type'] )
				: $entity_type;
		}

		return array(
			'entity_type'    => $adapter_entity_type,
			'source_id'      => $source_id,
			'translated_ref' => $translated_ref,
			'metadata'       => $metadata,
		);
	}

	/**
	 * Build a dispatch item from a non-text patch fragment.
	 *
	 * @param array $fragment Normalized patch fragment.
	 * @return array Dispatch item, or empty array if fragment lacks translated_ref.
	 */
	private function build_dispatch_item_from_fragment( $fragment ) {
		if ( ! is_array( $fragment ) ) {
			return array();
		}

		$type  = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? 'text' ) );
		$value = (string) ( $fragment['value'] ?? '' );

		// Parse structured value for translated_ref.
		$structured = $this->parse_non_text_patch_value( $value );
		if ( empty( $structured ) ) {
			return array();
		}

		// Need at least a translated_ref with ref_type and ref_value.
		$translated_ref = is_array( $structured['translated_ref'] ?? null )
			? $structured['translated_ref']
			: array();

		// Fall back: if value has url/path/id directly.
		if ( empty( $translated_ref ) ) {
			if ( ! empty( $structured['url'] ) ) {
				$translated_ref = array(
					'ref_type'  => 'url',
					'ref_value' => $structured['url'],
				);
			} elseif ( ! empty( $structured['path'] ) ) {
				$translated_ref = array(
					'ref_type'  => 'path',
					'ref_value' => $structured['path'],
				);
			} elseif ( ! empty( $structured['target_id'] ) ) {
				$translated_ref = array(
					'ref_type'  => 'id',
					'ref_value' => $structured['target_id'],
				);
			}
		}

		if ( empty( $translated_ref['ref_type'] ) || empty( $translated_ref['ref_value'] ) ) {
			return array();
		}

		$source_id = (int) ( $structured['source_id'] ?? ( $structured['id'] ?? 0 ) );

		// Map fragment type to entity_type.
		$entity_type_map = array(
			'image'    => 'image',
			'video'    => 'video',
			'audio'    => 'audio',
			'document' => 'document',
		);
		$entity_type = $entity_type_map[ $type ] ?? 'attachment';
		if ( ! empty( $structured['entity_type'] ) ) {
			$entity_type = sanitize_key( $structured['entity_type'] );
		}

		$metadata = is_array( $structured['metadata'] ?? null ) ? $structured['metadata'] : array();

		return array(
			'entity_type'    => $entity_type,
			'source_id'      => $source_id,
			'translated_ref' => $translated_ref,
			'metadata'       => $metadata,
		);
	}

	/**
	 * Build a deduplication key for a non-text dispatch item.
	 *
	 * @param array $item Dispatch item.
	 * @return string
	 */
	private function build_non_text_item_dedupe_key( $item ) {
		$entity_type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );
		$source_id   = (int) ( $item['source_id'] ?? 0 );
		$ref         = is_array( $item['translated_ref'] ?? null ) ? $item['translated_ref'] : array();
		$ref_type    = sanitize_key( (string) ( $ref['ref_type'] ?? '' ) );
		$ref_value   = (string) ( $ref['ref_value'] ?? '' );

		return sprintf( '%s:%d:%s:%s', $entity_type, $source_id, $ref_type, $ref_value );
	}

	/**
	 * Build task payload array from DB row.
	 *
	 * @param array $row Task row.
	 * @return array
	 */
	private function build_task_data_from_row( $row ) {
		$task = array(
			'id'                => (int) ( $row['id'] ?? 0 ),
			'blog_id'           => (int) ( $row['blog_id'] ?? 0 ),
			'target_blog'       => (int) ( $row['target_blog'] ?? 0 ),
			'target_type'       => sanitize_key( (string) ( $row['target_type'] ?? 'wp' ) ),
			'target_identifier' => sanitize_text_field( (string) ( $row['target_identifier'] ?? '' ) ),
			'site_id'           => (int) ( $row['site_id'] ?? 0 ),
			'site_mode'         => sanitize_text_field( (string) ( $row['site_mode'] ?? '' ) ),
			'template'          => sanitize_key( (string) ( $row['template'] ?? '' ) ),
			'object_type'       => sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
			'subtype'           => sanitize_key( (string) ( $row['subtype'] ?? '' ) ),
			'object_id'         => (int) ( $row['object_id'] ?? 0 ),
			'lang_from'         => sanitize_text_field( (string) ( $row['lang_from'] ?? '' ) ),
			'lang_to'           => sanitize_text_field( (string) ( $row['lang_to'] ?? '' ) ),
		);

		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( is_array( $payload ) ) {
			$task = array_merge( $task, $payload );
		}

		return $task;
	}

	/**
	 * Normalize outgoing client task payload structure.
	 *
	 * Ensure payload has a stable shape for Rust client:
	 * - payload.task_type
	 * - payload.subtasks[]
	 *
	 * @param array $payload Raw payload.
	 * @param array $row     Task row.
	 * @return array
	 */
	private function normalize_client_task_payload( $payload, $row ) {
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$subtasks = $this->normalize_client_subtasks_for_payload( $payload );
		if ( empty( $subtasks ) ) {
			$subtasks = $this->build_text_subtasks_from_fields( $payload );
		}
		$task_type = $this->infer_client_task_type( $payload, $row, $subtasks );
		$business_line = $this->infer_client_business_line( $payload, $row );
		$object_ref    = $this->build_client_object_ref( $payload, $row );
		$job_id        = $this->infer_client_job_id( $payload, $row, $business_line );

		$payload['job_id']      = $job_id;
		$payload['business_line'] = $business_line;
		$payload['object_ref']  = $object_ref;
		$payload['task_type'] = $task_type;
		$payload['subtasks']  = $subtasks;
		$payload['content_items'] = $subtasks;
		return $payload;
	}

	/**
	 * Infer client task type.
	 *
	 * @param array $payload  Payload.
	 * @param array $row      Task row.
	 * @param array $subtasks Normalized subtasks.
	 * @return string
	 */
	private function infer_client_task_type( $payload, $row, $subtasks ) {
		if ( is_array( $payload ) ) {
			foreach ( array( 'task_type', 'type' ) as $type_key ) {
				if ( ! empty( $payload[ $type_key ] ) && is_string( $payload[ $type_key ] ) ) {
					return $this->normalize_client_task_type_value( (string) $payload[ $type_key ] );
				}
			}
		}

		$types = array();
		foreach ( (array) $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			$type = $this->normalize_client_task_type_value( (string) ( $subtask['type'] ?? 'text' ) );
			$types[ $type ] = true;
		}
		$type_keys = array_keys( $types );
		if ( count( $type_keys ) > 1 ) {
			return 'mixed';
		}
		if ( 1 === count( $type_keys ) ) {
			return (string) $type_keys[0];
		}

		if ( is_array( $payload ) ) {
			$type_map = array(
				'image'    => array( 'images', 'image_items' ),
				'video'    => array( 'videos', 'video_items' ),
				'audio'    => array( 'audios', 'audio_items' ),
				'document' => array( 'documents', 'document_items' ),
			);
			foreach ( $type_map as $type => $keys ) {
				foreach ( $keys as $key ) {
					if ( ! empty( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
						return $type;
					}
				}
			}
		}

		$row_type = sanitize_key( (string) ( $row['type'] ?? '' ) );
		if ( in_array( $row_type, array( 'image', 'video', 'audio', 'document', 'text' ), true ) ) {
			return $row_type;
		}

		return 'text';
	}

	/**
	 * Infer client business line.
	 *
	 * @param array $payload Payload.
	 * @param array $row     Task row.
	 * @return string
	 */
	private function infer_client_business_line( $payload, $row ) {
		if ( is_array( $payload ) && ! empty( $payload['business_line'] ) && is_string( $payload['business_line'] ) ) {
			return $this->normalize_client_business_line_value( (string) $payload['business_line'] );
		}

		$object_type = sanitize_key( (string) ( $row['object_type'] ?? ( $payload['object_type'] ?? '' ) ) );
		$subtype     = sanitize_key( (string) ( $row['subtype'] ?? ( $payload['subtype'] ?? '' ) ) );
		if ( in_array( $object_type, array( 'post_type', 'post' ), true ) ) {
			return 'post_content';
		}
		if ( in_array( $object_type, array( 'taxonomy', 'term' ), true ) ) {
			return 'taxonomy_content';
		}
		if ( 'language_pack' === $object_type ) {
			if ( in_array( $subtype, array( 'theme', 'theme_i18n' ), true ) ) {
				return 'theme_i18n';
			}
			return 'plugin_i18n';
		}
		return 'custom_model';
	}

	/**
	 * Normalize business line value.
	 *
	 * @param string $raw_value Raw value.
	 * @return string
	 */
	private function normalize_client_business_line_value( $raw_value ) {
		$value = sanitize_key( strtolower( trim( (string) $raw_value ) ) );
		if ( in_array( $value, array( 'post', 'post_type', 'post_content' ), true ) ) {
			return 'post_content';
		}
		if ( in_array( $value, array( 'taxonomy', 'term', 'taxonomy_content' ), true ) ) {
			return 'taxonomy_content';
		}
		if ( in_array( $value, array( 'theme', 'theme_i18n' ), true ) ) {
			return 'theme_i18n';
		}
		if ( in_array( $value, array( 'plugin', 'plugin_i18n', 'language_pack', 'language_pack_i18n' ), true ) ) {
			return 'plugin_i18n';
		}
		if ( in_array( $value, array( 'custom_model', 'custom', 'model' ), true ) ) {
			return 'custom_model';
		}
		return 'custom_model';
	}

	/**
	 * Build object_ref from payload/row.
	 *
	 * @param array $payload Payload.
	 * @param array $row     Task row.
	 * @return array
	 */
	private function build_client_object_ref( $payload, $row ) {
		$existing = is_array( $payload ) ? ( $payload['object_ref'] ?? array() ) : array();
		if ( is_array( $existing ) && ! empty( $existing['object_type'] ) && isset( $existing['object_id'] ) ) {
			return array(
				'object_type'       => sanitize_key( (string) ( $existing['object_type'] ?? '' ) ),
				'subtype'           => sanitize_key( (string) ( $existing['subtype'] ?? '' ) ),
				'object_id'         => (int) ( $existing['object_id'] ?? 0 ),
				'source_blog_id'    => (int) ( $existing['source_blog_id'] ?? ( $row['blog_id'] ?? 0 ) ),
				'target_blog_id'    => (int) ( $existing['target_blog_id'] ?? ( $row['target_blog'] ?? 0 ) ),
				'target_type'       => sanitize_key( (string) ( $existing['target_type'] ?? ( $row['target_type'] ?? '' ) ) ),
				'target_identifier' => sanitize_text_field( (string) ( $existing['target_identifier'] ?? ( $row['target_identifier'] ?? '' ) ) ),
				'site_id'           => (int) ( $existing['site_id'] ?? ( $row['site_id'] ?? 0 ) ),
			);
		}

		return array(
			'object_type'       => sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
			'subtype'           => sanitize_key( (string) ( $row['subtype'] ?? '' ) ),
			'object_id'         => (int) ( $row['object_id'] ?? 0 ),
			'source_blog_id'    => (int) ( $row['blog_id'] ?? 0 ),
			'target_blog_id'    => (int) ( $row['target_blog'] ?? 0 ),
			'target_type'       => sanitize_key( (string) ( $row['target_type'] ?? '' ) ),
			'target_identifier' => sanitize_text_field( (string) ( $row['target_identifier'] ?? '' ) ),
			'site_id'           => (int) ( $row['site_id'] ?? 0 ),
		);
	}

	/**
	 * Infer job id from payload/row.
	 *
	 * @param array  $payload       Payload.
	 * @param array  $row           Task row.
	 * @param string $business_line Business line.
	 * @return string
	 */
	private function infer_client_job_id( $payload, $row, $business_line ) {
		if ( is_array( $payload ) && ! empty( $payload['job_id'] ) && is_string( $payload['job_id'] ) ) {
			return sanitize_text_field( (string) $payload['job_id'] );
		}
		$site_id = (int) ( $row['site_id'] ?? 0 );
		$task_id = (int) ( $row['id'] ?? 0 );
		return sanitize_key( sprintf( 'job_legacy_%d_%s_%d', $site_id, sanitize_key( (string) $business_line ), $task_id ) );
	}

	/**
	 * Normalize client task type value.
	 *
	 * @param string $raw_type Raw type.
	 * @return string
	 */
	private function normalize_client_task_type_value( $raw_type ) {
		$type = sanitize_key( strtolower( trim( (string) $raw_type ) ) );
		if ( in_array( $type, array( 'text', 'image', 'video', 'audio', 'document', 'mixed' ), true ) ) {
			return $type;
		}
		if ( in_array( $type, array( 'text_translation', 'field', 'fields' ), true ) ) {
			return 'text';
		}
		if ( in_array( $type, array( 'image_translation', 'images' ), true ) ) {
			return 'image';
		}
		if ( in_array( $type, array( 'video_translation', 'videos' ), true ) ) {
			return 'video';
		}
		if ( in_array( $type, array( 'audio_translation', 'audios' ), true ) ) {
			return 'audio';
		}
		if ( in_array( $type, array( 'document_translation', 'documents', 'doc', 'file', 'files' ), true ) ) {
			return 'document';
		}
		return 'text';
	}

	/**
	 * Normalize payload subtasks list.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private function normalize_client_subtasks_for_payload( $payload ) {
		$subtasks = array();
		$seen     = array();

		if ( ! is_array( $payload ) ) {
			return $subtasks;
		}

		foreach ( array( 'subtasks', 'content_items' ) as $list_key ) {
			if ( empty( $payload[ $list_key ] ) || ! is_array( $payload[ $list_key ] ) ) {
				continue;
			}
			foreach ( array_values( $payload[ $list_key ] ) as $index => $item ) {
				$this->append_client_subtask_from_item(
					$subtasks,
					$seen,
					$item,
					'text',
					'subtask',
					$index
				);
			}
		}

		$typed_lists = array(
			'image'    => array( 'images', 'image_items' ),
			'video'    => array( 'videos', 'video_items' ),
			'audio'    => array( 'audios', 'audio_items' ),
			'document' => array( 'documents', 'document_items' ),
		);
		foreach ( $typed_lists as $type => $keys ) {
			foreach ( $keys as $list_key ) {
				if ( empty( $payload[ $list_key ] ) || ! is_array( $payload[ $list_key ] ) ) {
					continue;
				}
				foreach ( array_values( $payload[ $list_key ] ) as $index => $item ) {
					$this->append_client_subtask_from_item(
						$subtasks,
						$seen,
						$item,
						$type,
						$type,
						$index
					);
				}
			}
		}

		return $subtasks;
	}

	/**
	 * Build text subtasks from payload fields (legacy payload compatibility).
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private function build_text_subtasks_from_fields( $payload ) {
		$subtasks = array();
		$seen     = array();
		if ( ! is_array( $payload ) || empty( $payload['fields'] ) || ! is_array( $payload['fields'] ) ) {
			return $subtasks;
		}

		foreach ( $payload['fields'] as $field_key => $field_value ) {
			$key = is_string( $field_key ) && '' !== $field_key
				? sanitize_key( $field_key )
				: 'field_' . ( count( $subtasks ) + 1 );
			$source_text = $this->extract_client_source_text( $field_value );
			$dedupe      = 'text|' . $key;
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;
			$subtasks[] = array(
				'type'        => 'text',
				'key'         => $key,
				'source_text' => $source_text,
			);
		}

		return $subtasks;
	}

	/**
	 * Append normalized subtask item.
	 *
	 * @param array  $subtasks      Subtasks list.
	 * @param array  $seen          De-dup map.
	 * @param mixed  $item          Raw item.
	 * @param string $fallback_type Fallback type.
	 * @param string $key_prefix    Key prefix.
	 * @param int    $index         Item index.
	 * @return void
	 */
	private function append_client_subtask_from_item( &$subtasks, &$seen, $item, $fallback_type, $key_prefix, $index ) {
		$item_array = is_array( $item ) ? $item : array( 'value' => $item );
		$raw_type   = (string) ( $item_array['type'] ?? ( $item_array['kind'] ?? ( $item_array['task_type'] ?? $fallback_type ) ) );
		$type       = $this->normalize_client_task_type_value( $raw_type );
		$raw_key    = (string) ( $item_array['key'] ?? ( $item_array['id'] ?? '' ) );
		$key        = '' !== trim( $raw_key )
			? sanitize_key( $raw_key )
			: sanitize_key( $key_prefix . '_' . ( (int) $index + 1 ) );
		if ( '' === $key ) {
			$key = sanitize_key( $key_prefix . '_' . ( (int) $index + 1 ) );
		}
		$dedupe = $type . '|' . $key;
		if ( isset( $seen[ $dedupe ] ) ) {
			return;
		}
		$seen[ $dedupe ] = true;

		$subtask = array(
			'type'        => $type,
			'task_type'   => $type,
			'key'         => $key,
			'source_text' => $this->extract_client_source_text( $item_array ),
		);

		// Preserve i18n entry metadata when present.
		if ( isset( $item_array['entry_id'] ) ) {
			$subtask['entry_id'] = (int) $item_array['entry_id'];
		}
		if ( ! empty( $item_array['context'] ) ) {
			$subtask['context'] = sanitize_text_field( (string) $item_array['context'] );
		}
		if ( ! empty( $item_array['plural'] ) ) {
			$subtask['plural'] = sanitize_text_field( (string) $item_array['plural'] );
		}

		// Preserve enriched media metadata when present.
		$source_ref = sanitize_text_field( (string) ( $item_array['source_ref'] ?? '' ) );
		if ( '' !== $source_ref ) {
			$subtask['source_ref'] = $source_ref;
		}
		if ( is_array( $item_array['source_payload'] ?? null ) && ! empty( $item_array['source_payload'] ) ) {
			$subtask['source_payload'] = $item_array['source_payload'];
		}

		$subtasks[] = $subtask;
		$target_path = sanitize_text_field(
			(string) ( $item_array['target_path'] ?? ( $item_array['path'] ?? '' ) )
		);
		if ( '' !== $target_path && preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $target_path ) ) {
			$subtasks[ count( $subtasks ) - 1 ]['target_path'] = $target_path;
		}
		$target_paths = array();
		foreach ( array( 'target_paths', 'field_targets', 'targets' ) as $map_key ) {
			if ( ! is_array( $item_array[ $map_key ] ?? null ) ) {
				continue;
			}
			foreach ( $item_array[ $map_key ] as $raw_field => $raw_path ) {
				$field = sanitize_key( (string) $raw_field );
				$path  = sanitize_text_field( (string) $raw_path );
				if ( '' === $field || '' === $path ) {
					continue;
				}
				if ( ! preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $path ) ) {
					continue;
				}
				$target_paths[ $field ] = $path;
			}
		}
		if ( ! empty( $target_paths ) ) {
			$subtasks[ count( $subtasks ) - 1 ]['target_paths'] = $target_paths;
		}
	}

	/**
	 * Extract source text from raw payload value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function extract_client_source_text( $value ) {
		if ( is_array( $value ) ) {
			$candidate_keys = array(
				'source_text',
				'text',
				'value',
				'source',
				'caption',
				'title',
				'description',
				'transcript',
				'prompt',
				'content',
			);
			foreach ( $candidate_keys as $key ) {
				if ( isset( $value[ $key ] ) ) {
					$text = $this->extract_client_source_text( $value[ $key ] );
					if ( '' !== trim( $text ) ) {
						return $text;
					}
				}
			}
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			return is_string( $encoded ) ? $encoded : '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_numeric( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Normalize translated fields from client result.
	 *
	 * Accepted:
	 * - { "fields": [ { "key": "post_title", "translated": "..." } ] }
	 * - { "fields": { "post_title": "..." } }
	 *
	 * @param array $result Client result payload.
	 * @return array<string, string>
	 */
	private function normalize_translated_fields( $result ) {
		$map = array();
		$fields = is_array( $result ) ? ( $result['fields'] ?? array() ) : array();

		if ( is_array( $fields ) ) {
			foreach ( $fields as $key => $item ) {
				if ( is_array( $item ) && isset( $item['key'] ) ) {
					$field_key = $this->normalize_field_key( (string) $item['key'] );
					$value = isset( $item['translated'] ) ? (string) $item['translated'] : (string) ( $item['value'] ?? '' );
					if ( '' !== $field_key ) {
						$map[ $field_key ] = $value;
					}
					continue;
				}

				if ( is_string( $key ) ) {
					$field_key = $this->normalize_field_key( $key );
					if ( '' !== $field_key ) {
						$map[ $field_key ] = is_scalar( $item ) ? (string) $item : wp_json_encode( $item );
					}
				}
			}
		}

		$subtasks = is_array( $result ) ? ( $result['subtasks'] ?? array() ) : array();
		if ( is_array( $subtasks ) ) {
			foreach ( $subtasks as $subtask ) {
				if ( ! is_array( $subtask ) ) {
					continue;
				}
				$type = $this->normalize_client_task_type_value( (string) ( $subtask['type'] ?? '' ) );
				if ( 'text' !== $type ) {
					continue;
				}
				$status = sanitize_key( (string) ( $subtask['status'] ?? '' ) );
				if ( ! in_array( $status, array( 'completed', 'success', 'done' ), true ) ) {
					continue;
				}
				$field_key = $this->normalize_field_key( (string) ( $subtask['key'] ?? '' ) );
				if ( '' === $field_key ) {
					continue;
				}
				$translated = $subtask['translated'] ?? ( $subtask['value'] ?? '' );
				$translated = is_scalar( $translated )
					? (string) $translated
					: wp_json_encode( $translated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$map[ $field_key ] = is_string( $translated ) ? $translated : '';
			}
		}

		$patch = is_array( $result ) ? ( $result['patch'] ?? array() ) : array();
		$fragments = is_array( $patch ) ? ( $patch['fragments'] ?? array() ) : array();
		if ( is_array( $fragments ) ) {
			foreach ( $fragments as $fragment ) {
				if ( ! is_array( $fragment ) ) {
					continue;
				}
				$type = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? '' ) );
				if ( 'text' !== $type ) {
					continue;
				}
				$status = sanitize_key( (string) ( $fragment['status'] ?? '' ) );
				if ( ! in_array( $status, array( 'completed', 'success', 'done' ), true ) ) {
					continue;
				}
				$field_key = $this->normalize_field_key( (string) ( $fragment['key'] ?? '' ) );
				if ( '' === $field_key ) {
					continue;
				}
				$value = $fragment['value'] ?? ( $fragment['translated'] ?? '' );
				$value = is_scalar( $value )
					? (string) $value
					: wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$map[ $field_key ] = is_string( $value ) ? $value : '';
			}
		}

		return $map;
	}

	/**
	 * Normalize patch payload from client result.
	 *
	 * @param array $result Client result payload.
	 * @return array
	 */
	private function normalize_client_result_patch( $result ) {
		$normalized = array(
			'idempotency_key'  => '',
			'fragments'        => array(),
			'translated_fields'=> array(),
			'summary'          => array(
				'total'     => 0,
				'completed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
			),
		);
		if ( ! is_array( $result ) ) {
			return $normalized;
		}

		$patch = is_array( $result['patch'] ?? null ) ? $result['patch'] : array();
		$normalized['idempotency_key'] = sanitize_text_field(
			(string) ( $patch['idempotency_key'] ?? ( $patch['key'] ?? '' ) )
		);

		$seen = array();
		$append_fragment = function( $fragment ) use ( &$normalized, &$seen ) {
			if ( ! is_array( $fragment ) ) {
				return;
			}
			$fragment_id = (string) ( $fragment['fragment_id'] ?? '' );
			if ( '' === $fragment_id ) {
				$fragment_id = (string) ( $fragment['type'] ?? 'text' ) . ':' . (string) ( $fragment['key'] ?? '' );
			}
			$dedupe_key = sanitize_key( $fragment_id );
			if ( '' === $dedupe_key ) {
				$dedupe_key = 'frag_' . ( count( $normalized['fragments'] ) + 1 );
			}
			if ( isset( $seen[ $dedupe_key ] ) ) {
				return;
			}
			$seen[ $dedupe_key ] = true;

			$status = sanitize_key( (string) ( $fragment['status'] ?? 'completed' ) );
			if ( in_array( $status, array( 'success', 'done' ), true ) ) {
				$status = 'completed';
			} elseif ( 'error' === $status ) {
				$status = 'failed';
			} elseif ( 'noop' === $status ) {
				$status = 'skipped';
			}
			if ( ! in_array( $status, array( 'completed', 'failed', 'skipped' ), true ) ) {
				$status = 'completed';
			}
			$fragment['status'] = $status;
			$normalized['fragments'][] = $fragment;
			$normalized['summary']['total']++;
			if ( isset( $normalized['summary'][ $status ] ) ) {
				$normalized['summary'][ $status ]++;
			}
			if ( 'completed' === $status ) {
				$type = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? '' ) );
				$key  = $this->normalize_field_key( (string) ( $fragment['key'] ?? '' ) );
				if ( 'text' === $type && '' !== $key ) {
					$normalized['translated_fields'][ $key ] = (string) ( $fragment['value'] ?? '' );
				}
			}
		};

		$patch_fragments = is_array( $patch['fragments'] ?? null ) ? $patch['fragments'] : array();
		foreach ( $patch_fragments as $fragment ) {
			$normalized_fragment = $this->normalize_single_client_patch_fragment( $fragment, array() );
			$append_fragment( $normalized_fragment );
		}

		$subtasks = is_array( $result['subtasks'] ?? null ) ? $result['subtasks'] : array();
		foreach ( $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			$fallback = array(
				'type'          => $subtask['type'] ?? '',
				'key'           => $subtask['key'] ?? '',
				'status'        => $subtask['status'] ?? 'completed',
				'translated'    => $subtask['translated'] ?? ( $subtask['value'] ?? '' ),
				'error_code'    => $subtask['error_code'] ?? '',
				'error_message' => $subtask['error_message'] ?? '',
				'fallback'      => ! empty( $subtask['fallback'] ),
			);
			if ( isset( $subtask['patch_fragment'] ) ) {
				$normalized_fragment = $this->normalize_single_client_patch_fragment(
					$subtask['patch_fragment'],
					$fallback
				);
				$append_fragment( $normalized_fragment );
				continue;
			}
			$normalized_fragment = $this->normalize_single_client_patch_fragment( $fallback, array() );
			$append_fragment( $normalized_fragment );
		}

		return $normalized;
	}

	/**
	 * Normalize single patch fragment.
	 *
	 * @param mixed $fragment Raw fragment.
	 * @param array $fallback Fallback values.
	 * @return array
	 */
	private function normalize_single_client_patch_fragment( $fragment, $fallback = array() ) {
		$fallback = is_array( $fallback ) ? $fallback : array();
		$raw      = is_array( $fragment ) ? $fragment : array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$type = $this->normalize_client_task_type_value(
			(string) ( $raw['type'] ?? ( $raw['task_type'] ?? ( $fallback['type'] ?? 'text' ) ) )
		);
		$key_raw = (string) ( $raw['key'] ?? ( $raw['id'] ?? ( $fallback['key'] ?? '' ) ) );
		$key     = sanitize_key( $key_raw );
		$status  = sanitize_key( (string) ( $raw['status'] ?? ( $fallback['status'] ?? 'completed' ) ) );
		if ( in_array( $status, array( 'success', 'done' ), true ) ) {
			$status = 'completed';
		} elseif ( 'error' === $status ) {
			$status = 'failed';
		} elseif ( 'noop' === $status ) {
			$status = 'skipped';
		}
		if ( ! in_array( $status, array( 'completed', 'failed', 'skipped' ), true ) ) {
			$status = 'completed';
		}

		$value = $this->normalize_client_patch_fragment_value(
			$raw['value'] ?? ( $raw['translated'] ?? ( $fallback['translated'] ?? '' ) )
		);
		$target_path = sanitize_text_field(
			(string) ( $raw['target_path'] ?? ( $raw['path'] ?? '' ) )
		);
		if ( '' !== $target_path && ! preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $target_path ) ) {
			$target_path = '';
		}
		$error_code = $this->normalize_client_error_code(
			(string) ( $raw['error_code'] ?? ( $fallback['error_code'] ?? '' ) )
		);
		$error_message = sanitize_text_field(
			(string) ( $raw['error_message'] ?? ( $fallback['error_message'] ?? '' ) )
		);
		$fallback_used = ! empty( $raw['fallback'] ) || ! empty( $fallback['fallback'] );
		$fragment_id   = sanitize_text_field(
			(string) ( $raw['fragment_id'] ?? sprintf( '%s:%s', $type, $key ) )
		);

		if ( '' === $key && '' === $target_path ) {
			return array();
		}

		return array(
			'fragment_id'   => $fragment_id,
			'type'          => $type,
			'key'           => $key,
			'status'        => $status,
			'value'         => $value,
			'target_path'   => $target_path,
			'error_code'    => $error_code,
			'error_message' => $error_message,
			'fallback'      => (bool) $fallback_used,
		);
	}

	/**
	 * Normalize fragment value into string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalize_client_patch_fragment_value( $value ) {
		if ( is_null( $value ) ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Apply patch fragments to complete data.
	 *
	 * @param array $task         Task.
	 * @param array $complete_data Complete data.
	 * @param array $fragments    Patch fragments.
	 * @return array|\WP_Error
	 */
	private function apply_patch_fragments_to_complete_data( $task, $complete_data, $fragments ) {
		$summary = array(
			'total'     => 0,
			'completed' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'applied'   => 0,
			'applied_business' => 0,
			'applied_recorded' => 0,
			'recorded_only' => 0,
			'unapplied' => 0,
			'applied_fragments'   => array(),
			'recorded_only_fragments' => array(),
			'unapplied_fragments' => array(),
		);
		if ( ! is_array( $complete_data ) ) {
			return new \WP_Error(
				'patch_apply_invalid_source',
				__( 'Patch application failed: invalid source data', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}
		if ( ! is_array( $fragments ) ) {
			return array(
				'complete_data' => $complete_data,
				'summary'       => $summary,
			);
		}

		if ( ! isset( $complete_data['meta'] ) || ! is_array( $complete_data['meta'] ) ) {
			$complete_data['meta'] = array();
		}

		foreach ( $fragments as $fragment ) {
			if ( ! is_array( $fragment ) ) {
				continue;
			}
			$summary['total']++;
			$status = sanitize_key( (string) ( $fragment['status'] ?? 'completed' ) );
			if ( in_array( $status, array( 'success', 'done' ), true ) ) {
				$status = 'completed';
			} elseif ( 'error' === $status ) {
				$status = 'failed';
			} elseif ( 'noop' === $status ) {
				$status = 'skipped';
			}
			if ( ! in_array( $status, array( 'completed', 'failed', 'skipped' ), true ) ) {
				$status = 'completed';
			}
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
			if ( 'completed' !== $status ) {
				continue;
			}

			$value      = $this->normalize_client_patch_fragment_value( $fragment['value'] ?? '' );
			$target_path = (string) ( $fragment['target_path'] ?? '' );
			$applied_target = false;
			$fallback_result = array(
				'applied' => false,
				'business_applied' => false,
				'recorded' => false,
			);
			$is_non_text = $this->is_non_text_patch_fragment( $fragment );
			if ( '' !== $target_path ) {
				$target_value = $value;
				if ( $is_non_text ) {
					$target_value = $this->resolve_non_text_target_path_value( $fragment, $target_path, $value );
				}
				if ( ! $is_non_text || '' !== trim( (string) $target_value ) ) {
					$applied_target = $this->apply_patch_value_to_complete_data_path( $complete_data, $target_path, $target_value );
				}
			}
			if ( ! $applied_target || $is_non_text ) {
				$fallback_result = $this->apply_patch_value_by_fallback_mapping( $task, $complete_data, $fragment, $value );
			}
			$applied_fallback = ! empty( $fallback_result['applied'] );
			$applied_business = $applied_target || ! empty( $fallback_result['business_applied'] );
			$applied_recorded = ! empty( $fallback_result['recorded'] );
			$applied = $applied_target || $applied_fallback;
			if ( $applied ) {
				$summary['applied']++;
				if ( $applied_business ) {
					$summary['applied_business']++;
				}
				if ( $applied_recorded ) {
					$summary['applied_recorded']++;
				}
				$summary['applied_fragments'][] = array(
					'fragment_id' => sanitize_text_field( (string) ( $fragment['fragment_id'] ?? '' ) ),
					'type'        => sanitize_key( (string) ( $fragment['type'] ?? 'text' ) ),
					'key'         => sanitize_key( (string) ( $fragment['key'] ?? '' ) ),
					'fragment_key'=> $this->build_patch_fragment_key( $fragment ),
					'business_applied' => (bool) $applied_business,
					'recorded'    => (bool) $applied_recorded,
					'target_path' => sanitize_text_field( (string) ( $fragment['target_path'] ?? '' ) ),
				);
				if ( $is_non_text && ! $applied_business && $applied_recorded ) {
					$summary['recorded_only']++;
					$summary['recorded_only_fragments'][] = array(
						'fragment_id' => sanitize_text_field( (string) ( $fragment['fragment_id'] ?? '' ) ),
						'type'        => sanitize_key( (string) ( $fragment['type'] ?? 'text' ) ),
						'key'         => sanitize_key( (string) ( $fragment['key'] ?? '' ) ),
						'fragment_key'=> $this->build_patch_fragment_key( $fragment ),
						'target_path' => sanitize_text_field( (string) ( $fragment['target_path'] ?? '' ) ),
					);
				}
			} else {
				$summary['unapplied']++;
				$summary['unapplied_fragments'][] = array(
					'fragment_id' => sanitize_text_field( (string) ( $fragment['fragment_id'] ?? '' ) ),
					'type'        => sanitize_key( (string) ( $fragment['type'] ?? 'text' ) ),
					'key'         => sanitize_key( (string) ( $fragment['key'] ?? '' ) ),
					'fragment_key'=> $this->build_patch_fragment_key( $fragment ),
					'target_path' => sanitize_text_field( (string) ( $fragment['target_path'] ?? '' ) ),
				);
			}
		}

		return array(
			'complete_data' => $complete_data,
			'summary'       => $summary,
		);
	}

	/**
	 * Build normalized patch fragment key (type:key).
	 *
	 * @param array $fragment Patch fragment.
	 * @return string
	 */
	private function build_patch_fragment_key( $fragment ) {
		if ( ! is_array( $fragment ) ) {
			return '';
		}
		$type = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? 'text' ) );
		if ( '' === $type ) {
			$type = 'text';
		}
		$key = sanitize_key( (string) ( $fragment['key'] ?? '' ) );
		if ( '' === $key ) {
			return '';
		}
		return $type . ':' . $key;
	}

	/**
	 * Collect normalized fragment keys from patch fragments.
	 *
	 * @param array $fragments Patch fragments.
	 * @return array
	 */
	private function collect_fragment_keys_from_fragments( $fragments ) {
		$keys = array();
		foreach ( (array) $fragments as $fragment ) {
			if ( ! is_array( $fragment ) ) {
				continue;
			}
			$key = $this->build_patch_fragment_key( $fragment );
			if ( '' === $key ) {
				continue;
			}
			$keys[] = $key;
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Collect retry fragment keys from patch summary.
	 *
	 * @param array $patch_summary Patch summary.
	 * @return array
	 */
	private function collect_patch_retry_fragment_keys( $patch_summary ) {
		$keys = array();
		foreach ( array( 'unapplied_fragments', 'recorded_only_fragments' ) as $bucket ) {
			$items = is_array( $patch_summary[ $bucket ] ?? null ) ? $patch_summary[ $bucket ] : array();
			foreach ( $items as $fragment ) {
				if ( ! is_array( $fragment ) ) {
					continue;
				}
				$key = sanitize_text_field( (string) ( $fragment['fragment_key'] ?? '' ) );
				if ( '' === $key ) {
					$key = $this->build_patch_fragment_key( $fragment );
				}
				if ( '' === $key ) {
					continue;
				}
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Cleanup manual queue after patch apply succeeded.
	 *
	 * @param array $meta_data     Task meta (by ref).
	 * @param array $patch_summary Patch apply summary.
	 * @return void
	 */
	private function cleanup_manual_queue_after_patch_apply( &$meta_data, $patch_summary ) {
		if ( ! is_array( $meta_data ) ) {
			return;
		}
		$queue_items = is_array( $meta_data['client_patch_manual_queue'] ?? null )
			? array_values( $meta_data['client_patch_manual_queue'] )
			: array();
		if ( empty( $queue_items ) ) {
			return;
		}

		$applied_count = (int) ( $patch_summary['applied'] ?? 0 );
		$business_applied_count = (int) ( $patch_summary['applied_business'] ?? 0 );
		if ( $applied_count <= 0 ) {
			return;
		}
		if ( $business_applied_count <= 0 ) {
			return;
		}

		$applied_keys = array();
		foreach ( (array) ( $patch_summary['applied_fragments'] ?? array() ) as $fragment ) {
			if ( ! is_array( $fragment ) ) {
				continue;
			}
			if ( empty( $fragment['business_applied'] ) ) {
				continue;
			}
			$key = sanitize_text_field( (string) ( $fragment['fragment_key'] ?? '' ) );
			if ( '' === $key ) {
				$key = $this->build_patch_fragment_key( $fragment );
			}
			if ( '' === $key ) {
				continue;
			}
			$applied_keys[ $key ] = true;
		}
		if ( empty( $applied_keys ) ) {
			return;
		}

		$next_queue = array();
		foreach ( $queue_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$reason = sanitize_key( (string) ( $item['reason'] ?? '' ) );
			if ( 'non_text_without_target_mapping' === $reason ) {
				// Current patch is now applicable, so stale "no mapping" entries can be dropped.
				continue;
			}

			$unapplied_fragments = is_array( $item['unapplied_fragments'] ?? null )
				? array_values( $item['unapplied_fragments'] )
				: array();
			if ( empty( $unapplied_fragments ) ) {
				$next_queue[] = $item;
				continue;
			}

			$next_fragments = array();
			foreach ( $unapplied_fragments as $fragment ) {
				if ( ! is_array( $fragment ) ) {
					continue;
				}
				$fragment_key = sanitize_text_field( (string) ( $fragment['fragment_key'] ?? '' ) );
				if ( '' === $fragment_key ) {
					$fragment_key = $this->build_patch_fragment_key( $fragment );
				}
				if ( '' !== $fragment_key && isset( $applied_keys[ $fragment_key ] ) ) {
					continue;
				}
				$next_fragments[] = $fragment;
			}
			if ( empty( $next_fragments ) && in_array( $reason, array( 'patch_fragment_unapplied', 'patch_fragment_recorded_only' ), true ) ) {
				continue;
			}
			$item['unapplied_fragments'] = $next_fragments;
			if ( isset( $item['unapplied'] ) ) {
				$item['unapplied'] = count( $next_fragments );
			}
			$next_queue[] = $item;
		}

		if ( empty( $next_queue ) ) {
			unset( $meta_data['client_patch_manual_queue'] );
			return;
		}
		$meta_data['client_patch_manual_queue'] = array_values( $next_queue );
	}

	/**
	 * Apply patch by explicit target path.
	 *
	 * @param array  $complete_data Complete data.
	 * @param string $target_path   Target path.
	 * @param string $value         Value.
	 * @return bool
	 */
	private function apply_patch_value_to_complete_data_path( &$complete_data, $target_path, $value ) {
		$target_path = trim( (string) $target_path );
		if ( '' === $target_path ) {
			return false;
		}
		if ( ! preg_match( '/^(post|meta|term)\.([A-Za-z0-9_\-]+)$/', $target_path, $matches ) ) {
			return false;
		}
		$root = (string) $matches[1];
		$key  = sanitize_key( (string) $matches[2] );
		if ( '' === $key ) {
			return false;
		}
		if ( ! isset( $complete_data[ $root ] ) || ! is_array( $complete_data[ $root ] ) ) {
			$complete_data[ $root ] = array();
		}
		$patched_value = $value;
		if ( ( 'term' === $root && 'slug' === $key ) || ( 'post' === $root && 'post_name' === $key ) ) {
			$patched_value = sanitize_title( $value );
		}
		$complete_data[ $root ][ $key ] = $patched_value;
		return true;
	}

	/**
	 * Whether patch fragment is a non-text type.
	 *
	 * @param array $fragment Patch fragment.
	 * @return bool
	 */
	private function is_non_text_patch_fragment( $fragment ) {
		$type = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? 'text' ) );
		return 'text' !== $type;
	}

	/**
	 * Resolve target path value for non-text fragments.
	 *
	 * For explicit business-field target paths we prefer translated_ref/translated_fields
	 * over raw structured JSON payload.
	 *
	 * @param array  $fragment    Patch fragment.
	 * @param string $target_path Target path.
	 * @param string $value       Raw patch value.
	 * @return string
	 */
	private function resolve_non_text_target_path_value( $fragment, $target_path, $value ) {
		$target_path = trim( (string) $target_path );
		if ( '' === $target_path ) {
			return $value;
		}
		if ( ! preg_match( '/^(post|meta|term)\.([A-Za-z0-9_\-]+)$/', $target_path, $matches ) ) {
			return $value;
		}
		$target_key = sanitize_key( (string) ( $matches[2] ?? '' ) );
		if ( '' === $target_key ) {
			return $value;
		}
		// Keep structured payload on wptsall_* diagnostic/meta keys.
		if ( 0 === strpos( $target_key, 'wptsall_' ) ) {
			return $value;
		}

		$structured = $this->parse_non_text_patch_value( $value );
		if ( empty( $structured ) ) {
			return $value;
		}
		$translated_ref = sanitize_text_field( (string) ( $structured['translated_ref'] ?? '' ) );
		$translated_fields = is_array( $structured['translated_fields'] ?? null ) ? $structured['translated_fields'] : array();
		$candidates = array(
			$target_key,
		);
			if ( in_array( $target_key, array( 'post_title', 'title', 'name' ), true ) ) {
				$candidates = array_merge( $candidates, array( 'title', 'name', 'summary', 'text' ) );
			} elseif ( in_array( $target_key, array( 'post_content', 'content', 'description' ), true ) ) {
				$candidates = array_merge( $candidates, array( 'description', 'content', 'text', 'transcript', 'subtitle', 'subtitles', 'summary', 'script', 'voiceover' ) );
			} elseif ( in_array( $target_key, array( 'post_excerpt', 'excerpt', 'caption' ), true ) ) {
				$candidates = array_merge( $candidates, array( 'caption', 'description', 'summary', 'text' ) );
			} elseif ( in_array( $target_key, array( 'post_name', 'slug' ), true ) ) {
				$candidates = array_merge( $candidates, array( 'slug', 'title', 'text' ) );
			} elseif ( in_array( $target_key, array( 'alt', 'alt_text' ), true ) ) {
				$candidates = array_merge( $candidates, array( 'alt', 'alt_text', 'title', 'text' ) );
			}
		foreach ( array_unique( $candidates ) as $candidate ) {
			$normalized = sanitize_key( (string) $candidate );
			if ( '' === $normalized ) {
				continue;
			}
			if ( ! array_key_exists( $normalized, $translated_fields ) ) {
				continue;
			}
			$field_value = $this->normalize_client_patch_fragment_value( $translated_fields[ $normalized ] );
			if ( '' !== trim( $field_value ) ) {
				return $field_value;
			}
		}
		if ( '' !== $translated_ref ) {
			return $translated_ref;
		}
		// For business target paths, unresolved structured payload should not be
		// written as raw JSON into object fields.
		return '';
	}

	/**
	 * Apply patch by fallback mapping.
	 *
	 * @param array  $task          Task.
	 * @param array  $complete_data Complete data.
	 * @param array  $fragment      Fragment.
	 * @param string $value         Value.
	 * @return array
	 */
	private function apply_patch_value_by_fallback_mapping( $task, &$complete_data, $fragment, $value ) {
		$result = array(
			'applied'          => false,
			'business_applied' => false,
			'recorded'         => false,
		);
		$type        = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? 'text' ) );
		$key         = sanitize_key( (string) ( $fragment['key'] ?? '' ) );
		$object_type = sanitize_key( (string) ( $task['object_type'] ?? '' ) );
		if ( '' === $key ) {
			return $result;
		}
		if ( ! isset( $complete_data['meta'] ) || ! is_array( $complete_data['meta'] ) ) {
			$complete_data['meta'] = array();
		}

		if ( 'text' === $type ) {
			if ( in_array( $object_type, array( 'post_type', 'post' ), true ) ) {
				if ( ! isset( $complete_data['post'] ) || ! is_array( $complete_data['post'] ) ) {
					$complete_data['post'] = array();
				}
				if ( in_array( $key, array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ), true ) ) {
					$complete_data['post'][ $key ] = 'post_name' === $key ? sanitize_title( $value ) : $value;
					$result['applied']          = true;
					$result['business_applied'] = true;
					return $result;
				}
				$complete_data['meta'][ $key ] = $value;
				$result['applied']          = true;
				$result['business_applied'] = true;
				return $result;
			}
			if ( in_array( $object_type, array( 'taxonomy', 'term' ), true ) ) {
				if ( ! isset( $complete_data['term'] ) || ! is_array( $complete_data['term'] ) ) {
					$complete_data['term'] = array();
				}
				if ( in_array( $key, array( 'name', 'description', 'slug' ), true ) ) {
					$complete_data['term'][ $key ] = 'slug' === $key ? sanitize_title( $value ) : $value;
					$result['applied']          = true;
					$result['business_applied'] = true;
					return $result;
				}
				$complete_data['meta'][ $key ] = $value;
				$result['applied']          = true;
				$result['business_applied'] = true;
				return $result;
			}
			$complete_data['meta'][ $key ] = $value;
			$result['applied']          = true;
			$result['business_applied'] = true;
			return $result;
		}

		$meta_key = sanitize_key( 'wptsall_' . $type . '_' . $key );
		if ( '' === $meta_key ) {
			$meta_key = sanitize_key( 'wptsall_patch_' . $key );
		}

		$structured = $this->parse_non_text_patch_value( $value );
		$mode       = sanitize_key( (string) ( $structured['mode'] ?? '' ) );
		if ( in_array( $mode, array( 'structured', 'reference_passthrough', 'placeholder' ), true ) ) {
			$complete_data['meta'][ $meta_key ] = $value;
			$result['applied']  = true;
			$result['recorded'] = true;
			$mode_key = sanitize_key( $meta_key . '_mode' );
			if ( '' !== $mode_key ) {
				$complete_data['meta'][ $mode_key ] = $mode;
			}

			$source_ref = sanitize_text_field( (string) ( $structured['source_ref'] ?? '' ) );
			if ( '' === $source_ref ) {
				$source = is_array( $structured['source'] ?? null ) ? $structured['source'] : array();
				foreach ( array( 'source_ref', 'url', 'src', 'source_url', 'file_url', 'path', 'file_path', 'attachment_url', 'attachment_id', 'media_id', 'source_id', 'id' ) as $ref_key ) {
					$candidate = sanitize_text_field( (string) ( $source[ $ref_key ] ?? '' ) );
					if ( '' !== $candidate ) {
						$source_ref = $candidate;
						break;
					}
				}
			}
			if ( '' !== $source_ref ) {
				$ref_key = sanitize_key( $meta_key . '_source_ref' );
				if ( '' !== $ref_key ) {
					$complete_data['meta'][ $ref_key ] = $source_ref;
				}
			}
			$translated_ref = sanitize_text_field( (string) ( $structured['translated_ref'] ?? '' ) );
			if ( '' !== $translated_ref ) {
				$translated_ref_key = sanitize_key( $meta_key . '_translated_ref' );
				if ( '' !== $translated_ref_key ) {
					$complete_data['meta'][ $translated_ref_key ] = $translated_ref;
				}
			}

			$translated_fields = is_array( $structured['translated_fields'] ?? null ) ? $structured['translated_fields'] : array();
			foreach ( $translated_fields as $field_key_raw => $field_value_raw ) {
				$field_key = sanitize_key( (string) $field_key_raw );
				if ( '' === $field_key ) {
					continue;
				}
				$field_value = $this->normalize_client_patch_fragment_value( $field_value_raw );
				if ( '' === trim( $field_value ) ) {
					continue;
				}
				$field_meta_key = sanitize_key( sprintf( '%s_%s', $meta_key, $field_key ) );
				if ( '' === $field_meta_key ) {
					continue;
				}
				$complete_data['meta'][ $field_meta_key ] = $field_value;
			}
			$mapped_count = $this->apply_non_text_structured_target_paths(
				$complete_data,
				$structured,
				$translated_fields,
				$source_ref,
				$translated_ref,
				$value
			);
			if ( $mapped_count > 0 ) {
				$result['business_applied'] = true;
			}

			return $result;
		}

		$complete_data['meta'][ $meta_key ] = $value;
		$result['applied']  = true;
		$result['recorded'] = true;
		return $result;
	}

	/**
	 * Apply structured non-text target_paths map to complete_data.
	 *
	 * @param array  $complete_data     Complete data (by ref).
	 * @param array  $structured        Structured non-text payload.
	 * @param array  $translated_fields Translated fields map.
	 * @param string $source_ref        Source ref.
	 * @param string $translated_ref    Translated ref.
	 * @param string $default_value     Raw patch value.
	 * @return int
	 */
	private function apply_non_text_structured_target_paths( &$complete_data, $structured, $translated_fields, $source_ref, $translated_ref, $default_value ) {
		$target_paths = is_array( $structured['target_paths'] ?? null ) ? $structured['target_paths'] : array();
		if ( empty( $target_paths ) ) {
			return 0;
		}
		$mode = sanitize_key( (string) ( $structured['mode'] ?? '' ) );
		$applied = 0;
		foreach ( $target_paths as $raw_alias => $raw_target_path ) {
			$alias       = sanitize_key( (string) $raw_alias );
			$target_path = sanitize_text_field( (string) $raw_target_path );
			if ( '' === $alias || '' === $target_path ) {
				continue;
			}
			if ( ! preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $target_path ) ) {
				continue;
			}
			$resolved = $this->resolve_non_text_structured_target_value(
				$alias,
				$mode,
				$translated_fields,
				$source_ref,
				$translated_ref,
				$default_value
			);
			if ( '' === trim( $resolved ) ) {
				continue;
			}
			if ( $this->apply_patch_value_to_complete_data_path( $complete_data, $target_path, $resolved ) ) {
				$applied++;
			}
		}
		return $applied;
	}

	/**
	 * Resolve mapped target value for structured non-text field alias.
	 *
	 * @param string $alias            Alias key.
	 * @param string $mode             Structured mode.
	 * @param array  $translated_fields Translated fields map.
	 * @param string $source_ref       Source ref.
	 * @param string $translated_ref   Translated ref.
	 * @param string $default_value    Raw patch value.
	 * @return string
	 */
	private function resolve_non_text_structured_target_value( $alias, $mode, $translated_fields, $source_ref, $translated_ref, $default_value ) {
		$alias = sanitize_key( (string) $alias );
		$mode  = sanitize_key( (string) $mode );
		if ( '' === $alias ) {
			return '';
		}

		if ( in_array( $alias, array( 'translated_ref', 'ref', 'media_ref' ), true ) ) {
			if ( '' !== $translated_ref ) {
				return $translated_ref;
			}
			// For reference_passthrough, caller should explicitly map source_ref,
			// otherwise this alias can produce pseudo "translated" completion.
			if ( in_array( $mode, array( 'reference_passthrough', 'passthrough' ), true ) ) {
				return '';
			}
			return '';
		}
		if ( in_array( $alias, array( 'source_ref', 'source' ), true ) ) {
			return $source_ref;
		}
		if ( 'value' === $alias ) {
			return $default_value;
		}

		$aliases = array( $alias );
		if ( 'alt' === $alias ) {
			$aliases[] = 'alt_text';
		} elseif ( 'alt_text' === $alias ) {
			$aliases[] = 'alt';
		} elseif ( 'caption' === $alias ) {
			$aliases[] = 'description';
			$aliases[] = 'summary';
			$aliases[] = 'text';
		} elseif ( 'content' === $alias ) {
			$aliases[] = 'description';
			$aliases[] = 'text';
			$aliases[] = 'transcript';
			$aliases[] = 'subtitle';
			$aliases[] = 'subtitles';
			$aliases[] = 'summary';
			$aliases[] = 'script';
			$aliases[] = 'voiceover';
		} elseif ( 'description' === $alias ) {
			$aliases[] = 'content';
			$aliases[] = 'text';
			$aliases[] = 'summary';
		} elseif ( 'summary' === $alias ) {
			$aliases[] = 'title';
			$aliases[] = 'description';
			$aliases[] = 'text';
		} elseif ( 'title' === $alias ) {
			$aliases[] = 'name';
			$aliases[] = 'summary';
			$aliases[] = 'text';
		} elseif ( 'transcript' === $alias || 'subtitle' === $alias || 'subtitles' === $alias ) {
			$aliases[] = 'text';
			$aliases[] = 'content';
			$aliases[] = 'description';
			$aliases[] = 'voiceover';
		} elseif ( 'script' === $alias || 'voiceover' === $alias ) {
			$aliases[] = 'transcript';
			$aliases[] = 'text';
			$aliases[] = 'content';
		}

		foreach ( array_unique( $aliases ) as $candidate ) {
			$candidate_key = sanitize_key( (string) $candidate );
			if ( '' === $candidate_key ) {
				continue;
			}
			if ( ! array_key_exists( $candidate_key, $translated_fields ) ) {
				continue;
			}
			$value = $this->normalize_client_patch_fragment_value( $translated_fields[ $candidate_key ] );
			if ( '' !== trim( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Parse non-text patch JSON value.
	 *
	 * @param string $value Raw fragment value.
	 * @return array
	 */
	private function parse_non_text_patch_value( $value ) {
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return array();
		}
		$first_char = substr( $raw, 0, 1 );
		if ( ! in_array( $first_char, array( '{', '[' ), true ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Normalize error code to uppercase A-Z0-9_.
	 *
	 * @param string $raw      Raw code.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function normalize_client_error_code( $raw, $fallback = '' ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '_', trim( (string) $raw ) ) );
		$code = trim( $code, '_' );
		if ( '' === $code ) {
			$fallback = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '_', trim( (string) $fallback ) ) );
			$code     = trim( $fallback, '_' );
		}
		if ( '' === $code ) {
			$code = 'UNKNOWN';
		}
		if ( strlen( $code ) > 96 ) {
			$code = substr( $code, 0, 96 );
		}
		return $code;
	}

	/**
	 * Extract CALLBACK_FAILED_* code from status message.
	 *
	 * @param string $message Status message.
	 * @return string
	 */
	private function extract_callback_failed_error_code( $message ) {
		$message = strtoupper( (string) $message );
		if ( '' === trim( $message ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/(CALLBACK_FAILED_[A-Z0-9_]+)/', $message, $matches ) ) {
			return '';
		}
		return $this->normalize_client_error_code( (string) $matches[1], 'CALLBACK_FAILED_UNKNOWN' );
	}

	/**
	 * Refresh job aggregate snapshot by job_id.
	 *
	 * @param string $table         Tasks table name.
	 * @param array  $row           Task row.
	 * @param string $latest_status Latest task status.
	 * @return array
	 */
	private function refresh_job_aggregate_snapshot( $table, $row, $latest_status = '' ) {
		global $wpdb;
		$job_id = $this->extract_job_id_from_task_row( $row );
		if ( '' === $job_id ) {
			return array();
		}

		$job_like = '%' . $wpdb->esc_like( '"job_id":"' . $job_id . '"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, status, payload, updated_at FROM %i WHERE payload LIKE %s',
				$table,
				$job_like
			),
			ARRAY_A
		);
		if ( ! is_array( $candidates ) ) {
			$candidates = array();
		}

		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'retry'      => 0,
			'failed'     => 0,
			'completed'  => 0,
			'other'      => 0,
		);
		$total      = 0;
		$latest_at  = '';
		foreach ( $candidates as $candidate ) {
			$payload = json_decode( (string) ( $candidate['payload'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$candidate_job_id = sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) );
			if ( $candidate_job_id !== $job_id ) {
				continue;
			}
			$total++;
			$status = sanitize_key( (string) ( $candidate['status'] ?? '' ) );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			} else {
				$counts['other']++;
			}
			$updated_at = sanitize_text_field( (string) ( $candidate['updated_at'] ?? '' ) );
			if ( '' !== $updated_at && ( '' === $latest_at || strtotime( $updated_at ) > strtotime( $latest_at ) ) ) {
				$latest_at = $updated_at;
			}
		}

		$job_status = $this->determine_job_status_by_counts( $counts, $total );
		$progress   = $total > 0 ? round( ( (int) $counts['completed'] / (int) $total ) * 100, 2 ) : 0;
		$snapshot   = array(
			'job_id'         => $job_id,
			'status'         => $job_status,
			'total'          => $total,
			'progress'       => $progress,
			'counts'         => $counts,
			'latest_status'  => sanitize_key( (string) $latest_status ),
			'latest_task_id' => (int) ( $row['id'] ?? 0 ),
			'latest_at'      => '' !== $latest_at ? $latest_at : current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
		);

		$option_key = 'wptsall_job_aggregate_' . md5( $job_id );
		update_option( $option_key, $snapshot, false );
		if ( function_exists( 'wptsall_upsert_task_job_snapshot' ) ) {
			wptsall_upsert_task_job_snapshot( $snapshot );
		}
		return $snapshot;
	}

	/**
	 * Determine job status by status counts.
	 *
	 * @param array $counts Counts.
	 * @param int   $total  Total tasks.
	 * @return string
	 */
	private function determine_job_status_by_counts( $counts, $total ) {
		$total = max( 0, (int) $total );
		if ( $total <= 0 ) {
			return 'running';
		}

		$pending    = (int) ( $counts['pending'] ?? 0 );
		$processing = (int) ( $counts['processing'] ?? 0 );
		$retry      = (int) ( $counts['retry'] ?? 0 );
		$failed     = (int) ( $counts['failed'] ?? 0 );
		$completed  = (int) ( $counts['completed'] ?? 0 );

		if ( $completed >= $total && 0 === $failed && 0 === $retry ) {
			return 'completed';
		}
		if ( $failed > 0 && 0 === $completed && 0 === $pending && 0 === $processing && 0 === $retry ) {
			return 'failed';
		}
		if ( $completed > 0 || $failed > 0 || $retry > 0 ) {
			return 'partial';
		}
		return 'running';
	}

	/**
	 * Extract job_id from task row payload.
	 *
	 * @param array $row Task row.
	 * @return string
	 */
	private function extract_job_id_from_task_row( $row ) {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( is_array( $payload ) ) {
			$job_id = sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) );
			if ( '' !== $job_id ) {
				return $job_id;
			}
		}
		return '';
	}

	/**
	 * Summarize client subtasks from result payload.
	 *
	 * @param array $result Client result payload.
	 * @return array
	 */
	private function summarize_client_subtasks( $result ) {
		$summary = array(
			'total'     => 0,
			'completed' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);

		$subtasks = is_array( $result ) ? ( $result['subtasks'] ?? array() ) : array();
		if ( ! is_array( $subtasks ) ) {
			return $summary;
		}

		foreach ( $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			++$summary['total'];
			$status = sanitize_key( (string) ( $subtask['status'] ?? '' ) );
			if ( in_array( $status, array( 'completed', 'success', 'done' ), true ) ) {
				++$summary['completed'];
			} elseif ( in_array( $status, array( 'skipped', 'noop' ), true ) ) {
				++$summary['skipped'];
			} elseif ( in_array( $status, array( 'failed', 'error' ), true ) ) {
				++$summary['failed'];
			}
		}

		return $summary;
	}

	/**
	 * Normalize incoming field key.
	 *
	 * Supports formats like:
	 * - post_title
	 * - post.post_title
	 * - term.description
	 *
	 * @param string $raw_key Raw key.
	 * @return string
	 */
	private function normalize_field_key( $raw_key ) {
		$raw_key = trim( $raw_key );
		if ( '' === $raw_key ) {
			return '';
		}

		$parts = explode( '.', $raw_key );
		$key   = end( $parts );
		$key   = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key );

		return sanitize_key( (string) $key );
	}

	/**
	 * Patch complete source data with translated values from client.
	 *
	 * @param array $task              Task data.
	 * @param array $complete_data     Source complete data.
	 * @param array $translated_fields Field map.
	 * @return array
	 */
	private function patch_complete_data_with_translations( $task, $complete_data, $translated_fields ) {
		$object_type = $task['object_type'] ?? '';

		if ( 'post_type' === $object_type ) {
			if ( empty( $complete_data['post'] ) || ! is_array( $complete_data['post'] ) ) {
				$complete_data['post'] = array();
			}
			if ( empty( $complete_data['meta'] ) || ! is_array( $complete_data['meta'] ) ) {
				$complete_data['meta'] = array();
			}

			foreach ( $translated_fields as $field_key => $translated ) {
				if ( in_array( $field_key, array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ), true ) ) {
					$complete_data['post'][ $field_key ] = $translated;
					continue;
				}
				$complete_data['meta'][ $field_key ] = $translated;
			}
		} elseif ( 'taxonomy' === $object_type ) {
			if ( empty( $complete_data['term'] ) || ! is_array( $complete_data['term'] ) ) {
				$complete_data['term'] = array();
			}
			if ( isset( $translated_fields['name'] ) ) {
				$complete_data['term']['name'] = $translated_fields['name'];
			}
			if ( isset( $translated_fields['description'] ) ) {
				$complete_data['term']['description'] = $translated_fields['description'];
			}
			}

		return $complete_data;
	}

	/**
	 * Append client status event and keep bounded history.
	 *
	 * @param array $meta_data Meta data array.
	 * @param array $event     Event payload.
	 * @return void
	 */
	private function append_client_status_history( &$meta_data, $event ) {
		if ( ! isset( $meta_data['client_status_history'] ) || ! is_array( $meta_data['client_status_history'] ) ) {
			$meta_data['client_status_history'] = array();
		}

		$meta_data['client_status_history'][] = array(
			'source'      => sanitize_key( (string) ( $event['source'] ?? 'client' ) ),
			'status'      => sanitize_key( (string) ( $event['status'] ?? '' ) ),
			'progress'    => max( 0, min( 100, (int) ( $event['progress'] ?? 0 ) ) ),
			'message'     => sanitize_text_field( (string) ( $event['message'] ?? '' ) ),
			'retry_count' => max( 0, (int) ( $event['retry_count'] ?? 0 ) ),
			'at'          => sanitize_text_field( (string) ( $event['at'] ?? current_time( 'mysql', true ) ) ),
		);

		if ( count( $meta_data['client_status_history'] ) > 50 ) {
			$meta_data['client_status_history'] = array_slice( $meta_data['client_status_history'], -50 );
		}
	}

	/**
	 * Resolve request id from header or generate fallback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function resolve_request_id( $request ) {
		$request_id = (string) $request->get_header( $this->request_id_header );
		$request_id = trim( $request_id );
		if ( '' !== $request_id && strlen( $request_id ) <= 128 && 1 === preg_match( '/^[\x20-\x7E]+$/', $request_id ) ) {
			return $request_id;
		}
		return 'wptreq-' . str_replace( '-', '', wp_generate_uuid4() );
	}

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

	/**
	 * Get stable client worker identity.
	 *
	 * Prefer explicit worker id header, fallback to token fingerprint for backward compatibility.
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

	/**
	 * Whether task status is terminal for client write path.
	 *
	 * @param string $status Task status.
	 * @return bool
	 */
	private function is_client_terminal_task_status( $status ) {
		$status = sanitize_key( (string) $status );
		return in_array( $status, array( 'completed', 'failed' ), true );
	}

	/**
	 * Build conflict error for finalized task updates.
	 *
	 * @param string $code           Error code.
	 * @param string $message        Error message.
	 * @param string $current_status Current task status.
	 * @return \WP_Error
	 */
	private function build_client_terminal_task_conflict_error( $code, $message, $current_status ) {
		return new \WP_Error(
			sanitize_key( (string) $code ),
			$message,
			array(
				'status'         => 409,
				'current_status' => sanitize_key( (string) $current_status ),
				'schema_version' => self::CLIENT_SCHEMA_VERSION,
			)
		);
	}

	/**
	 * Extract stored result fingerprint from task meta.
	 *
	 * @param array $meta_data Task meta.
	 * @return string
	 */
	private function extract_client_result_fingerprint( $meta_data ) {
		if ( ! is_array( $meta_data ) ) {
			return '';
		}
		$envelope = $meta_data['client_result_envelope'] ?? array();
		if ( ! is_array( $envelope ) ) {
			return '';
		}
		$fingerprint = sanitize_text_field( (string) ( $envelope['result_fingerprint'] ?? '' ) );
		if ( '' === $fingerprint ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return '';
		}
		return $fingerprint;
	}

	/**
	 * Build aggregate metrics for client result payload.
	 *
	 * @param array $result Client result.
	 * @return array
	 */
	private function build_client_result_aggregate( $result ) {
		$fields = $this->normalize_translated_fields( $result );
		$patch_context = $this->normalize_client_result_patch( $result );
		$field_total = count( $fields );
		$field_non_empty = 0;
		$field_fallback  = 0;

		foreach ( $fields as $value ) {
			$text = trim( (string) $value );
			if ( '' !== $text ) {
				++$field_non_empty;
			}
			if ( $this->looks_like_local_fallback_translation( $text ) ) {
				++$field_fallback;
			}
		}

		$subtask_total     = 0;
		$subtask_completed = 0;
		$subtask_failed    = 0;
		$subtask_skipped   = 0;
		$subtasks          = is_array( $result ) ? ( $result['subtasks'] ?? array() ) : array();
		if ( is_array( $subtasks ) ) {
			foreach ( $subtasks as $subtask ) {
				if ( ! is_array( $subtask ) ) {
					continue;
				}
				++$subtask_total;
				$subtask_status = sanitize_key( (string) ( $subtask['status'] ?? '' ) );
				if ( in_array( $subtask_status, array( 'completed', 'success', 'done' ), true ) ) {
					++$subtask_completed;
				} elseif ( in_array( $subtask_status, array( 'skipped', 'noop' ), true ) ) {
					++$subtask_skipped;
				} elseif ( in_array( $subtask_status, array( 'failed', 'error' ), true ) ) {
					++$subtask_failed;
				}
			}
		}

		return array(
			'schema_version'     => self::CLIENT_SCHEMA_VERSION,
			'field_total'        => $field_total,
			'field_non_empty'    => $field_non_empty,
			'field_empty'        => max( 0, $field_total - $field_non_empty ),
			'field_fallback'     => $field_fallback,
			'subtask_total'      => $subtask_total,
			'subtask_completed'  => $subtask_completed,
			'subtask_skipped'    => $subtask_skipped,
			'subtask_failed'     => $subtask_failed,
			'patch_total'        => (int) ( $patch_context['summary']['total'] ?? 0 ),
			'patch_completed'    => (int) ( $patch_context['summary']['completed'] ?? 0 ),
			'patch_failed'       => (int) ( $patch_context['summary']['failed'] ?? 0 ),
			'patch_skipped'      => (int) ( $patch_context['summary']['skipped'] ?? 0 ),
		);
	}

	/**
	 * Heuristic: local fallback translated text marker.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private function looks_like_local_fallback_translation( $text ) {
		return 0 === strpos( $text, '[translated] ' );
	}

	// ========================================
	// i18n auto-discovery
	// ========================================

	/**
	 * Auto-discover i18n tasks from pending template entries.
	 *
	 * Called automatically when client pulls tasks. For each active relation
	 * with i18n enabled, checks if there are pending template entries without
	 * a corresponding i18n task, and creates tasks on-the-fly.
	 *
	 * Throttled to run at most once per 30 seconds to avoid excessive DB queries
	 * when the client polls frequently.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	private function auto_discover_i18n_tasks() {
		// Throttle: only run discovery if enough time has passed since last run.
		$transient_key = 'wptsall_i18n_auto_discover_ts';
		$last_run      = (int) get_transient( $transient_key );
		$throttle_secs = 30;
		if ( $last_run > 0 && ( time() - $last_run ) < $throttle_secs ) {
			return;
		}
		set_transient( $transient_key, time(), $throttle_secs * 2 );

		$relations = \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations(
			array( 'status' => 'active' )
		);

		if ( empty( $relations ) ) {
			return;
		}

		foreach ( $relations as $relation ) {
			$relation_id = (int) ( $relation['id'] ?? 0 );
			if ( $relation_id <= 0 ) {
				continue;
			}

			// Only Virtual Site targets support i18n translation
			if ( 'virtual' !== ( $relation['target_site_type'] ?? '' ) ) {
				continue;
			}

			$config = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
			if ( ! $config['translate_plugin_i18n'] && ! $config['translate_theme_i18n'] ) {
				continue;
			}

			\WPTSALL\Tasks\Services\Monitoring_Task_Service::discover_i18n_tasks( $relation_id );
		}
	}

	// ========================================
	// i18n result writeback
	// ========================================

	/**
	 * Apply client result to template entries for i18n tasks.
	 *
	 * When business_line is plugin_i18n or theme_i18n, the client sends
	 * translated subtask results. This method writes those translations
	 * back to the template_entries table.
	 *
	 * @since 1.1.0
	 * @param array $row       Task row from database.
	 * @param array $result    Client result payload.
	 * @param array $meta_data Task meta data (by reference).
	 * @return array|\WP_Error Result summary or error.
	 */
	private function apply_i18n_result_to_template_entries( $row, $result, &$meta_data ) {
		$row_payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $row_payload ) ) {
			$row_payload = array();
		}

		$template_id = (int) ( $row_payload['template_id'] ?? ( $row['object_id'] ?? 0 ) );
		if ( $template_id <= 0 ) {
			return new \WP_Error(
				'i18n_result_invalid',
				__( 'i18n task missing template_id', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// Extract subtask results from the client result.
		$subtask_results = $this->extract_i18n_subtask_results( $result );
		if ( empty( $subtask_results ) ) {
			return new \WP_Error(
				'i18n_result_empty',
				__( 'Client i18n result contains no valid translations', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$updated  = 0;
		$failed   = 0;
		$skipped  = 0;

		foreach ( $subtask_results as $item ) {
			$entry_id   = (int) ( $item['entry_id'] ?? 0 );
			$translated = (string) ( $item['translated'] ?? '' );
			$status     = sanitize_key( (string) ( $item['status'] ?? 'completed' ) );

			if ( $entry_id <= 0 ) {
				$skipped++;
				continue;
			}

			if ( 'completed' !== $status && 'translated' !== $status ) {
				$skipped++;
				continue;
			}

			if ( '' === $translated ) {
				$skipped++;
				continue;
			}

			$update_ok = \WPTSALL\Templates\Services\Template_Entry_Service::update(
				$entry_id,
				array(
					'msgstr' => $translated,
					'status' => 'translated',
					'source' => 'client',
				)
			);

			if ( $update_ok ) {
				$updated++;
			} else {
				$failed++;
			}
		}

		// Update template stats.
		\WPTSALL\Templates\Services\Template_Service::update_stats( $template_id );

		if ( is_array( $meta_data ) ) {
			$meta_data['i18n_writeback'] = array(
				'template_id' => $template_id,
				'updated'     => $updated,
				'failed'      => $failed,
				'skipped'     => $skipped,
				'applied_at'  => current_time( 'mysql', true ),
			);
		}

		wptsall_log_info(
			'tasks-sync',
			'i18n result applied to template entries',
			array(
				'task_id'     => (int) ( $row['id'] ?? 0 ),
				'template_id' => $template_id,
				'updated'     => $updated,
				'failed'      => $failed,
				'skipped'     => $skipped,
			)
		);

		return array(
			'target_id'   => $template_id,
			'target_type' => 'template',
			'updated'     => $updated,
			'failed'      => $failed,
			'skipped'     => $skipped,
		);
	}

	/**
	 * Extract i18n subtask results from client result payload.
	 *
	 * The client sends results in subtasks format:
	 * ```
	 * { "subtasks": [
	 *     {"id": "entry_123", "status": "completed", "result": {"translated": "..."}}
	 * ]}
	 * ```
	 *
	 * @since 1.1.0
	 * @param array $result Client result payload.
	 * @return array Array of [{entry_id, translated, status}].
	 */
	private function extract_i18n_subtask_results( $result ) {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$items = array();

		// Try subtasks array first.
		$subtasks = $result['subtasks'] ?? ( $result['content_items'] ?? array() );
		if ( ! is_array( $subtasks ) ) {
			$subtasks = array();
		}

		foreach ( $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}

			$id     = (string) ( $subtask['id'] ?? ( $subtask['key'] ?? '' ) );
			$status = (string) ( $subtask['status'] ?? 'completed' );

			// Extract entry_id from "entry_123" format.
			$entry_id = 0;
			if ( 0 === strpos( $id, 'entry_' ) ) {
				$entry_id = (int) substr( $id, 6 );
			}

			// Get translated text from result or directly from subtask.
			$translated = '';
			if ( is_array( $subtask['result'] ?? null ) ) {
				$translated = (string) ( $subtask['result']['translated'] ?? ( $subtask['result']['text'] ?? '' ) );
			}
			if ( '' === $translated ) {
				$translated = (string) ( $subtask['translated'] ?? ( $subtask['text'] ?? '' ) );
			}

			if ( $entry_id > 0 ) {
				$items[] = array(
					'entry_id'   => $entry_id,
					'translated' => $translated,
					'status'     => $status,
				);
			}
		}

		return $items;
	}
}
