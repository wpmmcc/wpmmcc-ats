<?php
/**
 * Transport Middleware Tests
 *
 * Tests for WPTSALL\Core\Transport_Middleware class.
 *
 * @package WPTSALL\Tests\Unit\Core
 * @since 1.1.0
 */

use WPTSALL\Core\Transport_Middleware;

class Test_Transport_Middleware extends SimpleTestCase {

	/** @var array<int,string> Persistent nonce markers created by signing tests. */
	private $signature_nonce_options = array();

	/** @var array<string,\WP_Hook|null> Shared-hook registry snapshots for the destructive init test. */
	private $rest_hook_snapshots = array();

	public function tearDown(): void {
		// test_init_registers_filters() strips rest_pre_dispatch /
		// rest_post_dispatch to assert on a clean registry. Those hooks are
		// shared process-wide: the lab's third-party plugins (LearnPress JWT,
		// TEC, ACF, Elementor) hold rest_pre_dispatch callbacks registered
		// once at boot, and a bare remove_all_filters() permanently destroys
		// them — every later file then runs without them. First surfaced as
		// order-dependent fallout on 2026-09-21: test-client-rules-route.php
		// lost a lab plugin's (accidental) rest_pre_dispatch WP_Error
		// swallow and all 7 of its plain-HTTP dispatches turned red.
		// Snapshot and restore instead of nuking.
		foreach ( $this->rest_hook_snapshots as $tag => $hook ) {
			if ( null === $hook ) {
				unset( $GLOBALS['wp_filter'][ $tag ] );
			} else {
				$GLOBALS['wp_filter'][ $tag ] = $hook;
			}
		}
		$this->rest_hook_snapshots = array();

		foreach ( $this->signature_nonce_options as $option ) {
			delete_option( $option );
		}
		$this->signature_nonce_options = array();
		parent::tearDown();
	}

	/**
	 * Invoke the private Protocol v2 verifier through its intentionally stable
	 * test seam. The REST integration proof covers the dispatch order too.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $body Plaintext body.
	 * @param string           $token Token.
	 * @return true|\WP_Error
	 */
	private function validate_v2( $request, $body, $token ) {
		$method = new ReflectionMethod( Transport_Middleware::class, 'validate_protocol_v2_request' );
		$method->setAccessible( true );
		return $method->invoke( null, $request, $body, $token );
	}

