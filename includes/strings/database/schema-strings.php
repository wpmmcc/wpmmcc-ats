<?php
/**
 * WPTSALL Strings Table Schema
 *
 * Layer B site-structure strings (menus, widgets, site title, etc.).
 *
 * @package WPTSALL\Strings
 * @since 2.3.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create wptsall_strings table.
 *
 * Rows are scoped to the current WordPress source site (the table prefix), not
 * to a numeric site relation. A row stores a per-language translation map;
 * REST callers must validate the relation source site and target language.
 *
 * @return void
 */
function wptsall_create_strings_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'strings' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		context VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'site_title, site_tagline, menu, widget, etc.',
		object_id BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Optional WP object id (menu item, etc.)',
		string_key VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Stable key within context',
		source_text TEXT NOT NULL,
		source_lang VARCHAR(20) NOT NULL DEFAULT 'zh_CN',
		translations LONGTEXT DEFAULT NULL COMMENT 'JSON map lang_code => text',
		status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|translated|inherited',
		claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp',
		claim_owner_hash CHAR(64) DEFAULT NULL COMMENT 'Hashed device/relation claim owner',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY unique_string (context, object_id, string_key, source_lang),
		INDEX idx_context (context),
		INDEX idx_status (status),
		INDEX idx_claimed (claimed_at),
		INDEX idx_claim_owner (claim_owner_hash)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Layer B site structure strings';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Strings table created',
		array(
			'table'   => $table_name,
			'version' => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '',
		)
	);
}
