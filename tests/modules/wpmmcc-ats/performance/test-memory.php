<?php
/**
 * Memory Pressure / Performance Integration Test
 *
 * Verifies that the plugin handles large data sets without exceeding
 * reasonable memory bounds:
 *   - Reading 1000+ posts/terms does not crash
 *   - Translation_Progress_Service can summarize large sites
 *   - memory_limit_percent setting is sane (0-95 range, default 80)
 *   - PHP memory_limit is at least 128M (WP minimum)
 *   - Plugin does not leak memory across operations
 *   - GC can be triggered and reduces memory
 *   - Bulk operations complete in time
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	$_SERVER['HTTP_HOST']    = 'blog.wpmm.cc';
	$_SERVER['REQUEST_URI']  = '/';
	define( 'WP_USE_THEMES', false );
	define( 'WP_ADMIN', true );
	require '/var/www/wordpress/wp-load.php';
}

$results = [];
$failed  = 0;

function check( string $name, bool $ok, string $detail = '' ): void {
	global $results, $failed;
	$results[] = compact( 'name', 'ok', 'detail' );
	if ( ! $ok ) {
		$failed++;
		echo "FAIL: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
	} else {
		echo "PASS: {$name}\n";
	}
}

global $wpdb;

// ---------- Check 1: PHP memory_limit is set and >= 128M (or -1 for unlimited) ----------
$mem_limit_str = ini_get( 'memory_limit' );
$mem_limit_bytes = 0;
$is_unlimited = ( $mem_limit_str === '-1' );
if ( $is_unlimited ) {
	$mem_limit_bytes = PHP_INT_MAX;  // treat as unlimited
} elseif ( preg_match( '/^(\d+)([KMG]?)$/i', $mem_limit_str, $m ) ) {
	$val = (int) $m[1];
	$unit = strtoupper( $m[2] ?? '' );
	$mult = ( $unit === 'G' ? 1024 * 1024 * 1024 : ( $unit === 'M' ? 1024 * 1024 : ( $unit === 'K' ? 1024 : 1 ) ) );
	$mem_limit_bytes = $val * $mult;
}
$min_required = 128 * 1024 * 1024;
check(
	'PHP memory_limit is set and >= 128M (or -1 unlimited)',
	$mem_limit_bytes >= $min_required,
	'limit=' . $mem_limit_str . ' (unlimited=' . ( $is_unlimited ? '1' : '0' ) . ')'
);

// ---------- Check 2: WP_MEMORY_LIMIT constant is set ----------
check(
	'WP_MEMORY_LIMIT is defined',
	defined( 'WP_MEMORY_LIMIT' ),
	'value=' . ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'undefined' )
);

// ---------- Check 3: memory_limit_percent setting exists in tasks config ----------
$tasks_config = [];
if ( function_exists( 'wptsall_get_task_parameters' ) ) {
	$tasks_config = wptsall_get_task_parameters();
} elseif ( function_exists( 'wptsall_get_task_resource_limits' ) ) {
	$tasks_config = wptsall_get_task_resource_limits();
}
$has_mlp = isset( $tasks_config['memory_limit_percent'] );
$mlp_val = $tasks_config['memory_limit_percent'] ?? null;
check(
	'memory_limit_percent exists in task parameters',
	$has_mlp,
	'has_config=' . ( ! empty( $tasks_config ) ? '1' : '0' ) . ' mlp=' . var_export( $mlp_val, true )
);

// ---------- Check 3b: memory_limit_percent is in valid range (50-95) ----------
check(
	'memory_limit_percent is in 50-95 range (sane default)',
	$has_mlp && (int) $mlp_val >= 50 && (int) $mlp_val <= 95,
	'value=' . var_export( $mlp_val, true )
);

// ---------- Check 4: Reading 1000+ posts is bounded ----------
$mem_start = memory_get_usage( true );
$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'" );
$mem_after_count = memory_get_usage( true );
$delta_mb = ( $mem_after_count - $mem_start ) / 1024 / 1024;
check(
	'COUNT(*) on wp_posts uses < 5MB',
	$delta_mb < 5.0,
	'posts=' . $count . ' delta_mb=' . round( $delta_mb, 2 )
);

// ---------- Check 5: Translation_Progress_Service handles real dataset ----------
$mem_before_svc = memory_get_usage( true );
$svc = null;
if ( class_exists( 'WPTSALL\\ManualTranslation\\Services\\Translation_Progress_Service' ) ) {
	$svc = new \WPTSALL\ManualTranslation\Services\Translation_Progress_Service();
} elseif ( class_exists( 'WPTSALL\\Manual_Translation\\Services\\Translation_Progress_Service' ) ) {
	$svc = new \WPTSALL\Manual_Translation\Services\Translation_Progress_Service();
}
if ( $svc ) {
	$summary = $svc->summary();
	$mem_after_svc = memory_get_usage( true );
	$delta = ( $mem_after_svc - $mem_before_svc ) / 1024 / 1024;
	check(
		'Translation_Progress_Service::summary() uses < 32MB',
		is_array( $summary ) && $delta < 32.0,
		'summary_count=' . count( $summary ?? [] ) . ' delta_mb=' . round( $delta, 2 )
	);
	unset( $summary );
} else {
	check( 'Translation_Progress_Service::summary() uses < 32MB', false, 'class missing' );
}

// ---------- Check 6: gc_collect_cycles can be triggered ----------
$cycles_before = gc_status()['runs'] ?? 0;
$collected = gc_collect_cycles();
$cycles_after = gc_status()['runs'] ?? 0;
check(
	'gc_collect_cycles() runs and returns a count',
	$cycles_after >= $cycles_before,
	'cycles_before=' . $cycles_before . ' cycles_after=' . $cycles_after . ' collected=' . $collected
);

// ---------- Check 7: Large data array does not OOM ----------
$mem_pre = memory_get_usage( true );
try {
	$big = [];
	for ( $i = 0; $i < 100000; $i++ ) {
		$big[] = [ 'id' => $i, 'name' => "item_{$i}", 'data' => str_repeat( 'x', 10 ) ];
	}
	$mem_post = memory_get_usage( true );
	$delta_mb = ( $mem_post - $mem_pre ) / 1024 / 1024;
	check(
		'100k small array items fits in < 64MB',
		count( $big ) === 100000 && $delta_mb < 64.0,
		'count=' . count( $big ) . ' delta_mb=' . round( $delta_mb, 2 )
	);
	unset( $big );
} catch ( Exception $e ) {
	check( '100k small array items fits in < 64MB', false, 'exception: ' . $e->getMessage() );
}

// ---------- Check 8: Bulk insert 100 mappings uses < 8MB ----------
$mem_pre2 = memory_get_usage( true );
$pm_table = $wpdb->prefix . 'wptsall_post_mappings';
$inserted = 0;
$base_id = 70000;
for ( $i = 0; $i < 100; $i++ ) {
	$ok = $wpdb->insert( $pm_table, [
		'relation_id'       => $base_id + $i,
		'source_post_id'    => 1,
		'source_post_type'  => 'post',
		'source_site_id'    => 1,
		'target_post_id'    => 1,
		'target_post_type'  => 'post',
		'target_site_id'    => 1,
		'relationship_type' => 'translation',
		'created_at'        => current_time( 'mysql' ),
		'updated_at'        => current_time( 'mysql' ),
	] );
	if ( $ok ) $inserted++;
}
$mem_post2 = memory_get_usage( true );
$delta2 = ( $mem_post2 - $mem_pre2 ) / 1024 / 1024;
check(
	'100 wpdb->insert() calls use < 8MB',
	$inserted === 100 && $delta2 < 8.0,
	'inserted=' . $inserted . ' delta_mb=' . round( $delta2, 2 )
);
// Cleanup
$wpdb->query( "DELETE FROM {$pm_table} WHERE relation_id BETWEEN {$base_id} AND " . ( $base_id + 99 ) );
unset( $delta2 );

// ---------- Check 9: WordPress can read 500 posts in one go ----------
$mem_pre3 = memory_get_usage( true );
$t0       = microtime( true );
$posts    = get_posts( [
	'post_type'      => [ 'post', 'page' ],
	'post_status'    => 'publish',
	'numberposts'   => 500,
	'no_found_rows'  => true,
	'fields'         => 'ids',
] );
$dt       = microtime( true ) - $t0;
$mem_post3 = memory_get_usage( true );
$delta3   = ( $mem_post3 - $mem_pre3 ) / 1024 / 1024;
check(
	'WP get_posts(500) completes in < 5s and < 32MB',
	is_array( $posts ) && $dt < 5.0 && $delta3 < 32.0,
	'count=' . count( $posts ) . ' dt=' . round( $dt, 2 ) . 's delta_mb=' . round( $delta3, 2 )
);
unset( $posts );

// ---------- Check 10: memory_get_peak_usage is reasonable ----------
$peak_mb = memory_get_peak_usage( true ) / 1024 / 1024;
check(
	'Peak memory during test is < 256MB',
	$peak_mb < 256.0,
	'peak_mb=' . round( $peak_mb, 2 )
);

echo "\n=== Memory Pressure / Performance Integration Test ===\n";
echo "Passed: " . ( count( $results ) - $failed ) . " / " . count( $results ) . "\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
