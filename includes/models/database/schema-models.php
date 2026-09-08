<?php
/**
 * WPTSALL Models Table Schema
 *
 * New model table schema definitions
 * Model = plugin info + translation rules (complete relation chain)
 *
 * Relation chain: Frontend URL <-> Data source <-> Fields <-> Backend URL
 *
 * @package WPTSALL
 * @since 0.4.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create plugin models table
 *
 * Stores basic plugin info, one record per content plugin
 */
function wptsall_create_models_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'models' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		plugin_slug VARCHAR(100) NOT NULL COMMENT 'Plugin slug (unique)',
		plugin_name VARCHAR(255) NOT NULL COMMENT 'Plugin name',
		plugin_version VARCHAR(20) COMMENT 'Plugin version',
		text_domain VARCHAR(100) COMMENT 'Plugin text domain',
		description TEXT COMMENT 'Model description',
		plugin_file VARCHAR(255) COMMENT 'Plugin main file path',
		is_content_plugin TINYINT(1) DEFAULT 0 COMMENT 'Whether it is a content plugin',
		post_types LONGTEXT COMMENT 'Registered post_types (JSON array)',
		taxonomies LONGTEXT COMMENT 'Registered taxonomies (JSON array)',
		meta_fields LONGTEXT COMMENT 'Registered meta fields (JSON)',
		custom_tables LONGTEXT COMMENT 'Custom database tables (JSON)',
		status VARCHAR(20) DEFAULT 'active' COMMENT 'Status: active/inactive',
		usage_status VARCHAR(20) DEFAULT 'unused' COMMENT 'Usage status: active=has relation references, unused=no relation references',
		semantic_status VARCHAR(20) DEFAULT 'draft' COMMENT 'Semantic review status: draft/reviewed/approved/stale',
		source_type VARCHAR(20) DEFAULT 'auto' COMMENT 'Source type: auto=auto-scanned, manual=manually created',
		is_system TINYINT(1) DEFAULT 0 COMMENT 'Whether it is a system built-in',
		scan_version VARCHAR(20) COMMENT 'Scanner version',
		scan_result LONGTEXT COMMENT 'Scan result (JSON)',
		scan_status VARCHAR(20) DEFAULT 'pending' COMMENT 'Scan status: pending/scanning/completed/failed',
		scan_error TEXT COMMENT 'Error message on scan failure',
		user_consent TINYINT(1) DEFAULT 0 COMMENT 'Whether user has consented to scanning',
		consent_at DATETIME COMMENT 'Consent time',
		detection_method VARCHAR(50) COMMENT 'Detection method: static/runtime/hybrid/v4_smart',
		last_scanned DATETIME COMMENT 'Last scan time',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY unique_plugin (plugin_slug),
		INDEX idx_status (status),
		INDEX idx_usage_status (usage_status),
		INDEX idx_semantic_status (semantic_status),
		INDEX idx_source_type (source_type),
		INDEX idx_content (is_content_plugin),
		INDEX idx_scan_status (scan_status),
		INDEX idx_user_consent (user_consent)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Plugin models table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Models table created',
		array(
			'table'   => $table_name,
			'version' => '0.4.0',
		)
	);
}

/**
 * Create translation rules table
 *
 * AUTHORITATIVE source for sync behavior. The sync pipeline (Sync_Executor,
 * Monitoring_Task_Service) reads field_capabilities from this table to decide
 * which fields to translate, sync, map, or skip. Any code that needs to affect
 * sync must write to this table.
 *
 * Stores the complete translation relation chain:
 * Frontend URL -> Data source -> Field capabilities config -> Backend URL
 *
 * @since 0.4.0 Initial version
 * @since 0.8.0 Refactored field config to unified field_capabilities JSON
 */
