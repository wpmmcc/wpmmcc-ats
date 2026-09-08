<?php
/**
 * WPTSALL Templates Table Schema
 *
 * Templates module database table schema definitions
 *
 * @package WPTSALL\Templates
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
 * Create templates table
 *
 * Stores language pack scan information for each site relation.
 */
function wptsall_create_templates_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'templates' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK to site_relations.id, 0 means system template',
		slug VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Template unique identifier',

		-- Scan source
		source_type VARCHAR(20) NOT NULL DEFAULT 'core' COMMENT 'core / theme / plugin / config',
		source_identifier VARCHAR(100) DEFAULT '' COMMENT 'Source identifier (theme/plugin slug)',
		text_domain VARCHAR(100) NOT NULL COMMENT 'Translation domain',
		source_name VARCHAR(255) DEFAULT '' COMMENT 'Theme/plugin name',
		source_version VARCHAR(50) DEFAULT '' COMMENT 'Version number',

		-- Language
		source_language VARCHAR(20) DEFAULT 'en_US' COMMENT 'Source language code',
		target_language VARCHAR(20) DEFAULT '' COMMENT 'Target language code',

		-- Statistics
		total_entries INT UNSIGNED DEFAULT 0 COMMENT 'Total entries count',
		translated_entries INT UNSIGNED DEFAULT 0 COMMENT 'Translated entries count',
		reviewed_entries INT UNSIGNED DEFAULT 0 COMMENT 'Reviewed entries count',

		-- Status
		status VARCHAR(20) DEFAULT 'active' COMMENT 'active/pending/scanned/translating/completed',
		last_scanned_at DATETIME DEFAULT NULL COMMENT 'Last scanned time',

		-- Timestamps
		created_at DATETIME NOT NULL COMMENT 'Created time',
		updated_at DATETIME NOT NULL COMMENT 'Updated time',

		PRIMARY KEY (id),
		INDEX idx_relation (relation_id),
		INDEX idx_relation_source_type_id (relation_id, source_type, id),
		INDEX idx_domain (text_domain),
		INDEX idx_status (status),
		UNIQUE KEY unique_slug (slug),
		UNIQUE KEY unique_template (relation_id, source_type, text_domain)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Language pack templates table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	wptsall_ensure_template_language_columns();

	wptsall_log(
		'database',
		'info',
		'Templates table created',
		array(
			'table'   => $table_name,
			'version' => WPTSALL_VERSION,
		)
	);
}

/**
 * Widen template language columns for locale/provider identifiers.
 *
 * @return void
 */
function wptsall_ensure_template_language_columns() {
	global $wpdb;
	$table = wptsall_table( 'templates' );
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return;
	}
	foreach ( array( 'source_language', 'target_language' ) as $language_column ) {
		$column = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $language_column ),
			ARRAY_A
		);
		$type = strtolower( (string) ( $column['Type'] ?? '' ) );
		if ( preg_match( '/^varchar\((\d+)\)/', $type, $matches ) && (int) $matches[1] < 20 ) {
			$default = 'source_language' === $language_column ? 'en_US' : '';
			$label   = 'source_language' === $language_column ? 'Source' : 'Target';
			wptsall_db_alter_table(
				$table,
				'MODIFY COLUMN ' . $language_column . " VARCHAR(20) DEFAULT '" . $default . "' COMMENT '" . $label . " language code'"
			);
		}
	}
}

/**
 * Create template_entries table
 *
 * Stores individual translation entries.
 */
