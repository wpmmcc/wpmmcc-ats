<?php
/**
 * Client Tasks REST Controller Tests
 *
 * Tests for WPTSALL\Tasks\API\Client_Tasks_REST_Controller
 * Covers: permissions, parameter validation, response format.
 *
 * Note: Since the SimpleTestCase runner does not have a full REST server
 * environment, these tests focus on the controller methods directly
 * (permission check, ping, constants) rather than HTTP-level routing.
 *
 * @package WPTSALL
 * @since 1.0.0
 */

use WPTSALL\Tasks\API\Client_Tasks_REST_Controller;

class Test_Client_Tasks_Controller extends SimpleTestCase {

	/**
	 * Controller instance.
	 *
	 * @var Client_Tasks_REST_Controller
	 */
	private $controller;

	/**
	 * Options to clean up.
	 *
	 * @var array
	 */
	private $cleanup_options = array();

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id = 0;

	public function setUp(): void {
		parent::setUp();

		// Client_Tasks_REST_Controller is in Pro plugin.
		if ( ! class_exists( '\\WPTSALL\\Tasks\\API\\Client_Tasks_REST_Controller' ) ) {
			$this->markTestSkipped( 'WPTSALL Pro 插件未激活（Client_Tasks_REST_Controller 在 Pro 中）' );
			return; // markTestSkipped throws, but return for clarity.
		}

		$this->controller = new Client_Tasks_REST_Controller();
		$this->cleanup_options = array();

		// Create an admin user for permission tests.
		$this->admin_user_id = $this->factory->user->create(
			array( 'role' => 'administrator' )
		);
	}

	public function tearDown(): void {
		foreach ( $this->cleanup_options as $option ) {
			delete_option( $option );
		}

		// Restore current user.
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	private function track_option( $name ) {
		if ( ! in_array( $name, $this->cleanup_options, true ) ) {
			$this->cleanup_options[] = $name;
		}
	}

	// ==================== Constants ====================

	public function test_schema_version_is_positive_integer() {
		$this->assertGreaterThan( 0, Client_Tasks_REST_Controller::CLIENT_SCHEMA_VERSION );
	}

	public function test_default_claim_lease_is_positive() {
		$this->assertGreaterThan( 0, Client_Tasks_REST_Controller::DEFAULT_CLIENT_CLAIM_LEASE_SECONDS );
	}

	// ==================== Permission Check ====================

	public function test_check_client_permission_rejects_without_token() {
		// On dev domain, pro is enabled, so only the token check matters.
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/ping' );
		// No X-WPTSALL-Client-Token header.

		$result = $this->controller->check_client_permission( $request );

		// Should be WP_Error (either pro_required or client_unauthorized).
		if ( is_wp_error( $result ) ) {
			$this->assertIsString( $result->get_error_code() );
			// P1-TEST-02 (2026-09-02): Protocol v2 negotiation runs BEFORE
			// the token check (PRE-RELEASE-SINGLE-TRUTH: missing header →
			// 400), so a request without any headers is rejected with
			// missing_protocol_version first.
			$codes = array( 'wptsall_pro_required', 'client_unauthorized', 'missing_protocol_version' );
			$this->assertContains( $result->get_error_code(), $codes );
		} else {
			// If pro is not enabled and the function returns WP_Error for pro, that's also valid.
			// On dev, if pro is enabled, empty token should give client_unauthorized.
			$this->assertNotEquals( true, $result, 'Empty token should not be accepted' );
		}
	}

	public function test_check_client_permission_rejects_invalid_token() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/ping' );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', 'unit-invalid-device' );
		$request->set_header( 'X-WPTSALL-Client-Token', 'completely-invalid-token' );

		$result = $this->controller->check_client_permission( $request );

