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
		// UI-28-03: the Settings page renders the Theme & Plugin Locale
		// Override forms; their handlers were removed 2026-09-12 on a wrong
		// "zero callers" premise (these forms ARE the callers) — restore.
		add_action( 'admin_post_wptsall_locale_save',  array( '\\WPTSALL\\Settings\\Admin\\Settings_Page', 'handle_locale_save' ) );
		add_action( 'admin_post_wptsall_locale_clear', array( '\\WPTSALL\\Settings\\Admin\\Settings_Page', 'handle_locale_clear' ) );
		\WPTSALL\Settings\Admin\Seo_Settings_Page::init();
		add_action( 'admin_menu', array( '\\WPTSALL\\Settings\\Admin\\Seo_Settings_Page', 'add_menu_page' ), 13 );
	}
}
