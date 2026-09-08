<?php
/**
 * WPTSALL Uninstall
 *
 * Fired when the plugin is deleted through WordPress admin.
 * This file is automatically called by WordPress when the plugin is deleted.
 *
 * Security: This file will only run if WP_UNINSTALL_PLUGIN is defined.
 *
 * Scope: WordPress core includes this file INSIDE uninstall_plugin()'s function
 * scope, so the top-level $wptsall_* arrays below are local to that function.
 * They are therefore passed to the cleanup helpers as parameters; a `global`
 * statement inside a helper would fetch nothing and silently skip the cleanup
 * (the bug that left the whole wptsall_* table family behind on uninstall).
 *
 * @package WPTSALL
 * @since 0.3.0
 */

// Security check - exit if accessed directly or not during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check user capability.
// In multisite network admin, super admins may not have 'delete_plugins' on individual sites,
// but they should still be able to uninstall plugins network-wide.
// WP-CLI runs without a logged-in user by default; allow uninstall in that context.
if ( ! current_user_can( 'delete_plugins' ) && ! is_super_admin() ) {
	if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		exit;
	}
}

/**
 * Plugin database tables (without prefix)
 *
 * Keep this list in sync with Plugin_Lifecycle::$tables!
 */
$wptsall_tables = array(
	// Hooks module.
	'wptsall_hooks',

	// Mappings.
	'wptsall_mappings',

	// Models module.
	'wptsall_models',
	'wptsall_model_objects',      // Plugin template objects (v1.0.1).
	'wptsall_model_object_fields',// Plugin template object fields (v1.0.1).
	'wptsall_model_url_rules',      // Legacy table (V1).
	'wptsall_translation_rules',    // V2 table.
	'wptsall_plugin_mappings',      // V2 table.
	'wptsall_link_chains',          // Legacy link chains table name (kept for cleanup).
	'wptsall_model_link_chains',    // Link chains (v0.7.0+).

	// Sites module.
	'wptsall_site_relations',
	'wptsall_relation_models',      // Relation-model many-to-many (v0.6.0).
	'wptsall_site_groups',          // Legacy site groups (removed, kept for cleanup).
	'wptsall_site_group_models',    // Legacy site group models (removed, kept for cleanup).
	'wptsall_relation_post_type_configs', // Relation configs (v0.8.0).
	'wptsall_virtual_sites',
	'wptsall_virtual_site_content', // Virtual site content (v0.5.0).
	'wptsall_virtual_content',      // Deprecated in 0.5.0, kept for cleanup.

	// Field mappings (v0.5.0).
	'wptsall_term_mappings',
	'wptsall_media_mappings',
	'wptsall_post_mappings',
	'wptsall_content_change_outbox',
	'wptsall_user_mappings',

	// Sync module.
	'wptsall_sync_meta',
	'wptsall_conflicts',
	'wptsall_snapshots',

	// Translation module.
	'wptsall_translation_memory',
	'wptsall_terminology',

	// Templates module.
	'wptsall_templates',
	'wptsall_template_entries',

	// Tasks module tables (unified single-plugin mode).
	'wptsall_tasks',
	'wptsall_task_logs',
	'wptsall_task_items',
	'wptsall_task_jobs',
	'wptsall_origin_visits',
	'wptsall_translation_results',
	'wptsall_manual_queue',

	// Client API / language packs / site strings (rate limits, per-relation
	// languages, menu mappings, option sync leases; v1.0+ modules).
	'wptsall_client_rate_limits',
	'wptsall_languages',
	'wptsall_strings',
	'wptsall_menu_mappings',
	'wptsall_option_sync_state',
);

/**
 * Plugin options (stored in wp_options)
 */
$wptsall_options = array(
	'wptsall_db_version',
	'wptsall_initialized',         // Initialization status flag.
	'wptsall_activated_version',   // Version that was last activated.
	'wptsall_settings',
	'wptsall_hooks',
	'wptsall_sites',
	'wptsall_virtual_sites',       // Virtual sites stored as option (v0.5.0+).
	'wptsall_advanced_config',
	'wptsall_license_key', // Legacy option key (pre-free; not a product license).
	'wptsall_license_status', // Legacy option key (pre-free).
	'wptsall_client_route_secret', // Client API route secret (v0.9.0+).
	'wptsall_ssot_read_source',    // SSOT read source toggle (v1.2.0+).
	'wptsall_task_parameters',     // Task processing parameters.
);

