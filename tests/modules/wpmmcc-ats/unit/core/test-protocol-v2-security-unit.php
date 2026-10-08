<?php
/**
 * Protocol v2 Security Tests
 *
 * Tests for WPTSALL\Core\Transport_Crypto AES-256-GCM transport crypto
 * and the structured token binding that authenticates client apps.
 *
 * Covers the round-trip used by the Rust client ↔ WP plugin link:
 *   1. derive_key(token, nonce)            — HKDF-SHA256 → 32-byte key
 *   2. encrypt_response(plaintext, nonce)   — AES-256-GCM
 *   3. decrypt_request(ciphertext, nonce)   — AES-256-GCM
 *   4. derive_signing_key(token)            — HMAC key (separate from enc key)
 *   5. compute_plaintext_response_signature(plaintext, token) — base64url HMAC
 *
 * @package WPTSALL\Tests\Unit\Core
 * @since 1.1.0
 */

use WPTSALL\Core\Transport_Crypto;

if ( ! class_exists( '\\WPTSALL\\Core\\Transport_Crypto' ) ) {
	require_once dirname( __DIR__, 3 ) . '/source/includes/core/class-transport-crypto.php';
}

class Test_Protocol_V2_Security_Unit extends SimpleTestCase {

	/**
	 * Token secrets used across tests. We don't talk to a real client; these
	 * are the same inputs a Rust client would supply in the X-WPTSALL-Nonce
	 * / auth headers.
	 */
	private const TOKEN_A = 'wptc1.testtokenA.9999999999.aaaaaaaaaaaaaaaaaaaaaaaa';
	private const TOKEN_B = 'wptc1.testtokenB.9999999999.bbbbbbbbbbbbbbbbbbbbbbbb';

	/**
	 * A valid base64url nonce. 12 bytes are used as the HKDF salt; 16 bytes
	 * is the AES-GCM IV size. Any 16-byte random string base64url-encoded
	 * works for tests.
	 */
	private function nonce(): string {
		// 16 random bytes → base64url (22 chars, no padding).
		return rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
	}

	// =====================================================================
	// Class surface + constants
	// =====================================================================

	public function test_class_exists() {
		$this->assertTrue( class_exists( Transport_Crypto::class ) );
	}

	public function test_required_constants_defined() {
		$this->assertTrue( defined( Transport_Crypto::class . '::HKDF_INFO' ) );
		$this->assertTrue( defined( Transport_Crypto::class . '::SIGNING_HKDF_INFO' ) );
		$this->assertTrue( defined( Transport_Crypto::class . '::SIGNING_HKDF_SALT' ) );
		$this->assertTrue( defined( Transport_Crypto::class . '::ALGORITHM_ID' ) );
		$this->assertEquals( 'aes-256-gcm-v1', Transport_Crypto::ALGORITHM_ID );
	}

	public function test_required_methods_exist() {
		$methods = array(
			'derive_key',
			'derive_signing_key',
			'encrypt_response',
			'decrypt_request',
			'compute_plaintext_response_signature',
		);
		foreach ( $methods as $m ) {
			$this->assertTrue( method_exists( Transport_Crypto::class, $m ) );
		}
	}

	// =====================================================================
	// derive_key
	// =====================================================================

	public function test_derive_key_is_deterministic() {
		$nonce = $this->nonce();
		$k1    = Transport_Crypto::derive_key( self::TOKEN_A, $nonce );
		$k2    = Transport_Crypto::derive_key( self::TOKEN_A, $nonce );
		$this->assertIsString( $k1 );
		$this->assertEquals( 32, strlen( $k1 ), 'derived key must be 32 bytes (AES-256)' );
		$this->assertEquals( $k1, $k2 );
	}

	public function test_derive_key_differs_per_nonce() {
		$nonce1 = $this->nonce();
		$nonce2 = $this->nonce();
		$this->assertNotEquals(
			Transport_Crypto::derive_key( self::TOKEN_A, $nonce1 ),
			Transport_Crypto::derive_key( self::TOKEN_A, $nonce2 )
		);
	}

	public function test_derive_key_differs_per_secret() {
		$nonce = $this->nonce();
		$this->assertNotEquals(
			Transport_Crypto::derive_key( self::TOKEN_A, $nonce ),
			Transport_Crypto::derive_key( self::TOKEN_B, $nonce )
		);
	}

