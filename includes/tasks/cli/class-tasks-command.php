<?php
/**
 * WP-CLI Commands for Tasks Module
 *
 * Provides command-line interface for task processing with support for:
 * - Multiple worker concurrent execution
 * - Monitoring task processing
 * - Queue status and statistics
 *
 * @package WPTSALL\Tasks\CLI
 * @since 0.9.1 ISS-TSK-029
 */

namespace WPTSALL\Tasks\CLI;

use WPTSALL\Tasks\Services\Task_Orchestrator;
use WPTSALL\Tasks\Services\Monitoring_Task_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WPTSALL sync tasks from the command line.
 *
 * ## EXAMPLES
 *
 *     # Process pending sync tasks
 *     $ wp wptsall tasks process
 *
 *     # Process with multiple workers
 *     $ wp wptsall tasks process --workers=4
 *
 *     # Process tasks for specific relation
 *     $ wp wptsall tasks process --relation=123
 *
 *     # Run monitoring check for all active relations
 *     $ wp wptsall tasks monitor
 *
 *     # Show queue status
 *     $ wp wptsall tasks status
 */
class Tasks_Command extends \WP_CLI_Command {

	/**
	 * Process pending sync tasks from the queue.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of tasks to process per worker.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--workers=<number>]
	 * : Number of parallel workers (forks child processes).
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--relation=<id>]
	 * : Only process tasks for specific relation ID.
	 *
	 * [--continuous]
	 * : Keep running and check for new tasks (daemon mode).
	 *
	 * [--interval=<seconds>]
	 * : Interval between checks in continuous mode.
	 * ---
	 * default: 60
	 * ---
	 *
	 * [--local]
	 * : Force local WP executor (legacy/debug path). By default tasks are executed by external client runtime.
	 *
	 * ## EXAMPLES
	 *
	 *     # Process up to 10 pending tasks
	 *     $ wp wptsall tasks process
	 *
	 *     # Process with 4 parallel workers
	 *     $ wp wptsall tasks process --workers=4
	 *
	 *     # Run continuously (daemon mode)
	 *     $ wp wptsall tasks process --continuous --interval=30
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function process( $args, $assoc_args ) {
		$limit      = (int) ( $assoc_args['limit'] ?? 10 );
		$workers    = (int) ( $assoc_args['workers'] ?? 1 );
		$relation   = isset( $assoc_args['relation'] ) ? (int) $assoc_args['relation'] : null;
		$continuous = isset( $assoc_args['continuous'] );
		$interval   = (int) ( $assoc_args['interval'] ?? 60 );
		$force_local = isset( $assoc_args['local'] );

		// Validate workers count.
		$workers = max( 1, min( $workers, 10 ) ); // Limit to 1-10 workers.

		if ( $continuous ) {
			\WP_CLI::log( "Starting daemon mode with {$workers} worker(s), checking every {$interval}s..." );
			\WP_CLI::log( 'Press Ctrl+C to stop.' );
		}

		do {
			if ( $workers > 1 && function_exists( 'pcntl_fork' ) ) {
				$this->process_parallel( $workers, $limit, $relation, $force_local );
			} else {
				if ( $workers > 1 ) {
					\WP_CLI::warning( 'pcntl extension not available. Running in sequential mode.' );
				}
				$this->process_sequential( $limit, $relation, $force_local );
			}

			if ( $continuous ) {
				\WP_CLI::log( "Sleeping for {$interval} seconds..." );
				sleep( $interval );
			}
		} while ( $continuous );
	}

	/**
	 * Process tasks sequentially (single worker).
	 *
	 * @param int      $limit    Maximum tasks per run.
	 * @param int|null $relation Optional relation ID filter.
	 */
	private function process_sequential( $limit, $relation = null, $force_local = false ) {
		$worker_id = 'cli_' . getmypid();

		\WP_CLI::log( "Processing tasks (worker: {$worker_id}, limit: {$limit})..." );

		$result = Task_Orchestrator::process_pending_tasks( $limit, $worker_id, (bool) $force_local );

		$this->display_results( $result );
	}

