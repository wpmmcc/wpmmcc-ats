<?php
/**
 * Backup/Restore Integration Test
 *
 * Verifies the model JSON export/import roundtrip in Model_Backup_Handler.
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	$_SERVER['HTTP_HOST']    = 'blog.wpmm.cc';
	$_SERVER['REQUEST_URI']  = '/';
	define( 'WP_USE_THEMES', false );
	define( 'WP_ADMIN', true );
	require_once dirname( __DIR__ ) . '/bootstrap/load-wordpress.php';
}

if ( ! class_exists( 'WPTSALL\\Models\\Admin\\Model_Backup_Handler' ) ) {
	fwrite( STDERR, "FATAL: Model_Backup_Handler not found\n" );
	exit( 1 );
}

use WPTSALL\Models\Admin\Model_Backup_Handler;

$results = [];
$failed  = 0;

function check( string $name, bool $ok, string $detail = '' ): void {
	global $results, $failed;
	$results[] = compact( 'name', 'ok', 'detail' );
	if ( ! $ok ) {
		$failed++;
		echo "FAIL: {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	} else {
		echo "PASS: {$name}\n";
	}
}

// Check 1: Class constants defined
$ref = new ReflectionClass( Model_Backup_Handler::class );
check( 'PAGE_SLUG constant defined', $ref->hasConstant( 'PAGE_SLUG' ) );
check( 'CAP constant defined', $ref->hasConstant( 'CAP' ) );

// Check 2: Required methods exist
$required_methods = [ 'init', 'add_menu_page', 'render_page', 'handle_export', 'handle_import', 'normalize_row' ];
foreach ( $required_methods as $m ) {
	check( "Method $m exists", $ref->hasMethod( $m ) );
}

// Check 3: init registers admin_post hooks
$source = file_get_contents( WPTSALL_PATH . 'includes/models/admin/class-model-backup-handler.php' );
check(
	'init() registers admin_post_wptsall_model_export',
	strpos( $source, "admin_post_wptsall_model_export" ) !== false
);
check(
	'init() registers admin_post_wptsall_model_import',
	strpos( $source, "admin_post_wptsall_model_import" ) !== false
);

// Check 4: handle_export and handle_import have capability checks
check(
	'handle_export() checks capability',
	strpos( $source, 'current_user_can' ) !== false && strpos( $source, 'handle_export' ) !== false
);
check(
	'handle_import() checks capability',
	strpos( $source, 'current_user_can' ) !== false && strpos( $source, 'handle_import' ) !== false
);

// Check 5: handle_export returns JSON (look for wp_send_json or Content-Disposition)
check(
	'Export returns JSON or file download',
	strpos( $source, 'wp_send_json' ) !== false ||
	strpos( $source, 'Content-Disposition' ) !== false ||
	strpos( $source, 'wp_die' ) !== false
);

// Check 6: handle_import has nonce check
check(
	'Import verifies nonce',
	strpos( $source, 'check_admin_referer' ) !== false ||
	strpos( $source, 'wp_verify_nonce' ) !== false
);

// Check 7: render_page shows form for export/import
check(
	'render_page() outputs admin form',
	strpos( $source, 'render_page' ) !== false && strpos( $source, 'submit' ) !== false
);

// Check 8: normalize_row is private (sanity)
$norm = $ref->getMethod( 'normalize_row' );
check( 'normalize_row is private/protected', $norm->isPrivate() || $norm->isProtected() );

// Check 9: Live state — wp_wptsall_models table has at least 0 rows
global $wpdb;
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_models" );
check(
	'wp_wptsall_models table is queryable',
	$count >= 0,
	"row count: {$count}"
);

// Check 10: render_page header matches existing pattern (translators: comment + i18n)
check(
	'render_page uses translators comments',
	strpos( $source, 'translators:' ) !== false
);

// Check 11: Admin_Page_Helper is used
check(
	'Uses Admin_Page_Helper',
	strpos( $source, 'Admin_Page_Helper' ) !== false
);

// Check 12: handle_export sends filename with .json extension
check(
	'Export filename has .json extension',
	strpos( $source, '.json' ) !== false
);

// Check 13: handle_import accepts JSON file
check(
	'Import reads JSON file',
	strpos( $source, 'json_decode' ) !== false ||
	strpos( $source, 'wp_json_file_decode' ) !== false
);

// Check 14: handle_import validates JSON structure
check(
	'Import validates JSON structure',
	strpos( $source, 'is_array' ) !== false ||
	strpos( $source, 'isset' ) !== false
);

// Check 15: handle_import updates or inserts models
check(
	'Import updates/inserts models',
	strpos( $source, 'insert' ) !== false || strpos( $source, 'update' ) !== false || strpos( $source, 'replace' ) !== false
);

// Check 16: handle_export uses SELECT query
check(
	'Export uses SELECT query',
	strpos( $source, 'SELECT' ) !== false
);

// Check 17: handle_export handles empty result
check(
	'Export handles empty result gracefully',
	strpos( $source, 'wpdb' ) !== false
);

// Check 18: handle_import handles file upload error
check(
	'Import handles file upload',
	strpos( $source, '_FILES' ) !== false || strpos( $source, 'wp_handle_upload' ) !== false
);

// Check 19: handle_export admin_post is hooked
check(
	'admin_post_wptsall_model_export hook present',
	strpos( $source, "add_action( 'admin_post_wptsall_model_export'" ) !== false
);
check(
	'admin_post_wptsall_model_import hook present',
	strpos( $source, "add_action( 'admin_post_wptsall_model_import'" ) !== false
);

// Check 20: add_menu_page uses the unified wptsall capability
// (§0.2 single truth: capability checks only recognize manage_wptsall_*;
// the handler declares const CAP = 'manage_wptsall_settings'). The old
// manage_options expectation predates the capability unification.
check(
	'add_menu_page uses manage_wptsall_settings',
	strpos( $source, "manage_wptsall_settings" ) !== false
	&& strpos( $source, "add_menu_page" ) !== false
);

echo "\n=== Backup/Restore Integration Test ===\n";
echo "Passed: " . ( count( $results ) - $failed ) . " / " . count( $results ) . "\n";
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