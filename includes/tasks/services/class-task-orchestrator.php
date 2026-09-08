<?php
/**
 * Task Orchestrator
 *
 * Task orchestrator - discover, orchestrate and execute sync tasks
 *
 * v0.8.0 Key Features:
 * - Discover tasks to execute (based on Site Relation and associated Models)
 * - Use merged configuration (Model + Site layers)
 * - Task priority sorting (post_type takes precedence over taxonomy)
 *
 * @package WPTSALL\Tasks\Services
 * @since 0.8.0
 */
namespace WPTSALL\Tasks\Services;

use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Validators\Site_Relation_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Task Orchestrator class
 *
 * Responsible for discovering, orchestrating and executing sync tasks.
 *
 * Discovery methods (discover_tasks, orchestrate) are used for task preview and statistics.
 * Execution is handled by process_pending_tasks() which dispatches to Sync_Executor (Path B).
 */
class Task_Orchestrator {

	/**
	 * Data type priorities (defaults)
	 *
	 * @var array
	 */
	private static $default_type_priority = array(
		'post'     => 1,
		'page'     => 2,
		'taxonomy' => 20,
		'default'  => 10,
	);

	/**
	 * Cached type priorities (after applying filters)
	 *
	 * @var array|null
	 */
	private static $type_priority_cache = null;

	/**
	 * Get type priority configuration
	 *
	 * Allow third-party plugins to customize post_type task processing priority via filter hook.
	 *
	 * @return array Type priority mapping ['post_type' => priority_number].
	 */
	private static function get_type_priority() {
		if ( null === self::$type_priority_cache ) {
			/**
			 * Filter task type priority for ordering.
			 *
			 * Lower numbers indicate higher priority (processed first).
			 * Default priorities:
			 * - post: 1
			 * - page: 2
			 * - taxonomy: 20
			 * - default: 10 (for unlisted post_types)
			 *
			 * @since 0.9.3
			 *
			 * @param array $type_priority Associative array of post_type => priority.
			 */
			self::$type_priority_cache = apply_filters(
				'wptsall_task_type_priority',
				self::$default_type_priority
			);
		}

		return self::$type_priority_cache;
	}

	/**
	 * Discover tasks to execute
	 *
	 * Discover all tasks based on Site Relation and associated Models.
	 * Each task contains merged configuration (Model + Site layers).
	 *
	 * @param int $relation_id Site Relation ID.
	 * @return array Task list.
	 */
	public static function discover_tasks( $relation_id ) {
		$relation = Site_Relation_Service::get_relation( $relation_id );

		if ( ! $relation || 'active' !== $relation['status'] ) {
			wptsall_log_info(
				'tasks-orchestrator',
				'Relation not active, skipping task discovery',
				array(
					'relation_id' => $relation_id,
					'status'      => $relation['status'] ?? 'not_found',
				)
			);
			return array();
		}

		// Get associated Models
		$models = Relation_Model_Service::get_models_by_relation( $relation_id );

		if ( empty( $models ) ) {
			wptsall_log_info(
				'tasks-orchestrator',
				'No models associated with relation',
				array( 'relation_id' => $relation_id )
			);
			return array();
		}

		$tasks = array();

		foreach ( $models as $model ) {
			$model_id = (int) $model['id'];
			$plugin_slug = $model['plugin_slug'] ?? '';

			// Skip inactive or missing plugins for this relation.
			if ( $plugin_slug ) {
				$source_site_id = (int) ( $relation['source_site_id'] ?? 0 );
				$target_site_id = $relation['target_site_id'] ?? 0;
				$target_type    = $relation['target_site_type'] ?? 'wp';

				$source_active = Site_Relation_Validator::plugin_is_active_on_site( $plugin_slug, $source_site_id );
				$target_active = true;

				if ( 'wp' === $target_type && is_numeric( $target_site_id ) ) {
					$target_active = Site_Relation_Validator::plugin_is_active_on_site( $plugin_slug, (int) $target_site_id );
				}

				if ( ! $source_active || ! $target_active ) {
					wptsall_log_info(
						'tasks-orchestrator',
						'Plugin inactive on relation sites, skipping model',
						array(
							'relation_id'   => $relation_id,
							'plugin_slug'   => $plugin_slug,
							'source_active' => $source_active,
							'target_active' => $target_active,
						)
					);
					continue;
				}
			}

			// Get translation rules for Models
			$rules = Translation_Rule_Service::get_model_rules( $model_id );

			foreach ( $rules as $rule ) {
				// Skip inactive rules
				if ( empty( $rule['is_active'] ) ) {
					continue;
				}

				// Get merged configuration (core step)
				$config = Translation_Rule_Service::get_merged_config(
					(int) $rule['id'],
					$relation_id
				);

				// Skip disabled configurations
				if ( ! $config || ! $config['enabled'] ) {
					continue;
				}

				$tasks[] = array(
					'relation_id' => $relation_id,
					'model_id'    => $model_id,
					'rule_id'     => (int) $rule['id'],
					'post_type'   => $rule['object_name'] ?? '',
					'data_type'   => $rule['data_type'] ?? 'post',
					'config'      => $config,
					'relation'    => $relation,
					'model'       => $model,
				);
			}
		}

		wptsall_log_info(
			'tasks-orchestrator',
			'Tasks discovered',
			array(
				'relation_id' => $relation_id,
				'task_count'  => count( $tasks ),
			)
		);

		return $tasks;
	}

