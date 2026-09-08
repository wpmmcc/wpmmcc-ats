<?php
/**
 * Tasks Module
 *
 * Handles task management, automation, and cron job functionality.
 *
 * v0.6.0 Changes:
 * - Added Monitoring_Task_Service for relation-level monitoring
 * - Support for one monitoring task per site relation
 * - Cron handler for processing monitoring tasks
 *
 * @package WPTSALL\Tasks
 * @since 0.4.0
 */

namespace WPTSALL\Tasks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tasks Module class
 *
 * Initializes all components of the Tasks module:
 * - Task functions
 * - Automation and cron
 * - Service classes (v0.6.0+)
 * - Admin pages
 */
class Module {

	/**
	 * Initialize the module
	 *
	 * Task creation, monitoring, and cron are available in the free edition.
	 * Client REST endpoints authenticate via the site client API token.
	 *
	 * @return void
	 */
	public static function init() {
		// Register schema and table names.
		add_filter( 'wptsall_table_names', array( __CLASS__, 'register_table_names' ) );
		self::load_schema();

		// Load service classes.
		self::load_services();

		// Load function files (tasks.php, tasks-single.php, automation-cron.php, admin list).
		self::load_functions();

		// Register REST API routes.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Auto-start monitoring when relations/models change (i18n scan only since 1.2.0).
		self::register_relation_hooks();

		// Register monitoring cron hooks (i18n scan recovery only since 1.2.0).
		self::register_monitoring_hooks();

		if ( class_exists( '\\WPTSALL\\Tasks\\Services\\Direct_DB_Service' ) ) {
			\WPTSALL\Tasks\Services\Direct_DB_Service::init_meta_filters();
		}
	}

	/**
	 * Register Pro table names into Free's table registry.
	 *
	 * @param array $tables Table name registry.
	 * @return array
	 */
	public static function register_table_names( $tables ) {
		$tables['tasks']              = 'wptsall_tasks';
		$tables['task_items']         = 'wptsall_task_items';
		$tables['task_logs']          = 'wptsall_task_logs';
		$tables['task_jobs']          = 'wptsall_task_jobs';
		$tables['translation_results'] = 'wptsall_translation_results';
		$tables['origin_visits']      = 'wptsall_origin_visits';
		$tables['manual_queue']       = 'wptsall_manual_queue';
		return $tables;
	}

	/**
	 * Register REST API routes
	 *
	 * @since 0.6.0
	 * @since 0.9.0 Added Conflict_REST_Controller.
	 * @return void
	 */
	public static function register_rest_routes() {
		$module_path = dirname( __FILE__ );

		// Tasks REST Controller (admin task management — always available).
		require_once $module_path . '/api/class-tasks-rest-controller.php';
		$tasks_controller = new API\Tasks_REST_Controller();
		$tasks_controller->register_routes();

		// Site Verification REST Controller (v1.1.0 — always available).
		require_once $module_path . '/api/class-site-rest-controller.php';
		$site_controller = new API\Site_Rest_Controller();
		$site_controller->register_routes();

		// Client controllers (external translation client; always enabled, token-auth).
		$client_api_enabled = function_exists( 'wptsall_is_client_api_enabled' ) && wptsall_is_client_api_enabled();
		if ( $client_api_enabled ) {
			// Client REST Controller (for external Rust client).
			require_once $module_path . '/api/class-client-tasks-rest-controller.php';
			$client_controller = new API\Client_Tasks_REST_Controller();
			$client_controller->register_routes();

			// Client Data REST Controller (v1.0.5 — site-relations, rules, content, i18n, callback, meta).
			require_once $module_path . '/api/class-client-data-rest-controller.php';
			$client_data_controller = new API\Client_Data_REST_Controller();
			$client_data_controller->register_routes();
		}
	}

	/**
	 * Load service classes
	 *
	 * @since 0.6.0
	 * @return void
	 */
	private static function load_services() {
		$module_path = dirname( __FILE__ );

		// Origin/visited de-dup service (v1.0.2).
		require_once $module_path . '/services/class-origin-visit-service.php';

		// Monitoring Task Service (v0.6.0).
		require_once $module_path . '/services/class-monitoring-task-service.php';

		// Job planner service (v1.0.0).
		require_once $module_path . '/services/class-task-job-planner.php';
	}

