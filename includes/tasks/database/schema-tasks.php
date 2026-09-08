<?php
/**
 * WPTSALL Tasks Table Schema
 *
 * Task table and task log table structure definitions
 *
 * @package WPTSALL\Tasks
 * @since 0.5.0
 * @updated 0.8.0 Migrated from tasks.php to standalone schema file
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create tasks table
 *
 * Stores sync tasks, monitoring tasks, etc.
 *
 * M21 NOTE: The `payload` column is LONGTEXT and can be large (full translation
 * payloads). Batch queries that only need status/id should use explicit column
 * lists instead of SELECT *. If payload sizes become a bottleneck, consider
 * splitting payload into a separate `task_payloads` table (1:1 FK to tasks.id)
 * so that batch listing queries never touch the heavy data.
 */
function wptsall_create_tasks_table() {
	global $wpdb;
	$table           = wptsall_table( 'tasks' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		blog_id bigint(20) unsigned NOT NULL COMMENT 'Source site ID',
		target_blog bigint(20) unsigned DEFAULT 0 NOT NULL COMMENT 'Target site ID (WP subsite)',
		target_type varchar(20) DEFAULT '' NOT NULL COMMENT 'Target type: wp / virtual',
		target_identifier varchar(100) DEFAULT '' NOT NULL COMMENT 'Target identifier',
		site_id bigint(20) unsigned DEFAULT 0 NOT NULL COMMENT 'Site relation ID (deprecated, use relation_id)',
		relation_id bigint(20) unsigned DEFAULT 0 COMMENT 'Site relation ID (v0.6.0)',
		template varchar(100) DEFAULT '' NOT NULL COMMENT 'Model/plugin identifier',
		type varchar(20) DEFAULT 'sync' NOT NULL COMMENT 'Task type: monitoring / sync / translation',
		model_ids text COMMENT 'Associated model ID list (JSON array)',
		priority varchar(20) DEFAULT 'normal' NOT NULL COMMENT 'Priority: high / normal / low',
		object_type varchar(50) NOT NULL COMMENT 'Object type: post / term / user',
		subtype varchar(100) NOT NULL COMMENT 'Object subtype: post_type / taxonomy',
		object_id bigint(20) unsigned NOT NULL COMMENT 'Object ID',
		lang_from varchar(20) DEFAULT '' NOT NULL COMMENT 'Source language code',
		lang_to varchar(20) DEFAULT '' NOT NULL COMMENT 'Target language code',
		site_mode varchar(20) DEFAULT '' NOT NULL COMMENT 'Site mode',
		status varchar(20) DEFAULT 'pending' NOT NULL COMMENT 'Status: pending / processing / paused / completed / failed / retry',
		status_note text COMMENT 'Status note',
		retry_count int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Retry count',
		retry_at datetime DEFAULT NULL COMMENT 'Next retry time',
		progress longtext COMMENT 'Progress data (JSON)',
		meta longtext COMMENT 'Metadata (JSON)',
		last_check_at datetime DEFAULT NULL COMMENT 'Last check time',
		next_check_at datetime DEFAULT NULL COMMENT 'Next check time',
		payload longtext NOT NULL COMMENT 'Task data (JSON)',
		created_at datetime NOT NULL COMMENT 'Created time',
		updated_at datetime NOT NULL COMMENT 'Updated time',
		PRIMARY KEY (id),
		KEY idx_task_identity_status (site_id, template, object_type, subtype, object_id, status),
		KEY blog_object (blog_id, object_type, subtype, object_id),
		KEY status (status),
		KEY status_type_created_at (status, type, created_at),
		KEY target_blog (target_blog),
		KEY site_id (site_id),
		KEY relation_id (relation_id),
		KEY template (template),
		KEY type (type),
		KEY priority (priority),
		KEY next_check (next_check_at)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Tasks table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	wptsall_ensure_task_language_columns();

	wptsall_log_info(
		'database',
		'Tasks table created/updated',
		array( 'table' => $table )
	);
}

/**
 * Widen task language columns for locale/provider identifiers.
 *
 * @return void
 */
function wptsall_ensure_task_language_columns() {
	global $wpdb;
	$table = wptsall_table( 'tasks' );
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return;
	}

	// Older installations used VARCHAR(10), which truncates valid locale
	// identifiers (for example provider-specific values such as
	// "zh-Hant-TW") and can make callback task materialization fail. dbDelta
	// does not reliably widen existing columns on every MySQL variant, so make
	// the compatibility upgrade explicit and idempotent.
	foreach ( array( 'lang_from', 'lang_to' ) as $language_column ) {
		$column = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $language_column ),
			ARRAY_A
		);
		$type = strtolower( (string) ( $column['Type'] ?? '' ) );
		if ( preg_match( '/^varchar\((\d+)\)/', $type, $matches ) && (int) $matches[1] < 20 ) {
			$fragment = 'MODIFY COLUMN ' . $language_column . " VARCHAR(20) DEFAULT '' NOT NULL COMMENT '" . ( 'lang_from' === $language_column ? 'Source' : 'Target' ) . " language code'";
			wptsall_db_alter_table( $table, $fragment );
		}
	}
}

/**
 * Create task logs table
 *
 * Records task status change history
 */
