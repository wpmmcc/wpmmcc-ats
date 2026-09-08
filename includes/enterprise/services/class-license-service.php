<?php
/**
 * Client API token service (class name License_Service — internal historical).
 *
 * Device-scoped tokens only (ISS S2): hash-at-rest, per-device revoke, expiry.
 * Install-level shared bearer is not used for auth (pre-release single truth).
 *
 * @package WPTSALL\Enterprise\Services
 * @since 1.0.0
 * @updated 2.0.1 Device-scoped tokens.
 */

namespace WPTSALL\Enterprise\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client API token service.
 */
class License_Service {

	const OPTION_CLIENT_TOKEN        = 'wptsall_client_api_token';
	const OPTION_CLIENT_TOKEN_STATE  = 'wptsall_client_api_token_state';
	const OPTION_CLIENT_TOKEN_SECRET = 'wptsall_client_api_token_secret';
	const OPTION_CLIENT_DEVICES      = 'wptsall_client_devices';
	const DEFAULT_DEVICE_TOKEN_TTL   = 3600;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @return bool Always true.
	 */
	public function is_pro_enabled() {
		return true;
	}

	/**
	 * Clear any leftover install-level shared token (no longer used for auth).
	 *
	 * @param bool $regenerate Ignored; kept for call-site signature stability.
	 * @return string Always empty.
	 */
	public function get_client_token( $regenerate = false ) {
		unset( $regenerate );
		delete_option( self::OPTION_CLIENT_TOKEN );
		return '';
	}

