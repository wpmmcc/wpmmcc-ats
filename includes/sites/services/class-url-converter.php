<?php
/**
 * Virtual-site URL converter (public API).
 *
 * Mirrors TranslatePress's get_url_for_language() and Polylang's link helpers:
 * a single entry point for adding/stripping virtual path prefixes so themes,
 * SEO layers, and admin "View" links stay consistent.
 *
 * @package WPTSALL\Sites\Services
 * @since 2.2.0
 */

namespace WPTSALL\Sites\Services;

use WPTSALL\Hooks\Virtual_Site_Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Url_Converter class.
 */
class Url_Converter {

	/**
	 * Add the virtual-site path prefix to a URL.
	 *
	 * @param string     $url  Absolute or path URL.
	 * @param array|null $vs   Virtual site row; null = current request context.
	 * @param \WP_Post|null $post Optional post for structure-aware rewrite.
	 * @return string
	 */
	public static function virtualize( $url, $vs = null, $post = null ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return $url;
		}

		if ( null === $vs ) {
			$vs = class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Router' )
				? Virtual_Site_Router::get_current_virtual_site()
				: null;
		}
		if ( ! is_array( $vs ) || empty( $vs['path_prefix'] ) ) {
			return $url;
		}

		unset( $post ); // Reserved for structure-aware callers (Virtual_Site_Router).

		// WPML directory_for_default_language=false: default-lang VS links stay unprefixed.
		if ( self::should_omit_prefix_for_vs( $vs ) ) {
			return self::source_url( $url, $vs );
		}

		// Parameter URL form: ?lang= / ?wptsall_lang= instead of path prefix.
		if ( self::is_param_url_form() ) {
			return self::append_lang_param( $url, $vs );
		}

		$prefix = '/' . trim( (string) $vs['path_prefix'], '/' );
		$parsed = wp_parse_url( $url );

		// Relative path without scheme/host.
		if ( false === $parsed || ( empty( $parsed['host'] ) && isset( $parsed['path'] ) ) ) {
			$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : $url;
			if ( 0 === strpos( $path, $prefix . '/' ) || $path === $prefix || $path === $prefix . '/' ) {
				return $url;
			}
			$path = $prefix . '/' . ltrim( $path, '/' );
			$query    = ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '';
			$fragment = ! empty( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';
			return $path . $query . $fragment;
		}

		$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
		if ( 0 === strpos( $path, $prefix . '/' ) || $path === $prefix || $path === $prefix . '/' ) {
			return $url;
		}

		$new_path = $prefix . ( '/' === $path ? '/' : $path );
		return self::rebuild_url( $parsed, $new_path );
	}

	/**
	 * Whether Settings url_form is parameter mode.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public static function is_param_url_form(): bool {
		if ( ! class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			return false;
		}
		$settings = \WPTSALL\Settings\Services\Settings_Service::get_all();
		return ( $settings['url_form'] ?? 'subdir' ) === 'param';
	}

	/**
	 * Omit path/param prefix for default-language virtual site when setting is off.
	 *
	 * @since 2.2.0
	 * @param array $vs Virtual site.
	 * @return bool
	 */
	public static function should_omit_prefix_for_vs( array $vs ): bool {
		if ( ! class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			return false;
		}
		$settings = \WPTSALL\Settings\Services\Settings_Service::get_all();
		if ( ! empty( $settings['directory_for_default_language'] ) ) {
			return false;
		}
		$default = (string) ( $settings['default_language'] ?? '' );
		$lang    = (string) ( $vs['lang'] ?? '' );
		if ( '' === $default || '' === $lang ) {
			return false;
		}
		return self::normalize_lang( $default ) === self::normalize_lang( $lang );
	}

	/**
	 * Append ?lang= (or wptsall_lang) for parameter URL form.
	 *
	 * @since 2.2.0
	 * @param string $url URL.
	 * @param array  $vs  Virtual site.
	 * @return string
	 */
	public static function append_lang_param( $url, array $vs ) {
		$lang = (string) ( $vs['lang'] ?? '' );
		if ( '' === $lang ) {
			$lang = (string) ( $vs['path_prefix'] ?? '' );
		}
		if ( '' === $lang ) {
			return $url;
		}
		$key = apply_filters( 'wptsall_lang_query_arg', 'lang', $vs );
		$key = is_string( $key ) && '' !== $key ? $key : 'lang';
		if ( function_exists( 'add_query_arg' ) ) {
			return (string) add_query_arg( $key, $lang, $url );
		}
		$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . rawurlencode( $key ) . '=' . rawurlencode( $lang );
	}