	/**
	 * Process tasks in parallel using fork.
	 *
	 * @param int      $workers  Number of workers.
	 * @param int      $limit    Maximum tasks per worker.
	 * @param int|null $relation Optional relation ID filter.
	 */
	private function process_parallel( $workers, $limit, $relation = null, $force_local = false ) {
		\WP_CLI::log( "Starting {$workers} parallel workers..." );

		$pids = array();

		for ( $i = 0; $i < $workers; $i++ ) {
			$pid = pcntl_fork();

			if ( -1 === $pid ) {
				\WP_CLI::error( 'Failed to fork worker process.' );
			} elseif ( 0 === $pid ) {
				// Child process.
				$worker_id = 'cli_w' . $i . '_' . getmypid();

				// Reconnect to database (important after fork).
				global $wpdb;
				$wpdb->db_connect();

				$result = Task_Orchestrator::process_pending_tasks( $limit, $worker_id, (bool) $force_local );

				// Output result for child.
				$msg = sprintf(
					'Worker %d: processed=%d, succeeded=%d, failed=%d',
					$i,
					$result['processed'],
					$result['succeeded'],
					$result['failed']
				);
				echo $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				exit( 0 );
			} else {
				// Parent process.
				$pids[] = $pid;
			}
		}

		// Parent waits for all children.
		$total_results = array(
			'processed' => 0,
			'succeeded' => 0,
			'failed'    => 0,
		);

		foreach ( $pids as $pid ) {
			pcntl_waitpid( $pid, $status );
		}

		\WP_CLI::success( "All {$workers} workers completed." );
	}

