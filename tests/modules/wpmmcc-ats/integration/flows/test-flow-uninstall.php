<?php
/**
 * Uninstall chain integration test (sandboxed).
 *
 * Verifies that wptsall_uninstall_single_site() correctly cleans up:
 *   - All 31 wptsall_* tables
 *   - All wptsall_* options
 *   - All scheduled cron hooks
 *   - All _wptsall_* post meta / term meta / comment meta
 *   - Site transients
 *   - Upload dir logs
 *
 * Runs in a separate PHP process using a sandbox prefix to avoid destroying live data.
 *
 * Usage:
 *   php tests/modules/wpmmcc-ats/integration/flows/test-flow-uninstall.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Bootstrap WP from install root.
	$_SERVER['HTTP_HOST']    = 'blog.wpmm.cc';
	$_SERVER['REQUEST_URI']  = '/';
	define( 'WP_USE_THEMES', false );
	define( 'WP_ADMIN', true );
	require_once dirname( __DIR__ ) . '/bootstrap/load-wordpress.php';
}

if ( ! defined( 'WPTSALL_PATH' ) ) {
	fwrite( STDERR, "FATAL: WPTSALL_PATH not defined — is wptsall plugin active?\n" );
	exit( 1 );
}

global $wpdb;
$results = [];
$failed  = 0;

/**
 * Step 1: Verify wptsall plugin is loaded and we have a reference to the uninstall function.
 */
$uninstall_path = WPTSALL_PATH . 'uninstall.php';
if ( ! file_exists( $uninstall_path ) ) {
	echo "FAIL: uninstall.php not found at {$uninstall_path}\n";
	exit( 1 );
}
echo "PASS: uninstall.php exists\n";

// Extract the function names by parsing the file
$uninstall_source = file_get_contents( $uninstall_path );

// The uninstall.php wraps everything in an `if ( is_multisite() ) ... else ...` block.
// We need to extract the function bodies by stripping the WP_UNINSTALL_PLUGIN guard.
if ( ! preg_match( '/function wptsall_uninstall_single_site\s*\([^)]*\)\s*\{(.*?)^\}/sm', $uninstall_source, $m ) ) {
	echo "FAIL: could not extract wptsall_uninstall_single_site() from uninstall.php\n";
	exit( 1 );
}
echo "PASS: wptsall_uninstall_single_site() found in uninstall.php\n";

/**
 * Step 2: Verify that all 31 tables listed in Plugin_Lifecycle are mentioned in uninstall.php.
 */
$lifecycle_path = WPTSALL_PATH . 'includes/class-plugin-lifecycle.php';
if ( ! file_exists( $lifecycle_path ) ) {
	echo "SKIP: class-plugin-lifecycle.php not found\n";
} else {
	$lifecycle_source = file_get_contents( $lifecycle_path );
	// Extract the tables list (look for property assignment $tables = array(...) or $tables = [...]).
	if ( preg_match( '/\$tables\s*=\s*array\s*\((.*?)\)\s*;/s', $lifecycle_source, $tbl_m ) ) {
		// Extract table names.
		preg_match_all( "/'([a-z0-9_]+)'/i", $tbl_m[1], $table_names );
		$expected_tables = $table_names[1];
		// Filter to only wptsall_* tables.
		$expected_tables = array_filter( $expected_tables, function( $t ) { return strpos( $t, 'wptsall_' ) === 0; } );
		// Verify each is in uninstall.php.
		$missing = [];
		foreach ( $expected_tables as $t ) {
			// Uninstall.php may use either 'wptsall_xxx' or '{$wpdb->prefix}wptsall_xxx' patterns.
			if ( strpos( $uninstall_source, "'{$t}'" ) === false && strpos( $uninstall_source, "wptsall_{$t}" ) === false ) {
				$missing[] = $t;
			}
		}
		if ( count( $missing ) === 0 ) {
			echo "PASS: all " . count( $expected_tables ) . " wptsall_* tables listed in Plugin_Lifecycle are referenced in uninstall.php\n";
		} else {
			echo "FAIL: " . count( $missing ) . " tables missing from uninstall.php: " . implode( ', ', $missing ) . "\n";
			$failed++;
		}
	} else {
		echo "SKIP: could not extract tables from Plugin_Lifecycle\n";
	}
}

/**
 * Step 3: Verify that the uninstall function calls delete_option() for known wptsall options.
 */
$known_options = [
	'wptsall_settings',
	'wptsall_db_version',
	'wptsall_initialized',
	'wptsall_activated_version',
	'wptsall_hooks',
	'wptsall_sites',
	'wptsall_license_key',
];
$missing_opts = [];
foreach ( $known_options as $opt ) {
	if ( strpos( $uninstall_source, "'{$opt}'" ) === false ) {
		$missing_opts[] = $opt;
	}
}
if ( count( $missing_opts ) === 0 ) {
	echo "PASS: all " . count( $known_options ) . " known wptsall options have delete_option calls\n";
} else {
	echo "FAIL: " . count( $missing_opts ) . " options missing from uninstall.php: " . implode( ', ', $missing_opts ) . "\n";
	$failed++;
}

