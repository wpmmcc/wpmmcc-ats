<?php
/**
 * Transport Security Middleware
 *
 * Hooks into rest_pre_dispatch for /wptsall/v2/client/* routes.
 * When running over HTTP (not HTTPS), auto-decrypts request body
 * and encrypts response using Transport_Crypto.
 * Over HTTPS, default is TLS-only passthrough (no app-layer body crypto).
 *
 * WordPress.org sites are typically HTTPS-only; app-layer encryption remains
 * **optional** and off by default on HTTPS. Plain HTTP sites (local/dev)
 * still auto-encrypt. Sites that need defense-in-depth may opt in via filter
 * `wptsall_client_transport_encryption_policy` = `always` (not required for
 * directory review).
 *
 * Filter `wptsall_client_transport_encryption_policy` values:
 * always | https_optional | off  (see needs_encryption()).
 *
 * @package WPTSALL\Core
 * @since 1.0.5
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transport_Middleware class.
 */
class Transport_Middleware {

	/**
	 * Initialize the middleware by hooking into REST dispatch.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_decrypt_request' ), 5, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'maybe_encrypt_response' ), 999, 3 );
	}

	/**
	 * Check if the request is for a client route.
	 *
	 * Accounts for the per-installation route secret prefix:
	 * /wptsall/v2/{secret}/client/*
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	private static function is_client_route( $request ) {
		$route = $request->get_route();

		$secrets = function_exists( 'wptsall_get_active_client_route_secrets' )
			? wptsall_get_active_client_route_secrets()
			: array( function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '' );
		foreach ( $secrets as $secret ) {
			$secret_prefix = '' !== (string) $secret ? (string) $secret . '/' : '';
			$expected      = '/wptsall/v2/' . $secret_prefix . 'client';
			if ( 0 === strpos( $route, $expected ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pairing claim is an unauthenticated bootstrap route. It intentionally
	 * bypasses Protocol v2 transport crypto because no device token exists yet.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	private static function is_pairing_claim_route( $request ) {
		return 0 === strpos( $request->get_route(), '/wptsall/v2/' ) && false !== strpos( $request->get_route(), '/client/pairing/claim' );
	}

	/**
	 * Check if transport encryption is required by policy (HTTP, not HTTPS).
	 *
	 * Filter `wptsall_client_transport_encryption_required` allows integration
	 * tests to exercise client REST routes in-process over plain HTTP without
	 * changing production HTTPS defaults.
	 *
	 * @return bool
	 */
	private static function needs_encryption() {
		$policy   = (string) apply_filters( 'wptsall_client_transport_encryption_policy', 'https_optional' );
		$required = ! is_ssl();
		if ( 'always' === $policy ) {
			$required = true;
		} elseif ( 'off' === $policy ) {
			$required = false;
		}

		/**
		 * Filter whether client transport encryption is required for this request.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $required Default depends on policy + SSL.
		 */
		return (bool) apply_filters( 'wptsall_client_transport_encryption_required', $required );
	}

	/**
	 * Resolve the cryptographic secret for this request.
	 *
	 * Protocol v2 requires the presented device token header; no install-token fallback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|false
	 */
	private static function get_request_token_secret( $request ) {
		$presented = trim( (string) $request->get_header( 'X-WPTSALL-Client-Token' ) );
		return '' !== $presented ? $presented : false;
	}