function wptsall_create_translation_rules_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'translation_rules' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		model_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Associated model ID',
		name VARCHAR(255) COMMENT 'Rule name (for identification)',

		-- Frontend URL section
		url_pattern VARCHAR(500) NOT NULL COMMENT 'URL pattern, e.g. /courses/{slug}/',
		url_type VARCHAR(50) NOT NULL COMMENT 'Page type: single/archive/taxonomy/author/date/search/home/page/attachment/embed/feed/endpoint',
		requires_login TINYINT(1) DEFAULT 0 COMMENT 'Whether login is required to access',
		example_url VARCHAR(500) COMMENT 'Example URL (for reference)',

		-- Data source section
		data_type VARCHAR(50) NOT NULL COMMENT 'Data type: post/term/user/comment/option/custom_table',
		object_name VARCHAR(100) NOT NULL COMMENT 'post_type or taxonomy name',
		primary_table VARCHAR(100) COMMENT 'Primary data table',
		meta_table VARCHAR(100) COMMENT 'Meta table',

		-- Field config (v0.8.0 new structure)
		direction VARCHAR(30) DEFAULT 'source_to_target' COMMENT 'Default sync direction: source_to_target/bidirectional',
		sync_mode VARCHAR(20) DEFAULT 'new_only' COMMENT 'Sync mode: new_only (create-only sync)',
		field_capabilities LONGTEXT COMMENT 'Unified field capabilities config (JSON object)',

		-- Backend URL section
		backend_edit VARCHAR(500) COMMENT 'Backend edit page URL pattern',
		backend_list VARCHAR(500) COMMENT 'Backend list page URL',
		backend_new VARCHAR(500) COMMENT 'Backend create page URL',

		-- Related rules
		related_taxonomies LONGTEXT COMMENT 'Related taxonomies (JSON array)',
		parent_rule_id BIGINT(20) UNSIGNED COMMENT 'Parent rule ID (e.g. lesson belongs to course)',

		-- Metadata
		priority INT DEFAULT 10 COMMENT 'Priority (lower value = higher priority)',
		is_active TINYINT(1) DEFAULT 1 COMMENT 'Whether enabled',
		auto_detected TINYINT(1) DEFAULT 1 COMMENT 'Whether auto-detected',
		note TEXT COMMENT 'Notes',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,

		PRIMARY KEY (id),
		UNIQUE KEY unique_rule (model_id, data_type, object_name, url_type),
		INDEX idx_model (model_id),
		INDEX idx_data (data_type, object_name),
		INDEX idx_url_type (url_type),
		INDEX idx_active (is_active),
		INDEX idx_direction (direction)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Translation rules table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Translation rules table created',
		array(
			'table'   => $table_name,
			'version' => '0.8.0',
		)
	);
}

/**
 * Create plugin mappings table (Plugin Templates)
 *
 * @deprecated 1.0.1 This table is being consolidated into the `models` table.
 *             The `models` table now contains all fields that were unique to
 *             `plugin_mappings` (plugin_file, is_content_plugin, scan_result,
 *             scan_version, scan_status, scan_error, user_consent, consent_at,
 *             detection_method, custom_tables, meta_fields).
 *             See wptsall_migrate_models_add_plugin_fields() for the migration.
 *             This table is kept for backward compatibility but new code should
 *             read/write to the `models` table instead.
 *
 *             NOTE: This function is only called from migrations.php (v0.4.0
 *             sequential migration) for upgrade-path integrity. It is no longer
 *             called from wptsall_create_model_tables().
 *
 * Stores plugin scan results and mappings
 * V4 Smart Scanner scan results are stored in this table
 *
 * @since 0.4.0 Initial version
 * @since 0.9.0 Added V4 scan result fields（scan_result, scan_version, scan_status, user_consent）
 * @since 0.9.1 Added custom_tables column (ISS-MOD-019)
 */
