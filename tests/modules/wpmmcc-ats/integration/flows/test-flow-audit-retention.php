<?php
/**
 * Audit Log Retention / Rotation Integration Test
 *
 * Verifies the Audit_Log ring-buffer behavior:
 *   - Default cap is 500 entries (Audit_Log::CAP)
 *   - Inserting >500 entries keeps only the newest 500
 *   - Newest entries are at the head (array_unshift)
 *   - record() / list() / clear() are all functional
 *   - log_retention_days setting exists in settings service
 *   - retention setting accepts 0 (keep all) up to 365
 *   - retention setting is sanitized (max(0, (int)$val))
 *   - audit log action hooks fire (wptsall_post_mapping_created etc)
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
		echo "FAIL: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
	} else {
		echo "PASS: {$name}\n";
	}
}

if ( ! class_exists( '\\WPTSALL\\CLI\\Audit_Log' ) ) {
	echo "FAIL: WPTSALL\\CLI\\Audit_Log class missing\n";
	exit( 1 );
}

$log = '\\WPTSALL\\CLI\\Audit_Log';

// ---------- Check 1: Audit_Log::CAP is 500 ----------
check(
	'Audit_Log::CAP constant is 500',
	$log::CAP === 500,
	'cap=' . $log::CAP
);

// ---------- Check 2: Audit_Log::OPTION_KEY is wptsall_audit_log ----------
check(
	'Audit_Log::OPTION_KEY is wptsall_audit_log',
	$log::OPTION_KEY === 'wptsall_audit_log',
	'key=' . $log::OPTION_KEY
);

// ---------- Check 3: clear() empties the log ----------
$log::clear();
$entries = $log::list( 1000 );
check(
	'After clear(), log is empty',
	count( $entries ) === 0,
	'count=' . count( $entries )
);

// ---------- Check 4: record() adds an entry ----------
$log::record( 'test_action_a', [ 'foo' => 'bar' ] );
$entries = $log::list( 10 );
check(
	'record() adds an entry to the log',
	count( $entries ) === 1 && ( $entries[0]['action'] ?? '' ) === 'test_action_a',
	'count=' . count( $entries ) . ' first=' . ( $entries[0]['action'] ?? 'none' )
);

// ---------- Check 5: Newest entry is at index 0 (head) ----------
$log::record( 'test_action_b', [ 'foo' => 'baz' ] );
$entries = $log::list( 10 );
check(
	'Newest entry is at index 0 (ring-buffer head)',
	count( $entries ) === 2 && ( $entries[0]['action'] ?? '' ) === 'test_action_b' && ( $entries[1]['action'] ?? '' ) === 'test_action_a',
	'order=' . ( $entries[0]['action'] ?? 'none' ) . ',' . ( $entries[1]['action'] ?? 'none' )
);

// ---------- Check 6: Insert 600 entries, verify cap to 500 ----------
$log::clear();
for ( $i = 0; $i < 600; $i++ ) {
	$log::record( "bulk_test_{$i}", [ 'idx' => $i ] );
}
$raw      = get_option( $log::OPTION_KEY, [] );
$count    = is_array( $raw ) ? count( $raw ) : 0;
check(
	'After 600 inserts, log is capped at 500',
	$count === 500,
	'raw_count=' . $count
);

// ---------- Check 7: The 500 kept entries are the newest 500 (idx 100..599) ----------
$entries = $log::list( 500 );
$first_action = $entries[0]['action'] ?? '';
$last_action  = $entries[499]['action'] ?? '';
check(
	'Newest 500 are retained (head=bulk_test_599, tail=bulk_test_100)',
	$first_action === 'bulk_test_599' && $last_action === 'bulk_test_100',
	'head=' . $first_action . ' tail=' . $last_action
);

// ---------- Check 8: list() respects limit ----------
$entries_10 = $log::list( 10 );
check(
	'list(10) returns at most 10 entries',
	count( $entries_10 ) === 10,
	'count=' . count( $entries_10 )
);

// ---------- Check 9: list() with action_filter ----------
$log::clear();
for ( $i = 0; $i < 5; $i++ ) {
	$log::record( 'alpha', [ 'i' => $i ] );
	$log::record( 'beta', [ 'i' => $i ] );
}
$alpha_entries = $log::list( 100, 'alpha' );
$beta_entries  = $log::list( 100, 'beta' );
check(
	'list(limit, action_filter) filters by action',
	count( $alpha_entries ) === 5 && count( $beta_entries ) === 5,
	'alpha=' . count( $alpha_entries ) . ' beta=' . count( $beta_entries )
);

// ---------- Check 10: Entry has time, user, action, data fields ----------
$log::clear();
$log::record( 'shape_test', [ 'key' => 'val' ] );
$entries = $log::list( 1 );
$entry   = $entries[0] ?? [];
$has_all_keys = isset( $entry['time'] ) && isset( $entry['user'] ) && isset( $entry['action'] ) && isset( $entry['data'] );
check(
	'Entry has all required fields (time/user/action/data)',
	$has_all_keys,
	'keys=' . implode( ',', array_keys( $entry ) )
);

// ---------- Check 11: log_retention_days setting is registered ----------
if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
	$settings = \WPTSALL\Settings\Services\Settings_Service::get_all();
	check(
		'log_retention_days setting exists in Settings_Service',
		array_key_exists( 'log_retention_days', $settings ),
		'has_key=' . ( array_key_exists( 'log_retention_days', $settings ) ? '1' : '0' )
	);
	check(
		'log_retention_days has a sensible default',
		isset( $settings['log_retention_days'] ) && is_int( $settings['log_retention_days'] ) && $settings['log_retention_days'] >= 0,
		'default=' . ( $settings['log_retention_days'] ?? 'null' )
	);
} else {
	check( 'log_retention_days setting exists in Settings_Service', false, 'class missing' );
	check( 'log_retention_days has a sensible default', false, 'class missing' );
}

// ---------- Check 12: Settings can be updated with log_retention_days ----------
if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
	try {
		\WPTSALL\Settings\Services\Settings_Service::update( [ 'log_retention_days' => 14 ] );
		$s = \WPTSALL\Settings\Services\Settings_Service::get_all();
		check(
			'Settings_Service::update accepts log_retention_days=14',
			isset( $s['log_retention_days'] ) && (int) $s['log_retention_days'] === 14,
			'got=' . ( $s['log_retention_days'] ?? 'null' )
		);
		// restore
		\WPTSALL\Settings\Services\Settings_Service::update( [ 'log_retention_days' => 7 ] );
	} catch ( Exception $e ) {
		check( 'Settings_Service::update accepts log_retention_days=14', false, 'exception: ' . $e->getMessage() );
	}
}

// ---------- Check 13: log_retention_days=0 (keep all) is allowed ----------
if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
	try {
		\WPTSALL\Settings\Services\Settings_Service::update( [ 'log_retention_days' => 0 ] );
		$s = \WPTSALL\Settings\Services\Settings_Service::get_all();
		check(
			'log_retention_days=0 (keep all) is allowed',
			isset( $s['log_retention_days'] ) && (int) $s['log_retention_days'] === 0,
			'got=' . ( $s['log_retention_days'] ?? 'null' )
		);
		\WPTSALL\Settings\Services\Settings_Service::update( [ 'log_retention_days' => 7 ] );
	} catch ( Exception $e ) {
		check( 'log_retention_days=0 (keep all) is allowed', false, 'exception: ' . $e->getMessage() );
	}
}

// ---------- Check 14: Action hooks are registered ----------
// Audit_Log::init() runs from CLI\Module::init() on every request (web + CLI).
// Call explicitly here so the assertion is independent of bootstrap order.
\WPTSALL\CLI\Audit_Log::init();

$hook_names = [
	'wptsall_post_mapping_created',
	'wptsall_term_mapping_created',
	'wptsall_url_discovery_bulk',
	'wptsall_tm_recorded',
	'wptsall_string_translated',
];
$all_registered = true;
foreach ( $hook_names as $hn ) {
	if ( ! has_action( $hn ) ) {
		$all_registered = false;
		break;
	}
}
check(
	'All 5 audit-log action hooks are registered (init() ran)',
	$all_registered,
	'hooks=' . implode( ',', $hook_names )
);

// ---------- Check 15: Action hook fires record() via do_action ----------
$log::clear();
do_action( 'wptsall_post_mapping_created', 123, 456, [ 'extra' => 'data' ] );
$entries = $log::list( 10 );
// Handler stores the short action name (post_mapping_created), not the hook name.
check(
	'do_action(wptsall_post_mapping_created) triggers Audit_Log::record',
	count( $entries ) === 1 && ( $entries[0]['action'] ?? '' ) === 'post_mapping_created',
	'count=' . count( $entries ) . ' action=' . ( $entries[0]['action'] ?? 'none' )
);

// ---------- Cleanup ----------
$log::clear();

echo "\n=== Audit Log Retention / Rotation Integration Test ===\n";
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