	/**
	 * Load database schema files
	 *
	 * Always loaded regardless of license status so that DB tables
	 * are registered and can be created/migrated.
	 *
	 * @return void
	 */
	private static function load_schema() {
		$module_path = dirname( __FILE__ );
		require_once $module_path . '/database/schema-tasks.php';
		require_once $module_path . '/database/schema-translation-results.php';
		require_once $module_path . '/database/schema-origin-visits.php';
		require_once $module_path . '/database/schema-manual-queue.php';
	}

	/**
	 * Load function files
	 *
	 * @return void
	 */
	private static function load_functions() {
		$module_path = dirname( __FILE__ );

		// Tasks functions
		require_once $module_path . '/tasks.php';

		// Tasks single page
		require_once $module_path . '/tasks-single.php';

		// Automation and cron
		require_once $module_path . '/automation-cron.php';

		// Admin tasks list (only in admin)
		if ( is_admin() ) {
			require_once $module_path . '/admin/class-wptsall-tasks-list-table.php';
			require_once $module_path . '/admin/admin-tasks-list.php';
		}
	}

	/**
	 * Register monitoring task cron hooks
	 *
	 * @since 0.6.0
	 * @since 0.9.0 Added pending sync task processing.
	 * @since 1.2.0 Re-enabled for i18n scan automation.
	 * @return void
	 */
	private static function register_monitoring_hooks() {
		// Single-event hook: triggered after relation creation for immediate i18n scan.
		add_action( 'wptsall_run_i18n_scan', array( __CLASS__, 'handle_i18n_scan_cron' ), 10, 1 );

		// Recurring hook: checks for interrupted/incomplete i18n scans and resumes them.
		add_action( 'wptsall_process_monitoring_tasks', array( __CLASS__, 'handle_monitoring_cron' ) );
	}

	/**
	 * Register hooks from Sites module changes to Tasks module automation.
	 *
	 * Product rule: "only first delivery + backfill" => tasks should be started by monitoring/backfill,
	 * not by post/term hooks. But relation creation is an admin action and should auto-start backfill.
	 *
	 * @since 1.0.2
	 * @return void
	 */
	private static function register_relation_hooks() {
		// New target site relation => auto backfill (create monitoring task + set active).
		add_action( 'wptsall_site_relations_created', array( __CLASS__, 'on_site_relations_created' ), 20, 2 );

		// Models attached/detached => ensure monitoring task metadata stays in sync.
		add_action( 'wptsall_relation_models_updated', array( __CLASS__, 'on_relation_models_updated' ), 20, 1 );

		// Relation status flipped active/inactive => keep monitoring task status aligned (non-destructive).
		add_action( 'wptsall_site_relation_status_updated', array( __CLASS__, 'on_site_relation_status_updated' ), 20, 2 );

		// Relation config updated => schedule i18n scan if newly enabled.
		add_action( 'wptsall_relation_updated', array( __CLASS__, 'on_relation_config_updated' ), 20, 1 );
	}

	/**
	 * Auto-start monitoring/backfill when new relations are created.
	 *
	 * @param array $relation_ids Created relation IDs.
	 * @param array $data         Creation payload.
	 * @return void
	 */
	public static function on_site_relations_created( $relation_ids, $data ) {
		if ( empty( $relation_ids ) || ! is_array( $relation_ids ) ) {
			return;
		}

		foreach ( $relation_ids as $relation_id ) {
			$relation_id = (int) $relation_id;
			if ( $relation_id <= 0 ) {
				continue;
			}

			$result = Services\Monitoring_Task_Service::start_monitoring( $relation_id );

			if ( empty( $result['success'] ) ) {
				wptsall_log_warning(
					'tasks-monitoring',
					'Auto-start monitoring failed on relation create',
					array(
						'relation_id' => $relation_id,
						'error'       => $result['error'] ?? 'unknown',
					)
				);
			}

			// Schedule i18n scan for virtual relations with i18n enabled.
			self::schedule_i18n_scan( $relation_id );
		}
	}

	/**
	 * Keep monitoring task record aligned with relation models changes.
	 *
	 * Note: we do NOT auto-start a paused monitoring task here, to avoid overriding user intent.
	 *
	 * @param int $relation_id Relation ID.
	 * @return void
	 */
	public static function on_relation_models_updated( $relation_id ) {
		$relation_id = (int) $relation_id;
		if ( $relation_id <= 0 ) {
			return;
		}

		$task = Services\Monitoring_Task_Service::get_by_relation( $relation_id );
		if ( ! $task ) {
			// If relation exists but task doesn't, create+start to enable backfill automatically.
			Services\Monitoring_Task_Service::start_monitoring( $relation_id );
			return;
		}

		// Refresh model_ids field for UI/debugging (processing loads models dynamically).
		$models    = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );
		$model_ids = array_map( 'intval', array_column( $models, 'id' ) );