/**
 * Plugin cron hooks
 *
 * Keep this list in sync with Plugin_Lifecycle::$cron_hooks!
 */
$wptsall_cron_hooks = array(
	// Maintenance.
	'wptsall_daily_cleanup',
	'wptsall_weekly_maintenance',
	// i18n scan.
	'wptsall_run_i18n_scan',
);

// Tasks module cron hooks (include legacy names for cleanup compatibility).
$wptsall_cron_hooks = array_merge(
	$wptsall_cron_hooks,
	array(
		'wptsall_process_tasks',
		'wptsall_cron_process_tasks',
		'wptsall_cleanup_old_tasks',
		'wptsall_sync_cron',
		'wptsall_process_high_priority_tasks',
		'wptsall_process_normal_tasks',
		'wptsall_process_low_priority_tasks',
		'wptsall_retry_failed_tasks',
		'wptsall_process_monitoring_tasks',
		'wptsall_process_pending_sync_tasks',
	)
);

/**
 * Best-effort recursive directory deletion.
 *
 * WP uninstall can run without plugin bootstrap, so keep this self-contained and defensive.
 *
 * @param string $dir Directory path.
 * @return void
 */
function wptsall_delete_dir_recursive( $dir ) {
	$dir = (string) $dir;
	if ( '' === $dir || ! file_exists( $dir ) ) {
		return;
	}
	if ( ! is_dir( $dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $dir );
		return;
	}

	$items = @scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		wptsall_delete_dir_recursive( $dir . DIRECTORY_SEPARATOR . $item );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $dir );
}

/**
 * Drop any remaining wptsall tables with non-standard prefixes.
 *
 * Used during multisite cleanup to remove tables created by Plugin Check sandbox etc.
 *
 * @param wpdb $wpdb WordPress database object.
 * @return int Number of tables dropped.
 */
function wptsall_drop_remaining_tables( $wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wptsall_all_tables = $wpdb->get_col(
		$wpdb->prepare(
			'SHOW TABLES LIKE %s',
			'%' . $wpdb->esc_like( 'wptsall' ) . '%'
		)
	);
	$wptsall_dropped    = 0;
	foreach ( (array) $wptsall_all_tables as $wptsall_tbl ) {
		// Sanitize table name - only allow alphanumeric and underscore.
		$wptsall_safe_tbl = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $wptsall_tbl );
		if ( $wptsall_safe_tbl === $wptsall_tbl && '' !== $wptsall_safe_tbl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wptsall_safe_tbl ) );
			++$wptsall_dropped;
		}
	}
	return $wptsall_dropped;
}

/**
 * Uninstall single site
 *
 * Receives the cleanup lists as parameters because WordPress includes
 * uninstall.php inside uninstall_plugin()'s function scope: the file's
 * top-level $wptsall_* arrays never reach the global scope, so a `global`
 * statement here would fetch empty lists and skip the cleanup.
 *
 * @param array $wptsall_tables     Tables to drop (without prefix).
 * @param array $wptsall_options    Options to delete.
 * @param array $wptsall_cron_hooks Cron hooks to clear.
 * @return void
 */
