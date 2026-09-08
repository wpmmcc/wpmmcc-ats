<?php
/**
 * WPTSALL Automation and Cron Scheduling
 *
 * Implements advanced cron scheduling, task automation, and priority queue.
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Get task parameters for cron scheduling.
 *
 * @return array
 */
function wptsall_get_cron_parameters() {
	// Use function from admin page if available.
	if ( function_exists( 'wptsall_get_task_parameters' ) ) {
		return wptsall_get_task_parameters();
	}

	// Fallback to reading directly from option.
	$defaults = array(
		'high_priority_interval'   => 5,
		'normal_priority_interval' => 15,
		'low_priority_interval'    => 30,
		'retry_interval'           => 60,
		'high_priority_batch'      => 10,
		'normal_priority_batch'    => 20,
		'low_priority_batch'       => 5,
		'monitoring_batch'         => 50,
		'max_retry_count'          => 3,
		'retry_delay_1'            => 5,
		'retry_delay_2'            => 15,
		'retry_delay_3'            => 60,
		'cleanup_completed_days'   => 7,
		'cleanup_failed_days'      => 30,
		'task_timeout'             => 300,
		'enable_task_locking'      => true,
	);

	$saved = get_option( 'wptsall_task_parameters', array() );
	return wp_parse_args( $saved, $defaults );
}

/**
 * Register custom cron schedules.
 *
 * @param array $schedules Existing schedules.
 * @return array Modified schedules.
 */
function wptsall_add_cron_schedules( $schedules ) {
	$params = wptsall_get_cron_parameters();

	// High priority interval (default 5 minutes).
	$high_interval = max( 1, intval( $params['high_priority_interval'] ?? 5 ) );
	$schedules[ 'wptsall_every_' . $high_interval . '_minutes' ] = array(
		'interval' => $high_interval * MINUTE_IN_SECONDS,
		/* translators: %d: Number of minutes */
		'display'  => sprintf( __( 'Every %d minutes', 'wpmmcc-ats' ), $high_interval ),
	);

	// Normal priority interval (default 15 minutes).
	$normal_interval = max( 5, intval( $params['normal_priority_interval'] ?? 15 ) );
	$schedules[ 'wptsall_every_' . $normal_interval . '_minutes' ] = array(
		'interval' => $normal_interval * MINUTE_IN_SECONDS,
		/* translators: %d: Number of minutes */
		'display'  => sprintf( __( 'Every %d minutes', 'wpmmcc-ats' ), $normal_interval ),
	);

	// Low priority interval (default 30 minutes).
	$low_interval = max( 10, intval( $params['low_priority_interval'] ?? 30 ) );
	$schedules[ 'wptsall_every_' . $low_interval . '_minutes' ] = array(
		'interval' => $low_interval * MINUTE_IN_SECONDS,
		/* translators: %d: Number of minutes */
		'display'  => sprintf( __( 'Every %d minutes', 'wpmmcc-ats' ), $low_interval ),
	);

	// Retry interval (default 60 minutes).
	$retry_interval = max( 30, intval( $params['retry_interval'] ?? 60 ) );
	$schedules[ 'wptsall_every_' . $retry_interval . '_minutes' ] = array(
		'interval' => $retry_interval * MINUTE_IN_SECONDS,
		/* translators: %d: Number of minutes */
		'display'  => sprintf( __( 'Every %d minutes', 'wpmmcc-ats' ), $retry_interval ),
	);

	// Legacy schedules for backward compatibility.
	// v0.9.0+: Some code uses 'wptsall_every_minute' as an alias for a 60s recurrence.
	if ( ! isset( $schedules['wptsall_every_minute'] ) ) {
		$schedules['wptsall_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every 1 minute', 'wpmmcc-ats' ),
		);
	}

	if ( ! isset( $schedules['wptsall_every_5_minutes'] ) ) {
		$schedules['wptsall_every_5_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'wpmmcc-ats' ),
		);
	}

	if ( ! isset( $schedules['wptsall_every_15_minutes'] ) ) {
		$schedules['wptsall_every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'wpmmcc-ats' ),
		);
	}

	if ( ! isset( $schedules['wptsall_every_30_minutes'] ) ) {
		$schedules['wptsall_every_30_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes', 'wpmmcc-ats' ),
		);
	}

	// Every 2 hours - for cleanup tasks.
	$schedules['wptsall_every_2_hours'] = array(
		'interval' => 2 * HOUR_IN_SECONDS,
		'display'  => __( 'Every 2 hours', 'wpmmcc-ats' ),
	);

	return $schedules;
}
add_filter( 'cron_schedules', 'wptsall_add_cron_schedules' );

