<?php
/**
 * WPTSALL Database Migrations
 *
 * Handles database schema updates and migrations.
 *
 * @package WPTSALL
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run all pending database migrations.
 */
function wptsall_run_migrations() {
	$current_version = get_option( 'wptsall_db_version', '0.0.0' );
	$plugin_version  = '2.1.0';

	// Keep task language columns wide enough for all supported locale/provider
	// identifiers. This is intentionally checked on every migration pass so an
	// installation whose recorded DB version already predates the schema fix is
	// repaired without requiring a deactivate/reactivate cycle.
	$tasks_schema = dirname( __DIR__, 2 ) . '/tasks/database/schema-tasks.php';
	if ( is_readable( $tasks_schema ) ) {
		require_once $tasks_schema;
		if ( function_exists( 'wptsall_ensure_task_language_columns' ) ) {
			wptsall_ensure_task_language_columns();
		}
	}

	// Ensure new model object tables exist even when db_version doesn't change.
	// This keeps developer upgrades safe without requiring de/activate.
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-models.php';
	if ( function_exists( 'wptsall_create_model_objects_table' ) && function_exists( 'wptsall_create_model_object_fields_table' ) ) {
		global $wpdb;

		$model_objects_table = wptsall_table( 'model_objects' );
		$model_fields_table  = wptsall_table( 'model_object_fields' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$objects_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $model_objects_table ) );
		if ( $objects_exists !== $model_objects_table ) {
			wptsall_create_model_objects_table();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$fields_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $model_fields_table ) );
		if ( $fields_exists !== $model_fields_table ) {
			wptsall_create_model_object_fields_table();
		}
	}

	// P1-TEST-01 (2026-09-02): ensure the field-mapping family on every
	// migration pass, same pattern as model_objects above. Version-gated
	// v0.5.0 alone is not enough: installs that re-activate with a persisted
	// wptsall_db_version (slot re-provisioning, partial restores) skipped
	// v0.5.0 entirely and lost post/term/media_mappings until the next
	// version bump.
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-field-mappings.php';
	if ( function_exists( 'wptsall_create_field_mapping_tables' ) ) {
		global $wpdb;

		$mapping_tables = array(
			'post_mappings'  => 'wptsall_post_mappings_table_exists',
			'term_mappings'  => 'wptsall_term_mappings_table_exists',
			'media_mappings' => 'wptsall_media_mappings_table_exists',
		);

		foreach ( $mapping_tables as $mapping_key => $mapping_checker ) {
			if ( function_exists( $mapping_checker ) && ! call_user_func( $mapping_checker ) ) {
				wptsall_create_field_mapping_tables();
				break;
			}
		}
	}

	// Ensure languages table exists even when db_version is already current.
	// Manual multilingual setup must not depend on a client/matrix fixture.
	require_once dirname( __DIR__, 2 ) . '/languages/database/schema-languages.php';
	if ( function_exists( 'wptsall_create_languages_table' ) ) {
		global $wpdb;
		$languages_table = wptsall_table( 'languages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$languages_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $languages_table ) );
		if ( $languages_exists !== $languages_table ) {
			wptsall_create_languages_table();
		}
	}

	// Ensure strings table exists even when db_version already at 1.2.5+
	// (v1.2.5 may have run before the 'strings' key was added to wptsall_table()).
	require_once dirname( __DIR__, 2 ) . '/strings/database/schema-strings.php';
	if ( function_exists( 'wptsall_create_strings_table' ) ) {
		global $wpdb;
		$strings_table = wptsall_table( 'strings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$strings_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $strings_table ) );
		if ( $strings_exists !== $strings_table ) {
			wptsall_create_strings_table();
		}
	}

	// Translation Memory (admin TM page) — ensure even when db_version already current.
	require_once dirname( __DIR__, 2 ) . '/translation-memory/database/schema-translation-memory.php';
	if ( function_exists( 'wptsall_create_translation_memory_table' ) ) {
		global $wpdb;
		$tm_table = wptsall_table( 'translation_memory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tm_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tm_table ) );
		if ( $tm_exists !== $tm_table ) {
			wptsall_create_translation_memory_table();
		}
	}

	// Menu mappings (classic menu language clones / locations).
	require_once dirname( __DIR__, 2 ) . '/menu-translation/database/schema-menu-mappings.php';
	if ( function_exists( 'wptsall_create_menu_mappings_table' ) ) {
		global $wpdb;
		$menu_table = wptsall_table( 'menu_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$menu_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $menu_table ) );
		if ( $menu_exists !== $menu_table ) {
			wptsall_create_menu_mappings_table();
		}
	}

	// Client data rate limits use an atomic database counter rather than a
	// transient read/modify/write cycle. Ensure the table on every migration
	// pass, including installations whose recorded version is already current.
	require_once dirname( __DIR__, 2 ) . '/tasks/database/schema-client-rate-limits.php';
	if ( function_exists( 'wptsall_ensure_client_rate_limits_table' ) ) {
		wptsall_ensure_client_rate_limits_table();
	}

	// Option claims were introduced after the original field-mapping schema.
	// Ensure the table exists even when an installation already recorded the
	// current DB version and therefore will not revisit an older migration.
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-field-mappings.php';
	if ( function_exists( 'wptsall_create_option_sync_state_table' ) ) {
		global $wpdb;
		$option_state_table = wptsall_table( 'option_sync_state' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_state_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $option_state_table ) );
		if ( $option_state_exists !== $option_state_table ) {
			wptsall_create_option_sync_state_table();
		}
	}

	// v0.1.0 migrations
	if ( version_compare( $current_version, '0.1.0', '<' ) ) {
		wptsall_migrate_hooks_table();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.1.0',
			array(
				'from' => $current_version,
				'to'   => '0.1.0',
			)
		);
	}

	// v0.3.0 migrations
	if ( version_compare( $current_version, '0.3.0', '<' ) ) {
		wptsall_migrate_to_v030();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.3.0',
			array(
				'from' => $current_version,
				'to'   => '0.3.0',
			)
		);
	}

	// v0.3.1 migrations - Plugin mappings table
	if ( version_compare( $current_version, '0.3.1', '<' ) ) {
		wptsall_migrate_to_v031();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.3.1',
			array(
				'from' => $current_version,
				'to'   => '0.3.1',
			)
		);
	}

	// v0.4.0 migrations - Site relations restructure
	if ( version_compare( $current_version, '0.4.0', '<' ) ) {
		wptsall_migrate_to_v040();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.4.0',
			array(
				'from' => $current_version,
				'to'   => '0.4.0',
			)
		);
	}

	// v0.5.0 migrations - Field mappings and enhanced translation rules
	if ( version_compare( $current_version, '0.5.0', '<' ) ) {
		wptsall_migrate_to_v050();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.5.0',
			array(
				'from' => $current_version,
				'to'   => '0.5.0',
			)
		);
	}

	// v0.6.0 migrations - Multi-model relations, global templates
	if ( version_compare( $current_version, '0.6.0', '<' ) ) {
		wptsall_migrate_to_v060();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.6.0',
			array(
				'from' => $current_version,
				'to'   => '0.6.0',
			)
		);
	}

	// v0.7.0 migrations - Add meta_fields to plugin_mappings
	if ( version_compare( $current_version, '0.7.0', '<' ) ) {
		wptsall_migrate_to_v070();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.7.0',
			array(
				'from' => $current_version,
				'to'   => '0.7.0',
			)
		);
	}

	// v0.7.1 migrations - Custom model support (source_type, link_chains)
	if ( version_compare( $current_version, '0.7.1', '<' ) ) {
		wptsall_migrate_to_v071();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.7.1',
			array(
				'from' => $current_version,
				'to'   => '0.7.1',
			)
		);
	}

	// v0.8.0 migrations - Translation rules restructure, relation configs, virtual site storage
	if ( version_compare( $current_version, '0.8.0', '<' ) ) {
		wptsall_migrate_to_v080();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.8.0',
			array(
				'from' => $current_version,
				'to'   => '0.8.0',
			)
		);
	}

	// v0.8.1 migrations - Site relations sync_mode, direction, conflict_strategy
	if ( version_compare( $current_version, '0.8.1', '<' ) ) {
		wptsall_migrate_to_v081();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v0.8.1',
			array(
				'from' => $current_version,
				'to'   => '0.8.1',
			)
		);
	}

	// v1.0.1 migrations - Merge plugin scan fields into models table
	if ( version_compare( $current_version, '1.0.1', '<' ) ) {
		require_once dirname( __DIR__, 2 ) . '/models/database/schema-models.php';
		if ( function_exists( 'wptsall_migrate_models_add_plugin_fields' ) ) {
			wptsall_migrate_models_add_plugin_fields();
		} else {
			wptsall_log( 'migration', 'error', 'Function wptsall_migrate_models_add_plugin_fields not found' );
		}
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.0.1',
			array(
				'from' => $current_version,
				'to'   => '1.0.1',
			)
		);
	}

	// v1.0.3 migrations - ISS-MOD-027: Migrate manual fields to model_object_fields
	if ( version_compare( $current_version, '1.0.3', '<' ) ) {
		require_once dirname( __DIR__, 2 ) . '/models/database/migrate-manual-fields-to-object-fields.php';
		if ( function_exists( 'wptsall_migrate_manual_fields_to_object_fields' ) ) {
			wptsall_migrate_manual_fields_to_object_fields();
		} else {
			wptsall_log( 'migration', 'error', 'Function wptsall_migrate_manual_fields_to_object_fields not found' );
		}
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.0.3',
			array(
				'from' => $current_version,
				'to'   => '1.0.3',
			)
		);
	}

	// v1.0.4 migrations - ISS-SIT-037: Normalize sync_mode to 'new_only'
	if ( version_compare( $current_version, '1.0.4', '<' ) ) {
		require_once dirname( __DIR__, 2 ) . '/sites/database/migrate-sync-mode-to-new-only.php';
		if ( function_exists( 'wptsall_migrate_sync_mode_to_new_only' ) ) {
			wptsall_migrate_sync_mode_to_new_only();
		} else {
			wptsall_log( 'migration', 'error', 'Function wptsall_migrate_sync_mode_to_new_only not found' );
		}
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.0.4',
			array(
				'from' => $current_version,
				'to'   => '1.0.4',
			)
		);
	}

	// v1.0.6 migrations - Drop redundant idx_slug index from templates table
	if ( version_compare( $current_version, '1.0.6', '<' ) ) {
		wptsall_migrate_drop_redundant_idx_slug();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.0.6',
			array(
				'from' => $current_version,
				'to'   => '1.0.6',
			)
		);
	}

	// v1.1.0 migrations - Add SSOT columns to model_object_fields table
	if ( version_compare( $current_version, '1.1.0', '<' ) ) {
		require_once dirname( __DIR__, 2 ) . '/models/database/migrate-model-object-fields-v2.php';
		if ( function_exists( 'wptsall_migrate_model_object_fields_v2' ) ) {
			wptsall_migrate_model_object_fields_v2();
		} else {
			wptsall_log( 'migration', 'error', 'Function wptsall_migrate_model_object_fields_v2 not found' );
		}
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.1.0',
			array(
				'from' => $current_version,
				'to'   => '1.1.0',
			)
		);
	}

	// v1.2.0 migrations - Add needs_resync/claimed_at to post_mappings
	if ( version_compare( $current_version, '1.2.0', '<' ) ) {
		wptsall_migrate_post_mappings_add_resync_columns();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.0',
			array(
				'from' => $current_version,
				'to'   => '1.2.0',
			)
		);
	}

	// v1.2.1 migrations - Backfill relation/claim/semantic columns for legacy tables.
	if ( version_compare( $current_version, '1.2.1', '<' ) ) {
		wptsall_migrate_backfill_schema_columns_v121();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.1',
			array(
				'from' => $current_version,
				'to'   => '1.2.1',
			)
		);
	}

	// v1.2.2 migrations - Add composite indexes for high-frequency client/test flows.
	if ( version_compare( $current_version, '1.2.2', '<' ) ) {
		wptsall_migrate_add_query_optimization_indexes_v122();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.2',
			array(
				'from' => $current_version,
				'to'   => '1.2.2',
			)
		);
	}

	// v1.2.3 migrations - Replace hard task uniqueness with app-level open-task dedupe.
	if ( version_compare( $current_version, '1.2.3', '<' ) ) {
		wptsall_migrate_tasks_open_dedupe_index_v123();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.3',
			array(
				'from' => $current_version,
				'to'   => '1.2.3',
			)
		);
	}

	// v1.2.4 migrations - Backfill relation_id for legacy mapping rows.
	if ( version_compare( $current_version, '1.2.4', '<' ) ) {
		wptsall_migrate_backfill_mapping_relation_ids_v124();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.4',
			array(
				'from' => $current_version,
				'to'   => '1.2.4',
			)
		);
	}

	// v1.2.5 migrations - Layer B wptsall_strings table.
	if ( version_compare( $current_version, '1.2.5', '<' ) ) {
		wptsall_migrate_strings_table_v125();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.5',
			array(
				'from' => $current_version,
				'to'   => '1.2.5',
			)
		);
	}

	// v1.2.6 — classic menu language mappings.
	if ( version_compare( $current_version, '1.2.6', '<' ) ) {
		wptsall_migrate_menu_mappings_table_v126();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.6',
			array(
				'from' => $current_version,
				'to'   => '1.2.6',
			)
		);
	}

	// v1.2.7 — job snapshots + callback request hash (ISS P-A10 / S3).
	if ( version_compare( $current_version, '1.2.7', '<' ) ) {
		wptsall_migrate_translation_results_snapshots_v127();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.7',
			array(
				'from' => $current_version,
				'to'   => '1.2.7',
			)
		);
	}

	// v1.2.8 — media mappings participate in the same resync/claim lifecycle
	// as post and term mappings (ISS P-A1).
	if ( version_compare( $current_version, '1.2.8', '<' ) ) {
		wptsall_migrate_media_mappings_add_resync_columns_v128();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.8',
			array(
				'from' => $current_version,
				'to'   => '1.2.8',
			)
		);
	}

	// v1.2.9 — durable lifecycle outbox (ISS P-A1).
	if ( version_compare( $current_version, '1.2.9', '<' ) ) {
		wptsall_migrate_content_change_outbox_v129();
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.2.9',
			array(
				'from' => $current_version,
				'to'   => '1.2.9',
			)
		);
	}

	// v1.3.0 — relation-scoped media URL/ID replacement must work on every
	// existing site, not only newly-created mapping tables (ISS P-A1).
	if ( version_compare( $current_version, '1.3.0', '<' ) ) {
		wptsall_migrate_media_mappings_relation_id_v130( true );
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v1.3.0',
			array( 'from' => $current_version, 'to' => '1.3.0' )
		);
	}

	// v2.1.0 — durable claim owner digests and relation-scoped option state.
	// Run the column/index check even when db_version is already 2.1.0: an
	// interrupted dbDelta/ALTER or a partially restored backup must self-heal.
	$claim_owner_schema_upgrade = version_compare( $current_version, '2.1.0', '<' );
	wptsall_migrate_claim_owner_columns_v210();
	wptsall_migrate_legacy_mapping_relation_id_v210();
	wptsall_migrate_relation_scoped_mapping_unique_keys_v210();
	if ( $claim_owner_schema_upgrade ) {
		wptsall_log(
			'migration',
			'info',
			'Database migrated to v2.1.0 claim owner schema',
			array( 'from' => $current_version, 'to' => '2.1.0' )
		);
	}

	// Update version
	if ( version_compare( $current_version, $plugin_version, '<' ) ) {
		update_option( 'wptsall_db_version', $plugin_version );
	}
}
add_action( 'wptsall_activate', 'wptsall_run_migrations' );
add_action( 'admin_init', 'wptsall_run_migrations' );

