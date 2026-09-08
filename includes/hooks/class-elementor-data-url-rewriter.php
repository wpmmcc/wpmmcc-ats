<?php
/**
 * Elementor _elementor_data URL rewriter (sync + runtime).
 *
 * Walks Elementor JSON and virtualizes same-host URL strings for the
 * target virtual site. Complements Output_Link_Localizer (HTML) and
 * Metadata_Id_Remapper (numeric IDs). Not a WPML-style full link-scan UI.
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
 * Elementor_Data_Url_Rewriter class.
 */
class Elementor_Data_Url_Rewriter {

	/**
	 * Recursion / re-entry guard.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Register runtime get_post_metadata filter on virtual-site front.
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 25, 4 );
	}

	/**
	 * Runtime: rewrite _elementor_data URLs when serving a virtual site.
	 *
	 * @param mixed  $value     Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single flag.
	 * @return mixed
	 */
	public static function filter_get_post_metadata( $value, $object_id, $meta_key, $single ) {
		if ( self::$busy ) {
			return $value;
		}
		if ( '_elementor_data' !== (string) $meta_key ) {
			return $value;
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $value;
		}
		/**
		 * Disable Elementor JSON URL rewrite.
		 *
		 * @since 2.2.0
		 * @param bool  $enable Whether to rewrite.
		 * @param array $vs     Virtual site.
		 */
		if ( ! apply_filters( 'wptsall_enable_elementor_url_rewrite', true, $vs ) ) {
			return $value;
		}

		self::$busy = true;
		remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 25 );
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$raw = get_metadata( 'post', (int) $object_id, '_elementor_data', (bool) $single );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 25, 4 );
		self::$busy = false;

		if ( $single ) {
			return self::rewrite_meta_value( $raw, $vs );
		}
		if ( ! is_array( $raw ) ) {
			return array( self::rewrite_meta_value( $raw, $vs ) );
		}
		$out = array();
		foreach ( $raw as $one ) {
			$out[] = self::rewrite_meta_value( $one, $vs );
		}
		return $out;
	}

	/**
	 * Rewrite a stored _elementor_data payload for a virtual site (sync write path).
	 *
	 * @param mixed $value Meta value (JSON string or already-decoded).
	 * @param array $vs    Target virtual site.
	 * @return mixed Same shape as input when possible.
	 */
	public static function rewrite_meta_value( $value, array $vs ) {
		if ( null === $value || false === $value || '' === $value ) {
			return $value;
		}

		$was_string = is_string( $value );
		$data       = $value;
		if ( $was_string ) {
			$decoded = json_decode( $value, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				return self::rewrite_urls_in_text( (string) $value, $vs );
			}
			$data = $decoded;
		}

		$walked = self::walk( $data, $vs );
		if ( ! $was_string ) {
			return $walked;
		}
		$flags = defined( 'JSON_UNESCAPED_SLASHES' ) ? JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE : 0;
		$out   = wp_json_encode( $walked, $flags );
		return is_string( $out ) ? $out : $value;
	}

	/**
	 * Recursively virtualize URL-like strings.
	 *
	 * @param mixed $node Node.
	 * @param array $vs   VS.
	 * @return mixed
	 */
	public static function walk( $node, array $vs ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				$node[ $k ] = self::walk( $v, $vs );
			}
			return $node;
		}
		if ( is_string( $node ) && self::looks_like_localizable_url( $node ) ) {
			return Url_Converter::virtualize( $node, $vs );
		}
		return $node;
	}

	/**
	 * @param string $s Candidate.
	 * @return bool
	 */
	public static function looks_like_localizable_url( $s ) {
		$s = (string) $s;
		if ( '' === $s || strlen( $s ) > 4096 ) {
			return false;
		}
		if ( 0 === stripos( $s, 'http://' ) || 0 === stripos( $s, 'https://' ) ) {
			return true;
		}
		// Elementor often stores absolute paths for internal links / media.
		if ( '/' === $s[0] && false === strpos( $s, ' ' ) ) {
			return (bool) preg_match( '#^/[A-Za-z0-9_./~%+\-?#=&]*$#', $s );
		}
		return false;
	}

	/**
	 * Naive string replace for non-JSON payloads.
	 *
	 * @param string $text Text.
	 * @param array  $vs   VS.
	 * @return string
	 */
	private static function rewrite_urls_in_text( $text, array $vs ) {
		return (string) preg_replace_callback(
			'#(https?://[^\s"\'<>]+|/[A-Za-z0-9_./~%+\-]+)#',
			static function ( $m ) use ( $vs ) {
				return Url_Converter::virtualize( $m[1], $vs );
			},
			$text
		);
	}
}
