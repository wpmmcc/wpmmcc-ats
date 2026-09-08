<?php
/**
 * WPTSALL Origin Visits Table Schema
 *
 * Stores global origin/visited relationships to prevent backflow/loops across relations/site groups.
 *
 * @package WPTSALL\Core\Database
 * @since 1.0.2
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create origin_visits table.
 *
 * Key design:
 * - origin identifies the logical content root (site + object_type + subtype + object_id)
 * - visited identifies a destination site (wp blog or virtual site)
 * - unique constraint enforces "deliver once per destination site" (language NOT part of the key)
 *
 * @since 1.0.2
 */
function wptsall_create_origin_visits_table() {
	global $wpdb;

	$table_name      = wptsall_table( 'origin_visits' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		origin_site_type VARCHAR(20) NOT NULL COMMENT 'Origin site type (wp/virtual)',
		origin_site_id VARCHAR(50) NOT NULL COMMENT 'Origin site identifier (blog_id or virtual_site_id)',
		origin_object_type VARCHAR(20) NOT NULL COMMENT 'Object type (post_type/taxonomy)',
		origin_subtype VARCHAR(100) NOT NULL COMMENT 'Post type or taxonomy name',
		origin_object_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Origin object ID',
		visited_site_type VARCHAR(20) NOT NULL COMMENT 'Visited site type (wp/virtual)',
		visited_site_id VARCHAR(50) NOT NULL COMMENT 'Visited site identifier (blog_id or virtual_site_id)',
		visited_object_id BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Destination object ID (optional)',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_origin_visited (origin_site_type, origin_site_id, origin_object_type, origin_subtype, origin_object_id, visited_site_type, visited_site_id),
		KEY idx_origin (origin_site_type, origin_site_id, origin_object_type, origin_subtype, origin_object_id),
		KEY idx_visited (visited_site_type, visited_site_id)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Global origin -> visited site deliveries';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Check whether origin_visits table exists.
 *
 * @since 1.0.2
 * @return bool
 */
/**
 * Drop origin_visits table.
 *
 * @since 1.0.2
 */