/**
 * Setup cron jobs on activation.
 */
function wptsall_setup_cron_jobs() {
	$params = wptsall_get_cron_parameters();

	$retry_interval  = max( 30, intval( $params['retry_interval'] ?? 60 ) );

	// Retry failed tasks.
	if ( ! wp_next_scheduled( 'wptsall_retry_failed_tasks' ) ) {
		wp_schedule_event( time(), 'wptsall_every_' . $retry_interval . '_minutes', 'wptsall_retry_failed_tasks' );
	}

	// Cleanup old data - daily.
	if ( ! wp_next_scheduled( 'wptsall_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'wptsall_daily_cleanup' );
	}

	// Weekly maintenance - weekly.
	if ( ! wp_next_scheduled( 'wptsall_weekly_maintenance' ) ) {
		wp_schedule_event( time(), 'weekly', 'wptsall_weekly_maintenance' );
	}

	// Process monitoring tasks (i18n scan recovery) - every 15 minutes.
	if ( ! wp_next_scheduled( 'wptsall_process_monitoring_tasks' ) ) {
		wp_schedule_event( time(), 'wptsall_every_15_minutes', 'wptsall_process_monitoring_tasks' );
	}
}
add_action( 'wptsall_activate', 'wptsall_setup_cron_jobs' );

/**
 * Reschedule cron jobs when task parameters change.
 *
 * @param array $params New parameters.
 */
function wptsall_reschedule_cron_jobs( $params ) {
	// Clear existing cron jobs.
	wptsall_clear_cron_jobs();

	// Re-register cron schedules.
	add_filter( 'cron_schedules', 'wptsall_add_cron_schedules' );

	// Re-setup cron jobs with new intervals.
	$high_interval   = max( 1, intval( $params['high_priority_interval'] ?? 5 ) );
	$normal_interval = max( 5, intval( $params['normal_priority_interval'] ?? 15 ) );
	$low_interval    = max( 10, intval( $params['low_priority_interval'] ?? 30 ) );
	$retry_interval  = max( 30, intval( $params['retry_interval'] ?? 60 ) );

	wp_schedule_event( time(), 'wptsall_every_' . $retry_interval . '_minutes', 'wptsall_retry_failed_tasks' );

	// These don't change.
	wp_schedule_event( time(), 'daily', 'wptsall_daily_cleanup' );
	wp_schedule_event( time(), 'weekly', 'wptsall_weekly_maintenance' );
	wp_schedule_event( time(), 'wptsall_every_15_minutes', 'wptsall_process_monitoring_tasks' );

	wptsall_log( 'cron', 'info', 'Cron jobs rescheduled', array(
		'high_interval'   => $high_interval,
		'normal_interval' => $normal_interval,
		'low_interval'    => $low_interval,
		'retry_interval'  => $retry_interval,
	) );
}

/**
 * Clear cron jobs on deactivation.
 */
function wptsall_clear_cron_jobs() {
	wp_clear_scheduled_hook( 'wptsall_retry_failed_tasks' );
	wp_clear_scheduled_hook( 'wptsall_daily_cleanup' );
	wp_clear_scheduled_hook( 'wptsall_weekly_maintenance' );
	wp_clear_scheduled_hook( 'wptsall_process_monitoring_tasks' );
}
// Deactivation cleanup is handled centrally by Plugin_Lifecycle::deactivate().

/**
 * Check if a task is locked.
 *
 * @param int $task_id Task ID.
 * @return bool
 */
/**
 * Lock a task.
 *
 * @param int $task_id Task ID.
 * @param int $timeout Lock timeout in seconds.
 */
/**
 * Unlock a task.
 *
 * @param int $task_id Task ID.
 */
/**
 * Recover tasks stuck in processing/active state.
 *
 * When the Client crashes after claiming a task, the task remains in
 * processing/active status indefinitely. This function resets tasks
 * that have been in those states longer than the claim lease timeout
 * back to pending so they can be picked up again.
 *
 * @since 1.1.0
 */
function wptsall_recover_stuck_tasks() {
	global $wpdb;
	$table = wptsall_table( 'tasks' );

	// Match DEFAULT_CLIENT_CLAIM_LEASE_SECONDS from Client_Tasks_REST_Controller.
	$lease_timeout        = 600; // 10 minutes.
	$max_recovery_count   = (int) apply_filters( 'wptsall_max_stuck_recovery_count', 3 );
	$now_mysql            = current_time( 'mysql' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$stuck_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, meta
			 FROM %i
			 WHERE status IN ('active', 'processing')
			 AND type != 'monitoring'
			 AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)",
			$table,
			$lease_timeout
		),
		ARRAY_A
	);

	if ( empty( $stuck_rows ) ) {
		return;
	}

	$recovered_count = 0;
	$dead_letter_count = 0;

	foreach ( $stuck_rows as $row ) {
		$task_id   = (int) ( $row['id'] ?? 0 );
		$meta_data = json_decode( (string) ( $row['meta'] ?? '' ), true );
		if ( ! is_array( $meta_data ) ) {
			$meta_data = array();
		}

		$recovery_count = (int) ( $meta_data['recovery_count'] ?? 0 );
		$recovery_count++;
		$meta_data['recovery_count'] = $recovery_count;

		if ( $recovery_count > $max_recovery_count ) {
			// Dead-letter: too many recoveries, move to failed.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'      => 'failed',
					'status_note' => sprintf(
						'Dead-letter: stuck %d times (max %d), moved to failed at %s',
						$recovery_count,
						$max_recovery_count,
						$now_mysql
					),
					'meta'        => wp_json_encode( $meta_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'updated_at'  => $now_mysql,
				),
				array( 'id' => $task_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			$dead_letter_count++;
		} else {
			// Recover: reset to pending for re-processing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'      => 'pending',
					'status_note' => sprintf(
						'Auto-recovered from stuck (recovery #%d/%d) at %s',
						$recovery_count,
						$max_recovery_count,
						$now_mysql
					),
					'meta'        => wp_json_encode( $meta_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'updated_at'  => $now_mysql,
				),
				array( 'id' => $task_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			$recovered_count++;
		}
	}

	if ( $recovered_count > 0 || $dead_letter_count > 0 ) {
		wptsall_log( 'cron', 'info', 'Stuck task recovery completed', array(
			'total_stuck'      => count( $stuck_rows ),
			'recovered'        => $recovered_count,
			'dead_lettered'    => $dead_letter_count,
			'lease_timeout'    => $lease_timeout,
			'max_recovery'     => $max_recovery_count,
		) );
	}
}
add_action( 'wptsall_retry_failed_tasks', 'wptsall_recover_stuck_tasks', 5 );

/**
 * Retry failed tasks with exponential backoff.
 */
function wptsall_retry_failed_tasks() {
	global $wpdb;
	$table = wptsall_table( 'tasks' );

	$max_retries = apply_filters( 'wptsall_max_retries', 3 );
	$retry_limit = apply_filters( 'wptsall_retry_batch_size', 10 );

	// M21: explicit column list avoids loading payload LONGTEXT in batch queries.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$tasks = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id FROM %i
			WHERE status = 'retry'
			AND retry_count < %d
			AND (
				retry_at IS NULL
				OR retry_at <= NOW()
			)
			ORDER BY priority DESC, retry_count ASC
			LIMIT %d",
			$table,
			$max_retries,
			$retry_limit
		),
		ARRAY_A
	);

	$retried = 0;

	foreach ( $tasks as $task ) {
		// Reset status to 'pending' so the client can pick up the task again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'     => 'pending',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => intval( $task['id'] ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$retried++;
	}

	// Mark tasks that exceeded max retries as failed.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE %i SET status = 'failed', status_note = CONCAT(status_note, ' - Exceeded maximum retry count')
			WHERE status = 'retry'
			AND retry_count >= %d",
			$table,
			$max_retries
		)
	);

	wptsall_log( 'cron', 'info', 'Retried failed tasks', array(
		'count' => $retried,
	) );
}
add_action( 'wptsall_retry_failed_tasks', 'wptsall_retry_failed_tasks' );

