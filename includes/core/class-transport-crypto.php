<?php
/**
 * Transport layer encryption for Client ↔ WP Plugin communication.
 *
 * @package WPTSALL\Core
 * @since 1.1.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transport_Crypto handles request/response encryption using AES-256-GCM.
 *
 * Key derivation: HKDF-SHA256(ikm=client_api_token, salt=request_nonce, info="wptsall-transport-v1")
 *
 * Rust clients share the same wire format via `libs/client-runtime-core`
 * module `wp_transport` (encrypt/decrypt + derive_transport_key). This PHP
 * class remains the WordPress-side source of truth; do not remove it when
 * refactoring the Rust client.
 */
class Transport_Crypto {

	/**
	 * HKDF salt for request/response signing.
	 */
	const SIGNING_HKDF_SALT = 'request-signing';

	/**
	 * HKDF info for request/response signing.
	 */
	const SIGNING_HKDF_INFO = 'wptsall-signing-v1';

	/**
	 * HKDF info string for transport encryption.
	 */
	const HKDF_INFO = 'wptsall-transport-v1';

	/**
	 * Algorithm identifier for the encryption header.
	 */
	const ALGORITHM_ID = 'aes-256-gcm-v1';

	/**
	 * Derive encryption key from client token and nonce using HKDF.
	 *
	 * @param string $token_secret Client API token (HMAC-signed).
	 * @param string $nonce        Base64url-encoded nonce.
	 * @return string|false 32-byte encryption key, or false on failure.
	 */
	public static function derive_key( $token_secret, $nonce ) {
		$salt = base64_decode( strtr( $nonce, '-_', '+/' ) );
		if ( false === $salt ) {
			return false;
		}
		// HKDF-SHA256: PHP 7.1.2+ via hash_hkdf
		$key = hash_hkdf( 'sha256', $token_secret, 32, self::HKDF_INFO, $salt );
		return $key;
	}

	/**
	 * Decrypt an incoming request body.
	 *
	 * @param string $encrypted_payload Base64-encoded ciphertext (includes GCM tag appended).
	 * @param string $nonce             Base64url-encoded nonce from X-WPTSALL-Nonce header.
	 * @param string $token_secret      Client API token.
	 * @return string|false Decrypted JSON string, or false on failure.
	 */
	public static function decrypt_request( $encrypted_payload, $nonce, $token_secret ) {
		$key = self::derive_key( $token_secret, $nonce );
		if ( false === $key ) {
			return false;
		}

		$raw = base64_decode( $encrypted_payload );
		if ( false === $raw || strlen( $raw ) < 28 ) {
			// Minimum: 12 bytes IV + 16 bytes tag
			return false;
		}

		// Extract IV (12 bytes), tag (last 16 bytes), ciphertext (middle)
		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, -16 );
		$ciphertext = substr( $raw, 12, -16 );

		$plaintext = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return $plaintext;
	}

	/**
	 * Encrypt a response body.
	 *
	 * @param mixed  $data         Response data (will be JSON-encoded).
	 * @param string $nonce        Base64url-encoded nonce (same as request nonce for correlation).
	 * @param string $token_secret Client API token.
	 * @return array|false Array with 'encrypted_payload' and 'nonce', or false on failure.
	 */
	public static function encrypt_response( $data, $nonce, $token_secret ) {
		$key = self::derive_key( $token_secret, $nonce );
		if ( false === $key ) {
			return false;
		}

		$plaintext = wp_json_encode( $data );
		if ( false === $plaintext ) {
			return false;
		}

		// Generate random 12-byte IV
		$iv  = openssl_random_pseudo_bytes( 12 );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		if ( false === $ciphertext ) {
			return false;
		}

		// Pack: IV + ciphertext + tag
		$packed = $iv . $ciphertext . $tag;

		return array(
			'encrypted_payload' => base64_encode( $packed ),
			'nonce'             => $nonce,
		);
	}

	/**
	 * Derive HMAC signing key from client token.
	 *
	 * Must stay aligned with the Rust client HKDF contract.
	 *
	 * @param string $token_secret Client API token.
	 * @return string|false Raw 32-byte key or false on failure.
	 */
	public static function derive_signing_key( $token_secret ) {
		if ( ! is_string( $token_secret ) || '' === $token_secret ) {
			return false;
		}

		return hash_hkdf(
			'sha256',
			$token_secret,
			32,
			self::SIGNING_HKDF_INFO,
			self::SIGNING_HKDF_SALT
		);
	}

	/**
	 * Compute Protocol v2 plaintext response signature.
	 *
	 * Format: base64url-no-pad( HMAC-SHA256( signing_key, plaintext_json_bytes ) )
	 *
	 * @param string $plaintext_json Plaintext response JSON bytes.
	 * @param string $token_secret   Client API token.
	 * @return string|false
	 */
	public static function compute_plaintext_response_signature( $plaintext_json, $token_secret ) {
		$signing_key = self::derive_signing_key( $token_secret );
		if ( false === $signing_key || ! is_string( $plaintext_json ) ) {
			return false;
		}

		$mac = hash_hmac( 'sha256', $plaintext_json, $signing_key, true );
		if ( false === $mac ) {
			return false;
		}

		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' );
	}

	/**
	 * Check if a request has encryption headers.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function is_encrypted_request( $request ) {
		$header = $request->get_header( 'X-WPTSALL-Encrypted' );
		return self::ALGORITHM_ID === $header;
	}

	/**
	 * Get nonce from request header.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return string|null
	 */
	public static function get_request_nonce( $request ) {
		return $request->get_header( 'X-WPTSALL-Nonce' );
	}
}
