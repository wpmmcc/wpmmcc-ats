<?php
namespace WPTSALL\MediaTranslation;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Module {
	public static function init() {
		// Frontend hooks (fires for both admin + front-end so attachments in admin UI also swap).
		add_action( 'init', array( '\\WPTSALL\\MediaTranslation\\Hooks\\Media_Translation_Frontend', 'init' ), 20 );
		// Admin page.
		add_action( 'admin_menu', array( '\\WPTSALL\\MediaTranslation\\Admin\\Media_Translation_Page', 'add_menu_page' ), 15 );
		add_action( 'admin_post_wptsall_media_save',   array( '\\WPTSALL\\MediaTranslation\\Admin\\Media_Translation_Page', 'handle_save' ) );
		add_action( 'admin_post_wptsall_media_delete', array( '\\WPTSALL\\MediaTranslation\\Admin\\Media_Translation_Page', 'handle_delete' ) );
		// Media library columns + featured mapping metabox (B5).
		add_action( 'admin_init', array( '\\WPTSALL\\MediaTranslation\\Admin\\Media_Library_Columns', 'init' ) );
	}
}
