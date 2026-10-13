<?php
/**
 * Flow: DB Migration Upgrade Chain
 *
 * §18 requires old-version → new-version upgrade chain verification, not
 * only fresh activation. This flow exercises includes/core/database/
 * migrations.php (wptsall_run_migrations) in three scenarios:
 *
 *   A. Idempotency: run migrations twice on a fully-upgraded install —
 *      no errors, db_version unchanged, seed data preserved, core tables
 *      complete.
 *   B. Simulated upgrade: rewrite wptsall_db_version to a lower legal
 *      historical value (1.2.0) and run migrations — db_version must rise
 *      back to the current target (WPTSALL_VERSION), tables/columns
 *      complete, seed data preserved.
 *   C. Schema self-heal (v1.2.0-style anchored ALTER): simulate the
 *      pre-change shape by dropping the later-added post_mappings columns
 *      (needs_resync/claimed_at + idx_resync, added by v1.2.0 migration
 *      wptsall_migrate_post_mappings_add_resync_columns), set db_version
 *      to 1.1.0, run migrations, and assert the columns are rebuilt while
 *      the seed row's data is preserved.
 *
 * All mutated state (db_version option, seed rows, dropped columns) is
 * restored before exit.
 *
 * @package WPTSALL\Tests\Integration\Flows
 * @since 2.1.0
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
		echo "FAIL: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
	} else {
		echo "PASS: {$name}\n";
	}
}

/**
 * Run wptsall_run_migrations() and return null on success or the error
 * message on failure. Also snapshots $wpdb->last_error afterwards.
 *
 * @return array{err: string|null, db_err: string} Result descriptor.
 */
function run_migrations_once(): array {
	global $wpdb;
	$err = null;
	try {
		wptsall_run_migrations();
	} catch ( Throwable $e ) {
		$err = $e->getMessage();
	}
	return array(
		'err'    => $err,
		'db_err' => (string) $wpdb->last_error,
	);
}

/**
 * Check a set of core wptsall tables exists.
 *
 * @param string $label Scenario label for check names.
 */