/**
 * Migrate hooks table to add new fields.
 */
function wptsall_migrate_hooks_table() {
	global $wpdb;
	$table = wptsall_table( 'hooks' );

	// P1-TEST-01 (2026-09-02): the hooks table was removed in v0.9.0
	// (hooks are auto-generated). On a fresh install this ALTER ran against
	// a nonexistent table and emitted DB errors that fail wp-cli
	// activations ("unexpected output").
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$hooks_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $hooks_table_exists !== $table ) {
		return;
	}

	// Check if last_triggered column exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$last_triggered_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'last_triggered' ) );

	if ( ! $last_triggered_exists ) {
		// Add last_triggered tracking.
		wptsall_db_alter_table(
			$table,
			'ADD COLUMN last_triggered DATETIME DEFAULT NULL AFTER updated_at,
			ADD COLUMN trigger_count INT DEFAULT 0 AFTER last_triggered,
			ADD INDEX idx_last_triggered (last_triggered)'
		);
	}
}

/**
 * Migrate to v0.3.0
 *
 * - Create site_relations table
 * - Create hooks table (new structure)
 * - Create models table (new)
 * - Create model_url_rules table (new)
 * - Modify virtual_sites table (add subtitle, logo_url, blog sync fields)
 *
 * @since 0.3.0
 */
function wptsall_migrate_to_v030() {
	// Load schema files from respective modules
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-site-relations.php';
	// Note: schema-hooks.php removed in v0.9.0 (deprecated since v0.7.0)
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-models.php';

	// Create new tables with safety checks
	if ( function_exists( 'wptsall_create_site_relations_table' ) ) {
		wptsall_create_site_relations_table();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_site_relations_table not found' );
	}

	// Note: wptsall_create_hooks_table removed in v0.9.0 (deprecated since v0.7.0)
	// Hooks are now auto-generated by Hook_Manager, no database table needed.

	if ( function_exists( 'wptsall_create_model_tables' ) ) {
		wptsall_create_model_tables();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_model_tables not found' );
	}

	// Modify existing tables
	wptsall_migrate_virtual_sites_table_v030();

	// Migrate existing templates to new model tables
	wptsall_migrate_templates_to_models();

	wptsall_log(
		'migration',
		'info',
		'v0.3.0 migration completed',
		array(
			'tables_created'  => array( 'site_relations', 'hooks', 'models', 'model_url_rules' ),
			'tables_modified' => array( 'virtual_sites' ),
		)
	);
}