/**
 * Daily cleanup routine.
 *
 * Performs daily maintenance tasks:
 * - Cleanup old sync metadata (30 days)
 * - Cleanup old completed tasks (60 days)
 * - Cleanup old task logs (configurable, default 30 days)
 * - Rotate task logs by count (configurable, default 10000 records)
 * - Invalidate stale cache
 *
 * @since 0.5.0
 * @updated 0.8.0 ISS-TSK-006 Added configurable log cleanup and rotation.
 */
function wptsall_daily_cleanup() {
	$stats = array();

	// Cleanup old completed tasks.
	$deleted_tasks = wptsall_cleanup_old_completed_tasks( 60 );
	$stats['tasks_deleted'] = $deleted_tasks;

	// Cleanup old logs (uses configurable retention days).
	$deleted_logs = wptsall_cleanup_old_logs();
	$stats['logs_deleted'] = $deleted_logs;
	$stats['log_retention_days'] = wptsall_get_task_log_retention_days();

	// Rotate logs by count limit (v0.8.0 ISS-TSK-006).
	$rotated_logs = wptsall_rotate_task_logs();
	$stats['logs_rotated'] = $rotated_logs;
	$stats['log_max_count'] = wptsall_get_task_log_max_count();

	// Invalidate stale cache.
	wptsall_cache_invalidate_all();

	wptsall_log( 'cron', 'info', 'Daily cleanup completed', $stats );

	do_action( 'wptsall_daily_cleanup_done', $stats );
}
add_action( 'wptsall_daily_cleanup', 'wptsall_daily_cleanup' );

