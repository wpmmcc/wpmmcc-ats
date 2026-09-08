<?php
/**
 * Schema: wptsall_conflicts
 *
 * @package WPTSALL\Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return void
 */
function wptsall_create_conflicts_table() {
	global $wpdb;
	$table           = wptsall_table( 'conflicts' );
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE IF NOT EXISTS {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		relation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		object_type VARCHAR(32) NOT NULL DEFAULT 'post',
		source_object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		target_object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		field_path VARCHAR(191) NOT NULL DEFAULT '',
		manual_hash VARCHAR(64) NOT NULL DEFAULT '',
		machine_hash VARCHAR(64) NOT NULL DEFAULT '',
		client_task_id VARCHAR(100) NOT NULL DEFAULT '',
		status VARCHAR(32) NOT NULL DEFAULT 'open',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		resolved_at DATETIME DEFAULT NULL,
		PRIMARY KEY (id),
		KEY idx_open (status, relation_id),
		KEY idx_target_field (target_object_id, field_path),
		KEY idx_client_task (client_task_id)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
