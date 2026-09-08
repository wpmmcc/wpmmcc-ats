<?php
/**
 * WPTSALL Translation Memory table schema.
 *
 * @package WPTSALL\TranslationMemory
 * @since 1.2.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create wptsall_translation_memory table.
 *
 * @return void
 */
function wptsall_create_translation_memory_table() {
	global $wpdb;
	$table_name      = function_exists( 'wptsall_table' )
		? wptsall_table( 'translation_memory' )
		: $wpdb->prefix . 'wptsall_translation_memory';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		source_lang VARCHAR(20) NOT NULL DEFAULT '',
		target_lang VARCHAR(20) NOT NULL DEFAULT '',
		source_text TEXT NOT NULL,
		target_text TEXT NOT NULL,
		domain VARCHAR(100) NOT NULL DEFAULT '',
		context VARCHAR(191) NOT NULL DEFAULT '',
		occurrences INT(11) NOT NULL DEFAULT 1,
		last_used_at DATETIME DEFAULT NULL,
		created_at DATETIME DEFAULT NULL,
		updated_at DATETIME DEFAULT NULL,
		PRIMARY KEY (id),
		KEY idx_lookup (source_lang, target_lang, source_text(191)),
		KEY idx_domain (domain),
		KEY idx_occurrences (occurrences)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Bilingual translation memory pairs';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
