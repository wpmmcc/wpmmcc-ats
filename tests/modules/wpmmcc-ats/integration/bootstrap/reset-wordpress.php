<?php
/**
 * Bootstrap: Reset WordPress Content
 *
 * Truncates WordPress core content tables and all WPTSALL plugin tables to
 * provide a clean slate for integration test isolation.
 *
 * Safe to run multiple times (idempotent).
 *
 * Tables cleared:
 *   WP core : wp_posts, wp_postmeta, wp_term_relationships, wp_comments, wp_commentmeta
 *   WPTSALL : all plugin tables via wptsall_table() / $wpdb->prefix
 *
 * NOT cleared: wp_users, wp_options, wp_usermeta (preserves admin account and settings).
 *
 * @package WPTSALL\DevTools\Bootstrap
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

global $wpdb;

echo "=== reset-wordpress.php ===\n";
echo "Resetting WordPress content and WPTSALL tables...\n\n";

// ---------------------------------------------------------------------------
// 1. WordPress core content tables
// ---------------------------------------------------------------------------

$wp_core_tables = array(
	$wpdb->prefix . 'comments',
	$wpdb->prefix . 'commentmeta',
	$wpdb->prefix . 'term_relationships',
	$wpdb->prefix . 'postmeta',
	$wpdb->prefix . 'posts',
);

$wp_cleared   = array();
$wp_skipped   = array();

// Disable foreign key checks to allow truncation in any order.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

foreach ( $wp_core_tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists ) {
		$safe_table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$safe_table}`" );
		$wp_cleared[] = $table;
	} else {
		$wp_skipped[] = $table;
	}
}

// Re-enable foreign key checks.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

echo "WordPress core tables cleared (" . count( $wp_cleared ) . "):\n";
foreach ( $wp_cleared as $t ) {
	echo "  - {$t}\n";
}
if ( ! empty( $wp_skipped ) ) {
	echo "WordPress core tables skipped (not found): " . implode( ', ', $wp_skipped ) . "\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 2. WPTSALL plugin tables
// ---------------------------------------------------------------------------

// Canonical list of WPTSALL table keys (mirrors Plugin_Lifecycle::$tables).
// Keys used with wptsall_table(); bare names used as fallback with $wpdb->prefix.
$wptsall_table_keys = array(
	'hooks',
	'mappings',
	'models',
	'model_objects',
	'model_object_fields',
	'translation_rules',
	'plugin_mappings',
	'site_relations',
	'relation_models',
	'relation_post_type_configs',
	'virtual_sites',
	'virtual_site_content',
	'term_mappings',
	'media_mappings',
	'post_mappings',
	'user_mappings',
	'sync_meta',
	'conflicts',
	'snapshots',
	'translation_memory',
	'terminology',
	'templates',
	'template_entries',
);

// Extra legacy/v1 table names that may exist but are not in wptsall_table().
$wptsall_raw_names = array(
	$wpdb->prefix . 'wptsall_model_url_rules',
	$wpdb->prefix . 'wptsall_link_chains',
	$wpdb->prefix . 'wptsall_site_groups',
	$wpdb->prefix . 'wptsall_site_group_models',
	$wpdb->prefix . 'wptsall_virtual_content',
);

$wptsall_cleared = array();
$wptsall_skipped = array();

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

// Resolve via wptsall_table() when available, otherwise fall back to $wpdb->prefix.
foreach ( $wptsall_table_keys as $key ) {
	if ( function_exists( 'wptsall_table' ) ) {
		$table = wptsall_table( $key );
	} else {
		$table = $wpdb->prefix . 'wptsall_' . $key;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists ) {
		$safe_table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$safe_table}`" );
		$wptsall_cleared[] = $table;
	} else {
		$wptsall_skipped[] = $table;
	}
}

// Legacy / raw tables.
foreach ( $wptsall_raw_names as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists ) {
		$safe_table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$safe_table}`" );
		$wptsall_cleared[] = $table . ' (legacy)';
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

echo "WPTSALL tables cleared (" . count( $wptsall_cleared ) . "):\n";
foreach ( $wptsall_cleared as $t ) {
	echo "  - {$t}\n";
}
if ( ! empty( $wptsall_skipped ) ) {
	echo "WPTSALL tables skipped (not found, " . count( $wptsall_skipped ) . "):\n";
	foreach ( $wptsall_skipped as $t ) {
		echo "  - {$t}\n";
	}
}
echo "\n";

// ---------------------------------------------------------------------------
// 3. Summary
// ---------------------------------------------------------------------------

$total_cleared = count( $wp_cleared ) + count( $wptsall_cleared );
echo "Summary: {$total_cleared} table(s) truncated.\n";
echo "  - WordPress core : " . count( $wp_cleared ) . " table(s)\n";
echo "  - WPTSALL plugin : " . count( $wptsall_cleared ) . " table(s)\n";
echo "NOTE: wp_users, wp_options, wp_usermeta were NOT modified.\n";
echo "=== reset-wordpress.php DONE ===\n\n";