function wptsall_create_task_logs_table() {
	global $wpdb;
	$table           = wptsall_table( 'task_logs' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		task_id bigint(20) unsigned NOT NULL COMMENT 'Associated task ID',
		status_from varchar(20) DEFAULT '' NOT NULL COMMENT 'Previous status',
		status_to varchar(20) DEFAULT '' NOT NULL COMMENT 'New status',
		note text COMMENT 'Status note',
		context longtext COMMENT 'Context data (JSON)',
		created_at datetime NOT NULL COMMENT 'Created time',
		PRIMARY KEY (id),
		KEY task_id (task_id),
		KEY status_to (status_to),
		KEY created_at (created_at)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Task status log table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log_info(
		'database',
		'Task logs table created/updated',
		array( 'table' => $table )
	);
}

/**
 * Create task items detail table
 *
 * Records specific content items processed by each task (e.g., which posts were synced)
 *
 * @since 0.8.0
 */
function wptsall_create_task_items_table() {
	global $wpdb;
	$table           = wptsall_table( 'task_items' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		task_id bigint(20) unsigned NOT NULL COMMENT 'Associated task ID',
		item_type varchar(50) NOT NULL COMMENT 'Content type: post / term / user / meta',
		source_id bigint(20) unsigned NOT NULL COMMENT 'Source content ID',
		target_id bigint(20) unsigned DEFAULT NULL COMMENT 'Target content ID (after sync)',
		status varchar(20) DEFAULT 'pending' NOT NULL COMMENT 'Status: pending / processing / completed / skipped / failed',
		error_message text COMMENT 'Error message',
		result longtext COMMENT 'Execution result (JSON)',
		started_at datetime DEFAULT NULL COMMENT 'Processing start time',
		completed_at datetime DEFAULT NULL COMMENT 'Completion time',
		created_at datetime NOT NULL COMMENT 'Created time',
		updated_at datetime NOT NULL COMMENT 'Updated time',
		PRIMARY KEY (id),
		KEY task_id (task_id),
		KEY item_type (item_type),
		KEY source_id (source_id),
		KEY target_id (target_id),
		KEY status (status),
		KEY created_at (created_at)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Task items detail table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log_info(
		'database',
		'Task items table created/updated',
		array( 'table' => $table )
	);
}

/**
 * Create task job aggregate table
 *
 * Records aggregate status of each job, used for dashboard and recovery logic.
 *
 * @since 1.1.0
 */
function wptsall_create_task_jobs_table() {
	global $wpdb;
	$table           = wptsall_table( 'task_jobs' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		job_id varchar(128) NOT NULL COMMENT 'Task batch ID',
		status varchar(20) DEFAULT 'running' NOT NULL COMMENT 'Aggregate status: running / partial / completed / failed',
		progress decimal(6,2) DEFAULT 0 NOT NULL COMMENT 'Completion progress percentage',
		task_total int(10) unsigned DEFAULT 0 NOT NULL COMMENT 'Total task count',
		pending_count int(10) unsigned DEFAULT 0 NOT NULL,
		processing_count int(10) unsigned DEFAULT 0 NOT NULL,
		retry_count int(10) unsigned DEFAULT 0 NOT NULL,
		failed_count int(10) unsigned DEFAULT 0 NOT NULL,
		completed_count int(10) unsigned DEFAULT 0 NOT NULL,
		other_count int(10) unsigned DEFAULT 0 NOT NULL,
		latest_status varchar(20) DEFAULT '' NOT NULL COMMENT 'Latest task status',
		latest_task_id bigint(20) unsigned DEFAULT 0 NOT NULL COMMENT 'Most recently updated task ID',
		latest_at datetime DEFAULT NULL COMMENT 'Task-level latest update time',
		created_at datetime NOT NULL COMMENT 'Created time',
		updated_at datetime NOT NULL COMMENT 'Updated time',
		PRIMARY KEY (id),
		UNIQUE KEY uniq_job_id (job_id),
		KEY status (status),
		KEY latest_at (latest_at),
		KEY updated_at (updated_at)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Task job aggregate table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log_info(
		'database',
		'Task jobs table created/updated',
		array( 'table' => $table )
	);
}

/**
 * Check if tasks table exists
 *
 * @return bool
 */
function wptsall_tasks_table_exists() {
	static $exists = null;
	if ( null === $exists ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		$exists = ( $result === $table );
	}
	return $exists;
}

/**
 * Check if task logs table exists
 *
 * @return bool
 */
/**
 * Check if task items detail table exists
 *
 * @return bool
 * @since 0.8.0
 */
/**
 * Check if task job aggregate table exists
 *
 * @return bool
 * @since 1.1.0
 */
function wptsall_task_jobs_table_exists() {
	global $wpdb;
	$table = wptsall_table( 'task_jobs' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
	);

	return $result === $table;
}

/**
 * Drop tasks table
 *
 * @return bool
 */
/**
 * Drop task logs table
 *
 * @return bool
 */
/**
 * Drop task items detail table
 *
 * @return bool
 * @since 0.8.0
 */
/**
 * Drop task job aggregate table
 *
 * @return bool
 * @since 1.1.0
 */
/**
 * Initialize Tasks module database tables
 *
 * Called during plugin activation
 */
function wptsall_init_tasks_tables() {
	wptsall_create_tasks_table();
	wptsall_create_task_logs_table();
	wptsall_create_task_items_table();
	wptsall_create_task_jobs_table();
	wptsall_create_translation_results_table();
	wptsall_create_origin_visits_table();
	if ( function_exists( 'wptsall_create_manual_queue_table' ) ) {
		wptsall_create_manual_queue_table();
	}
}

// Register activation hook
add_action( 'wptsall_activate', 'wptsall_init_tasks_tables' );
