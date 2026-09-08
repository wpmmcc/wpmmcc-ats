<?php
/**
 * Field capability type helpers (WPML four-state aligned).
 *
 * WPML CF preferences: Translate / Copy / Copy once / Don't translate.
 * Internal types: translate | sync | copy_once | skip (+ id_mapping, compute).
 *
 * @package WPTSALL\Models\Services
 * @since 2.2.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field_Capability class.
 */
class Field_Capability {

	/**
	 * Normalize a capability type string.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	public static function normalize_type( $type ): string {
		$type = sanitize_key( (string) $type );
		if ( in_array( $type, array( 'copy', 'sync_direct' ), true ) ) {
			return 'sync';
		}
		if ( in_array( $type, array( 'copy-once', 'copy_once', 'copyonce' ), true ) ) {
			return 'copy_once';
		}
		if ( in_array( $type, array( 'ignore', 'dont_translate', 'no_sync', 'do_nothing' ), true ) ) {
			return 'skip';
		}
		if ( 'mapping' === $type ) {
			return 'id_mapping';
		}
		return $type;
	}

	/**
	 * Whether type is copy-once (first write only).
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function is_copy_once( $type ): bool {
		return 'copy_once' === self::normalize_type( $type );
	}

	/**
	 * Whether target meta is considered empty for copy-once.
	 *
	 * @param mixed $value Existing target value.
	 * @return bool
	 */
	public static function is_empty_meta( $value ): bool {
		if ( null === $value || false === $value ) {
			return true;
		}
		if ( is_string( $value ) && '' === $value ) {
			return true;
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether a sync/copy write should overwrite the target value.
	 *
	 * @param string $type           Capability type.
	 * @param mixed  $existing_value Current target value.
	 * @return bool True = write/overwrite allowed.
	 */
	public static function should_write_to_target( $type, $existing_value ): bool {
		$type = self::normalize_type( $type );
		if ( 'skip' === $type ) {
			return false;
		}
		if ( self::is_copy_once( $type ) ) {
			return self::is_empty_meta( $existing_value );
		}
		// sync / translate / id_mapping / compute: caller decides; default allow.
		return true;
	}

	/**
	 * WPML-style four-state label for admin UI.
	 *
	 * @param string $type Internal type.
	 * @return string
	 */
	public static function four_state_label( $type ): string {
		switch ( self::normalize_type( $type ) ) {
			case 'translate':
				return __( 'Translate', 'wpmmcc-ats' );
			case 'sync':
				return __( 'Copy', 'wpmmcc-ats' );
			case 'copy_once':
				return __( 'Copy once', 'wpmmcc-ats' );
			case 'skip':
				return __( "Don't translate", 'wpmmcc-ats' );
			case 'id_mapping':
				return __( 'ID mapping', 'wpmmcc-ats' );
			case 'compute':
				return __( 'Compute', 'wpmmcc-ats' );
			default:
				return (string) $type;
		}
	}

	/**
	 * Short description for the four-state preference.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function four_state_description( $type ): string {
		switch ( self::normalize_type( $type ) ) {
			case 'translate':
				return __( 'Send for translation; do not overwrite with source on later syncs.', 'wpmmcc-ats' );
			case 'sync':
				return __( 'Always copy from source (WPML Copy).', 'wpmmcc-ats' );
			case 'copy_once':
				return __( 'Copy from source only when the target value is empty (WPML Copy once).', 'wpmmcc-ats' );
			case 'skip':
				return __( 'Do not copy or translate this field.', 'wpmmcc-ats' );
			default:
				return '';
		}
	}

	/**
	 * Resolve type for a meta key from field_capabilities map.
	 *
	 * @param array  $field_capabilities Caps map.
	 * @param string $meta_key           Key.
	 * @param string $default            Default type.
	 * @return string
	 */
	public static function type_for_field( array $field_capabilities, string $meta_key, string $default = 'sync' ): string {
		if ( ! isset( $field_capabilities[ $meta_key ] ) ) {
			return self::normalize_type( $default );
		}
		$cfg = $field_capabilities[ $meta_key ];
		if ( is_string( $cfg ) ) {
			return self::normalize_type( $cfg );
		}
		if ( is_array( $cfg ) ) {
			return self::normalize_type( (string) ( $cfg['type'] ?? $default ) );
		}
		return self::normalize_type( $default );
	}
}