function wptsall_create_plugin_mappings_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'plugin_mappings' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		plugin_slug VARCHAR(100) NOT NULL COMMENT 'Plugin slug',
		plugin_name VARCHAR(255) NOT NULL COMMENT 'Plugin name',
		plugin_file VARCHAR(255) COMMENT 'Plugin main file path',
		is_content_plugin TINYINT(1) DEFAULT 0 COMMENT 'Whether it is a content plugin',

		-- Basic scan results (backward compatible)
		post_types LONGTEXT COMMENT 'Registered post_types (JSON)',
		taxonomies LONGTEXT COMMENT 'Registered taxonomies (JSON)',
		meta_fields LONGTEXT COMMENT 'Registered meta fields (JSON)',
		custom_tables LONGTEXT COMMENT 'Custom database tables (JSON, ISS-MOD-019)',

		-- V4 scan results (added v0.9.0)
		scan_result LONGTEXT COMMENT 'V4 full scan result (JSON, includes object_metadata/fields/url_info)',
		scan_version VARCHAR(20) COMMENT 'Scanner version (e.g. v4.2)',
		scan_status VARCHAR(20) DEFAULT 'pending' COMMENT 'Scan status: pending/scanning/completed/failed',
		scan_error TEXT COMMENT 'Error message on scan failure',

		-- User consent
		user_consent TINYINT(1) DEFAULT 0 COMMENT 'Whether user has consented to scanning',
		consent_at DATETIME COMMENT 'Consent time',

		-- Metadata
		detection_method VARCHAR(50) COMMENT 'Detection method: static/runtime/hybrid/v4_smart',
		last_scanned DATETIME COMMENT 'Last scan time',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,

		PRIMARY KEY (id),
		UNIQUE KEY unique_plugin (plugin_slug),
		INDEX idx_content (is_content_plugin),
		INDEX idx_scan_status (scan_status),
		INDEX idx_user_consent (user_consent)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Plugin mappings table (Plugin Templates)';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Plugin mappings table created',
		array(
			'table'   => $table_name,
			'version' => '0.9.0',
		)
	);
}

/**
 * Create model storage objects table (Plugin Template Objects)
 *
 * DISPLAY/REFERENCE only. This table stores scan results and manual field
 * additions for visualization in the admin UI. It does NOT affect sync behavior.
 * The AUTHORITATIVE source for sync is the translation_rules table
 * (specifically its field_capabilities column).
 *
 * One record represents a 'storage object', e.g.:
 * - post_type: product
 * - taxonomy: product_cat
 * - custom_table: wp_bookly_appointments
 *
 * This table only stores factual data (scan/manual additions), not translation/sync policies.
 *
 * @since 1.0.1
 */
function wptsall_create_model_objects_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'model_objects' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		model_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Associated model ID (plugin level)',
		object_type VARCHAR(30) NOT NULL COMMENT 'Object type: post_type/taxonomy/custom_table',
		object_name VARCHAR(191) NOT NULL COMMENT 'Object identifier: post_type or taxonomy or table_name',
		url_signature VARCHAR(500) NULL COMMENT 'Frontend URL signature (canonical query)',
		metadata LONGTEXT NULL COMMENT 'Object metadata (JSON)',
		source_type VARCHAR(20) DEFAULT 'auto' COMMENT 'Source: auto/manual',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_model_object (model_id, object_type, object_name),
		INDEX idx_model (model_id),
		INDEX idx_type (object_type)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Model storage objects table (Plugin Template Objects)';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Model objects table created',
		array(
			'table'   => $table_name,
			'version' => '1.0.1',
		)
	);
}

/**
 * Create model object fields table (Plugin Template Object Fields)
 *
 * DISPLAY/REFERENCE only (same as model_objects). Stores discovered fields
 * for admin UI visualization. Does NOT drive sync behavior -- the
 * translation_rules.field_capabilities column is authoritative for sync.
 *
 * One record represents a field (belonging to a storage object).
 * Scan results must map to a storage object; otherwise they are not stored and logged.
 *
 * @since 1.0.1
 */