		Services\Monitoring_Task_Service::update_model_ids( (int) $task['id'], $model_ids );
	}

	/**
	 * Align monitoring status with relation status.
	 *
	 * - relation inactive => pause monitoring task if exists
	 * - relation active => ensure monitoring task exists, but do not force-start if user paused it
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $status      New relation status.
	 * @return void
	 */
	public static function on_site_relation_status_updated( $relation_id, $status ) {
		$relation_id = (int) $relation_id;
		$status      = sanitize_key( (string) $status );

		$task = Services\Monitoring_Task_Service::get_by_relation( $relation_id );

		if ( 'inactive' === $status ) {
			if ( $task && Services\Monitoring_Task_Service::STATUS_PAUSED !== ( $task['status'] ?? '' ) ) {
				Services\Monitoring_Task_Service::update_status( (int) $task['id'], Services\Monitoring_Task_Service::STATUS_PAUSED, 'Relation inactive' );
			}
			return;
		}

		if ( 'active' === $status && ! $task ) {
			Services\Monitoring_Task_Service::start_monitoring( $relation_id );
		}
	}

	/**
	 * Schedule a non-blocking i18n scan for a relation.
	 *
	 * Only schedules if target is virtual and i18n is enabled.
	 *
	 * @since 1.2.0
	 * @param int $relation_id Relation ID.
	 * @return void
	 */
	private static function schedule_i18n_scan( $relation_id ) {
		$relation_id = (int) $relation_id;

		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation || 'virtual' !== ( $relation['target_site_type'] ?? '' ) ) {
			return;
		}

		$config = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		if ( ! $config['translate_plugin_i18n'] && ! $config['translate_theme_i18n'] ) {
			return;
		}

		// Schedule single event for next WP cron tick (non-blocking).
		wp_schedule_single_event( time(), 'wptsall_run_i18n_scan', array( $relation_id ) );

		wptsall_log_info( 'tasks-monitoring', 'i18n scan scheduled', array(
			'relation_id' => $relation_id,
		) );
	}

	/**
	 * Handle single-event cron callback for i18n scan.
	 *
	 * @since 1.2.0
	 * @param int $relation_id Relation ID.
	 * @return void
	 */
	public static function handle_i18n_scan_cron( $relation_id ) {
		$relation_id = (int) $relation_id;
		if ( $relation_id <= 0 ) {
			return;
		}

		Services\Monitoring_Task_Service::run_i18n_scan( $relation_id );
	}

	/**
	 * Handle recurring cron callback to resume interrupted i18n scans.
	 *
	 * Iterates active monitoring tasks and resumes any incomplete scans
	 * whose locks have expired.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function handle_monitoring_cron() {
		$tasks = Services\Monitoring_Task_Service::get_active_tasks( 50 );

		foreach ( $tasks as $task ) {
			$scan = $task['meta']['i18n_scan'] ?? null;
			if ( ! $scan ) {
				continue;
			}

			$status = $scan['status'] ?? 'not_started';

			// Skip completed or not yet started scans.
			if ( in_array( $status, array( 'completed', 'not_started' ), true ) ) {
				continue;
			}

			// Skip if max retries reached.
			if ( ( $scan['retry_count'] ?? 0 ) >= Services\Monitoring_Task_Service::I18N_SCAN_MAX_RETRIES ) {
				continue;
			}

			$relation_id = (int) ( $task['relation_id'] ?? 0 );
			if ( $relation_id <= 0 ) {
				continue;
			}

			// run_i18n_scan checks lock internally, so safe to call directly.
			Services\Monitoring_Task_Service::run_i18n_scan( $relation_id );
		}
	}

	/**
	 * Handle relation config updates — schedule i18n scan when newly enabled.
	 *
	 * @since 1.2.0
	 * @param int   $relation_id Relation ID.
	 * @param array $data        Updated config data.
	 * @return void
	 */
	public static function on_relation_config_updated( $relation_id, $data = array() ) {
		$relation_id = (int) $relation_id;
		if ( $relation_id <= 0 ) {
			return;
		}

		// Check if i18n scan is incomplete.
		$scan_status = Services\Monitoring_Task_Service::get_i18n_scan_status( $relation_id );
		if ( 'completed' === ( $scan_status['status'] ?? '' ) ) {
			return;
		}

		// Schedule scan if i18n is now enabled.
		self::schedule_i18n_scan( $relation_id );
	}

}