/**
 * Weekly maintenance routine.
 */
function wptsall_weekly_maintenance() {
	$stats = array();

	// Optimize database tables.
	$optimized = wptsall_optimize_database_tables();
	$stats['tables_optimized'] = $optimized;

	// Clear all cache.
	wptsall_cache_invalidate_all();

	// Gradually heal mapping↔meta identity drift (bounded batch).
	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
		$heal = \WPTSALL\Sites\Services\Translation_Identity::heal_batch(
			array(
				'dry_run'    => false,
				'limit'      => 500,
				'sample_max' => 0,
			)
		);
		$stats['identity_heal'] = array(
			'scanned'  => (int) ( $heal['scanned'] ?? 0 ),
			'diverged' => (int) ( $heal['diverged'] ?? 0 ),
			'healed'   => (int) ( $heal['healed'] ?? 0 ),
			'failed'   => (int) ( $heal['failed'] ?? 0 ),
		);
	}

	wptsall_log( 'cron', 'info', 'Weekly maintenance completed', $stats );

	do_action( 'wptsall_weekly_maintenance_done', $stats );
}
add_action( 'wptsall_weekly_maintenance', 'wptsall_weekly_maintenance' );

/**
 * Get tasks by priority.
 *
 * @param string $priority Priority level (high|normal|low).
 * @param string $status   Task status.
 * @param int    $limit    Number of tasks to retrieve.
 * @return array Array of task records.
 */
/**
 * Set task priority.
 *
 * @param int    $task_id  Task ID.
 * @param string $priority Priority level (high|normal|low).
 * @return bool True on success.
 */
