<?php
/**
 * Capability helpers (ISS S6).
 *
 * @package WPTSALL
 * @since 2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $cap Cap.
 * @return bool
 */
function wptsall_user_can( $cap ) {
	if ( class_exists( '\\WPTSALL\\Core\\Capabilities' ) ) {
		return \WPTSALL\Core\Capabilities::current_user_can( $cap );
	}
	return current_user_can( $cap );
}

/**
 * @return bool
 */
function wptsall_user_can_manage_security() {
	return wptsall_user_can( 'manage_wptsall_security' );
}

/**
 * @return bool
 */
function wptsall_user_can_manage_settings() {
	return wptsall_user_can( 'manage_wptsall_settings' );
}

/**
 * @return bool
 */
function wptsall_user_can_manage_translations() {
	return wptsall_user_can( 'manage_wptsall_translations' );
}
