<?php
/**
 * Single request language context for virtual-site / locale resolution.
 *
 * Replaces duplicated URL-first-segment heuristics across Theme/Menu/Media/
 * Widget/CustomFields/User modules. Prefer the resolved virtual site; fall
 * back to a validated language-looking path prefix only when needed.
 *
 * @package WPTSALL\Core
 * @since 2.0.1
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language_Context class.
 */
class Language_Context {

	/**
	 * Cached locale for this request (null = unresolved).
	 *
	 * @var string|null
	 */
	private static $locale = null;

	/**
	 * Whether locale filters were registered.
	 *
	 * @var bool
	 */
	private static $filters_ready = false;

	/**
	 * Bootstrap early filters (call from plugins_loaded).
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$filters_ready ) {
			return;
		}
		self::$filters_ready = true;

		// Before most textdomain loads; Theme_Localization still runs on init
		// but now delegates here so both paths share one resolver.
		add_filter( 'locale', array( __CLASS__, 'filter_locale' ), 1 );
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_plugin_locale' ), 1, 2 );
		add_filter( 'determine_locale', array( __CLASS__, 'filter_determine_locale' ), 1 );
		add_filter( 'pre_determine_locale', array( __CLASS__, 'filter_pre_determine_locale' ), 1 );

		/**
		 * Public filter alias for extensions.
		 *
		 * @param string $lang Current target language / locale or ''.
		 */
		add_filter( 'wptsall_current_language', array( __CLASS__, 'current_language' ), 1 );
	}

	/**
	 * Forget cached locale (after virtual site detection switches language).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$locale = null;
	}

	/**
	 * Pin the request language (called when VS router detects a site).
	 *
	 * @param string $lang Locale / language code.
	 * @return void
	 */
	public static function set_language( $lang ) {
		$lang = self::normalize_lang( (string) $lang );
		self::$locale = ( '' !== $lang ) ? $lang : null;
	}

	/**
	 * Current target language for this request.
	 *
	 * @return string Locale like zh_CN / en_US, or '' on source site.
	 */
	public static function current_language() {
		if ( null !== self::$locale && '' !== self::$locale ) {
			return self::$locale;
		}

		$from_vs = self::from_virtual_site();
		if ( '' !== $from_vs ) {
			self::$locale = $from_vs;
			return self::$locale;
		}

		$from_uri = self::from_request_uri_prefix();
		if ( '' !== $from_uri ) {
			self::$locale = $from_uri;
			return self::$locale;
		}

		self::$locale = '';
		return '';
	}

	/**
	 * @param string $locale Incoming locale.
	 * @return string
	 */
	public static function filter_locale( $locale ) {
		$target = self::current_language();
		if ( '' === $target ) {
			return $locale;
		}
		$overrides = get_option( 'wptsall_locale_overrides', array() );
		if ( is_array( $overrides ) && isset( $overrides[ $target ] ) && is_string( $overrides[ $target ] ) && '' !== $overrides[ $target ] ) {
			return (string) $overrides[ $target ];
		}
		return $target;
	}

	/**
	 * @param string $locale Incoming locale.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function filter_plugin_locale( $locale, $domain ) {
		unset( $domain );
		return self::filter_locale( $locale );
	}

	/**
	 * @param string $locale Determined locale.
	 * @return string
	 */
	public static function filter_determine_locale( $locale ) {
		return self::filter_locale( $locale );
	}

	/**
	 * Short-circuit determine_locale when we already know the VS language.
	 *
	 * @param string|null $locale Short-circuit value.
	 * @return string|null
	 */
	public static function filter_pre_determine_locale( $locale ) {
		if ( null !== $locale && '' !== $locale ) {
			return $locale;
		}
		$target = self::from_virtual_site();
		if ( '' === $target ) {
			$target = self::from_request_uri_prefix();
		}
		return ( '' !== $target ) ? $target : $locale;
	}

	/**
	 * @return string
	 */
	private static function from_virtual_site() {
		if ( ! empty( $GLOBALS['wptsall_current_virtual_site']['lang'] ) ) {
			return self::normalize_lang( (string) $GLOBALS['wptsall_current_virtual_site']['lang'] );
		}
		if ( class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Router' ) ) {
			$vs = \WPTSALL\Hooks\Virtual_Site_Router::get_current_virtual_site();
			if ( is_array( $vs ) && ! empty( $vs['lang'] ) ) {
				return self::normalize_lang( (string) $vs['lang'] );
			}
		}
		return '';
	}

	/**
	 * Best-effort: first path segment that looks like xx_YY / xx-YY.
	 *
	 * @return string
	 */
	private static function from_request_uri_prefix() {
		$uri = function_exists( 'wptsall_get_request_uri' ) ? wptsall_get_request_uri() : '';
		if ( '' === $uri ) {
			return '';
		}
		$path  = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$first = explode( '/', $path );
		$first = isset( $first[0] ) ? (string) $first[0] : '';
		if ( '' === $first || ( false === strpos( $first, '_' ) && false === strpos( $first, '-' ) ) ) {
			return '';
		}
		return self::normalize_lang( $first );
	}

	/**
	 * Normalize language-looking tokens to WP-style locales.
	 *
	 * @param string $lang Raw token.
	 * @return string
	 */
	public static function normalize_lang( $lang ) {
		$lang = trim( str_replace( '-', '_', (string) $lang ) );
		if ( '' === $lang ) {
			return '';
		}
		$parts = explode( '_', $lang );
		if ( 2 === count( $parts ) && 2 === strlen( $parts[0] ) && 2 === strlen( $parts[1] ) ) {
			return strtolower( $parts[0] ) . '_' . strtoupper( $parts[1] );
		}
		// Keep known short language codes without a region (e.g. en, fr).
		if ( preg_match( '/^[a-z]{2}$/', $lang ) ) {
			return $lang;
		}
		return $lang;
	}
}