function wptsall_uninstall_single_site( $wptsall_tables, $wptsall_options, $wptsall_cron_hooks ) {
	global $wpdb;

	// Guard: ensure lists are arrays (callers pass the file-scope arrays above).
	if ( ! is_array( $wptsall_tables ) ) {
		$wptsall_tables = array();
	}
	if ( ! is_array( $wptsall_options ) ) {
		$wptsall_options = array();
	}
	if ( ! is_array( $wptsall_cron_hooks ) ) {
		$wptsall_cron_hooks = array();
	}

	// 1. Drop database tables.
	foreach ( $wptsall_tables as $table ) {
		$table_name = $wpdb->prefix . $table;
		// Sanitize table name - only allow alphanumeric and underscore.
		$table_name = preg_replace( '/[^a-zA-Z0-9_]/', '', $table_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
	}

	// 2. Delete options.
	foreach ( $wptsall_options as $option ) {
		delete_option( $option );
	}

	// Defensive cleanup: remove any remaining plugin options (dynamic names, legacy flags, etc.).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'wptsall_' ) . '%',
			$wpdb->esc_like( '_wptsall_' ) . '%'
		)
	);

	// 3. Delete template options (dynamic names).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'wptsall_template_' ) . '%'
		)
	);

	// 4. Delete transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_wptsall_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wptsall_' ) . '%'
		)
	);

	// 5. Clear cron events.
	foreach ( $wptsall_cron_hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// 6. Delete user meta.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->usermeta,
			$wpdb->esc_like( 'wptsall_' ) . '%'
		)
	);
	// Also delete underscored keys for completeness.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->usermeta,
			$wpdb->esc_like( '_wptsall_' ) . '%'
		)
	);

	// 7. Delete virtual site posts (BEFORE deleting post meta).
	// Virtual site content is stored in wp_posts with meta markers.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$virtual_post_ids = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT p.ID
			 FROM %i p
			 INNER JOIN %i pm ON p.ID = pm.post_id
			 	AND pm.meta_key = %s',
			$wpdb->posts,
			$wpdb->postmeta,
			'_wptsall_virtual_site_id'
		)
	);

	foreach ( $virtual_post_ids as $post_id ) {
		wp_delete_post( $post_id, true ); // Force delete, bypass trash.
	}

	// 8. Delete virtual site terms (BEFORE deleting term meta).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$virtual_term_data = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT t.term_id, tt.taxonomy
			 FROM %i t
			 INNER JOIN %i tt ON t.term_id = tt.term_id
			 INNER JOIN %i tm ON t.term_id = tm.term_id
			 	AND tm.meta_key = %s',
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$wpdb->termmeta,
			'_wptsall_virtual_site_id'
		),
		ARRAY_A
	);

	foreach ( $virtual_term_data as $term ) {
		wp_delete_term( $term['term_id'], $term['taxonomy'] );
	}

	// 9. Delete post meta (after deleting virtual posts).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->postmeta,
			$wpdb->esc_like( '_wptsall_' ) . '%'
		)
	);

	// 10. Delete term meta (after deleting virtual terms).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->termmeta,
			$wpdb->esc_like( '_wptsall_' ) . '%'
		)
	);

	// 11. Delete comment meta.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->commentmeta,
			$wpdb->esc_like( '_wptsall_' ) . '%'
		)
	);

	// 12. Delete plugin log files under uploads (best effort).
	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['basedir'] ) ) {
		$log_dir = rtrim( (string) $upload_dir['basedir'], '/\\' ) . '/wptsall-logs';
		wptsall_delete_dir_recursive( $log_dir );
		$langpack_dir = rtrim( (string) $upload_dir['basedir'], '/\\' ) . '/wpmmcc-ats/languages';
		wptsall_delete_dir_recursive( $langpack_dir );
		$wpmmcc_dir = rtrim( (string) $upload_dir['basedir'], '/\\' ) . '/wpmmcc-ats';
		if ( is_dir( $wpmmcc_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $wpmmcc_dir );
		}
	}
}

// Run uninstall.
if ( is_multisite() ) {
	global $wpdb;

	// Get all blog IDs.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wptsall_blog_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i', $wpdb->blogs ) );

	foreach ( $wptsall_blog_ids as $wptsall_blog_id ) {
		switch_to_blog( $wptsall_blog_id );
		wptsall_uninstall_single_site( $wptsall_tables, $wptsall_options, $wptsall_cron_hooks );
		restore_current_blog();
	}

	// Delete site transients (network level).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s',
			$wpdb->sitemeta,
			$wpdb->esc_like( '_site_transient_wptsall_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_wptsall_' ) . '%'
		)
	);

	// Clean up any remaining wptsall tables with non-standard prefixes (e.g., Plugin Check sandbox wp_pc_).
	wptsall_drop_remaining_tables( $wpdb );
} else {
	global $wpdb;

	wptsall_uninstall_single_site( $wptsall_tables, $wptsall_options, $wptsall_cron_hooks );

	// Defensive cleanup: remove any remaining wptsall tables with non-standard
	// prefixes or names missing from the list above. Single-site counterpart of
	// the multisite sweep above.
	wptsall_drop_remaining_tables( $wpdb );
}

// Flush rewrite rules.
flush_rewrite_rules();
