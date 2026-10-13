<?php
/**
 * Client Rules Route Tests (G-14)
 *
 * REST-dispatch coverage for GET /{secret}/client/rules — the discovery
 * rules endpoint in the CLIENT-auth family
 * (class-client-data-rest-controller.php, discovery trait get_rules).
 *
 * Exercises the full client-auth chain through the real WP_REST_Server:
 * protocol version negotiation, device-token verification (positive +
 * negative), and the get_rules handler contract (missing parameter,
 * unknown relation, happy path on an active relation).
 *
 * catalog: WP-REST-wptsall-client-rules
 * oracle: L1
 *
 * In-process harness opt-outs (mirrors integration/run.php): this file
 * dispatches secret-prefixed client routes over plain HTTP without
 * replaying the Rust client's signing path. Without these, the transport
 * middleware correctly 400s every dispatch (transport_encryption_required
 * — the CLI runner is non-SSL with the default https_optional policy).
 * Before 2026-09-21 the file only passed by the accident of a lab plugin
 * swallowing the middleware's WP_Error on rest_pre_dispatch; when
 * test-transport-middleware.php (core/) stripped that swallower
 * mid-suite, all 7 dispatch tests went red. Core's signature-enforcement
 * tests run in core/ — before this file — so the constant cannot reach
 * them.
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

if ( ! defined( 'WPTSALL_TEST_ONLY_TRANSPORT_SIGNATURE_FALLBACK' ) ) {
	define( 'WPTSALL_TEST_ONLY_TRANSPORT_SIGNATURE_FALLBACK', true );
}

class Test_Client_Rules_Route extends WP_UnitTestCase {

	/**
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * @var string Device id issued in setUp.
	 */
	private $device_id = '';

	/**
	 * @var string Plaintext device token issued in setUp.
	 */
	private $client_token = '';

	/**
	 * @var string Route secret prefix.
	 */
	private $secret = '';

	/**
	 * @var mixed Original wptsall_client_devices option value.
	 */
	private $orig_devices_option = null;

	/**
	 * @var array<int, int> Site relation row ids created by this run.
	 */
	private $relation_ids = array();

	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wptsall_issue_client_device_token' ) || ! function_exists( 'wptsall_get_client_route_secret' ) ) {
			$this->markTestSkipped( 'client-pairing module not loaded' );
			return;
		}

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Documented middleware integration seam: exercise client routes
		// in-process over plain HTTP without the app-layer encryption gate.
		// tearDown() removes it so later files see the production default.
		remove_all_filters( 'wptsall_client_transport_encryption_required' );
		add_filter( 'wptsall_client_transport_encryption_required', '__return_false' );

		// Client routes must work without any logged-in WP user.
		wp_set_current_user( 0 );

		$this->secret              = (string) wptsall_get_client_route_secret();
		$this->orig_devices_option = get_option( 'wptsall_client_devices', null );

		$issued            = wptsall_issue_client_device_token( 'unit-rules-device', 'unit-rules' );
		$this->device_id   = (string) ( $issued['device_id'] ?? '' );
		$this->client_token = (string) ( $issued['token'] ?? '' );
	}

	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		// Drop the in-process encryption-gate opt-out (see setUp).
		remove_all_filters( 'wptsall_client_transport_encryption_required' );

		// Restore the devices option exactly (the issued token must not leak
		// into subsequent test files).
		if ( null !== $this->orig_devices_option ) {
			update_option( 'wptsall_client_devices', $this->orig_devices_option, false );
		} else {
			delete_option( 'wptsall_client_devices' );
		}

		$relations_table = wptsall_table( 'site_relations' );
		foreach ( $this->relation_ids as $relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $relations_table, array( 'id' => $relation_id ), array( '%d' ) );
		}
		$this->relation_ids = array();

		parent::tearDown();
	}

	private function rules_route(): string {
		return '/wptsall/v2/' . $this->secret . '/client/rules';
	}

	/**
	 * Headers of a fully-authenticated Protocol v2 client that also satisfies
	 * the production storage-map contract gate (X-Client-Version >= 2.1).
	 */
	private function valid_headers(): array {
		return array(
			'X-WPTSALL-Protocol-Version' => '2',
			'X-WPTSALL-Device-Id'        => $this->device_id,
			'X-WPTSALL-Client-Token'     => $this->client_token,
			'X-Client-Version'           => '2.2.0',
		);
	}

	private function dispatch_rules( array $params = array(), array $headers = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $this->rules_route() );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, (string) $value );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	private function seed_active_relation(): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'zz-rules-' . uniqid(),
				'target_site_id'   => 'v_rules_' . uniqid(),
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
		return $relation_id;
	}

	// ========================================
	// Client-auth negative paths
	// ========================================

	public function test_rules_route_rejects_missing_protocol_version() {
		$this->assertNotSame( '', $this->secret, 'route secret must be configured' );

		// No headers at all: protocol negotiation runs first and rejects.
		$response = $this->dispatch_rules();
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'missing_protocol_version', $response->get_data()['code'] );
	}

	public function test_rules_route_rejects_missing_client_token() {
		$response = $this->dispatch_rules( array(), array(
			'X-WPTSALL-Protocol-Version' => '2',
			'X-WPTSALL-Device-Id'        => $this->device_id,
		) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'client_unauthorized', $response->get_data()['code'] );
	}

	public function test_rules_route_rejects_invalid_client_token() {
		$response = $this->dispatch_rules( array(), array(
			'X-WPTSALL-Protocol-Version' => '2',
			'X-WPTSALL-Device-Id'        => 'unit-rules-device',
			'X-WPTSALL-Client-Token'     => 'completely-invalid-token',
		) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'client_unauthorized', $response->get_data()['code'] );
	}

	public function test_rules_route_rejects_token_without_device_id() {
		$response = $this->dispatch_rules( array(), array(
			'X-WPTSALL-Protocol-Version' => '2',
			'X-WPTSALL-Client-Token'     => $this->client_token,
		) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'client_device_required', $response->get_data()['code'] );
	}

	// ========================================
	// Handler contract (authenticated)
	// ========================================

	public function test_rules_route_requires_relation_or_model_id() {
		$response = $this->dispatch_rules( array(), $this->valid_headers() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'missing_parameter', $response->get_data()['error'] );
	}

	public function test_rules_route_rejects_unknown_relation() {
		$response = $this->dispatch_rules( array( 'relation_id' => 999999999 ), $this->valid_headers() );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'relation_not_found', $response->get_data()['error'] );
	}

	public function test_rules_route_returns_rules_for_active_relation() {
		$relation_id = $this->seed_active_relation();

		// Happy path with a real device token and no logged-in WP user:
		// the relation has no bound models, so the rules list is empty.
		$response = $this->dispatch_rules( array( 'relation_id' => $relation_id ), $this->valid_headers() );
		$this->assertSame( 200, $response->get_status(), 'rules discovery must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertIsArray( $data['rules'] );
		$this->assertSame( array(), $data['rules'] );
	}
}
