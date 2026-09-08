<?php
/**
 * Manual Queue Table Schema
 *
 * Stores non-text items that cannot be auto-applied by write-back adapters
 * and need manual review.
 *
 * @package WPTSALL\Tasks
 * @since 1.0.5
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create the manual_queue table.
 *
 * @return void
 */
function wptsall_create_manual_queue_table() {
	global $wpdb;
	$table           = wptsall_table( 'manual_queue' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		task_id bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Related task ID',
		relation_id bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Site relation ID',
		source_blog bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Source blog ID',
		target_blog bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Target blog ID',
		entity_type varchar(50) NOT NULL DEFAULT '' COMMENT 'Entity type (image/video/audio/document)',
		source_id bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Source object ID',
		adapter_type varchar(50) NOT NULL DEFAULT '' COMMENT 'Write-back adapter type',
		payload longtext COMMENT 'Item data (JSON)',
		reason text COMMENT 'Why the item was routed to manual queue',
		status varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'Status: pending/reviewing/applied/rejected/expired',
		created_at datetime NOT NULL COMMENT 'Created time',
		updated_at datetime NOT NULL COMMENT 'Updated time',
		PRIMARY KEY (id),
		KEY task_id (task_id),
		KEY relation_id (relation_id),
		KEY status (status),
		KEY entity_type (entity_type),
		KEY created_at (created_at)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Manual review queue for non-text items';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log_info(
		'database',
		'Manual queue table created/updated',
		array( 'table' => $table )
	);
}

/**
 * Check whether manual_queue table exists.
 *
 * @return bool
 */
/**
 * Drop manual_queue table.
 *
 * @return bool
 */
