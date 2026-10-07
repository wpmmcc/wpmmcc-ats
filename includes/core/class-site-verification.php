<?php
/**
 * Site Verification for Challenge-Response domain binding.
 *
 * Generates one-time verification nonces and signed responses so the WPTSALL
 * server can prove site ownership before binding a domain.
 *
 * @package WPTSALL\Core
 * @since 1.1.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site_Verification handles nonce generation, HMAC signing, and sealed-box encryption
 * for the domain verification challenge-response protocol.
 */
class Site_Verification {

	/**
	 * WPTSALL server verification public key (X25519, 64-char hex).
	 *
	 * 批 O6 复栈 protocol fix: the server's verification crypto is an X25519
	 * sealed box — GET /api/v1/domains/verification-public-key on the server
	 * returns the live `public_key_hex` (algorithm X25519-SEALED-BOX). The old
	 * default here was an RSA-2048 PEM whose private half exists nowhere in
	 * any repo or deployment, and the server decrypts with an X25519 key —
	 * RSA-OAEP payloads could never be decrypted by ANY build of the server,
	 * so bind/reverify against the real plugin never worked. The key MUST be
	 * provisioned per deployment (the server generates and persists its
	 * keypair; copy the exported hex into wp-config.php):
	 *
	 *   define( 'WPTSALL_SERVER_PUBLIC_KEY', '<64-char hex from the server>' );
	 *
	 * @return string Hex-encoded X25519 public key, '' when not configured.
	 */
	public static function get_server_public_key() {
		return defined( 'WPTSALL_SERVER_PUBLIC_KEY' ) ? (string) WPTSALL_SERVER_PUBLIC_KEY : '';
	}

	/**
	 * Verification nonce TTL in seconds.
	 *
	 * @var int
	 */
	const NONCE_TTL = 600; // 10 minutes

	/**
	 * WordPress option key for site secret.
	 *
	 * @var string
	 */
	const SITE_SECRET_OPTION = 'wptsall_site_secret';