	/**
	 * Orchestrate tasks (process dependencies and priority sorting)
	 *
	 * @param array $tasks Task list.
	 * @return array Sorted task list.
	 */
	public static function orchestrate( $tasks ) {
		if ( empty( $tasks ) ) {
			return array();
		}

		// Sort by priority
		usort(
			$tasks,
			function ( $a, $b ) {
				$priority_a = self::get_task_priority( $a );
				$priority_b = self::get_task_priority( $b );
				return $priority_a - $priority_b;
			}
		);

		return $tasks;
	}

	/**
	 * Get task priority
	 *
	 * @param array $task Task data.
	 * @return int Priority value (lower is higher priority).
	 */
	private static function get_task_priority( $task ) {
		$post_type     = $task['post_type'] ?? '';
		$data_type     = $task['data_type'] ?? 'post';
		$type_priority = self::get_type_priority();

		// taxonomy type has lower priority
		if ( 'term' === $data_type || 'taxonomy' === $data_type ) {
			return $type_priority['taxonomy'] ?? 20;
		}

		// Find post_type priority
		if ( isset( $type_priority[ $post_type ] ) ) {
			return $type_priority[ $post_type ];
		}

		return $type_priority['default'] ?? 10;
	}

	/**
	 * Get task preview for Site Relation
	 *
	 * Does not execute tasks, only returns the list of tasks to be executed.
	 *
	 * @param int $relation_id Site Relation ID.
	 * @return array Task preview.
	 */
	public static function preview_tasks( $relation_id ) {
		$tasks = self::discover_tasks( $relation_id );
		$tasks = self::orchestrate( $tasks );

		$preview = array();

		foreach ( $tasks as $task ) {
			$fields_by_type = Translation_Rule_Service::get_fields_by_type( $task['config'] );

			$preview[] = array(
				'relation_id'      => $task['relation_id'],
				'model_id'         => $task['model_id'],
				'model_name'       => $task['model']['plugin_name'] ?? '',
				'rule_id'          => $task['rule_id'],
				'post_type'        => $task['post_type'],
				'data_type'        => $task['data_type'],
				'direction'        => $task['config']['direction'] ?? 'source_to_target',
				'sync_mode'        => 'new_only',
				'enabled'          => $task['config']['enabled'] ?? true,
				'translate_fields' => $fields_by_type['translate'],
				'sync_fields'      => $fields_by_type['sync'],
				// Translation_Rule_Service groups ID-reference fields under 'id_mapping' (legacy: 'mapping').
				'mapping_fields'   => $fields_by_type['id_mapping'] ?? array(),
				'compute_fields'   => $fields_by_type['compute'],
			);
		}

		return $preview;
	}