/**
 * Calculate retry delay with exponential backoff.
 *
 * @param int $retry_count Current retry count.
 * @return int Delay in seconds.
 */
function wptsall_calculate_retry_delay( $retry_count ) {
	// Exponential backoff: 5 min, 15 min, 1 hour.
	$delays = array(
		0 => 5 * MINUTE_IN_SECONDS,   // First retry after 5 minutes.
		1 => 15 * MINUTE_IN_SECONDS,  // Second retry after 15 minutes.
		2 => HOUR_IN_SECONDS,         // Third retry after 1 hour.
	);

	return $delays[ $retry_count ] ?? HOUR_IN_SECONDS;
}

/**
 * Schedule task for retry with backoff.
 *
 * @param int    $task_id Task ID.
 * @param string $reason  Reason for retry.
 * @return bool True on success.
 */
/**
 * Cleanup old completed tasks.
 *
 * @param int $days Number of days to keep.
 * @return int Number of deleted tasks.
 */
function wptsall_cleanup_old_completed_tasks( $days ) {
	global $wpdb;
	$table = wptsall_table( 'tasks' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM %i WHERE status = 'completed' AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$table,
			$days
		)
	);

	if ( $deleted ) {
		wptsall_cache_invalidate_tasks();
	}

	return intval( $deleted );
}

/**
 * Cleanup old logs.
 *
 * @param int $days Number of days to keep. Default from settings or 30.
 * @return int Number of deleted logs.
 */
function wptsall_cleanup_old_logs( $days = null ) {
	global $wpdb;
	$table = wptsall_table( 'task_logs' );

	// Use configured retention days if not specified.
	if ( null === $days ) {
		$days = wptsall_get_task_log_retention_days();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$table,
			$days
		)
	);

	return intval( $deleted );
}

/**
 * Rotate task logs by count limit.
 *
 * Keeps only the most recent N records.
 *
 * @since 0.8.0 ISS-TSK-006
 * @param int $max_count Maximum number of logs to keep. Default from settings or 10000.
 * @return int Number of deleted logs.
 */
function wptsall_rotate_task_logs( $max_count = null ) {
	global $wpdb;
	$table = wptsall_table( 'task_logs' );

	// Use configured max count if not specified.
	if ( null === $max_count ) {
		$max_count = wptsall_get_task_log_max_count();
	}

	// Get current count.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$current_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

	if ( $current_count <= $max_count ) {
		return 0;
	}

	$to_delete = $current_count - $max_count;

	// Delete oldest records.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM %i ORDER BY created_at ASC LIMIT %d",
			$table,
			$to_delete
		)
	);

	return intval( $deleted );
}

/**
 * Get task log retention days from settings.
 *
 * @since 0.8.0 ISS-TSK-006
 * @return int Retention days (default 30).
 */
function wptsall_get_task_log_retention_days() {
	$settings = get_option( 'wptsall_task_settings', array() );
	return (int) ( $settings['log_retention_days'] ?? 30 );
}

/**
 * Get task log max count from settings.
 *
 * @since 0.8.0 ISS-TSK-006
 * @return int Max log count (default 10000).
 */
function wptsall_get_task_log_max_count() {
	$settings = get_option( 'wptsall_task_settings', array() );
	return (int) ( $settings['log_max_count'] ?? 10000 );
}

/**
 * Query task logs.
 *
 * @since 0.8.0 ISS-TSK-006
 * @param array $args Query arguments.
 *                    - task_id: int Filter by task ID.
 *                    - status_to: string Filter by target status.
 *                    - start_date: string Filter logs after this date (Y-m-d H:i:s).
 *                    - end_date: string Filter logs before this date (Y-m-d H:i:s).
 *                    - limit: int Number of records to return (default 50).
 *                    - offset: int Offset for pagination (default 0).
 * @return array Array of log records.
 */