	/**
	 * Verify a device-scoped client API token.
	 *
	 * @param string $token      Token to verify.
	 * @param string $device_id  Device id from X-WPTSALL-Device-Id (required).
	 * @param bool   $device_only Ignored; device tokens are always required.
	 * @return bool
	 */
	public function verify_client_token( $token, $device_id = '', $device_only = true ) {
		unset( $device_only );
		$token     = (string) $token;
		$device_id = sanitize_key( (string) $device_id );
		if ( '' === $token || '' === $device_id ) {
			return false;
		}

		$devices = $this->get_devices();
		$hash    = $this->hash_token( $token );
		foreach ( $devices as $device ) {
			if ( ! empty( $device['revoked_at'] ) ) {
				continue;
			}
			if ( isset( $device['expires_at'] ) && '' !== (string) $device['expires_at'] ) {
				if ( ! is_numeric( $device['expires_at'] ) || (int) $device['expires_at'] <= time() ) {
					continue;
				}
			}
			if ( (string) ( $device['device_id'] ?? '' ) !== $device_id ) {
				continue;
			}
			if ( ! empty( $device['token_hash'] ) && hash_equals( (string) $device['token_hash'], $hash ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Issue a new device-scoped token. Returns plaintext once.
	 *
	 * @param string $device_id Stable device id (client-generated UUID).
	 * @param string $label    Human label.
	 * @param int|null $ttl_seconds Token lifetime in seconds. Null uses the
	 *                              configured short-lived default.
	 * @return array{device_id:string,token:string,created_at:string,expires_at:int,expires_in:int}
	 */
	public function issue_device_token( $device_id, $label = '', $ttl_seconds = null ) {
		$device_id = sanitize_key( (string) $device_id );
		if ( '' === $device_id ) {
			$device_id = wp_generate_uuid4();
		}
		$token   = bin2hex( random_bytes( 32 ) );
		$ttl     = $this->resolve_device_token_ttl( $ttl_seconds );
		$expires = time() + $ttl;
		$devices = $this->get_devices();
		$now     = current_time( 'mysql' );
		$found   = false;
		foreach ( $devices as &$device ) {
			if ( (string) ( $device['device_id'] ?? '' ) === $device_id ) {
				$device['token_hash'] = $this->hash_token( $token );
				$device['label']      = sanitize_text_field( $label );
				$device['created_at'] = $now;
				$device['expires_at'] = $expires;
				$device['revoked_at'] = null;
				$found               = true;
				break;
			}
		}
		unset( $device );
		if ( ! $found ) {
			$devices[] = array(
				'device_id'   => $device_id,
				'token_hash' => $this->hash_token( $token ),
				'label'      => sanitize_text_field( $label ),
				'created_at' => $now,
				'expires_at' => $expires,
				'revoked_at' => null,
			);
		}
		$this->save_devices( $devices );
		return array(
			'device_id'   => $device_id,
			'token'      => $token,
			'created_at' => $now,
			'expires_at' => $expires,
			'expires_in' => $ttl,
		);
	}

	/**
	 * Revoke a single device token.
	 *
	 * @param string $device_id Device id.
	 * @return bool True if revoked.
	 */
	public function revoke_device_token( $device_id ) {
		$device_id = sanitize_key( (string) $device_id );
		$devices  = $this->get_devices();
		$changed  = false;
		$now      = current_time( 'mysql' );
		foreach ( $devices as &$device ) {
			if ( (string) ( $device['device_id'] ?? '' ) === $device_id && empty( $device['revoked_at'] ) ) {
				$device['revoked_at'] = $now;
				$changed             = true;
			}
		}
		unset( $device );
		if ( $changed ) {
			$this->save_devices( $devices );
		}
		return $changed;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list_devices() {
		$out = array();
		foreach ( $this->get_devices() as $device ) {
			$expired = isset( $device['expires_at'] ) && '' !== (string) $device['expires_at']
				&& ( ! is_numeric( $device['expires_at'] ) || (int) $device['expires_at'] <= time() );
			$out[] = array(
				'device_id'   => (string) ( $device['device_id'] ?? '' ),
				'label'      => (string) ( $device['label'] ?? '' ),
				'created_at' => (string) ( $device['created_at'] ?? '' ),
				'expires_at' => isset( $device['expires_at'] ) ? (int) $device['expires_at'] : null,
				'revoked_at' => $device['revoked_at'] ?? null,
				'active'     => empty( $device['revoked_at'] ) && ! $expired,
			);
		}
		return $out;
	}

	/**
	 * @return array
	 */
	public function get_client_token_snapshot() {
		$active = 0;
		foreach ( $this->get_devices() as $device ) {
			$expired = isset( $device['expires_at'] ) && '' !== (string) $device['expires_at']
				&& ( ! is_numeric( $device['expires_at'] ) || (int) $device['expires_at'] <= time() );
			if ( empty( $device['revoked_at'] ) && ! $expired ) {
				++$active;
			}
		}
		return array(
			'has_token'      => $active > 0,
			'regenerated'    => false,
			'active_devices' => $active,
			'device_scoped'  => true,
		);
	}

	/**
	 * Resolve a short-lived token TTL. A zero TTL is intentionally accepted for
	 * deterministic expiry tests and emergency disablement; negative values use
	 * the configured default instead of creating already-invalid credentials.
	 *
	 * @param int|null $requested Requested lifetime.
	 * @return int
	 */
	private function resolve_device_token_ttl( $requested ) {
		if ( null === $requested || '' === $requested ) {
			$requested = defined( 'WPTSALL_DEVICE_TOKEN_TTL' )
				? WPTSALL_DEVICE_TOKEN_TTL
				: self::DEFAULT_DEVICE_TOKEN_TTL;
			if ( function_exists( 'apply_filters' ) ) {
				$requested = apply_filters( 'wptsall_device_token_ttl', $requested );
			}
		}
		$requested = is_numeric( $requested ) ? (int) $requested : self::DEFAULT_DEVICE_TOKEN_TTL;
		return max( 0, $requested );
	}

	/**
	 * @param string $token Plain token.
	 * @return string
	 */
	private function hash_token( $token ) {
		$pepper = (string) get_option( self::OPTION_CLIENT_TOKEN_SECRET, '' );
		if ( '' === $pepper ) {
			$pepper = bin2hex( random_bytes( 32 ) );
			update_option( self::OPTION_CLIENT_TOKEN_SECRET, $pepper, false );
			if ( function_exists( 'wptsall_set_option_autoload' ) ) {
				wptsall_set_option_autoload( self::OPTION_CLIENT_TOKEN_SECRET, false );
			}
		}
		return hash_hmac( 'sha256', (string) $token, $pepper );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_devices() {
		$raw = get_option( self::OPTION_CLIENT_DEVICES, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array $devices Devices.
	 * @return void
	 */
	private function save_devices( array $devices ) {
		update_option( self::OPTION_CLIENT_DEVICES, $devices, false );
		if ( function_exists( 'wptsall_set_option_autoload' ) ) {
			wptsall_set_option_autoload( self::OPTION_CLIENT_DEVICES, false );
		}
	}
}