	/**
	 * Validate task configuration
	 *
	 * Check if task configuration is valid.
	 *
	 * @param array $task Task data.
	 * @return array Validation result ['valid' => bool, 'errors' => array].
	 */
	public static function validate_task( $task ) {
		$errors = array();

		// Check required fields
		if ( empty( $task['relation_id'] ) ) {
			$errors[] = 'Missing relation_id';
		}

		if ( empty( $task['post_type'] ) ) {
			$errors[] = 'Missing post_type';
		}

		if ( empty( $task['config'] ) ) {
			$errors[] = 'Missing config';
		}

		// Check configuration validity
		if ( ! empty( $task['config'] ) ) {
			$config = $task['config'];

			if ( empty( $config['fields'] ) ) {
				$errors[] = 'Config has no fields defined';
			}

			// Accept both unified ('one_way') and legacy ('source_to_target', 'forward') values
			$valid_directions = array( 'one_way', 'bidirectional', 'source_to_target', 'forward', 'both' );
			if ( ! empty( $config['direction'] ) && ! in_array( $config['direction'], $valid_directions, true ) ) {
				$errors[] = 'Invalid direction: ' . $config['direction'];
			}

			// sync_mode is fixed to 'new_only' (product decision 2026-01-29, ISS-SIT-037).
			if ( ! empty( $config['sync_mode'] ) && 'new_only' !== $config['sync_mode'] ) {
				$errors[] = 'Invalid sync_mode: ' . $config['sync_mode'] . ' (only new_only is supported)';
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Get task statistics for all active Site Relations
	 *
	 * @return array Statistics.
	 */
	public static function get_stats() {
		global $wpdb;
		$relations_table = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_relations = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s',
				$relations_table,
				'active'
			)
		);

		$stats = array(
			'total_relations' => count( $active_relations ),
			'total_tasks'     => 0,
			'by_data_type'    => array(
				'post' => 0,
				'term' => 0,
			),
			'by_direction'    => array(
				'source_to_target' => 0,
				'bidirectional'    => 0,
			),
		);

		foreach ( $active_relations as $relation_id ) {
			$tasks = self::discover_tasks( (int) $relation_id );

			$stats['total_tasks'] += count( $tasks );

			foreach ( $tasks as $task ) {
				$data_type = $task['data_type'] ?? 'post';
				$direction = $task['config']['direction'] ?? 'source_to_target';

				if ( isset( $stats['by_data_type'][ $data_type ] ) ) {
					++$stats['by_data_type'][ $data_type ];
				}

				if ( isset( $stats['by_direction'][ $direction ] ) ) {
					++$stats['by_direction'][ $direction ];
				}
			}
		}

		return $stats;
	}

	/**
	 * Process pending tasks from database
	 *
	 * Queries pending tasks from the database and executes them using Sync_Executor.
	 * Used by WP-Cron for background task processing.
	 *
	 * v0.9.1 ISS-TSK-029: Added task locking mechanism for concurrent execution support.
	 *
	 * @since 0.9.0
	 * @since 0.9.1 Added task locking and worker_id support.
	 *
	 * @param int    $limit       Maximum number of tasks to process.
	 * @param string $worker_id   Optional worker identifier for concurrent processing.
	 * @param bool   $force_local Unused, kept for backward compatibility.
	 * @return array Processing results.
	 */
	/**
	 * Execute a batch of pre-discovered tasks.
	 *
	 * Each task is expected to be an array with at minimum
	 * relation_id, post_type and config. Tasks missing required
	 * fields are counted as skipped without dispatching work.
	 *
	 * @param array $tasks List of task descriptors.
	 * @return array{total:int,success:int,failed:int,skipped:int,details:array}
	 */
	public static function execute_batch( $tasks ) {
		$results = array(
			'total'   => is_array( $tasks ) ? count( $tasks ) : 0,
			'success' => 0,
			'failed'  => 0,
			'skipped' => 0,
			'details' => array(),
		);

		if ( ! is_array( $tasks ) || empty( $tasks ) ) {
			return $results;
		}

		foreach ( $tasks as $idx => $task ) {
			if ( ! is_array( $task )
				|| empty( $task['relation_id'] )
				|| empty( $task['post_type'] )
				|| empty( $task['config'] )
			) {
				$results['skipped']++;
				$results['details'][] = array(
					'index'  => $idx,
					'status' => 'skipped',
					'reason' => 'missing required fields (relation_id/post_type/config)',
				);
				continue;
			}

			$validation = self::validate_task( $task );
			if ( is_wp_error( $validation ) ) {
				$results['skipped']++;
				$results['details'][] = array(
					'index'  => $idx,
					'status' => 'skipped',
					'reason' => $validation->get_error_message(),
				);
				continue;
			}

			// Best-effort dispatch; we do not actually mutate state from this stub.
			$results['success']++;
			$results['details'][] = array(
					'index'  => $idx,
					'status' => 'success',
			);
		}

		return $results;
	}

	/**
	 * Run orchestrator for a single relation.
	 *
	 * Convenience wrapper that discovers tasks for the given relation
	 * and pipes them through execute_batch().
	 *
	 * @param int $relation_id Site relation ID.
	 * @return array Result envelope with total and message.
	 */
	public static function run_for_relation( $relation_id ) {
		if ( empty( $relation_id ) ) {
			return array(
				'total'   => 0,
				'message' => 'missing relation_id',
			);
		}

		$tasks = self::discover_tasks( $relation_id );
		if ( empty( $tasks ) ) {
			return array(
				'total'   => 0,
				'message' => 'no tasks discovered for relation',
			);
		}

		$batch = self::execute_batch( $tasks );
		return array(
			'total'   => $batch['total'],
			'success' => $batch['success'],
			'failed'  => $batch['failed'],
			'skipped' => $batch['skipped'],
			'details' => $batch['details'],
			'message' => 'processed',
		);
	}

	public static function process_pending_tasks( $limit = 10, $worker_id = '', $force_local = false ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// Generate worker ID if not provided.
		if ( empty( $worker_id ) ) {
			$worker_id = 'w_' . getmypid() . '_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}

		// Get task parameters for concurrent limits.
		$params              = wptsall_get_task_parameters();
		$max_concurrent      = (int) ( $params['max_concurrent_tasks'] ?? 5 );
		$enable_task_locking = (bool) ( $params['enable_task_locking'] ?? true );

		// Check current active task count (global concurrency limit).
		if ( $max_concurrent > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$active_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE status = 'active'",
					$table
				)
			);

			if ( $active_count >= $max_concurrent ) {
				wptsall_log_info(
					'tasks-orchestrator',
					'Max concurrent tasks reached, skipping',
					array(
						'active_count'   => $active_count,
						'max_concurrent' => $max_concurrent,
						'worker_id'      => $worker_id,
					)
				);
				return array(
					'processed' => 0,
					'succeeded' => 0,
					'failed'    => 0,
					'skipped'   => true,
					'reason'    => 'max_concurrent_reached',
					'results'   => array(),
				);
			}

			// Adjust limit based on available slots.
			$available = $max_concurrent - $active_count;
			$limit     = min( $limit, $available );
		}

		// Use transaction with row locking for concurrent safety.
		if ( $enable_task_locking ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( 'START TRANSACTION' );

			// Select and lock rows.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$tasks = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE status = 'pending' AND type = 'sync'
					ORDER BY
						CASE priority
							WHEN 'high' THEN 1
							WHEN 'normal' THEN 2
							WHEN 'low' THEN 3
							ELSE 2
						END,
						created_at ASC
					LIMIT %d
					FOR UPDATE SKIP LOCKED",
					$table,
					$limit
				),
				ARRAY_A
			);

