<?php
/**
 * Languages Module Loader
 *
 * Loads the Languages admin pages and (in future) REST API routes.
 *
 * @package WPTSALL\Languages
 * @since 1.2.0
 */

namespace WPTSALL\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Languages Module.
 */
class Module {
	/**
	 * Initialize the module.
	 */
	public static function init() {
		// Admin page registration.
		add_action( 'admin_menu', array( '\\WPTSALL\\Languages\\Admin\\Languages_Page', 'add_menu_page' ), 11 );

		// Form action handlers.
		$cls = '\\WPTSALL\\Languages\\Admin\\Languages_Page';
		add_action( 'admin_post_wptsall_language_save',        array( $cls, 'handle_save' ) );
		add_action( 'admin_post_wptsall_language_delete',      array( $cls, 'handle_delete' ) );
		add_action( 'admin_post_wptsall_language_set_default', array( $cls, 'handle_set_default' ) );

		// P1-4 — Admin bar language switcher (Polylang pattern).
		add_action( 'admin_bar_menu', array( '\\WPTSALL\\Languages\\Admin\\Admin_Bar_Switcher', 'add_node' ), 100 );
	}
}
