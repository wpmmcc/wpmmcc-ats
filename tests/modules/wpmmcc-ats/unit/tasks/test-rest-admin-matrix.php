<?php
/**
 * REST Admin Matrix Tests (Lane D)
 *
 * REST-dispatch coverage for six wptsall/v2 routes that had zero catalog
 * references:
 *
 * - POST /{secret}/client/pairing/claim (WP-REST-wptsall-pairing-claim,
 *   class-client-data-rest-controller.php): one-time pairing code round trip
 *   via wptsall_create_site_connection_pack(), re-claim rejection, device
 *   binding rejection, and input validation.
 * - POST /site/generate-verification   (WP-REST-wptsall-site-generate-verification,
 *   class-site-rest-controller.php): admin gate 403 and the IP-hosted lab
 *   domain guard (invalid_domain 400 — slot sites run on 127.0.0.1).
 * - GET  /tasks/orchestration-stats    (WP-REST-wptsall-tasks-orchestration-stats,
 *   class-tasks-rest-controller.php): report shape.
 * - POST /tasks/retry-failed           (WP-REST-wptsall-tasks-retry-failed,
 *   class-tasks-rest-controller.php): failed/retry rows are requeued to
 *   pending, completed rows untouched.
 * - GET  /templates/match              (WP-REST-wptsall-templates-match,
 *   class-template-rest-controller.php): empty-branch shape.
 * - POST /custom-models/test-link-chain (WP-REST-wptsall-test-link-chain,
 *   class-custom-model-rest-controller.php): arg validation and chain
 *   structure validation branches.
 *
 * catalog: WP-REST-wptsall-pairing-claim
 * oracle: L1
 * catalog: WP-REST-wptsall-site-generate-verification
 * oracle: L1
 * catalog: WP-REST-wptsall-tasks-orchestration-stats
 * oracle: L1
 * catalog: WP-REST-wptsall-tasks-retry-failed
 * oracle: L1
 * catalog: WP-REST-wptsall-templates-match
 * oracle: L1
 * catalog: WP-REST-wptsall-test-link-chain
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

class Test_Rest_Admin_Matrix extends WP_UnitTestCase {

	/**
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * @var int
	 */
	protected $admin_id = 0;

	/**
	 * @var array<int, int> Task row ids created by this run.
	 */
	private $task_ids = array();

	/**
	 * @var array<int, int> Site relation row ids created by this run.
	 */
	private $relation_ids = array();

	/**
	 * @var mixed Original pairing-codes option value.
	 */
	private $orig_pairing_option = null;

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->orig_pairing_option = get_option( 'wptsall_client_pairing_codes', null );
	}

	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		$tasks_table = wptsall_table( 'tasks' );
		foreach ( $this->task_ids as $task_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $tasks_table, array( 'id' => $task_id ), array( '%d' ) );
		}
		$this->task_ids = array();

		$relations_table = wptsall_table( 'site_relations' );
		foreach ( $this->relation_ids as $relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $relations_table, array( 'id' => $relation_id ), array( '%d' ) );
		}
		$this->relation_ids = array();

		// Restore the pairing-codes option exactly.
		if ( null !== $this->orig_pairing_option ) {
			update_option( 'wptsall_client_pairing_codes', $this->orig_pairing_option, false );
		} else {
			delete_option( 'wptsall_client_pairing_codes' );
		}
		parent::tearDown();
	}

	private function dispatch( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	private function seed_task( string $status, int $object_id ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			wptsall_table( 'tasks' ),
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'site_id'     => 0,
				'template'    => 'zz_ram_' . uniqid(),
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => $object_id,
				'status'      => $status,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$this->assertNotEmpty( $wpdb->insert_id );
		$this->task_ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	public function test_pairing_claim_route_round_trip() {
		$secret  = function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '';
		$this->assertNotSame( '', $secret, 'route secret must be configured' );
		$route   = '/wptsall/v2/' . $secret . '/client/pairing/claim';
		$device  = 'dev_' . strtolower( uniqid() );

		// Input validation: missing device_id / pairing_code.
		$response = $this->dispatch( 'POST', $route, array( 'pairing_code' => 'WPTS-0000' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pairing_invalid_request', $response->get_data()['code'] );

		// Invalid code is rejected.
		$response = $this->dispatch( 'POST', $route, array(
			'device_id'    => $device,
			'pairing_code' => 'WPTS-DEADBEEFDEADBEEF',
		) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'pairing_code_invalid', $response->get_data()['code'] );

		// Issue a one-time pack and claim it with the bound device.
		$pack = wptsall_create_site_connection_pack( $device, 'Lane D pairing test', 300 );
		$this->assertIsArray( $pack );
		$this->assertNotEmpty( $pack['pairing_code'] );

		$response = $this->dispatch( 'POST', $route, array(
			'device_id'    => $device,
			'pairing_code' => $pack['pairing_code'],
		) );
		$this->assertSame( 200, $response->get_status(), 'claim must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data()['data'];
		$this->assertSame( $device, $data['device_id'] );
		$this->assertNotEmpty( $data['client_token'] );
		$this->assertGreaterThan( 0, (int) $data['expires_in'] );
		$this->assertContains( 'translate.read', $data['scopes'] );

		// The pairing code is one-time: a second claim is rejected.
		$response = $this->dispatch( 'POST', $route, array(
			'device_id'    => $device,
			'pairing_code' => $pack['pairing_code'],
		) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'pairing_code_invalid', $response->get_data()['code'] );

		// Cleanup the issued device token.
		if ( function_exists( 'wptsall_revoke_client_device_token' ) ) {
			wptsall_revoke_client_device_token( $device );
		}
	}

	public function test_site_generate_verification_route() {
		// Non-admin is forbidden.
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $other );
		$response = $this->dispatch( 'POST', '/wptsall/v2/site/generate-verification' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );

		// Admin on an IP-hosted lab site (127.0.0.1): the domain guard
		// rejects IP hosts deterministically.
		wp_set_current_user( $this->admin_id );
		$domain = wptsall_get_current_site_domain();
		$response = $this->dispatch( 'POST', '/wptsall/v2/site/generate-verification' );
		if ( wptsall_validate_domain_format( $domain ) ) {
			// Valid-domain environment: nonce is generated.
			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data()['data'];
			$this->assertNotEmpty( $data['nonce'] );
			$this->assertStringContainsString( 'nonce=', $data['verification_url'] );
		} else {
			// IP/localhost environment (the lab): 400 invalid_domain.
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'invalid_domain', $response->get_data()['code'] );
		}
	}

	public function test_tasks_orchestration_stats_route() {
		$response = $this->dispatch( 'GET', '/wptsall/v2/tasks/orchestration-stats' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$stats = $data['stats'];
		$this->assertIsArray( $stats );
		$this->assertIsInt( (int) $stats['total_relations'] );
		$this->assertIsInt( (int) $stats['total_tasks'] );
		$this->assertArrayHasKey( 'by_data_type', $stats );
		$this->assertArrayHasKey( 'by_direction', $stats );
		$this->assertArrayHasKey( 'post', $stats['by_data_type'] );
		$this->assertArrayHasKey( 'source_to_target', $stats['by_direction'] );
	}

	public function test_tasks_retry_failed_route() {
		$tag = crc32( uniqid() );

		$failed_id   = $this->seed_task( 'failed', 900000 + $tag );
		$retry_id    = $this->seed_task( 'retry', 900001 + $tag );
		$completed_id = $this->seed_task( 'completed', 900002 + $tag );

		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/retry-failed', array(
			'note' => 'Lane D retry test',
		) );
		$this->assertSame( 200, $response->get_status(), 'retry-failed must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data()['data'];
		$this->assertIsInt( (int) $data['updated'] );
		$this->assertIsArray( $data['updated_ids'] );
		$this->assertContains( $failed_id, array_map( 'intval', $data['updated_ids'] ) );
		$this->assertContains( $retry_id, array_map( 'intval', $data['updated_ids'] ) );
		$this->assertNotContains( $completed_id, array_map( 'intval', $data['updated_ids'] ), 'completed tasks must not be requeued' );

		// The requeued rows are now pending.
		global $wpdb;
		$tasks_table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tasks_table} WHERE id = %d", $failed_id ) );
		$this->assertSame( 'pending', $status );
	}

	public function test_templates_match_route_empty_branch() {
		// The permission boundary requires an existing relation_id, so a real
		// row is seeded; it has no language-pack templates, exercising the
		// handler's empty-branch shape.
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'zz-ram-' . uniqid(),
				'target_site_id'   => 'v_ram_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertNotEmpty( $wpdb->insert_id );
		$relation_id          = (int) $wpdb->insert_id;
		$this->relation_ids[] = $relation_id;

		$response = $this->dispatch( 'GET', '/wptsall/v2/templates/match', array(
			'relation_id' => $relation_id,
		) );
		$this->assertSame( 200, $response->get_status(), 'match must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertSame( $relation_id, (int) $data['relation_id'] );
		$this->assertSame( null, $data['theme'] );
		$this->assertSame( array(), $data['plugins'] );
		$this->assertSame( 0, (int) $data['summary']['total'] );
		$this->assertSame( 0, (int) $data['summary']['matched'] );
		$this->assertSame( 0, (int) $data['summary']['missing'] );

		// Unknown relation: rejected at the permission boundary.
		$response = $this->dispatch( 'GET', '/wptsall/v2/templates/match', array(
			'relation_id' => 999999999,
		) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_object_not_found', $response->get_data()['code'] );
	}

	public function test_custom_models_test_link_chain_route() {
		// Empty chain config: 400 invalid_chain_config.
		$response = $this->dispatch( 'POST', '/wptsall/v2/custom-models/test-link-chain', array(
			'chain_config' => array(),
			'sample_value' => '1',
		) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_chain_config', $response->get_data()['code'] );

		// Structurally invalid node: 200 with valid=false + errors.
		$response = $this->dispatch( 'POST', '/wptsall/v2/custom-models/test-link-chain', array(
			'chain_config' => array( array( 'garbage' => 'node' ) ),
			'sample_value' => '1',
		) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( (bool) $data['success'] );
		$this->assertFalse( (bool) $data['valid'] );
		$this->assertNotEmpty( $data['errors'] );
		$this->assertSame( null, $data['test_result'] );
	}
}
