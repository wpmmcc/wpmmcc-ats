<?php
/**
 * WPTSALL Virtual Sites Table Schema
 *
 * Virtual sites table structure definition
 *
 * @package WPTSALL
 * @since 0.5.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create virtual sites table
 *
 * Virtual sites are standalone site configurations independent of WordPress Multisite
 * Used to create virtual paths for translated versions
 */
function wptsall_create_virtual_sites_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'virtual_sites' );
	$charset_collate = $wpdb->get_charset_collate();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

		-- Virtual site basic info
		site_name VARCHAR(255) NOT NULL COMMENT 'Site name',
		site_tagline VARCHAR(255) DEFAULT '' COMMENT 'Site subtitle',
		site_path VARCHAR(100) NOT NULL COMMENT 'Site path (e.g. zh)',
		site_language VARCHAR(20) NOT NULL COMMENT 'Site language code (e.g. zh_CN)',
		site_logo VARCHAR(500) DEFAULT '' COMMENT 'Site logo URL',

		-- Blog sync settings
		source_blog_id BIGINT(20) UNSIGNED DEFAULT 0 COMMENT 'Source blog site ID (for sync)',
		enable_blog_sync TINYINT(1) DEFAULT 0 COMMENT 'Whether blog sync is enabled',

		-- Permalink settings (v0.6.0)
		permalink_structure VARCHAR(255) DEFAULT '' COMMENT 'Permalink structure (empty=inherit from source site)',
		category_base VARCHAR(100) DEFAULT '' COMMENT 'Category base (empty=inherit from source site)',
		tag_base VARCHAR(100) DEFAULT '' COMMENT 'Tag base (empty=inherit from source site)',

		-- Status
		status VARCHAR(20) DEFAULT 'active' COMMENT 'Status: active / inactive',

		-- Timestamps
		created_at DATETIME NOT NULL COMMENT 'Created at',
		updated_at DATETIME NOT NULL COMMENT 'Updated at',

		PRIMARY KEY (id),

		-- Path unique constraint
		UNIQUE KEY unique_path (site_path),

		-- Indexes
		INDEX idx_lang (site_language),
		INDEX idx_status (status)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Virtual sites table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Virtual sites table created',
		array(
			'table'   => $table_name,
			'version' => '0.5.0',
		)
	);
}

/**
 * Delete virtual site content table
 *
 * Since v0.8.0, virtual site content is stored as copies in the wp_posts table.
 * This function cleans up the deprecated virtual_site_content table.
 *
 * @since 0.8.0
 */
function wptsall_drop_virtual_site_content_table() {
	global $wpdb;
	$table_name = wptsall_table( 'virtual_site_content' );

	// Check if table exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	if ( $table_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );

		wptsall_log(
			'database',
			'info',
			'Virtual site content table dropped (deprecated in v0.8.0)',
			array( 'table' => $table_name )
		);

		return true;
	}

	return false;
}

/**
 * Check if virtual sites table exists
 *
 * @return bool
 */
function wptsall_virtual_sites_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'virtual_sites' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Delete virtual sites table (used only during uninstall)
 */
/**
 * Get virtual sites table statistics
 *
 * @return array
 */
/**
 * Migrate virtual sites table for v0.6.0
 *
 * Adds category_base and tag_base columns if they don't exist.
 *
 * @since 0.6.0
 */
function wptsall_migrate_virtual_sites_060() {
	global $wpdb;
	$table_name = wptsall_table( 'virtual_sites' );

	if ( ! wptsall_virtual_sites_table_exists() ) {
		return;
	}

	// Check if category_base column exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column_exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'category_base' ) );

	if ( empty( $column_exists ) ) {
		// Add category_base and tag_base columns
		wptsall_db_alter_table(
			$table_name,
			"ADD COLUMN category_base VARCHAR(100) DEFAULT '' COMMENT 'Category base (empty=inherit from source site)' AFTER permalink_structure,
			ADD COLUMN tag_base VARCHAR(100) DEFAULT '' COMMENT 'Tag base (empty=inherit from source site)' AFTER category_base"
		);

		wptsall_log(
			'database',
			'info',
			'Virtual sites table migrated to v0.6.0',
			array(
				'table'         => $table_name,
				'added_columns' => array( 'category_base', 'tag_base' ),
			)
		);
	}
}
