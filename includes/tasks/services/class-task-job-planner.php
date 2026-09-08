<?php
/**
 * Task Job Planner Service
 *
 * Unified planner for site-level job bundles (Job -> Task list).
 *
 * @package WPTSALL\Tasks\Services
 * @since 1.0.0
 */

namespace WPTSALL\Tasks\Services;

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Templates\Scanners\Language_Pack_Scanner;
use WPTSALL\Templates\Services\Template_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Task Job Planner class
 *
 * == ENTRY POINT AUDIT (CORE-P0-003, v1.0.5) ==
 *
 * All task production entry points and their planner coverage status:
 *
 * THROUGH PLANNER (source = 'task_job_planner'):
 * 1. Task_Job_Planner::plan_relation_job()
 *    - Called by: REST POST /tasks/jobs (create_task_job)
 *    - Normalizes: job_id, business_line, object_ref, subtasks via prepare_and_plan_tasks()
 *
 * 2. Task_Job_Planner::plan_language_pack_template_job()
 *    - Called by: REST POST /tasks/jobs (with template_id)
 *    - Same normalization as above
 *
 * BYPASSED PLANNER (wrapped with wptsall_normalize_task_for_planner):
 * 3. REST POST /tasks (create_task) -> source = 'rest_create_task'
 *    - Uses wptsall_generate_task_for_object() + wptsall_insert_tasks()
 *    - Wrapped: normalize called before insert
 *
 * 4. Hook_Manager::run_hook_action() -> source = 'hook_manager'
 *    - Currently disabled for post/term/frontend hooks (returns early)
 *    - Fallback path: wptsall_generate_tasks_from_template() + wptsall_insert_tasks()
 *    - Wrapped: normalize called before insert
 *
 * 5. Hook_Manager::handle_frontend_hook() -> source = 'hook_manager_frontend'
 *    - wptsall_generate_task_for_object() + wptsall_insert_tasks()
 *    - Wrapped: normalize called before insert
 *
 * 6. wptsall_insert_task() (tasks-single.php) -> source = 'insert_task_single'
 *    - Direct DB insert, already includes job_id/business_line/object_ref in payload
 *    - Source field added to payload
 *
 * MONITORING / EXECUTION PATHS (not task creation to planner):
 * 7. Monitoring_Task_Service::create() -> source = 'monitoring_create' (in meta)
 *    - Creates monitoring-type tasks (one per relation), not content tasks
 *    - Direct DB insert with type=monitoring, not routed through planner
 *
 * 8. Monitoring_Task_Service::process() — Path A execution
 *    - Processes existing monitoring tasks, does direct sync (not task creation)
 *
 * 9. Sync_Executor::execute_with_config() — Path B execution
 *    - Executes existing tasks from the task table
 *
 * 10. automation-cron.php handlers — Task CONSUMPTION
 *     - wptsall_process_high/normal/low_priority_tasks: consume pending tasks
 *     - wptsall_retry_failed_tasks: retry failed tasks
 *
 * 11. Task_Orchestrator::process_pending_tasks() — Task EXECUTION
 *     - Queries pending tasks from DB, dispatches to Sync_Executor
 *
 * 12. module.php::on_site_relations_created() — Monitoring task auto-start
 *     - Calls Monitoring_Task_Service::start_monitoring() on relation creation
 *
 * 13. Client_Tasks_REST_Controller — External client endpoints
 *     - GET /client/tasks: reads pending tasks for external client
 *     - POST /client/tasks/{id}/status: updates task status
 *     - POST /client/tasks/{id}/result: submits completed result
 *     - No task creation, only consumption/status updates
 */
class Task_Job_Planner {