	/**
	 * Decrypt incoming request body if encrypted transport is indicated.
	 *
	 * Hooked into rest_pre_dispatch at priority 5 (before route dispatch).
	 *
	 * @param mixed            $result  Response to replace the requested version with. Can be anything
	 *                                  a normal endpoint can return, or null to not hijack the request.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public static function maybe_decrypt_request( $result, $server, $request ) {
		// Only process client routes.
		if ( ! self::is_client_route( $request ) || self::is_pairing_claim_route( $request ) ) {
			return $result;
		}

		// Check X-WPTSALL-Transport header for 'encrypted' flag.
		$transport_header = $request->get_header( 'X-WPTSALL-Transport' );
		if ( 'encrypted' !== $transport_header ) {
			// Enforce encryption on HTTP; keep HTTPS backward-compatible.
			if ( self::needs_encryption() ) {
				return new \WP_Error(
					'transport_encryption_required',
					'Transport encryption required for HTTP connections.',
					array( 'status' => 400 )
				);
			}

			$signature_error = self::verify_protocol_v2_request( $request, $request->get_body() );
			return is_wp_error( $signature_error ) ? $signature_error : $result;
		}

		// Check if Transport_Crypto is available.
		if ( ! class_exists( '\\WPTSALL\\Core\\Transport_Crypto' ) ) {
			return $result;
		}

		// Only decrypt POST/PUT/PATCH requests with body.
		$method = $request->get_method();
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$signature_error = self::verify_protocol_v2_request( $request, '' );
			return is_wp_error( $signature_error ) ? $signature_error : $result;
		}

		$nonce = Transport_Crypto::get_request_nonce( $request );
		if ( empty( $nonce ) ) {
			$protocol = trim( (string) $request->get_header( 'X-WPTSALL-Protocol-Version' ) );
			return '2' === $protocol
				? new \WP_Error( 'transport_nonce_required', 'Encrypted Protocol v2 request nonce is required.', array( 'status' => 400 ) )
				: $result;
		}

		$token_secret = self::get_request_token_secret( $request );
		if ( false === $token_secret ) {
			return $result;
		}

		// Get the raw body — Client sends a JSON envelope with encrypted_payload field.
		$raw_body = $request->get_body();
		if ( empty( $raw_body ) ) {
			$protocol = trim( (string) $request->get_header( 'X-WPTSALL-Protocol-Version' ) );
			return '2' === $protocol
				? new \WP_Error( 'transport_payload_required', 'Encrypted Protocol v2 request payload is required.', array( 'status' => 400 ) )
				: $result;
		}

		// Extract encrypted_payload from JSON envelope sent by Client.
		// Client sends: {"encrypted_payload": "<base64>", "nonce": "...", "algorithm": "..."}
		$envelope = json_decode( $raw_body, true );
		if ( is_array( $envelope ) && ! empty( $envelope['encrypted_payload'] ) ) {
			$encrypted_payload = $envelope['encrypted_payload'];
		} else {
			// Fallback: treat raw body as base64 directly (legacy compatibility).
			$encrypted_payload = $raw_body;
		}

		$decrypted = Transport_Crypto::decrypt_request( $encrypted_payload, $nonce, $token_secret );
		if ( false === $decrypted ) {
			wptsall_log_warning(
				'transport',
				'Failed to decrypt client request',
				array( 'route' => $request->get_route() )
			);
			return new \WP_Error(
				'transport_decrypt_failed',
				'Failed to decrypt encrypted transport payload.',
				array( 'status' => 400 )
			);
		}

		// Replace the request body with decrypted JSON.
		$request->set_body( $decrypted );

		// Re-parse JSON params so the route handler sees the decrypted data.
		$decoded = json_decode( $decrypted, true );
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}

		// Mark request as decrypted (for response encryption).
		$request->set_header( 'X-WPTSALL-Decrypted', '1' );

		$signature_error = self::verify_protocol_v2_request( $request, $decrypted );
		return is_wp_error( $signature_error ) ? $signature_error : $result;
	}

	/**
	 * Verify Protocol v2 Client→WP request signatures.
	 *
	 * The Rust client signs the plaintext body using HKDF-SHA256(token,
	 * "request-signing", "wptsall-signing-v1") and a canonical method/path/
	 * timestamp/nonce/body-hash/headers-hash string. Requests that do not
	 * declare Protocol-Version 2 remain backward-compatible; v2 requests must
	 * provide a valid signature and a fresh nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string          $body    Plaintext body bytes.
	 * @return true|\WP_Error
	 */
	private static function verify_protocol_v2_request( $request, $body ) {
		// Protocol v2 signatures are derived from the token presented by this
		// request.  Permission callbacks subsequently verify that token is valid
		// and bound to the declared device id (including device-scoped tokens).
		$token = (string) $request->get_header( 'X-WPTSALL-Client-Token' );
		return self::validate_protocol_v2_request( $request, $body, $token );
	}