	/**
	 * Normalize language code for loose equality (en_US ≈ en-us).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function normalize_lang( string $code ): string {
		return strtolower( str_replace( '-', '_', trim( $code ) ) );
	}

	/**
	 * Strip a virtual-site path prefix from a URL (canonical / source form).
	 *
	 * @param string     $url URL that may include a language prefix.
	 * @param array|null $vs  Virtual site; null tries current then all active sites.
	 * @return string
	 */
	public static function source_url( $url, $vs = null ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return $url;
		}

		$prefixes = array();
		if ( is_array( $vs ) && ! empty( $vs['path_prefix'] ) ) {
			$prefixes[] = trim( (string) $vs['path_prefix'], '/' );
		} else {
			if ( class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Router' ) ) {
				$cur = Virtual_Site_Router::get_current_virtual_site();
				if ( is_array( $cur ) && ! empty( $cur['path_prefix'] ) ) {
					$prefixes[] = trim( (string) $cur['path_prefix'], '/' );
				}
			}
			if ( class_exists( __NAMESPACE__ . '\\Virtual_Site_Service' ) ) {
				foreach ( (array) Virtual_Site_Service::get_all( array( 'status' => 'active' ) ) as $site ) {
					if ( ! empty( $site['path_prefix'] ) ) {
						$prefixes[] = trim( (string) $site['path_prefix'], '/' );
					}
				}
			}
		}
		$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
		if ( empty( $prefixes ) ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );
		$path   = is_array( $parsed ) && isset( $parsed['path'] ) ? (string) $parsed['path'] : $url;

		foreach ( $prefixes as $p ) {
			$needle = '/' . $p;
			if ( $path === $needle || $path === $needle . '/' ) {
				$path = '/';
				break;
			}
			if ( 0 === strpos( $path, $needle . '/' ) ) {
				$path = substr( $path, strlen( $needle ) );
				if ( '' === $path || '/' !== $path[0] ) {
					$path = '/' . ltrim( (string) $path, '/' );
				}
				break;
			}
		}

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			$query    = is_array( $parsed ) && ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '';
			$fragment = is_array( $parsed ) && ! empty( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';
			return $path . $query . $fragment;
		}

		return self::rebuild_url( $parsed, $path );
	}

	/**
	 * Home URL for a virtual site (with trailing slash).
	 *
	 * @param array|null $vs Virtual site; null = current.
	 * @return string
	 */
	public static function home_for_virtual_site( $vs = null ) {
		if ( null === $vs ) {
			$vs = class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Router' )
				? Virtual_Site_Router::get_current_virtual_site()
				: null;
		}
		// Use option 'home' (not home_url()) to avoid recursion with home_url filters.
		$base = untrailingslashit( (string) get_option( 'home' ) );
		if ( '' === $base && function_exists( 'home_url' ) ) {
			$base = untrailingslashit( (string) home_url( '/' ) );
		}
		if ( ! is_array( $vs ) || empty( $vs['path_prefix'] ) ) {
			return $base . '/';
		}
		if ( self::should_omit_prefix_for_vs( $vs ) ) {
			return $base . '/';
		}
		if ( self::is_param_url_form() ) {
			return self::append_lang_param( $base . '/', $vs );
		}
		return $base . '/' . trim( (string) $vs['path_prefix'], '/' ) . '/';
	}

	/**
	 * Rebuild URL from parse_url parts with a new path.
	 *
	 * @param array  $parsed Parsed URL parts.
	 * @param string $path   New path.
	 * @return string
	 */
	private static function rebuild_url( array $parsed, $path ) {
		$out = '';
		if ( ! empty( $parsed['scheme'] ) ) {
			$out .= $parsed['scheme'] . '://';
		}
		if ( ! empty( $parsed['host'] ) ) {
			$out .= $parsed['host'];
		}
		if ( ! empty( $parsed['port'] ) ) {
			$out .= ':' . $parsed['port'];
		}
		$out .= $path;
		if ( ! empty( $parsed['query'] ) ) {
			$out .= '?' . $parsed['query'];
		}
		if ( ! empty( $parsed['fragment'] ) ) {
			$out .= '#' . $parsed['fragment'];
		}
		return $out;
	}
}
