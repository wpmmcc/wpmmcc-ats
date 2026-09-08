<?php
/**
 * Custom Field Translation Service (1.3.0)
 *
 * Polylang/TranslatePress-style translation of custom post-type meta fields.
 * When a post is saved, we read the registered model's meta_fields and
 * register the field's value as a String (context='cpt_field', key=field_<slug>_<id>).
 * The frontend `get_post_metadata` filter swaps the value when in a
 * virtual-site context.
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\CustomFields\Services;

use WPTSALL\Strings\Services\String_Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Custom_Field_Translation_Service {

	const CONTEXT = 'cpt_field';

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_meta' ), 10, 4 );
	}

	/**
	 * Read registered meta fields for a post and register values as strings.
	 */
	public static function on_save_post( $post_id, $post, $update ) {
		if ( ! is_object( $post ) || wp_is_post_revision( $post_id ) || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return;
		}
		$fields = self::get_meta_fields_for_post( $post );
		if ( empty( $fields ) ) {
			return;
		}
		foreach ( $fields as $f ) {
			$key  = (string) ( $f['meta_key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$val = get_post_meta( $post_id, $key, true );
			if ( ! is_string( $val ) || '' === $val ) {
				continue;
			}
			String_Translation_Service::register( self::CONTEXT, 'field_' . sanitize_key( $key ) . '_' . (int) $post_id, $val );
		}
	}

	/**
	 * Get registered meta_fields for a given post from wp_wptsall_models.
	 *
	 * @return array
	 */
	public static function get_meta_fields_for_post( $post ) {
		global $wpdb;
		$pt = is_object( $post ) && isset( $post->post_type ) ? (string) $post->post_type : '';
		if ( '' === $pt ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, meta_fields FROM %i WHERE status = %s',
				$wpdb->prefix . 'wptsall_models',
				'active'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$mf = json_decode( (string) $r['meta_fields'], true );
			if ( ! is_array( $mf ) ) {
				continue;
			}
			foreach ( $mf as $entry ) {
				if ( isset( $entry['object_subtype'] ) && $entry['object_subtype'] === $pt && ! empty( $entry['meta_key'] ) ) {
					$out[] = $entry;
				}
			}
		}
		return $out;
	}

	/**
	 * Frontend filter: swap meta value when in a virtual-site request.
	 */
	public static function filter_meta( $value, $object_id, $meta_key, $single ) {
		if ( ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $value;
		}
		$lang = self::current_target_lang();
		if ( '' === $lang || '' === (string) $meta_key ) {
			return $value;
		}

		$fallback = is_string( $value ) ? $value : '';
		if ( '' === $fallback ) {
			// In WordPress this filter runs before metadata is loaded, so $value is
			// normally null. Fetch the raw value with this filter temporarily removed
			// to avoid recursion, then only short-circuit when a translation exists.
			remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_meta' ), 10 );
			$raw = get_post_meta( (int) $object_id, (string) $meta_key, (bool) $single );
			add_filter( 'get_post_metadata', array( __CLASS__, 'filter_meta' ), 10, 4 );
			if ( is_string( $raw ) ) {
				$fallback = $raw;
			} elseif ( is_array( $raw ) && isset( $raw[0] ) && is_string( $raw[0] ) ) {
				$fallback = $raw[0];
			}
		}
		if ( '' === $fallback ) {
			return $value;
		}

		$tr = String_Translation_Service::translate( self::CONTEXT, 'field_' . sanitize_key( (string) $meta_key ) . '_' . (int) $object_id, $fallback, $lang );
		if ( '' !== $tr && $tr !== $fallback ) {
			return $single ? $tr : array( $tr );
		}
		return $value;
	}

	private static function current_target_lang() {
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			return \WPTSALL\Core\Language_Context::current_language();
		}
		return isset( $GLOBALS['wptsall_current_virtual_site']['lang'] )
			? (string) $GLOBALS['wptsall_current_virtual_site']['lang']
			: '';
	}
}
