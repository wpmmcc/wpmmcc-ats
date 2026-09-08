<?php
/**
 * Models Module
 *
 * Handles plugin model management, scanning, translation rules, and import/export.
 *
 * @package WPTSALL\Models
 * @since 0.4.0
 */

namespace WPTSALL\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Models Module class
 *
 * Initializes all components of the Models module:
 * - Database schemas
 * - REST API endpoints
 * - Admin pages
 * - Services and scanners
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

		// Load function files
		self::load_functions();

		// Register REST API routes
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Load database schemas
	 *
	 * @return void
	 */
	private static function load_schemas() {
		$module_path = dirname( __FILE__ );

		// schema-models.php contains all model-related table schemas
		// including plugin_mappings table (formerly in schema-plugin-mappings.php)
		require_once $module_path . '/database/schema-models.php';
	}

	/**
	 * Load function files
	 *
	 * @return void
	 */
	private static function load_functions() {
		$module_path = dirname( __FILE__ );

		// Note: Smart_Field_Classifier is autoloaded from core/classifiers/ (WPTSALL\Core\Classifiers namespace).
		// Note: scanner.php has been deprecated and removed.
		// Use Model_Scanner_V2 (includes/models/scanners/class-model-scanner-v2.php) for dynamic scanning.
		// Sample functions moved to core/functions.php (wptsall_sample_posts, wptsall_sample_terms).

		// Import/Export functions
		require_once $module_path . '/import-export.php';
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		// Translation Rule REST controller (V2 API)
		// Note: V1 API (Model_REST_Controller) has been deprecated and removed
		$rule_controller = new API\Translation_Rule_REST_Controller();
		$rule_controller->register_routes();

		// Discovery REST controller (for manual field management)
		$discovery_controller = new API\Discovery_REST_Controller();
		$discovery_controller->register_routes();

		// Custom Model REST controller (V1 API)
		$custom_model_controller = new API\Custom_Model_REST_Controller();
		$custom_model_controller->register_routes();

		// Plugin Mapping REST controller (V2 API)
		$plugin_mapping_controller = new API\Plugin_Mapping_REST_Controller();
		$plugin_mapping_controller->register_routes();

		// Model Objects REST controller (V2 API — manual object CRUD for /wptsall/v2/models/{id}/objects)
		$model_objects_controller = new API\Model_Objects_REST_Controller();
		$model_objects_controller->register_routes();
	}
}
