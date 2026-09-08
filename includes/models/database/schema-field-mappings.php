<?php
/**
 * WPTSALL Field Mappings Table Schema
 *
 * Field mappings table schema definitions
 * Stores ID mappings between source and target sites
 *
 * Contains three tables:
 * - term_mappings: term/tag ID mappings
 * - media_mappings: media file ID mappings
 * - post_mappings: post association ID mappings
 *
 * Note: user_mappings table is managed by the Sites module
 * @see includes/sites/database/schema-user-mappings.php
 *
 * @package WPTSALL\Models\Database
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
 * Create term/tag mappings table
 *
 * Stores mappings between source and target site terms/tags
 * Supports auto-creating missing terms/tags and recording translation method
 */
function wptsall_create_term_mappings_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'term_mappings' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Site relation ID',
		source_term_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Source term/tag ID',
		source_taxonomy VARCHAR(100) NOT NULL COMMENT 'Source taxonomy',
		source_site_id INT NOT NULL DEFAULT 1 COMMENT 'Source site ID',
		source_lang VARCHAR(20) NOT NULL COMMENT 'Source language',
		target_term_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Target term/tag ID',
		target_taxonomy VARCHAR(100) NOT NULL COMMENT 'Target taxonomy',
		target_site_id VARCHAR(50) NOT NULL COMMENT 'Target site ID (may be virtual site)',
		target_lang VARCHAR(20) NOT NULL COMMENT 'Target language',
		mapping_method VARCHAR(50) DEFAULT 'manual' COMMENT 'Mapping method: manual/auto_create/auto_match',
		translation_method VARCHAR(50) DEFAULT NULL COMMENT 'Translation method: api/template/fallback',
		needs_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Source content changed, needs re-translation',
		claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for content processing',
		claim_owner_hash CHAR(64) DEFAULT NULL COMMENT 'Hashed device/relation claim owner',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY unique_mapping (relation_id, source_term_id, source_taxonomy, source_site_id, target_site_id, target_lang),
		INDEX idx_relation (relation_id),
		INDEX idx_relation_term_claimed (relation_id, source_term_id, source_taxonomy, source_site_id, target_site_id, target_lang, claimed_at),
		INDEX idx_claim_owner (claim_owner_hash),
		INDEX idx_source (source_term_id, source_taxonomy, source_site_id),
		INDEX idx_target (target_term_id, target_taxonomy, target_site_id),
		INDEX idx_lang (source_lang, target_lang)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Term/tag mappings table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log_info(
		'models',
		'Term mappings table created',
		array(
			'table'   => $table_name,
			'version' => '0.5.0',
		)
	);
}

/**
 * Create media file mappings table
 *
 * Stores mappings between source and target site media files
 * Supports recording file paths, alt text translations, etc.
 */
function wptsall_create_media_mappings_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'media_mappings' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		source_media_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Source media ID',
		relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Site relation ID',
		source_site_id INT NOT NULL DEFAULT 1 COMMENT 'Source site ID',
		source_file_path VARCHAR(500) NOT NULL COMMENT 'Source file path',
		source_file_url VARCHAR(500) NOT NULL COMMENT 'Source file URL',
		target_media_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Target media ID',
		target_site_id VARCHAR(50) NOT NULL COMMENT 'Target site ID (may be virtual site)',
		target_file_path VARCHAR(500) NOT NULL COMMENT 'Target file path',
		target_file_url VARCHAR(500) NOT NULL COMMENT 'Target file URL',
		mapping_method VARCHAR(50) DEFAULT 'copy' COMMENT 'Mapping method: copy/reuse/external',
		alt_translated TINYINT(1) DEFAULT 0 COMMENT 'Whether alt text has been translated',
		needs_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Source media changed, needs re-sync',
		claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for media processing',
		claim_owner_hash CHAR(64) DEFAULT NULL COMMENT 'Hashed device/relation claim owner',
		metadata LONGTEXT COMMENT 'Additional metadata (JSON)',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY unique_mapping (relation_id, source_media_id, source_site_id, target_site_id),
		INDEX idx_source (source_media_id, source_site_id),
		INDEX idx_relation (relation_id),
		INDEX idx_claim_owner (claim_owner_hash),
		INDEX idx_target (target_media_id, target_site_id),
		INDEX idx_source_file (source_file_path(255)),
		INDEX idx_resync (needs_resync)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Media file mappings table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log_info(
		'models',
		'Media mappings table created',
		array(
			'table'   => $table_name,
			'version' => '0.5.0',
		)
	);
}

/**
 * Create post association mappings table
 *
 * Stores mappings between source and target site posts
 * Handles post associations (e.g., courses and lessons, parent-child, etc.)
 */