function wptsall_get_task_logs( $args = array() ) {
	global $wpdb;
	$table = wptsall_table( 'task_logs' );

	$defaults = array(
		'task_id'    => null,
		'status_to'  => null,
		'start_date' => null,
		'end_date'   => null,
		'limit'      => 50,
		'offset'     => 0,
	);

	$args = wp_parse_args( $args, $defaults );

	$where = array( '1=1' );
	$values = array();

	if ( $args['task_id'] ) {
		$where[] = 'task_id = %d';
		$values[] = (int) $args['task_id'];
	}

	if ( $args['status_to'] ) {
		$where[] = 'status_to = %s';
		$values[] = sanitize_text_field( $args['status_to'] );
	}

	if ( $args['start_date'] ) {
		$where[] = 'created_at >= %s';
		$values[] = sanitize_text_field( $args['start_date'] );
	}

	if ( $args['end_date'] ) {
		$where[] = 'created_at <= %s';
		$values[] = sanitize_text_field( $args['end_date'] );
	}

	$where_sql = implode( ' AND ', $where );
	$limit     = (int) $args['limit'];
	$offset    = (int) $args['offset'];
	$params    = array_merge( array( $table ), $values, array( $limit, $offset ) );

	$results = wptsall_db_get_results(
		'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
		$params,
		ARRAY_A
	);

	return $results ? $results : array();
}

/**
 * Get task log count.
 *
 * @since 0.8.0 ISS-TSK-006
 * @param array $args Query arguments (same as wptsall_get_task_logs).
 * @return int Log count.
 */
function wptsall_count_task_logs( $args = array() ) {
	global $wpdb;
	$table = wptsall_table( 'task_logs' );

	$where = array( '1=1' );
	$values = array();

	if ( ! empty( $args['task_id'] ) ) {
		$where[] = 'task_id = %d';
		$values[] = (int) $args['task_id'];
	}

	if ( ! empty( $args['status_to'] ) ) {
		$where[] = 'status_to = %s';
		$values[] = sanitize_text_field( $args['status_to'] );
	}

	if ( ! empty( $args['start_date'] ) ) {
		$where[] = 'created_at >= %s';
		$values[] = sanitize_text_field( $args['start_date'] );
	}

	if ( ! empty( $args['end_date'] ) ) {
		$where[] = 'created_at <= %s';
		$values[] = sanitize_text_field( $args['end_date'] );
	}

	$where_sql = implode( ' AND ', $where );
	$params    = array_merge( array( $table ), $values );

	return (int) wptsall_db_get_var(
		'SELECT COUNT(*) FROM %i WHERE ' . $where_sql,
		$params
	);
}

/**
 * Optimize database tables.
 *
 * @return int Number of tables optimized.
 */
function wptsall_optimize_database_tables() {
	global $wpdb;

	// Only include tables that exist on all installs.
	// Deprecated tables (hooks, virtual_site_content) are omitted.
	$tables = array(
		wptsall_table( 'tasks' ),
		wptsall_table( 'task_logs' ),
		wptsall_table( 'sync_meta' ),
		wptsall_table( 'conflicts' ),
		wptsall_table( 'snapshots' ),
	);

	$optimized = 0;

	foreach ( $tables as $table ) {
		// Skip legacy/optional tables that are not present on newer installs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( 'OPTIMIZE TABLE %i', $table ) );
		if ( false !== $result ) {
			$optimized++;
		}
	}

	return $optimized;
}

/**
 * Get cron status information.
 *
 * @return array Cron status data.
 */
/**
 * Get automation statistics.
 *
 * @return array Statistics data.
 */
/**
 * Allowed cron action hooks that may be manually triggered.
 *
 * Keep in sync with uninstall.php / Plugin_Lifecycle cron hook lists.
 *
 * @since 1.9.0
 * @return string[]
 */