		if ( is_wp_error( $result ) ) {
			$codes = array( 'wptsall_pro_required', 'client_unauthorized' );
			$this->assertContains( $result->get_error_code(), $codes );
		} else {
			$this->assertNotEquals( true, $result );
		}
	}

	public function test_check_client_permission_accepts_valid_token() {
		// Generate a valid token.
		if ( ! function_exists( 'wptsall_issue_client_device_token' ) ) {
			return; // client-pairing module not loaded.
		}

		$this->track_option( 'wptsall_client_api_token_state' );
		$this->track_option( 'wptsall_client_api_token_secret' );
		$this->track_option( 'wptsall_client_api_token' );

		$_c = wptsall_issue_client_device_token( 'unit-tasks', 'unit' );
		$token = (string) ( $_c['token'] ?? '' );
		$device = (string) ( $_c['device_id'] ?? '' );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/ping' );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $device );
		$request->set_header( 'X-WPTSALL-Client-Token', $token );

		$result = $this->controller->check_client_permission( $request );

		// On dev domain with valid token, should return true.
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( in_array( $domain, array( 'localhost', '127.0.0.1' ), true )
			|| preg_match( '/(\.local|\.test)$/', $domain ) ) {
			$this->assertTrue( $result, 'Valid token on dev domain should be accepted' );
		}
	}

	// ==================== Ping Endpoint ====================

	public function test_ping_returns_rest_response() {
		$result = $this->controller->ping();
		$this->assertInstanceOf( 'WP_REST_Response', $result );
	}

	public function test_ping_response_has_expected_structure() {
		$response = $this->controller->ping();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'plugin_identity', $data['data'] );
		$this->assertArrayHasKey( 'plugin_version', $data['data'] );
		$this->assertNotEmpty( $data['data']['plugin_version'] );
	}

	/**
	 * WP-ID-0 (contract/identity-v1): client/ping must surface the canonical
	 * plugin identity so the Rust client can distinguish wpmmcc-ats from the
	 * wpmmcc cross-site sync plugin on the same binding table.
	 */
	public function test_ping_plugin_identity_matches_contract() {
		$response = $this->controller->ping();
		$data     = $response->get_data();

		// Golden value: fixtures/wpmmcc-ats-identity.json (contract/identity-v1).
		$this->assertSame( 'wpmmcc_ats', $data['data']['plugin_identity'] );
	}

	// ==================== Update Task Status Validation ====================

	public function test_update_task_status_rejects_invalid_status() {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// Insert a test task.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'type'       => 'sync',
				'status'     => 'pending',
				'blog_id'    => get_current_blog_id(),
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
		$task_id = (int) $wpdb->insert_id;

		$request = new WP_REST_Request( 'POST', "/wptsall/v2/client/tasks/{$task_id}/status" );
		$request->set_param( 'id', $task_id );
		$request->set_param( 'status', 'invalid_status' );
		$request->set_param( 'progress', 0 );
		$request->set_param( 'message', '' );

		$result = $this->controller->update_task_status( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_status', $result->get_error_code() );

		// Cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, array( 'id' => $task_id ), array( '%d' ) );
	}

	public function test_update_task_status_returns_not_found_for_missing_task() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/tasks/999999/status' );
		$request->set_param( 'id', 999999 );
		$request->set_param( 'status', 'processing' );
		$request->set_param( 'progress', 0 );
		$request->set_param( 'message', '' );

		$result = $this->controller->update_task_status( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'task_not_found', $result->get_error_code() );
	}

	// ==================== Submit Task Result Validation ====================

	public function test_submit_task_result_returns_not_found_for_missing_task() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/tasks/999999/result' );
		$request->set_param( 'id', 999999 );
		$request->set_header( 'Content-Type', 'application/json' );

		$result = $this->controller->submit_task_result( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'task_not_found', $result->get_error_code() );
	}

	// ==================== Route Registration ====================

	public function test_register_routes_does_not_throw() {
		$controller = new Client_Tasks_REST_Controller();
		$exception_thrown = false;

		try {
			$controller->register_routes();
		} catch ( \Throwable $e ) {
			$exception_thrown = true;
		}

		$this->assertFalse( $exception_thrown, 'register_routes() should not throw' );
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