	/**
	 * Validate a Protocol v2 request signature.
	 *
	 * Kept as a separate method so integrations can exercise the exact
	 * authentication primitive without running a full REST dispatch.  The
	 * token passed here is the token presented by the request; the permission
	 * callback still performs the device-scoped token authorization separately.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $body    Plaintext body bytes.
	 * @param string|false     $token_secret Request token/secret.
	 * @return true|\WP_Error
	 */
	private static function validate_protocol_v2_request( $request, $body, $token_secret = false ) {
		$protocol = trim( (string) $request->get_header( 'X-WPTSALL-Protocol-Version' ) );
		if ( '2' !== $protocol ) {
			return true;
		}
		// Test-only opt-out (see wptsall_is_test_only_transport_signature_fallback_enabled):
		// in-process integration harnesses explicitly declare that they do not
		// replay the Rust client's signing path. Production never defines the
		// constant, so every real Protocol v2 request stays signature-verified.
		if ( function_exists( 'wptsall_is_test_only_transport_signature_fallback_enabled' )
			&& wptsall_is_test_only_transport_signature_fallback_enabled() ) {
			return true;
		}
		$token     = (string) $request->get_header( 'X-WPTSALL-Client-Token' );
		$timestamp = trim( (string) $request->get_header( 'X-WPTSALL-Timestamp' ) );
		$nonce     = trim( (string) $request->get_header( 'X-WPTSALL-Signature-Nonce' ) );
		$signature = trim( (string) $request->get_header( 'X-WPTSALL-Signature' ) );
		// Check timestamp syntax/window first. This prevents an expired request
		// from being classified as a generic missing/invalid signature.
		if ( '' === $timestamp || ! ctype_digit( $timestamp ) ) {
			return new \WP_Error( 'request_timestamp_invalid', 'Protocol v2 request timestamp is invalid.', array( 'status' => 401 ) );
		}
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new \WP_Error( 'request_timestamp_expired', 'Protocol v2 request timestamp has expired.', array( 'status' => 401 ) );
		}
		if ( '' === $token || '' === $nonce || '' === $signature ) {
			return new \WP_Error( 'request_signature_required', 'Protocol v2 request signature is required.', array( 'status' => 401 ) );
		}
		if ( false === $token_secret || '' === (string) $token_secret ) {
			$token_secret = $token;
		}
		if ( self::is_signature_nonce_replay( $token_secret, $nonce ) ) {
			return new \WP_Error( 'request_signature_replay', 'Protocol v2 request signature nonce was already used.', array( 'status' => 401 ) );
		}
		$path = (string) $request->get_route();
		$query = $request->get_query_params();
		if ( is_array( $query ) && ! empty( $query ) ) {
			$pairs = array();
			foreach ( $query as $key => $value ) {
				$values = is_array( $value ) ? $value : array( $value );
				foreach ( $values as $item ) {
					$pairs[] = array( (string) $key, (string) $item );
				}
			}
			if ( ! empty( $pairs ) ) {
				// Match Rust's stable key-then-value ordering, including repeated
				// query keys, before RFC3986 encoding.
				usort(
					$pairs,
					static function ( $left, $right ) {
						$key_cmp = strcmp( $left[0], $right[0] );
						return 0 !== $key_cmp ? $key_cmp : strcmp( $left[1], $right[1] );
					}
				);
				$encoded_pairs = array();
				foreach ( $pairs as $pair ) {
					$encoded_pairs[] = rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
				}
				$path .= '?' . implode( '&', $encoded_pairs );
			}
		}
		$allowed_headers = array( 'idempotency-key', 'x-route-secret', 'x-wptsall-task-id', 'x-wptsall-relation-id', 'x-wptsall-source-id', 'x-wptsall-filename', 'x-wptsall-upload-id', 'x-wptsall-chunk-index' );
		$signed = array();
		foreach ( $allowed_headers as $name ) {
			$value = trim( (string) $request->get_header( $name ) );
			if ( '' !== $value ) {
				$signed[ $name ] = $value;
			}
		}
		ksort( $signed, SORT_STRING );
		$header_lines = '';
		foreach ( $signed as $name => $value ) {
			$header_lines .= $name . ':' . $value . "\n";
		}
		$canonical = strtoupper( $request->get_method() ) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', (string) $body ) . "\n" . hash( 'sha256', $header_lines );
		$signing_key = self::derive_signing_key( (string) $token_secret );
		$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $canonical, $signing_key, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error( 'request_signature_invalid', 'Protocol v2 request signature is invalid.', array( 'status' => 401 ) );
		}
		// add_option is an atomic insert on the WP options table. If another
		// request won the race after the initial check, reject this request too.
		if ( ! self::remember_signature_nonce( $token_secret, $nonce ) ) {
			return new \WP_Error( 'request_signature_replay', 'Protocol v2 request signature nonce was already used.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Build the persistent replay marker key for a token/nonce pair.
	 *
	 * @param string $token_secret Token secret.
	 * @param string $nonce Nonce.
	 * @return string
	 */
	private static function get_signature_nonce_option_key( $token_secret, $nonce ) {
		return 'wptsall_sig_nonce_' . hash( 'sha256', (string) $token_secret . '|' . (string) $nonce );
	}

	/**
	 * Determine whether a signature nonce has already been consumed.
	 * Expired markers are removed lazily.
	 *
	 * @param string $token_secret Token secret.
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	private static function is_signature_nonce_replay( $token_secret, $nonce ) {
		$key       = self::get_signature_nonce_option_key( $token_secret, $nonce );
		$expires_at = (int) get_option( $key, 0 );
		if ( $expires_at <= 0 ) {
			return false;
		}
		if ( $expires_at <= time() ) {
			delete_option( $key );
			return false;
		}
		return true;
	}

	/**
	 * Remember a nonce for the replay window.
	 *
	 * @return bool True when this call claimed the nonce; false if already held.
	 */
	private static function remember_signature_nonce( $token_secret, $nonce ) {
		$key = self::get_signature_nonce_option_key( $token_secret, $nonce );
		if ( self::is_signature_nonce_replay( $token_secret, $nonce ) ) {
			return false;
		}
		return (bool) add_option( $key, time() + ( 5 * MINUTE_IN_SECONDS ), '', 'no' );
	}

	/** @param string $token Client token. @return string */
	private static function derive_signing_key( $token ) {
		return hash_hkdf( 'sha256', (string) $token, 32, 'wptsall-signing-v1', 'request-signing' );
	}

	/**
	 * Encrypt outgoing response if the request was decrypted.
	 *
	 * Hooked into rest_post_dispatch at priority 999 (after all other processing).
	 *
	 * @param \WP_REST_Response $response Response object.
	 * @param \WP_REST_Server   $server   Server instance.
	 * @param \WP_REST_Request  $request  Request object.
	 * @return \WP_REST_Response
	 */
	public static function maybe_encrypt_response( $response, $server, $request ) {
		// Only process client routes.
		if ( ! self::is_client_route( $request ) || self::is_pairing_claim_route( $request ) ) {
			return $response;
		}

		$token_secret = self::get_request_token_secret( $request );
		if ( false !== $token_secret && is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$plaintext = wp_json_encode( $response->get_data() );
			if ( is_string( $plaintext ) && '' !== $plaintext ) {
				$signature = Transport_Crypto::compute_plaintext_response_signature( $plaintext, $token_secret );
				if ( is_string( $signature ) && '' !== $signature && method_exists( $response, 'header' ) ) {
					$response->header( 'X-WPTSALL-Response-Signature', $signature );
					$response->header( 'X-WPTSALL-Transport', 'plaintext' );
				}
			}
		}

		// If HTTPS, no encryption needed.
		if ( ! self::needs_encryption() ) {
			return $response;
		}

		// Check if we decrypted the request (or if X-WPTSALL-Transport was set on a GET).
		$was_decrypted    = '1' === $request->get_header( 'X-WPTSALL-Decrypted' );
		$transport_header = $request->get_header( 'X-WPTSALL-Transport' );

		if ( ! $was_decrypted && 'encrypted' !== $transport_header ) {
			return $response;
		}

		if ( ! class_exists( '\\WPTSALL\\Core\\Transport_Crypto' ) ) {
			return $response;
		}

		$nonce = Transport_Crypto::get_request_nonce( $request );
		if ( empty( $nonce ) ) {
			return $response;
		}

		if ( false === $token_secret ) {
			return $response;
		}

		$data = $response->get_data();
		$encrypted = Transport_Crypto::encrypt_response( $data, $nonce, $token_secret );

		if ( false === $encrypted ) {
			wptsall_log_warning(
				'transport',
				'Failed to encrypt client response',
				array( 'route' => $request->get_route() )
			);
			return $response;
		}

		// Replace response with encrypted payload.
		$encrypted_response = new \WP_REST_Response(
			array(
				'encrypted_payload' => $encrypted['encrypted_payload'],
				'nonce'             => $encrypted['nonce'],
				'algorithm'         => Transport_Crypto::ALGORITHM_ID,
			),
			$response->get_status()
		);

		// Copy original headers.
		foreach ( $response->get_headers() as $key => $value ) {
			$encrypted_response->header( $key, $value );
		}

		$encrypted_response->header( 'X-WPTSALL-Transport', 'encrypted' );
		$encrypted_response->header( 'X-WPTSALL-Nonce', $nonce );
		$encrypted_response->header( 'X-WPTSALL-Encrypted', Transport_Crypto::ALGORITHM_ID );

		return $encrypted_response;
	}
}