function wptsall_get_allowed_cron_job_hooks() {
	return array(
		'wptsall_daily_cleanup',
		'wptsall_weekly_maintenance',
		'wptsall_run_i18n_scan',
		'wptsall_process_tasks',
		'wptsall_cron_process_tasks',
		'wptsall_cleanup_old_tasks',
		'wptsall_sync_cron',
		'wptsall_process_high_priority_tasks',
		'wptsall_process_normal_tasks',
		'wptsall_process_low_priority_tasks',
		'wptsall_retry_failed_tasks',
		'wptsall_process_monitoring_tasks',
		'wptsall_process_pending_sync_tasks',
	);
}

/**
 * Manually trigger a cron job (for testing / admin tools).
 *
 * Only allowlisted wptsall_* cron hooks may be fired. Callers that accept
 * external input must also enforce capability + nonce checks.
 *
 * @since 1.9.0 Restricted to allowlist (WordPress.org review).
 *
 * @param string $job_name Cron job name.
 * @return bool True on success.
 */
function wptsall_trigger_cron_job( $job_name ) {
	$job_name = is_string( $job_name ) ? $job_name : '';
	if ( '' === $job_name ) {
		return false;
	}

	$allowed = wptsall_get_allowed_cron_job_hooks();
	/**
	 * Filter the allowlist of cron hooks that may be triggered manually.
	 *
	 * @since 1.9.0
	 * @param string[] $allowed Hook names.
	 */
	$allowed = apply_filters( 'wptsall_allowed_cron_job_hooks', $allowed );
	if ( ! is_array( $allowed ) || ! in_array( $job_name, $allowed, true ) ) {
		return false;
	}

	// Fire only fixed, plugin-prefixed hook literals (Plugin Check: no dynamic do_action).
	// Keep in sync with wptsall_get_allowed_cron_job_hooks().
	switch ( $job_name ) {
		case 'wptsall_daily_cleanup':
			if ( ! has_action( 'wptsall_daily_cleanup' ) ) {
				return false;
			}
			do_action( 'wptsall_daily_cleanup' );
			return true;
		case 'wptsall_weekly_maintenance':
			if ( ! has_action( 'wptsall_weekly_maintenance' ) ) {
				return false;
			}
			do_action( 'wptsall_weekly_maintenance' );
			return true;
		case 'wptsall_run_i18n_scan':
			if ( ! has_action( 'wptsall_run_i18n_scan' ) ) {
				return false;
			}
			do_action( 'wptsall_run_i18n_scan' );
			return true;
		case 'wptsall_process_tasks':
			if ( ! has_action( 'wptsall_process_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_process_tasks' );
			return true;
		case 'wptsall_cron_process_tasks':
			if ( ! has_action( 'wptsall_cron_process_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_cron_process_tasks' );
			return true;
		case 'wptsall_cleanup_old_tasks':
			if ( ! has_action( 'wptsall_cleanup_old_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_cleanup_old_tasks' );
			return true;
		case 'wptsall_sync_cron':
			if ( ! has_action( 'wptsall_sync_cron' ) ) {
				return false;
			}
			do_action( 'wptsall_sync_cron' );
			return true;
		case 'wptsall_process_high_priority_tasks':
			if ( ! has_action( 'wptsall_process_high_priority_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_process_high_priority_tasks' );
			return true;
		case 'wptsall_process_normal_tasks':
			if ( ! has_action( 'wptsall_process_normal_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_process_normal_tasks' );
			return true;
		case 'wptsall_process_low_priority_tasks':
			if ( ! has_action( 'wptsall_process_low_priority_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_process_low_priority_tasks' );
			return true;
		case 'wptsall_retry_failed_tasks':
			if ( ! has_action( 'wptsall_retry_failed_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_retry_failed_tasks' );
			return true;
		case 'wptsall_process_monitoring_tasks':
			if ( ! has_action( 'wptsall_process_monitoring_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_process_monitoring_tasks' );
			return true;
		case 'wptsall_process_pending_sync_tasks':
			if ( ! has_action( 'wptsall_process_pending_sync_tasks' ) ) {
				return false;
			}
			do_action( 'wptsall_process_pending_sync_tasks' );
			return true;
		default:
			return false;
	}
}
