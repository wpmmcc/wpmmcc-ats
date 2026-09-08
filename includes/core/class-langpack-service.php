<?php
/**
 * Language pack helpers (local only).
 *
 * This plugin does NOT download language packs from external servers.
 * Translations for the plugin UI are provided by WordPress.org
 * (translate.wordpress.org) or by locale files installed locally by the site
 * administrator under uploads (legacy installs only).
 *
 * @package WPTSALL
 */

namespace WPTSALL\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Local language-pack helpers.
 *
 * Remote download APIs were removed to comply with WordPress.org review
 * requirements (no hard-coded third-party language-pack service).
 */
class Langpack_Service {

	/**
	 * Load a user-installed language pack from uploads when present.
	 *
	 * Used only for legacy installs that previously wrote packs under
	 * wp-content/uploads/wpmmcc-ats/languages/. New installs should rely on
	 * WordPress.org language packs (translate.wordpress.org).
	 *
	 * @since 1.8.0
	 *
	 * @param string|null $locale Optional locale; defaults to determine_locale().
	 * @return void
	 */
	public static function load_uploads_textdomain( $locale = null ) {
		$locale = $locale ? (string) $locale : determine_locale();
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return;
		}
		$mo_file = trailingslashit( $uploads['basedir'] ) . 'wpmmcc-ats/languages/wpmmcc-ats-' . $locale . '.mo';
		if ( is_readable( $mo_file ) ) {
			load_textdomain( 'wpmmcc-ats', $mo_file );
		}
	}
}