function wptsall_create_model_object_fields_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'model_object_fields' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		object_id BIGINT(20) UNSIGNED NOT NULL,
		field_kind VARCHAR(30) NOT NULL,
		field_key VARCHAR(191) NOT NULL,
		source VARCHAR(20) NOT NULL DEFAULT 'scan',
		data_type VARCHAR(50) DEFAULT NULL COMMENT 'text/html/numeric/id_ref/id_list/url/datetime/serialized/json/enum/slug/boolean',
		reference_type VARCHAR(50) DEFAULT NULL COMMENT 'When data_type=id_ref/id_list: post/taxonomy/media/user',
		reference_target VARCHAR(100) DEFAULT NULL COMMENT 'Specific target: attachment/product/product_cat etc.',
		usage_count INT UNSIGNED DEFAULT 0 COMMENT 'Usage count from scan',
		sample_value TEXT DEFAULT NULL COMMENT 'Sample value (first 100 chars)',
		status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active/new/orphan/deprecated',
		discovered_at DATETIME DEFAULT NULL COMMENT 'First discovered timestamp',
		confirmed_at DATETIME DEFAULT NULL COMMENT 'User confirmed timestamp',
		extra LONGTEXT NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_object_field (object_id, field_kind, field_key),
		INDEX idx_object (object_id),
		INDEX idx_kind (field_kind),
		INDEX idx_status (status),
		INDEX idx_data_type (data_type)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Model object fields table (Plugin Template Object Fields)';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Model object fields table created',
		array(
			'table'   => $table_name,
			'version' => '1.0.1',
		)
	);
}

/**
 * Create all model-related tables
 */
function wptsall_create_model_tables() {
	wptsall_create_models_table();
	wptsall_create_model_objects_table();
	wptsall_create_model_object_fields_table();
	wptsall_create_translation_rules_table();
	wptsall_create_link_chains_table();
}

/**
 * Check if models table exists
 *
 * @return bool
 */
function wptsall_models_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'models' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Check if translation rules table exists
 *
 * @return bool
 */
function wptsall_translation_rules_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'translation_rules' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Check if plugin mappings table exists
 *
 * @return bool
 */
function wptsall_plugin_mappings_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'plugin_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $result === $table_name;
}

/**
 * Drop model-related tables (for uninstall only)
 */
/**
 * Get model statistics
 *
 * @return array
 */
function wptsall_get_models_stats() {
	global $wpdb;
	$models_table = wptsall_table( 'models' );
	$rules_table  = wptsall_table( 'translation_rules' );

	if ( ! wptsall_models_table_exists() ) {
		return array(
			'total_models'       => 0,
			'active_models'      => 0,
			'total_rules'        => 0,
			'active_rules'       => 0,
			'single_rules'       => 0,
			'archive_rules'      => 0,
			'taxonomy_rules'     => 0,
			'login_required'     => 0,
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$total_models = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $models_table )
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$active_models = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $models_table, 'active' )
	);

	$rules_stats = array(
		'total_rules'    => 0,
		'active_rules'   => 0,
		'single_rules'   => 0,
		'archive_rules'  => 0,
		'taxonomy_rules' => 0,
		'login_required' => 0,
	);

	if ( wptsall_translation_rules_table_exists() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules_stats['total_rules'] = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $rules_table )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules_stats['active_rules'] = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_active = 1', $rules_table )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules_stats['single_rules'] = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE url_type = %s', $rules_table, 'single' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules_stats['archive_rules'] = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE url_type = %s', $rules_table, 'archive' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules_stats['taxonomy_rules'] = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE url_type = %s', $rules_table, 'taxonomy' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules_stats['login_required'] = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE requires_login = 1', $rules_table )
		);
	}

	return array_merge(
		array(
			'total_models'  => $total_models,
			'active_models' => $active_models,
		),
		$rules_stats
	);
}

/**
 * Migration: add usage_status field
 *
 * @return bool Whether migration was executed
 */
