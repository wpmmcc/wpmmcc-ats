<?php
/**
 * Client API token service.
 *
 * Device-scoped tokens only (ISS S2): hash-at-rest, per-device revoke, expiry.
 * Install-level shared bearer is not used for auth (pre-release single truth).
 *
 * @package WPTSALL\Client_Pairing\Services
 * @since 1.0.0
 * @updated 2.0.1 Device-scoped tokens.
 * @updated 2.1.2 Renamed from historical License_Service (enterprise module).
 */

namespace WPTSALL\Client_Pairing\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client API token service.
 */
class Client_Token_Service {

	const OPTION_CLIENT_TOKEN        = 'wptsall_client_api_token';
	const OPTION_CLIENT_TOKEN_STATE  = 'wptsall_client_api_token_state';
	const OPTION_CLIENT_TOKEN_SECRET = 'wptsall_client_api_token_secret';
	const OPTION_CLIENT_DEVICES      = 'wptsall_client_devices';
	const DEFAULT_DEVICE_TOKEN_TTL   = 3600;
	/**
	 * Rotation overlap window (批 D ③): after a device token is re-issued,
	 * the immediately previous token stays valid for this many seconds so a
	 * live worker holding it finishes its run instead of hard-401 mid-flight.
	 * Bounded by the replaced token's own expiry (never resurrects an expired
	 * credential). `wptsall_device_token_overlap_seconds` filters it; 0
	 * disables (back to hard cutover). Revocation is NOT softened: a revoked
	 * device row fails both generations immediately.
	 */
	const DEVICE_TOKEN_ROTATION_OVERLAP_SECONDS = 300;

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
			$this->log_verify_failure( $device_id, 'empty_token_or_device' );
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
			// 批 D ③: rotation overlap window — the immediately previous
			// token stays valid for a bounded window after re-issue. The
			// row-level revoked/expiry guards above already ran, so a revoked
			// or fully expired device never reaches this fallback.
			if ( ! empty( $device['previous_token_hash'] )
				&& isset( $device['previous_valid_until'] )
				&& is_numeric( $device['previous_valid_until'] )
				&& (int) $device['previous_valid_until'] > time()
				&& hash_equals( (string) $device['previous_token_hash'], $hash ) ) {
				$this->log_overlap_acceptance( $device_id );
				return true;
			}
		}

		$this->log_verify_failure( $device_id, 'no_active_device_token_match' );
		return false;
	}

	/**
	 * Warn-log a device-token verification failure (G-09).
	 *
	 * Verification used to fail silently, hiding brute-force / replay
	 * attempts against the client API. Only the device id and a stable
	 * reason slug are logged — never token material.
	 *
	 * @param string $device_id Device id from the request header.
	 * @param string $reason   Failure reason slug.
	 * @return void
	 */
	private function log_verify_failure( $device_id, $reason ) {
		if ( ! function_exists( 'wptsall_log_warning' ) ) {
			return;
		}
		wptsall_log_warning(
			'client-pairing',
			'Client device token verification failed',
			array(
				'device_id' => sanitize_key( (string) $device_id ),
				'reason'    => sanitize_key( (string) $reason ),
			)
		);
	}

	/**
	 * Info-log an acceptance through the rotation overlap window (批 D ③).
	 *
	 * A verify via the previous generation is a legitimate transition signal
	 * (worker still holding the pre-rotation token), useful for operators
	 * watching a rotation land. Only the device id is logged — never token
	 * material.
	 *
	 * @param string $device_id Device id from the request header.
	 * @return void
	 */
	private function log_overlap_acceptance( $device_id ) {
		if ( ! function_exists( 'wptsall_log_info' ) ) {
			return;
		}
		wptsall_log_info(
			'client-pairing',
			'Client device token accepted via rotation overlap window',
			array(
				'device_id' => sanitize_key( (string) $device_id ),
			)
		);
	}

	/**
	 * Resolve the rotation overlap window in seconds (批 D ③).
	 *
	 * Filterable via `wptsall_device_token_overlap_seconds`; values are
	 * clamped to >= 0 (0 = hard cutover, the pre-batch-D-③ behavior).
	 *
	 * @return int
	 */
	private function resolve_rotation_overlap_seconds() {
		$seconds = self::DEVICE_TOKEN_ROTATION_OVERLAP_SECONDS;
		if ( function_exists( 'apply_filters' ) ) {
			$seconds = apply_filters( 'wptsall_device_token_overlap_seconds', $seconds );
		}
		return max( 0, is_numeric( $seconds ) ? (int) $seconds : 0 );
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
		$overlap = $this->resolve_rotation_overlap_seconds();
		foreach ( $devices as &$device ) {
			if ( (string) ( $device['device_id'] ?? '' ) === $device_id ) {
				// 批 D ③: keep the replaced token valid for the bounded
				// rotation overlap window — but only if it was live at
				// rotation time. An expired or revoked credential is never
				// resurrected, and a second rotation replaces the chain
				// (newest + immediate predecessor only, no三代链).
				$replaced_expires = (string) ( $device['expires_at'] ?? '' );
				$replaced_was_live = empty( $device['revoked_at'] )
					&& '' !== $replaced_expires
					&& is_numeric( $replaced_expires )
					&& (int) $replaced_expires > time()
					&& ! empty( $device['token_hash'] );
				if ( $overlap > 0 && $replaced_was_live ) {
					$device['previous_token_hash']  = (string) $device['token_hash'];
					$device['previous_valid_until'] = min( (int) $replaced_expires, time() + $overlap );
				} else {
					$device['previous_token_hash']  = null;
					$device['previous_valid_until'] = null;
				}
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
			// 批 D ③: surface the live rotation overlap window (if any) so
			// `wp wptsall security list-devices` shows which devices are
			// mid-transition and until when.
			$overlap_until = null;
			if ( empty( $device['revoked_at'] ) && ! $expired
				&& ! empty( $device['previous_token_hash'] )
				&& isset( $device['previous_valid_until'] )
				&& is_numeric( $device['previous_valid_until'] )
				&& (int) $device['previous_valid_until'] > time() ) {
				$overlap_until = (int) $device['previous_valid_until'];
			}
			$out[] = array(
				'device_id'   => (string) ( $device['device_id'] ?? '' ),
				'label'      => (string) ( $device['label'] ?? '' ),
				'created_at' => (string) ( $device['created_at'] ?? '' ),
				'expires_at' => isset( $device['expires_at'] ) ? (int) $device['expires_at'] : null,
				'revoked_at' => $device['revoked_at'] ?? null,
				'active'     => empty( $device['revoked_at'] ) && ! $expired,
				'rotation_overlap_until' => $overlap_until,
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