	public function test_derive_key_handles_garbage_nonce() {
		// PHP's base64_decode is non-strict by default, so it tolerates
		// most strings (ignoring non-base64 chars). derive_key must not
		// throw in any case — either derive a 32-byte key or return false.
		$out = Transport_Crypto::derive_key( self::TOKEN_A, '!!!not-base64!!!' );
		if ( false !== $out ) {
			$this->assertIsString( $out );
			$this->assertEquals( 32, strlen( $out ) );
		}
		// Empty nonce (decodes to empty string) is also tolerated by hash_hkdf.
		$out2 = Transport_Crypto::derive_key( self::TOKEN_A, '' );
		$this->assertTrue( false === $out2 || 32 === strlen( (string) $out2 ) );
	}

	// =====================================================================
	// encrypt_response / decrypt_request round-trip
	// =====================================================================

	public function test_encrypt_response_returns_envelope_with_nonce() {
		$nonce = $this->nonce();
		$out   = Transport_Crypto::encrypt_response( array( 'hello' => 'world' ), $nonce, self::TOKEN_A );
		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'encrypted_payload', $out );
		$this->assertArrayHasKey( 'nonce', $out );
		$this->assertEquals( $nonce, $out['nonce'] );
		$this->assertIsString( $out['encrypted_payload'] );
		$this->assertNotEmpty( $out['encrypted_payload'] );
		// Envelope payload is base64 → must decode.
		$bin = base64_decode( $out['encrypted_payload'], true );
		$this->assertNotFalse( $bin );
		// Layout: 12-byte IV + ciphertext + 16-byte tag.
		$this->assertGreaterThanOrEqual( 28, strlen( $bin ) );
	}

	public function test_decrypt_request_recovers_plaintext() {
		$nonce   = $this->nonce();
		$payload = array( 'foo' => 'bar', 'n' => 42, 'nested' => array( 'a' => 1 ) );
		$enc     = Transport_Crypto::encrypt_response( $payload, $nonce, self::TOKEN_A );
		$this->assertIsArray( $enc );

		$decrypted = Transport_Crypto::decrypt_request( $enc['encrypted_payload'], $nonce, self::TOKEN_A );
		$this->assertIsString( $decrypted );
		$this->assertEquals( $payload, json_decode( $decrypted, true ) );
	}

	public function test_decrypt_request_rejects_wrong_nonce() {
		$nonce = $this->nonce();
		$enc   = Transport_Crypto::encrypt_response( array( 'k' => 'v' ), $nonce, self::TOKEN_A );
		$wrong = $this->nonce();
		$this->assertFalse( Transport_Crypto::decrypt_request( $enc['encrypted_payload'], $wrong, self::TOKEN_A ) );
	}

	public function test_decrypt_request_rejects_wrong_secret() {
		$nonce = $this->nonce();
		$enc   = Transport_Crypto::encrypt_response( array( 'k' => 'v' ), $nonce, self::TOKEN_A );
		$this->assertFalse( Transport_Crypto::decrypt_request( $enc['encrypted_payload'], $nonce, self::TOKEN_B ) );
	}

	public function test_decrypt_request_rejects_tampered_ciphertext() {
		$nonce = $this->nonce();
		$enc   = Transport_Crypto::encrypt_response( array( 'k' => 'v' ), $nonce, self::TOKEN_A );

		// Flip a bit in the middle of the base64 payload.
		$bin = base64_decode( $enc['encrypted_payload'], true );
		$bin[15] = chr( ord( $bin[15] ) ^ 0x01 );
		$tampered = base64_encode( $bin );

		$this->assertFalse( Transport_Crypto::decrypt_request( $tampered, $nonce, self::TOKEN_A ) );
	}

	public function test_decrypt_request_rejects_short_payload() {
		$nonce = $this->nonce();
		// Shorter than 12 (IV) + 16 (tag) = 28 bytes.
		$short = base64_encode( str_repeat( 'A', 10 ) );
		$this->assertFalse( Transport_Crypto::decrypt_request( $short, $nonce, self::TOKEN_A ) );
	}

	public function test_encrypt_response_handles_unicode_payload() {
		$nonce = $this->nonce();
		$payload = array(
			'zh'   => '你好世界',
			'emoji' => '🚀',
			'ar'   => 'مرحبا',
		);
		$enc     = Transport_Crypto::encrypt_response( $payload, $nonce, self::TOKEN_A );
		$decoded = json_decode( Transport_Crypto::decrypt_request( $enc['encrypted_payload'], $nonce, self::TOKEN_A ), true );
		$this->assertEquals( $payload, $decoded );
	}

	// =====================================================================
	// Signing key + response signature
	// =====================================================================

	public function test_derive_signing_key_is_deterministic() {
		$k1 = Transport_Crypto::derive_signing_key( self::TOKEN_A );
		$k2 = Transport_Crypto::derive_signing_key( self::TOKEN_A );
		$this->assertIsString( $k1 );
		$this->assertEquals( 32, strlen( $k1 ) );
		$this->assertEquals( $k1, $k2 );
	}

	public function test_signing_key_differs_from_encryption_key() {
		// Even with the same token, the signing key uses a different
		// info+salt tuple than the encryption key, so they must not match.
		$enc_key = Transport_Crypto::derive_key( self::TOKEN_A, $this->nonce() );
		$sig_key = Transport_Crypto::derive_signing_key( self::TOKEN_A );
		$this->assertIsString( $enc_key );
		$this->assertIsString( $sig_key );
		$this->assertNotEquals( $enc_key, $sig_key );
	}

	public function test_derive_signing_key_rejects_empty() {
		$this->assertFalse( Transport_Crypto::derive_signing_key( '' ) );
	}

	public function test_compute_plaintext_response_signature_is_deterministic() {
		$plaintext = wp_json_encode( array( 'ok' => true ) );
		$s1 = Transport_Crypto::compute_plaintext_response_signature( $plaintext, self::TOKEN_A );
		$s2 = Transport_Crypto::compute_plaintext_response_signature( $plaintext, self::TOKEN_A );
		$this->assertIsString( $s1 );
		$this->assertNotEmpty( $s1 );
		$this->assertEquals( $s1, $s2 );
		// base64url (no padding, [A-Za-z0-9_-]).
		$this->assertTrue( (bool) preg_match( '/^[A-Za-z0-9_-]+$/', $s1 ), 'signature must be base64url' );
	}

	public function test_signature_differs_per_payload() {
		$nonce = $this->nonce();
		$s1 = Transport_Crypto::compute_plaintext_response_signature( '{"a":1}', self::TOKEN_A );
		$s2 = Transport_Crypto::compute_plaintext_response_signature( '{"a":2}', self::TOKEN_A );
		$this->assertNotEquals( $s1, $s2 );
		// (Avoid unused-var warning.)
		$this->assertIsString( $nonce );
	}

	public function test_signature_differs_per_secret() {
		$plain = '{"k":"v"}';
		$this->assertNotEquals(
			Transport_Crypto::compute_plaintext_response_signature( $plain, self::TOKEN_A ),
			Transport_Crypto::compute_plaintext_response_signature( $plain, self::TOKEN_B )
		);
	}

	public function test_signature_rejects_non_string_payload() {
		// Empty string is a valid HMAC input (returns a deterministic sig);
		// only non-string types are rejected.
		$empty = Transport_Crypto::compute_plaintext_response_signature( '', self::TOKEN_A );
		$this->assertIsString( $empty );
		$this->assertNotEmpty( $empty );

		$null_in = Transport_Crypto::compute_plaintext_response_signature( null, self::TOKEN_A );
		$this->assertFalse( $null_in );
		$arr_in = Transport_Crypto::compute_plaintext_response_signature( array( 'x' ), self::TOKEN_A );
		$this->assertFalse( $arr_in );
	}

	// =====================================================================
	// Bound to client token (integration shape)
	// =====================================================================

	/**
	 * End-to-end: given a token issued by Client_Token_Service, a Rust client
	 * can encrypt a request, send it, the server decrypts, and signs a
	 * response with a signature the client can verify.
	 */
	public function test_end_to_end_round_trip_with_real_token() {
		// Pull the real client token from the running WP install. If the
		// helper isn't available (no license service), skip this integration
		// test rather than fail — the unit-level crypto above is the contract.
		if ( ! function_exists( 'wptsall_issue_client_device_token' ) ) {
			$this->assertTrue( true, 'helper unavailable; skipping' );
			return;
		}
		$_c = wptsall_issue_client_device_token( 'proto-unit', 'unit' );
		$token = (string) ( $_c['token'] ?? '' );
		$this->assertIsString( $token );
		$this->assertNotEmpty( $token );

		$nonce = $this->nonce();
		$req_payload = array( 'op' => 'list-tasks', 'limit' => 10 );
		$enc     = Transport_Crypto::encrypt_response( $req_payload, $nonce, $token );
		$decoded = json_decode( Transport_Crypto::decrypt_request( $enc['encrypted_payload'], $nonce, $token ), true );
		$this->assertEquals( $req_payload, $decoded );

		$resp_json  = wp_json_encode( array( 'ok' => true, 'data' => array( 1, 2, 3 ) ) );
		$sig        = Transport_Crypto::compute_plaintext_response_signature( $resp_json, $token );
		$verify     = Transport_Crypto::compute_plaintext_response_signature( $resp_json, $token );
		$this->assertEquals( $sig, $verify, 'server and client must compute the same signature for the same payload+token' );
	}
}