	/**
	 * Plan relation job bundle (site-level).
	 *
	 * @param int   $relation_id Relation id.
	 * @param array $options     Planner options.
	 * @return array|\WP_Error
	 */
	public static function plan_relation_job( $relation_id, $options = array() ) {
		// Reject non-positive / negative before absint() (absint(-1) === 1).
		if ( ! is_numeric( $relation_id ) || (int) $relation_id <= 0 ) {
			return new \WP_Error(
				'invalid_relation_id',
				__( 'Invalid relation_id', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		$relation_id = absint( $relation_id );
		if ( $relation_id <= 0 ) {
			return new \WP_Error(
				'invalid_relation_id',
				__( 'Invalid relation_id', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$relation = is_array( $options['relation'] ?? null )
			? $options['relation']
			: Site_Relation_Service::get_relation( $relation_id );
		if ( empty( $relation ) || ! is_array( $relation ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Site relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$include_content       = self::normalize_bool( $options['include_content'] ?? true, true );
		$include_language_pack = self::normalize_bool( $options['include_language_pack'] ?? false, false );
		if ( ! $include_content && ! $include_language_pack ) {
			return new \WP_Error(
				'invalid_scope',
				__( 'At least one task generation scope must be enabled (content or language_pack)', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$job_id = sanitize_text_field( (string) ( $options['job_id'] ?? '' ) );
		if ( '' === $job_id ) {
			$job_id = function_exists( 'wptsall_generate_task_job_id' )
				? wptsall_generate_task_job_id( 'relation_' . $relation_id )
				: sanitize_key( 'job_relation_' . $relation_id . '_' . time() );
		}

		$limit      = max( 1, min( 5000, absint( $options['limit'] ?? 100 ) ) );
		$batch_size = max( 1, min( 500, absint( $options['batch_size'] ?? 50 ) ) );
		$target_lang = sanitize_text_field( (string) ( $options['target_lang'] ?? '' ) );
		if ( '' === $target_lang ) {
			$target_lang = sanitize_text_field( (string) ( $relation['target_lang'] ?? '' ) );
		}
		$source_lang = sanitize_text_field( (string) ( $options['source_lang'] ?? '' ) );
		if ( '' === $source_lang ) {
			$source_lang = sanitize_text_field( (string) ( $relation['source_lang'] ?? get_locale() ) );
		}

		$line_batch_strategy = self::normalize_line_batch_strategy( $options['line_batch_strategy'] ?? 'priority' );
		$allowed_business_lines = self::resolve_allowed_business_lines(
			$options['business_lines'] ?? array(),
			$include_content,
			$include_language_pack
		);
		$business_line_limits = self::normalize_business_line_limits(
			$options['business_line_limits'] ?? array(),
			$allowed_business_lines
		);

		$all_tasks      = array();
		$warnings       = array();
		$content_tasks  = 0;
		$language_tasks = 0;

		if ( $include_content ) {
			$generated = wptsall_generate_tasks_from_relation(
				$relation_id,
				array(
					'limit'  => $limit,
					'job_id' => $job_id,
				)
			);
			if ( is_wp_error( $generated ) ) {
				return $generated;
			}
			if ( is_array( $generated ) ) {
				$content_tasks = count( $generated );
				$all_tasks     = array_merge( $all_tasks, $generated );
			}
		}

		if ( $include_language_pack ) {
			$language_result = self::generate_relation_language_pack_tasks(
				$relation_id,
				array(
					'job_id'     => $job_id,
					'source_lang'=> $source_lang,
					'target_lang'=> $target_lang,
					'batch_size' => $batch_size,
				)
			);
			if ( is_wp_error( $language_result ) ) {
				return $language_result;
			}
			$language_tasks = (int) ( $language_result['count'] ?? 0 );
			$all_tasks      = array_merge( $all_tasks, (array) ( $language_result['tasks'] ?? array() ) );
			$warnings       = array_merge( $warnings, (array) ( $language_result['warnings'] ?? array() ) );
		}

		$planned = self::prepare_and_plan_tasks(
			$all_tasks,
			$job_id,
			$allowed_business_lines,
			$business_line_limits,
			$line_batch_strategy
		);

		$planned_tasks = $planned['tasks'];
		$planning      = $planned['planning'];
		$summary       = self::summarize_tasks( $planned_tasks );

		return array(
			'job_id'               => $job_id,
			'tasks'                => $planned_tasks,
			'summary'              => $summary,
			'content_tasks'        => $content_tasks,
			'language_pack_tasks'  => $language_tasks,
			'warnings'             => $warnings,
			'planning'             => $planning,
			'line_batch_strategy'  => $line_batch_strategy,
			'business_lines'       => $allowed_business_lines,
			'business_line_limits' => $business_line_limits,
		);
	}

	/**
	 * Plan a language-pack template job bundle.
	 *
	 * @param int   $template_id Template id.
	 * @param array $options     Planner options.
	 * @return array|\WP_Error
	 */
	public static function plan_language_pack_template_job( $template_id, $options = array() ) {
		// Reject non-positive / negative before absint() (absint(-5) === 5).
		if ( ! is_numeric( $template_id ) || (int) $template_id <= 0 ) {
			return new \WP_Error(
				'invalid_template',
				__( 'Language pack template does not exist', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}
		$template_id = absint( $template_id );
		if ( $template_id <= 0 ) {
			return new \WP_Error(
				'invalid_template',
				__( 'Language pack template does not exist', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$job_id = sanitize_text_field( (string) ( $options['job_id'] ?? '' ) );
		if ( '' === $job_id ) {
			$job_id = function_exists( 'wptsall_generate_task_job_id' )
				? wptsall_generate_task_job_id( 'language_pack_' . $template_id )
				: sanitize_key( 'job_language_pack_' . $template_id . '_' . time() );
		}
		$source_lang = sanitize_text_field( (string) ( $options['source_lang'] ?? get_locale() ) );
		$target_lang = sanitize_text_field( (string) ( $options['target_lang'] ?? '' ) );
		$batch_size  = max( 1, min( 500, absint( $options['batch_size'] ?? 50 ) ) );

		$generated = wptsall_generate_language_pack_task(
			$template_id,
			array(
				'lang_from'  => $source_lang,
				'lang_to'    => $target_lang,
				'batch_size' => $batch_size,
				'job_id'     => $job_id,
			)
		);
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		$line_batch_strategy   = self::normalize_line_batch_strategy( $options['line_batch_strategy'] ?? 'priority' );
		$allowed_business_lines = self::normalize_business_lines_list( $options['business_lines'] ?? array() );
		$business_line_limits   = self::normalize_business_line_limits(
			$options['business_line_limits'] ?? array(),
			$allowed_business_lines
		);

		$planned = self::prepare_and_plan_tasks(
			is_array( $generated ) ? $generated : array(),
			$job_id,
			array_values( $allowed_business_lines ),
			$business_line_limits,
			$line_batch_strategy
		);
		$tasks    = $planned['tasks'];
		$planning = $planned['planning'];
		$summary  = self::summarize_tasks( $tasks );

		return array(
			'job_id'               => $job_id,
			'tasks'                => $tasks,
			'summary'              => $summary,
			'content_tasks'        => 0,
			'language_pack_tasks'  => count( $tasks ),
			'warnings'             => array(),
			'planning'             => $planning,
			'line_batch_strategy'  => $line_batch_strategy,
			'business_lines'       => array_values( $allowed_business_lines ),
			'business_line_limits' => $business_line_limits,
		);
	}

	/**
	 * Generate language pack tasks by relation (with auto-scan fallback).
	 *
	 * @param int   $relation_id Relation id.
	 * @param array $options     Generate options.
	 * @return array|\WP_Error
	 */
	private static function generate_relation_language_pack_tasks( $relation_id, $options = array() ) {
		$relation_id = absint( $relation_id );
		if ( $relation_id <= 0 ) {
			return new \WP_Error(
				'invalid_relation_id',
				__( 'Invalid relation_id', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$target_lang = sanitize_text_field( (string) ( $options['target_lang'] ?? '' ) );
		$source_lang = sanitize_text_field( (string) ( $options['source_lang'] ?? get_locale() ) );
		$batch_size  = max( 1, min( 500, absint( $options['batch_size'] ?? 50 ) ) );
		$job_id      = sanitize_text_field( (string) ( $options['job_id'] ?? '' ) );

		$warnings = array();
		$tasks    = array();

		$templates_result = Template_Service::get_all(
			array(
				'relation_id'     => $relation_id,
				'target_language' => $target_lang,
				'per_page'        => 1000,
			)
		);
		$templates = is_array( $templates_result['items'] ?? null ) ? $templates_result['items'] : array();
		if ( empty( $templates ) && class_exists( Language_Pack_Scanner::class ) ) {
			$scan_result = Language_Pack_Scanner::scan_relation( $relation_id, 'pot' );
			if ( ! is_array( $scan_result ) || empty( $scan_result['success'] ) ) {
				$warnings[] = array(
					'code'    => 'language_pack_scan_failed',
					'message' => sanitize_text_field(
						(string) ( is_array( $scan_result ) ? ( $scan_result['error'] ?? '' ) : '' )
					),
				);
			}
			$templates_result = Template_Service::get_all(
				array(
					'relation_id'     => $relation_id,
					'target_language' => $target_lang,
					'per_page'        => 1000,
				)
			);
			$templates = is_array( $templates_result['items'] ?? null ) ? $templates_result['items'] : array();
		}
		if ( empty( $templates ) ) {
			$warnings[] = array(
				'code'    => 'language_pack_templates_empty',
				'message' => __( 'Language pack template not found (auto-scan attempted)', 'wpmmcc-ats' ),
			);
		}

		foreach ( $templates as $template ) {
			$template_id = absint( $template['id'] ?? 0 );
			if ( $template_id <= 0 ) {
				continue;
			}
			$generated = wptsall_generate_language_pack_task(
				$template_id,
				array(
					'lang_from'   => $source_lang,
					'lang_to'     => $target_lang,
					'batch_size'  => $batch_size,
					'job_id'      => $job_id,
				)
			);
			if ( is_wp_error( $generated ) ) {
				if ( 'no_entries' === $generated->get_error_code() ) {
					continue;
				}
				$warnings[] = array(
					'template_id' => $template_id,
					'code'        => sanitize_key( (string) $generated->get_error_code() ),
					'message'     => sanitize_text_field( (string) $generated->get_error_message() ),
				);
				continue;
			}
			if ( is_array( $generated ) ) {
				$tasks = array_merge( $tasks, $generated );
			}
		}

		return array(
			'tasks'    => $tasks,
			'count'    => count( $tasks ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Prepare generated tasks and apply planner rules.
	 *
	 * @param array  $tasks                 Raw tasks.
	 * @param string $job_id                Job id.
	 * @param array  $allowed_business_lines Allowed business lines.
	 * @param array  $business_line_limits  Line limits.
	 * @param string $line_batch_strategy   Batch strategy.
	 * @return array
	 */
	private static function prepare_and_plan_tasks( $tasks, $job_id, $allowed_business_lines, $business_line_limits, $line_batch_strategy ) {
		$prepared = array();
		$dropped  = array(
			'invalid'               => 0,
			'business_line_filtered'=> array(),
			'line_limit'            => array(),
		);
		$line_counts = array();

		foreach ( (array) $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				$dropped['invalid']++;
				continue;
			}
			$task['job_id'] = $job_id;
			if ( empty( $task['source'] ) ) {
				$task['source'] = 'task_job_planner';
			}
			if ( function_exists( 'wptsall_prepare_task_for_client_payload' ) ) {
				$task = wptsall_prepare_task_for_client_payload( $task, 'text' );
			}

			$business_line = self::infer_task_business_line( $task );
			$task['business_line'] = $business_line;
			$task['task_type']     = self::infer_task_type( $task );

			if ( ! empty( $allowed_business_lines ) && ! in_array( $business_line, $allowed_business_lines, true ) ) {
				$dropped['business_line_filtered'][ $business_line ] = (int) ( $dropped['business_line_filtered'][ $business_line ] ?? 0 ) + 1;
				continue;
			}

			$limit = (int) ( $business_line_limits[ $business_line ] ?? 0 );
			if ( $limit > 0 ) {
				$current_count = (int) ( $line_counts[ $business_line ] ?? 0 );
				if ( $current_count >= $limit ) {
					$dropped['line_limit'][ $business_line ] = (int) ( $dropped['line_limit'][ $business_line ] ?? 0 ) + 1;
					continue;
				}
			}

			$line_counts[ $business_line ] = (int) ( $line_counts[ $business_line ] ?? 0 ) + 1;
			$prepared[] = $task;
		}

		$ordered = self::order_tasks( $prepared, $line_batch_strategy );

		return array(
			'tasks'    => $ordered,
			'planning' => array(
				'line_batch_strategy'   => $line_batch_strategy,
				'allowed_business_lines'=> array_values( $allowed_business_lines ),
				'business_line_limits'  => $business_line_limits,
				'selected_by_line'      => $line_counts,
				'dropped'               => $dropped,
				'total_selected'        => count( $ordered ),
			),
		);
	}

	/**
	 * Order tasks by planner strategy.
	 *
	 * @param array  $tasks     Tasks.
	 * @param string $strategy  Strategy.
	 * @return array
	 */
	private static function order_tasks( $tasks, $strategy ) {
		$tasks = array_values( (array) $tasks );
		if ( empty( $tasks ) ) {
			return $tasks;
		}

		if ( 'round_robin' === $strategy ) {
			return self::order_tasks_round_robin( $tasks );
		}

		usort( $tasks, array( __CLASS__, 'compare_tasks_by_priority' ) );
		return $tasks;
	}

	/**
	 * Order tasks by round robin across business lines.
	 *
	 * @param array $tasks Tasks.
	 * @return array
	 */
	private static function order_tasks_round_robin( $tasks ) {
		$groups = array();
		foreach ( $tasks as $task ) {
			$line = self::infer_task_business_line( $task );
			if ( ! isset( $groups[ $line ] ) ) {
				$groups[ $line ] = array();
			}
			$groups[ $line ][] = $task;
		}

		foreach ( $groups as &$group_tasks ) {
			usort( $group_tasks, array( __CLASS__, 'compare_tasks_by_priority' ) );
		}
		unset( $group_tasks );

		$priority_map = self::get_business_line_priority_map();
		$line_order   = array_keys( $groups );
		usort(
			$line_order,
			function( $a, $b ) use ( $priority_map ) {
				$pa = (int) ( $priority_map[ $a ] ?? 999 );
				$pb = (int) ( $priority_map[ $b ] ?? 999 );
				if ( $pa === $pb ) {
					return strcmp( (string) $a, (string) $b );
				}
				return $pa <=> $pb;
			}
		);

		$ordered = array();
		do {
			$has_left = false;
			foreach ( $line_order as $line ) {
				if ( empty( $groups[ $line ] ) ) {
					continue;
				}
				$ordered[] = array_shift( $groups[ $line ] );
				$has_left  = true;
			}
		} while ( $has_left );

		return $ordered;
	}

	/**
	 * Compare tasks by planner priority.
	 *
	 * @param array $a Task A.
	 * @param array $b Task B.
	 * @return int
	 */
	private static function compare_tasks_by_priority( $a, $b ) {
		$line_priority = self::get_business_line_priority_map();
		$object_priority = self::get_object_type_priority_map();

		$line_a = self::infer_task_business_line( $a );
		$line_b = self::infer_task_business_line( $b );
		$pa     = (int) ( $line_priority[ $line_a ] ?? 999 );
		$pb     = (int) ( $line_priority[ $line_b ] ?? 999 );
		if ( $pa !== $pb ) {
			return $pa <=> $pb;
		}

		$type_a = sanitize_key( (string) ( $a['object_type'] ?? 'unknown' ) );
		$type_b = sanitize_key( (string) ( $b['object_type'] ?? 'unknown' ) );
		$oa     = (int) ( $object_priority[ $type_a ] ?? ( $object_priority['default'] ?? 999 ) );
		$ob     = (int) ( $object_priority[ $type_b ] ?? ( $object_priority['default'] ?? 999 ) );
		if ( $oa !== $ob ) {
			return $oa <=> $ob;
		}

		$sub_a = sanitize_key( (string) ( $a['subtype'] ?? '' ) );
		$sub_b = sanitize_key( (string) ( $b['subtype'] ?? '' ) );
		if ( $sub_a !== $sub_b ) {
			return strcmp( $sub_a, $sub_b );
		}

		$id_a = (int) ( $a['object_id'] ?? 0 );
		$id_b = (int) ( $b['object_id'] ?? 0 );
		if ( $id_a !== $id_b ) {
			return $id_a <=> $id_b;
		}
		return strcmp(
			sanitize_text_field( (string) ( $a['template'] ?? '' ) ),
			sanitize_text_field( (string) ( $b['template'] ?? '' ) )
		);
	}

	/**
	 * Resolve allowed business lines.
	 *
	 * @param mixed $raw_lines              Raw lines.
	 * @param bool  $include_content        Include content.
	 * @param bool  $include_language_pack  Include language pack.
	 * @return array
	 */
	private static function resolve_allowed_business_lines( $raw_lines, $include_content, $include_language_pack ) {
		$explicit = self::normalize_business_lines_list( $raw_lines );
		if ( ! empty( $explicit ) ) {
			return array_values( $explicit );
		}

		$lines = array();
		if ( $include_content ) {
			$lines = array_merge( $lines, array( 'post_content', 'taxonomy_content', 'custom_model' ) );
		}
		if ( $include_language_pack ) {
			$lines = array_merge( $lines, array( 'plugin_i18n', 'theme_i18n' ) );
		}
		$lines = self::normalize_business_lines_list( $lines );
		return array_values( $lines );
	}

	/**
	 * Normalize line limits map.
	 *
	 * @param mixed $raw_limits Limits input.
	 * @param array $allowed_lines Allowed lines.
	 * @return array
	 */
	private static function normalize_business_line_limits( $raw_limits, $allowed_lines ) {
		if ( ! is_array( $raw_limits ) ) {
			return array();
		}
		$allowed_map = array_fill_keys( (array) $allowed_lines, true );
		$limits      = array();
		foreach ( $raw_limits as $raw_line => $raw_limit ) {
			$line = self::normalize_business_line( (string) $raw_line );
			if ( '' === $line ) {
				continue;
			}
			if ( ! empty( $allowed_map ) && empty( $allowed_map[ $line ] ) ) {
				continue;
			}
			$limit = absint( $raw_limit );
			if ( $limit <= 0 ) {
				continue;
			}
			$limits[ $line ] = min( 100000, $limit );
		}
		return $limits;
	}

	/**
	 * Normalize batch strategy.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	private static function normalize_line_batch_strategy( $raw ) {
		$value = sanitize_key( (string) $raw );
		if ( in_array( $value, array( 'priority', 'round_robin' ), true ) ) {
			return $value;
		}
		return 'priority';
	}

	/**
	 * Normalize business lines list.
	 *
	 * @param mixed $raw_lines Raw input.
	 * @return array
	 */
	private static function normalize_business_lines_list( $raw_lines ) {
		$values = array();
		if ( is_string( $raw_lines ) ) {
			$values = preg_split( '/[\s,;|]+/', $raw_lines );
		} elseif ( is_array( $raw_lines ) ) {
			$values = $raw_lines;
		}

		$lines = array();
		foreach ( (array) $values as $raw_line ) {
			$line = self::normalize_business_line( (string) $raw_line );
			if ( '' === $line ) {
				continue;
			}
			$lines[ $line ] = $line;
		}
		return $lines;
	}

	/**
	 * Infer business line from task.
	 *
	 * @param array $task Task.
	 * @return string
	 */
	private static function infer_task_business_line( $task ) {
		$explicit = self::normalize_business_line( (string) ( $task['business_line'] ?? '' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}
		if ( function_exists( 'wptsall_infer_business_line_from_task' ) ) {
			$line = self::normalize_business_line( (string) wptsall_infer_business_line_from_task( $task ) );
			if ( '' !== $line ) {
				return $line;
			}
		}
		return 'custom_model';
	}

	/**
	 * Infer normalized task type from task payload.
	 *
	 * @param array $task Task.
	 * @return string
	 */
	private static function infer_task_type( $task ) {
		$type = sanitize_key( (string) ( $task['task_type'] ?? ( $task['type'] ?? '' ) ) );
		if ( '' === $type && is_array( $task['payload'] ?? null ) ) {
			$type = sanitize_key( (string) ( $task['payload']['task_type'] ?? ( $task['payload']['type'] ?? '' ) ) );
		}
		if ( '' === $type ) {
			$type = 'text';
		}
		if ( ! in_array( $type, array( 'text', 'image', 'video', 'audio', 'document', 'mixed' ), true ) ) {
			$type = 'text';
		}
		return $type;
	}

	/**
	 * Normalize business line value.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private static function normalize_business_line( $raw ) {
		$value = sanitize_key( trim( (string) $raw ) );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'wptsall_normalize_business_line' ) ) {
			$normalized = sanitize_key( (string) wptsall_normalize_business_line( $value ) );
			if ( '' !== $normalized ) {
				return $normalized;
			}
		}

		$aliases = array(
			'post'             => 'post_content',
			'posts'            => 'post_content',
			'post_content'     => 'post_content',
			'taxonomy'         => 'taxonomy_content',
			'taxonomies'       => 'taxonomy_content',
			'taxonomy_content' => 'taxonomy_content',
			'plugin'           => 'plugin_i18n',
			'plugin_i18n'      => 'plugin_i18n',
			'theme'            => 'theme_i18n',
			'theme_i18n'       => 'theme_i18n',
			'model'            => 'custom_model',
			'custom_model'     => 'custom_model',
		);
		$normalized = $aliases[ $value ] ?? '';
		if ( '' === $normalized ) {
			return '';
		}
		return $normalized;
	}

	/**
	 * Get business line priority map.
	 *
	 * @return array
	 */
	private static function get_business_line_priority_map() {
		$defaults = array(
			'post_content'     => 10,
			'taxonomy_content' => 20,
			'custom_model'     => 30,
			'plugin_i18n'      => 40,
			'theme_i18n'       => 50,
		);
		$map = apply_filters( 'wptsall_task_job_business_line_priority', $defaults );
		if ( ! is_array( $map ) ) {
			return $defaults;
		}
		$normalized = array();
		foreach ( $map as $line => $priority ) {
			$key = self::normalize_business_line( (string) $line );
			if ( '' === $key ) {
				continue;
			}
			$normalized[ $key ] = (int) $priority;
		}
		foreach ( $defaults as $line => $priority ) {
			if ( ! isset( $normalized[ $line ] ) ) {
				$normalized[ $line ] = $priority;
			}
		}
		return $normalized;
	}

	/**
	 * Get object type priority map.
	 *
	 * @return array
	 */
	private static function get_object_type_priority_map() {
		$defaults = array(
			'post_type'     => 10,
			'taxonomy'      => 20,
			'language_pack' => 30,
			'default'       => 50,
		);
		$map = apply_filters( 'wptsall_task_job_object_type_priority', $defaults );
		if ( ! is_array( $map ) ) {
			return $defaults;
		}
		$normalized = array();
		foreach ( $map as $type => $priority ) {
			$key = sanitize_key( (string) $type );
			if ( '' === $key ) {
				continue;
			}
			$normalized[ $key ] = (int) $priority;
		}
		if ( ! isset( $normalized['default'] ) ) {
			$normalized['default'] = $defaults['default'];
		}
		return $normalized;
	}

	/**
	 * Summarize tasks for job response.
	 *
	 * @param array $tasks Tasks.
	 * @return array
	 */
	private static function summarize_tasks( $tasks ) {
		$summary = array(
			'business_lines' => array(),
			'task_types'     => array(),
			'object_types'   => array(),
		);
		foreach ( (array) $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$line = self::infer_task_business_line( $task );
			$type = self::infer_task_type( $task );
			$obj  = sanitize_key( (string) ( $task['object_type'] ?? 'unknown' ) );
			if ( '' === $obj ) {
				$obj = 'unknown';
			}
			$summary['business_lines'][ $line ] = (int) ( $summary['business_lines'][ $line ] ?? 0 ) + 1;
			$summary['task_types'][ $type ]     = (int) ( $summary['task_types'][ $type ] ?? 0 ) + 1;
			$summary['object_types'][ $obj ]    = (int) ( $summary['object_types'][ $obj ] ?? 0 ) + 1;
		}
		arsort( $summary['business_lines'] );
		arsort( $summary['task_types'] );
		arsort( $summary['object_types'] );
		return $summary;
	}

	/**
	 * Normalize mixed bool values.
	 *
	 * @param mixed $value   Value.
	 * @param bool  $default Default.
	 * @return bool
	 */
	private static function normalize_bool( $value, $default = false ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}
		if ( is_string( $value ) ) {
			$text = strtolower( trim( $value ) );
			if ( in_array( $text, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $text, array( '0', 'false', 'no', 'off' ), true ) ) {
				return false;
			}
		}
		return (bool) $default;
	}
}