	/**
	 * Run monitoring check for active relations.
	 *
	 * Triggers monitoring tasks to check for content changes.
	 *
	 * ## OPTIONS
	 *
	 * [--relation=<id>]
	 * : Only check specific relation ID.
	 *
	 * [--limit=<number>]
	 * : Maximum monitoring tasks to process.
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Check all active monitoring tasks
	 *     $ wp wptsall tasks monitor
	 *
	 *     # Check specific relation
	 *     $ wp wptsall tasks monitor --relation=123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function monitor( $args, $assoc_args ) {
		$relation_id = isset( $assoc_args['relation'] ) ? (int) $assoc_args['relation'] : null;
		$limit       = (int) ( $assoc_args['limit'] ?? 10 );

		\WP_CLI::log( 'Processing monitoring tasks...' );

		if ( $relation_id ) {
			// Process specific relation.
			$task = Monitoring_Task_Service::get_by_relation( $relation_id );

			if ( ! $task ) {
				\WP_CLI::error( "No monitoring task found for relation {$relation_id}" );
			}

			$result = Monitoring_Task_Service::process( $task['id'] );
			$this->display_monitoring_result( $result );
		} else {
			// Process all due tasks.
			$tasks = Monitoring_Task_Service::get_due_tasks( $limit );

			if ( empty( $tasks ) ) {
				\WP_CLI::log( 'No due monitoring tasks found.' );
				return;
			}

			\WP_CLI::log( sprintf( 'Found %d due monitoring tasks.', count( $tasks ) ) );

			$processed = 0;
			$errors    = 0;

			foreach ( $tasks as $task ) {
				\WP_CLI::log( sprintf( 'Processing task %d (relation %d)...', $task['id'], $task['relation_id'] ) );
				$result = Monitoring_Task_Service::process( $task['id'] );

				if ( $result['success'] ) {
					$processed++;
					\WP_CLI::log( sprintf( '  - Synced: %d items', $result['stats']['synced'] ?? 0 ) );
				} else {
					$errors++;
					\WP_CLI::warning( '  - Error: ' . ( $result['error'] ?? 'Unknown' ) );
				}
			}

			\WP_CLI::success( sprintf( 'Completed: %d processed, %d errors', $processed, $errors ) );
		}
	}

	/**
	 * Show queue status and statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show status as table
	 *     $ wp wptsall tasks status
	 *
	 *     # Show status as JSON
	 *     $ wp wptsall tasks status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		global $wpdb;

		$format = $assoc_args['format'] ?? 'table';
		$table  = wptsall_table( 'tasks' );

		// Get status counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$status_counts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) as count FROM %i GROUP BY status',
				$table
			),
			ARRAY_A
		);

		$stats = array(
			'pending'   => 0,
			'active'    => 0,
			'completed' => 0,
			'error'     => 0,
			'paused'    => 0,
			'retry'     => 0,
		);

		foreach ( $status_counts as $row ) {
			$stats[ $row['status'] ] = (int) $row['count'];
		}

		// Get type counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$type_counts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, COUNT(*) as count FROM %i GROUP BY type',
				$table
			),
			ARRAY_A
		);

		$types = array();
		foreach ( $type_counts as $row ) {
			$types[ $row['type'] ] = (int) $row['count'];
		}

		// Get recent errors.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$recent_errors = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE status = 'error' AND updated_at > %s",
				$table,
				gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
			)
		);

		// Get task parameters.
		$params = wptsall_get_task_parameters();

		if ( 'json' === $format ) {
			$output = array(
				'queue_status'   => $stats,
				'by_type'        => $types,
				'recent_errors'  => (int) $recent_errors,
				'config'         => array(
					'max_concurrent_tasks' => $params['max_concurrent_tasks'] ?? 5,
					'enable_task_locking'  => $params['enable_task_locking'] ?? true,
					'task_timeout'         => $params['task_timeout'] ?? 300,
				),
			);
			\WP_CLI::line( wp_json_encode( $output, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI::log( '=== Queue Status ===' );
			\WP_CLI::log( sprintf( 'Pending:   %d', $stats['pending'] ) );
			\WP_CLI::log( sprintf( 'Active:    %d', $stats['active'] ) );
			\WP_CLI::log( sprintf( 'Completed: %d', $stats['completed'] ) );
			\WP_CLI::log( sprintf( 'Errors:    %d', $stats['error'] ) );
			\WP_CLI::log( sprintf( 'Paused:    %d', $stats['paused'] ) );
			\WP_CLI::log( sprintf( 'Retry:     %d', $stats['retry'] ) );
			\WP_CLI::log( '' );
			\WP_CLI::log( '=== Recent Activity ===' );
			\WP_CLI::log( sprintf( 'Errors (24h): %d', $recent_errors ) );
			\WP_CLI::log( '' );
			\WP_CLI::log( '=== Configuration ===' );
			\WP_CLI::log( sprintf( 'Max Concurrent: %d', $params['max_concurrent_tasks'] ?? 5 ) );
			\WP_CLI::log( sprintf( 'Task Locking:   %s', ( $params['enable_task_locking'] ?? true ) ? 'enabled' : 'disabled' ) );
			\WP_CLI::log( sprintf( 'Task Timeout:   %ds', $params['task_timeout'] ?? 300 ) );
		}
	}

	/**
	 * Reset stuck tasks (tasks in 'active' status for too long).
	 *
	 * ## OPTIONS
	 *
	 * [--timeout=<minutes>]
	 * : Consider tasks stuck if active longer than this.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be reset without actually resetting.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reset tasks stuck for more than 30 minutes
	 *     $ wp wptsall tasks reset-stuck
	 *
	 *     # Preview what would be reset
	 *     $ wp wptsall tasks reset-stuck --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function reset_stuck( $args, $assoc_args ) {
		global $wpdb;

		$timeout = (int) ( $assoc_args['timeout'] ?? 30 );
		$dry_run = isset( $assoc_args['dry-run'] );
		$table   = wptsall_table( 'tasks' );

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$timeout} minutes" ) );

		// Find stuck tasks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stuck_tasks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, relation_id, subtype, updated_at FROM %i
				WHERE status = 'active' AND updated_at < %s",
				$table,
				$cutoff
			),
			ARRAY_A
		);

		if ( empty( $stuck_tasks ) ) {
			\WP_CLI::success( 'No stuck tasks found.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d stuck tasks (active > %d minutes):', count( $stuck_tasks ), $timeout ) );

		foreach ( $stuck_tasks as $task ) {
			\WP_CLI::log( sprintf( '  - Task %d (relation %d, type: %s, updated: %s)', $task['id'], $task['relation_id'], $task['subtype'], $task['updated_at'] ) );
		}

		if ( $dry_run ) {
			\WP_CLI::log( '(Dry run - no changes made)' );
			return;
		}

		// Reset stuck tasks to pending.
		$task_ids = wp_list_pluck( $stuck_tasks, 'id' );
		list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $task_ids );

		$updated = wptsall_db_query(
			"UPDATE %i SET status = 'pending', status_note = %s, updated_at = %s WHERE id IN ($in_sql)",
			array_merge(
				array(
					$table,
					'Reset by CLI (was stuck)',
					current_time( 'mysql' ),
				),
				$in_args
			)
		);

		\WP_CLI::success( sprintf( 'Reset %d stuck tasks to pending.', $updated ) );
	}

	/**
	 * Display processing results.
	 *
	 * @param array $result Processing result array.
	 */
	private function display_results( $result ) {
		if ( isset( $result['skipped'] ) && $result['skipped'] ) {
			\WP_CLI::warning( 'Processing skipped: ' . ( $result['reason'] ?? 'Unknown reason' ) );
			return;
		}

		if ( 0 === $result['processed'] ) {
			\WP_CLI::log( 'No pending tasks to process.' );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Processed %d tasks: %d succeeded, %d failed',
				$result['processed'],
				$result['succeeded'],
				$result['failed']
			)
		);
	}

	/**
	 * Display monitoring result.
	 *
	 * @param array $result Monitoring result array.
	 */
	private function display_monitoring_result( $result ) {
		if ( $result['success'] ) {
			$stats = $result['stats'] ?? array();
			\WP_CLI::success(
				sprintf(
					'Monitoring completed: checked=%d, synced=%d, errors=%d',
					$stats['checked'] ?? 0,
					$stats['synced'] ?? 0,
					$stats['errors'] ?? 0
				)
			);
		} else {
			\WP_CLI::error( 'Monitoring failed: ' . ( $result['error'] ?? 'Unknown error' ) );
		}
	}
}
