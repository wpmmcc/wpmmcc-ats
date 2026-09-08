<?php
/**
 * Sites Module
 *
 * Handles site relations, virtual sites, and site management functionality.
 *
 * @package WPTSALL\Sites
 * @since 0.4.0
 */

namespace WPTSALL\Sites;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sites Module class
 *
 * Initializes all components of the Sites module:
 * - Database schemas
 * - REST API endpoints
 * - Admin pages
 * - AJAX handlers
 * - Services
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

		// Note: AJAX handlers removed in v0.5.0, using REST API exclusively

		// v1.1.0: Initialize Translation Editor page
		Admin\Translation_Editor_Page::init();

		// Register admin scripts
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );

		// v0.9.0: Initialize Conflict Page
		Admin\Conflict_Page::init();

		// v0.7.0: Auto-sync template field to relation_models table
		add_action( 'wptsall_site_relations_created', array( __CLASS__, 'sync_template_to_relation_models' ), 10, 2 );

		// P0-1 fix: auto-create a wp_wptsall_virtual_sites row whenever a new
		// virtual target is added to a relation (or an existing one is updated).
		Services\Virtual_Site_Auto_Sync::init();
	}

	/**
	 * Sync template field to relation_models table
	 *
	 * v0.7.0: When a site relation is created, if a template field exists, automatically
	 * create relation_models associations. This is a transition mechanism from the single
	 * template field to the many-to-many relation_models table.
	 *
	 * @param array $relation_ids Created site relation ID list.
	 * @param array $data         Creation data.
	 * @return void
	 */
	public static function sync_template_to_relation_models( $relation_ids, $data ) {
		if ( empty( $relation_ids ) || empty( $data['template'] ) ) {
			return;
		}

		// Find the corresponding model
		$model = \WPTSALL\Models\Services\Translation_Rule_Service::get_model_by_plugin_slug( $data['template'] );
		if ( ! $model || empty( $model['id'] ) ) {
			wptsall_log_warning(
				'sites',
				'Cannot sync template to relation_models: model not found',
				array(
					'template'     => $data['template'],
					'relation_ids' => $relation_ids,
				)
			);
			return;
		}

		$model_id = (int) $model['id'];

		// Create relation_models association for each site relation
		foreach ( $relation_ids as $relation_id ) {
			Services\Relation_Model_Service::add_model_to_relation( (int) $relation_id, $model_id );
		}

		wptsall_log_info(
			'sites',
			'Synced template to relation_models',
			array(
				'template'     => $data['template'],
				'model_id'     => $model_id,
				'relation_ids' => $relation_ids,
			)
		);
	}

	/**
	 * Load database schemas
	 *
	 * @return void
	 */
	private static function load_schemas() {
		$module_path = dirname( __FILE__ );

		require_once $module_path . '/database/schema-site-relations.php';
		require_once $module_path . '/database/schema-relation-models.php';
		require_once $module_path . '/database/schema-virtual-sites.php';
		require_once $module_path . '/database/schema-relation-configs.php';
		require_once $module_path . '/database/schema-user-mappings.php';

	}

	/**
	 * Load function files
	 *
	 * @return void
	 */
	private static function load_functions() {
		$module_path = dirname( __FILE__ );

		// Sites functions
		require_once $module_path . '/sites.php';

	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		$sites_controller = new API\Sites_REST_Controller();
		$sites_controller->register_routes();

		$manual_controller = new API\Manual_Translation_REST_Controller();
		$manual_controller->register_routes();

	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_scripts( $hook ) {
		// Only load on Sites admin page (Sites is a submenu under wptsall)
		// Use strpos for broader matching to handle different WordPress configurations.
		if ( strpos( $hook, 'wptsall-sites' ) === false ) {
			return;
		}

		// Enqueue site relations CSS (v0.6.0).
		wp_enqueue_style(
			'wptsall-site-relations',
			WPTSALL_URL . 'assets/css/site-relations.css',
			array(),
			WPTSALL_VERSION
		);

		// Enqueue site relations JavaScript
		wp_enqueue_script(
			'wptsall-site-relations',
			WPTSALL_URL . 'assets/js/site-relations.js',
			array( 'jquery' ),
			WPTSALL_VERSION,
			true
		);

		// Localize script with REST API configuration and strings
		wp_localize_script(
			'wptsall-site-relations',
			'wptsallSiteRelations',
			array(
				// REST API (v0.5.0+)
				'restUrl'   => rest_url( 'wptsall/v2' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'strings'   => array(
					'confirmDelete'        => __( 'Are you sure you want to delete this site relation?', 'wpmmcc-ats' ),
					'confirmPause'         => __( 'Are you sure you want to pause this site relation?', 'wpmmcc-ats' ),
					'confirmActivate'      => __( 'Are you sure you want to activate this site relation?', 'wpmmcc-ats' ),
					'selectModel'          => __( 'Please select a model template', 'wpmmcc-ats' ),
					'selectSource'         => __( 'Please select a source site', 'wpmmcc-ats' ),
					'selectTarget'         => __( 'Please select at least one target site', 'wpmmcc-ats' ),
					// ISS-SIT-018: Copy Config i18n.
					'copyConfigTitle'      => __( 'Copy Configuration to Other Relations', 'wpmmcc-ats' ),
					'loading'              => __( 'Loading...', 'wpmmcc-ats' ),
					'sourceRelation'       => __( 'Source Relation', 'wpmmcc-ats' ),
					'targetRelation'       => __( 'Target Relation', 'wpmmcc-ats' ),
					'selectTargetRelation' => __( 'Select target relation...', 'wpmmcc-ats' ),
					'copyConfigDesc'       => __( 'Select the site relation to copy the configuration to. The configuration will overwrite the existing configuration of the target relation.', 'wpmmcc-ats' ),
					'cancel'               => __( 'Cancel', 'wpmmcc-ats' ),
					'copyConfig'           => __( 'Copy Configuration', 'wpmmcc-ats' ),
					'configCount'          => __( 'Config Items', 'wpmmcc-ats' ),
					'noOtherRelations'     => __( 'No other relations available', 'wpmmcc-ats' ),
					'selectTargetFirst'    => __( 'Please select a target relation first', 'wpmmcc-ats' ),
					'confirmCopyConfig'    => __( 'Are you sure you want to copy the configuration? This will overwrite the existing configuration of the target relation.', 'wpmmcc-ats' ),
					'copying'              => __( 'Copying...', 'wpmmcc-ats' ),
					/* translators: %d is the number of configuration items copied */
					'copySuccess'          => __( 'Configuration copied successfully! Copied %d items', 'wpmmcc-ats' ),
					'copyFailed'           => __( 'Copy failed', 'wpmmcc-ats' ),
					'loadError'            => __( 'Load failed', 'wpmmcc-ats' ),
				),
			)
		);

	}

}