/**
 * Migrate existing templates (stored in options) to new model tables
 *
 * @since 0.3.0
 */
function wptsall_migrate_templates_to_models() {
	global $wpdb;

	// Get all saved templates from options
	$templates = wptsall_saved_templates();

	if ( empty( $templates ) ) {
		return;
	}

	$models_table = wptsall_table( 'models' );
	$rules_table  = wptsall_table( 'model_url_rules' );
	$migrated     = 0;

	foreach ( $templates as $slug => $plugin_name ) {
		// Check if already migrated
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', $models_table, $slug )
		);

		if ( $exists ) {
			continue;
		}

		// Get full template data
		$template = get_option( 'wptsall_template_' . $slug );

		if ( ! is_array( $template ) ) {
			continue;
		}

		// Insert model
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$models_table,
			array(
				'slug'          => $slug,
				'plugin_slug'   => $template['plugin'] ?? $slug,
				'plugin_name'   => $plugin_name,
				'version'       => $template['version'] ?? '1.0.0',
				'description'   => '',
				'status'        => 'active',
				'is_system'     => ( 'wordpress-blog' === $slug ) ? 1 : 0,
				'field_summary' => wp_json_encode( array() ),
				'template_i18n' => wp_json_encode( array() ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		$model_id = $wpdb->insert_id;

		if ( $model_id ) {
			// Migrate URL rules from template
			wptsall_migrate_template_urls_to_rules( $model_id, $template, $rules_table );
			++$migrated;
		}
	}

	if ( $migrated > 0 ) {
		wptsall_log(
			'migration',
			'info',
			'Templates migrated to models',
			array( 'count' => $migrated )
		);
	}
}

/**
 * Migrate template URLs to model URL rules
 *
 * @param int    $model_id    Model ID.
 * @param array  $template    Template data.
 * @param string $rules_table Rules table name.
 */
function wptsall_migrate_template_urls_to_rules( $model_id, $template, $rules_table ) {
	global $wpdb;

	$now        = current_time( 'mysql' );
	$sort_order = 0;

	// Migrate post_types
	if ( ! empty( $template['objects']['post_types'] ) ) {
		foreach ( $template['objects']['post_types'] as $pt ) {
			$subtype  = $pt['subtype'] ?? $pt['name'] ?? '';
			$rule_id  = 'post_type_' . $subtype;
			$pattern  = '/' . $subtype . '/{slug}/';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$rules_table,
				array(
					'model_id'         => $model_id,
					'rule_id'          => $rule_id,
					'url_pattern'      => $pattern,
					'label'            => $pt['label'] ?? $subtype,
					'access_level'     => 'public',
					'object_type'      => 'post_type',
					'object_subtype'   => $subtype,
					'template_file'    => 'single-' . $subtype . '.php',
					'template_type'    => 'static',
					'template_domain'  => $template['plugin'] ?? '',
					'fields_translate' => wp_json_encode( $pt['fields'] ?? array() ),
					'fields_sync'      => wp_json_encode( array() ),
					'fields_exclude'   => wp_json_encode( array() ),
					'sort_order'       => $sort_order++,
					'status'           => 'active',
					'auto_detected'    => 1,
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
			);
		}
	}

	// Migrate taxonomies
	if ( ! empty( $template['objects']['taxonomies'] ) ) {
		foreach ( $template['objects']['taxonomies'] as $tax ) {
			$subtype  = $tax['subtype'] ?? $tax['name'] ?? '';
			$rule_id  = 'taxonomy_' . $subtype;
			$pattern  = '/' . str_replace( '_', '-', $subtype ) . '/{term}/';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$rules_table,
				array(
					'model_id'         => $model_id,
					'rule_id'          => $rule_id,
					'url_pattern'      => $pattern,
					'label'            => $tax['label'] ?? $subtype,
					'access_level'     => 'public',
					'object_type'      => 'taxonomy',
					'object_subtype'   => $subtype,
					'template_file'    => 'taxonomy-' . $subtype . '.php',
					'template_type'    => 'static',
					'template_domain'  => $template['plugin'] ?? '',
					'fields_translate' => wp_json_encode( $tax['fields'] ?? array() ),
					'fields_sync'      => wp_json_encode( array() ),
					'fields_exclude'   => wp_json_encode( array() ),
					'sort_order'       => $sort_order++,
					'status'           => 'active',
					'auto_detected'    => 1,
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
			);
		}
	}
}

/**
 * Migrate virtual_sites table for v0.3.0
 *
 * Add new fields:
 * - subtitle (VARCHAR 255)
 * - logo_url (VARCHAR 255)
 * - enable_blog_sync (TINYINT)
 * - blog_source_site (BIGINT)
 *
 * @since 0.3.0
 */
function wptsall_migrate_virtual_sites_table_v030() {
	global $wpdb;
	$table = wptsall_table( 'virtual_sites' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$anchor_name = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'site_name' ) );
	$anchor_id   = $anchor_name ? ' AFTER site_name' : '';

	$columns = array(
		'subtitle'          => "ADD COLUMN subtitle VARCHAR(255) DEFAULT '' COMMENT 'Subtitle'{$anchor_id}",
		'logo_url'          => "ADD COLUMN logo_url VARCHAR(255) DEFAULT '' COMMENT 'Site logo URL'",
		'enable_blog_sync'  => "ADD COLUMN enable_blog_sync TINYINT(1) DEFAULT 0 COMMENT 'Whether blog sync is enabled'",
		'blog_source_site'  => "ADD COLUMN blog_source_site BIGINT(20) DEFAULT NULL COMMENT 'Blog source site ID'",
	);

	$added = array();
	foreach ( $columns as $column => $ddl ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
		if ( $exists ) {
			continue;
		}
		wptsall_db_alter_table( $table, $ddl );
		$added[] = $column;
	}

	if ( ! empty( $added ) ) {
		wptsall_log(
			'migration',
			'info',
			'Virtual sites table migrated',
			array( 'fields_added' => $added )
		);
	}
}

/**
 * Get database schema version.
 *
 * @return string Database version.
 */
/**
 * Migrate to v0.3.1
 *
 * - Create plugin_mappings table
 *
 * @since 0.3.1
 */
function wptsall_migrate_to_v031() {
	// Load schema file from models module (plugin_mappings merged into schema-models.php)
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-models.php';

	// Create plugin mappings table with safety check
	if ( function_exists( 'wptsall_create_plugin_mappings_table' ) ) {
		wptsall_create_plugin_mappings_table();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_plugin_mappings_table not found' );
		return;
	}

	wptsall_log(
		'migration',
		'info',
		'v0.3.1 migration completed',
		array(
			'tables_created' => array( 'plugin_mappings' ),
		)
	);
}

function wptsall_get_db_version() {
	return get_option( 'wptsall_db_version', '0.0.0' );
}

/**
 * Migrate to v0.4.0
 *
 * Site relations restructure:
 * - Change from JSON array (target_sites) to one-to-one structure
 * - Add source_lang field
 * - Add theme info fields (source_theme_name, source_theme_path, target_theme_name, target_theme_path)
 * - Update unique constraint to quintuple
 *
 * @since 0.4.0
 */