function check_core_tables( string $label ): void {
	global $wpdb;
	$core = array(
		'post_mappings',
		'term_mappings',
		'media_mappings',
		'site_relations',
		'tasks',
		'task_logs',
		'models',
		'virtual_sites',
		'templates',
		'plugin_mappings',
		'languages',
	);
	foreach ( $core as $key ) {
		$table = wptsall_table( $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		check(
			"{$label}: core table {$table} exists",
			$exists === $table,
			'found=' . var_export( $exists, true )
		);
	}
}

// ---------------------------------------------------------------------------
// Prerequisites
// ---------------------------------------------------------------------------
check(
	'wptsall_run_migrations() exists',
	function_exists( 'wptsall_run_migrations' )
);
check(
	'wptsall_migrate_post_mappings_add_resync_columns() exists (v1.2.0 migration)',
	function_exists( 'wptsall_migrate_post_mappings_add_resync_columns' )
);
check(
	'wptsall_db_alter_table() exists',
	function_exists( 'wptsall_db_alter_table' )
);

$original_db_version = get_option( 'wptsall_db_version', '' );
check(
	'wptsall_db_version option is set before flow',
	'' !== $original_db_version,
	'value=' . var_export( $original_db_version, true )
);

global $wpdb;
$tasks_table = wptsall_table( 'tasks' );
$pm_table    = wptsall_table( 'post_mappings' );

// Seed data (scenario A/B): one task row with a unique template.
$seed_template = 'migration-upgrade-flow-' . uniqid();
$now           = current_time( 'mysql' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->insert(
	$tasks_table,
	array(
		'blog_id'     => get_current_blog_id(),
		'site_id'     => 1,
		'template'    => $seed_template,
		'object_type' => 'post_type',
		'subtype'     => 'post',
		'object_id'   => 2147483001,
		'status'      => 'active',
		'created_at'  => $now,
		'updated_at'  => $now,
	)
);
$seed_task_id = (int) $wpdb->insert_id;
check(
	'seed task row inserted',
	$seed_task_id > 0,
	'last_error=' . $wpdb->last_error
);

// Seed data (scenario C): one post_mappings row with reserved unique values.
$seed_source_post_id = 2147483000 + wp_rand( 0, 99999 );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->insert(
	$pm_table,
	array(
		'relation_id'      => 999999, // Reserved: no relation with this id.
		'source_post_id'   => $seed_source_post_id,
		'source_post_type' => 'post',
		'source_site_id'   => get_current_blog_id(),
		'target_post_id'   => $seed_source_post_id,
		'target_post_type' => 'post',
		'target_site_id'   => get_current_blog_id(),
		'relationship_type' => 'translation',
		'created_at'       => $now,
		'updated_at'       => $now,
	)
);
$seed_pm_id = (int) $wpdb->insert_id;
check(
	'seed post_mappings row inserted',
	$seed_pm_id > 0,
	'last_error=' . $wpdb->last_error
);

// ===========================================================================
// Scenario A: idempotency — migrations run twice on current version.
// ===========================================================================
echo "\n--- Scenario A: idempotent double run ---\n";

$run1 = run_migrations_once();
$run2 = run_migrations_once();

check( 'Scenario A: migration run #1 no exception', null === $run1['err'], (string) $run1['err'] );
check( 'Scenario A: migration run #1 no DB error', '' === $run1['db_err'], 'last_error=' . $run1['db_err'] );
check( 'Scenario A: migration run #2 no exception', null === $run2['err'], (string) $run2['err'] );
check( 'Scenario A: migration run #2 no DB error', '' === $run2['db_err'], 'last_error=' . $run2['db_err'] );
check(
	'Scenario A: db_version unchanged after double run',
	get_option( 'wptsall_db_version' ) === $original_db_version,
	'now=' . var_export( get_option( 'wptsall_db_version' ), true )
);
check(
	'Scenario A: seed task row survived double run',
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tasks_table} WHERE id = %d", $seed_task_id ) ) === $seed_task_id // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
check_core_tables( 'Scenario A' );

// ===========================================================================
// Scenario B: simulated upgrade from db_version 1.2.0 → current.
// ===========================================================================
echo "\n--- Scenario B: simulated upgrade 1.2.0 -> current ---\n";

// migrations.php stamps db_version with WPTSALL_VERSION (fallback '2.1.0').
// Derive the expectation the same way instead of pinning a literal — the
// pinned '2.1.0' drifted stale the moment the plugin shipped 2.1.4 (found
// 2026-09-22 during the env-unit slot validation; same drift class the
// 2026-09 lifecycle audit hit in migrations.php itself).
$current_target = defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '2.1.0';

update_option( 'wptsall_db_version', '1.2.0' );
$run_b = run_migrations_once();

check( 'Scenario B: upgrade run no exception', null === $run_b['err'], (string) $run_b['err'] );
check( 'Scenario B: upgrade run no DB error', '' === $run_b['db_err'], 'last_error=' . $run_b['db_err'] );
check(
	'Scenario B: db_version raised back to current target ' . $current_target,
	get_option( 'wptsall_db_version' ) === $current_target,
	'now=' . var_export( get_option( 'wptsall_db_version' ), true )
);
check(
	'Scenario B: seed task row survived upgrade',
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tasks_table} WHERE id = %d", $seed_task_id ) ) === $seed_task_id // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
check_core_tables( 'Scenario B' );
$pm_cols_b = $wpdb->get_col( "SHOW COLUMNS FROM {$pm_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
check(
	'Scenario B: post_mappings columns complete after upgrade',
	in_array( 'needs_resync', $pm_cols_b, true ) && in_array( 'claimed_at', $pm_cols_b, true ) && in_array( 'claim_owner_hash', $pm_cols_b, true ),
	'columns=' . implode( ',', $pm_cols_b )
);

// ===========================================================================
// Scenario C: v1.2.0-style anchored ALTER self-heal.
//
// Precondition confirmation (done at runtime): the v1.2.0 migration
// (wptsall_migrate_post_mappings_add_resync_columns) rebuilds needs_resync
// + claimed_at + idx_resync when they are missing, anchored on the
// existing relationship_type column. We simulate the pre-v1.2.0 shape by
// dropping those columns, then run migrations from db_version 1.1.0.
// ===========================================================================
echo "\n--- Scenario C: column drop self-heal (v1.2.0 ALTER) ---\n";

$pm_cols_c0 = $wpdb->get_col( "SHOW COLUMNS FROM {$pm_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$scenario_c_runnable =
	in_array( 'needs_resync', $pm_cols_c0, true )
	&& in_array( 'claimed_at', $pm_cols_c0, true )
	&& in_array( 'relationship_type', $pm_cols_c0, true );

if ( ! $scenario_c_runnable ) {
	echo "SKIP: Scenario C preconditions not met (post_mappings shape unexpected)\n";
	echo '       columns=' . implode( ',', $pm_cols_c0 ) . "\n";
} else {
	// Drop the pre-change state: index first (single-column index on
	// needs_resync), then both v1.2.0 columns.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "ALTER TABLE {$pm_table} DROP INDEX idx_resync, DROP COLUMN needs_resync, DROP COLUMN claimed_at" );
	check(
		'Scenario C: pre-change shape simulated (columns dropped)',
		'' === $wpdb->last_error,
		'last_error=' . $wpdb->last_error
	);
	$pm_cols_c1 = $wpdb->get_col( "SHOW COLUMNS FROM {$pm_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	check(
		'Scenario C: needs_resync gone before migration',
		! in_array( 'needs_resync', $pm_cols_c1, true )
	);
	check(
		'Scenario C: claimed_at gone before migration',
		! in_array( 'claimed_at', $pm_cols_c1, true )
	);

	// Lower db_version below 1.2.0 so the upgrade chain re-runs v1.2.0.
	update_option( 'wptsall_db_version', '1.1.0' );
	$run_c = run_migrations_once();

	check( 'Scenario C: migration run no exception', null === $run_c['err'], (string) $run_c['err'] );
	check( 'Scenario C: migration run no DB error', '' === $run_c['db_err'], 'last_error=' . $run_c['db_err'] );

	$pm_cols_c2 = $wpdb->get_col( "SHOW COLUMNS FROM {$pm_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	check(
		'Scenario C: needs_resync rebuilt by migration',
		in_array( 'needs_resync', $pm_cols_c2, true )
	);
	check(
		'Scenario C: claimed_at rebuilt by migration',
		in_array( 'claimed_at', $pm_cols_c2, true )
	);
	$idx_resync = $wpdb->get_results( "SHOW INDEX FROM {$pm_table} WHERE Key_name = 'idx_resync'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	check(
		'Scenario C: idx_resync index rebuilt by migration',
		! empty( $idx_resync )
	);
	check(
		'Scenario C: seed post_mappings row survived column drop + self-heal',
		(int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pm_table} WHERE id = %d", $seed_pm_id ) ) === $seed_pm_id, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'last_error=' . $wpdb->last_error
	);
	$seed_rel_type = $wpdb->get_var( $wpdb->prepare( "SELECT relationship_type FROM {$pm_table} WHERE id = %d", $seed_pm_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	check(
		'Scenario C: seed row data intact (relationship_type preserved)',
		'translation' === $seed_rel_type,
		'got=' . var_export( $seed_rel_type, true )
	);
	check(
		'Scenario C: db_version raised back to current target ' . $current_target,
		get_option( 'wptsall_db_version' ) === $current_target,
		'now=' . var_export( get_option( 'wptsall_db_version' ), true )
	);

	// Fail-safe: if the self-heal did NOT restore the columns, leave
	// db_version at 1.1.0 so the next migration pass (admin_init) repairs
	// the schema instead of permanently stranding the install.
	if ( ! in_array( 'needs_resync', $pm_cols_c2, true ) || ! in_array( 'claimed_at', $pm_cols_c2, true ) ) {
		update_option( 'wptsall_db_version', '1.1.0' );
		echo "NOTE: db_version left at 1.1.0 for self-heal on next migration pass\n";
	}
}

// ===========================================================================
// Cleanup: restore every piece of state this flow touched.
// ===========================================================================
echo "\n--- Cleanup ---\n";

// Restore the db_version option we rewrote (scenarios B and C).
update_option( 'wptsall_db_version', $original_db_version );
check(
	'cleanup: db_version option restored',
	get_option( 'wptsall_db_version' ) === $original_db_version,
	'now=' . var_export( get_option( 'wptsall_db_version' ), true )
);

// Remove seed rows.
if ( $seed_task_id > 0 ) {
	$wpdb->delete( $tasks_table, array( 'id' => $seed_task_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
if ( $seed_pm_id > 0 ) {
	$wpdb->delete( $pm_table, array( 'id' => $seed_pm_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
check(
	'cleanup: seed task row removed',
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_table} WHERE id = %d", $seed_task_id ) ) === 0 // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
check(
	'cleanup: seed post_mappings row removed',
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pm_table} WHERE id = %d", $seed_pm_id ) ) === 0 // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$pm_cols_final = $wpdb->get_col( "SHOW COLUMNS FROM {$pm_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
check(
	'cleanup: post_mappings schema fully restored',
	in_array( 'needs_resync', $pm_cols_final, true ) && in_array( 'claimed_at', $pm_cols_final, true ),
	'columns=' . implode( ',', $pm_cols_final )
);

echo "\n=== DB Migration Upgrade Chain Flow ===\n";
echo 'Passed: ' . ( count( $results ) - $failed ) . ' / ' . count( $results ) . "\n";
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