	/**
	 * Transient prefix for verification nonces.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wptsall_site_verify_';

	/**
	 * Get or generate the site secret (32 bytes hex).
	 * Generated once per WP installation, stored in wp_options.
	 *
	 * @return string 64-character hex string.
	 */
	public static function get_site_secret() {
		$secret = get_option( self::SITE_SECRET_OPTION );
		if ( empty( $secret ) || strlen( $secret ) !== 64 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::SITE_SECRET_OPTION, $secret, false ); // autoload=false
		}
		return $secret;
	}

	/**
	 * Generate a one-time verification nonce.
	 *
	 * @return array {
	 *     @type string $nonce            UUID4 nonce.
	 *     @type string $verification_url Full REST URL for server to fetch.
	 *     @type int    $expires_at       Unix timestamp.
	 *     @type int    $expires_in       TTL in seconds.
	 * }
	 */
	public static function generate_verification_nonce() {
		// Validate that current site uses a real domain name (not IP/localhost).
		$domain = wptsall_get_current_site_domain();
		if ( ! wptsall_validate_domain_format( $domain ) ) {
			return new \WP_Error(
				'invalid_domain',
				sprintf(
					'Site domain "%s" is not a valid domain name. IP addresses and localhost are not supported for site verification.',
					$domain
				),
				array( 'status' => 400 )
			);
		}

		$nonce      = wp_generate_uuid4();
		$expires_at = time() + self::NONCE_TTL;

		$data = array(
			'nonce'      => $nonce,
			'site_url'   => home_url(),
			'expires_at' => $expires_at,
			'created_at' => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . $nonce, $data, self::NONCE_TTL );

		$verification_url = rest_url( 'wptsall/v2/site/verify' ) . '?nonce=' . $nonce;

		return array(
			'nonce'            => $nonce,
			'verification_url' => $verification_url,
			'expires_at'       => $expires_at,
			'expires_in'       => self::NONCE_TTL,
		);
	}

	/**
	 * Consume a verification nonce and return signed response data.
	 *
	 * @param string $nonce UUID4 nonce from the verification URL.
	 * @return array|\WP_Error Signed response on success, WP_Error on failure.
	 */
	public static function consume_nonce_and_sign( $nonce ) {
		$data = get_transient( self::TRANSIENT_PREFIX . $nonce );
		if ( empty( $data ) ) {
			return new \WP_Error( 'verification_expired', 'Verification nonce expired or not found', array( 'status' => 404 ) );
		}

		// Check expiry.
		if ( time() > $data['expires_at'] ) {
			delete_transient( self::TRANSIENT_PREFIX . $nonce );
			return new \WP_Error( 'verification_expired', 'Verification nonce expired', array( 'status' => 410 ) );
		}

		// Delete transient (one-time use).
		delete_transient( self::TRANSIENT_PREFIX . $nonce );

		$site_url       = $data['site_url'];
		$expires_at     = $data['expires_at'];
		$plugin_version = defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '1.0.0';

		// Get site secret.
		$site_secret = self::get_site_secret();

		// WP-ID-0 (contract/identity-v1): identity rides in the payload but is
		// NOT part of the signed canonical message, so existing server-side
		// signature verification is unaffected (additive contract field).
		$plugin_identity = defined( 'WPMMCC_ATS_IDENTITY' ) ? WPMMCC_ATS_IDENTITY : '';

		// Build canonical message.
		$canonical = self::build_canonical_message( $site_url, $nonce, $expires_at, $plugin_version );

		// Sign with HMAC-SHA256.
		$signature = hash_hmac( 'sha256', $canonical, hex2bin( $site_secret ) );

		// Encrypt site secret with server public key (RSA-OAEP).
		$encrypted_secret = self::encrypt_site_secret( $site_secret );
		if ( is_wp_error( $encrypted_secret ) ) {
			return $encrypted_secret;
		}

		return array(
			'site_url'         => $site_url,
			'nonce'            => $nonce,
			'expires_at'       => $expires_at,
			'plugin_identity'  => $plugin_identity,
			'plugin_version'   => $plugin_version,
			'signature'        => $signature,
			'encrypted_secret' => $encrypted_secret,
			'route_secret'     => function_exists( 'wptsall_get_client_route_secret' )
				? wptsall_get_client_route_secret()
				: '',
		);
	}

	/**
	 * Build canonical message for HMAC signing.
	 *
	 * Fields sorted alphabetically, URL-encoded values, joined with &.
	 *
	 * @param string $site_url       Site URL.
	 * @param string $nonce          Verification nonce.
	 * @param int    $expires_at     Expiry timestamp.
	 * @param string $plugin_version Plugin version string.
	 * @return string Canonical message string.
	 */
	public static function build_canonical_message( $site_url, $nonce, $expires_at, $plugin_version ) {
		$fields = array(
			'expires_at'     => (string) $expires_at,
			'nonce'          => $nonce,
			'plugin_version' => $plugin_version,
			'site_url'       => $site_url,
		);
		ksort( $fields );

		$parts = array();
		foreach ( $fields as $key => $value ) {
			$parts[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
		}
		return implode( '&', $parts );
	}

	/**
	 * Encrypt site secret to the server's X25519 public key (sealed box).
	 *
	 * Matches the server's site_verification::decrypt_site_secret
	 * (crypto_box sealed box, X25519 + XSalsa20-Poly1305). Requires ext-sodium
	 * (bundled and enabled by default since PHP 7.2) and a provisioned
	 * WPTSALL_SERVER_PUBLIC_KEY hex constant.
	 *
	 * @param string $site_secret_hex 64-character hex site secret.
	 * @return string|\WP_Error Base64-encoded ciphertext or WP_Error.
	 */
	public static function encrypt_site_secret( $site_secret_hex ) {
		$public_key_hex = self::get_server_public_key();
		if ( empty( $public_key_hex ) ) {
			return new \WP_Error(
				'encryption_failed',
				'WPTSALL_SERVER_PUBLIC_KEY is not configured: fetch public_key_hex from the WPTSALL server (GET /api/v1/domains/verification-public-key) and define it in wp-config.php'
			);
		}
		if ( ! function_exists( 'sodium_crypto_box_seal' ) ) {
			return new \WP_Error( 'encryption_failed', 'ext-sodium is required for site verification (sodium_crypto_box_seal unavailable)' );
		}
		$decoded = @hex2bin( trim( $public_key_hex ) );
		if ( false === $decoded || 32 !== strlen( $decoded ) ) {
			return new \WP_Error( 'encryption_failed', 'WPTSALL_SERVER_PUBLIC_KEY must be a 64-character hex X25519 public key' );
		}

		$plaintext = hex2bin( $site_secret_hex );
		try {
			$encrypted = sodium_crypto_box_seal( $plaintext, $decoded );
		} catch ( \SodiumException $e ) {
			return new \WP_Error( 'encryption_failed', 'sealed box encryption failed: ' . $e->getMessage() );
		}

		return base64_encode( $encrypted );
	}
}