function wptsall_migrate_add_usage_status_field() {
	global $wpdb;

	$table_name = wptsall_table( 'models' );

	// Check if column already exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'usage_status' ) );

	if ( $column_exists ) {
		return false; // Column already exists, no migration needed
	}

	// Add column
	wptsall_db_alter_table(
		$table_name,
		"ADD COLUMN usage_status VARCHAR(20) DEFAULT 'unused' COMMENT 'Usage status: active=has relation references, unused=no relation references' AFTER status"
	);

	// Add index
	wptsall_db_alter_table( $table_name, 'ADD INDEX idx_usage_status (usage_status)' );

	wptsall_log(
		'database',
		'info',
		'Migration: added usage_status field to models table',
		array( 'table' => $table_name )
	);

	return true;
}

/**
 * Create link chains table
 *
 * Stores custom model table association config
 * Used to define how URL parameters link to database tables
 *
 * @since 0.7.0
 */
function wptsall_create_link_chains_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'model_link_chains' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		rule_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Associated rule ID',
		chain_order INT NOT NULL DEFAULT 0 COMMENT 'Chain order (starting from 0)',

		-- Source config (left side)
		source_type VARCHAR(20) NOT NULL COMMENT 'Source type: url_param=URL parameter, table_column=table column',
		source_param VARCHAR(100) COMMENT 'URL param name (when source_type=url_param)',
		source_table VARCHAR(100) COMMENT 'Source table name (when source_type=table_column)',
		source_column VARCHAR(100) COMMENT 'Source column name (when source_type=table_column)',

		-- Target config (right side)
		target_table VARCHAR(100) NOT NULL COMMENT 'Target table name',
		target_match_column VARCHAR(100) NOT NULL COMMENT 'Match column (for WHERE clause)',
		target_id_column VARCHAR(100) NOT NULL COMMENT 'ID column (for next-level join or final query)',

		-- Metadata
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,

		PRIMARY KEY (id),
		INDEX idx_rule (rule_id),
		INDEX idx_order (rule_id, chain_order)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Model link chains table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Link chains table created',
		array(
			'table'   => $table_name,
			'version' => '0.7.0',
		)
	);
}

/**
 * Check if link chains table exists
 *
 * @since 0.7.0
 * @return bool
 */
/**
 * Migration: add source_type field to models table
 *
 * @since 0.7.0
 * @return bool Whether migration was executed
 */
function wptsall_migrate_add_source_type_field() {
	global $wpdb;

	$table_name = wptsall_table( 'models' );

	// Check if table exists
	if ( ! wptsall_models_table_exists() ) {
		return false;
	}

	// Check if column already exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'source_type' ) );

	if ( $column_exists ) {
		return false; // Column already exists, no migration needed
	}

	// Add column (after usage_status)
	wptsall_db_alter_table(
		$table_name,
		"ADD COLUMN source_type VARCHAR(20) DEFAULT 'auto' COMMENT 'Source type: auto=auto-scanned, manual=manually created' AFTER usage_status"
	);

	// Add index
	wptsall_db_alter_table( $table_name, 'ADD INDEX idx_source_type (source_type)' );

	// Initialize existing data to 'auto'
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE %i SET source_type = 'auto' WHERE source_type IS NULL",
			$table_name
		)
	);

	wptsall_log(
		'database',
		'info',
		'Migration: added source_type field to models table',
		array( 'table' => $table_name )
	);

	return true;
}

/**
 * Migration: add plugin scan fields to models table (merge plugin_mappings)
 *
 * @since 1.0.1
 * @return bool Whether migration was executed
 */