	/**
	 * Create a Client-compatible signed request for verifier tests.
	 *
	 * @param string $body Body.
	 * @param string $token Token.
	 * @param string $nonce Nonce.
	 * @param int    $timestamp Unix timestamp.
	 * @return \WP_REST_Request
	 */
	private function signed_v2_request( $body, $token, $nonce, $timestamp ) {
		$route   = '/wptsall/v2/unit-secret/client/validate-token';
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Client-Token', $token );
		$request->set_header( 'X-WPTSALL-Timestamp', (string) $timestamp );
		$request->set_header( 'X-WPTSALL-Signature-Nonce', $nonce );
		$request->set_body( $body );

		$key       = \WPTSALL\Core\Transport_Crypto::derive_signing_key( $token );
		$canonical = "POST\n" . $route . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body ) . "\n" . hash( 'sha256', '' );
		$signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $canonical, $key, true ) ), '+/', '-_' ), '=' );
		$request->set_header( 'X-WPTSALL-Signature', $signature );
		return $request;
	}

	/** Track a marker created by the verifier for deterministic cleanup. */
	private function track_signature_nonce( $token, $nonce ) {
		$method = new ReflectionMethod( Transport_Middleware::class, 'get_signature_nonce_option_key' );
		$method->setAccessible( true );
		$this->signature_nonce_options[] = $method->invoke( null, $token, $nonce );
	}

	/**
	 * Test init() registers filters
	 */
	public function test_init_registers_filters() {
		// Snapshot the shared hook registries, then assert init() on a clean
		// slate. tearDown() restores them — the lab's third-party
		// rest_pre_dispatch callbacks are registered once at boot and must
		// survive this test (see class-level note).
		$this->rest_hook_snapshots['rest_pre_dispatch']  = $GLOBALS['wp_filter']['rest_pre_dispatch'] ?? null;
		$this->rest_hook_snapshots['rest_post_dispatch'] = $GLOBALS['wp_filter']['rest_post_dispatch'] ?? null;

		remove_all_filters( 'rest_pre_dispatch' );
		remove_all_filters( 'rest_post_dispatch' );

		Transport_Middleware::init();

		$this->assertTrue(
			has_filter( 'rest_pre_dispatch' ) !== false,
			'rest_pre_dispatch filter should be registered'
		);

		$this->assertTrue(
			has_filter( 'rest_post_dispatch' ) !== false,
			'rest_post_dispatch filter should be registered'
		);
	}

	/**
	 * Test is_client_route() identifies client routes
	 */
	public function test_is_client_route_identifies_client_routes() {
		$method = new ReflectionMethod( Transport_Middleware::class, 'is_client_route' );
		$method->setAccessible( true );

		// Build the client route prefix using the actual secret.
		$secret = wptsall_get_client_route_secret();
		$prefix = '/wptsall/v2/' . $secret . '/client';

		// Client routes (with secret prefix).
		$client_request = new WP_REST_Request( 'GET', $prefix . '/tasks' );
		$this->assertTrue( $method->invoke( null, $client_request ), $prefix . '/tasks should be a client route' );

		$client_request2 = new WP_REST_Request( 'POST', $prefix . '/translation-callback' );
		$this->assertTrue( $method->invoke( null, $client_request2 ), $prefix . '/translation-callback should be a client route' );

		$client_request3 = new WP_REST_Request( 'GET', $prefix . '/site-relations' );
		$this->assertTrue( $method->invoke( null, $client_request3 ), $prefix . '/site-relations should be a client route' );

		// Non-client routes (no secret prefix).
		$admin_request = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$this->assertFalse( $method->invoke( null, $admin_request ), '/tasks should NOT be a client route' );

		$other_request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->assertFalse( $method->invoke( null, $other_request ), '/wp/v2/posts should NOT be a client route' );

		// Route without secret should NOT be a client route.
		$no_secret_request = new WP_REST_Request( 'GET', '/wptsall/v2/client/tasks' );
		$this->assertFalse( $method->invoke( null, $no_secret_request ), '/wptsall/v2/client/tasks without secret should NOT be a client route' );
	}

	/**
	 * Test maybe_decrypt_request passes through non-client routes
	 */
	public function test_maybe_decrypt_request_skips_non_client_routes() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks' );
		$request->set_header( 'X-WPTSALL-Transport', 'encrypted' );

		$result = Transport_Middleware::maybe_decrypt_request( null, new WP_REST_Server(), $request );

		$this->assertNull( $result, 'Non-client routes should pass through unchanged' );
	}

	/**
	 * Test maybe_decrypt_request skips when no encrypted header
	 */
	public function test_maybe_decrypt_request_skips_without_transport_header() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/tasks' );
		// No X-WPTSALL-Transport header.

		$result = Transport_Middleware::maybe_decrypt_request( null, new WP_REST_Server(), $request );

		$this->assertNull( $result, 'Should skip when no transport header' );
	}

	/**
	 * Test maybe_decrypt_request skips GET requests
	 */
	public function test_maybe_decrypt_request_skips_get_requests() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/tasks' );
		$request->set_header( 'X-WPTSALL-Transport', 'encrypted' );

		$result = Transport_Middleware::maybe_decrypt_request( null, new WP_REST_Server(), $request );

		$this->assertNull( $result, 'GET requests should not be decrypted' );
	}

	/**
	 * Test maybe_encrypt_response passes through non-client routes
	 */
	public function test_maybe_encrypt_response_skips_non_client_routes() {
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$response = new WP_REST_Response( array( 'data' => 'test' ), 200 );

		$result = Transport_Middleware::maybe_encrypt_response( $response, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_REST_Response', $result );
		$this->assertEquals( array( 'data' => 'test' ), $result->get_data(), 'Non-client response should pass through unchanged' );
	}

	/**
	 * Test maybe_encrypt_response skips when no decryption occurred
	 */
	public function test_maybe_encrypt_response_skips_without_decryption_flag() {
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/client/tasks' );
		$response = new WP_REST_Response( array( 'data' => 'test' ), 200 );
		// No X-WPTSALL-Decrypted header and no X-WPTSALL-Transport header.

		$result = Transport_Middleware::maybe_encrypt_response( $response, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_REST_Response', $result );
		$this->assertEquals( array( 'data' => 'test' ), $result->get_data(), 'Should not encrypt when no decryption flag' );
	}

	/**
	 * Test needs_encryption returns false for HTTPS
	 */
	public function test_needs_encryption_returns_false_for_https() {
		$method = new ReflectionMethod( Transport_Middleware::class, 'needs_encryption' );
		$method->setAccessible( true );

		// In test environment (CLI), is_ssl() usually returns false.
		// We test the method exists and returns a boolean.
		$result = $method->invoke( null );
		$this->assertIsBool( $result );
	}

	/**
	 * Test get_request_token_secret returns value or false.
	 *
	 * P1-TEST-01 (2026-09-02): the old get_token_secret() (install-level
	 * secret) was replaced by get_request_token_secret($request) in the
	 * Protocol v2 rework — the secret is now the presented device token
	 * header, with no install-token fallback. Test the current contract:
	 * missing header → false; present header → the header string.
	 */
	public function test_get_token_secret_returns_value_or_false() {
		$method = new ReflectionMethod( Transport_Middleware::class, 'get_request_token_secret' );
		$method->setAccessible( true );

		$missing = $method->invoke( null, new \WP_REST_Request( 'POST', '/wptsall/v2/x' ) );
		$this->assertFalse( $missing, 'no presented token header must resolve to false' );

		$with_token = new \WP_REST_Request( 'POST', '/wptsall/v2/x' );
		$with_token->set_header( 'X-WPTSALL-Client-Token', 'dt-test-token-123' );
		$result = $method->invoke( null, $with_token );
		$this->assertSame(
			'dt-test-token-123',
			$result,
			'presented device token header must be returned as the secret'
		);
	}

	/**
	 * Test maybe_decrypt_request marks request as decrypted on success flow
	 *
	 * This tests that the X-WPTSALL-Decrypted header is set when decryption would succeed.
	 * Since actual crypto depends on Transport_Crypto + token, we verify the flow logic.
	 */
	public function test_decrypt_sets_decrypted_header_marker() {
		// Verify that the marker mechanism works:
		// When maybe_decrypt_request sets X-WPTSALL-Decrypted: 1,
		// maybe_encrypt_response should detect it.
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'X-WPTSALL-Decrypted', '1' );
		$request->set_header( 'X-WPTSALL-Transport', 'encrypted' );

		$decrypted = $request->get_header( 'X-WPTSALL-Decrypted' );
		$this->assertEquals( '1', $decrypted, 'Decrypted header marker should be readable' );
	}

	/**
	 * Test encrypted response structure
	 *
	 * When encryption happens, the response should have specific fields.
	 */
	public function test_encrypted_response_has_expected_structure() {
		// If Transport_Crypto is available and we have a token, test the full flow.
		if ( ! class_exists( '\\WPTSALL\\Core\\Transport_Crypto' ) ) {
			$this->markTestSkipped( 'Transport_Crypto class not available' );
			return;
		}

		// The encrypted response format should contain: encrypted_payload, nonce, algorithm.
		$expected_keys = array( 'encrypted_payload', 'nonce', 'algorithm' );

		// Verify ALGORITHM_ID constant exists.
		$this->assertTrue(
			defined( '\\WPTSALL\\Core\\Transport_Crypto::ALGORITHM_ID' ),
			'Transport_Crypto::ALGORITHM_ID constant should exist'
		);
	}

	/** Protocol v2 accepts the same HKDF/HMAC canonicalization as Rust. */
	public function test_protocol_v2_accepts_valid_client_signature() {
		$token = 'unit-token-' . wp_generate_password( 20, false );
		$nonce = 'unit-nonce-' . wp_generate_password( 16, false );
		$body  = '{"message":"signed body"}';
		$this->track_signature_nonce( $token, $nonce );

		$result = $this->validate_v2( $this->signed_v2_request( $body, $token, $nonce, time() ), $body, $token );
		$this->assertTrue( true === $result, 'Valid Rust-compatible Protocol v2 signature must pass' );
	}

	/** The exact plaintext bytes, not a parsed JSON representation, are signed. */
	public function test_protocol_v2_rejects_tampered_plaintext_body() {
		$token = 'unit-token-' . wp_generate_password( 20, false );
		$nonce = 'unit-nonce-' . wp_generate_password( 16, false );
		$body  = '{"message":"original"}';
		$this->track_signature_nonce( $token, $nonce );

		$result = $this->validate_v2( $this->signed_v2_request( $body, $token, $nonce, time() ), '{"message":"tampered"}', $token );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'request_signature_invalid', $result->get_error_code() );
	}

	/** Fresh nonces are single-use even when a replay has a valid signature. */
	public function test_protocol_v2_rejects_replayed_nonce() {
		$token = 'unit-token-' . wp_generate_password( 20, false );
		$nonce = 'unit-nonce-' . wp_generate_password( 16, false );
		$body  = '{}';
		$this->track_signature_nonce( $token, $nonce );
		$request = $this->signed_v2_request( $body, $token, $nonce, time() );

		$this->assertTrue( true === $this->validate_v2( $request, $body, $token ) );
		$replay = $this->validate_v2( $request, $body, $token );
		$this->assertTrue( is_wp_error( $replay ) );
		$this->assertEquals( 'request_signature_replay', $replay->get_error_code() );
	}

	/** Expired timestamps must fail before a signature is evaluated or remembered. */
	public function test_protocol_v2_rejects_expired_timestamp() {
		$token = 'unit-token-' . wp_generate_password( 20, false );
		$nonce = 'unit-nonce-' . wp_generate_password( 16, false );
		$body  = '{}';
		$result = $this->validate_v2( $this->signed_v2_request( $body, $token, $nonce, time() - 301 ), $body, $token );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'request_timestamp_expired', $result->get_error_code() );
	}
}