function wptsall_migrate_to_v040() {
	global $wpdb;
	$table = wptsall_table( 'site_relations' );

	// Check if already migrated (check for source_lang column)
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$source_lang_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'source_lang' ) );

	if ( $source_lang_exists ) {
		// Already migrated
		wptsall_log(
			'migration',
			'info',
			'v0.4.0 migration skipped - already migrated'
		);
		return;
	}

	// Check if old structure exists (target_sites column)
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$target_sites_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'target_sites' ) );

	if ( ! $target_sites_exists ) {
		// Fresh install - just recreate with new schema
		// Load schema file first if not already loaded
		require_once dirname( __DIR__, 2 ) . '/sites/database/schema-site-relations.php';

		if ( function_exists( 'wptsall_create_site_relations_table' ) ) {
			wptsall_create_site_relations_table();
			wptsall_log(
				'migration',
				'info',
				'v0.4.0 migration: Fresh install, created new table structure'
			);
		} else {
			wptsall_log( 'migration', 'error', 'Function wptsall_create_site_relations_table not found in v0.4.0 migration' );
		}
		return;
	}

	// Backup existing data
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$old_relations = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );

	if ( empty( $old_relations ) ) {
		// No data to migrate, just alter table
		wptsall_alter_site_relations_table_v040();
		wptsall_log(
			'migration',
			'info',
			'v0.4.0 migration: No data to migrate, altered table structure'
		);
		return;
	}

	// Store backup in option for safety
	update_option( 'wptsall_site_relations_backup_v040', $old_relations );

	// Create new table with temporary name
	$temp_table = $table . '_new';
	wptsall_create_site_relations_table_v040_temp( $temp_table );

	// Migrate data
	$migrated_count = 0;
	$now            = current_time( 'mysql' );

	foreach ( $old_relations as $relation ) {
		$target_sites = json_decode( $relation['target_sites'], true );

		if ( ! is_array( $target_sites ) || empty( $target_sites ) ) {
			continue;
		}

		// Get source site language
		$source_lang = '';
		if ( is_multisite() ) {
			switch_to_blog( $relation['source_site_id'] );
			$source_lang = get_option( 'WPLANG', 'en_US' );
			if ( empty( $source_lang ) ) {
				$source_lang = 'en_US';
			}
			restore_current_blog();
		} else {
			$source_lang = get_option( 'WPLANG', 'en_US' );
			if ( empty( $source_lang ) ) {
				$source_lang = 'en_US';
			}
		}

		// Get source theme info
		$source_theme = wptsall_get_site_theme_info_for_migration( $relation['source_site_id'] );

		// Migrate each target site as separate record
		foreach ( $target_sites as $target ) {
			$target_id   = $target['id'] ?? '';
			$target_type = $target['type'] ?? 'wp';
			$target_lang = $target['lang_to'] ?? $target['lang'] ?? '';

			// Get target theme info (if wp site)
			$target_theme = array( 'name' => '', 'path' => '' );
			if ( 'wp' === $target_type && is_numeric( $target_id ) ) {
				$target_theme = wptsall_get_site_theme_info_for_migration( (int) $target_id );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$temp_table,
				array(
					'source_site_id'    => (int) $relation['source_site_id'],
					'source_site_type'  => $relation['source_site_type'] ?? 'wp',
					'source_lang'       => $source_lang,
					'source_theme_name' => $source_theme['name'],
					'source_theme_path' => $source_theme['path'],
					'template'          => $relation['template'],
					'target_site_id'    => (string) $target_id,
					'target_site_type'  => $target_type,
					'target_lang'       => $target_lang,
					'target_theme_name' => $target_theme['name'],
					'target_theme_path' => $target_theme['path'],
					'status'            => $relation['status'] ?? 'active',
					'plugin_status'     => $relation['plugin_status'],
					'created_at'        => $relation['created_at'] ?? $now,
					'updated_at'        => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			++$migrated_count;
		}
	}

	// Swap tables
	$backup_table = $table . '_backup_v040';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $table, $backup_table, $temp_table, $table ) );

	wptsall_log(
		'migration',
		'info',
		'v0.4.0 migration completed',
		array(
			'old_records'     => count( $old_relations ),
			'migrated_records' => $migrated_count,
			'backup_table'    => $backup_table,
		)
	);
}

/**
 * Create temporary site relations table for v0.4.0 migration
 *
 * @param string $table_name Table name.
 */
function wptsall_create_site_relations_table_v040_temp( $table_name ) {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		source_site_id BIGINT(20) UNSIGNED NOT NULL,
		source_site_type VARCHAR(20) DEFAULT 'wp',
		source_lang VARCHAR(20) NOT NULL DEFAULT '',
		source_theme_name VARCHAR(255) DEFAULT '',
		source_theme_path VARCHAR(255) DEFAULT '',
		template VARCHAR(100) NOT NULL,
		target_site_id VARCHAR(50) NOT NULL,
		target_site_type VARCHAR(20) NOT NULL DEFAULT 'wp',
		target_lang VARCHAR(20) NOT NULL DEFAULT '',
		target_theme_name VARCHAR(255) DEFAULT '',
		target_theme_path VARCHAR(255) DEFAULT '',
		status VARCHAR(20) DEFAULT 'active',
		plugin_status MEDIUMTEXT,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY unique_relation (source_site_id, source_lang, template, target_site_id, target_lang),
		INDEX idx_source (source_site_id, source_lang),
		INDEX idx_target (target_site_id, target_site_type, target_lang),
		INDEX idx_template (template),
		INDEX idx_status (status)
	) {$charset_collate} ENGINE=InnoDB;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Alter site relations table for v0.4.0 (when no data)
 */
function wptsall_alter_site_relations_table_v040() {
	global $wpdb;
	$table = wptsall_table( 'site_relations' );

	// Drop old table and create new one
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

	// Load schema file and recreate with new schema
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-site-relations.php';

	if ( function_exists( 'wptsall_create_site_relations_table' ) ) {
		wptsall_create_site_relations_table();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_site_relations_table not found in alter v0.4.0' );
	}
}

/**
 * Get site theme info for migration
 *
 * @param int $site_id Site ID.
 * @return array Theme info.
 */
function wptsall_get_site_theme_info_for_migration( $site_id ) {
	$current_blog_id = get_current_blog_id();
	$need_switch     = is_multisite() && (int) $site_id !== $current_blog_id;

	if ( $need_switch ) {
		switch_to_blog( $site_id );
	}

	$theme = wp_get_theme();
	$info  = array(
		'name' => $theme->get( 'Name' ),
		'path' => $theme->get_stylesheet(),
	);

	if ( $need_switch ) {
		restore_current_blog();
	}

	return $info;
}

/**
 * Check if migrations are needed.
 *
 * @return bool True if migrations needed.
 */
function wptsall_needs_migration() {
	$current_version = wptsall_get_db_version();
	$plugin_version  = '1.1.0';
	return version_compare( $current_version, $plugin_version, '<' );
}

/**
 * Migrate to v0.5.0
 *
 * Field mappings and enhanced translation rules:
 * - Create term_mappings table
 * - Create media_mappings table
 * - Create post_mappings table
 * - Add field_mappings column to translation_rules
 * - Add compute_fields column to translation_rules
 *
 * @since 0.5.0
 */
function wptsall_migrate_to_v050() {
	// Load schema files
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-field-mappings.php';
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-virtual-sites.php';

	// Create new mapping tables with safety checks
	if ( function_exists( 'wptsall_create_field_mapping_tables' ) ) {
		wptsall_create_field_mapping_tables();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_field_mapping_tables not found' );
	}

	// virtual_site_content table is created by the Sites module schema on activation.
	// Function wptsall_create_virtual_site_content_table was removed in v0.7.0.

	// Update translation_rules table
	wptsall_migrate_translation_rules_table_v050();

	wptsall_log(
		'migration',
		'info',
		'v0.5.0 migration completed',
		array(
			'tables_created'  => array( 'term_mappings', 'media_mappings', 'post_mappings', 'virtual_site_content' ),
			'tables_modified' => array( 'translation_rules' ),
		)
	);
}

/**
 * Migrate translation_rules table for v0.5.0
 *
 * Add new fields:
 * - field_mappings (LONGTEXT) - Field mapping configuration
 * - compute_fields (LONGTEXT) - Computed field configuration
 *
 * @since 0.5.0
 */
function wptsall_migrate_translation_rules_table_v050() {
	global $wpdb;
	$table = wptsall_table( 'translation_rules' );

	// Check if field_mappings exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$field_mappings_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'field_mappings' ) );

	if ( ! $field_mappings_exists ) {
		// Add new fields. P1-TEST-01 (2026-09-02): do not anchor on
		// sync_fields — fresh installs and post-v0.8.0 tables (which drop
		// sync_fields) made this ALTER fail with "Unknown column
		// 'sync_fields'", which on fresh activations left field_mappings
		// missing and cascaded into missing mapping tables downstream.
		wptsall_db_alter_table(
			$table,
			"ADD COLUMN field_mappings LONGTEXT DEFAULT NULL COMMENT 'Field mapping config (JSON object)',
			ADD COLUMN compute_fields LONGTEXT DEFAULT NULL COMMENT 'Computed field config (JSON array)' AFTER field_mappings"
		);

		wptsall_log(
			'migration',
			'info',
			'Translation rules table migrated for v0.5.0',
			array(
				'fields_added' => array( 'field_mappings', 'compute_fields' ),
			)
		);
	}
}

/**
 * Get migration status.
 *
 * @return array Migration status information.
 */
/**
 * Migrate to v0.6.0
 *
 * Major restructure for automated sync system:
 * - Create relation_models table (many-to-many)
 * - Modify site_relations table (remove template, add models_count)
 * - Modify templates table (global language packs)
 *
 * @since 0.6.0
 */
function wptsall_migrate_to_v060() {
	// Load schema files.
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-relation-models.php';

	// 1. Create relation_models table.
	if ( function_exists( 'wptsall_create_relation_models_table' ) ) {
		wptsall_create_relation_models_table();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_relation_models_table not found' );
	}

	// 2. Migrate site_relations table.
	wptsall_migrate_site_relations_table_v060();

	// 3. Migrate templates table to global language packs.
	wptsall_migrate_templates_table_v060();

	// 4. Add field_source to translation_rules table.
	wptsall_migrate_translation_rules_table_v060();

	wptsall_log(
		'migration',
		'info',
		'v0.6.0 migration completed',
		array(
			'tables_created'  => array( 'relation_models' ),
			'tables_modified' => array( 'site_relations', 'templates', 'translation_rules' ),
		)
	);
}

