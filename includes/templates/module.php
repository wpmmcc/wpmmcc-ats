<?php
/**
 * Templates Module
 *
 * Handles language pack management, POT/PO file scanning and translation entries.
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
 */

namespace WPTSALL\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates Module class
 *
 * Initializes all components of the Templates module:
 * - Database schemas
 * - REST API endpoints
 * - Admin pages
 * - Services
 * - Scanners
 */
class Module {

	/**
	 * Initialize the module
	 *
	 * @return void
	 */
	public static function init() {
		// Load database schemas
		self::load_schemas();

		// Ensure tables exist
		add_action( 'plugins_loaded', array( __CLASS__, 'ensure_tables' ), 20 );

		// Register REST API routes
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Admin pages / admin_post handlers (menus attach to admin_menu).
		self::init_admin();

		// Register hooks for site relation events
		self::register_hooks();
	}

	/**
	 * Load database schemas
	 *
	 * @return void
	 */
	private static function load_schemas() {
		$module_path = dirname( __FILE__ );

		require_once $module_path . '/database/schema-templates.php';
	}

	/**
	 * Ensure database tables exist
	 *
	 * @return void
	 */
	public static function ensure_tables() {
		if ( function_exists( 'wptsall_ensure_templates_tables' ) ) {
			wptsall_ensure_templates_tables();
		}

		// Create system templates after ensuring tables exist
		if ( function_exists( 'wptsall_create_system_templates' ) ) {
			wptsall_create_system_templates();
		}
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		$controller = new API\Template_REST_Controller();
		$controller->register_routes();
	}

	/**
	 * Initialize admin components
	 *
	 * @return void
	 */
	public static function init_admin() {
		Admin\Template_List_Page::init();
		Admin\Theme_Plugin_Localization_Page::init();
		Admin\Browse_As_Role_Page::init();
		add_action( 'admin_menu', array( '\\WPTSALL\\Templates\\Admin\\Theme_Plugin_Localization_Page', 'add_menu_page' ), 14 );
		add_action( 'admin_menu', array( '\\WPTSALL\\Templates\\Admin\\Browse_As_Role_Page', 'add_menu_page' ), 15 );
	}

	/**
	 * Register hooks for integration with other modules
	 *
	 * @return void
	 */
	private static function register_hooks() {
		// When a site relation is deleted, delete associated templates
		add_action( 'wptsall_site_relation_deleted', array( __CLASS__, 'on_relation_deleted' ), 10, 2 );

		// When site relations are created, optionally auto-scan
		add_action( 'wptsall_site_relations_created', array( __CLASS__, 'on_relations_created' ), 10, 2 );
	}

	/**
	 * Handle site relation deletion
	 *
	 * @param int   $relation_id Deleted relation ID
	 * @param array $relation    Relation data before deletion
	 * @return void
	 */
	public static function on_relation_deleted( $relation_id, $relation ) {
		// Delete all templates associated with this relation
		$count = Services\Template_Service::delete_by_relation( $relation_id );

		if ( $count > 0 ) {
			wptsall_log(
				'templates',
				'info',
				'Templates deleted due to relation deletion',
				array(
					'relation_id' => $relation_id,
					'count'       => $count,
				)
			);
		}
	}

	/**
	 * Handle site relations creation
	 *
	 * @param array $relation_ids Created relation IDs
	 * @param array $data         Creation data
	 * @return void
	 */
	public static function on_relations_created( $relation_ids, $data ) {
		// Auto-scan is disabled by default
		// Uncomment the following to enable auto-scan on relation creation
		/*
		foreach ( $relation_ids as $relation_id ) {
			Scanners\Language_Pack_Scanner::scan_relation( $relation_id );
		}
		*/
	}

	/**
	 * Get module version
	 *
	 * @return string
	 */
	public static function get_version() {
		return WPTSALL_VERSION;
	}

	/**
	 * Get module info
	 *
	 * @return array
	 */
	public static function get_info() {
		return array(
			'name'        => __( 'Templates', 'wpmmcc-ats' ),
			'description' => __( 'Language pack management module for scanning and managing POT/PO translation files', 'wpmmcc-ats' ),
			'version'     => self::get_version(),
			'namespace'   => __NAMESPACE__,
		);
	}
}
