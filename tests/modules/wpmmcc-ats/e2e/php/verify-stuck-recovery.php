<?php
/**
 * Canonical stuck-task recovery verifier wrapper.
 *
 * Historical verifier lived under wpmmcc-ats/testing/. When absent, run a
 * lightweight stuck-task health probe against the current tasks table.
 */

require_once __DIR__ . '/helpers.php';

$legacy_candidates = array(
	dirname( __DIR__, 3 ) . '/testing/verify-stuck-recovery.php',
	dirname( __DIR__, 4 ) . '/testing/verify-stuck-recovery.php',
	dirname( __DIR__, 2 ) . '/legacy/verify-stuck-recovery.php',
);

foreach ( $legacy_candidates as $legacy_script ) {
	if ( is_string( $legacy_script ) && file_exists( $legacy_script ) ) {
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['table'] = $GLOBALS['wpdb']->prefix . 'wptsall_tasks';
		}
		if ( function_exists( 'current_time' ) ) {
			$GLOBALS['now'] = current_time( 'mysql' );
		}
		if ( ! isset( $GLOBALS['test_ids'] ) ) {
			$GLOBALS['test_ids'] = array();
		}
		require $legacy_script;
		return;
	}
}

echo "=== E2E v2: Stuck-task Recovery Health Probe ===\n\n";

global $wpdb;

$checks = array();
$passed = 0;
$failed = 0;

$tasks_table = e2e_table( 'tasks' );
if ( ! e2e_table_exists( $tasks_table ) ) {
	e2e_check( 'Tasks table exists', false, "missing {$tasks_table}", $checks, $passed, $failed );
	e2e_print_results( $checks, $passed, $failed );
	exit( 1 );
}

// Count long-running processing tasks as a stuck signal ( > 2 hours ).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$stuck_processing = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$tasks_table}
		 WHERE status IN ('processing', 'running')
		   AND updated_at < %s",
		gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS )
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$error_tasks = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$tasks_table} WHERE status IN ('error', 'failed')"
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$retry_tasks = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$tasks_table} WHERE status = 'retry'"
);

e2e_check(
	'No long-stuck processing tasks',
	0 === $stuck_processing,
	"stuck_processing={$stuck_processing}",
	$checks,
	$passed,
	$failed
);

// Error/retry presence is informational for core-only unless catastrophic.
e2e_check(
	'Error task volume is bounded',
	$error_tasks < 50,
	"error_tasks={$error_tasks}",
	$checks,
	$passed,
	$failed
);

e2e_check(
	'Retry task volume is bounded',
	$retry_tasks < 100,
	"retry_tasks={$retry_tasks}",
	$checks,
	$passed,
	$failed
);

e2e_print_results( $checks, $passed, $failed );
exit( $failed > 0 ? 1 : 0 );
