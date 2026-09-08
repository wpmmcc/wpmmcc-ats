<?php
/**
 * Settings Module Loader
 *
 * @package WPTSALL\Settings
 * @since 1.2.0
 */

namespace WPTSALL\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Module {
	public static function init() {
		add_action( 'admin_menu', array( '\\WPTSALL\\Settings\\Admin\\Settings_Page', 'add_menu_page' ), 12 );
		add_action( 'admin_post_wptsall_settings_save', array( '\\WPTSALL\\Settings\\Admin\\Settings_Page', 'handle_save' ) );
		\WPTSALL\Settings\Admin\Seo_Settings_Page::init();
		add_action( 'admin_menu', array( '\\WPTSALL\\Settings\\Admin\\Seo_Settings_Page', 'add_menu_page' ), 13 );
	}
}