/**
 * Migrate translation_rules table for v0.6.0
 *
 * Changes:
 * - Add field_source column to distinguish scanned vs custom fields
 *
 * @since 0.6.0
 */
function wptsall_migrate_translation_rules_table_v060() {
	global $wpdb;
	$table = wptsall_table( 'translation_rules' );

	// Check if field_source column exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$field_source_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'field_source' ) );

	if ( ! $field_source_exists ) {
		wptsall_db_alter_table(
			$table,
			"ADD COLUMN field_source ENUM('scanned','custom') DEFAULT 'scanned'
			COMMENT 'Field source: scanned=auto-discovered, custom=user-defined'"
		);

		wptsall_log(
			'migration',
			'info',
			'Added field_source column to translation_rules table'
		);
	}
}

/**
 * Migrate site_relations table for v0.6.0
 *
 * Changes:
 * - Add models_count column (cache field)
 * - Migrate template data to relation_models table
 * - Keep template column for backward compatibility (will be removed in v0.7.0)
 *
 * @since 0.6.0
 */
function wptsall_migrate_site_relations_table_v060() {
	global $wpdb;
	$table = wptsall_table( 'site_relations' );

	// Check if models_count column exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$models_count_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'models_count' ) );

	if ( ! $models_count_exists ) {
		// Add models_count column.
		wptsall_db_alter_table(
			$table,
			"ADD COLUMN models_count INT(11) DEFAULT 0 COMMENT 'Associated model count (cache)' AFTER target_theme_path"
		);

		wptsall_log(
			'migration',
			'info',
			'Site relations table: added models_count column'
		);
	}

	// Migrate existing template field to relation_models table.
	wptsall_migrate_template_to_relation_models();
}

/**
 * Migrate existing template field data to relation_models table
 *
 * @since 0.6.0
 */
function wptsall_migrate_template_to_relation_models() {
	global $wpdb;
	$relations_table = wptsall_table( 'site_relations' );
	$models_table    = wptsall_table( 'models' );
	$rm_table        = wptsall_table( 'relation_models' );

	// Get all relations with template field.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$relations = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, template FROM %i WHERE template IS NOT NULL AND template != ''",
			$relations_table
		),
		ARRAY_A
	);

	if ( empty( $relations ) ) {
		return;
	}

	$migrated = 0;
	$now      = current_time( 'mysql' );

	foreach ( $relations as $relation ) {
		// Find model by plugin_slug matching template.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$model = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE plugin_slug = %s OR slug = %s LIMIT 1',
				$models_table,
				$relation['template'],
				$relation['template']
			),
			ARRAY_A
		);

		if ( ! $model ) {
			continue;
		}

		// Check if already migrated.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE relation_id = %d AND model_id = %d',
				$rm_table,
				$relation['id'],
				$model['id']
			)
		);

		if ( $exists ) {
			continue;
		}

		// Insert into relation_models.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$rm_table,
			array(
				'relation_id' => $relation['id'],
				'model_id'    => $model['id'],
				'created_at'  => $now,
			),
			array( '%d', '%d', '%s' )
		);

		++$migrated;

		// Update models_count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET models_count = 1 WHERE id = %d',
				$relations_table,
				$relation['id']
			)
		);
	}

	if ( $migrated > 0 ) {
		wptsall_log(
			'migration',
			'info',
			'Migrated template field to relation_models',
			array( 'count' => $migrated )
		);
	}
}

/**
 * Migrate templates table for v0.6.0
 *
 * Changes:
 * - Add source_identifier column (theme/plugin slug)
 * - Add source_language column
 * - Add target_language column
 * - Keep relation_id for backward compatibility (will be removed in v0.7.0)
 *
 * @since 0.6.0
 */
function wptsall_migrate_templates_table_v060() {
	global $wpdb;
	$table = wptsall_table( 'templates' );

	// Check if source_identifier column exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$source_identifier_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'source_identifier' ) );

	if ( ! $source_identifier_exists ) {
		// Add new columns for global language pack support.
		wptsall_db_alter_table(
			$table,
			"ADD COLUMN source_identifier VARCHAR(255) DEFAULT '' COMMENT 'Source identifier (theme/plugin slug)' AFTER source_type,
			ADD COLUMN source_language VARCHAR(10) NOT NULL DEFAULT 'en_US' COMMENT 'Source language' AFTER source_version,
			ADD COLUMN target_language VARCHAR(10) DEFAULT '' COMMENT 'Target language' AFTER source_language,
			ADD INDEX idx_source_target (source_type, source_identifier, target_language)"
		);

		// Migrate existing data: populate source_identifier from text_domain.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET source_identifier = text_domain WHERE source_identifier = '' OR source_identifier IS NULL",
				$table
			)
		);

		// Migrate target_language from site_relations if relation_id exists.
		wptsall_migrate_templates_language_from_relations();

		wptsall_log(
			'migration',
			'info',
			'Templates table: added global language pack columns',
			array(
				'columns_added' => array( 'source_identifier', 'source_language', 'target_language' ),
			)
		);
	}
}

/**
 * Migrate templates target_language from site_relations
 *
 * @since 0.6.0
 */
function wptsall_migrate_templates_language_from_relations() {
	global $wpdb;
	$templates_table  = wptsall_table( 'templates' );
	$relations_table  = wptsall_table( 'site_relations' );

	// Update target_language based on relation_id.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i t
			INNER JOIN %i r ON t.relation_id = r.id
			SET t.target_language = r.target_lang,
			    t.source_language = r.source_lang
			WHERE t.relation_id > 0',
			$templates_table,
			$relations_table
		)
	);
}

/**
 * Migrate to v0.7.0
 *
 * Changes:
 * - Add meta_fields column to plugin_mappings table
 *
 * @since 0.7.0
 */
function wptsall_migrate_to_v070() {
	wptsall_migrate_plugin_mappings_v070();
}

/**
 * Migrate plugin_mappings table for v0.7.0
 *
 * Changes:
 * - Add meta_fields column for storing registered meta fields (JSON)
 *
 * @since 0.7.0
 */
function wptsall_migrate_plugin_mappings_v070() {
	global $wpdb;
	$table = wptsall_table( 'plugin_mappings' );

	// Check if table exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	if ( ! $table_exists ) {
		wptsall_log(
			'migration',
			'info',
			'v0.7.0 migration skipped - plugin_mappings table does not exist'
		);
		return;
	}

	// Check if meta_fields column exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$meta_fields_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'meta_fields' ) );

	if ( $meta_fields_exists ) {
		wptsall_log(
			'migration',
			'info',
			'v0.7.0 migration skipped - meta_fields column already exists'
		);
		return;
	}

	// Add meta_fields column.
	$result = wptsall_db_alter_table(
		$table,
		"ADD COLUMN meta_fields LONGTEXT COMMENT 'Registered meta fields (JSON)' AFTER taxonomies"
	);

	if ( false === $result ) {
		wptsall_log(
			'migration',
			'error',
			'v0.7.0 migration failed - could not add meta_fields column',
			array( 'error' => $wpdb->last_error )
		);
		return;
	}

	wptsall_log(
		'migration',
		'info',
		'plugin_mappings table: added meta_fields column',
		array(
			'columns_added' => array( 'meta_fields' ),
		)
	);
}

/**
 * Migrate to v0.7.1
 *
 * Custom model support:
 * - Add source_type field to models table
 * - Create model_link_chains table
 *
 * @since 0.7.1
 */
function wptsall_migrate_to_v071() {
	// Load schema file.
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-models.php';

	// 1. Add source_type field to models table.
	if ( function_exists( 'wptsall_migrate_add_source_type_field' ) ) {
		wptsall_migrate_add_source_type_field();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_migrate_add_source_type_field not found' );
	}

	// 2. Create model_link_chains table.
	if ( function_exists( 'wptsall_create_link_chains_table' ) ) {
		wptsall_create_link_chains_table();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_link_chains_table not found' );
	}

	wptsall_log(
		'migration',
		'info',
		'v0.7.1 migration completed',
		array(
			'tables_created'  => array( 'model_link_chains' ),
			'tables_modified' => array( 'models' ),
			'fields_added'    => array( 'source_type' ),
		)
	);
}

/**
 * Migrate to v0.8.0
 *
 * Translation rules restructure and relation-level config override:
 * - Restructure translation_rules table (direction, sync_mode, field_capabilities)
 * - Create relation_post_type_configs table for relation-level overrides
 * - Drop deprecated virtual_site_content table (storage moved to wp_posts replica)
 *
 * @since 0.8.0
 */
