<?php
/**
 * Settings Service
 *
 * Centralized get/set for the `wptsall_settings` option. Replaces the previous
 * single-field `{"client_api_enabled": true}` with a full schema.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\Settings\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Service {

	const OPTION_KEY = 'wptsall_settings';

	/**
	 * Default settings. Matches the P1-3 design doc.
	 */
	public static function defaults() {
		return array(
			'url_form'           => 'subdir',
			'default_language'    => 'zh_CN',
			'detect_browser'     => false,
			'media_handling'     => 'copy',
			'sync_mode'          => 'new_only',
			'debug_mode'         => false,
			'log_retention_days' => 7,
			'client_api_enabled' => true,
			'permalink_fallback' => true,
			'hreflang_emitter'   => 'wpmmcc-ats',
			// WPML DisableHeadLangs: when Yoast/Rank Math sitemap owns alternates, skip head hreflang.
			'prefer_sitemap_hreflang' => true,
			// WPML directory_for_default_language: when true, default-lang VS keeps path prefix on home.
			'directory_for_default_language' => false,
			// Menu Sync: clone newly created nav menus for active virtual sites
			// automatically (default off keeps manual Menu Sync behaviour).
			'menu_auto_clone'                  => false,
			// Uninstall policy: full data deletion (tables, virtual posts, translation
			// memory, language packs) only when explicitly opted in. The default
			// (false) keeps user translation data when the plugin is deleted.
			'delete_data_on_uninstall'         => false,
		);
	}

	/**
	 * Get the full settings array (defaults merged in).
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = array_merge( self::defaults(), $stored );

		$merged['url_form']           = in_array( $merged['url_form'], array( 'subdir', 'subdomain', 'param' ), true ) ? $merged['url_form'] : 'subdir';
		$merged['media_handling']     = in_array( $merged['media_handling'], array( 'copy', 'reference' ), true ) ? $merged['media_handling'] : 'copy';
		$merged['sync_mode']          = in_array( $merged['sync_mode'], array( 'new_only', 'full', 'manual' ), true ) ? $merged['sync_mode'] : 'new_only';
		// Normalize legacy form value "wptsall" (pre-1.9.0 UI bug) to canonical slug.
		if ( 'wptsall' === (string) ( $merged['hreflang_emitter'] ?? '' ) ) {
			$merged['hreflang_emitter'] = 'wpmmcc-ats';
		}
		$merged['hreflang_emitter']   = in_array( $merged['hreflang_emitter'], array( 'wpmmcc-ats', 'yoast', 'none' ), true ) ? $merged['hreflang_emitter'] : 'wpmmcc-ats';
		$merged['detect_browser']     = (bool) $merged['detect_browser'];
		$merged['debug_mode']         = (bool) $merged['debug_mode'];
		$merged['client_api_enabled'] = (bool) $merged['client_api_enabled'];
		$merged['permalink_fallback'] = (bool) $merged['permalink_fallback'];
		$merged['prefer_sitemap_hreflang'] = (bool) ( $merged['prefer_sitemap_hreflang'] ?? true );
		$merged['directory_for_default_language'] = (bool) ( $merged['directory_for_default_language'] ?? false );
		$merged['menu_auto_clone']         = (bool) ( $merged['menu_auto_clone'] ?? false );
		$merged['delete_data_on_uninstall'] = (bool) ( $merged['delete_data_on_uninstall'] ?? false );
		$merged['log_retention_days'] = max( 0, (int) $merged['log_retention_days'] );
		$merged['default_language']   = (string) $merged['default_language'];

		return $merged;
	}

	/**
	 * Get a single setting key (with default fallback).
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::get_all();
		return $all[ $key ] ?? $fallback;
	}

	/**
	 * Update settings. Accepts a partial array; unknown keys are ignored.
	 *
	 * @return array Updated full settings.
	 */
	public static function update( $partial ) {
		if ( ! is_array( $partial ) ) {
			return self::get_all();
		}
		$allowed = array_keys( self::defaults() );
		$clean   = array();
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $partial ) ) {
				$clean[ $k ] = $partial[ $k ];
			}
		}
		foreach ( array( 'detect_browser', 'debug_mode', 'client_api_enabled', 'permalink_fallback', 'prefer_sitemap_hreflang', 'directory_for_default_language', 'menu_auto_clone', 'delete_data_on_uninstall' ) as $b ) {
			if ( isset( $clean[ $b ] ) ) {
				$clean[ $b ] = ! empty( $clean[ $b ] ) ? 1 : 0;
			}
		}
		if ( isset( $clean['log_retention_days'] ) ) {
			$clean['log_retention_days'] = max( 0, (int) $clean['log_retention_days'] );
		}
		if ( isset( $clean['hreflang_emitter'] ) ) {
			if ( 'wptsall' === (string) $clean['hreflang_emitter'] ) {
				$clean['hreflang_emitter'] = 'wpmmcc-ats';
			}
			if ( ! in_array( $clean['hreflang_emitter'], array( 'wpmmcc-ats', 'yoast', 'none' ), true ) ) {
				$clean['hreflang_emitter'] = 'wpmmcc-ats';
			}
		}
		$current = get_option( self::OPTION_KEY, array() );
		$next    = array_merge( is_array( $current ) ? $current : array(), $clean );
		update_option( self::OPTION_KEY, $next );
		do_action( 'wptsall_settings_updated', $next, $partial );
		return self::get_all();
	}
}
