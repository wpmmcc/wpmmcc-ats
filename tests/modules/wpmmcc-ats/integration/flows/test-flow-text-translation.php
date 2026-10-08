<?php
/**
 * Flow: Text Translation via Client Callback
 *
 * Tests the client task pull endpoint (data.items key), the translation
 * callback write-back flow, idempotency semantics, and that post_name is
 * never polluted with translation markers.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Test_Flow_Text_Translation
 */
class Test_Flow_Text_Translation extends REST_Integration_Test_Case {

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
	 * Client route secret (cached once per class).
	 *
	 * @var string
	 */
	private static $route_secret = '';

	/**
	 * Client API token (cached once per class).
	 *
	 * @var string
	 */
	private static $device_id = '';
	private static $client_token = '';

	/**
	 * One-time setup: verify namespace, functions, and cache auth values.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace is not registered';
			return;
		}

		if ( ! function_exists( 'wptsall_get_client_route_secret' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall_get_client_route_secret() not found';
			return;
		}

		if ( ! function_exists( 'wptsall_issue_client_device_token' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall_issue_client_device_token() not found';
			return;
		}

		self::$route_secret  = wptsall_get_client_route_secret();
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
	 * Seed a post_mappings row so content/claim can stamp ownership (unit parity).
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

	/**
	 * Send an authenticated request to the client task-pull endpoint.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route relative to wptsall/v2.
	 * @param array  $params Body or query params.
	 * @param array  $extra_headers Additional request headers.
	 * @return \WP_REST_Response
	 */
	private function client_request( $method, $route, $params = array(), $extra_headers = array() ) {
		$full_route = '/wptsall/v2/' . ltrim( $route, '/' );
		$request    = new \WP_REST_Request( $method, $full_route );

		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', isset( self::$device_id ) ? self::$device_id : '' );
		$request->set_header( 'X-WPTSALL-Client-Token', self::$client_token );

		foreach ( $extra_headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$request->set_body( wp_json_encode( $params ) );
			$request->set_header( 'Content-Type', 'application/json' );
		} else {
			$request->set_query_params( $params );
		}

		return self::$server->dispatch( $request );
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Assert that the client task-pull endpoint returns data.items (not data.tasks).
	 */
	public function test_pull_tasks_returns_items_key() {
		$secret = self::$route_secret;
		$route  = "{$secret}/client/tasks";

		// HTTP assertion: must return 200.
		$response = $this->client_request( 'GET', $route );
		$this->assertEquals( 200, $response->get_status(), 'Client task pull must return HTTP 200' );

		$body = $response->get_data();

		// Specific field assertion: data.items must exist, data.tasks must not be the primary key.
		$this->assertArrayHasKey( 'success', $body, 'Response must contain success' );
		$this->assertTrue( $body['success'], 'success must be true' );

		$data = $body['data'] ?? $body;
		$this->assertArrayHasKey( 'items', $data, 'Client task response must use data.items (not data.tasks)' );
		$this->assertIsArray( $data['items'], 'data.items must be an array' );

		// DB assertion: task table exists and is accessible.
		global $wpdb;
		$tasks_table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tasks_table ) );
		$this->assertEquals( $tasks_table, $db_exists, 'tasks table must exist in DB' );
	}

	/**
	 * Assert that the translation-callback endpoint writes translated content
	 * (with 【zh】 marker) to the target site and does NOT put the marker on post_name.
	 */
	public function test_text_callback_writes_translated_content() {
		global $wpdb;

		// Set up relation.
		$vs_id        = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		// Create source post.
		$post_id  = $this->create_test_post( array(
			'post_title'   => 'Flow Translation Test Post',
			'post_content' => 'Original content for translation flow test.',
			'post_name'    => 'flow-translation-test-post',
		) );

		// Unique client_task_id to avoid idempotency collisions between test runs.
		$client_task_id = 'flow-text-cb-' . wp_generate_uuid4();

		$secret   = self::$route_secret;
		$callback_route = "{$secret}/client/translation-callback";

		$translated_title   = '【zh】Flow Translation Test Post【/zh】';
		$translated_content = '【zh】Original content for translation flow test.【/zh】';

		// Path B: claim before write-back.
		$this->ensure_post_mapping_for_callback( $relation_id, $post_id );
		$claim = $this->client_request( 'POST', "{$secret}/client/content/claim", array(
			'relation_id' => $relation_id,
			'data_type'   => 'post',
			'items'       => array(
				array( 'object_id' => $post_id, 'post_type' => 'post', 'subtype' => 'post' ),
			),
		) );
		$this->assertEquals( 200, $claim->get_status(), 'content/claim must return HTTP 200 before callback: ' . wp_json_encode( $claim->get_data() ) );

		// HTTP assertion: callback returns 200.
		$response = $this->client_request( 'POST', $callback_route, array(
			'client_task_id'    => $client_task_id,
			'relation_id'       => $relation_id,
			'business_line'     => 'post_content',
			'object_type'       => 'post',
			'object_id'         => $post_id,
			'translated_fields' => array(
				'post_title'   => $translated_title,
				'post_content' => $translated_content,
			),
			'source_lang'       => 'en_US',
			'target_lang'       => 'zh_CN',
			'source_revision'   => class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
				? \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id )
				: 'flow-text-rev',
			'policy_version'    => class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
				? \WPTSALL\Core\Job_Snapshot::current_policy_version()
				: 'test-policy-v1',
			'post_type'         => 'post',
			'subtype'           => 'post',
		) );

		$this->assertEquals( 200, $response->get_status(), 'translation-callback must return HTTP 200: ' . wp_json_encode( $response->get_data() ) );

		$cb_data = $response->get_data();

		// Specific field assertion: result_id or idempotent must be present.
		$this->assertArrayHasKey( 'success', $cb_data, 'Callback response must have success key' );
		$this->assertTrue( $cb_data['success'], 'Callback must succeed' );

		// DB assertion: translation_results table must have a record with the marker.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, translated_fields FROM {$results_table} WHERE client_task_id = %s",
			$client_task_id
		), ARRAY_A );

		$this->assertNotNull( $result_row, 'translation_results must contain a record for this client_task_id' );

		$stored_fields = json_decode( (string) ( $result_row['translated_fields'] ?? '{}' ), true );
		$stored_title  = $stored_fields['post_title'] ?? '';
		$this->assertStringContainsString( '【zh】', $stored_title, 'Stored post_title must contain 【zh】 marker' );

		// Specific field assertion: post_name in wp_posts must NOT have the translation marker.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_name = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_name FROM {$wpdb->posts} WHERE ID = %d",
			$post_id
		) );

		if ( $post_name !== null ) {
			$this->assertStringNotContainsString(
				'【zh】',
				(string) $post_name,
				'post_name must never contain the translation marker 【zh】'
			);
		}
	}

	/**
	 * Assert that posting the same callback twice returns idempotent: true on the second call
	 * and does not create duplicate translation_results records.
	 */
	public function test_text_callback_idempotent() {
		global $wpdb;

		// Set up relation.
		$vs_id        = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		$post_id        = $this->create_test_post();
		$client_task_id = 'flow-idempotent-' . wp_generate_uuid4();

		$secret         = self::$route_secret;
		$callback_route = "{$secret}/client/translation-callback";
		$payload        = array(
			'client_task_id'    => $client_task_id,
			'relation_id'       => $relation_id,
			'business_line'     => 'post_content',
			'object_type'       => 'post',
			'object_id'         => $post_id,
			'translated_fields' => array(
				'post_title' => '【zh】Idempotent Test【/zh】',
			),
			'source_lang'       => 'en_US',
			'target_lang'       => 'zh_CN',
			'source_revision'   => class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
				? \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id )
				: 'flow-idempotent-rev',
			'policy_version'    => class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
				? \WPTSALL\Core\Job_Snapshot::current_policy_version()
				: 'test-policy-v1',
			'post_type'         => 'post',
			'subtype'           => 'post',
		);

		$this->ensure_post_mapping_for_callback( $relation_id, $post_id );
		$claim = $this->client_request( 'POST', "{$secret}/client/content/claim", array(
			'relation_id' => $relation_id,
			'data_type'   => 'post',
			'items'       => array(
				array( 'object_id' => $post_id, 'post_type' => 'post', 'subtype' => 'post' ),
			),
		) );
		$this->assertEquals( 200, $claim->get_status(), 'content/claim must return HTTP 200 before idempotent callback: ' . wp_json_encode( $claim->get_data() ) );

		// First call.
		$response1 = $this->client_request( 'POST', $callback_route, $payload );

		// HTTP assertion: first call returns 200.
		$this->assertEquals( 200, $response1->get_status(), 'First callback call must return HTTP 200: ' . wp_json_encode( $response1->get_data() ) );

		// Count records after first call.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after_first = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s",
			$client_task_id
		) );

		// Second call with identical payload.
		$response2 = $this->client_request( 'POST', $callback_route, $payload );

		// HTTP assertion: second call also returns 200.
		$this->assertEquals( 200, $response2->get_status(), 'Second (idempotent) callback must return HTTP 200' );

		$cb2_data = $response2->get_data();

		// Specific field assertion: second response must contain idempotent: true.
		$this->assertArrayHasKey( 'idempotent', $cb2_data, 'Idempotent response must contain idempotent key' );
		$this->assertTrue( $cb2_data['idempotent'], 'idempotent flag must be true on duplicate call' );

		// DB assertion: translation_results count must not increase on second call.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after_second = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s",
			$client_task_id
		) );

		$this->assertEquals(
			$count_after_first,
			$count_after_second,
			'translation_results count must not increase after a duplicate (idempotent) callback'
		);
	}
}
