<?php
/**
 * Tasks REST Controller
 *
 * Tasks Module REST API
 *
 * @package WPTSALL\Tasks
 * @since 0.6.0
 */
namespace WPTSALL\Tasks\API;

use WPTSALL\Tasks\Services\Monitoring_Task_Service;
use WPTSALL\Tasks\Services\Translation_Simulation_Service;
use WPTSALL\Tasks\Services\Task_Orchestrator;
use WPTSALL\Tasks\Services\Task_Job_Planner;
use WPTSALL\Tasks\Sync\Sync_Executor;
use WPTSALL\Templates\Scanners\Language_Pack_Scanner;
use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tasks_REST_Controller class
 *
 * Provides REST API endpoints for the Tasks module
 */
class Tasks_REST_Controller {

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Tasks collection
		register_rest_route(
			$this->namespace,
			'/tasks',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tasks' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'        => array(
							'type'              => 'string',
							'enum'              => array( 'monitoring', 'sync', 'translation' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'status'      => array(
							'type'              => 'string',
							'enum'              => array( 'pending', 'active', 'paused', 'completed', 'error', 'retry' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'relation_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'job_id'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'business_line' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'task_type'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'blog_id'     => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'target_blog' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'page'        => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page'    => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_task' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'template'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'site_id'     => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'object_type' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'subtype'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'object_id'   => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Task statistics (must be before /tasks/{id})
		register_rest_route(
			$this->namespace,
			'/tasks/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_task_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Job aggregates (must be before /tasks/{id}).
		register_rest_route(
			$this->namespace,
			'/tasks/jobs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_task_jobs' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status'   => array(
							'type'              => 'string',
							'enum'              => array( 'running', 'partial', 'completed', 'failed' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'job_id'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'      => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page'  => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_task_job' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'relation_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'job_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'include_content' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'include_language_pack' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'preview' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'limit' => array(
							'type'              => 'integer',
							'default'           => 100,
							'sanitize_callback' => 'absint',
						),
						'batch_size' => array(
							'type'              => 'integer',
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
						'target_lang' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
							'allow_existing_job' => array(
								'type'    => 'boolean',
								'default' => false,
							),
							'business_lines' => array(
								'type'     => 'array',
								'items'    => array( 'type' => 'string' ),
								'default'  => array(),
							),
							'business_line_limits' => array(
								'type'    => 'object',
								'default' => array(),
							),
							'line_batch_strategy' => array(
								'type'              => 'string',
								'enum'              => array( 'priority', 'round_robin' ),
								'default'           => 'priority',
								'sanitize_callback' => 'sanitize_key',
							),
						),
					),
				)
			);

		// Job-level task list.
		register_rest_route(
			$this->namespace,
			'/tasks/jobs/(?P<job_id>[A-Za-z0-9_\-\.]+)/tasks',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tasks_by_job' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'business_line' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'task_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'page' => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'include_payload' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Job-level retry failed subtasks.
		register_rest_route(
			$this->namespace,
			'/tasks/jobs/(?P<job_id>[A-Za-z0-9_\-\.]+)/retry-failed-subtasks',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'retry_failed_subtasks_by_job' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'business_line' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'task_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 500,
						'sanitize_callback' => 'absint',
					),
					'note' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Batch update status (manual retry / batch operations).
		register_rest_route(
			$this->namespace,
			'/tasks/batch/status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_update_task_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'ids'             => array(
						'required' => true,
						'type'     => 'array',
					),
					'status'          => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'note'            => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'increment_retry' => array(
						'type'              => 'boolean',
						'default'           => false,
					),
				),
			)
		);

		// Retry failed/retry tasks quickly.
		register_rest_route(
			$this->namespace,
			'/tasks/retry-failed',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'retry_failed_tasks' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 100,
						'sanitize_callback' => 'absint',
					),
					'note'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Manual subtask intervention (retry / skip one subtask).
		register_rest_route(
			$this->namespace,
			'/tasks/(?P<id>\d+)/subtasks/manual-action',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'manual_subtask_action' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'action' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'retry', 'skip' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'type'   => array(
						'type'              => 'string',
						'default'           => 'text',
						'sanitize_callback' => 'sanitize_key',
					),
					'key'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'note'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Manual patch queue query.
		register_rest_route(
			$this->namespace,
			'/tasks/(?P<id>\d+)/manual-queue',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_task_manual_queue' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Manual patch queue resolve actions.
		register_rest_route(
			$this->namespace,
			'/tasks/(?P<id>\d+)/manual-queue/resolve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_task_manual_queue' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'action' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'retry', 'dismiss', 'clear_all' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'index'  => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'note'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Language pack task creation (must be before /tasks/{id})
		register_rest_route(
			$this->namespace,
			'/tasks/language',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_language_task' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'textdomain'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'component_type' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'theme', 'plugin', 'core' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'target_lang'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
						'site_id'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'job_id'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'preview'        => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'business_lines' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'business_line_limits' => array(
							'type'    => 'object',
							'default' => array(),
						),
						'line_batch_strategy' => array(
							'type'              => 'string',
							'enum'              => array( 'priority', 'round_robin' ),
							'default'           => 'priority',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			);

		// Single task
		register_rest_route(
			$this->namespace,
			'/tasks/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_task' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_task' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'note'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_task' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Monitoring task status endpoint
		register_rest_route(
			$this->namespace,
			'/tasks/monitor',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_monitor_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'relation_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Site Relation ID', 'wpmmcc-ats' ),
					),
				),
			)
		);

		// Start monitoring
		register_rest_route(
			$this->namespace,
			'/tasks/monitor/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_monitoring' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'relation_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Stop monitoring
		register_rest_route(
			$this->namespace,
			'/tasks/monitor/stop',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stop_monitoring' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'relation_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Trigger language pack scan (P2: Language pack scan entry)
		register_rest_route(
			$this->namespace,
			'/tasks/scan-language-pack',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_language_pack' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'relation_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'mode'        => array(
						'type'              => 'string',
						'default'           => 'pot',
						'enum'              => array( 'pot', 'source', 'content', 'all' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Trigger language pack translation (P3: Scan → Translation flow)
		register_rest_route(
			$this->namespace,
			'/tasks/translate-language-pack',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'translate_language_pack' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'relation_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// ========================================
		// Task orchestration endpoints (v0.8.0)
		// ========================================

		// Discover sync tasks
		register_rest_route(
			$this->namespace,
			'/tasks/discover',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'discover_sync_tasks' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'relation_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Site Relation ID', 'wpmmcc-ats' ),
					),
					'preview'     => array(
						'type'              => 'boolean',
						'default'           => true,
						'description'       => __( 'Preview only (without executing)', 'wpmmcc-ats' ),
					),
				),
			)
		);

		// Get orchestration statistics
		register_rest_route(
			$this->namespace,
			'/tasks/orchestration-stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_orchestration_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// ========================================
		// Task logs endpoints (v0.8.0)
		// ========================================

		// Get logs for a specific task
		register_rest_route(
			$this->namespace,
			'/tasks/(?P<id>\d+)/logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_task_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get all task logs (global query)
		register_rest_route(
			$this->namespace,
			'/task-logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_all_task_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'task_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'status'     => array(
						'type'              => 'string',
						'enum'              => array( 'pending', 'active', 'paused', 'completed', 'error', 'retry' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'start_date' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Start date (YYYY-MM-DD)', 'wpmmcc-ats' ),
					),
					'end_date'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'End date (YYYY-MM-DD)', 'wpmmcc-ats' ),
					),
					'page'       => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'   => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Check permissions and validate addressed task, relation and job objects.
	 *
	 * REST permission callbacks run before their controller callbacks.  Keep
	 * object checks here so an authenticated translation manager cannot reach a
	 * mutation callback with a fabricated task/relation/job identifier.
	 *
	 * @param \WP_REST_Request|null $request Request, when invoked by REST.
	 * @return bool|\WP_Error
	 */
	public function check_permission( $request = null ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}
		if ( ! wptsall_user_can_manage_translations() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'wpmmcc-ats' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $request instanceof \WP_REST_Request ) {
			return true;
		}

		$route = (string) $request->get_route();

		// Task IDs occur in /tasks/{id} and all its nested manual/log routes.
		// Do not use a generic `id` request parameter here: other route families
		// use it for unrelated resources.
		if ( preg_match( '#/tasks/(\d+)(?:/|$)#', $route, $match ) ) {
			$task_id = absint( $match[1] );
			if ( ! $this->task_exists( $task_id ) ) {
				return $this->rest_object_not_found( __( 'Task does not exist.', 'wpmmcc-ats' ) );
			}
		}

		// The global logs endpoint carries a task object as a query parameter.
		if ( $request->has_param( 'task_id' ) && absint( $request->get_param( 'task_id' ) ) > 0
			&& ! $this->task_exists( absint( $request->get_param( 'task_id' ) ) ) ) {
			return $this->rest_object_not_found( __( 'Task does not exist.', 'wpmmcc-ats' ) );
		}

		// Every relation_id accepted by this controller is a relation object, not
		// an arbitrary filter value.  This includes monitoring, scans, discovery,
		// and relation-scoped job creation.
		if ( $request->has_param( 'relation_id' ) && absint( $request->get_param( 'relation_id' ) ) > 0
			&& ! Site_Relation_Service::get_relation( absint( $request->get_param( 'relation_id' ) ) ) ) {
			return $this->rest_object_not_found( __( 'Site relation does not exist.', 'wpmmcc-ats' ) );
		}

		// Legacy POST /tasks and language-pack task creation call the relation
		// parameter `site_id`.  Preserve the legacy name while still treating it
		// as the relation object it is used as by the callback.
		if ( preg_match( '#/tasks(?:/language)?$#', $route ) && $request->has_param( 'site_id' ) ) {
			$site_relation_id = absint( $request->get_param( 'site_id' ) );
			if ( $site_relation_id > 0 && ! Site_Relation_Service::get_relation( $site_relation_id ) ) {
				return $this->rest_object_not_found( __( 'Site relation does not exist.', 'wpmmcc-ats' ) );
			}
		}

		// Job-specific subroutes have an unambiguous job object in the path. A
		// list filter or a job creation request is deliberately excluded: those
		// can legitimately name no rows or a new job ID.
		if ( preg_match( '#/tasks/jobs/([A-Za-z0-9_\-\.]+)/(?:tasks|retry-failed-subtasks)$#', $route, $match )
			&& ! $this->task_job_exists( $match[1] ) ) {
			return $this->rest_object_not_found( __( 'Task job does not exist.', 'wpmmcc-ats' ) );
		}

		if ( preg_match( '#/tasks/batch/status$#', $route ) ) {
			foreach ( (array) $request->get_param( 'ids' ) as $task_id ) {
				$task_id = absint( $task_id );
				if ( $task_id <= 0 || ! $this->task_exists( $task_id ) ) {
					return $this->rest_object_not_found( __( 'One or more tasks do not exist.', 'wpmmcc-ats' ) );
				}
			}
		}

		return true;
	}

	/**
	 * Return a consistent object-not-found REST error.
	 *
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private function rest_object_not_found( $message ) {
		return new \WP_Error( 'rest_object_not_found', $message, array( 'status' => 404 ) );
	}

	/**
	 * Check a task row exists without loading its payload into the permission path.
	 *
	 * @param int $task_id Task ID.
	 * @return bool
	 */
	private function task_exists( $task_id ) {
		global $wpdb;

		$task_id = absint( $task_id );
		if ( $task_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', wptsall_task_table_name(), $task_id )
		);
	}

	/**
	 * Check whether at least one task belongs to a job ID.
	 *
	 * `task_jobs` is an optional aggregate table on older installs, while task
	 * payload is the canonical membership record.  Use the latter so permission
	 * behavior stays correct during upgrades and on fallback installations.
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	private function task_job_exists( $job_id ) {
		global $wpdb;

		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return false;
		}

		$table = wptsall_task_table_name();

		// Prefer task_jobs aggregate table when present.
		$jobs_table = wptsall_table( 'task_jobs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$jobs_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs_table ) );
		if ( $jobs_exists === $jobs_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found_job = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE job_id = %s LIMIT 1',
					$jobs_table,
					$job_id
				)
			);
			if ( $found_job ) {
				return true;
			}
		}

		// Prefer dedicated column on tasks when present.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$col = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'job_id' ), ARRAY_A );
		if ( ! empty( $col ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE job_id = %s LIMIT 1',
					$table,
					$job_id
				)
			);
			if ( $found ) {
				return true;
			}
		}

		// Payload JSON may encode with or without spaces after ":".
		$job_like = '%' . $wpdb->esc_like( '"job_id"' ) . '%' . $wpdb->esc_like( $job_id ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, payload FROM %i WHERE payload LIKE %s ORDER BY id DESC LIMIT 25',
				$table,
				$job_like
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			if ( is_array( $payload ) && $job_id === sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get task list
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response
	 */
	public function get_tasks( $request ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		$type        = $request->get_param( 'type' );
		$status      = $request->get_param( 'status' );
		$relation_id = $request->get_param( 'relation_id' );
		$job_id      = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$raw_business_line = sanitize_key( (string) $request->get_param( 'business_line' ) );
		$raw_task_type     = sanitize_key( (string) $request->get_param( 'task_type' ) );
		$business_line_filter = $this->normalize_job_task_business_line( $raw_business_line );
		$task_type_filter     = $this->normalize_job_task_type( $raw_task_type );
		$page        = max( 1, $request->get_param( 'page' ) );
		$per_page    = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$offset      = ( $page - 1 ) * $per_page;
		$include_payload = rest_sanitize_boolean( $request->get_param( 'include_payload' ) );
		$needs_payload   = $include_payload || '' !== $job_id || '' !== $business_line_filter || '' !== $task_type_filter;

		if ( '' !== $raw_business_line && '' === $business_line_filter ) {
			return new \WP_Error(
				'invalid_business_line',
				__( 'Invalid business_line', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		if ( '' !== $raw_task_type && '' === $task_type_filter ) {
			return new \WP_Error(
				'invalid_task_type',
				__( 'Invalid task_type', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// Build WHERE clause
		$where_parts = array( '1=1' );
		$where_args  = array();

		if ( $type ) {
			$where_parts[] = 'type = %s';
			$where_args[]  = $type;
		}

		if ( $status ) {
			$where_parts[] = 'status = %s';
			$where_args[]  = $status;
		}

		if ( $relation_id ) {
			$where_parts[] = 'relation_id = %d';
			$where_args[]  = $relation_id;
		}
		if ( '' !== $job_id ) {
			$where_parts[] = 'payload LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( '"job_id":"' . $job_id . '"' ) . '%';
		}
		if ( '' !== $business_line_filter ) {
			$where_parts[] = 'payload LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( '"business_line":"' . $business_line_filter . '"' ) . '%';
		}
		if ( '' !== $task_type_filter ) {
			$where_parts[] = 'payload LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( '"task_type":"' . $task_type_filter . '"' ) . '%';
		}

		$where_sql = implode( ' AND ', $where_parts );

		// Get total count — $where_sql is fixed placeholder fragments only.
		$total = (int) wptsall_db_get_var(
			'SELECT COUNT(*) FROM %i WHERE ' . $where_sql,
			array_merge( array( $table ), $where_args )
		);

		// Get tasks. Keep payload out of default list responses because it is LONGTEXT.
		$query_args = array_merge( array( $table ), $where_args, array( $per_page, $offset ) );
		if ( $needs_payload ) {
			$tasks = wptsall_db_get_results(
				'SELECT id, blog_id, target_blog, target_type, target_identifier, site_id, relation_id, template, type, model_ids, priority, object_type, subtype, object_id, lang_from, lang_to, site_mode, status, status_note, retry_count, retry_at, progress, meta, last_check_at, next_check_at, created_at, updated_at, payload FROM %i WHERE ' . $where_sql . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$query_args,
				ARRAY_A
			);
		} else {
			$tasks = wptsall_db_get_results(
				'SELECT id, blog_id, target_blog, target_type, target_identifier, site_id, relation_id, template, type, model_ids, priority, object_type, subtype, object_id, lang_from, lang_to, site_mode, status, status_note, retry_count, retry_at, progress, meta, last_check_at, next_check_at, created_at, updated_at FROM %i WHERE ' . $where_sql . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$query_args,
				ARRAY_A
			);
		}

		// Parse JSON fields
		$tasks = is_array( $tasks ) ? $tasks : array();
		foreach ( $tasks as &$task ) {
			$task['progress']  = json_decode( $task['progress'] ?? '{}', true ) ?: array();
			$task['meta']      = json_decode( $task['meta'] ?? '{}', true ) ?: array();
			$task['model_ids'] = json_decode( $task['model_ids'] ?? '[]', true ) ?: array();

			$payload = json_decode( $task['payload'] ?? '{}', true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
			$task['job_id'] = sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) );
			$task['business_line'] = $this->normalize_job_task_business_line( (string) ( $payload['business_line'] ?? '' ) );
			if ( '' === $task['business_line'] ) {
				if ( function_exists( 'wptsall_infer_business_line_from_task' ) ) {
					$infer_task = array_merge(
						array(
							'object_type' => sanitize_key( (string) ( $task['object_type'] ?? '' ) ),
							'subtype'     => sanitize_key( (string) ( $task['subtype'] ?? '' ) ),
						),
						$payload
					);
					$task['business_line'] = sanitize_key( (string) wptsall_infer_business_line_from_task( $infer_task ) );
				} else {
					$task['business_line'] = 'custom_model';
				}
			}
			if ( '' === $task['business_line'] ) {
				$task['business_line'] = 'custom_model';
			}
			$task['task_type'] = $this->normalize_job_task_type( (string) ( $payload['task_type'] ?? ( $payload['type'] ?? '' ) ) );
			if ( '' === $task['task_type'] ) {
				$task['task_type'] = 'text';
			}
			$task['subtasks'] = $this->summarize_job_task_subtasks( $payload, $task['meta'], sanitize_key( (string) ( $task['status'] ?? '' ) ) );
			$task['manual_queue'] = $this->summarize_task_manual_queue( $task['meta'] );
			if ( ! $include_payload ) {
				unset( $task['payload'] );
			}
		}

		return rest_ensure_response( array(
			'items' => $tasks,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
			'page'  => $page,
		) );
	}

	/**
	 * Get single task
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_task( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$task = Monitoring_Task_Service::get( $id );

		if ( ! $task ) {
			return new \WP_Error(
				'not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$task['meta'] = is_array( $task['meta'] ?? null ) ? $task['meta'] : array();
		$task['manual_queue'] = array(
			'summary' => $this->summarize_task_manual_queue( $task['meta'] ),
			'items'   => $this->normalize_task_manual_queue_items( $task['meta'] ),
		);

		return rest_ensure_response( $task );
	}

	/**
	 * Delete task
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_task( $request ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		$id   = absint( $request->get_param( 'id' ) );
		$task = Monitoring_Task_Service::get( $id );

		if ( ! $task ) {
			return new \WP_Error(
				'not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $result ) {
			wptsall_log_error( 'tasks-api', 'delete_task failed', array( 'task_id' => $id ) );
			return new \WP_Error(
				'delete_failed',
				__( 'Delete failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		wptsall_log_info(
			'tasks-api',
			'Task deleted via API',
			array( 'task_id' => $id )
		);

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Create a new task
	 *
	 * Migrated from rest.php v1 endpoint.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_task( $request ) {
		$template    = $request->get_param( 'template' );
		$site_id     = $request->get_param( 'site_id' );
		$object_type = $request->get_param( 'object_type' );
		$subtype     = $request->get_param( 'subtype' );
		$object_id   = $request->get_param( 'object_id' );

		// Get plugin mapping.
		$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( $template );
		if ( ! $mapping ) {
			return new \WP_Error(
				'not_found',
				__( 'Plugin mapping not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Convert to template format.
		$tpl = wptsall_mapping_to_template( $mapping );
		if ( ! $tpl ) {
			return new \WP_Error(
				'invalid_mapping',
				__( 'Invalid mapping configuration', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		// Get site relation.
		$relation = Site_Relation_Service::get_relation( $site_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'invalid_site',
				__( 'Invalid site relation', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Build site_rel array for compatibility.
		$site_rel = array(
			'id'       => $relation['id'],
			'template' => $relation['template'],
			'source'   => array(
				'type' => 'wp',
				'id'   => $relation['source_site_id'],
				'lang' => $relation['source_lang'],
			),
			'targets'  => array(
				array(
					'type'    => $relation['target_site_type'],
					'id'      => $relation['target_site_id'],
					'lang_to'          => $relation['target_lang'],
				'target_language'  => $relation['target_lang'],
				),
			),
		);

		$task = wptsall_generate_task_for_object( $tpl, $site_rel, $object_type, $subtype, $object_id );

		if ( is_wp_error( $task ) ) {
			wptsall_log_error(
				'tasks-api',
				'create_task failed: generate error',
				array(
					'template'    => $template,
					'site_id'     => $site_id,
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'error'       => $task->get_error_message(),
				)
			);
			return $task;
		}

		if ( ! $task ) {
			wptsall_log_error(
				'tasks-api',
				'create_task failed: unknown error',
				array(
					'template'    => $template,
					'site_id'     => $site_id,
					'object_type' => $object_type,
					'object_id'   => $object_id,
				)
			);
			return new \WP_Error(
				'invalid_object',
				__( 'Failed to generate task: Unknown error', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// Normalize for planner protocol (bypasses Task_Job_Planner).
		$task = wptsall_normalize_task_for_planner( $task, 'rest_create_task' );

		wptsall_insert_tasks( array( $task ) );
		$row = wptsall_fetch_task_row( $task['blog_id'], $task['object_id'], $task['subtype'], $task['template'] );

		if ( $row ) {
			wptsall_update_task_status( $row, 'pending', 'REST created' );
		}

		wptsall_log_info(
			'tasks-api',
			'Task created via API',
			array(
				'template' => $template,
				'site_id'  => $site_id,
				'task_id'  => absint( $row['id'] ?? 0 ),
			)
		);

		return rest_ensure_response(
			array(
				'created' => true,
				'task_id' => absint( $row['id'] ?? 0 ),
			)
		);
	}

	/**
	 * Update task status
	 *
	 * Migrated from rest.php v1 endpoint.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_task( $request ) {
		global $wpdb;

		$table  = wptsall_task_table_name();
		$id     = absint( $request->get_param( 'id' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$note   = sanitize_text_field( (string) ( $request->get_param( 'note' ) ?? '' ) );

		if ( ! $id || ! $status ) {
			return new \WP_Error(
				'invalid',
				__( 'Status parameter required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->is_allowed_status( $status ) ) {
			return new \WP_Error(
				'invalid_status',
				__( 'Invalid status', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new \WP_Error(
				'task_not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$increment_retry = in_array( $status, array( 'retry', 'failed', 'error' ), true );
		$manual_context  = $this->build_manual_audit_context(
			$request,
			'update_task',
			array(
				'task_id'         => $id,
				'previous_status' => (string) ( $row['status'] ?? '' ),
				'target_status'   => $status,
				'increment_retry' => (bool) $increment_retry,
			)
		);
		wptsall_update_task_status( $row, $status, $note, $increment_retry, $manual_context );
		$retry_count = intval( $row['retry_count'] ?? 0 ) + ( $increment_retry ? 1 : 0 );

		return rest_ensure_response(
			array(
				'id'          => $id,
				'status'      => $status,
				'retry_count' => $retry_count,
			)
		);
	}

	/**
	 * Manual action for a single subtask.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function manual_subtask_action( $request ) {
		global $wpdb;

		$table    = wptsall_task_table_name();
		$id       = absint( $request->get_param( 'id' ) );
		$action   = sanitize_key( (string) $request->get_param( 'action' ) );
		$type     = $this->normalize_manual_subtask_type( (string) ( $request->get_param( 'type' ) ?? 'text' ) );
		$key      = $this->normalize_manual_subtask_key( (string) $request->get_param( 'key' ) );
		$note     = sanitize_text_field( (string) ( $request->get_param( 'note' ) ?? '' ) );

		if ( $id <= 0 ) {
			return new \WP_Error(
				'invalid_task_id',
				__( 'Invalid Task ID', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		if ( ! in_array( $action, array( 'retry', 'skip' ), true ) ) {
			return new \WP_Error(
				'invalid_action',
				__( 'Invalid subtask operation (only retry/skip supported)', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		if ( '' === $key ) {
			return new \WP_Error(
				'invalid_subtask_key',
				__( 'Subtask key cannot be empty', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, retry_count, status_note, payload, meta
				 FROM %i
				 WHERE id = %d',
				$table,
				$id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new \WP_Error(
				'task_not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$meta    = json_decode( (string) ( $row['meta'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$client_result = is_array( $meta['client_result'] ?? null ) ? $meta['client_result'] : array();
		$subtasks      = is_array( $client_result['subtasks'] ?? null ) ? $client_result['subtasks'] : array();
		if ( empty( $subtasks ) ) {
			return new \WP_Error(
				'client_subtasks_missing',
				__( 'Current task has no operable subtask results', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$target_index = -1;
		foreach ( $subtasks as $idx => $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			$subtask_type = $this->normalize_manual_subtask_type( (string) ( $subtask['type'] ?? '' ) );
			$subtask_key  = $this->normalize_manual_subtask_key( (string) ( $subtask['key'] ?? '' ) );
			if ( $subtask_type === $type && $subtask_key === $key ) {
				$target_index = (int) $idx;
				break;
			}
		}
		if ( $target_index < 0 ) {
			return new \WP_Error(
				'subtask_not_found',
				__( 'Specified subtask not found (type/key)', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$target_subtask = is_array( $subtasks[ $target_index ] ) ? $subtasks[ $target_index ] : array();
		$state_history  = is_array( $target_subtask['state_history'] ?? null )
			? $target_subtask['state_history']
			: array();
		$state_history[] = 'manual_' . $action;
		$target_subtask['type']         = $type;
		$target_subtask['key']          = $key;
		$target_subtask['state_history'] = $state_history;

		if ( 'retry' === $action ) {
			$target_subtask['status']        = 'pending';
			$target_subtask['error_code']    = 'MANUAL_RETRY_REQUESTED';
			$target_subtask['error_message'] = '' !== $note ? $note : __( 'Admin manual retry subtask', 'wpmmcc-ats' );
		} else {
			$target_subtask['status']        = 'skipped';
			$target_subtask['error_code']    = 'MANUAL_SKIPPED';
			$target_subtask['error_message'] = '' !== $note ? $note : __( 'Admin manual skip subtask', 'wpmmcc-ats' );
		}
		$subtasks[ $target_index ] = $target_subtask;

		$summary = $this->summarize_manual_subtasks( $subtasks );
		$client_result['subtasks'] = $subtasks;
		$client_result['summary']  = array(
			'subtask_total'     => $summary['total'],
			'subtask_completed' => $summary['completed'],
			'subtask_failed'    => $summary['failed'],
			'subtask_skipped'   => $summary['skipped'],
			'partial'           => $summary['failed'] > 0 && ( $summary['completed'] + $summary['skipped'] ) > 0,
		);
		$meta['client_result'] = $client_result;

		if ( ! is_array( $meta['client_result_aggregate'] ?? null ) ) {
			$meta['client_result_aggregate'] = array();
		}
		$meta['client_result_aggregate']['subtask_total']     = $summary['total'];
		$meta['client_result_aggregate']['subtask_completed'] = $summary['completed'];
		$meta['client_result_aggregate']['subtask_failed']    = $summary['failed'];
		$meta['client_result_aggregate']['subtask_skipped']   = $summary['skipped'];
		if ( is_array( $meta['client_result_envelope']['aggregate'] ?? null ) ) {
			$meta['client_result_envelope']['aggregate']['subtask_total']     = $summary['total'];
			$meta['client_result_envelope']['aggregate']['subtask_completed'] = $summary['completed'];
			$meta['client_result_envelope']['aggregate']['subtask_failed']    = $summary['failed'];
			$meta['client_result_envelope']['aggregate']['subtask_skipped']   = $summary['skipped'];
		}

		$manual_record = array(
			'action'     => $action,
			'type'       => $type,
			'key'        => $key,
			'note'       => $note,
			'at'         => current_time( 'mysql' ),
			'user_id'    => get_current_user_id(),
			'user_login' => sanitize_text_field( (string) ( wp_get_current_user()->user_login ?? '' ) ),
		);
		if ( ! is_array( $meta['manual_subtask_actions'] ?? null ) ) {
			$meta['manual_subtask_actions'] = array();
		}
		$meta['manual_subtask_actions'][] = $manual_record;
		if ( count( $meta['manual_subtask_actions'] ) > 30 ) {
			$meta['manual_subtask_actions'] = array_slice( $meta['manual_subtask_actions'], -30 );
		}

		$fragment_key = $this->build_manual_subtask_fragment_key( $type, $key );
		if ( 'retry' === $action ) {
			$payload['manual_retry_only_subtasks'] = array( $fragment_key );
		}

		$new_status      = sanitize_key( (string) ( $row['status'] ?? '' ) );
		$new_retry_count = (int) ( $row['retry_count'] ?? 0 );
		$status_note     = '' !== $note
			? $note
			: sprintf( 'manual_%s:%s', $action, $fragment_key );

		if ( 'retry' === $action ) {
			$new_status = 'retry';
			$new_retry_count++;
		} elseif ( 0 === (int) $summary['failed'] && in_array( $new_status, array( 'failed', 'retry' ), true ) ) {
			$new_status = 'completed';
		}

		$manual_context = $this->build_manual_audit_context(
			$request,
			'manual_subtask_action',
			array(
				'task_id'       => $id,
				'action_target' => array(
					'type' => $type,
					'key'  => $key,
				),
				'action'        => $action,
				'next_status'   => $new_status,
			)
		);
		$meta['manual_last_action'] = $manual_context;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'status'      => $new_status,
				'status_note' => $status_note,
				'retry_count' => $new_retry_count,
				'payload'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'meta'        => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new \WP_Error(
				'manual_subtask_action_failed',
				__( 'Subtask manual intervention write failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'task_id'      => $id,
					'status'       => $new_status,
					'retry_count'  => $new_retry_count,
					'action'       => $action,
					'target'       => array(
						'type' => $type,
						'key'  => $key,
					),
					'summary'      => $summary,
					'retry_filter' => is_array( $payload['manual_retry_only_subtasks'] ?? null ) ? $payload['manual_retry_only_subtasks'] : array(),
				),
			)
		);
	}

	/**
	 * Get task manual patch queue.
	 *
	 * @since 1.0.4
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_task_manual_queue( $request ) {
		$row = $this->load_task_row_for_manual_queue( absint( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$meta  = is_array( $row['meta'] ?? null ) ? $row['meta'] : array();
		$items = $this->normalize_task_manual_queue_items( $meta );
		$summary = $this->summarize_task_manual_queue( $meta );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'task_id'      => absint( $row['id'] ?? 0 ),
					'status'       => sanitize_key( (string) ( $row['status'] ?? '' ) ),
					'retry_count'  => absint( $row['retry_count'] ?? 0 ),
					'summary'      => $summary,
					'items'        => $items,
					'retry_filter' => is_array( $row['payload']['manual_retry_only_subtasks'] ?? null )
						? array_values( array_map( 'sanitize_text_field', $row['payload']['manual_retry_only_subtasks'] ) )
						: array(),
				),
			)
		);
	}

	/**
	 * Resolve task manual patch queue.
	 *
	 * Actions:
	 * - retry: set task to retry and optionally focus retry_filter by queued unapplied fragments.
	 * - dismiss: remove one queue item (by index; default latest).
	 * - clear_all: clear queue.
	 *
	 * @since 1.0.4
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resolve_task_manual_queue( $request ) {
		global $wpdb;

		$table  = wptsall_task_table_name();
		$task_id = absint( $request->get_param( 'id' ) );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$index  = absint( $request->get_param( 'index' ) );
		$note   = sanitize_text_field( (string) ( $request->get_param( 'note' ) ?? '' ) );

		$row = $this->load_task_row_for_manual_queue( $task_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! in_array( $action, array( 'retry', 'dismiss', 'clear_all' ), true ) ) {
			return new \WP_Error(
				'invalid_action',
				__( 'Invalid manual queue operation (only retry/dismiss/clear_all supported)', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$payload = is_array( $row['payload'] ?? null ) ? $row['payload'] : array();
		$meta    = is_array( $row['meta'] ?? null ) ? $row['meta'] : array();
		$items   = $this->normalize_task_manual_queue_items( $meta );
		if ( empty( $items ) ) {
			return new \WP_Error(
				'manual_queue_empty',
				__( 'Current task has no pending manual queue records', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$new_status     = sanitize_key( (string) ( $row['status'] ?? '' ) );
		$new_retry_count = absint( $row['retry_count'] ?? 0 );
		$status_note    = sanitize_text_field( (string) ( $row['status_note'] ?? '' ) );
		$now            = current_time( 'mysql' );
			$queue_items    = is_array( $meta['client_patch_manual_queue'] ?? null ) ? array_values( $meta['client_patch_manual_queue'] ) : array();
			$retry_filter_limit = 200;
			$retry_filter   = is_array( $payload['manual_retry_only_subtasks'] ?? null )
				? array_values(
					array_filter(
						array_map( 'sanitize_text_field', $payload['manual_retry_only_subtasks'] ),
						static function ( $value ) {
							return '' !== trim( (string) $value );
						}
					)
				)
				: array();
			$retry_filter   = array_values( array_unique( $retry_filter ) );
			if ( count( $retry_filter ) > $retry_filter_limit ) {
				$retry_filter = array_slice( $retry_filter, -1 * $retry_filter_limit );
			}

		$target_indexes = array();
		if ( $index > 0 ) {
			$target_indexes[] = $index - 1;
		}
		if ( empty( $target_indexes ) ) {
			$target_indexes[] = max( 0, count( $queue_items ) - 1 );
		}

			if ( 'retry' === $action ) {
				$fragment_keys = $this->collect_manual_queue_fragment_keys( $items, $index );
				if ( ! empty( $fragment_keys ) ) {
					$retry_filter = array_values( array_unique( array_merge( $retry_filter, $fragment_keys ) ) );
					if ( count( $retry_filter ) > $retry_filter_limit ) {
						$retry_filter = array_slice( $retry_filter, -1 * $retry_filter_limit );
					}
					$payload['manual_retry_only_subtasks'] = $retry_filter;
				}
			$new_status = 'retry';
			$new_retry_count++;
			$status_note = '' !== $note
				? $note
				: sprintf(
					/* translators: 1: task id, 2: datetime */
					__( 'Manual queue retry requested (task #%1$d, %2$s)', 'wpmmcc-ats' ),
					$task_id,
					$now
				);
		} elseif ( 'dismiss' === $action ) {
			$dismissed_fragment_keys = $this->collect_manual_queue_fragment_keys( $items, $index );
			rsort( $target_indexes );
			foreach ( $target_indexes as $target_index ) {
				if ( $target_index < 0 || $target_index >= count( $queue_items ) ) {
					continue;
				}
				array_splice( $queue_items, $target_index, 1 );
			}
			$meta['client_patch_manual_queue'] = array_values( $queue_items );
			if ( ! empty( $dismissed_fragment_keys ) && ! empty( $retry_filter ) ) {
				$retry_filter = array_values( array_diff( $retry_filter, $dismissed_fragment_keys ) );
			}
			if ( empty( $retry_filter ) ) {
				unset( $payload['manual_retry_only_subtasks'] );
			} else {
				$payload['manual_retry_only_subtasks'] = $retry_filter;
			}
			$status_note = '' !== $note
				? $note
				: sprintf(
					/* translators: %s: datetime */
					__( 'Manual queue item dismissed (%s)', 'wpmmcc-ats' ),
					$now
				);
		} else {
			$meta['client_patch_manual_queue'] = array();
			unset( $payload['manual_retry_only_subtasks'] );
			$status_note = '' !== $note
				? $note
				: sprintf(
					/* translators: %s: datetime */
					__( 'Manual queue cleared (%s)', 'wpmmcc-ats' ),
					$now
				);
		}

		$manual_context = $this->build_manual_audit_context(
			$request,
			'resolve_task_manual_queue',
			array(
				'task_id'    => $task_id,
				'action'     => $action,
				'index'      => $index,
				'next_status'=> $new_status,
			)
		);
		$meta['manual_last_action'] = $manual_context;
		if ( ! is_array( $meta['manual_patch_queue_actions'] ?? null ) ) {
			$meta['manual_patch_queue_actions'] = array();
		}
		$meta['manual_patch_queue_actions'][] = array(
			'action'       => $action,
			'index'        => $index,
			'note'         => $note,
			'status_after' => $new_status,
			'retry_count'  => $new_retry_count,
			'at'           => $now,
			'user_id'      => get_current_user_id(),
			'user_login'   => sanitize_text_field( (string) ( wp_get_current_user()->user_login ?? '' ) ),
		);
		if ( count( $meta['manual_patch_queue_actions'] ) > 30 ) {
			$meta['manual_patch_queue_actions'] = array_slice( $meta['manual_patch_queue_actions'], -30 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'status'      => $new_status,
				'status_note' => $status_note,
				'retry_count' => $new_retry_count,
				'payload'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'meta'        => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at'  => $now,
			),
			array( 'id' => $task_id ),
			array( '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new \WP_Error(
				'manual_queue_update_failed',
				__( 'Manual queue operation write failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		$job_snapshot = $this->refresh_job_snapshot_by_job_id( sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) ) );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'task_id'      => $task_id,
					'action'       => $action,
					'status'       => $new_status,
					'retry_count'  => $new_retry_count,
					'summary'      => $this->summarize_task_manual_queue( $meta ),
					'items'        => $this->normalize_task_manual_queue_items( $meta ),
					'retry_filter' => is_array( $payload['manual_retry_only_subtasks'] ?? null )
						? array_values( array_map( 'sanitize_text_field', $payload['manual_retry_only_subtasks'] ) )
						: array(),
					'job'          => $job_snapshot,
				),
			)
		);
	}

	/**
	 * Batch update task status.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function batch_update_task_status( $request ) {
		global $wpdb;

		$table = wptsall_task_table_name();
		$ids   = $request->get_param( 'ids' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$note   = sanitize_text_field( (string) ( $request->get_param( 'note' ) ?? '' ) );
		$force_increment_retry = (bool) $request->get_param( 'increment_retry' );

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return new \WP_Error(
				'invalid_ids',
				__( 'Array parameter ids required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		if ( count( $ids ) > 200 ) {
			return new \WP_Error(
				'too_many_ids',
				__( 'Batch update count cannot exceed 200', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		if ( ! $this->is_allowed_status( $status ) ) {
			return new \WP_Error(
				'invalid_status',
				__( 'Invalid status', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$success = 0;
		$missing = 0;
		$failed  = 0;
		$updated_ids = array();
		$base_context = $this->build_manual_audit_context(
			$request,
			'batch_update_task_status',
			array(
				'target_status'   => $status,
				'requested_count' => count( $ids ),
				'note'            => $note,
			)
		);
		foreach ( $ids as $raw_id ) {
			$id = absint( $raw_id );
			if ( $id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				++$missing;
				continue;
			}

			$increment_retry = $force_increment_retry || in_array( $status, array( 'retry', 'failed', 'error' ), true );
			$task_context    = array_merge(
				$base_context,
				array(
					'task_id'         => $id,
					'previous_status' => (string) ( $row['status'] ?? '' ),
					'increment_retry' => (bool) $increment_retry,
				)
			);
			wptsall_update_task_status( $row, $status, $note, $increment_retry, $task_context );
			++$success;
			$updated_ids[] = $id;
		}

		if ( 0 === $success && 0 === $missing ) {
			$failed = count( $ids );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'status'      => $status,
					'updated'     => $success,
					'missing'     => $missing,
					'failed'      => $failed,
					'updated_ids' => $updated_ids,
				),
			)
		);
	}

	/**
	 * Load task row for manual queue operations.
	 *
	 * @param int $task_id Task id.
	 * @return array|\WP_Error
	 */
	private function load_task_row_for_manual_queue( $task_id ) {
		global $wpdb;

		$task_id = absint( $task_id );
		if ( $task_id <= 0 ) {
			return new \WP_Error(
				'invalid_task_id',
				__( 'Invalid Task ID', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$table = wptsall_task_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, retry_count, status_note, payload, meta, updated_at FROM %i WHERE id = %d',
				$table,
				$task_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new \WP_Error(
				'task_not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$row['payload'] = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$row['meta']    = json_decode( (string) ( $row['meta'] ?? '' ), true );
		if ( ! is_array( $row['payload'] ) ) {
			$row['payload'] = array();
		}
		if ( ! is_array( $row['meta'] ) ) {
			$row['meta'] = array();
		}

		return $row;
	}

	/**
	 * Normalize task manual queue items from task meta.
	 *
	 * @param array $meta Task meta.
	 * @return array
	 */
	private function normalize_task_manual_queue_items( $meta ) {
		$queue = is_array( $meta['client_patch_manual_queue'] ?? null )
			? array_values( $meta['client_patch_manual_queue'] )
			: array();
		$items = array();
		foreach ( $queue as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$reason = sanitize_key( (string) ( $item['reason'] ?? 'unknown' ) );
			if ( '' === $reason ) {
				$reason = 'unknown';
			}
			$recorded_at = sanitize_text_field( (string) ( $item['recorded_at'] ?? '' ) );
			$subtasks    = is_array( $item['subtasks'] ?? null ) ? $item['subtasks'] : array();
			$patch_summary = is_array( $item['patch_summary'] ?? null ) ? $item['patch_summary'] : array();
			$unapplied_fragments = array();
			foreach ( (array) ( $item['unapplied_fragments'] ?? array() ) as $fragment ) {
				if ( ! is_array( $fragment ) ) {
					continue;
				}
				$type = $this->normalize_manual_subtask_type( (string) ( $fragment['type'] ?? 'text' ) );
				$key  = $this->normalize_manual_subtask_key( (string) ( $fragment['key'] ?? '' ) );
				$fragment_id = sanitize_text_field( (string) ( $fragment['fragment_id'] ?? '' ) );
				$target_path = sanitize_text_field( (string) ( $fragment['target_path'] ?? '' ) );
				$unapplied_fragments[] = array(
					'fragment_id' => $fragment_id,
					'type'        => $type,
					'key'         => $key,
					'target_path' => $target_path,
					'fragment_key'=> '' !== $key ? $this->build_manual_subtask_fragment_key( $type, $key ) : '',
				);
			}

			$items[] = array(
				'index'              => (int) $idx + 1,
				'reason'             => $reason,
				'recorded_at'        => $recorded_at,
				'subtasks'           => $subtasks,
				'patch_summary'      => $patch_summary,
				'unapplied_fragments'=> $unapplied_fragments,
			);
		}
		return $items;
	}

	/**
	 * Summarize task manual queue status.
	 *
	 * @param array $meta Task meta.
	 * @return array
	 */
	private function summarize_task_manual_queue( $meta ) {
		$items = $this->normalize_task_manual_queue_items( $meta );
		$summary = array(
			'total'                => 0,
			'reasons'              => array(),
			'unapplied_fragments'  => 0,
			'last_recorded_at'     => '',
		);
		foreach ( $items as $item ) {
			$summary['total']++;
			$reason = sanitize_key( (string) ( $item['reason'] ?? 'unknown' ) );
			if ( '' === $reason ) {
				$reason = 'unknown';
			}
			$summary['reasons'][ $reason ] = (int) ( $summary['reasons'][ $reason ] ?? 0 ) + 1;
			$summary['unapplied_fragments'] += count( (array) ( $item['unapplied_fragments'] ?? array() ) );
			$recorded_at = sanitize_text_field( (string) ( $item['recorded_at'] ?? '' ) );
			if ( '' !== $recorded_at && ( '' === $summary['last_recorded_at'] || strtotime( $recorded_at ) > strtotime( $summary['last_recorded_at'] ) ) ) {
				$summary['last_recorded_at'] = $recorded_at;
			}
		}
		arsort( $summary['reasons'] );
		return $summary;
	}

	/**
	 * Collect fragment keys from manual queue items.
	 *
	 * @param array $items Manual queue items.
	 * @param int   $index 1-based index (0 means all).
	 * @return array
	 */
	private function collect_manual_queue_fragment_keys( $items, $index = 0 ) {
		$keys = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_index = absint( $item['index'] ?? 0 );
			if ( $index > 0 && $item_index !== $index ) {
				continue;
			}
			$unapplied_fragments = is_array( $item['unapplied_fragments'] ?? null ) ? $item['unapplied_fragments'] : array();
			foreach ( $unapplied_fragments as $fragment ) {
				if ( ! is_array( $fragment ) ) {
					continue;
				}
				$fragment_key = sanitize_text_field( (string) ( $fragment['fragment_key'] ?? '' ) );
				if ( '' !== $fragment_key ) {
					$keys[] = $fragment_key;
				}
			}
		}
		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Normalize manual subtask type.
	 *
	 * @param string $raw_type Raw type.
	 * @return string
	 */
	private function normalize_manual_subtask_type( $raw_type ) {
		$type = sanitize_key( strtolower( trim( (string) $raw_type ) ) );
		if ( in_array( $type, array( 'text', 'field', 'fields', 'text_translation' ), true ) ) {
			return 'text';
		}
		if ( in_array( $type, array( 'image', 'images', 'image_translation' ), true ) ) {
			return 'image';
		}
		if ( in_array( $type, array( 'video', 'videos', 'video_translation' ), true ) ) {
			return 'video';
		}
		if ( in_array( $type, array( 'audio', 'audios', 'audio_translation' ), true ) ) {
			return 'audio';
		}
		if ( in_array( $type, array( 'document', 'documents', 'doc', 'file', 'files', 'document_translation' ), true ) ) {
			return 'document';
		}
		return 'text';
	}

	/**
	 * Normalize manual subtask key.
	 *
	 * @param string $raw_key Raw key.
	 * @return string
	 */
	private function normalize_manual_subtask_key( $raw_key ) {
		$key = strtolower( trim( (string) $raw_key ) );
		$key = str_replace( array( ' ', '.', '/' ), '_', $key );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
		$key = preg_replace( '/_{2,}/', '_', (string) $key );
		$key = trim( (string) $key, '_' );
		return sanitize_key( (string) $key );
	}

	/**
	 * Build normalized manual subtask fragment key.
	 *
	 * @param string $type Subtask type.
	 * @param string $key  Subtask key.
	 * @return string
	 */
	private function build_manual_subtask_fragment_key( $type, $key ) {
		return $this->normalize_manual_subtask_type( $type ) . ':' . $this->normalize_manual_subtask_key( $key );
	}

	/**
	 * Summarize subtasks list for manual action response.
	 *
	 * @param array $subtasks Subtasks.
	 * @return array
	 */
	private function summarize_manual_subtasks( $subtasks ) {
		$summary = array(
			'total'      => 0,
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'skipped'    => 0,
			'failed'     => 0,
		);

		foreach ( (array) $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			++$summary['total'];
			$status = sanitize_key( (string) ( $subtask['status'] ?? '' ) );
			if ( in_array( $status, array( 'pending', 'queued' ), true ) ) {
				++$summary['pending'];
			} elseif ( in_array( $status, array( 'processing', 'running' ), true ) ) {
				++$summary['processing'];
			} elseif ( in_array( $status, array( 'completed', 'done', 'success' ), true ) ) {
				++$summary['completed'];
			} elseif ( in_array( $status, array( 'skipped', 'noop' ), true ) ) {
				++$summary['skipped'];
			} elseif ( in_array( $status, array( 'failed', 'error' ), true ) ) {
				++$summary['failed'];
			}
		}

		return $summary;
	}

	/**
	 * Collect failed subtask fragment keys from meta.client_result.subtasks.
	 *
	 * @param array $meta Task meta.
	 * @return array
	 */
	private function collect_failed_subtask_fragment_keys_from_meta( $meta ) {
		$keys = array();
		if ( ! is_array( $meta ) ) {
			return $keys;
		}
		$subtasks = is_array( $meta['client_result']['subtasks'] ?? null ) ? $meta['client_result']['subtasks'] : array();
		foreach ( $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			$status = sanitize_key( (string) ( $subtask['status'] ?? '' ) );
			if ( ! in_array( $status, array( 'failed', 'error' ), true ) ) {
				continue;
			}
			$type = $this->normalize_manual_subtask_type( (string) ( $subtask['type'] ?? ( $subtask['task_type'] ?? 'text' ) ) );
			$key  = $this->normalize_manual_subtask_key( (string) ( $subtask['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$keys[] = $this->build_manual_subtask_fragment_key( $type, $key );
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Refresh job aggregate snapshot by job_id.
	 *
	 * @param string $job_id Job id.
	 * @return array
	 */
	private function refresh_job_snapshot_by_job_id( $job_id ) {
		global $wpdb;

		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return array();
		}

		$table    = wptsall_task_table_name();
		$job_like = '%' . $wpdb->esc_like( '"job_id":"' . $job_id . '"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, status, payload, updated_at FROM %i WHERE payload LIKE %s',
				$table,
				$job_like
			),
			ARRAY_A
		);
		if ( ! is_array( $candidates ) ) {
			$candidates = array();
		}

		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'retry'      => 0,
			'failed'     => 0,
			'completed'  => 0,
			'other'      => 0,
		);
		$total       = 0;
		$latest_at   = '';
		$latest_id   = 0;
		$latest_stat = '';
		foreach ( $candidates as $candidate ) {
			$payload = json_decode( (string) ( $candidate['payload'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			if ( sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) ) !== $job_id ) {
				continue;
			}
			$total++;
			$status = sanitize_key( (string) ( $candidate['status'] ?? '' ) );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			} else {
				$counts['other']++;
			}
			$updated_at = sanitize_text_field( (string) ( $candidate['updated_at'] ?? '' ) );
			if ( '' !== $updated_at && ( '' === $latest_at || strtotime( $updated_at ) > strtotime( $latest_at ) ) ) {
				$latest_at   = $updated_at;
				$latest_id   = absint( $candidate['id'] ?? 0 );
				$latest_stat = $status;
			}
		}

		$job_status = 'running';
		if ( $total > 0 ) {
			if ( (int) $counts['completed'] >= (int) $total && 0 === (int) $counts['failed'] && 0 === (int) $counts['retry'] ) {
				$job_status = 'completed';
			} elseif ( (int) $counts['failed'] > 0 && 0 === (int) $counts['completed'] && 0 === (int) $counts['pending'] && 0 === (int) $counts['processing'] && 0 === (int) $counts['retry'] ) {
				$job_status = 'failed';
			} elseif ( (int) $counts['completed'] > 0 || (int) $counts['failed'] > 0 || (int) $counts['retry'] > 0 ) {
				$job_status = 'partial';
			}
		}
		$progress = $total > 0 ? round( ( (int) $counts['completed'] / (int) $total ) * 100, 2 ) : 0;
		$snapshot = array(
			'job_id'         => $job_id,
			'status'         => $job_status,
			'total'          => $total,
			'progress'       => $progress,
			'counts'         => $counts,
			'latest_status'  => $latest_stat,
			'latest_task_id' => $latest_id,
			'latest_at'      => '' !== $latest_at ? $latest_at : current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		update_option( 'wptsall_job_aggregate_' . md5( $job_id ), $snapshot, false );
		if ( function_exists( 'wptsall_upsert_task_job_snapshot' ) ) {
			wptsall_upsert_task_job_snapshot( $snapshot );
		}
		return $snapshot;
	}

	/**
	 * Check whether status is allowed in task management endpoints.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	private function is_allowed_status( $status ) {
		return wptsall_is_valid_task_status( $status );
	}

	/**
	 * Build audit context for manual task operations.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $action  Action key.
	 * @param array            $extra   Extra context payload.
	 * @return array
	 */
	private function build_manual_audit_context( $request, $action, $extra = array() ) {
		$user = wp_get_current_user();
		$context = array(
			'source' => 'admin_rest',
			'action' => sanitize_key( (string) $action ),
			'actor'  => array(
				'user_id'    => get_current_user_id(),
				'user_login' => sanitize_text_field( (string) ( $user->user_login ?? '' ) ),
				'user_email' => sanitize_email( (string) ( $user->user_email ?? '' ) ),
			),
			'request_id' => sanitize_text_field( (string) $request->get_header( 'X-Request-Id' ) ),
			'at'         => current_time( 'mysql' ),
		);

		if ( ! empty( $extra ) ) {
			$context = array_merge( $context, $extra );
		}

		return $context;
	}

	/**
	 * Retry failed/retry tasks in batch.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function retry_failed_tasks( $request ) {
		global $wpdb;

		$table = wptsall_task_table_name();
		$limit = max( 1, min( 500, absint( $request->get_param( 'limit' ) ) ) );
		$note  = sanitize_text_field( (string) ( $request->get_param( 'note' ) ?? '' ) );
		if ( '' === $note ) {
			$note = sprintf(
				/* translators: %s: datetime */
				__( 'Batch retry failed tasks (%s)', 'wpmmcc-ats' ),
				current_time( 'mysql' )
			);
		}

		// M21: explicit column list avoids loading payload LONGTEXT in batch queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, status, retry_count FROM %i WHERE status IN (%s, %s) ORDER BY updated_at ASC LIMIT %d',
				$table,
				'failed',
				'retry',
				$limit
			),
			ARRAY_A
		);

		$updated_ids = array();
		$base_context = $this->build_manual_audit_context(
			$request,
			'retry_failed_tasks',
			array(
				'target_status'   => 'pending',
				'requested_limit' => $limit,
				'note'            => $note,
			)
		);
		foreach ( (array) $rows as $row ) {
			$id = absint( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$task_context = array_merge(
				$base_context,
				array(
					'task_id'         => $id,
					'previous_status' => (string) ( $row['status'] ?? '' ),
					'increment_retry' => true,
				)
			);
			wptsall_update_task_status( $row, 'pending', $note, true, $task_context );
			$updated_ids[] = $id;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'updated'     => count( $updated_ids ),
					'updated_ids' => $updated_ids,
				),
			)
		);
	}

	/**
	 * Get job aggregate list.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_task_jobs( $request ) {
		global $wpdb;

		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$job_id   = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( function_exists( 'wptsall_task_jobs_table_exists' ) && ! wptsall_task_jobs_table_exists() ) {
			$fallback_items = array();
			if ( '' !== $job_id && function_exists( 'wptsall_get_task_job_snapshot' ) ) {
				$snapshot = wptsall_get_task_job_snapshot( $job_id );
				if ( is_array( $snapshot ) && ! empty( $snapshot ) ) {
					$fallback_items[] = $snapshot;
				}
			}
			return rest_ensure_response(
				array(
					'items' => $fallback_items,
					'total' => count( $fallback_items ),
					'pages' => 1,
					'page'  => 1,
				)
			);
		}

		$table = wptsall_table( 'task_jobs' );

		$where_parts = array( '1=1' );
		$where_args  = array();
		if ( '' !== $status ) {
			$where_parts[] = 'status = %s';
			$where_args[]  = $status;
		}
		if ( '' !== $job_id ) {
			$where_parts[] = 'job_id = %s';
			$where_args[]  = $job_id;
		}
		$where_sql = implode( ' AND ', $where_parts );

		$total = (int) wptsall_db_get_var(
			'SELECT COUNT(*) FROM %i WHERE ' . $where_sql,
			array_merge( array( $table ), $where_args )
		);

		$query_args = array_merge( array( $table ), $where_args, array( $per_page, $offset ) );
		$rows       = wptsall_db_get_results(
			'SELECT job_id, status, progress, task_total, pending_count, processing_count, retry_count, failed_count, completed_count, other_count, latest_status, latest_task_id, latest_at, updated_at
				 FROM %i
				 WHERE ' . $where_sql . '
				 ORDER BY updated_at DESC
				 LIMIT %d OFFSET %d',
			$query_args,
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'job_id'         => sanitize_text_field( (string) ( $row['job_id'] ?? '' ) ),
				'status'         => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'progress'       => (float) ( $row['progress'] ?? 0 ),
				'total'          => (int) ( $row['task_total'] ?? 0 ),
				'counts'         => array(
					'pending'    => (int) ( $row['pending_count'] ?? 0 ),
					'processing' => (int) ( $row['processing_count'] ?? 0 ),
					'retry'      => (int) ( $row['retry_count'] ?? 0 ),
					'failed'     => (int) ( $row['failed_count'] ?? 0 ),
					'completed'  => (int) ( $row['completed_count'] ?? 0 ),
					'other'      => (int) ( $row['other_count'] ?? 0 ),
				),
				'latest_status'  => sanitize_key( (string) ( $row['latest_status'] ?? '' ) ),
				'latest_task_id' => (int) ( $row['latest_task_id'] ?? 0 ),
				'latest_at'      => sanitize_text_field( (string) ( $row['latest_at'] ?? '' ) ),
				'updated_at'     => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
			);
		}

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => $total,
				'pages' => (int) ceil( max( 1, $total ) / $per_page ),
				'page'  => $page,
			)
		);
	}

	/**
	 * Get tasks list scoped by job_id.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_tasks_by_job( $request ) {
		global $wpdb;

		$table                = wptsall_task_table_name();
		$job_id               = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$status               = sanitize_key( (string) $request->get_param( 'status' ) );
		$raw_business_line    = sanitize_key( (string) $request->get_param( 'business_line' ) );
		$raw_task_type        = sanitize_key( (string) $request->get_param( 'task_type' ) );
		$page                 = max( 1, (int) $request->get_param( 'page' ) );
		$per_page             = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$include_payload      = $this->normalize_request_bool( $request->get_param( 'include_payload' ), false );
		$business_line_filter = $this->normalize_job_task_business_line( $raw_business_line );
		$task_type_filter     = $this->normalize_job_task_type( $raw_task_type );
		$max_scan             = 20000;

		if ( '' === $job_id ) {
			return new \WP_Error(
				'invalid_job_id',
				__( 'job_id cannot be empty', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		if ( '' !== $raw_business_line && '' === $business_line_filter ) {
			return new \WP_Error(
				'invalid_business_line',
				__( 'Invalid business_line', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		if ( '' !== $raw_task_type && '' === $task_type_filter ) {
			return new \WP_Error(
				'invalid_task_type',
				__( 'Invalid task_type', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$where_parts = array( 'payload LIKE %s' );
		$where_args  = array( '%' . $wpdb->esc_like( '"job_id":"' . $job_id . '"' ) . '%' );
		if ( '' !== $status ) {
			$where_parts[] = 'status = %s';
			$where_args[]  = $status;
		}
		if ( '' !== $business_line_filter ) {
			$where_parts[] = 'payload LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( '"business_line":"' . $business_line_filter . '"' ) . '%';
		}
		if ( '' !== $task_type_filter ) {
			$where_parts[] = 'payload LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( '"task_type":"' . $task_type_filter . '"' ) . '%';
		}
		$where_sql  = implode( ' AND ', $where_parts );
		$query_args = array_merge( array( $table ), $where_args, array( $max_scan ) );

		$candidates = wptsall_db_get_results(
			'SELECT id, status, retry_count, status_note, site_id, template, object_type, subtype, object_id, created_at, updated_at, payload, meta
				 FROM %i
				 WHERE ' . $where_sql . '
				 ORDER BY updated_at DESC
				 LIMIT %d',
			$query_args,
			ARRAY_A
		);

		$items = array();
		$summary = array(
			'status'         => array(),
			'business_lines' => array(),
			'task_types'     => array(),
			'subtasks'       => array(
				'total'      => 0,
				'completed'  => 0,
				'failed'     => 0,
				'skipped'    => 0,
				'pending'    => 0,
				'processing' => 0,
			),
			'manual_queue'   => array(
				'tasks' => 0,
				'items' => 0,
			),
		);
		foreach ( (array) $candidates as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$meta    = json_decode( (string) ( $row['meta'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}

			$row_job_id = sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) );
			if ( $row_job_id !== $job_id ) {
				continue;
			}

			$row_business_line = $this->normalize_job_task_business_line( (string) ( $payload['business_line'] ?? '' ) );
			if ( '' === $row_business_line && function_exists( 'wptsall_infer_business_line_from_task' ) ) {
				$infer_task = array_merge(
					array(
						'object_type' => sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
						'subtype'     => sanitize_key( (string) ( $row['subtype'] ?? '' ) ),
					),
					$payload
				);
				$row_business_line = sanitize_key( (string) wptsall_infer_business_line_from_task( $infer_task ) );
			}
			if ( '' === $row_business_line ) {
				$row_business_line = 'custom_model';
			}
			if ( '' !== $business_line_filter && $row_business_line !== $business_line_filter ) {
				continue;
			}

			$row_task_type = $this->normalize_job_task_type( (string) ( $payload['task_type'] ?? ( $payload['type'] ?? '' ) ) );
			if ( '' === $row_task_type ) {
				$row_task_type = 'text';
			}
			if ( '' !== $task_type_filter && $row_task_type !== $task_type_filter ) {
				continue;
			}

			$task_status      = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$subtask_summary  = $this->summarize_job_task_subtasks( $payload, $meta, $task_status );
			$item             = array(
				'id'             => (int) ( $row['id'] ?? 0 ),
				'job_id'         => $job_id,
				'status'         => $task_status,
				'retry_count'    => (int) ( $row['retry_count'] ?? 0 ),
				'status_note'    => sanitize_text_field( (string) ( $row['status_note'] ?? '' ) ),
				'business_line'  => $row_business_line,
				'task_type'      => $row_task_type,
				'site_id'        => (int) ( $row['site_id'] ?? 0 ),
				'template'       => sanitize_key( (string) ( $row['template'] ?? '' ) ),
				'object_type'    => sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
				'subtype'        => sanitize_key( (string) ( $row['subtype'] ?? '' ) ),
				'object_id'      => (int) ( $row['object_id'] ?? 0 ),
				'subtasks'       => $subtask_summary,
				'object_ref'     => is_array( $payload['object_ref'] ?? null ) ? $payload['object_ref'] : array(),
				'created_at'     => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				'updated_at'     => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
			);
			$manual_queue_summary = $this->summarize_task_manual_queue( $meta );
			$item['manual_queue'] = $manual_queue_summary;
			$summary['status'][ $task_status ] = (int) ( $summary['status'][ $task_status ] ?? 0 ) + 1;
			$summary['business_lines'][ $row_business_line ] = (int) ( $summary['business_lines'][ $row_business_line ] ?? 0 ) + 1;
			$summary['task_types'][ $row_task_type ] = (int) ( $summary['task_types'][ $row_task_type ] ?? 0 ) + 1;
			foreach ( array( 'total', 'completed', 'failed', 'skipped', 'pending', 'processing' ) as $bucket ) {
				$summary['subtasks'][ $bucket ] += (int) ( $subtask_summary[ $bucket ] ?? 0 );
			}
			if ( (int) ( $manual_queue_summary['total'] ?? 0 ) > 0 ) {
				$summary['manual_queue']['tasks']++;
				$summary['manual_queue']['items'] += (int) ( $manual_queue_summary['total'] ?? 0 );
			}

			$last_error = is_array( $meta['client_result_last_error'] ?? null ) ? $meta['client_result_last_error'] : array();
			if ( ! empty( $last_error ) ) {
				$item['last_error'] = array(
					'category' => sanitize_key( (string) ( $last_error['category'] ?? '' ) ),
					'code'     => sanitize_text_field( (string) ( $last_error['code'] ?? '' ) ),
					'message'  => sanitize_text_field( (string) ( $last_error['message'] ?? '' ) ),
				);
			}

			if ( $include_payload ) {
				$item['payload'] = $payload;
			}

			$items[] = $item;
		}

		$total  = count( $items );
		$pages  = (int) ceil( max( 1, $total ) / $per_page );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $items, $offset, $per_page );
		arsort( $summary['status'] );
		arsort( $summary['business_lines'] );
		arsort( $summary['task_types'] );

		return rest_ensure_response(
			array(
				'job_id'    => $job_id,
				'filters'   => array(
					'status'        => $status,
					'business_line' => $business_line_filter,
					'task_type'     => $task_type_filter,
				),
				'items'     => array_values( $items ),
				'total'     => $total,
				'pages'     => $pages,
				'page'      => $page,
				'per_page'  => $per_page,
				'summary'   => $summary,
				'truncated' => is_array( $candidates ) && count( $candidates ) >= $max_scan,
			)
		);
	}

	/**
	 * Create a relation-scoped task job bundle.
	 *
	 * Generates task rows sharing the same job_id so client can process
	 * a multi-business-line batch in one execution window.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_task_job( $request ) {
		$relation_id            = absint( $request->get_param( 'relation_id' ) );
		$include_content        = $this->normalize_request_bool( $request->get_param( 'include_content' ), true );
		$include_language_pack  = $this->normalize_request_bool( $request->get_param( 'include_language_pack' ), false );
		$preview                = $this->normalize_request_bool( $request->get_param( 'preview' ), false );
		$allow_existing_job     = $this->normalize_request_bool( $request->get_param( 'allow_existing_job' ), false );
		$limit                  = max( 1, min( 5000, absint( $request->get_param( 'limit' ) ) ) );
		$batch_size             = max( 1, min( 500, absint( $request->get_param( 'batch_size' ) ) ) );

		if ( $relation_id <= 0 ) {
			return new \WP_Error(
				'invalid_relation_id',
				__( 'Invalid relation_id', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $include_content && ! $include_language_pack ) {
			return new \WP_Error(
				'invalid_scope',
				__( 'At least one task generation scope must be enabled (content or language_pack)', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Site relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		if ( '' === $job_id ) {
			$job_id = function_exists( 'wptsall_generate_task_job_id' )
				? wptsall_generate_task_job_id( 'relation_' . $relation_id )
				: sanitize_key( 'job_relation_' . $relation_id . '_' . time() );
		}
		$existing_task_ids = $this->find_task_ids_by_job_id( $job_id, 50 );
		if ( ! $preview && ! $allow_existing_job && ! empty( $existing_task_ids ) ) {
			return new \WP_Error(
				'job_id_conflict',
				__( 'Tasks already exist for this job_id. Please use a different job_id or explicitly allow reuse.', 'wpmmcc-ats' ),
				array(
					'status'           => 409,
					'job_id'           => $job_id,
					'existing_count'   => count( $existing_task_ids ),
					'existing_samples' => implode( ',', array_slice( $existing_task_ids, 0, 20 ) ),
				)
			);
		}

			$target_lang = sanitize_text_field( (string) $request->get_param( 'target_lang' ) );
			if ( '' === $target_lang ) {
				$target_lang = sanitize_text_field( (string) ( $relation['target_lang'] ?? '' ) );
			}
			$source_lang = sanitize_text_field( (string) ( $relation['source_lang'] ?? get_locale() ) );
			$business_lines = $request->get_param( 'business_lines' );
			if ( is_string( $business_lines ) ) {
				$business_lines = preg_split( '/[\s,;|]+/', $business_lines );
			}
			if ( ! is_array( $business_lines ) ) {
				$business_lines = array();
			}
			$business_line_limits = $request->get_param( 'business_line_limits' );
			if ( is_object( $business_line_limits ) ) {
				$business_line_limits = get_object_vars( $business_line_limits );
			}
			if ( ! is_array( $business_line_limits ) ) {
				$business_line_limits = array();
			}
			$line_batch_strategy = sanitize_key( (string) $request->get_param( 'line_batch_strategy' ) );

			$plan_result = Task_Job_Planner::plan_relation_job(
				$relation_id,
				array(
					'relation'              => $relation,
					'job_id'                => $job_id,
					'include_content'       => $include_content,
					'include_language_pack' => $include_language_pack,
					'limit'                 => $limit,
					'batch_size'            => $batch_size,
					'target_lang'           => $target_lang,
					'source_lang'           => $source_lang,
					'business_lines'        => $business_lines,
					'business_line_limits'  => $business_line_limits,
					'line_batch_strategy'   => $line_batch_strategy,
				)
			);
			if ( is_wp_error( $plan_result ) ) {
				return $plan_result;
			}

			$job_id         = sanitize_text_field( (string) ( $plan_result['job_id'] ?? $job_id ) );
			$all_tasks      = is_array( $plan_result['tasks'] ?? null ) ? $plan_result['tasks'] : array();
			$warnings       = is_array( $plan_result['warnings'] ?? null ) ? $plan_result['warnings'] : array();
			$content_tasks  = (int) ( $plan_result['content_tasks'] ?? 0 );
			$language_tasks = (int) ( $plan_result['language_pack_tasks'] ?? 0 );
			$summary        = is_array( $plan_result['summary'] ?? null )
				? $plan_result['summary']
				: $this->summarize_generated_tasks_for_job( $all_tasks );
			$planning       = is_array( $plan_result['planning'] ?? null ) ? $plan_result['planning'] : array();

			if ( ! $preview && ! empty( $all_tasks ) ) {
				wptsall_insert_tasks( $all_tasks );
			}
			$inserted_task_ids = array();
			if ( ! $preview && ! empty( $all_tasks ) ) {
			$inserted_task_ids = $this->find_task_ids_by_job_id( $job_id, 50 );
		}
		$job_snapshot = array();
		if ( ! $preview && ! empty( $all_tasks ) ) {
			$job_snapshot = $this->refresh_job_snapshot_by_job_id( $job_id );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'relation_id'            => $relation_id,
					'job_id'                 => $job_id,
					'preview'                => $preview,
					'include_content'        => $include_content,
					'include_language_pack'  => $include_language_pack,
					'content_tasks'          => $content_tasks,
					'language_pack_tasks'    => $language_tasks,
					'total_tasks'            => count( $all_tasks ),
					'summary'                => $summary,
					'job'                    => $job_snapshot,
					'allow_existing_job'     => $allow_existing_job,
						'existing_before'        => count( $existing_task_ids ),
						'inserted_count'         => count( $inserted_task_ids ),
						'task_samples'           => implode( ',', array_slice( $inserted_task_ids, 0, 20 ) ),
						'warnings'               => $warnings,
						'planning'               => $planning,
					),
				)
			);
		}

	/**
	 * Find task ids by job_id in payload.
	 *
	 * @param string $job_id Job id.
	 * @param int    $limit  Max ids to return.
	 * @return array
	 */
	private function find_task_ids_by_job_id( $job_id, $limit = 50 ) {
		global $wpdb;

		$job_id = sanitize_text_field( (string) $job_id );
		$limit  = max( 1, min( 200, (int) $limit ) );
		if ( '' === $job_id ) {
			return array();
		}

		$table    = wptsall_task_table_name();
		$job_like = '%' . $wpdb->esc_like( '"job_id":"' . $job_id . '"' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, payload FROM %i WHERE payload LIKE %s ORDER BY id DESC LIMIT %d',
				$table,
				$job_like,
				$limit
			),
			ARRAY_A
		);

		$ids = array();
		foreach ( (array) $candidates as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$row_job_id = sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) );
			if ( $row_job_id !== $job_id ) {
				continue;
			}
			$ids[] = (int) ( $row['id'] ?? 0 );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Normalize request boolean param.
	 *
	 * @param mixed $value   Raw value.
	 * @param bool  $default Default value when input is null/empty.
	 * @return bool
	 */
	private function normalize_request_bool( $value, $default = false ) {
		if ( null === $value || '' === $value ) {
			return (bool) $default;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}
		$normalized = strtolower( trim( (string) $value ) );
		return in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Normalize business line for job-task filtering.
	 *
	 * @param string $raw_value Raw value.
	 * @return string
	 */
	private function normalize_job_task_business_line( $raw_value ) {
		$value = sanitize_key( strtolower( trim( (string) $raw_value ) ) );
		if ( '' === $value ) {
			return '';
		}
		if ( in_array( $value, array( 'post', 'post_type', 'post_content' ), true ) ) {
			return 'post_content';
		}
		if ( in_array( $value, array( 'taxonomy', 'term', 'taxonomy_content' ), true ) ) {
			return 'taxonomy_content';
		}
		if ( in_array( $value, array( 'theme', 'theme_i18n' ), true ) ) {
			return 'theme_i18n';
		}
		if ( in_array( $value, array( 'plugin', 'plugin_i18n', 'language_pack', 'language_pack_i18n' ), true ) ) {
			return 'plugin_i18n';
		}
		if ( in_array( $value, array( 'custom_model', 'custom', 'model' ), true ) ) {
			return 'custom_model';
		}
		return '';
	}

	/**
	 * Normalize task type for job-task filtering.
	 *
	 * @param string $raw_type Raw type.
	 * @return string
	 */
	private function normalize_job_task_type( $raw_type ) {
		$type = sanitize_key( strtolower( trim( (string) $raw_type ) ) );
		if ( '' === $type ) {
			return '';
		}
		if ( in_array( $type, array( 'text', 'image', 'video', 'audio', 'document', 'mixed' ), true ) ) {
			return $type;
		}
		if ( in_array( $type, array( 'text_translation', 'field', 'fields' ), true ) ) {
			return 'text';
		}
		if ( in_array( $type, array( 'image_translation', 'images' ), true ) ) {
			return 'image';
		}
		if ( in_array( $type, array( 'video_translation', 'videos' ), true ) ) {
			return 'video';
		}
		if ( in_array( $type, array( 'audio_translation', 'audios' ), true ) ) {
			return 'audio';
		}
		if ( in_array( $type, array( 'document_translation', 'documents', 'doc', 'file', 'files' ), true ) ) {
			return 'document';
		}
		return '';
	}

	/**
	 * Build subtask summary for job task listing.
	 *
	 * @param array  $payload    Task payload.
	 * @param array  $meta       Task meta.
	 * @param string $task_status Task status.
	 * @return array
	 */
	private function summarize_job_task_subtasks( $payload, $meta, $task_status ) {
		$summary = array(
			'total'      => 0,
			'completed'  => 0,
			'failed'     => 0,
			'skipped'    => 0,
			'pending'    => 0,
			'processing' => 0,
			'types'      => array(),
		);

		$client_result = is_array( $meta['client_result'] ?? null ) ? $meta['client_result'] : array();
		$result_items  = is_array( $client_result['subtasks'] ?? null ) ? $client_result['subtasks'] : array();
		if ( ! empty( $result_items ) ) {
			foreach ( $result_items as $subtask ) {
				if ( ! is_array( $subtask ) ) {
					continue;
				}
				$type = $this->normalize_job_task_type( (string) ( $subtask['type'] ?? ( $subtask['task_type'] ?? 'text' ) ) );
				if ( '' === $type ) {
					$type = 'text';
				}
				$status = sanitize_key( (string) ( $subtask['status'] ?? '' ) );
				if ( in_array( $status, array( 'success', 'done' ), true ) ) {
					$status = 'completed';
				} elseif ( 'error' === $status ) {
					$status = 'failed';
				} elseif ( 'noop' === $status ) {
					$status = 'skipped';
				}
				if ( ! in_array( $status, array( 'completed', 'failed', 'skipped', 'pending', 'processing' ), true ) ) {
					$status = 'pending';
				}
				$summary['total']++;
				$summary['types'][ $type ] = (int) ( $summary['types'][ $type ] ?? 0 ) + 1;
				$summary[ $status ]++;
			}
			arsort( $summary['types'] );
			return $summary;
		}

		$payload_items = $this->collect_job_task_payload_subtasks( $payload );
		foreach ( $payload_items as $subtask ) {
			$type = $this->normalize_job_task_type( (string) ( $subtask['type'] ?? 'text' ) );
			if ( '' === $type ) {
				$type = 'text';
			}
			$summary['total']++;
			$summary['types'][ $type ] = (int) ( $summary['types'][ $type ] ?? 0 ) + 1;
		}

		$default_bucket = 'pending';
		if ( 'processing' === $task_status || 'active' === $task_status ) {
			$default_bucket = 'processing';
		} elseif ( 'completed' === $task_status ) {
			$default_bucket = 'completed';
		} elseif ( in_array( $task_status, array( 'failed', 'error' ), true ) ) {
			$default_bucket = 'failed';
		}
		if ( $summary['total'] > 0 ) {
			$summary[ $default_bucket ] = $summary['total'];
		}
		arsort( $summary['types'] );
		return $summary;
	}

	/**
	 * Collect payload subtasks for job summary.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private function collect_job_task_payload_subtasks( $payload ) {
		$subtasks = array();
		if ( ! is_array( $payload ) ) {
			return $subtasks;
		}

		$seen = array();
		$append_item = function( $item, $fallback_type, $prefix, $index ) use ( &$subtasks, &$seen ) {
			$item_array = is_array( $item ) ? $item : array( 'value' => $item );
			$type       = $this->normalize_job_task_type( (string) ( $item_array['type'] ?? $fallback_type ) );
			if ( '' === $type ) {
				$type = 'text';
			}
			$raw_key = sanitize_key( (string) ( $item_array['key'] ?? ( $item_array['id'] ?? '' ) ) );
			$key     = '' !== $raw_key ? $raw_key : sanitize_key( $prefix . '_' . ( (int) $index + 1 ) );
			$dedupe  = $type . '|' . $key;
			if ( isset( $seen[ $dedupe ] ) ) {
				return;
			}
			$seen[ $dedupe ] = true;
			$subtasks[]      = array(
				'type' => $type,
				'key'  => $key,
			);
		};

		foreach ( array( 'subtasks', 'content_items' ) as $list_key ) {
			$items = is_array( $payload[ $list_key ] ?? null ) ? $payload[ $list_key ] : array();
			foreach ( array_values( $items ) as $index => $item ) {
				$append_item( $item, 'text', 'subtask', $index );
			}
		}
		$typed_lists = array(
			'image'    => array( 'images', 'image_items' ),
			'video'    => array( 'videos', 'video_items' ),
			'audio'    => array( 'audios', 'audio_items' ),
			'document' => array( 'documents', 'document_items' ),
		);
		foreach ( $typed_lists as $type => $list_keys ) {
			foreach ( $list_keys as $list_key ) {
				$items = is_array( $payload[ $list_key ] ?? null ) ? $payload[ $list_key ] : array();
				foreach ( array_values( $items ) as $index => $item ) {
					$append_item( $item, $type, $type, $index );
				}
			}
		}

		if ( empty( $subtasks ) ) {
			$fields = is_array( $payload['fields'] ?? null ) ? $payload['fields'] : array();
			$idx    = 0;
			foreach ( $fields as $field_key => $_value ) {
				$key = is_string( $field_key ) && '' !== trim( $field_key )
					? sanitize_key( $field_key )
					: sanitize_key( 'field_' . ( $idx + 1 ) );
				if ( '' === $key ) {
					continue;
				}
				$dedupe = 'text|' . $key;
				if ( isset( $seen[ $dedupe ] ) ) {
					continue;
				}
				$seen[ $dedupe ] = true;
				$subtasks[]      = array(
					'type' => 'text',
					'key'  => $key,
				);
				$idx++;
			}
		}

		return $subtasks;
	}

	/**
	 * Summarize generated tasks by business line and task type.
	 *
	 * @param array $tasks Tasks list.
	 * @return array
	 */
	private function summarize_generated_tasks_for_job( $tasks ) {
		$summary = array(
			'business_lines' => array(),
			'task_types'     => array(),
			'object_types'   => array(),
		);

		foreach ( (array) $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$business_line = sanitize_key( (string) ( $task['business_line'] ?? '' ) );
			if ( '' === $business_line && function_exists( 'wptsall_infer_business_line_from_task' ) ) {
				$business_line = sanitize_key( (string) wptsall_infer_business_line_from_task( $task ) );
			}
			if ( '' === $business_line ) {
				$business_line = 'custom_model';
			}

			$task_type = sanitize_key( (string) ( $task['task_type'] ?? '' ) );
			if ( '' === $task_type ) {
				$task_type = 'text';
			}

			$object_type = sanitize_key( (string) ( $task['object_type'] ?? '' ) );
			if ( '' === $object_type ) {
				$object_type = 'unknown';
			}

			$summary['business_lines'][ $business_line ] = (int) ( $summary['business_lines'][ $business_line ] ?? 0 ) + 1;
			$summary['task_types'][ $task_type ]         = (int) ( $summary['task_types'][ $task_type ] ?? 0 ) + 1;
			$summary['object_types'][ $object_type ]     = (int) ( $summary['object_types'][ $object_type ] ?? 0 ) + 1;
		}

		arsort( $summary['business_lines'] );
		arsort( $summary['task_types'] );
		arsort( $summary['object_types'] );
		return $summary;
	}

	/**
	 * Retry failed subtasks by job id.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry_failed_subtasks_by_job( $request ) {
		global $wpdb;

		$table         = wptsall_task_table_name();
		$job_id        = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$business_line = sanitize_key( (string) $request->get_param( 'business_line' ) );
		$raw_task_type = sanitize_key( (string) $request->get_param( 'task_type' ) );
		$task_type     = $this->normalize_job_task_type( $raw_task_type );
		$limit         = min( 1000, max( 1, (int) $request->get_param( 'limit' ) ) );
		$note          = sanitize_text_field( (string) ( $request->get_param( 'note' ) ?? '' ) );

		if ( '' === $job_id ) {
			return new \WP_Error(
				'invalid_job_id',
				__( 'job_id cannot be empty', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		if ( '' !== $raw_task_type && '' === $task_type ) {
			return new \WP_Error(
				'invalid_task_type',
				__( 'Invalid task_type', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$where_parts = array( 'payload LIKE %s' );
		$where_args  = array( '%' . $wpdb->esc_like( '"job_id":"' . $job_id . '"' ) . '%' );
		if ( '' !== $business_line ) {
			$where_parts[] = 'payload LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( '"business_line":"' . $business_line . '"' ) . '%';
		}
		if ( '' !== $task_type ) {
			$where_parts[] = 'payload LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( '"task_type":"' . $task_type . '"' ) . '%';
		}
		$where_sql  = implode( ' AND ', $where_parts );
		$query_args = array_merge( array( $table ), $where_args, array( $limit ) );

		$rows = wptsall_db_get_results(
			'SELECT id, status, retry_count, payload, meta
				 FROM %i
				 WHERE ' . $where_sql . '
				 ORDER BY updated_at ASC
				 LIMIT %d',
			$query_args,
			ARRAY_A
		);

		$now             = current_time( 'mysql' );
		$updated_tasks   = 0;
		$updated_subtasks = 0;
		$updated_task_ids = array();
		foreach ( (array) $rows as $row ) {
			$task_id = absint( $row['id'] ?? 0 );
			if ( $task_id <= 0 ) {
				continue;
			}

			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$meta    = json_decode( (string) ( $row['meta'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}

			$payload_job_id = sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) );
			if ( $payload_job_id !== $job_id ) {
				continue;
			}
			if ( '' !== $business_line ) {
				$row_business_line = sanitize_key( (string) ( $payload['business_line'] ?? '' ) );
				if ( '' !== $row_business_line && $row_business_line !== $business_line ) {
					continue;
				}
			}
			if ( '' !== $task_type ) {
				$row_task_type = $this->normalize_job_task_type( (string) ( $payload['task_type'] ?? ( $payload['type'] ?? '' ) ) );
				if ( '' === $row_task_type ) {
					$row_task_type = 'text';
				}
				if ( $row_task_type !== $task_type ) {
					continue;
				}
			}

			$failed_keys = $this->collect_failed_subtask_fragment_keys_from_meta( $meta );
			if ( empty( $failed_keys ) ) {
				continue;
			}

				$existing_retry_filter = is_array( $payload['manual_retry_only_subtasks'] ?? null )
					? $payload['manual_retry_only_subtasks']
					: array();
				$existing_retry_filter = array_values(
					array_filter(
						array_map(
							static function ( $value ) {
								return sanitize_text_field( (string) $value );
							},
							$existing_retry_filter
						),
						static function ( $value ) {
							return '' !== trim( (string) $value );
						}
					)
				);
				$next_retry_filter = array_values(
					array_unique( array_merge( $existing_retry_filter, $failed_keys ) )
				);
				if ( count( $next_retry_filter ) > 200 ) {
					$next_retry_filter = array_slice( $next_retry_filter, -200 );
				}
				$payload['manual_retry_only_subtasks'] = $next_retry_filter;

			$manual_context = $this->build_manual_audit_context(
				$request,
				'retry_failed_subtasks_by_job',
				array(
					'job_id'       => $job_id,
					'business_line'=> $business_line,
					'task_type'    => $task_type,
					'task_id'      => $task_id,
					'subtask_cnt'  => count( $failed_keys ),
				)
			);
			$meta['manual_last_action'] = $manual_context;
			if ( ! is_array( $meta['manual_subtask_actions'] ?? null ) ) {
				$meta['manual_subtask_actions'] = array();
			}
			$meta['manual_subtask_actions'][] = array(
				'action'     => 'retry_failed_subtasks_job',
				'type'       => 'batch',
				'key'        => implode( ',', array_slice( $failed_keys, 0, 20 ) ),
				'note'       => $note,
				'at'         => $now,
				'user_id'    => get_current_user_id(),
				'user_login' => sanitize_text_field( (string) ( wp_get_current_user()->user_login ?? '' ) ),
			);
			if ( count( $meta['manual_subtask_actions'] ) > 30 ) {
				$meta['manual_subtask_actions'] = array_slice( $meta['manual_subtask_actions'], -30 );
			}

			$status_note = '' !== $note
				? $note
				: sprintf(
					/* translators: 1: job id, 2: subtask count, 3: datetime */
					__( 'Job[%1$s] batch retry failed subtasks (%2$d items, %3$s)', 'wpmmcc-ats' ),
					$job_id,
					count( $failed_keys ),
					$now
				);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$table,
				array(
					'status'      => 'retry',
					'status_note' => $status_note,
					'retry_count' => intval( $row['retry_count'] ?? 0 ) + 1,
					'payload'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'meta'        => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'updated_at'  => $now,
				),
				array( 'id' => $task_id ),
				array( '%s', '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				continue;
			}

			$updated_tasks++;
			$updated_subtasks += count( $failed_keys );
			$updated_task_ids[] = $task_id;
		}

		if ( $updated_tasks > 0 && function_exists( 'wptsall_cache_invalidate_tasks' ) ) {
			wptsall_cache_invalidate_tasks();
		}

		$job_snapshot = $this->refresh_job_snapshot_by_job_id( $job_id );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'job_id'          => $job_id,
					'business_line'   => $business_line,
					'task_type'       => $task_type,
					'updated_tasks'   => $updated_tasks,
					'updated_subtasks'=> $updated_subtasks,
					'updated_task_ids'=> $updated_task_ids,
					'scope'           => $this->build_job_retry_scope_label( $job_id, $business_line, $task_type ),
					'task_samples'    => implode( ',', array_slice( $updated_task_ids, 0, 20 ) ),
					'job'             => $job_snapshot,
				),
			)
		);
	}

	/**
	 * Build scope label for job failed-subtasks retry response.
	 *
	 * @param string $job_id        Job id.
	 * @param string $business_line Business line.
	 * @param string $task_type     Task type.
	 * @return string
	 */
	private function build_job_retry_scope_label( $job_id, $business_line, $task_type ) {
		$parts = array( 'job_id=' . sanitize_text_field( (string) $job_id ) );
		if ( '' !== (string) $business_line ) {
			$parts[] = 'business_line=' . sanitize_key( (string) $business_line );
		}
		if ( '' !== (string) $task_type ) {
			$parts[] = 'task_type=' . sanitize_key( (string) $task_type );
		}
		return implode( '; ', $parts );
	}

	/**
	 * Get task statistics
	 *
	 * Migrated from rest.php v1 endpoint.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_task_stats( $request ) {
		$stats = wptsall_get_task_statistics();

		return rest_ensure_response( $stats );
	}

	/**
	 * Create a language pack translation task
	 *
	 * Migrated from rest.php v1 endpoint.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_language_task( $request ) {
		$textdomain     = $request->get_param( 'textdomain' );
		$component_type = $request->get_param( 'component_type' );
		$target_lang    = $request->get_param( 'target_lang' );
		$site_id        = $request->get_param( 'site_id' ) ?? '';
		$preview        = $this->normalize_request_bool( $request->get_param( 'preview' ), false );
		$job_id         = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$line_batch_strategy = sanitize_key( (string) $request->get_param( 'line_batch_strategy' ) );

		$business_lines = $request->get_param( 'business_lines' );
		if ( is_string( $business_lines ) ) {
			$business_lines = preg_split( '/[\s,;|]+/', $business_lines );
		}
		if ( ! is_array( $business_lines ) ) {
			$business_lines = array();
		}
		$business_line_limits = $request->get_param( 'business_line_limits' );
		if ( is_object( $business_line_limits ) ) {
			$business_line_limits = get_object_vars( $business_line_limits );
		}
		if ( ! is_array( $business_line_limits ) ) {
			$business_line_limits = array();
		}

		$relation_id = absint( $site_id );
		$template    = null;

		if ( $relation_id ) {
			$templates = Template_Service::get_by_relation( $relation_id );
			foreach ( $templates as $candidate ) {
				if ( $candidate['text_domain'] === $textdomain && $candidate['source_type'] === $component_type ) {
					$template = $candidate;
					break;
				}
			}

			if ( ! $template && class_exists( Language_Pack_Scanner::class ) ) {
				Language_Pack_Scanner::scan_relation( $relation_id );
				$templates = Template_Service::get_by_relation( $relation_id );
				foreach ( $templates as $candidate ) {
					if ( $candidate['text_domain'] === $textdomain && $candidate['source_type'] === $component_type ) {
						$template = $candidate;
						break;
					}
				}
			}
		} else {
			$candidate = Template_Service::get_by_slug( $textdomain, true, false );
			if ( $candidate && $candidate['source_type'] === $component_type ) {
				$template = $candidate;
			}
		}

		if ( ! $template || empty( $template['id'] ) ) {
			return new \WP_Error(
				'template_not_found',
				__( 'Language pack template not found. Please scan language pack first.', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$plan_result = Task_Job_Planner::plan_language_pack_template_job(
			(int) $template['id'],
			array(
				'job_id'               => $job_id,
				'source_lang'          => get_locale(),
				'target_lang'          => $target_lang,
				'business_lines'       => $business_lines,
				'business_line_limits' => $business_line_limits,
				'line_batch_strategy'  => $line_batch_strategy,
			)
		);
		if ( is_wp_error( $plan_result ) ) {
			return new \WP_Error(
				$plan_result->get_error_code(),
				$plan_result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$tasks = is_array( $plan_result['tasks'] ?? null ) ? $plan_result['tasks'] : array();
		if ( empty( $tasks ) ) {
			return new \WP_Error(
				'no_tasks',
				__( 'No language pack tasks generated', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $preview ) {
			wptsall_insert_tasks( $tasks );
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'task_count' => count( $tasks ),
				'preview'    => $preview,
				'job_id'     => sanitize_text_field( (string) ( $plan_result['job_id'] ?? '' ) ),
				'summary'    => is_array( $plan_result['summary'] ?? null ) ? $plan_result['summary'] : array(),
				'planning'   => is_array( $plan_result['planning'] ?? null ) ? $plan_result['planning'] : array(),
				/* translators: %1$s: textdomain, %2$s: target language */
				'message'    => sprintf( __( 'Language pack translation task created: %1$s → %2$s', 'wpmmcc-ats' ), $textdomain, $target_lang ),
			)
		);
	}