function wptsall_migrate_models_add_plugin_fields() {
	global $wpdb;

	$table_name = wptsall_table( 'models' );

	if ( ! wptsall_models_table_exists() ) {
		return false;
	}

	$columns = array(
		'plugin_file'      => "ADD COLUMN plugin_file VARCHAR(255) COMMENT 'Plugin main file path' AFTER description",
		'is_content_plugin'=> "ADD COLUMN is_content_plugin TINYINT(1) DEFAULT 0 COMMENT 'Whether it is a content plugin' AFTER plugin_file",
		'meta_fields'      => "ADD COLUMN meta_fields LONGTEXT COMMENT 'Registered meta fields (JSON)' AFTER taxonomies",
		'custom_tables'    => "ADD COLUMN custom_tables LONGTEXT COMMENT 'Custom database tables (JSON)' AFTER meta_fields",
		'scan_result'      => "ADD COLUMN scan_result LONGTEXT COMMENT 'Scan result (JSON)' AFTER scan_version",
		'scan_status'      => "ADD COLUMN scan_status VARCHAR(20) DEFAULT 'pending' COMMENT 'Scan status: pending/scanning/completed/failed' AFTER scan_result",
		'scan_error'       => "ADD COLUMN scan_error TEXT COMMENT 'Error message on scan failure' AFTER scan_status",
		'user_consent'     => "ADD COLUMN user_consent TINYINT(1) DEFAULT 0 COMMENT 'Whether user has consented to scanning' AFTER scan_error",
		'consent_at'       => "ADD COLUMN consent_at DATETIME COMMENT 'Consent time' AFTER user_consent",
		'detection_method' => "ADD COLUMN detection_method VARCHAR(50) COMMENT 'Detection method: static/runtime/hybrid/v4_smart' AFTER consent_at",
	);

	$migrated = false;

	foreach ( $columns as $column => $alter_sql ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, $column ) );
		if ( ! $exists ) {
			wptsall_db_alter_table( $table_name, $alter_sql );
			$migrated = true;
		}
	}

	// Add indexes if missing.
	$indexes = array(
		'idx_content'     => 'ADD INDEX idx_content (is_content_plugin)',
		'idx_scan_status' => 'ADD INDEX idx_scan_status (scan_status)',
		'idx_user_consent'=> 'ADD INDEX idx_user_consent (user_consent)',
	);

	foreach ( $indexes as $key => $index_sql ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$index_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, $key ) );
		if ( ! $index_exists ) {
			wptsall_db_alter_table( $table_name, $index_sql );
			$migrated = true;
		}
	}

	if ( $migrated ) {
		wptsall_log(
			'database',
			'info',
			'Migration: added plugin scan fields to models table',
			array( 'table' => $table_name )
		);
	}

	return $migrated;
}

/**
 * Migration: upgrade translation_rules table to v0.8.0 structure
 *
 * Changes:
 * - Remove old fields: translate_fields, sync_fields, field_mappings, compute_fields, hooks
 * - Add new fields: direction, sync_mode, field_capabilities
 *
 * @since 0.8.0
 * @return bool Whether migration was executed
 */
function wptsall_migrate_translation_rules_v080() {
	global $wpdb;

	$table_name = wptsall_table( 'translation_rules' );

	// Check if table exists
	if ( ! wptsall_translation_rules_table_exists() ) {
		return false;
	}

	$migrated = false;

	// 1. Check and add direction field
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$direction_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'direction' ) );

	if ( ! $direction_exists ) {
		wptsall_db_alter_table(
			$table_name,
			"ADD COLUMN direction VARCHAR(30) DEFAULT 'source_to_target' COMMENT 'Default sync direction: source_to_target/bidirectional' AFTER meta_table"
		);
		$migrated = true;
	}

	// 2. Check and add sync_mode field
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$sync_mode_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'sync_mode' ) );

	if ( ! $sync_mode_exists ) {
		wptsall_db_alter_table(
			$table_name,
			"ADD COLUMN sync_mode VARCHAR(20) DEFAULT 'new_only' COMMENT 'Sync mode: new_only (create-only sync)' AFTER direction"
		);
		$migrated = true;
	}

	// 3. Check and add field_capabilities field
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$field_capabilities_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'field_capabilities' ) );

	if ( ! $field_capabilities_exists ) {
		wptsall_db_alter_table(
			$table_name,
			"ADD COLUMN field_capabilities LONGTEXT COMMENT 'Unified field capabilities config (JSON object)' AFTER sync_mode"
		);
		$migrated = true;
	}

	// 4. Remove old fields (dev environment only)
	$old_fields = array( 'translate_fields', 'sync_fields', 'field_mappings', 'compute_fields', 'hooks' );

	foreach ( $old_fields as $field ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$field_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, $field ) );

		if ( $field_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table_name, $field ) );
			$migrated = true;
		}
	}

	// 5. Add index
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$index_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, 'idx_direction' )
		);

	if ( ! $index_exists && $direction_exists ) {
		wptsall_db_alter_table( $table_name, 'ADD INDEX idx_direction (direction)' );
	}

	if ( $migrated ) {
		wptsall_log(
			'database',
			'info',
			'Migration: upgraded translation_rules table to v0.8.0',
			array(
				'table'          => $table_name,
				'added_fields'   => array( 'direction', 'sync_mode', 'field_capabilities' ),
				'removed_fields' => $old_fields,
			)
		);
	}

	return $migrated;
}

