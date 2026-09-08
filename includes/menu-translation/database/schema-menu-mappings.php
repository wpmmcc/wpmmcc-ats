<?php
/**
 * Schema: wptsall_menu_mappings (+ location option helper).
 *
 * @package WPTSALL\MenuTranslation
 * @since 2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create menu mappings table.
 *
 * @return void
 */
function wptsall_create_menu_mappings_table() {
	global $wpdb;
	$table           = wptsall_table( 'menu_mappings' );
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE IF NOT EXISTS {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		source_menu_term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		target_menu_term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		virtual_site_id VARCHAR(64) NOT NULL DEFAULT '',
		relation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		target_lang VARCHAR(32) NOT NULL DEFAULT '',
		location_slug VARCHAR(191) NOT NULL DEFAULT '',
		source_hash VARCHAR(64) NOT NULL DEFAULT '',
		sync_status VARCHAR(32) NOT NULL DEFAULT 'synced',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uq_source_vs (source_menu_term_id, virtual_site_id),
		KEY idx_target (target_menu_term_id),
		KEY idx_location_vs (location_slug, virtual_site_id),
		KEY idx_relation (relation_id)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
