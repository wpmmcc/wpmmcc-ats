<?php
/**
 * Output-layer link localizer (HTML treat).
 *
 * Final safety net for same-host href/src/action URLs that bypassed
 * permalink filters (hardcoded theme links, builder HTML). Modeled on
 * Weglot's treat_page / TranslatePress output buffer — runs only on
 * virtual-site HTML responses.
 *
 * @package WPTSALL\Hooks
 * @since 2.2.0
 */

namespace WPTSALL\Hooks;

use WPTSALL\Sites\Services\Url_Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output_Link_Localizer class.
 */
class Output_Link_Localizer {

	/**
	 * Whether the buffer was started.
	 *
	 * @var bool
	 */
	private static $started = false;

	/**
	 * Register buffer start on template_redirect.
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_buffer' ), 0 );
	}

	/**
	 * Start output buffering when serving a virtual site HTML page.
	 *
	 * @return void
	 */
	public static function maybe_start_buffer() {
		if ( self::$started ) {
			return;
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return;
		}
		/**
		 * Disable output-layer link rewriting.
		 *
		 * @since 2.2.0
		 * @param bool  $enable Whether to enable.
		 * @param array $vs     Virtual site.
		 */
		if ( ! apply_filters( 'wptsall_enable_output_link_localizer', true, $vs ) ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'rewrite_html' ) );
		self::$started = true;
	}

	/**
	 * Rewrite same-host absolute/root-relative links to include the VS prefix.
	 *
	 * @param string $html Buffered HTML.
	 * @return string
	 */
	public static function rewrite_html( $html ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		// Skip non-HTML payloads (JSON/XML/feeds).
		$trim = ltrim( $html );
		if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] || '<' !== $trim[0] ) ) {
			return $html;
		}
		if ( 0 === stripos( $trim, '<?xml' ) || false !== stripos( $trim, '<rss' ) || false !== stripos( $trim, '<feed' ) ) {
			return $html;
		}

		$prefix = trim( (string) ( $vs['path_prefix'] ?? '' ), '/' );
		if ( '' === $prefix ) {
			return $html;
		}

		// Protect SEO-emitted hreflang tags: they intentionally mix prefixed
		// virtual URLs with unprefixed x-default / peer URLs. Rewriting their
		// hrefs would break the alternate set (common Weglot/TP pitfall).
		$hreflang_tokens = array();
		$html            = (string) preg_replace_callback(
			'/<link\b[^>]*\brel=["\']alternate["\'][^>]*>/i',
			static function ( $m ) use ( &$hreflang_tokens ) {
				if ( ! preg_match( '/\bhreflang=/i', $m[0] ) ) {
					return $m[0];
				}
				$token                       = '<!--WPTSALL_HREFLANG_' . count( $hreflang_tokens ) . '-->';
				$hreflang_tokens[ $token ] = $m[0];
				return $token;
			},
			$html
		);

		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = rtrim( $home_path, '/' );

		$pattern = '/\b(href|src|action)=([\'"])([^\'"]+)\2/i';
		$html    = (string) preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $vs, $prefix, $home_host, $home_path ) {
				$attr = $m[1];
				$q    = $m[2];
				$url  = $m[3];
				$new  = self::maybe_localize_url( $url, $vs, $prefix, $home_host, $home_path );
				return $attr . '=' . $q . $new . $q;
			},
			$html
		);

		// Search forms: force VS home action + wptsall_vs (handles action="#" that URL rewrite skips).
		$html = Virtual_Site_Link_Filters::localize_search_forms_in_html( $html, $vs );

		// JSON-LD / Schema.org script blocks (WPML-style absolute URL rewrite).
		$html = self::rewrite_json_ld_scripts( $html, $vs, $prefix, $home_host, $home_path );

		foreach ( $hreflang_tokens as $token => $tag ) {
			$html = str_replace( $token, $tag, $html );
		}
		return $html;
	}

	/**
	 * Rewrite same-host URLs inside application/ld+json script tags.
	 *
	 * @since 2.2.0
	 * @param string $html      HTML.
	 * @param array  $vs        Virtual site.
	 * @param string $prefix    Path prefix.
	 * @param string $home_host Home host.
	 * @param string $home_path Home path.
	 * @return string
	 */
	public static function rewrite_json_ld_scripts( $html, array $vs, $prefix, $home_host, $home_path ) {
		$pattern = '#(<script[^>]*type=(["\'])application/ld\+json\2[^>]*>)(.*?)(</script>)#is';
		return (string) preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $vs, $prefix, $home_host, $home_path ) {
				$json = $m[3];
				$decoded = json_decode( $json, true );
				if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
					// Fallback: rewrite quoted absolute/root URLs as strings.
					$rewritten = self::rewrite_urls_in_json_text( $json, $vs, $prefix, $home_host, $home_path );
					return $m[1] . $rewritten . $m[4];
				}
				$walked = self::walk_localize_json( $decoded, $vs, $prefix, $home_host, $home_path );
				$flags  = defined( 'JSON_UNESCAPED_SLASHES' ) ? JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE : 0;
				$out    = wp_json_encode( $walked, $flags );
				if ( ! is_string( $out ) || '' === $out ) {
					return $m[0];
				}
				return $m[1] . $out . $m[4];
			},
			$html
		);
	}

	/**
	 * Recursively localize string URL values in decoded JSON.
	 *
	 * @param mixed  $node      Node.
	 * @param array  $vs        VS.
	 * @param string $prefix    Prefix.
	 * @param string $home_host Host.
	 * @param string $home_path Path.
	 * @return mixed
	 */
	public static function walk_localize_json( $node, array $vs, $prefix, $home_host, $home_path ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				$node[ $k ] = self::walk_localize_json( $v, $vs, $prefix, $home_host, $home_path );
			}
			return $node;
		}
		if ( is_string( $node ) && self::looks_like_url( $node ) ) {
			return self::maybe_localize_url( $node, $vs, $prefix, $home_host, $home_path );
		}
		return $node;
	}

	/**
	 * Heuristic: string looks like an HTTP(S) or root-relative URL.
	 *
	 * @param string $s Candidate.
	 * @return bool
	 */
	public static function looks_like_url( $s ) {
		$s = (string) $s;
		if ( '' === $s || strlen( $s ) > 2048 ) {
			return false;
		}
		if ( 0 === stripos( $s, 'http://' ) || 0 === stripos( $s, 'https://' ) ) {
			return true;
		}
		return (bool) preg_match( '#^/[A-Za-z0-9_./~%+\-]*$#', $s );
	}

	/**
	 * Rewrite URL-looking quoted strings inside raw JSON text.
	 *
	 * @param string $json      Raw JSON.
	 * @param array  $vs        VS.
	 * @param string $prefix    Prefix.
	 * @param string $home_host Host.
	 * @param string $home_path Path.
	 * @return string
	 */
	private static function rewrite_urls_in_json_text( $json, array $vs, $prefix, $home_host, $home_path ) {
		return (string) preg_replace_callback(
			'#"((?:https?:)?//[^"]+|/[A-Za-z0-9_./~%+\-]*)"#',
			static function ( $m ) use ( $vs, $prefix, $home_host, $home_path ) {
				$new = self::maybe_localize_url( $m[1], $vs, $prefix, $home_host, $home_path );
				return '"' . $new . '"';
			},
			$json
		);
	}

	/**
	 * Localize one URL if it is same-host and missing the prefix.
	 *
	 * @param string $url       Candidate URL.
	 * @param array  $vs        Virtual site.
	 * @param string $prefix    Path prefix without slashes.
	 * @param string $home_host Site host.
	 * @param string $home_path Site path (subdir installs).
	 * @return string
	 */
	public static function maybe_localize_url( $url, array $vs, $prefix, $home_host, $home_path ) {
		$url = (string) $url;
		if ( '' === $url || '#' === $url[0] || 0 === stripos( $url, 'javascript:' ) || 0 === stripos( $url, 'mailto:' ) || 0 === stripos( $url, 'tel:' ) || 0 === stripos( $url, 'data:' ) ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );
		$path   = is_array( $parsed ) && isset( $parsed['path'] ) ? (string) $parsed['path'] : '';

		if ( is_array( $parsed ) && ! empty( $parsed['host'] ) && (string) $parsed['host'] !== $home_host ) {
			return $url;
		}

		// Root-relative or absolute same-host.
		if ( '' === $path && ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) ) {
			// Protocol-relative or bare query — leave alone.
			if ( 0 === strpos( $url, '//' ) || '?' === $url[0] ) {
				return $url;
			}
			$path = $url;
		}

		$check = $path;
		if ( '' !== $home_path && 0 === strpos( $check, $home_path ) ) {
			$check = substr( $check, strlen( $home_path ) );
			if ( '' === $check ) {
				$check = '/';
			}
		}

		$needle = '/' . $prefix;
		if ( $check === $needle || $check === $needle . '/' || 0 === strpos( $check, $needle . '/' ) ) {
			return $url;
		}

		// Do not prefix wp-admin / wp-content / wp-includes / wp-json / feeds.
		if ( preg_match( '#^/(wp-admin|wp-content|wp-includes|wp-json|feed|xmlrpc\.php|wp-login\.php)#i', $check ) ) {
			return $url;
		}
		if ( preg_match( '/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|map|xml)(\?|$)/i', $check ) ) {
			return $url;
		}

		return Url_Converter::virtualize( $url, $vs );
	}
}
