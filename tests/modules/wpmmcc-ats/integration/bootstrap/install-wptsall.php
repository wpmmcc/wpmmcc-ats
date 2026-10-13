<?php
/**
 * Bootstrap: Install / Reinstall WPTSALL
 *
 * Forces a clean reinstallation of the WPTSALL plugin by:
 *   1. Checking the plugin is active (exits with error if not).
 *   2. Deactivating and re-activating the plugin to re-run activation hooks.
 *   3. Calling the REST /wptsall/v2/initialize endpoint via rest_do_request().
 *   4. Verifying that the 11 required core tables exist after activation.
 *
 * Safe to run multiple times.
 *
 * @package WPTSALL\DevTools\Bootstrap
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

global $wpdb;

echo "=== install-wptsall.php ===\n";
echo "Reinstalling WPTSALL plugin...\n\n";

// ---------------------------------------------------------------------------
// 0. Load plugin admin helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/**
 * Ensure admin user context for permissioned REST routes.
 *
 * @throws Exception When no admin user is available.
 * @return int
 */
function wptsall_install_ensure_admin_user() {
	$current = get_current_user_id();
	if ( $current > 0 && user_can( $current, 'manage_options' ) ) {
		return $current;
	}

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => array( 'ID' ),
		)
	);
	if ( empty( $admins ) ) {
		throw new Exception( 'No administrator account found for initialize endpoint.' );
	}

	$admin_id = (int) $admins[0]->ID;
	wp_set_current_user( $admin_id );
	return $admin_id;
}

// ---------------------------------------------------------------------------
// 1. Check the plugin is active
// ---------------------------------------------------------------------------

$plugin_slug = 'wpmmcc-ats/wpmmcc-ats.php';

if ( ! is_plugin_active( $plugin_slug ) ) {
	echo "ERROR: WPTSALL plugin is not active ({$plugin_slug}).\n";
	echo "Run: wp plugin activate wpmmcc-ats\n";
	echo "=== install-wptsall.php FAILED ===\n\n";
	return;
}

echo "  OK  Plugin is active: {$plugin_slug}\n\n";

// ---------------------------------------------------------------------------
// 2. Deactivate then re-activate to trigger activation hooks
// ---------------------------------------------------------------------------

echo "Step 1: Deactivating plugin...\n";
deactivate_plugins( $plugin_slug );

if ( is_plugin_active( $plugin_slug ) ) {
	echo "  WARN  Plugin is still active after deactivate_plugins() call.\n";
} else {
	echo "  OK  Plugin deactivated.\n";
}

echo "Step 2: Re-activating plugin...\n";
$activation_result = activate_plugin( $plugin_slug );

if ( is_wp_error( $activation_result ) ) {
	echo "  ERROR  Activation failed: " . $activation_result->get_error_message() . "\n";
	echo "=== install-wptsall.php FAILED ===\n\n";
	return;
}

if ( ! is_plugin_active( $plugin_slug ) ) {
	echo "  ERROR  Plugin did not become active after activate_plugin().\n";
	echo "=== install-wptsall.php FAILED ===\n\n";
	return;
}

echo "  OK  Plugin re-activated (activation hooks fired).\n\n";

// ---------------------------------------------------------------------------
// 3. Call the REST initialize endpoint
// ---------------------------------------------------------------------------

echo "Step 3: Calling REST /wptsall/v2/initialize...\n";

// Ensure REST server and routes are registered.
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init' );
}

try {
	$admin_id = wptsall_install_ensure_admin_user();
	echo "  OK  Admin context set: user_id={$admin_id}\n";
} catch ( Exception $e ) {
	echo "  WARN  Unable to set admin context: " . $e->getMessage() . "\n";
}

$request  = new WP_REST_Request( 'POST', '/wptsall/v2/initialize' );
$response = rest_do_request( $request );

if ( is_wp_error( $response ) ) {
	echo "  WARN  REST request returned WP_Error: " . $response->get_error_message() . "\n";
} else {
	$status = $response->get_status();
	$data   = $response->get_data();

	if ( $status >= 200 && $status < 300 ) {
		echo "  OK  REST /wptsall/v2/initialize returned HTTP {$status}.\n";
	} elseif ( 404 === $status ) {
		echo "  WARN  REST /wptsall/v2/initialize returned 404 (endpoint may not exist yet).\n";
	} else {
		$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : wp_json_encode( $data );
		echo "  WARN  REST /wptsall/v2/initialize returned HTTP {$status}: {$message}\n";
	}
}

echo "\n";

// ---------------------------------------------------------------------------
// 4. Verify required tables exist
// ---------------------------------------------------------------------------

echo "Step 4: Verifying required database tables...\n";

// Core tables every fresh WPTSALL install must have.
// Tasks module is now always loaded (not just in Pro), so tasks/translation_results
// are also required since bootstrap.php includes tasks/module.php unconditionally.
$required_table_keys = array(
	'mappings',
	'models',
	'model_objects',
	'model_object_fields',
	'translation_rules',
	'plugin_mappings',
	'site_relations',
	'relation_models',
	'virtual_sites',
	'templates',
	'template_entries',
	// Tasks module (always-on since unified single-plugin mode).
	'tasks',
	'translation_results',
);

$tables_ok      = 0;
$tables_missing = array();

foreach ( $required_table_keys as $key ) {
	if ( function_exists( 'wptsall_table' ) ) {
		$table = wptsall_table( $key );
	} else {
		$table = $wpdb->prefix . 'wptsall_' . $key;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	if ( $exists ) {
		echo "  OK    {$table}\n";
		++$tables_ok;
	} else {
		echo "  MISS  {$table}\n";
		$tables_missing[] = $table;
	}
}

echo "\n";

// ---------------------------------------------------------------------------
// 5. Summary
// ---------------------------------------------------------------------------

$total_required = count( $required_table_keys );

if ( empty( $tables_missing ) ) {
	echo "All {$total_required} required tables present.\n";
	echo "=== install-wptsall.php DONE (OK) ===\n\n";
} else {
	$missing_count = count( $tables_missing );
	echo "WARNING: {$missing_count} of {$total_required} required table(s) are missing:\n";
	foreach ( $tables_missing as $t ) {
		echo "  - {$t}\n";
	}
	echo "Plugin activation may have partially failed. Check PHP error logs.\n";
	echo "=== install-wptsall.php DONE (with warnings) ===\n\n";
}