function wptsall_migrate_to_v080() {
	// Load schema files.
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-models.php';
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-virtual-sites.php';
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-relation-configs.php';
	// 1. Migrate translation_rules table structure.
	if ( function_exists( 'wptsall_migrate_translation_rules_v080' ) ) {
		wptsall_migrate_translation_rules_v080();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_migrate_translation_rules_v080 not found' );
	}

	// 2. Create relation_post_type_configs table.
	if ( function_exists( 'wptsall_create_relation_post_type_configs_table' ) ) {
		wptsall_create_relation_post_type_configs_table();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_relation_post_type_configs_table not found' );
	}

	// 3. Migrate virtual_site_content to wp_posts/wp_terms + meta.
	require_once __DIR__ . '/migrate-virtual-site-content.php';

	if ( function_exists( 'wptsall_needs_virtual_site_content_migration' ) &&
		wptsall_needs_virtual_site_content_migration() ) {

		wptsall_log(
			'migration',
			'info',
			'Starting virtual_site_content to wp_posts migration'
		);

		if ( function_exists( 'wptsall_migrate_virtual_site_content_to_native' ) ) {
			$migration_result = wptsall_migrate_virtual_site_content_to_native( false );

			wptsall_log(
				'migration',
				'info',
				'Virtual site content migration completed',
				$migration_result
			);

			// Only drop table if migration was successful (no failed items).
			if ( $migration_result['posts_failed'] === 0 && $migration_result['terms_failed'] === 0 ) {
				if ( function_exists( 'wptsall_drop_virtual_site_content_table' ) ) {
					wptsall_drop_virtual_site_content_table();
					wptsall_log(
						'migration',
						'info',
						'Dropped deprecated virtual_site_content table after successful migration'
					);
				}
			} else {
				wptsall_log(
					'migration',
					'warning',
					'Kept virtual_site_content table due to migration failures - manual review needed',
					array(
						'posts_failed' => $migration_result['posts_failed'],
						'terms_failed' => $migration_result['terms_failed'],
						'errors'       => $migration_result['errors'],
					)
				);
			}
		} else {
			wptsall_log( 'migration', 'error', 'Function wptsall_migrate_virtual_site_content_to_native not found' );
		}
	} else {
		wptsall_log(
			'migration',
			'info',
			'No virtual_site_content migration needed - table empty or does not exist'
		);

		// Drop empty table if exists.
		if ( function_exists( 'wptsall_drop_virtual_site_content_table' ) ) {
			wptsall_drop_virtual_site_content_table();
		}
	}

	// 4. Add media_handling column to site_relations table.
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-site-relations.php';
	if ( function_exists( 'wptsall_migrate_site_relations_080' ) ) {
		wptsall_migrate_site_relations_080();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_migrate_site_relations_080 not found' );
	}

	// 5. Create user_mappings table (from Sites module).
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-user-mappings.php';
	if ( function_exists( 'wptsall_create_user_mappings_table' ) ) {
		wptsall_create_user_mappings_table();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_create_user_mappings_table not found' );
	}

	wptsall_log(
		'migration',
		'info',
		'v0.8.0 migration completed',
		array(
			'tables_created'  => array( 'relation_post_type_configs', 'user_mappings' ),
			'tables_modified' => array( 'translation_rules', 'site_relations' ),
			'tables_dropped'  => array( 'virtual_site_content' ),
			'data_migrated'   => array( 'virtual_site_content → wp_posts/wp_terms + meta' ),
		)
	);
}

/**
 * Migrate to v0.8.1
 *
 * Site relations enhancements:
 * - sync_mode column (full/sync_only)
 * - direction column (source_to_target/bidirectional)
 * - conflict_strategy column
 * - group_id column
 *
 * @since 0.8.1
 */
function wptsall_migrate_to_v081() {
	// Load schema file.
	require_once dirname( __DIR__, 2 ) . '/sites/database/schema-site-relations.php';

	// Migrate site_relations table with new columns.
	if ( function_exists( 'wptsall_migrate_site_relations_081' ) ) {
		wptsall_migrate_site_relations_081();
	} else {
		wptsall_log( 'migration', 'error', 'Function wptsall_migrate_site_relations_081 not found' );
	}

	wptsall_log(
		'migration',
		'info',
		'v0.8.1 migration completed',
		array(
			'tables_modified' => array( 'site_relations' ),
			'columns_added'   => array( 'sync_mode', 'direction', 'conflict_strategy' ),
		)
	);
}

/**
 * Drop redundant idx_slug index from templates table.
 *
 * The slug column already has a UNIQUE KEY (unique_slug), so the
 * non-unique idx_slug index is redundant and wastes storage.
 *
 * @since 1.0.6
 */
function wptsall_migrate_drop_redundant_idx_slug() {
	global $wpdb;
	$table = wptsall_table( 'templates' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $table_exists ) {
		return;
	}

	// Check if idx_slug exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'idx_slug' ), ARRAY_A );

	if ( ! empty( $indexes ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX idx_slug', $table ) );

		wptsall_log(
			'migration',
			'info',
			'Dropped redundant idx_slug index from templates table'
		);
	}
}

/**
 * Add needs_resync and claimed_at columns to post_mappings table.
 *
 * Supports client-driven task model: Hook_Manager marks modified posts
 * as needs_resync, Client claims content for processing.
 *
 * @since 1.2.0
 */
function wptsall_migrate_post_mappings_add_resync_columns() {
	global $wpdb;
	$table = wptsall_table( 'post_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $table_exists ) {
		return;
	}

	// Check if needs_resync column exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$col_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'needs_resync' ) );

	if ( ! $col_exists ) {
		wptsall_db_alter_table(
			$table,
			"ADD COLUMN needs_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Source content changed, needs re-translation' AFTER relationship_type,
			ADD COLUMN claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for content processing' AFTER needs_resync,
			ADD INDEX idx_resync (needs_resync)"
		);

		wptsall_log(
			'migration',
			'info',
			'Added needs_resync and claimed_at columns to post_mappings table'
		);
	}
}

/**
 * Backfill schema columns required by current client callback and discovery flows.
 *
 * Some long-lived environments have old tables created before these columns were
 * introduced. This migration adds them incrementally without destructive rebuild.
 *
 * @since 1.2.1
 */
function wptsall_migrate_backfill_schema_columns_v121() {
	global $wpdb;

	$models_table          = wptsall_table( 'models' );
	$post_mappings_table   = wptsall_table( 'post_mappings' );
	$term_mappings_table   = wptsall_table( 'term_mappings' );
	$template_entries_table = wptsall_table( 'template_entries' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$models_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $models_table ) );
	if ( $models_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$semantic_status_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $models_table, 'semantic_status' ) );
		if ( ! $semantic_status_exists ) {
			wptsall_db_alter_table(
				$models_table,
				"ADD COLUMN semantic_status VARCHAR(20) DEFAULT 'draft' COMMENT 'Semantic review status: draft/reviewed/approved/stale' AFTER usage_status"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$semantic_index_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $models_table, 'idx_semantic_status' ) );
		if ( ! $semantic_index_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX idx_semantic_status (semantic_status)', $models_table ) );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$post_mappings_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $post_mappings_table ) );
	if ( $post_mappings_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation_col_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $post_mappings_table, 'relation_id' ) );
		if ( ! $relation_col_exists ) {
			wptsall_db_alter_table(
				$post_mappings_table,
				"ADD COLUMN relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Site relation ID' AFTER source_site_id"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$needs_resync_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $post_mappings_table, 'needs_resync' ) );
		if ( ! $needs_resync_exists ) {
			wptsall_db_alter_table(
				$post_mappings_table,
				"ADD COLUMN needs_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Source content changed, needs re-translation' AFTER relationship_type"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed_at_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $post_mappings_table, 'claimed_at' ) );
		if ( ! $claimed_at_exists ) {
			wptsall_db_alter_table(
				$post_mappings_table,
				"ADD COLUMN claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for content processing' AFTER needs_resync"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation_index_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $post_mappings_table, 'idx_relation' ) );
		if ( ! $relation_index_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX idx_relation (relation_id)', $post_mappings_table ) );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$term_mappings_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $term_mappings_table ) );
	if ( $term_mappings_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_relation_col_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $term_mappings_table, 'relation_id' ) );
		if ( ! $term_relation_col_exists ) {
			wptsall_db_alter_table(
				$term_mappings_table,
				"ADD COLUMN relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Site relation ID' AFTER id"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_needs_resync_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $term_mappings_table, 'needs_resync' ) );
		if ( ! $term_needs_resync_exists ) {
			wptsall_db_alter_table(
				$term_mappings_table,
				"ADD COLUMN needs_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Source content changed, needs re-translation' AFTER translation_method"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_claimed_at_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $term_mappings_table, 'claimed_at' ) );
		if ( ! $term_claimed_at_exists ) {
			wptsall_db_alter_table(
				$term_mappings_table,
				"ADD COLUMN claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for content processing' AFTER needs_resync"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_relation_index_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $term_mappings_table, 'idx_relation' ) );
		if ( ! $term_relation_index_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX idx_relation (relation_id)', $term_mappings_table ) );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$template_entries_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $template_entries_table ) );
	if ( $template_entries_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$template_claimed_at_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $template_entries_table, 'claimed_at' ) );
		if ( ! $template_claimed_at_exists ) {
			wptsall_db_alter_table(
				$template_entries_table,
				"ADD COLUMN claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for entry lock' AFTER source"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$template_claimed_at_index_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $template_entries_table, 'idx_claimed_at' ) );
		if ( ! $template_claimed_at_index_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX idx_claimed_at (claimed_at)', $template_entries_table ) );
		}
	}
}

