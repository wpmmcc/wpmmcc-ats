<?php
namespace WPTSALL\UserTranslation;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Module {
	public static function init() {
		// Frontend hooks (filters + register on save).
		add_action( 'init', array( '\\WPTSALL\\UserTranslation\\Services\\User_Translation_Service', 'init' ) );
		add_action( 'profile_update', array( '\\WPTSALL\\UserTranslation\\Services\\User_Translation_Service', 'on_save_user' ), 20, 2 );
		// Admin page.
		add_action( 'admin_menu', array( '\\WPTSALL\\UserTranslation\\Admin\\User_Translation_Page', 'add_menu_page' ), 17 );
		add_action( 'admin_post_wptsall_user_save',   array( '\\WPTSALL\\UserTranslation\\Admin\\User_Translation_Page', 'handle_save' ) );
		add_action( 'admin_post_wptsall_user_delete', array( '\\WPTSALL\\UserTranslation\\Admin\\User_Translation_Page', 'handle_delete' ) );
	}
}