	/**
	 * Get monitoring status
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response
	 */
	public function get_monitor_status( $request ) {
		$relation_id = absint( $request->get_param( 'relation_id' ) );
		$status      = Monitoring_Task_Service::get_status( $relation_id );

		return rest_ensure_response( array(
			'success'     => true,
			'relation_id' => $relation_id,
			'monitoring'  => $status,
		) );
	}

	/**
	 * Start monitoring
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_monitoring( $request ) {
		$relation_id = absint( $request->get_param( 'relation_id' ) );
		$result      = Monitoring_Task_Service::start_monitoring( $relation_id );

		if ( ! $result['success'] ) {
			wptsall_log_error(
				'tasks-api',
				'start_monitoring failed',
				array( 'relation_id' => $relation_id, 'error' => $result['error'] ?? '' )
			);
			return new \WP_Error(
				'start_failed',
				$result['error'] ?? __( 'Failed to start monitoring', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'     => true,
			'relation_id' => $relation_id,
			'task_id'     => $result['task_id'],
			'message'     => $result['message'],
		) );
	}

	/**
	 * Stop monitoring
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function stop_monitoring( $request ) {
		$relation_id = absint( $request->get_param( 'relation_id' ) );
		$result      = Monitoring_Task_Service::stop_monitoring( $relation_id );

		if ( ! $result['success'] ) {
			wptsall_log_error(
				'tasks-api',
				'stop_monitoring failed',
				array( 'relation_id' => $relation_id, 'error' => $result['error'] ?? '' )
			);
			return new \WP_Error(
				'stop_failed',
				$result['error'] ?? __( 'Failed to stop monitoring', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'     => true,
			'relation_id' => $relation_id,
			'task_id'     => $result['task_id'],
			'message'     => $result['message'],
		) );
	}

	/**
	 * Trigger language pack scan
	 *
	 * P2: Language pack scan entry - scan POT/PO files, source code and database content
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_language_pack( $request ) {
		$relation_id = absint( $request->get_param( 'relation_id' ) );
		$mode        = $request->get_param( 'mode' ) ?? 'pot';

		wptsall_log_info(
			'tasks-api',
			'Language pack scan triggered',
			array(
				'relation_id' => $relation_id,
				'mode'        => $mode,
			)
		);

		// Verify relation exists
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Site relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Execute scan
		$result = Language_Pack_Scanner::scan_relation( $relation_id, $mode );

		if ( ! isset( $result['success'] ) || ! $result['success'] ) {
			wptsall_log_error(
				'tasks-api',
				'Language pack scan failed',
				array(
					'relation_id' => $relation_id,
					'mode'        => $mode,
					'error'       => $result['error'] ?? 'Unknown error',
				)
			);
			return new \WP_Error(
				'scan_failed',
				$result['error'] ?? __( 'Language pack scan failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		wptsall_log_info(
			'tasks-api',
			'Language pack scan completed',
			array(
				'relation_id'      => $relation_id,
				'mode'             => $mode,
				'templates_count'  => count( $result['templates'] ?? array() ),
				'total_entries'    => $result['total_entries'] ?? 0,
			)
		);

		return rest_ensure_response( array(
			'success'       => true,
			'relation_id'   => $relation_id,
			'mode'          => $mode,
			'templates'     => $result['templates'] ?? array(),
			'total_entries' => $result['total_entries'] ?? 0,
			'stats'         => $result['stats'] ?? array(),
		) );
	}

	/**
	 * Trigger language pack translation
	 *
	 * P3: Scan to translation flow - perform simulated translation on scanned entries
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function translate_language_pack( $request ) {
		if ( ! Translation_Simulation_Service::is_enabled() ) {
			return new \WP_Error(
				'simulation_disabled',
				__( 'Translation simulation is disabled by default in production. Enable WPTSALL_ENABLE_TRANSLATION_SIMULATION explicitly for debugging.', 'wpmmcc-ats' ),
				array( 'status' => 403 )
			);
		}

		$relation_id = absint( $request->get_param( 'relation_id' ) );

		wptsall_log_info(
			'tasks-api',
			'Language pack translation triggered',
			array( 'relation_id' => $relation_id )
		);

		// Verify relation exists
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Site relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Get target language from relation
		$target_lang = $relation['target_lang'] ?? 'zh';

		// Relation-scoped templates: templates/entries must be bound to relation_id.
		// This aligns with Gettext_Filter preloading (relation_id) and avoids global_only mismatches.
		$result = Template_Service::get_all( array(
			'relation_id'     => $relation_id,
			'target_language' => $target_lang,
			'per_page'        => 1000,
		) );
		$templates = $result['items'] ?? array();

		if ( empty( $templates ) ) {
			wptsall_log_warning(
				'tasks-api',
				'No templates found for translation',
				array( 'relation_id' => $relation_id )
			);
			return rest_ensure_response( array(
				'success'     => true,
				'relation_id' => $relation_id,
				'translated'  => 0,
				'message'     => __( 'No translatable templates found. Please run a scan first.', 'wpmmcc-ats' ),
			) );
		}

		// Translate each template
		$total_translated = 0;
		$template_stats   = array();

		foreach ( $templates as $template ) {
			$stats = Translation_Simulation_Service::simulate_template_translation(
				$template['id'],
				$target_lang
			);

			$template_stats[] = array(
				'template_id' => $template['id'],
				'domain'      => $template['domain'] ?? '',
				'translated'  => $stats['translated'],
			);

			$total_translated += $stats['translated'];
		}

		wptsall_log_info(
			'tasks-api',
			'Language pack translation completed',
			array(
				'relation_id'      => $relation_id,
				'target_lang'      => $target_lang,
				'templates_count'  => count( $templates ),
				'total_translated' => $total_translated,
			)
		);

		return rest_ensure_response( array(
			'success'     => true,
			'relation_id' => $relation_id,
			'target_lang' => $target_lang,
			'translated'  => $total_translated,
			'templates'   => $template_stats,
		) );
	}

	// ========================================
	// Task orchestration callback methods (v0.8.0)
	// ========================================

	/**
	 * Discover sync tasks
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function discover_sync_tasks( $request ) {
		$relation_id = absint( $request->get_param( 'relation_id' ) );
		$preview     = (bool) $request->get_param( 'preview' );

		wptsall_log_info(
			'tasks-api',
			'Discover sync tasks called',
			array(
				'relation_id' => $relation_id,
				'preview'     => $preview,
			)
		);

		// Verify relation exists
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Site relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		if ( $preview ) {
			// Get preview (includes detailed field info)
			$tasks = Task_Orchestrator::preview_tasks( $relation_id );
		} else {
			// Get raw discovered tasks
			$tasks = Task_Orchestrator::discover_tasks( $relation_id );
			$tasks = Task_Orchestrator::orchestrate( $tasks );

			// Simplify for API response
			$tasks = array_map( function( $task ) {
				return array(
					'relation_id' => $task['relation_id'],
					'model_id'    => $task['model_id'],
					'rule_id'     => $task['rule_id'],
					'post_type'   => $task['post_type'],
					'data_type'   => $task['data_type'],
					'direction'   => $task['config']['direction'] ?? 'source_to_target',
					'sync_mode'   => 'new_only',
					'enabled'     => $task['config']['enabled'] ?? true,
				);
			}, $tasks );
		}

		return rest_ensure_response( array(
			'success'     => true,
			'relation_id' => $relation_id,
			'preview'     => $preview,
			'tasks'       => $tasks,
			'task_count'  => count( $tasks ),
		) );
	}

	/**
	 * Get orchestration statistics
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response
	 */
	public function get_orchestration_stats( $request ) {
		$stats = Task_Orchestrator::get_stats();

		return rest_ensure_response( array(
			'success' => true,
			'stats'   => $stats,
		) );
	}

