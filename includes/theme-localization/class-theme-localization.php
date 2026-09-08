<?php
/**
 * Theme & Plugin Localization (1.3.0)
 *
 * On virtual-site requests, forces WP's `locale` to the target language
 * locale so that .mo files under wp-content/languages/{plugins,themes} are
 * loaded for the target language. Mirrors WPML's `wpml_locale` filter and
 * TranslatePress's "Force language in locale" setting.
 *
 * The mapping from `target_lang` (e.g. en_US) to `locale` is normally
 * 1:1 (WP locale codes are the same), but we expose an option for edge
 * cases (e.g. target_lang=en_GB but WP only ships en_GB.mo under
 * wp-content/languages/).
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\ThemeLocalization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Theme_Localization {

	const OPTION = 'wptsall_locale_overrides';

	public static function init() {
		// Locale filters are owned by Language_Context (plugins_loaded).
		// Keep this class for overrides API + backward-compatible helpers.
	}

	/**
	 * Filter the WP locale on virtual-site requests.
	 *
	 * @param string $locale
	 * @deprecated 2.0.1 Use Language_Context::filter_locale().
	 */
	public static function filter_locale( $locale ) {
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			return \WPTSALL\Core\Language_Context::filter_locale( $locale );
		}
		return $locale;
	}

	/**
	 * Same as filter_locale but per-plugin textdomain.
	 *
	 * @deprecated 2.0.1 Use Language_Context::filter_plugin_locale().
	 */
	public static function filter_plugin_locale( $locale, $domain ) {
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			return \WPTSALL\Core\Language_Context::filter_plugin_locale( $locale, $domain );
		}
		return $locale;
	}

	/**
	 * Detect the current request's target language.
	 */
	public static function current_target_lang() {
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			return \WPTSALL\Core\Language_Context::current_language();
		}
		return '';
	}

	/**
	 * Get / set locale overrides.
	 *
	 * @return array<string,string>
	 */
	public static function get_overrides() {
		$opt = get_option( self::OPTION, array() );
		return is_array( $opt ) ? $opt : array();
	}

	public static function set_override( $lang, $locale ) {
		$opt = self::get_overrides();
		$opt[ (string) $lang ] = (string) $locale;
		update_option( self::OPTION, $opt );
		return $opt;
	}

	public static function clear_override( $lang ) {
		$opt = self::get_overrides();
		unset( $opt[ (string) $lang ] );
		update_option( self::OPTION, $opt );
		return $opt;
	}
}
