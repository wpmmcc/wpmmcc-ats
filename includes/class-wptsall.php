<?php
/**
 * Main plugin controller class
 *
 * @package WPTSALL
 * @since 0.2.0
 */

namespace WPTSALL;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main WPTSALL class
 *
 * Singleton pattern - single instance throughout plugin lifecycle
 */
class WPTSALL {

	/**
	 * Plugin version
	 */
	const VERSION = '1.1.0';

	/**
	 * Single instance
	 *
	 * @var WPTSALL
	 */
	private static $instance = null;

	/**
	 * Service container
	 *
	 * @var array
	 */
	private $container = array();

	/**
	 * Legacy mode flag
	 *
	 * @deprecated 1.0.0 All files have been migrated to modular architecture.
	 * @var bool
	 */
	private $legacy_mode = false;

	/**
	 * Get instance (Singleton)
	 *
	 * @return WPTSALL
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * Private to prevent direct instantiation
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize plugin
	 *
	 * @return void
	 */
	private function init() {
		// Load legacy includes for backward compatibility
		if ( $this->legacy_mode ) {
			$this->load_legacy_includes();
		}

		// Register core services
		$this->register_services();

		// Initialize hooks
		$this->init_hooks();
	}

	/**
	 * Load legacy includes
	 *
	 * @deprecated 1.0.0 All files have been migrated to modular architecture.
	 *             Files are now loaded via module.php in each module directory.
	 *
	 * @return void
	 */
	private function load_legacy_includes() {
		// All legacy files have been migrated to modular architecture.
		// This method is kept for backward compatibility but does nothing.
		// Files are now loaded via:
		// - includes/models/module.php
		// - includes/sites/module.php
		// - includes/tasks/module.php
		// - includes/hooks/module.php
		// - includes/templates/module.php
		// - includes/core/ (via bootstrap.php)
	}

	/**
	 * Register core services
	 *
	 * Services are lazily loaded when accessed
	 *
	 * @return void
	 */
	private function register_services() {
		// Validators
		$this->container['template_validator'] = function() {
			return new \WPTSALL\Models\Validators\Template_Validator();
		};

		$this->container['site_relation_validator'] = function() {
			return new \WPTSALL\Sites\Validators\Site_Relation_Validator();
		};

		// Services
		$this->container['site_relation_service'] = function() {
			return new \WPTSALL\Sites\Services\Site_Relation_Service();
		};

		$this->container['virtual_site_service'] = function() {
			return new \WPTSALL\Sites\Services\Virtual_Site_Service();
		};

		// Models
		$this->container['blog_template'] = function() {
			return new Models\Blog_Template();
		};

		/**
		 * Future services:
		 *
		 * $this->container['scanner']      = function() {
		 *     return new Core\Scanner();
		 * };
		 *
		 * $this->container['cache']        = function() {
		 *     return new Utils\Cache_Manager();
		 * };
		 */
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Enqueue admin styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Initialize admin pages
		$this->init_admin_pages();

		// REST API: handled by module-specific REST controllers
		// Frontend: handled by Virtual_Site_Router in hooks module
	}

	/**
	 * Initialize admin pages
	 *
	 * @return void
	 */
	private function init_admin_pages() {
		// Admin pages are now initialized through their respective modules
		// - Sites module: Sites_Page (includes/sites/admin/class-sites-page.php)
		// - Models module: Model_Editor_Page (includes/models/admin/class-model-editor-page.php)
		// - Tasks module: Admin_Tasks_List (includes/tasks/admin/admin-tasks-list.php)
	}

	/**
	 * Get service from container
	 *
	 * @param string $service Service name.
	 * @return mixed Service instance or null.
	 */
	public function get( $service ) {
		if ( ! isset( $this->container[ $service ] ) ) {
			return null;
		}

		// If service is a closure, call it and cache result
		if ( is_callable( $this->container[ $service ] ) ) {
			$this->container[ $service ] = call_user_func( $this->container[ $service ] );
		}

		return $this->container[ $service ];
	}

	/**
	 * Set service in container
	 *
	 * @param string $service Service name.
	 * @param mixed  $value   Service instance or closure.
	 * @return void
	 */
	public function set( $service, $value ) {
		$this->container[ $service ] = $value;
	}

	/**
	 * Check if service exists
	 *
	 * @param string $service Service name.
	 * @return bool
	 */
	public function has( $service ) {
		return isset( $this->container[ $service ] );
	}

	/**
	 * Enqueue admin assets
	 *
	 * @return void
	 */
	public function enqueue_admin_assets() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'wpmmcc-ats' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wptsall-admin-styles',
			plugins_url( 'assets/admin-styles.css', dirname( __FILE__ ) ),
			array(),
			self::VERSION
		);
	}

	/**
	 * Get plugin version
	 *
	 * @return string
	 */
	public function get_version() {
		return self::VERSION;
	}

	/**
	 * Get plugin path
	 *
	 * @return string
	 */
	public function get_plugin_path() {
		return WPTSALL_PATH;
	}

	/**
	 * Get plugin URL
	 *
	 * @return string
	 */
	public function get_plugin_url() {
		return WPTSALL_URL;
	}

	/**
	 * Enable/disable legacy mode
	 *
	 * @param bool $enabled Legacy mode enabled.
	 * @return void
	 */
	public function set_legacy_mode( $enabled ) {
		$this->legacy_mode = (bool) $enabled;
	}

	/**
	 * Check if legacy mode is enabled
	 *
	 * @return bool
	 */
	public function is_legacy_mode() {
		return $this->legacy_mode;
	}
}
