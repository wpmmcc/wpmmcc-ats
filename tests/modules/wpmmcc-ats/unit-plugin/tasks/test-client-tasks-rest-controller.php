<?php
/**
 * Client tasks REST controller canonical tests.
 *
 * @package WPTSALL
 */

use WPTSALL\Tasks\API\Client_Tasks_REST_Controller;

class Test_Client_Tasks_REST_Controller_Canonical extends SimpleTestCase {

	/**
	 * Controller instance.
	 *
	 * @var Client_Tasks_REST_Controller
	 */
	private $controller;

	/**
	 * Inserted task IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $task_ids = array();

	public function setUp(): void {
		parent::setUp();
		$this->ensure_controller_loaded();
		$this->controller = new Client_Tasks_REST_Controller();
	}

	public function tearDown(): void {
		global $wpdb;

		$table = wptsall_table( 'tasks' );
		foreach ( $this->task_ids as $task_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $table, array( 'id' => (int) $task_id ), array( '%d' ) );
		}

		$this->task_ids = array();

		parent::tearDown();
	}

	private function ensure_controller_loaded() {
		if ( class_exists( '\WPTSALL\Tasks\API\Client_Tasks_REST_Controller' ) ) {
			return;
		}

		require_once WP_PLUGIN_DIR . '/wptsall-pro/includes/tasks/api/class-client-tasks-rest-controller.php';
	}

	private function reflect_private_method( $method_name ) {
		$method = new ReflectionMethod( Client_Tasks_REST_Controller::class, $method_name );
		$method->setAccessible( true );
		return $method;
	}

	private function create_claimed_task( $worker_id = 'worker-a', $lease_until_ts = null ) {
		global $wpdb;

		$table          = wptsall_table( 'tasks' );
		$now            = current_time( 'mysql' );
		$lease_until_ts = is_int( $lease_until_ts ) ? $lease_until_ts : ( time() + Client_Tasks_REST_Controller::DEFAULT_CLIENT_CLAIM_LEASE_SECONDS );
		$meta           = array(
			'client_claim' => array(
				'claim_id'           => wp_generate_uuid4(),
				'worker_id'          => $worker_id,
				'worker_fingerprint' => $worker_id,
				'claimed_at'         => $now,
				'last_heartbeat_at'  => $now,
				'lease_seconds'      => max( 1, $lease_until_ts - time() ),
				'lease_until_ts'     => $lease_until_ts,
				'lease_until'        => gmdate( 'Y-m-d H:i:s', $lease_until_ts ),
			),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'blog_id'           => get_current_blog_id(),
				'target_blog'       => 0,
				'target_type'       => 'wp',
				'target_identifier' => '',
				'site_id'           => 1,
				'relation_id'       => 1,
				'template'          => 'test_template',
				'type'              => 'sync',
				'priority'          => 'normal',
				'object_type'       => 'post_type',
				'subtype'           => 'post',
				'object_id'         => 1000 + count( $this->task_ids ) + 1,
				'lang_from'         => 'en',
				'lang_to'           => 'zh',
				'site_mode'         => 'production',
				'status'            => 'pending',
				'payload'           => wp_json_encode( array( 'delivery_target' => 'post_writeback' ) ),
				'meta'              => wp_json_encode( $meta ),
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$task_id = (int) $wpdb->insert_id;
		if ( $task_id > 0 ) {
			$this->task_ids[] = $task_id;
		}

		return $task_id;
	}

	public function test_normalize_client_task_payload_shapes_legacy_fields_payload() {
		$method = $this->reflect_private_method( 'normalize_client_task_payload' );

		$row = array(
			'id'                => 23,
			'blog_id'           => 11,
			'target_blog'       => 22,
			'target_type'       => 'wp',
			'target_identifier' => 'target-site',
			'site_id'           => 9,
			'object_type'       => 'post_type',
			'subtype'           => 'post',
			'object_id'         => 456,
		);
		$payload = array(
			'fields' => array(
				'post_title'   => 'Hello title',
				'post_content' => array(
					'source_text' => 'Hello body',
				),
			),
		);

		$normalized = $method->invokeArgs(
			$this->controller,
			array(
				$payload,
				$row,
			)
		);

		$this->assertSame( 'text', $normalized['task_type'] );
		$this->assertSame( 'post_content', $normalized['business_line'] );
		$this->assertSame( 'job_legacy_9_post_content_23', $normalized['job_id'] );
		$this->assertSame( 456, (int) $normalized['object_ref']['object_id'] );
		$this->assertSame( 'post_type', $normalized['object_ref']['object_type'] );
		$this->assertCount( 2, $normalized['subtasks'] );
		$this->assertSame( $normalized['subtasks'], $normalized['content_items'] );
		$this->assertSame( 'post_title', $normalized['subtasks'][0]['key'] );
		$this->assertSame( 'Hello title', $normalized['subtasks'][0]['source_text'] );
		$this->assertSame( 'post_content', $normalized['subtasks'][1]['key'] );
		$this->assertSame( 'Hello body', $normalized['subtasks'][1]['source_text'] );
	}

	public function test_apply_client_result_to_target_rejects_message_template_writeback() {
		$method = $this->reflect_private_method( 'apply_client_result_to_target' );

		$row = array(
			'id'         => 123,
			'blog_id'    => get_current_blog_id(),
			'object_type'=> 'option',
			'subtype'    => 'message_template',
			'object_id'  => 456,
			'payload'    => wp_json_encode(
				array(
					'delivery_target' => 'message_template_writeback',
				)
			),
		);
		$meta_data = array();

		$result = $method->invokeArgs(
			$this->controller,
			array(
				$row,
				array( 'translated_fields' => array( 'subject' => 'Translated subject' ) ),
				&$meta_data,
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'delivery_target_not_supported', $result->get_error_code() );
		$this->assertSame( 422, (int) $result->get_error_data()['status'] );
	}

	public function test_compute_client_result_snapshot_hash_is_stable_for_associative_key_order() {
		$method = $this->reflect_private_method( 'compute_client_result_snapshot_hash' );

		$left = array(
			'post_title' => 'Hello',
			'meta'       => array(
				'b' => 2,
				'a' => 1,
			),
			'blocks'     => array(
				array(
					'type' => 'paragraph',
					'text' => 'First',
				),
			),
		);
		$right = array(
			'blocks'     => array(
				array(
					'text' => 'First',
					'type' => 'paragraph',
				),
			),
			'meta'       => array(
				'a' => 1,
				'b' => 2,
			),
			'post_title' => 'Hello',
		);

		$left_hash  = $method->invokeArgs( $this->controller, array( $left ) );
		$right_hash = $method->invokeArgs( $this->controller, array( $right ) );

		$this->assertTrue( '' !== $left_hash );
		$this->assertSame( $left_hash, $right_hash );
	}

	public function test_validate_client_result_snapshot_hash_rejects_mismatch() {
		$compute_method  = $this->reflect_private_method( 'compute_client_result_snapshot_hash' );
		$validate_method = $this->reflect_private_method( 'validate_client_result_snapshot_hash' );

		$original_payload = array(
			'post_title' => 'Before',
			'meta'       => array(
				'a' => 1,
			),
		);
		$current_payload = array(
			'post_title' => 'After',
			'meta'       => array(
				'a' => 1,
			),
		);

		$provided_hash = $compute_method->invokeArgs( $this->controller, array( $original_payload ) );
		$result        = $validate_method->invokeArgs(
			$this->controller,
			array(
				array(
					'object_snapshot_hash' => $provided_hash,
				),
				$current_payload,
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'task_source_snapshot_mismatch', $result->get_error_code() );
		$this->assertSame( 409, (int) $result->get_error_data()['status'] );
	}

	public function test_normalize_client_result_patch_collects_text_fragments_and_summary() {
		$method = $this->reflect_private_method( 'normalize_client_result_patch' );

		$normalized = $method->invokeArgs(
			$this->controller,
			array(
				array(
					'patch'    => array(
						'idempotency_key' => ' patch-key ',
						'fragments'       => array(
							array(
								'fragment_id' => 'title-1',
								'type'        => 'text',
								'key'         => 'post_title',
								'status'      => 'success',
								'value'       => 'Translated title',
							),
							array(
								'fragment_id' => 'meta-1',
								'type'        => 'text',
								'key'         => 'seo_description',
								'status'      => 'error',
								'value'       => 'Ignored value',
							),
						),
					),
					'subtasks' => array(
						array(
							'type'       => 'text',
							'key'        => 'post_excerpt',
							'status'     => 'completed',
							'translated' => 'Translated excerpt',
						),
					),
				),
			)
		);

		$this->assertSame( 'patch-key', $normalized['idempotency_key'] );
		$this->assertSame( 3, (int) $normalized['summary']['total'] );
		$this->assertSame( 2, (int) $normalized['summary']['completed'] );
		$this->assertSame( 1, (int) $normalized['summary']['failed'] );
		$this->assertSame( 'Translated title', $normalized['translated_fields']['post_title'] );
		$this->assertSame( 'Translated excerpt', $normalized['translated_fields']['post_excerpt'] );
		$this->assertFalse( isset( $normalized['translated_fields']['seo_description'] ) );
	}

	public function test_collect_patch_retry_fragment_keys_deduplicates_multiple_buckets() {
		$method = $this->reflect_private_method( 'collect_patch_retry_fragment_keys' );

		$keys = $method->invokeArgs(
			$this->controller,
			array(
				array(
					'unapplied_fragments'     => array(
						array( 'fragment_key' => 'text:post_title' ),
						array( 'fragment_key' => 'text:post_title' ),
					),
					'recorded_only_fragments' => array(
						array(
							'type' => 'text',
							'key'  => 'post_excerpt',
						),
						array( 'fragment_key' => 'text:post_excerpt' ),
					),
				),
			)
		);

		$this->assertSame(
			array( 'text:post_title', 'text:post_excerpt' ),
			$keys
		);
	}

	public function test_normalize_typed_non_text_item_builds_dispatch_shape_from_url_item() {
		$method = $this->reflect_private_method( 'normalize_typed_non_text_item' );

		$item = $method->invokeArgs(
			$this->controller,
			array(
				array(
					'attachment_id' => 33,
					'url'           => 'https://cdn.example.com/a.jpg',
					'filename'      => 'a.jpg',
					'mime_type'     => 'image/jpeg',
					'title'         => 'Hero Image',
				),
				'image',
			)
		);

		$this->assertSame( 'image', $item['entity_type'] );
		$this->assertSame( 33, (int) $item['source_id'] );
		$this->assertSame( 'url', $item['translated_ref']['ref_type'] );
		$this->assertSame( 'https://cdn.example.com/a.jpg', $item['translated_ref']['ref_value'] );
		$this->assertSame( 'a.jpg', $item['metadata']['filename'] );
	}

	public function test_extract_non_text_dispatch_items_deduplicates_direct_typed_and_fragment_sources() {
		$method = $this->reflect_private_method( 'extract_non_text_dispatch_items' );

		$result = array(
			'non_text_items' => array(
				array(
					'entity_type'    => 'image',
					'source_id'      => 12,
					'translated_ref' => array(
						'ref_type'  => 'url',
						'ref_value' => 'https://cdn.example.com/a.jpg',
					),
				),
			),
			'images'         => array(
				array(
					'source_id' => 12,
					'url'       => 'https://cdn.example.com/a.jpg',
				),
				array(
					'source_id' => 13,
					'url'       => 'https://cdn.example.com/b.jpg',
				),
			),
		);
		$patch_context = array(
			'fragments' => array(
				array(
					'type'   => 'image',
					'status' => 'completed',
					'value'  => wp_json_encode(
						array(
							'source_id'      => 13,
							'translated_ref' => array(
								'ref_type'  => 'url',
								'ref_value' => 'https://cdn.example.com/b.jpg',
							),
						)
					),
				),
				array(
					'type'   => 'image',
					'status' => 'completed',
					'value'  => wp_json_encode(
						array(
							'source_id'      => 14,
							'translated_ref' => array(
								'ref_type'  => 'url',
								'ref_value' => 'https://cdn.example.com/c.jpg',
							),
						)
					),
				),
			),
		);

		$items = $method->invokeArgs( $this->controller, array( $result, $patch_context ) );

		$this->assertCount( 3, $items );
		$this->assertSame( 12, (int) $items[0]['source_id'] );
		$this->assertSame( 13, (int) $items[1]['source_id'] );
		$this->assertSame( 14, (int) $items[2]['source_id'] );
	}

	public function test_update_task_status_rejects_different_worker_while_claim_active() {
		$task_id = $this->create_claimed_task( 'worker-a' );
		$this->assertGreaterThan( 0, $task_id );

		$request = new WP_REST_Request( 'POST', "/wptsall/v2/client/tasks/{$task_id}/status" );
		$request->set_param( 'id', $task_id );
		$request->set_param( 'status', 'processing' );
		$request->set_param( 'progress', 10 );
		$request->set_param( 'message', 'still running' );
		$request->set_header( 'X-WPTSALL-Worker-Id', 'worker-b' );

		$result = $this->controller->update_task_status( $request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'task_status_conflict', $result->get_error_code() );
		$this->assertSame( 409, (int) $result->get_error_data()['status'] );
	}

	public function test_submit_task_result_rejects_different_worker_while_claim_active() {
		$task_id = $this->create_claimed_task( 'worker-a' );
		$this->assertGreaterThan( 0, $task_id );

		$request = new WP_REST_Request( 'POST', "/wptsall/v2/client/tasks/{$task_id}/result" );
		$request->set_param( 'id', $task_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Worker-Id', 'worker-b' );
		$request->set_body(
			wp_json_encode(
				array(
					'status' => 'completed',
					'result' => array(
						'translated_fields' => array(
							'post_title' => 'Translated title',
						),
					),
					'meta'   => array(
						'component_id' => 'mock-component',
					),
				)
			)
		);

		$result = $this->controller->submit_task_result( $request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'task_result_conflict', $result->get_error_code() );
		$this->assertSame( 409, (int) $result->get_error_data()['status'] );
	}

	public function test_heartbeat_task_claim_extends_active_lease_for_same_worker() {
		global $wpdb;

		$task_id = $this->create_claimed_task( 'worker-a', time() + 120 );
		$this->assertGreaterThan( 0, $task_id );

		$table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$before_meta_json = $wpdb->get_var(
			$wpdb->prepare( 'SELECT meta FROM %i WHERE id = %d', $table, $task_id )
		);
		$before_meta = json_decode( (string) $before_meta_json, true );
		$before_lease_until_ts = (int) ( $before_meta['client_claim']['lease_until_ts'] ?? 0 );

		$request = new WP_REST_Request( 'POST', "/wptsall/v2/client/tasks/{$task_id}/heartbeat" );
		$request->set_param( 'id', $task_id );
		$request->set_header( 'X-WPTSALL-Worker-Id', 'worker-a' );

		$response = $this->controller->heartbeat_task_claim( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		$this->assertTrue( ! empty( $data['success'] ) );
		$this->assertGreaterThan( $before_lease_until_ts, (int) $data['data']['lease_until_ts'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$after_meta_json = $wpdb->get_var(
			$wpdb->prepare( 'SELECT meta FROM %i WHERE id = %d', $table, $task_id )
		);
		$after_meta = json_decode( (string) $after_meta_json, true );
		$this->assertGreaterThan(
			$before_lease_until_ts,
			(int) ( $after_meta['client_claim']['lease_until_ts'] ?? 0 )
		);
	}

	public function test_heartbeat_task_claim_rejects_other_worker() {
		$task_id = $this->create_claimed_task( 'worker-a', time() + 120 );
		$this->assertGreaterThan( 0, $task_id );

		$request = new WP_REST_Request( 'POST', "/wptsall/v2/client/tasks/{$task_id}/heartbeat" );
		$request->set_param( 'id', $task_id );
		$request->set_header( 'X-WPTSALL-Worker-Id', 'worker-b' );

		$result = $this->controller->heartbeat_task_claim( $request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'task_claim_conflict', $result->get_error_code() );
		$this->assertSame( 409, (int) $result->get_error_data()['status'] );
	}
	/**
	 * GAP-06 wrap-up: the dispatch filter must echo the client's run trace id
	 * (X-WPTSALL-Trace-Id) on secreted client routes, with the same validation
	 * shape as the request id; absent or malformed trace stays silent (the
	 * request ran outside a client run — nothing to correlate).
	 */
	public function test_dispatch_filter_echoes_run_trace_id_on_client_routes() {
		$secret = wptsall_get_client_route_secret();
		$this->assertNotSame( '', $secret, 'route secret should self-heal to a valid value' );
		$route = '/wptsall/v2/' . $secret . '/client/tasks';

		// Valid run trace: echoed verbatim; request id still echoes alongside.
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_header( 'x-wptsall-trace-id', 'disc-run-echo-probe' );
		$response = new WP_REST_Response( array( 'ok' => true ) );

		$result = $this->controller->append_request_id_header( $response, null, $request );

		$headers = $result->get_headers();
		$this->assertSame( 'disc-run-echo-probe', $headers['X-WPTSALL-Trace-Id'] ?? '' );
		$this->assertNotSame( '', (string) ( $headers['X-Request-Id'] ?? '' ) );

		// Absent trace: no echo, no invented fallback.
		$plain_request  = new WP_REST_Request( 'GET', $route );
		$plain_response = new WP_REST_Response( array( 'ok' => true ) );

		$plain_result = $this->controller->append_request_id_header( $plain_response, null, $plain_request );

		$this->assertSame( '', (string) ( $plain_result->get_headers()['X-WPTSALL-Trace-Id'] ?? '' ) );

		// Malformed trace (control chars): rejected, not echoed.
		$bad_request = new WP_REST_Request( 'GET', $route );
		$bad_request->set_header( 'x-wptsall-trace-id', "bad\ntrace id" );
		$bad_response = new WP_REST_Response( array( 'ok' => true ) );

		$bad_result = $this->controller->append_request_id_header( $bad_response, null, $bad_request );

		$this->assertSame( '', (string) ( $bad_result->get_headers()['X-WPTSALL-Trace-Id'] ?? '' ) );
	}
}

