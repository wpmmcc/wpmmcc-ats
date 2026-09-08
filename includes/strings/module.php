<?php
/**
 * Strings Module Loader
 *
 * @package WPTSALL\Strings
 * @since 1.2.0
 */

namespace WPTSALL\Strings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Module {
	public static function init() {
		add_action( 'admin_menu', array( '\\WPTSALL\\Strings\\Admin\\Strings_Page', 'add_menu_page' ), 13 );
		add_action( 'admin_post_wptsall_strings_save',  array( '\\WPTSALL\\Strings\\Admin\\Strings_Page', 'handle_save' ) );
		add_action( 'admin_post_wptsall_string_delete', array( '\\WPTSALL\\Strings\\Admin\\Strings_Page', 'handle_delete' ) );
		// Front: blogname/tagline from wptsall_strings (Layer B).
		if ( class_exists( '\\WPTSALL\\Strings\\Site_Identity_Translation' ) ) {
			\WPTSALL\Strings\Site_Identity_Translation::init();
		}
	}
}