function wptsall_create_post_mappings_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'post_mappings' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Site relation ID',
		source_post_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Source post ID',
		source_post_type VARCHAR(100) NOT NULL COMMENT 'Source post type',
		source_site_id INT NOT NULL DEFAULT 1 COMMENT 'Source site ID',
		target_post_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Target post ID',
		target_post_type VARCHAR(100) NOT NULL COMMENT 'Target post type',
		target_site_id VARCHAR(50) NOT NULL COMMENT 'Target site ID (may be virtual site)',
		relationship_type VARCHAR(50) DEFAULT 'translation' COMMENT 'Relationship type: translation/reference/parent_child',
		needs_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Source content changed, needs re-translation',
		claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for content processing',
		claim_owner_hash CHAR(64) DEFAULT NULL COMMENT 'Hashed device/relation claim owner',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY unique_mapping (relation_id, source_post_id, source_post_type, source_site_id, target_site_id),
		INDEX idx_relation (relation_id),
		INDEX idx_relation_source_claimed (relation_id, source_post_id, source_post_type, source_site_id, target_site_id, claimed_at),
		INDEX idx_claim_owner (claim_owner_hash),
		INDEX idx_source (source_post_id, source_post_type, source_site_id),
		INDEX idx_target (target_post_id, target_post_type, target_site_id),
		INDEX idx_relationship (relationship_type),
		INDEX idx_resync (needs_resync)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Post association mappings table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log_info(
		'models',
		'Post mappings table created',
		array(
			'table'   => $table_name,
			'version' => '0.5.0',
		)
	);
}

/**
 * Create the durable content-change outbox.
 *
 * Lifecycle hooks append one row per source object/relation/event. Consumers
 * claim rows with a lease and acknowledge them after applying the mapping;
 * failed workers can safely resume from pending/processing rows.
 *
 * @since 2.3.0
 * @return void
 */
function wptsall_create_content_change_outbox_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'content_change_outbox' );
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		event_key VARCHAR(191) NOT NULL,
		source_type VARCHAR(30) NOT NULL,
		source_id BIGINT(20) UNSIGNED NOT NULL,
		source_site_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
		relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		event_name VARCHAR(60) NOT NULL,
		payload LONGTEXT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		attempts INT(10) UNSIGNED NOT NULL DEFAULT 0,
		available_at DATETIME NOT NULL,
		claimed_at DATETIME DEFAULT NULL,
		completed_at DATETIME DEFAULT NULL,
		last_error TEXT NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY event_key (event_key),
		KEY claim_queue (status, available_at, claimed_at, id),
		KEY source_lookup (source_type, source_id, source_site_id, id)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Durable content change outbox';";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Create all field mapping tables
 *
 * Note: user_mappings table is created by Sites module
 * @see wptsall_create_user_mappings_table() in sites/database/schema-user-mappings.php
 */
/**
 * Create relation-scoped option synchronization state.
 *
 * Options do not have a WordPress object id that can safely identify a claim.
 * The option name is therefore part of the durable key and the device owner is
 * stored only as a digest. This table is intentionally separate from
 * post/term mappings so a synthetic option id can never cross a relation.
 *
 * @since 2.1.0
 * @return void
 */
function wptsall_create_option_sync_state_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'option_sync_state' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		relation_id BIGINT(20) UNSIGNED NOT NULL,
		source_site_id INT NOT NULL DEFAULT 1,
		target_site_id VARCHAR(50) NOT NULL DEFAULT '',
		option_name VARCHAR(191) NOT NULL,
		needs_resync TINYINT(1) NOT NULL DEFAULT 0,
		claimed_at DATETIME DEFAULT NULL,
		claim_owner_hash CHAR(64) DEFAULT NULL,
		synced_at DATETIME DEFAULT NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY unique_option_state (relation_id, source_site_id, target_site_id, option_name),
		KEY idx_relation_option_claimed (relation_id, source_site_id, target_site_id, option_name, claimed_at),
		KEY idx_claim_owner (claim_owner_hash),
		KEY idx_resync (needs_resync)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Relation-scoped option synchronization state';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Check whether the option synchronization state table exists.
 *
 * @since 2.1.0
 * @return bool
 */
function wptsall_create_field_mapping_tables() {
	wptsall_create_term_mappings_table();
	wptsall_create_media_mappings_table();
	wptsall_create_post_mappings_table();
	wptsall_create_content_change_outbox_table();
	wptsall_create_option_sync_state_table();
	// user_mappings table is created by Sites module.
}

/**
 * Ensure relation-scoped mapping columns exist for client claim flows.
 *
 * This wrapper keeps backward compatibility with callers that explicitly
 * request relation-scoped mapping support.
 *
 * @return void
 */
function wptsall_ensure_relation_scoped_mapping_tables() {
	wptsall_create_field_mapping_tables();
	// A target subsite can enter a write-back request before its normal
	// admin_init migration pass. Repair relation-scoped unique keys here too;
	// the migration function is idempotent and may be unavailable during very
	// early bootstrap, in which case the normal migration pass remains the
	// fallback.
	if ( function_exists( 'wptsall_migrate_relation_scoped_mapping_unique_keys_v210' ) ) {
		wptsall_migrate_relation_scoped_mapping_unique_keys_v210();
	}
}

/**
 * Check if term mappings table exists
 *
 * @return bool
 */
function wptsall_term_mappings_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'term_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Check if media mappings table exists
 *
 * @return bool
 */
function wptsall_media_mappings_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'media_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Check if post mappings table exists
 *
 * @return bool
 */
function wptsall_post_mappings_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'post_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Drop field mapping tables (for uninstall only)
 *
 * Note: user_mappings table is managed by the Sites module
 * @see wptsall_drop_user_mappings_table() in sites/database/schema-user-mappings.php
 */
/**
 * Get field mapping statistics
 *
 * @return array
 */
