<?php
/**
 * Translation Results Table Schema
 *
 * Stores translated content received from the external Rust client
 * before it is synced to the target site.
 *
 * @package WPTSALL\Tasks
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create the translation_results table.
 *
 * @return void
 */
function wptsall_create_translation_results_table() {
	global $wpdb;
	$table           = wptsall_table( 'translation_results' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table} (
		id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		relation_id BIGINT UNSIGNED NOT NULL,
		object_type VARCHAR(50) NOT NULL DEFAULT 'post',
		object_id BIGINT UNSIGNED NOT NULL,
		translated_fields JSON,
		translated_meta JSON,
		media_mappings JSON,
		client_task_id VARCHAR(100) NOT NULL,
		source_revision VARCHAR(64) NOT NULL DEFAULT '',
		policy_version VARCHAR(64) NOT NULL DEFAULT '',
		request_hash VARCHAR(64) NOT NULL DEFAULT '',
		source_lang VARCHAR(20) DEFAULT '',
		target_lang VARCHAR(20) DEFAULT '',
		status VARCHAR(20) DEFAULT 'pending',
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		synced_at DATETIME DEFAULT NULL,
		UNIQUE KEY client_task_id (client_task_id),
		KEY relation_object (relation_id, object_type, object_id),
		KEY status_idx (status),
		KEY status_created_at (status, created_at)
	) {$charset_collate} ENGINE=InnoDB;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
