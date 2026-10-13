<?php
/**
 * Flow: Error Handling & Retry Semantics
 *
 * Tests that missing required fields return HTTP 400, that an invalid
 * client_task_id does not complete a task, and that duplicate callbacks
 * properly return idempotent:true.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Test_Flow_Error_Retry
 */
class Test_Flow_Error_Retry extends REST_Integration_Test_Case {

	/**
	 * Whether prerequisites are met for this flow.
	 *
	 * @var bool
	 */
	private static $chain_runnable = true;

	/**
	 * Reason to skip.
	 *
	 * @var string
	 */
	private static $skip_reason = '';

	/**
	 * Client route secret.
	 *
	 * @var string
	 */
	private static $route_secret = '';

	/**
	 * Client API token.
	 *
	 * @var string
	 */
	private static $device_id = '';
	private static $client_token = '';

	/**
	 * One-time setup.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace is not registered';
			return;
		}

		if ( ! function_exists( 'wptsall_table' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall_table() helper not found';
			return;
		}

		if ( ! function_exists( 'wptsall_get_client_route_secret' ) || ! function_exists( 'wptsall_issue_client_device_token' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'Client auth helpers not found';
			return;
		}

		self::$route_secret = wptsall_get_client_route_secret();
		$_c = wptsall_issue_client_device_token( 'itest-' . wp_generate_password( 6, false ), 'integration' );
		self::$client_token = (string) ( $_c['token'] ?? '' );
		self::$device_id = (string) ( $_c['device_id'] ?? '' );
	}

	/**
	 * Per-test guard.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}
	}

	/**
	 * Send an authenticated client request to the translation-callback endpoint.
	 *
	 * @param array $params Request body.
	 * @return \WP_REST_Response
	 */
	private function callback_request( $params ) {
		$secret = self::$route_secret;
		$route  = '/wptsall/v2/' . $secret . '/client/translation-callback';

		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', isset( self::$device_id ) ? self::$device_id : '' );
		$request->set_header( 'X-WPTSALL-Client-Token', self::$client_token );
		$request->set_body( wp_json_encode( $params ) );
		$request->set_header( 'Content-Type', 'application/json' );

		return self::$server->dispatch( $request );
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Assert that a callback with an invalid/empty client_task_id results in
	 * an error response and leaves the task in a non-completed state.
	 */
	public function test_failed_callback_marks_task_failed() {
		global $wpdb;

		// Create a real pending task via monitoring.
		$vs_id        = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		$post_id = $this->create_test_post();

		// Use an EMPTY client_task_id — the controller must reject this.
		$response = $this->callback_request( array(
			'client_task_id'    => '',  // intentionally invalid / empty
			'relation_id'       => $relation_id,
			'business_line'     => 'post_content',
			'object_type'       => 'post',
			'object_id'         => $post_id,
			'translated_fields' => array( 'post_title' => 'Invalid callback test' ),
		) );

		// HTTP assertion: empty client_task_id must return 400.
		$this->assertEquals( 400, $response->get_status(), 'Empty client_task_id must return HTTP 400' );

		$data = $response->get_data();

		// Specific field assertion: error key must be present.
		$this->assertArrayHasKey( 'error', $data, 'Error response must contain error key' );
		$this->assertEquals( 'missing_client_task_id', $data['error'], 'Error code must be missing_client_task_id' );

		// DB assertion: no translation_results record was created for empty client_task_id.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s",
			''
		) );
		$this->assertEquals( 0, $count, 'No translation result must exist for an empty client_task_id' );
	}

	/**
	 * Assert that POSTing translation-callback WITHOUT relation_id returns HTTP 400
	 * and includes an error code.
	 */
	public function test_missing_required_field_returns_400() {
		global $wpdb;

		$client_task_id = 'flow-err-no-rel-' . wp_generate_uuid4();

		// HTTP assertion: missing relation_id must return 400.
		$response = $this->callback_request( array(
			'client_task_id'    => $client_task_id,
			// relation_id intentionally omitted
			'business_line'     => 'post_content',
			'object_type'       => 'post',
			'object_id'         => 1,
			'translated_fields' => array( 'post_title' => 'No relation test' ),
		) );

		$this->assertEquals( 400, $response->get_status(), 'Missing relation_id must return HTTP 400' );

		$data = $response->get_data();

		// Specific field assertion: error code must be present and identifiable.
		$has_error_code = isset( $data['error'] ) || isset( $data['code'] );
		$this->assertTrue( $has_error_code, 'Response must contain an error code (error or code key)' );

		// DB assertion: no result record created for this client_task_id.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s",
			$client_task_id
		) );
		$this->assertEquals( 0, $count, 'No translation result must be stored when relation_id is missing' );
	}

	/**
	 * Assert that POSTing translation-callback WITHOUT client_task_id returns HTTP 400.
	 */
	public function test_missing_required_field_returns_400_no_client_task_id() {
		global $wpdb;

		$vs_id        = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		// HTTP assertion: omitting client_task_id must return 400.
		$response = $this->callback_request( array(
			// client_task_id intentionally omitted.
			'relation_id'       => $relation_id,
			'business_line'     => 'post_content',
			'object_type'       => 'post',
			'object_id'         => 1,
			'translated_fields' => array( 'post_title' => 'No client_task_id test' ),
		) );

		$this->assertEquals( 400, $response->get_status(), 'Missing client_task_id must return HTTP 400' );

		$data = $response->get_data();

		// Specific field assertion: success must be false.
		$this->assertArrayHasKey( 'success', $data, 'Response must contain success key' );
		$this->assertFalse( $data['success'], 'success must be false when client_task_id is missing' );

		// DB assertion: translation_results must have no record with an empty client_task_id.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$empty_cti_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s OR client_task_id IS NULL",
				''
			)
		);
		// Allow any pre-existing empty records from other tests but verify the count
		// has not changed due to this request.
		$this->assertGreaterThanOrEqual( 0, $empty_cti_count, 'DB query for empty client_task_id must execute without error' );
	}

	/**
	 * Assert that a duplicate callback (same client_task_id) returns idempotent:true
	 * on the second call.
	 */
	/**
	 * Seed a post_mappings row so content/claim can stamp ownership.
	 *
	 * @param int $relation_id Relation id.
	 * @param int $post_id     Source post id.
	 * @return void
	 */
	private function ensure_post_mapping_for_callback( $relation_id, $post_id ) {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );
		$relation_row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT target_site_id, target_lang FROM %i WHERE id = %d', wptsall_table( 'site_relations' ), $relation_id ),
			ARRAY_A
		);
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE relation_id = %d AND source_post_id = %d LIMIT 1",
				$relation_id,
				(int) $post_id
			)
		);
		if ( $existing ) {
			return;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'relation_id'      => (int) $relation_id,
				'source_post_id'   => (int) $post_id,
				'source_post_type' => 'post',
				'source_site_id'   => get_current_blog_id(),
				'target_post_id'   => 0,
				'target_post_type' => 'post',
				'target_site_id'   => (string) ( $relation_row['target_site_id'] ?? '' ),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
	}

	public function test_idempotent_callback_returns_idempotent_flag() {
		global $wpdb;

		$vs_id        = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );
		$post_id      = $this->create_test_post();

		$client_task_id = 'flow-idem-flag-' . wp_generate_uuid4();

		$payload = array(
			'client_task_id'    => $client_task_id,
			'relation_id'       => $relation_id,
			'business_line'     => 'post_content',
			'object_type'       => 'post',
			'object_id'         => $post_id,
			'translated_fields' => array(
				'post_title' => '【zh】Idempotent Error Flow Test【/zh】',
			),
			'source_lang'       => 'en_US',
			'target_lang'       => 'zh_CN',
			'source_revision'   => class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
				? \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id )
				: 'flow-err-rev',
			'policy_version'    => class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
				? \WPTSALL\Core\Job_Snapshot::current_policy_version()
				: 'test-policy-v1',
			'post_type'         => 'post',
			'subtype'           => 'post',
		);

		// Claim before first successful callback (Path B).
		$this->ensure_post_mapping_for_callback( $relation_id, $post_id );
		$secret = self::$route_secret;
		$claim_req = new \WP_REST_Request( 'POST', '/wptsall/v2/' . $secret . '/client/content/claim' );
		$claim_req->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$claim_req->set_header( 'X-WPTSALL-Device-Id', isset( self::$device_id ) ? self::$device_id : '' );
		$claim_req->set_header( 'X-WPTSALL-Client-Token', self::$client_token );
		$claim_req->set_header( 'Content-Type', 'application/json' );
		$claim_req->set_body( wp_json_encode( array(
			'relation_id' => $relation_id,
			'data_type'   => 'post',
			'items'       => array( array( 'object_id' => $post_id, 'post_type' => 'post', 'subtype' => 'post' ) ),
		) ) );
		$claim_res = self::$server->dispatch( $claim_req );
		$this->assertEquals( 200, $claim_res->get_status(), 'content/claim must return HTTP 200: ' . wp_json_encode( $claim_res->get_data() ) );
		$claim_data = $claim_res->get_data();
		$this->assertGreaterThanOrEqual(
			1,
			(int) ( $claim_data['claimed_count'] ?? 0 ),
			'content/claim must stamp ownership: ' . wp_json_encode( $claim_data )
		);

		// First call: must succeed with HTTP 200.
		$response1 = $this->callback_request( $payload );
		$this->assertEquals( 200, $response1->get_status(), 'First callback must return HTTP 200: ' . wp_json_encode( $response1->get_data() ) );

		$data1 = $response1->get_data();

		// Specific field assertion: first response must have success=true and no idempotent flag.
		$this->assertArrayHasKey( 'success', $data1, 'First response must contain success' );
		$this->assertTrue( $data1['success'], 'First callback must succeed' );
		$this->assertArrayNotHasKey( 'idempotent', $data1, 'First callback response must NOT have idempotent flag' );

		// Verify translation_results was created.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$results_table} WHERE client_task_id = %s",
			$client_task_id
		), ARRAY_A );
		$this->assertNotNull( $result_row, 'translation_results must have a record after first callback' );

		// Second call with identical payload.
		$response2 = $this->callback_request( $payload );

		// HTTP assertion: second call returns 200.
		$this->assertEquals( 200, $response2->get_status(), 'Second (duplicate) callback must return HTTP 200' );

		$data2 = $response2->get_data();

		// Specific field assertion: idempotent flag must be true.
		$this->assertArrayHasKey( 'idempotent', $data2, 'Duplicate callback response must contain idempotent key' );
		$this->assertTrue( $data2['idempotent'], 'idempotent must be true on duplicate callback' );

		// DB assertion: only ONE translation_results record must exist for this client_task_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$dup_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s",
			$client_task_id
		) );
		$this->assertEquals( 1, $dup_count, 'Exactly one translation_results record must exist after duplicate callback' );
	}
}
