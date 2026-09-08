<?php
/**
 * Component trust / revoke helpers (ISS S4 / W6).
 *
 * Stores a revoke list of component digests. Tampered / revoked packages
 * must be rejected by loaders that call assert_component_trusted().
 *
 * @package WPTSALL\Core
 * @since 2.0.1
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Component_Trust class.
 */
class Component_Trust {

	const OPTION_REVOKE = 'wptsall_component_revoke_list';

	/**
	 * @param string $digest Hex digest.
	 * @param string $reason Reason.
	 * @return void
	 */
	public static function revoke( $digest, $reason = '' ) {
		$digest = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $digest ) );
		if ( '' === $digest ) {
			return;
		}
		$list = self::list_revoked();
		$list[ $digest ] = array(
			'reason'     => sanitize_text_field( $reason ),
			'revoked_at' => current_time( 'mysql' ),
		);
		update_option( self::OPTION_REVOKE, $list, false );
		if ( function_exists( 'wptsall_set_option_autoload' ) ) {
			wptsall_set_option_autoload( self::OPTION_REVOKE, false );
		}
	}

	/**
	 * @return array<string,array>
	 */
	public static function list_revoked() {
		$raw = get_option( self::OPTION_REVOKE, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param string $digest Digest.
	 * @return bool
	 */
	public static function is_revoked( $digest ) {
		$digest = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $digest ) );
		$list   = self::list_revoked();
		return isset( $list[ $digest ] );
	}

	/**
	 * @param array $manifest Component manifest (digest, expires_at, capabilities).
	 * @return true|\WP_Error
	 */
	public static function assert_component_trusted( array $manifest ) {
		$digest = strtolower( (string) ( $manifest['digest'] ?? '' ) );
		if ( '' === $digest ) {
			return new \WP_Error( 'component_missing_digest', 'Component digest required' );
		}
		if ( self::is_revoked( $digest ) ) {
			return new \WP_Error( 'component_revoked', 'Component digest revoked' );
		}
		if ( ! empty( $manifest['expires_at'] ) && strtotime( (string) $manifest['expires_at'] ) < time() ) {
			return new \WP_Error( 'component_expired', 'Component manifest expired' );
		}
		$allowed_caps = (array) apply_filters(
			'wptsall_component_capability_allowlist',
			array( 'translate', 'segment', 'format', 'tm_lookup' )
		);
		foreach ( (array) ( $manifest['capabilities'] ?? array() ) as $cap ) {
			if ( ! in_array( (string) $cap, $allowed_caps, true ) ) {
				return new \WP_Error( 'component_cap_denied', 'Component capability not allowlisted', array( 'cap' => $cap ) );
			}
		}
		return true;
	}
}
