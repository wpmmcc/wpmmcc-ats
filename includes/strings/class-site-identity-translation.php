<?php
/**
 * Site identity translation (blogname / blogdescription) from wptsall_strings.
 *
 * Layer B runtime: options come from Strings, not config-site template_entries.
 *
 * @package WPTSALL\Strings
 * @since 1.2.5
 */

namespace WPTSALL\Strings;

use WPTSALL\Strings\Services\String_Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site_Identity_Translation class.
 */
class Site_Identity_Translation {

	/**
	 * Bootstrap front filters.
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'option_blogname', array( __CLASS__, 'filter_blogname' ), 20 );
		add_filter( 'option_blogdescription', array( __CLASS__, 'filter_blogdescription' ), 20 );
	}

	/**
	 * Current target language for Layer B.
	 *
	 * @return string
	 */
	private static function current_lang() {
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			return \WPTSALL\Core\Language_Context::current_language();
		}
		if ( ! empty( $GLOBALS['wptsall_current_virtual_site']['lang'] ) ) {
			return (string) $GLOBALS['wptsall_current_virtual_site']['lang'];
		}
		return '';
	}

	/**
	 * @param string $value Blogname.
	 * @return string
	 */
	public static function filter_blogname( $value ) {
		$lang = self::current_lang();
		if ( '' === $lang || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $value;
		}
		$tr = String_Translation_Service::translate( 'site_title', 'blogname', (string) $value, $lang );
		return ( '' !== $tr ) ? $tr : $value;
	}

	/**
	 * @param string $value Tagline.
	 * @return string
	 */
	public static function filter_blogdescription( $value ) {
		$lang = self::current_lang();
		if ( '' === $lang || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $value;
		}
		$tr = String_Translation_Service::translate( 'site_tagline', 'blogdescription', (string) $value, $lang );
		return ( '' !== $tr ) ? $tr : $value;
	}
}