/**
 * Migration: add custom_tables field to plugin_mappings table (ISS-MOD-019)
 *
 * Support scanning plugins with custom tables only.
 *
 * @since 0.9.1
 * @return bool Whether migration was executed
 */
function wptsall_migrate_add_custom_tables_field() {
	global $wpdb;

	$table_name = wptsall_table( 'plugin_mappings' );

	// Check if table exists
	if ( ! wptsall_plugin_mappings_table_exists() ) {
		return false;
	}

	// Check if column already exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'custom_tables' ) );

	if ( $column_exists ) {
		return false; // Column already exists, no migration needed
	}

	// Add column (after meta_fields)
	wptsall_db_alter_table(
		$table_name,
		"ADD COLUMN custom_tables LONGTEXT COMMENT 'Custom database tables (JSON)' AFTER meta_fields"
	);

	wptsall_log(
		'database',
		'info',
		'Migration: added custom_tables field to plugin_mappings (ISS-MOD-019)',
		array( 'table' => $table_name )
	);

	return true;
}

/**
 * Migration: add unique key constraint to prevent duplicate rules (ISS-MOD-001)
 *
 * Fix model scanner creating duplicate translation rules.
 * Add unique key constraint: UNIQUE (model_id, data_type, object_name, url_type)
 *
 * @since 0.9.0
 * @return bool Whether migration was executed
 */
function wptsall_migrate_add_unique_rule_constraint() {
	global $wpdb;

	$table_name = wptsall_table( 'translation_rules' );

	// Check if table exists
	if ( ! wptsall_translation_rules_table_exists() ) {
		return false;
	}

	// Check if unique key already exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$index_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, 'unique_rule' )
		);

	if ( $index_exists ) {
		return false; // Constraint already exists, no migration needed
	}

	// Clean up duplicate data before adding unique key constraint
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$duplicates_cleaned = $wpdb->query(
		$wpdb->prepare(
			'DELETE t1 FROM %i t1
			INNER JOIN (
				SELECT MIN(id) as min_id, model_id, data_type, object_name, url_type
				FROM %i
				GROUP BY model_id, data_type, object_name, url_type
				HAVING COUNT(*) > 1
			) t2 ON t1.model_id = t2.model_id
				AND t1.data_type = t2.data_type
				AND t1.object_name = t2.object_name
				AND t1.url_type = t2.url_type
				AND t1.id > t2.min_id',
			$table_name,
			$table_name
		)
	);

	// Add unique key constraint
	wptsall_db_alter_table(
		$table_name,
		'ADD UNIQUE KEY unique_rule (model_id, data_type, object_name, url_type)'
	);

	wptsall_log(
		'database',
		'info',
		'Migration: added unique constraint to translation_rules (ISS-MOD-001)',
		array(
			'table'              => $table_name,
			'duplicates_cleaned' => $duplicates_cleaned,
		)
	);

	return true;
}
