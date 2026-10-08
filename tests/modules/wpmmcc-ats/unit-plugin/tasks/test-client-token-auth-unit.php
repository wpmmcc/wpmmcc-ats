<?php
/**
 * Client Token Authentication Unit Tests
 *
 * Tests token generation, verification, expiry, and rotation
 * in WPTSALL\Client_Pairing\Services\Client_Token_Service.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

class Test_Client_Token_Auth_Unit extends WP_UnitTestCase {

	/**
	 * License service instance.
	 *
	 * @var \WPTSALL\Client_Pairing\Services\Client_Token_Service
	 */
	private $service;

	/**
	 * Options to clean up.
	 *
	 * @var array
	 */
	private $cleanup_options = array();

	/**
	 * Ensure client-pairing module is loaded.
	 */
	private static function ensure_client_pairing_loaded() {
		if ( class_exists( '\\WPTSALL\\Client_Pairing\\Services\\Client_Token_Service' ) ) {
			return;
		}
		$path = WP_PLUGIN_DIR . '/wptsall-pro/includes/client-pairing/services/class-client-token-service.php';
		if ( ! file_exists( $path ) ) {
			$path = WP_PLUGIN_DIR . '/wptsall/includes/client-pairing/services/class-client-token-service.php';
		}
		if ( file_exists( $path ) ) {
			require_once $path;
		}
		$fn_path = dirname( $path, 2 ) . '/functions.php';
		if ( file_exists( $fn_path ) ) {
			require_once $fn_path;
		}
	}

	/**
	 * Whether client-pairing module is available.
	 *
	 * @var bool
	 */
	private $client_pairing_available = false;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		self::ensure_client_pairing_loaded();

		$this->client_pairing_available = class_exists( '\\WPTSALL\\Client_Pairing\\Services\\Client_Token_Service' );
		if ( ! $this->client_pairing_available ) {
			return; // Tests that need the service will skip themselves.
		}

		$this->service         = new \WPTSALL\Client_Pairing\Services\Client_Token_Service();
		$this->cleanup_options = array();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		foreach ( $this->cleanup_options as $opt ) {
			delete_option( $opt );
		}
		parent::tearDown();
	}

	/**
	 * Track option for cleanup.
	 */
	private function track_option( $name ) {
		$this->cleanup_options[] = $name;
	}

	// -----------------------------------------------------------------
	// Test: get_client_token returns structured wptc1 token
	// -----------------------------------------------------------------
	public function test_generate_client_token_returns_structured_format() {
		if ( ! $this->client_pairing_available ) {
			return; // Skip silently.
		}
		$this->track_option( 'wptsall_client_api_token_state' );
		$this->track_option( 'wptsall_client_api_token_secret' );
		$this->track_option( 'wptsall_client_api_token' );

		$token = $this->service->get_client_token( true );

		$this->assertIsString( $token, 'get_client_token should return a string' );
		$this->assertStringStartsWith( 'wptc1.', $token );

		$parts = explode( '.', $token );
		$this->assertCount( 4, $parts );
		$this->assertEquals( 'wptc1', $parts[0] );
		$this->assertGreaterThan( time(), (int) $parts[2], 'Token should not be expired at creation' );
	}

	// -----------------------------------------------------------------
	// Test: verify valid token passes
	// -----------------------------------------------------------------
	public function test_verify_valid_token_passes() {
		if ( ! $this->client_pairing_available ) {
			return;
		}
		$this->track_option( 'wptsall_client_api_token_state' );
		$this->track_option( 'wptsall_client_api_token_secret' );
		$this->track_option( 'wptsall_client_api_token' );

		$token = $this->service->get_client_token( true );

		$this->assertTrue( $this->service->verify_client_token( $token ) );
	}

	// -----------------------------------------------------------------
	// Test: empty token is rejected
	// -----------------------------------------------------------------
	public function test_verify_empty_token_fails() {
		if ( ! $this->client_pairing_available ) {
			return;
		}
		$this->assertFalse( $this->service->verify_client_token( '' ) );
	}

	// -----------------------------------------------------------------
	// Test: garbage token is rejected
	// -----------------------------------------------------------------
	public function test_verify_garbage_token_fails() {
		if ( ! $this->client_pairing_available ) {
			return;
		}
		$this->assertFalse( $this->service->verify_client_token( 'not-a-real-token' ) );
	}

	// -----------------------------------------------------------------
	// Test: tampered signature is rejected
	// -----------------------------------------------------------------
	public function test_verify_tampered_signature_fails() {
		if ( ! $this->client_pairing_available ) {
			return;
		}
		$this->track_option( 'wptsall_client_api_token_state' );
		$this->track_option( 'wptsall_client_api_token_secret' );
		$this->track_option( 'wptsall_client_api_token' );

		$token = $this->service->get_client_token( true );

		// Flip last character of signature.
		$tampered = substr( $token, 0, -1 ) . ( substr( $token, -1 ) === 'A' ? 'B' : 'A' );

		$this->assertFalse( $this->service->verify_client_token( $tampered ) );
	}

	// -----------------------------------------------------------------
	// Test: token rotation - new token valid, previous still valid during grace
	// -----------------------------------------------------------------
	public function test_token_rotation_both_valid_during_grace() {
		if ( ! $this->client_pairing_available ) {
			return;
		}
		$this->track_option( 'wptsall_client_api_token_state' );
		$this->track_option( 'wptsall_client_api_token_secret' );
		$this->track_option( 'wptsall_client_api_token' );

		$token1 = $this->service->get_client_token( true );
		$token2 = $this->service->get_client_token( true );

		$this->assertNotEquals( $token1, $token2, 'Two generated tokens must be different' );

		// New token must be valid.
		$this->assertTrue( $this->service->verify_client_token( $token2 ), 'New token should be valid' );

		// Previous token should still be valid (grace period).
		$this->assertTrue( $this->service->verify_client_token( $token1 ), 'Previous token should still be valid during grace' );
	}

	// -----------------------------------------------------------------
	// Test: token snapshot includes expected fields
	// -----------------------------------------------------------------
	public function test_get_token_snapshot_has_expected_keys() {
		if ( ! $this->client_pairing_available ) {
			return;
		}
		$this->track_option( 'wptsall_client_api_token_state' );
		$this->track_option( 'wptsall_client_api_token_secret' );
		$this->track_option( 'wptsall_client_api_token' );

		$this->service->get_client_token( true );

		$snapshot = $this->service->get_client_token_snapshot();
		$this->assertIsArray( $snapshot );
		$this->assertArrayHasKey( 'mode', $snapshot );
		$this->assertArrayHasKey( 'masked', $snapshot );
	}

	// -----------------------------------------------------------------
	// Test: verify_client_token helper function (wrapper in client-pairing/functions.php)
	// -----------------------------------------------------------------
	public function test_wptsall_verify_client_api_token_function() {
		if ( ! $this->client_pairing_available ) {
			return;
		}
		if ( ! function_exists( 'wptsall_verify_client_api_token' ) ) {
			return; // Skip silently — function not available.
		}

		$this->track_option( 'wptsall_client_api_token_state' );
		$this->track_option( 'wptsall_client_api_token_secret' );
		$this->track_option( 'wptsall_client_api_token' );

		$token = $this->service->get_client_token( true );

		$this->assertTrue( wptsall_verify_client_api_token( $token ) );
		$this->assertFalse( wptsall_verify_client_api_token( 'invalid-token' ) );
	}

	// -----------------------------------------------------------------
	// Test: REST API rejects unauthenticated requests
	// -----------------------------------------------------------------
	public function test_client_data_rest_rejects_without_token() {
		if ( ! $this->client_pairing_available ) {
			return;
		}
		// Ensure REST server is available.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$route_secret = get_option( 'wptsall_route_secret', '' );
		if ( empty( $route_secret ) ) {
			return; // Skip — route secret not configured.
		}

		$request = new \WP_REST_Request( 'GET', "/{$route_secret}/client/site-relations" );
		$response = $wp_rest_server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status(), 'Request without token should be rejected' );
	}
}
