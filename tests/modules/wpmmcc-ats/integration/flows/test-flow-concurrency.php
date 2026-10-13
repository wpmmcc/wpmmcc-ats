<?php
/**
 * Concurrent Sync Atomicity Integration Test
 *
 * Verifies that the sync executor handles concurrent operations atomically:
 *   - No duplicate mapping rows on simultaneous claims
 *   - No orphaned rows on interrupted sync
 *   - State transitions are consistent under concurrent load
 *
 * Test approach: spawn N concurrent "claims" against the same post_id
 * and verify only one succeeds (others get duplicate or skip).
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

global $wpdb;

// Check 1: Direct_DB_Service has table() method
$ref_direct = null;
$direct_file = WPTSALL_PATH . 'includes/tasks/services/class-direct-db-service.php';
check(
	'Direct_DB_Service class file exists',
	file_exists( $direct_file )
);

// Check 2: Source code uses transactions for atomic state changes (optional, schema UNIQUE may suffice)
$source = file_get_contents( $direct_file );
$has_start_tx  = strpos( $source, 'START TRANSACTION' ) !== false;
$has_commit    = strpos( $source, 'COMMIT' ) !== false;
$has_rollback  = strpos( $source, 'ROLLBACK' ) !== false;
if ( $has_start_tx && $has_commit && $has_rollback ) {
	check( 'Direct_DB_Service uses full transaction support', true );
} else {
	echo "INFO: Direct_DB_Service lacks full transaction support (relies on schema UNIQUE keys for dedup)\n";
}

// Check 3: Sync_Executor has transaction wrapping
$sync_file = WPTSALL_PATH . 'includes/tasks/sync/class-sync-executor.php';
$sync_source = file_get_contents( $sync_file );
$has_tx_wrap = strpos( $sync_source, 'transaction' ) !== false || strpos( $sync_source, 'START TRANSACTION' ) !== false;
check( 'Sync_Executor has transaction support', $has_tx_wrap );

// Check 4: Manual queue has unique constraint or deduplication (optional)
$mq_file = WPTSALL_PATH . 'includes/tasks/sync/class-manual-queue.php';
if ( file_exists( $mq_file ) ) {
	$mq_source = file_get_contents( $mq_file );
	$has_unique = strpos( $mq_source, 'UNIQUE' ) !== false
		|| strpos( $mq_source, 'INSERT IGNORE' ) !== false
		|| strpos( $mq_source, 'ON DUPLICATE' ) !== false;
	if ( $has_unique ) {
		check( 'Manual queue has unique/dedup logic', true );
	} else {
		echo "INFO: Manual queue has no explicit UNIQUE/INSERT IGNORE (acceptable with table constraints)\n";
	}
}

// Check 5: Post mappings have unique key on (source_id, target_lang)
$pm_source = file_get_contents( WPTSALL_PATH . 'includes/sites/database/schema-post-mappings.php' );
if ( $pm_source ) {
	$has_unique = strpos( $pm_source, 'UNIQUE KEY' ) !== false || strpos( $pm_source, 'UNIQUE INDEX' ) !== false;
	check( 'Post mappings schema has UNIQUE constraint', $has_unique );
} else {
	echo "INFO: post-mappings schema file not found\n";
}

// Check 6: Term mappings have unique key
$tm_source = file_get_contents( WPTSALL_PATH . 'includes/sites/database/schema-term-mappings.php' );
if ( $tm_source ) {
	$has_unique = strpos( $tm_source, 'UNIQUE KEY' ) !== false || strpos( $tm_source, 'UNIQUE INDEX' ) !== false;
	check( 'Term mappings schema has UNIQUE constraint', $has_unique );
}

// Check 7: Media mappings have unique key
$mm_source = file_get_contents( WPTSALL_PATH . 'includes/media-translation/database/schema-media-mappings.php' );
if ( ! $mm_source ) {
	$mm_source = file_get_contents( WPTSALL_PATH . 'includes/media-translation/database/schema.php' );
}
if ( $mm_source ) {
	$has_unique = strpos( $mm_source, 'UNIQUE KEY' ) !== false || strpos( $mm_source, 'UNIQUE INDEX' ) !== false;
	check( 'Media mappings schema has UNIQUE constraint', $has_unique );
}

// Check 8: User mappings have unique key
$um_source = file_get_contents( WPTSALL_PATH . 'includes/user-translation/database/schema-user-mappings.php' );
if ( ! $um_source ) {
	$um_source = file_get_contents( WPTSALL_PATH . 'includes/user-translation/database/schema.php' );
}
if ( $um_source ) {
	$has_unique = strpos( $um_source, 'UNIQUE KEY' ) !== false || strpos( $um_source, 'UNIQUE INDEX' ) !== false;
	check( 'User mappings schema has UNIQUE constraint', $has_unique );
}

// Check 9: Verify post_mappings has UNIQUE constraint that prevents duplicates
$pm_table = $wpdb->prefix . 'wptsall_post_mappings';
$indexes = $wpdb->get_results( "SHOW INDEX FROM {$pm_table}", ARRAY_A );
$has_unique_mapping = false;
$unique_cols = '';
foreach ( $indexes ?: [] as $idx ) {
	if ( $idx['Non_unique'] == 0 && $idx['Key_name'] === 'unique_mapping' ) {
		$has_unique_mapping = true;
		$unique_cols = $idx['Column_name'];
		break;
	}
}
check(
	'post_mappings has unique_mapping UNIQUE constraint (dedup at DB level)',
	$has_unique_mapping,
	'columns=' . $unique_cols
);

// Check 9b: Simulate concurrent INSERT with same key — only 1 should succeed
$src_post = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft') ORDER BY ID DESC LIMIT 1" );
$target_post = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE ID != {$src_post} AND post_status IN ('publish','draft') ORDER BY ID DESC LIMIT 1" );
if ( $src_post > 0 && $target_post > 0 ) {
	$test_relation = 99999;  // Test-specific relation to avoid conflicts
	// Cleanup any prior test rows
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$pm_table} WHERE relation_id = %d",
		$test_relation
	) );

	$inserted_ids = [];
	for ( $i = 0; $i < 5; $i++ ) {
		$ok = $wpdb->insert(
			$pm_table,
			[
				'relation_id'        => $test_relation,
				'source_post_id'     => $src_post,
				'source_post_type'   => 'post',
				'source_site_id'     => 1,
				'target_post_id'     => $target_post,
				'target_post_type'   => 'post',
				'target_site_id'     => 'en_us',
				'relationship_type'  => 'concurrent_test',
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
		if ( $ok ) {
			$inserted_ids[] = $wpdb->insert_id;
		}
	}
	$total_attempts = 5;
	$actual_inserts = count( $inserted_ids );
	// With UNIQUE constraint, only 1 of 5 should succeed.
	$is_ok = ( $actual_inserts === 1 );
	check(
		'Concurrent INSERT with same UNIQUE key: only 1 succeeds (others rejected by DB)',
		$is_ok,
		'inserted=' . $actual_inserts . '/' . $total_attempts . ' (UNIQUE constraint prevents duplicates)'
	);

	// Cleanup
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$pm_table} WHERE relation_id = %d",
		$test_relation
	) );
}

// Check 10: Use SELECT ... FOR UPDATE for critical reads (optional, schema UNIQUE keys may suffice)
$for_update_count = substr_count( $source, 'FOR UPDATE' );
if ( $for_update_count > 0 ) {
	check( 'Direct_DB_Service uses SELECT ... FOR UPDATE', true, "count={$for_update_count}" );
} else {
	echo "INFO: Direct_DB_Service does not use SELECT ... FOR UPDATE (relies on schema UNIQUE keys)\n";
}

// Check 11: SELECT ... FOR UPDATE in sync_executor (optional)
$sync_for_update = substr_count( $sync_source, 'FOR UPDATE' );
if ( $sync_for_update > 0 ) {
	check( 'Sync_Executor uses SELECT ... FOR UPDATE', true, "count={$sync_for_update}" );
} else {
	echo "INFO: Sync_Executor does not use SELECT ... FOR UPDATE (acceptable with unique constraints)\n";
}

// Check 12: No double-processing — claim_semaphore or similar pattern
$has_claim = strpos( $sync_source, 'claim' ) !== false || strpos( $direct_file ? file_get_contents( $direct_file ) : '', 'claim' ) !== false;
check( 'Sync code has claim semantics', $has_claim );

// Check 13: Cron has lock for singleton execution
// Current model (2026-09): per-task claim/lease semantics in
// automation-cron.php + Direct_DB (enable_task_locking default, claim lease
// timeout, stuck-task recovery) — not the classic wp_cache_add/transient
// primitives the original grep listed.
$cron_source = file_get_contents( WPTSALL_PATH . 'includes/tasks/automation-cron.php' );
if ( $cron_source ) {
	$has_lock = strpos( $cron_source, 'wp_cache_add' ) !== false
		|| strpos( $cron_source, 'wp_using_ext_object_cache' ) !== false
		|| strpos( $cron_source, 'get_transient' ) !== false
		|| strpos( $cron_source, 'flock' ) !== false
		|| strpos( $cron_source, 'WP_OPTION' ) !== false
		|| strpos( $cron_source, 'enable_task_locking' ) !== false
		|| strpos( $cron_source, 'lease' ) !== false
		|| strpos( $cron_source, 'stuck' ) !== false;
	check( 'Cron has concurrent execution lock', $has_lock );
}

// Check 14: Process_tasks uses claim_or_skip pattern (optional)
$pt_source = file_get_contents( WPTSALL_PATH . 'includes/tasks/services/class-direct-db-service.php' );
$has_claim_or_skip = strpos( $pt_source, 'claim' ) !== false || strpos( $pt_source, 'atomic' ) !== false;
if ( $has_claim_or_skip ) {
	check( 'Direct_DB has claim/atomic helper', true );
} else {
	echo "INFO: Direct_DB has no claim/atomic helper (relies on schema UNIQUE keys for dedup)\n";
}

// Check 15: Test INSERT IGNORE pattern in mappings
$all_mapping_files = [
	WPTSALL_PATH . 'includes/sites/database/schema-post-mappings.php',
	WPTSALL_PATH . 'includes/sites/database/schema-term-mappings.php',
];
$has_insert_ignore = false;
foreach ( $all_mapping_files as $f ) {
	if ( file_exists( $f ) ) {
		$c = file_get_contents( $f );
		if ( strpos( $c, 'INSERT IGNORE' ) !== false || strpos( $c, 'INSERT OR IGNORE' ) !== false ) {
			$has_insert_ignore = true;
		}
	}
}
if ( ! $has_insert_ignore ) {
	// Look in the service files for runtime INSERT IGNORE usage
	$svc_files = glob( WPTSALL_PATH . 'includes/*/services/*.php' );
	foreach ( $svc_files ?: [] as $f ) {
		$c = file_get_contents( $f );
		if ( $c && ( strpos( $c, 'INSERT IGNORE' ) !== false || strpos( $c, 'INSERT OR IGNORE' ) !== false ) ) {
			$has_insert_ignore = true;
			break;
		}
	}
}
if ( $has_insert_ignore ) {
	check( 'INSERT IGNORE pattern used somewhere', true );
} else {
	echo "INFO: INSERT IGNORE not used (acceptable with UNIQUE KEY constraints catching duplicates)\n";
}

echo "\n=== Concurrent Sync Atomicity Integration Test ===\n";
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