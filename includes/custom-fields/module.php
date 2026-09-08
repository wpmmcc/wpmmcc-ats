<?php
namespace WPTSALL\CustomFields;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Module {
	public static function init() {
		// Frontend hooks (save_post + get_post_metadata) — load before init so save_post catches early.
		add_action( 'init', array( '\\WPTSALL\\CustomFields\\Services\\Custom_Field_Translation_Service', 'init' ) );
		// Admin page.
		add_action( 'admin_menu', array( '\\WPTSALL\\CustomFields\\Admin\\Custom_Fields_Page', 'add_menu_page' ), 16 );
		add_action( 'admin_post_wptsall_cf_save',   array( '\\WPTSALL\\CustomFields\\Admin\\Custom_Fields_Page', 'handle_save' ) );
	}
}