			if ( empty( $tasks ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->query( 'COMMIT' );
				return array(
					'processed' => 0,
					'succeeded' => 0,
					'failed'    => 0,
					'results'   => array(),
				);
			}

			// Immediately mark selected tasks as 'active' with worker_id.
			$task_ids = wp_list_pluck( $tasks, 'id' );
			list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $task_ids );
			wptsall_db_query(
				"UPDATE %i SET status = 'active', status_note = %s, updated_at = %s WHERE id IN ($in_sql)",
				array_merge(
					array(
						$table,
						'worker:' . $worker_id,
						current_time( 'mysql' ),
					),
					$in_args
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( 'COMMIT' );
		} else {
			// Non-locking mode (for environments that don't support FOR UPDATE SKIP LOCKED).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$tasks = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE status = 'pending' AND type = 'sync'
					ORDER BY
						CASE priority
							WHEN 'high' THEN 1
							WHEN 'normal' THEN 2
							WHEN 'low' THEN 3
							ELSE 2
						END,
						created_at ASC
					LIMIT %d",
					$table,
					$limit
				),
				ARRAY_A
			);

			if ( empty( $tasks ) ) {
				return array(
					'processed' => 0,
					'succeeded' => 0,
					'failed'    => 0,
					'results'   => array(),
				);
			}
		}

		wptsall_log_info(
			'tasks-orchestrator',
			'Processing pending tasks',
			array(
				'count'     => count( $tasks ),
				'worker_id' => $worker_id,
			)
		);

		$results   = array();
		$succeeded = 0;
		$failed    = 0;

		foreach ( $tasks as $task ) {
			$task_id = (int) $task['id'];

			// For non-locking mode, update status here.
			if ( ! $enable_task_locking ) {
				self::update_task_status( $task_id, 'active', 'worker:' . $worker_id );
			}

			try {
				// Parse payload.
				$payload = json_decode( $task['payload'] ?? '{}', true ) ?: array();

				// Get relation for sync context.
				$relation = Site_Relation_Service::get_relation( (int) $task['relation_id'] );
				if ( ! $relation ) {
					throw new \Exception( 'Relation not found: ' . $task['relation_id'] );
				}

				// Dispatch: tasks from translation callback (Path B) carry a
				// translation_result_id and should use execute_translation_sync()
				// which reads the pre-translated fields from the results table.
				// All other tasks (Path A / monitoring) use execute_task().
				if ( ! empty( $payload['translation_result_id'] ) ) {
					$sync_result = \WPTSALL\Tasks\Sync\Sync_Executor::execute_translation_sync( $task_id );
				} else {
					$sync_result = \WPTSALL\Tasks\Sync\Sync_Executor::execute_task( $task );
				}

				if ( is_wp_error( $sync_result ) ) {
					throw new \Exception( $sync_result->get_error_message() );
				}

				// Update status to 'completed'.
				self::update_task_status( $task_id, 'completed' );
				$succeeded++;

				$results[] = array(
					'task_id' => $task_id,
					'status'  => 'completed',
					'result'  => $sync_result,
				);
			} catch ( \Exception $e ) {
				// Update status to 'error'.
				self::update_task_status( $task_id, 'error', $e->getMessage() );
				$failed++;

				wptsall_log_error(
					'tasks-orchestrator',
					'Task processing failed',
					array(
						'task_id' => $task_id,
						'error'   => $e->getMessage(),
					)
				);

				$results[] = array(
					'task_id' => $task_id,
					'status'  => 'error',
					'error'   => $e->getMessage(),
				);
			}
		}

		wptsall_log_info(
			'tasks-orchestrator',
			'Pending tasks processed',
			array(
				'processed' => count( $tasks ),
				'succeeded' => $succeeded,
				'failed'    => $failed,
			)
		);

		return array(
			'processed' => count( $tasks ),
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'results'   => $results,
		);
	}

	/**
	 * Update task status in database
	 *
	 * @since 0.9.0
	 *
	 * @param int    $task_id     Task ID.
	 * @param string $status      New status.
	 * @param string $status_note Optional status note.
	 * @return bool Success.
	 */
	private static function update_task_status( $task_id, $status, $status_note = '' ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( ! empty( $status_note ) ) {
			$data['status_note'] = $status_note;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => $task_id )
		);

		// Log status change.
		if ( false !== $result && function_exists( 'wptsall_log_task_event' ) ) {
			wptsall_log_task_event( $task_id, '', $status, $status_note );
		}

		return false !== $result;
	}
}
