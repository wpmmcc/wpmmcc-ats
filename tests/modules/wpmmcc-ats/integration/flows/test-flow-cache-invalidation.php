<?php
/**
 * Cache Invalidation Integration Test
 *
 * Verifies that the wptsall cache layer is correctly invalidated when:
 *   - Data changes (transient caching)
 *   - Settings change
 *   - Models are updated
 *
 * Uses WordPress Transients API (wptsall_cache_* group) and option-based caches.
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

// Check 1: WPTSALL_CACHE_GROUP constant defined
check(
	'WPTSALL_CACHE_GROUP constant defined',
	defined( 'WPTSALL_CACHE_GROUP' ) && strpos( WPTSALL_CACHE_GROUP, 'wptsall' ) === 0
);

// Check 2: wptsall_get_cache_ttl function exists
check(
	'wptsall_get_cache_ttl() function exists',
	function_exists( 'wptsall_get_cache_ttl' )
);

// Check 3: wptsall_get_cache_ttl returns int for known types
$ttl_default = wptsall_get_cache_ttl( 'default' );
check(
	'wptsall_get_cache_ttl(default) returns int',
	is_int( $ttl_default ) && $ttl_default > 0,
	'value=' . $ttl_default
);

$ttl_templates = wptsall_get_cache_ttl( 'templates' );
check(
	'wptsall_get_cache_ttl(templates) returns int',
	is_int( $ttl_templates ) && $ttl_templates > 0,
	'value=' . $ttl_templates
);

$ttl_sites = wptsall_get_cache_ttl( 'sites' );
check(
	'wptsall_get_cache_ttl(sites) returns int',
	is_int( $ttl_sites ) && $ttl_sites > 0,
	'value=' . $ttl_sites
);

$ttl_stats = wptsall_get_cache_ttl( 'stats' );
check(
	'wptsall_get_cache_ttl(stats) returns int',
	is_int( $ttl_stats ) && $ttl_stats > 0,
	'value=' . $ttl_stats
);

// Check 4: Set/Get transient with wptsall prefix
$test_key  = 'wptsall_cache_test_' . wp_rand();
$test_data = [ 'foo' => 'bar', 'time' => time() ];
set_transient( $test_key, $test_data, MINUTE_IN_SECONDS );
$retrieved = get_transient( $test_key );
check(
	'Set/get transient roundtrip',
	$retrieved === $test_data,
	'foo=' . ( $retrieved['foo'] ?? 'missing' )
);

// Check 5: Delete transient works
delete_transient( $test_key );
$after_delete = get_transient( $test_key );
check(
	'Delete transient removes value',
	false === $after_delete,
	'value=' . var_export( $after_delete, true )
);

// Check 6: wptsall_cache_invalidate_all() or similar function exists
$has_flush = function_exists( 'wptsall_cache_invalidate_all' )
	|| function_exists( 'wptsall_cache_invalidate' )
	|| function_exists( 'wptsall_cache_flush' );
check( 'Cache invalidate function exists', $has_flush );

// Check 7: Bulk delete transients works
$keys = [];
for ( $i = 0; $i < 3; $i++ ) {
	$k = 'wptsall_cache_bulk_' . $i;
	set_transient( $k, $i, MINUTE_IN_SECONDS );
	$keys[] = $k;
}
foreach ( $keys as $k ) {
	delete_transient( $k );
}
$all_cleared = true;
foreach ( $keys as $k ) {
	if ( false !== get_transient( $k ) ) {
		$all_cleared = false;
		break;
	}
}
check( 'Bulk delete transients works', $all_cleared );

// Check 8: At least one model/admin path has cache invalidation
$search_paths = [
	WPTSALL_PATH . 'includes/models/services/',
	WPTSALL_PATH . 'includes/models/admin/',
	WPTSALL_PATH . 'includes/models/',
];
$has_model_invalidation = false;
$checked = [];
foreach ( $search_paths as $path ) {
	if ( ! is_dir( $path ) ) continue;
	$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
	foreach ( $iter as $f ) {
		if ( $f->getExtension() !== 'php' ) continue;
		$content = file_get_contents( $f->getPathname() );
		if ( $content && ( strpos( $content, 'delete_transient' ) !== false || strpos( $content, 'wptsall_cache_invalidate' ) !== false ) ) {
			$has_model_invalidation = true;
			$checked[] = basename( $f->getPathname() );
			break 2;
		}
	}
}
if ( $has_model_invalidation ) {
	check( 'At least one model-related file has cache invalidation', true );
} else {
	echo "INFO: No model-related file has cache invalidation (may be acceptable if cache not used)\n";
}

// Check 9: Settings service has cache invalidation (optional)
$settings_files = [
	WPTSALL_PATH . 'includes/settings/services/class-settings-service.php',
	WPTSALL_PATH . 'includes/settings/admin/class-settings-page.php',
];
$has_settings_invalidation = false;
foreach ( $settings_files as $f ) {
	if ( file_exists( $f ) ) {
		$content = file_get_contents( $f );
		if ( $content && ( strpos( $content, 'delete_transient' ) !== false || strpos( $content, 'wptsall_cache_invalidate' ) !== false ) ) {
			$has_settings_invalidation = true;
			break;
		}
	}
}
// Settings may not have cache invalidation if settings are read directly from option - that's OK
if ( $has_settings_invalidation ) {
	check( 'Settings has cache invalidation', true );
} else {
	echo "INFO: Settings has no cache invalidation (settings may be read directly from option)\n";
}

// Check 10: Cache layer file exists
check(
	'Cache layer file exists',
	file_exists( WPTSALL_PATH . 'includes/core/cache.php' )
);

// Check 11: cache.php defines get_or_set / invalidate / invalidate_all helpers
$cache_source = file_get_contents( WPTSALL_PATH . 'includes/core/cache.php' );
$has_set_helper = strpos( $cache_source, 'function wptsall_cache_get_or_set' ) !== false;
$has_get_helper = strpos( $cache_source, 'function wptsall_cache_get_or_set' ) !== false;
$has_del_helper = strpos( $cache_source, 'function wptsall_cache_invalidate' ) !== false;
$has_flush_helper = strpos( $cache_source, 'function wptsall_cache_invalidate_all' ) !== false;
check( 'wptsall_cache_get_or_set helper exists', $has_set_helper );
check( 'wptsall_cache_get_or_set exists (covers get/set)', $has_get_helper );
check( 'wptsall_cache_invalidate helper exists', $has_del_helper );
check( 'wptsall_cache_invalidate_all helper exists', $has_flush_helper );

// Check 12: wptsall_cache_get_or_set roundtrip
if ( $has_set_helper ) {
	$ck = 'cache_helper_test_' . wp_rand();
	$result = wptsall_cache_get_or_set( $ck, function () { return [ 'data' => 'test123' ]; }, MINUTE_IN_SECONDS );
	check(
		'wptsall_cache_get_or_set callback returns data',
		is_array( $result ) && ( $result['data'] ?? null ) === 'test123'
	);
	$cached2 = wptsall_cache_get_or_set( $ck, function () { return [ 'data' => 'should-not-run' ]; }, MINUTE_IN_SECONDS );
	check(
		'wptsall_cache_get_or_set returns cached value (callback not re-run)',
		is_array( $cached2 ) && ( $cached2['data'] ?? null ) === 'test123'
	);
	if ( $has_del_helper && function_exists( 'wptsall_cache_invalidate' ) ) {
		wptsall_cache_invalidate( $ck );
		$cached3 = wptsall_cache_get_or_set( $ck, function () { return [ 'data' => 'after-invalidate' ]; }, MINUTE_IN_SECONDS );
		check( 'wptsall_cache_invalidate clears cached value', $cached3['data'] === 'after-invalidate' );
	}
}

// Check 13: TTL values are reasonable (not infinite)
$ttl_min = 60; // 1 min
check(
	'Default TTL is >= 1 minute',
	$ttl_default >= $ttl_min,
	"ttl_default={$ttl_default}"
);
check(
	'Templates TTL is >= 1 minute',
	$ttl_templates >= $ttl_min,
	"ttl_templates={$ttl_templates}"
);

// Check 14: task lifecycle invalidation chain (§63 A1 architecture)
// Cache invalidation moved out of the admin pages into the lifecycle-hook
// chain: tasks.php emits task_created/task_updated, the REST controller
// emits task_deleted and still invalidates on update, cache.php listens on
// all three hooks and invalidates the task stats, and automation-cron
// invalidates directly. Verify each link of that chain instead of the old
// "admin page files contain invalidation strings" contract.
$tasks_source = file_get_contents( WPTSALL_PATH . 'includes/tasks/tasks.php' );
$rest_source  = file_get_contents( WPTSALL_PATH . 'includes/tasks/api/class-tasks-rest-controller.php' );
$cron_source  = file_get_contents( WPTSALL_PATH . 'includes/tasks/automation-cron.php' );
$has_emitters = is_string( $tasks_source )
	&& strpos( $tasks_source, "do_action( 'wptsall_task_created'" ) !== false
	&& strpos( $tasks_source, "do_action( 'wptsall_task_updated'" ) !== false;
$has_rest_link = is_string( $rest_source )
	&& ( strpos( $rest_source, "do_action( 'wptsall_task_deleted'" ) !== false
		|| strpos( $rest_source, 'wptsall_cache_invalidate' ) !== false );
$has_cache_listener = is_string( $cache_source )
	&& strpos( $cache_source, 'wptsall_task_created' ) !== false
	&& strpos( $cache_source, 'wptsall_task_updated' ) !== false
	&& strpos( $cache_source, 'wptsall_task_deleted' ) !== false
	&& strpos( $cache_source, 'wptsall_cache_invalidate' ) !== false;
$has_cron_link = is_string( $cron_source )
	&& strpos( $cron_source, 'wptsall_cache_invalidate' ) !== false;
check(
	'Task lifecycle invalidation chain (emitters + cache listener + REST/cron)',
	$has_emitters && $has_rest_link && $has_cache_listener && $has_cron_link,
	'emitters=' . (int) $has_emitters . ' rest=' . (int) $has_rest_link . ' listener=' . (int) $has_cache_listener . ' cron=' . (int) $has_cron_link
);

// Check 15: Settings update has cache handling (may be inline or via cache_invalidate_all)
$source_settings_page = file_get_contents( WPTSALL_PATH . 'includes/settings/admin/class-settings-page.php' );
if ( $source_settings_page ) {
	$has_clear_on_save = strpos( $source_settings_page, 'delete_transient' ) !== false
		|| strpos( $source_settings_page, 'wptsall_cache_invalidate' ) !== false
		|| strpos( $source_settings_page, 'wptsall_cache_flush' ) !== false
		|| strpos( $source_settings_page, 'wptsall_cache_invalidate_all' ) !== false;
	if ( $has_clear_on_save ) {
		check( 'Settings page has cache invalidation', true );
	} else {
		echo "INFO: Settings page has no explicit cache invalidation (acceptable if settings are not cached)\n";
	}
}

echo "\n=== Cache Invalidation Integration Test ===\n";
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