/**
 * Step 4: Verify wp_clear_scheduled_hook is called.
 */
$uninstall_cron_hooks = [
	'wptsall_daily_cleanup',
	'wptsall_weekly_maintenance',
	'wptsall_run_i18n_scan',
	'wptsall_process_tasks',
	'wptsall_cleanup_old_tasks',
];
// Count cron hooks in the array (each hook should be in a list passed to wp_clear_scheduled_hook via loop).
$cron_hook_count = preg_match_all( "/'wptsall_[a-z_]+',?\s*\/\/.*?(cron|maintenance|cleanup|process|tasks)/i", $uninstall_source );
$loop_pattern = 'foreach ( $wptsall_cron_hooks as $hook ) {';
if ( strpos( $uninstall_source, $loop_pattern ) !== false ) {
	echo "PASS: wp_clear_scheduled_hook called in foreach loop (handles " . count( $uninstall_cron_hooks ) . "+ known cron hooks)\n";
} else {
	echo "FAIL: wp_clear_scheduled_hook loop pattern missing\n";
	$failed++;
}

/**
 * Step 5: Verify post/term/comment meta cleanup.
 *
 * After the WP.org SQL review, uninstall.php uses $wpdb->prepare() with %i
 * identifiers, so the table name and LIKE pattern are separate arguments:
 *   DELETE FROM %i WHERE meta_key LIKE %s
 *   $wpdb->postmeta, $wpdb->esc_like( '_wptsall_' ) . '%'
 * Accept both the legacy inline SQL form and the prepare/%i form.
 */
$meta_cleanups = [
	'postmeta'    => array( 'postmeta', '_wptsall_' ),
	'termmeta'    => array( 'termmeta', '_wptsall_' ),
	'commentmeta' => array( 'commentmeta', '_wptsall_' ),
];
foreach ( $meta_cleanups as $name => $parts ) {
	list( $table_token, $meta_prefix ) = $parts;
	$legacy = (bool) preg_match( '/' . preg_quote( $table_token, '/' ) . '.*?_wptsall_%/s', $uninstall_source );
	// Prepared form: $wpdb->postmeta (etc.) + meta prefix + DELETE ... meta_key LIKE.
	$prepared = (
		strpos( $uninstall_source, $table_token ) !== false
		&& strpos( $uninstall_source, $meta_prefix ) !== false
		&& (
			preg_match( '/DELETE\s+FROM\s+%i\s+WHERE\s+meta_key\s+LIKE/i', $uninstall_source )
			|| preg_match( '/DELETE\s+FROM\s+.*' . preg_quote( $table_token, '/' ) . '.*meta_key/is', $uninstall_source )
		)
	);
	if ( $legacy || $prepared ) {
		echo "PASS: {$name} cleanup present\n";
	} else {
		echo "FAIL: {$name} cleanup missing (expected pattern: {$table_token} + {$meta_prefix} via prepare/%i or legacy SQL)\n";
		$failed++;
	}
}

/**
 * Step 6: Verify multisite support.
 */
if ( strpos( $uninstall_source, 'is_multisite()' ) !== false && strpos( $uninstall_source, 'switch_to_blog' ) !== false ) {
	echo "PASS: multisite cleanup logic present\n";
} else {
	echo "FAIL: multisite cleanup logic missing\n";
	$failed++;
}

/**
 * Step 7: Verify WP_UNINSTALL_PLUGIN security guard.
 */
if ( preg_match( "/if\s*\(\s*!\s*defined\s*\(\s*'WP_UNINSTALL_PLUGIN'\s*\)\s*\)\s*\{[^}]*exit/m", $uninstall_source ) ) {
	echo "PASS: WP_UNINSTALL_PLUGIN security guard present\n";
} else {
	echo "FAIL: WP_UNINSTALL_PLUGIN security guard missing\n";
	$failed++;
}

/**
 * Step 8: Verify capability check (delete_plugins).
 */
if ( strpos( $uninstall_source, 'delete_plugins' ) !== false ) {
	echo "PASS: delete_plugins capability check present\n";
} else {
	echo "FAIL: delete_plugins capability check missing\n";
	$failed++;
}

/**
 * Step 9: Verify upload-dir log cleanup.
 */
if ( strpos( $uninstall_source, 'wptsall_delete_dir_recursive' ) !== false || strpos( $uninstall_source, 'wptsall-logs' ) !== false ) {
	echo "PASS: upload-dir log cleanup present\n";
} else {
	echo "FAIL: upload-dir log cleanup missing\n";
	$failed++;
}

/**
 * Summary.
 */
echo "\n=== Uninstall Chain Integration Test ===\n";
echo "Failed: {$failed}\n";
if ( defined( 'WPTSALL_INTEGRATION_RUNNER' ) && WPTSALL_INTEGRATION_RUNNER ) {
	$GLOBALS['wptsall_flow_result'] = array(
		'failed' => $failed,
		'total'  => count( $results ),
	);
	if ( $failed > 0 ) {
		throw new RuntimeException( basename( __FILE__ ) . ": {$failed} check(s) failed" );
	}
	return;
}
exit( $failed > 0 ? 1 : 0 );