/**
 * Add composite indexes for high-frequency claim/list/writeback queries.
 *
 * Existing installs do not reliably receive dbDelta-added indexes, so this
 * migration adds each index explicitly and idempotently.
 *
 * @since 1.2.2
 */
function wptsall_migrate_add_query_optimization_indexes_v122() {
	$indexes = array(
		wptsall_table( 'templates' )           => array(
			'idx_relation_source_type_id' => 'ADD INDEX idx_relation_source_type_id (relation_id, source_type, id)',
		),
		wptsall_table( 'template_entries' )    => array(
			'idx_template_status_claimed_id' => 'ADD INDEX idx_template_status_claimed_id (template_id, status, claimed_at, id)',
		),
		wptsall_table( 'translation_results' ) => array(
			'status_created_at' => 'ADD INDEX status_created_at (status, created_at)',
		),
		wptsall_table( 'post_mappings' )       => array(
			'idx_relation_source_claimed' => 'ADD INDEX idx_relation_source_claimed (relation_id, source_post_id, source_post_type, source_site_id, target_site_id, claimed_at)',
		),
		wptsall_table( 'term_mappings' )       => array(
			'idx_relation_term_claimed' => 'ADD INDEX idx_relation_term_claimed (relation_id, source_term_id, source_taxonomy, source_site_id, target_site_id, target_lang, claimed_at)',
		),
		wptsall_table( 'tasks' )               => array(
			'status_type_created_at' => 'ADD INDEX status_type_created_at (status, type, created_at)',
		),
	);

	foreach ( $indexes as $table => $table_indexes ) {
		wptsall_migrate_add_indexes_if_missing( $table, $table_indexes );
	}
}

/**
 * Add hardcoded index definitions to a table when missing.
 *
 * @param string              $table   Table name.
 * @param array<string,string> $indexes Map of index name to ALTER fragment.
 * @return void
 */
function wptsall_migrate_add_indexes_if_missing( $table, $indexes ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $table_exists !== $table ) {
		return;
	}

	foreach ( $indexes as $index_name => $alter_fragment ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$index_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index_name )
		);

		if ( $index_exists ) {
			continue;
		}

		// Index definitions are hardcoded above; no user input is interpolated.
		wptsall_db_alter_table( $table, $alter_fragment );

		if ( '' !== $wpdb->last_error ) {
			wptsall_log(
				'migration',
				'error',
				'Failed to add query optimization index',
				array(
					'table' => $table,
					'index' => $index_name,
					'error' => $wpdb->last_error,
				)
			);
			continue;
		}

		wptsall_log(
			'migration',
			'info',
			'Added query optimization index',
			array(
				'table' => $table,
				'index' => $index_name,
			)
		);
	}
}

/**
 * Replace the legacy hard unique task key with a non-unique lookup index.
 *
 * The old `uniq_task(site_id, template, object_type, subtype, object_id, status)`
 * key made completed-history retention impossible: a later pending task for the
 * same business object could not transition to completed while an old completed
 * row existed. Open-task dedupe is now enforced in insertion code instead.
 *
 * @since 1.2.3
 * @return void
 */
function wptsall_migrate_tasks_open_dedupe_index_v123() {
	global $wpdb;

	$table = wptsall_table( 'tasks' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $table_exists !== $table ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$legacy_unique_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'uniq_task' )
	);
	if ( $legacy_unique_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX uniq_task', $table ) );
	}

	wptsall_migrate_add_indexes_if_missing(
		$table,
		array(
			'idx_task_identity_status' => 'ADD INDEX idx_task_identity_status (site_id, template, object_type, subtype, object_id, status)',
		)
	);
}

/**
 * Backfill relation_id on legacy mapping rows when a unique site relation can be resolved.
 *
 * Older mapping rows were created before relation_id became part of the claim
 * path. Query indexes from v1.2.2 only help if relation_id is populated, so this
 * migration fills unambiguous post/term mappings and leaves ambiguous rows at 0.
 *
 * @since 1.2.4
 * @return void
 */
function wptsall_migrate_backfill_mapping_relation_ids_v124() {
	global $wpdb;

	$relations_table     = wptsall_table( 'site_relations' );
	$post_mappings_table = wptsall_table( 'post_mappings' );
	$term_mappings_table = wptsall_table( 'term_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$relations_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations_table ) );
	if ( $relations_exists !== $relations_table ) {
		return;
	}

	$post_updated = 0;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$post_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $post_mappings_table ) );
	if ( $post_exists === $post_mappings_table ) {
		// Only backfill rows with exactly one matching relation to avoid assigning
		// the wrong language/template relation in older multi-target installs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i pm
				 INNER JOIN (
					SELECT candidate.id, MIN(candidate.relation_id) AS relation_id
					FROM (
						SELECT pm_inner.id, sr.id AS relation_id
						FROM %i pm_inner
						INNER JOIN %i sr
							ON sr.source_site_id = pm_inner.source_site_id
							AND sr.target_site_id = pm_inner.target_site_id
						WHERE pm_inner.relation_id = 0
					) candidate
					GROUP BY candidate.id
					HAVING COUNT(DISTINCT candidate.relation_id) = 1
				 ) resolved ON resolved.id = pm.id
				 SET pm.relation_id = resolved.relation_id,
				     pm.updated_at = COALESCE(NULLIF(pm.updated_at, \'0000-00-00 00:00:00\'), NOW())
				 WHERE pm.relation_id = 0',
				$post_mappings_table,
				$post_mappings_table,
				$relations_table
			)
		);
		$post_updated = false === $post_result ? 0 : (int) $post_result;
	}

	$term_updated = 0;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$term_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $term_mappings_table ) );
	if ( $term_exists === $term_mappings_table ) {
		// Term mappings have language columns; use them when present, but still
		// skip ambiguous empty-language rows by requiring one unique relation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i tm
				 INNER JOIN (
					SELECT candidate.id, MIN(candidate.relation_id) AS relation_id
					FROM (
						SELECT tm_inner.id, sr.id AS relation_id
						FROM %i tm_inner
						INNER JOIN %i sr
							ON sr.source_site_id = tm_inner.source_site_id
							AND sr.target_site_id = tm_inner.target_site_id
							AND (tm_inner.source_lang = \'\' OR sr.source_lang = tm_inner.source_lang)
							AND (tm_inner.target_lang = \'\' OR sr.target_lang = tm_inner.target_lang)
						WHERE tm_inner.relation_id = 0
					) candidate
					GROUP BY candidate.id
					HAVING COUNT(DISTINCT candidate.relation_id) = 1
				 ) resolved ON resolved.id = tm.id
				 SET tm.relation_id = resolved.relation_id,
				     tm.updated_at = COALESCE(NULLIF(tm.updated_at, \'0000-00-00 00:00:00\'), NOW())
				 WHERE tm.relation_id = 0',
				$term_mappings_table,
				$term_mappings_table,
				$relations_table
			)
		);
		$term_updated = false === $term_result ? 0 : (int) $term_result;
	}

	wptsall_log(
		'migration',
		'info',
		'Backfilled legacy mapping relation_id values',
		array(
			'post_mappings_updated' => $post_updated,
			'term_mappings_updated' => $term_updated,
		)
	);
}

/**
 * Create wptsall_strings table for Layer B site-structure strings.
 *
 * @since 1.2.5
 * @return void
 */
function wptsall_migrate_strings_table_v125() {
	require_once dirname( __DIR__, 2 ) . '/strings/database/schema-strings.php';
	if ( function_exists( 'wptsall_create_strings_table' ) ) {
		wptsall_create_strings_table();
	}
}

/**
 * Create wptsall_menu_mappings table.
 *
 * @since 1.2.6
 * @return void
 */
function wptsall_migrate_menu_mappings_table_v126() {
	require_once dirname( __DIR__, 2 ) . '/menu-translation/database/schema-menu-mappings.php';
	if ( function_exists( 'wptsall_create_menu_mappings_table' ) ) {
		wptsall_create_menu_mappings_table();
	}
}

/**
 * Add source_revision / policy_version / request_hash to translation_results.
 *
 * @since 1.2.7
 * @return void
 */
function wptsall_migrate_translation_results_snapshots_v127() {
	global $wpdb;
	$table = wptsall_table( 'translation_results' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		if ( function_exists( 'wptsall_create_translation_results_table' ) ) {
			wptsall_create_translation_results_table();
		}
		return;
	}
	$cols = array(
		'source_revision' => "ADD COLUMN source_revision VARCHAR(64) NOT NULL DEFAULT '' AFTER client_task_id",
		'policy_version'  => "ADD COLUMN policy_version VARCHAR(64) NOT NULL DEFAULT '' AFTER source_revision",
		'request_hash'    => "ADD COLUMN request_hash VARCHAR(64) NOT NULL DEFAULT '' AFTER policy_version",
	);
	foreach ( $cols as $name => $alter ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $name ) );
		if ( ! $col ) {
			wptsall_db_alter_table( $table, $alter );
		}
	}
	if ( class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ) {
		\WPTSALL\Core\Job_Snapshot::current_policy_version();
	}
	if ( function_exists( 'wptsall_create_conflicts_table' ) ) {
		wptsall_create_conflicts_table();
	} else {
		require_once dirname( __DIR__, 2 ) . '/sync/database/schema-conflicts.php';
		if ( function_exists( 'wptsall_create_conflicts_table' ) ) {
			wptsall_create_conflicts_table();
		}
	}
}