function wptsall_create_template_entries_table() {
	global $wpdb;
	$table_name      = wptsall_table( 'template_entries' );
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		template_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'FK to templates.id',

		-- Translation content
		msgid TEXT NOT NULL COMMENT 'Source string',
		msgid_plural TEXT COMMENT 'Plural form',
		msgctxt VARCHAR(255) DEFAULT '' COMMENT 'Context',
		msgstr TEXT COMMENT 'Translation result',
		msgstr_plural TEXT COMMENT 'Plural translation',

		-- Status
		status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending/translated/reviewed/skipped',
		source VARCHAR(20) DEFAULT 'scan' COMMENT 'scan/auto/manual',
		claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for entry lock',
		claim_owner_hash CHAR(64) DEFAULT NULL COMMENT 'Hashed device/relation claim owner',

		-- Metadata
		reference TEXT COMMENT 'Occurrence location (file:line)',
		note TEXT COMMENT 'Note',

		-- Timestamps
		created_at DATETIME NOT NULL COMMENT 'Created time',
		updated_at DATETIME NOT NULL COMMENT 'Updated time',

		PRIMARY KEY (id),
		INDEX idx_template (template_id),
		INDEX idx_template_status_claimed_id (template_id, status, claimed_at, id),
		INDEX idx_status (status),
		INDEX idx_source (source),
		INDEX idx_claimed_at (claimed_at),
		INDEX idx_claim_owner (claim_owner_hash),
		UNIQUE KEY unique_entry (template_id, msgid(191), msgctxt)
	) {$charset_collate} ENGINE=InnoDB COMMENT='Language pack translation entries table';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_log(
		'database',
		'info',
		'Template entries table created',
		array(
			'table'   => $table_name,
			'version' => WPTSALL_VERSION,
		)
	);
}

/**
 * Check if templates table exists
 *
 * @return bool
 */
function wptsall_templates_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'templates' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
	);

	return $result === $table_name;
}

/**
 * Check if template_entries table exists
 *
 * @return bool
 */
function wptsall_template_entries_table_exists() {
	global $wpdb;
	$table_name = wptsall_table( 'template_entries' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
	);

	return $result === $table_name;
}

/**
 * Drop templates-related tables (for uninstall only)
 */
/**
 * Ensure templates-related tables exist
 */
function wptsall_ensure_templates_tables() {
	if ( ! wptsall_templates_table_exists() ) {
		wptsall_create_templates_table();
	} elseif ( function_exists( 'wptsall_ensure_template_language_columns' ) ) {
		wptsall_ensure_template_language_columns();
	}

	if ( ! wptsall_template_entries_table_exists() ) {
		wptsall_create_template_entries_table();
	}
}

/**
 * Create system preset templates
 *
 * System templates use relation_id = 0 for general translation scenarios.
 *
 * @return void
 */
function wptsall_create_system_templates() {
	global $wpdb;
	$table = wptsall_table( 'templates' );

	// Check if wordpress-blog template already exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE slug = %s',
			$table,
			'wordpress-blog'
		)
	);

	if ( $existing > 0 ) {
		return; // Already exists, skip creation
	}

	// Create wordpress-blog preset template
	$now = current_time( 'mysql' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->insert(
		$table,
		array(
			'relation_id'        => 0,
			'slug'               => 'wordpress-blog',
			'source_type'        => 'core',
			'text_domain'        => 'wordpress-blog',
			'source_name'        => 'WordPress Blog Template',
			'source_version'     => '1.0.0',
			'total_entries'      => 5,
			'translated_entries' => 0,
			'reviewed_entries'   => 0,
			'status'             => 'active',
			'last_scanned_at'    => $now,
			'created_at'         => $now,
			'updated_at'         => $now,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
	);

	$template_id = $wpdb->insert_id;

	if ( ! $template_id ) {
		wptsall_log(
			'templates',
			'error',
			'Failed to create system template: wordpress-blog'
		);
		return;
	}

	// Create base entries
	$entries_table = wptsall_table( 'template_entries' );
	$entries       = array(
		array(
			'msgid'   => 'post_title',
			'msgctxt' => 'field',
		),
		array(
			'msgid'   => 'post_content',
			'msgctxt' => 'field',
		),
		array(
			'msgid'   => 'post_excerpt',
			'msgctxt' => 'field',
		),
		array(
			'msgid'   => 'post_name',
			'msgctxt' => 'field',
		),
		array(
			'msgid'   => 'post_meta',
			'msgctxt' => 'field',
		),
	);

	foreach ( $entries as $entry ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$entries_table,
			array(
				'template_id' => $template_id,
				'msgid'       => $entry['msgid'],
				'msgctxt'     => $entry['msgctxt'],
				'msgstr'      => '',
				'status'      => 'pending',
				'source'      => 'system',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	wptsall_log(
		'templates',
		'info',
		'System template created: wordpress-blog',
		array(
			'template_id' => $template_id,
			'entries'     => count( $entries ),
		)
	);
}

/**
 * Get templates module statistics
 *
 * @return array
 */
