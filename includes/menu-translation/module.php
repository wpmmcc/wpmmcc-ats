<?php
namespace WPTSALL\MenuTranslation;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Module {
	public static function init() {
		add_action( 'init', array( '\\WPTSALL\\MenuTranslation\\Menu_Translation', 'init' ) );
		add_action( 'init', array( '\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service', 'init' ), 20 );
		\WPTSALL\MenuTranslation\Admin\Menu_Sync_Page::init();
		add_action( 'admin_menu', array( '\\WPTSALL\\MenuTranslation\\Admin\\Menu_Sync_Page', 'add_menu_page' ), 25 );
		// Auto-resync pending source menus on admin_init (bounded).
		add_action( 'admin_init', array( __CLASS__, 'maybe_resync_pending_menus' ), 40 );
	}

	/**
	 * @return void
	 */
	public static function maybe_resync_pending_menus() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}
		if ( empty( $_GET['wptsall_menu_resync'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$source = isset( $_GET['source_menu'] ) ? absint( wp_unslash( $_GET['source_menu'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated admin resync trigger.
		if ( $source > 0 && class_exists( '\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service' ) ) {
			\WPTSALL\MenuTranslation\Menu_Mapping_Service::resync_pending_for_source( $source );
		}
	}
}