/**
 * Add resync state to media mappings without rebuilding installations that
 * predate the unified content-change dispatcher.
 *
 * @since 1.2.8
 * @return void
 */
function wptsall_migrate_media_mappings_add_resync_columns_v128() {
	global $wpdb;
	$table = wptsall_table( 'media_mappings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$needs_resync = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'needs_resync' ) );
	if ( ! $needs_resync ) {
		wptsall_db_alter_table(
			$table,
			"ADD COLUMN needs_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Source media changed, needs re-sync' AFTER alt_translated"
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$claimed_at = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'claimed_at' ) );
	if ( ! $claimed_at ) {
		wptsall_db_alter_table(
			$table,
			"ADD COLUMN claimed_at DATETIME DEFAULT NULL COMMENT 'Client claim timestamp for media processing' AFTER needs_resync"
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$idx_resync = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'idx_resync' ) );
	if ( ! $idx_resync ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX idx_resync (needs_resync)', $table ) );
	}
}

/**
 * Create the durable dispatcher outbox on existing installations.
 *
 * @since 1.2.9
 * @return void
 */
function wptsall_migrate_content_change_outbox_v129() {
	require_once dirname( __DIR__, 2 ) . '/models/database/schema-field-mappings.php';
	if ( function_exists( 'wptsall_create_content_change_outbox_table' ) ) {
		wptsall_create_content_change_outbox_table();
	}
}

/**
 * Add the relation_id used by Sync_Executor media replacement queries.
 *
 * @since 1.3.0
 * @return void
 */
function wptsall_migrate_media_mappings_relation_id_v130( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		global $wpdb;
		// A schema option is per blog, so migrating only the site that happened
		// to receive admin_init leaves target-site mapping tables stale.  Upgrade
		// every local table exactly once from the initiating site.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$blog_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i', $wpdb->blogs ) );
		foreach ( (array) $blog_ids as $blog_id ) {
			$blog_id = (int) $blog_id;
			if ( $blog_id <= 0 || $blog_id === get_current_blog_id() ) {
				continue;
			}
			switch_to_blog( $blog_id );
			wptsall_migrate_media_mappings_relation_id_v130( false );
			restore_current_blog();
		}
	}

	global $wpdb;
	$table  = wptsall_table( 'media_mappings' );
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return;
	}
	$column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'relation_id' ) );
	if ( ! $column ) {
		wptsall_db_alter_table( $table, "ADD COLUMN relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Site relation ID' AFTER source_media_id" );
	}
	$index = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'idx_relation' ) );
	if ( ! $index ) {
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX idx_relation (relation_id)', $table ) );
	}
}

/**
 * Add hashed claim owner columns to existing claim tables.
 *
 * Claim ownership is deliberately stored as a digest. Existing installations
 * may already have the lease timestamp columns, so this migration is
 * incremental and safe to run after the table-creation functions.
 *
 * @since 2.1.0
 * @return void
 */
function wptsall_migrate_claim_owner_columns_v210() {
	global $wpdb;

	$tables = array(
		'post_mappings',
		'term_mappings',
		'media_mappings',
		'template_entries',
		'strings',
		'option_sync_state',
	);
	foreach ( $tables as $key ) {
		$table = wptsall_table( $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'claim_owner_hash' ) );
		if ( ! $column ) {
			$claimed_at = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'claimed_at' ) );
			$after      = $claimed_at ? ' AFTER claimed_at' : '';
			wptsall_db_alter_table(
				$table,
				"ADD COLUMN claim_owner_hash CHAR(64) DEFAULT NULL COMMENT 'Hashed device/relation claim owner'" . $after
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$index = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'idx_claim_owner' ) );
		if ( ! $index ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX idx_claim_owner (claim_owner_hash)', $table ) );
		}
	}
}

/**
 * Add relation scope to the shared legacy mapping table.
 *
 * The legacy table predates site relations. Relation-aware callers now write
 * and read this column; zero remains reserved for historical rows that cannot
 * be safely attributed to one relation.
 *
 * @since 2.1.0
 * @return void
 */
function wptsall_migrate_legacy_mapping_relation_id_v210() {
	global $wpdb;
	$table = $wpdb->base_prefix . 'wptsall_mappings';
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return;
	}

	$column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'relation_id' ) );
	if ( ! $column ) {
		wptsall_db_alter_table( $table, 'ADD COLUMN relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER model' );
	}

	$index = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'relation_source_idx' ) );
	if ( ! $index ) {
		wptsall_db_alter_table( $table, 'ADD INDEX relation_source_idx (relation_id, source_blog_id, source_object_type, source_subtype, source_object_id, target_blog_id)' );
	}
}

/**
 * Make mapping uniqueness relation-scoped on existing installations.
 *
 * The original post/media keys omitted relation_id, which allowed one
 * relation's mapping to suppress or collide with another relation targeting
 * the same site. The schema declarations cover new tables; this repair is
 * intentionally idempotent and also runs when db_version is already current.
 *
 * @since 2.1.0
 * @return void
 */
function wptsall_migrate_relation_scoped_mapping_unique_keys_v210() {
	global $wpdb;

	$definitions = array(
		'post_mappings'  => array( 'relation_id', 'source_post_id', 'source_post_type', 'source_site_id', 'target_site_id' ),
		'term_mappings'  => array( 'relation_id', 'source_term_id', 'source_taxonomy', 'source_site_id', 'target_site_id', 'target_lang' ),
		'media_mappings' => array( 'relation_id', 'source_media_id', 'source_site_id', 'target_site_id' ),
	);

	foreach ( $definitions as $key => $expected_columns ) {
		$table = wptsall_table( $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			continue;
		}

		// A partially-restored or very old table may still lack relation_id even
		// when the recorded db version is current. Add the column before trying
		// to rebuild the unique key; otherwise the repair itself fails.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'relation_id' ) );
		if ( ! $relation_column ) {
			wptsall_db_alter_table( $table, "ADD COLUMN relation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Site relation ID' AFTER id" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$index_rows = $wpdb->get_results(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'unique_mapping' ),
			ARRAY_A
		);
		// SHOW INDEX does not accept ORDER BY; sort in PHP so the column
		// comparison below is deterministic.
		$index_rows    = (array) $index_rows;
		$index_columns = array();
		foreach ( $index_rows as $row ) {
			$index_columns[ (int) ( $row['Seq_in_index'] ?? 0 ) ] = $row;
		}
		ksort( $index_columns );
		$index_rows     = array_values( $index_columns );
		$actual_columns = array();
		foreach ( (array) $index_rows as $index_row ) {
			$actual_columns[] = (string) ( $index_row['Column_name'] ?? '' );
		}
		if ( $actual_columns === $expected_columns ) {
			continue;
		}

		// An interrupted/hand-created installation may have duplicate rows even
		// when no usable unique key is present. Remove only later duplicates,
		// retaining the oldest row so the unique-key repair is deterministic and
		// repeatable. All column names here come from the hardcoded definitions.
		$join_parts = array();
		foreach ( $expected_columns as $column ) {
			$join_parts[] = 'a.' . $column . ' <=> b.' . $column;
		}
		$duplicate_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT a.id FROM %i a INNER JOIN %i b ON b.id < a.id AND ' . implode( ' AND ', $join_parts ),
				$table,
				$table
			)
		);
		if ( ! empty( $duplicate_ids ) ) {
			list( $duplicate_sql, $duplicate_args ) = wptsall_db_prepare_int_in( array_map( 'absint', $duplicate_ids ) );
			wptsall_db_query(
				'DELETE FROM %i WHERE id IN (' . $duplicate_sql . ')',
				array_merge( array( $table ), $duplicate_args )
			);
		}

		if ( ! empty( $actual_columns ) ) {
			wptsall_db_alter_table( $table, 'DROP INDEX unique_mapping' );
		}
		$index_columns = implode( ', ', $expected_columns );
		wptsall_db_alter_table( $table, 'ADD UNIQUE KEY unique_mapping (' . $index_columns . ')' );
	}
}
