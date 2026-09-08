<?php
namespace WPTSALL\TranslationMemory;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Module {
	public static function init() {
		add_action( 'admin_menu', array( '\\WPTSALL\\TranslationMemory\\Admin\\Translation_Memory_Page', 'add_menu_page' ), 14 );
		add_action( 'admin_post_wptsall_tm_save',   array( '\\WPTSALL\\TranslationMemory\\Admin\\Translation_Memory_Page', 'handle_save' ) );
		add_action( 'admin_post_wptsall_tm_delete', array( '\\WPTSALL\\TranslationMemory\\Admin\\Translation_Memory_Page', 'handle_delete' ) );
		add_action( 'admin_post_wptsall_tm_export', array( '\\WPTSALL\\TranslationMemory\\Admin\\Translation_Memory_Page', 'handle_export' ) );
	}
}
