<?php
/**
 * Capability split (ISS S6 / W3).
 *
 * Registers granular caps; administrators get all. Sensitive actions
 * (rotate secret, device tokens, credentials) require manage_wptsall_security.
 *
 * @package WPTSALL\Core
 * @since 2.0.1
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities class.
 */
class Capabilities {

	const CAPS = array(
		'manage_wptsall',
		'manage_wptsall_translations',
		'manage_wptsall_settings',
		'manage_wptsall_security',
		'manage_wptsall_sync',
	);

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_caps' ), 5 );
	}

	/**
	 * Grant caps to administrator role (idempotent).
	 *
	 * @return void
	 */
	public static function ensure_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::CAPS as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}

		// Translator: translations only — cannot manage security/settings.
		$translator = get_role( 'wptsall_translator' );
		if ( ! $translator ) {
			add_role(
				'wptsall_translator',
				'WPTSALL Translator',
				array(
					'read'                         => true,
					'manage_wptsall'               => true,
					'manage_wptsall_translations'  => true,
				)
			);
		} else {
			$translator->add_cap( 'manage_wptsall' );
			$translator->add_cap( 'manage_wptsall_translations' );
			$translator->remove_cap( 'manage_wptsall_security' );
			$translator->remove_cap( 'manage_wptsall_settings' );
		}
	}

	/**
	 * Whether current user may perform an action (exact cap only).
	 *
	 * Administrators receive granular caps on activation via ensure_caps().
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	public static function current_user_can( $cap ) {
		return current_user_can( $cap );
	}

	/**
	 * @return bool
	 */
	public static function can_manage_security() {
		return self::current_user_can( 'manage_wptsall_security' );
	}

	/**
	 * @return bool
	 */
	public static function can_manage_settings() {
		return self::current_user_can( 'manage_wptsall_settings' );
	}

	/**
	 * @return bool
	 */
	public static function can_manage_translations() {
		return self::current_user_can( 'manage_wptsall_translations' );
	}
}