	// ========================================
	// Task logs callback methods (v0.8.0)
	// ========================================

	/**
	 * Get logs for a specific task
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 * @since 0.8.0
	 */
	public function get_task_logs( $request ) {
		$task_id  = absint( $request->get_param( 'id' ) );
		$page     = absint( $request->get_param( 'page' ) ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;

		// Limit per_page to max 100.
		$per_page = min( $per_page, 100 );
		$offset   = ( $page - 1 ) * $per_page;

		// Verify task exists.
		$task = Monitoring_Task_Service::get( $task_id );
		if ( ! $task ) {
			return new \WP_Error(
				'not_found',
				__( 'Task does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Get logs for this task.
		$logs = wptsall_get_task_logs( array(
			'task_id' => $task_id,
			'limit'   => $per_page,
			'offset'  => $offset,
		) );

		// Get total count.
		$total = wptsall_count_task_logs( array( 'task_id' => $task_id ) );
		$pages = ceil( $total / $per_page );

		return rest_ensure_response( array(
			'items'   => $logs,
			'total'   => (int) $total,
			'pages'   => (int) $pages,
			'page'    => $page,
			'task_id' => $task_id,
		) );
	}

	/**
	 * Get all task logs (global query)
	 *
	 * Supports filtering by task_id, status, date range
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response
	 * @since 0.8.0
	 */
	public function get_all_task_logs( $request ) {
		$task_id    = $request->get_param( 'task_id' );
		$status     = $request->get_param( 'status' );
		$start_date = $request->get_param( 'start_date' );
		$end_date   = $request->get_param( 'end_date' );
		$page       = absint( $request->get_param( 'page' ) ) ?: 1;
		$per_page   = absint( $request->get_param( 'per_page' ) ) ?: 20;

		// Limit per_page to max 100.
		$per_page = min( $per_page, 100 );
		$offset   = ( $page - 1 ) * $per_page;

		// Build query args.
		$args = array(
			'limit'  => $per_page,
			'offset' => $offset,
		);

		if ( $task_id ) {
			$args['task_id'] = absint( $task_id );
		}

		if ( $status ) {
			$args['status_to'] = sanitize_key( $status );
		}

		if ( $start_date ) {
			$args['start_date'] = sanitize_text_field( $start_date );
		}

		if ( $end_date ) {
			$args['end_date'] = sanitize_text_field( $end_date );
		}

		// Get logs.
		$logs = wptsall_get_task_logs( $args );

		// Get total count (same filters, no limit/offset).
		$count_args = array_diff_key( $args, array( 'limit' => '', 'offset' => '' ) );
		$total      = wptsall_count_task_logs( $count_args );
		$pages      = ceil( $total / $per_page );

		return rest_ensure_response( array(
			'items' => $logs,
			'total' => (int) $total,
			'pages' => (int) $pages,
			'page'  => $page,
		) );
	}
}
