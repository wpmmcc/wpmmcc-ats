<?php
/**
 * Bootstrap: Run Plan A Seeding
 *
 * Executes the content seeding plan (Plan A, seed phase) using WP-CLI, then
 * verifies that expected minimum data counts are present.
 *
 * Verification checks:
 *   - wp_posts WHERE post_type='product'  >= 3  (WooCommerce products)
 *   - wp_posts WHERE post_type='topic'    >= 1  (bbPress topics)
 *
 * If counts are 0 after seeding, a WARNING is printed (plugins may not be active)
 * but no fatal error is thrown so subsequent tests can still run.
 *
 * Safe to run multiple times; seeding scripts are expected to be additive.
 *
 * @package WPTSALL\DevTools\Bootstrap
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

global $wpdb;

echo "=== run-seeding.php ===\n";
echo "Running seeding workflow...\n\n";

// ---------------------------------------------------------------------------
// 1. Locate seeding directory
// ---------------------------------------------------------------------------

// __DIR__ is wpmmcc-ats/tests/integration/bootstrap; seeding is wpmmcc-ats/tests/seeding.
$seeding_dir = dirname( __DIR__, 2 ) . '/seeding';

if ( ! is_dir( $seeding_dir ) ) {
	echo "ERROR: Seeding directory not found: {$seeding_dir}\n";
	echo "=== run-seeding.php FAILED ===\n\n";
	return;
}

$run_plan = $seeding_dir . '/run-plan.php';
if ( ! file_exists( $run_plan ) ) {
	echo "ERROR: run-plan.php not found at: {$run_plan}\n";
	echo "=== run-seeding.php FAILED ===\n\n";
	return;
}

echo "Seeding directory : {$seeding_dir}\n";
echo "Run-plan script   : {$run_plan}\n\n";

// ---------------------------------------------------------------------------
// 2. Execute seeding via WP-CLI
// ---------------------------------------------------------------------------

// Build the WP-CLI command.  We run from the WordPress root so that WP-CLI
// can locate wp-config.php without extra flags.
$wp_root    = '/usr/local/var/www';
$wp_cli_bin = trim( shell_exec( 'which wp 2>/dev/null' ) );
if ( empty( $wp_cli_bin ) ) {
	$wp_cli_bin = '/usr/local/bin/wp';
}

// Default strategy: run full lifecycle (pre + seed + post + verify) so that
// fixtures are in a consistent state before integration tests.
$plan_id    = getenv( 'WPTSALL_SEED_PLAN' );
$plan_stage = getenv( 'WPTSALL_SEED_STAGE' );
if ( empty( $plan_id ) ) {
	$plan_id = 'A';
}
if ( empty( $plan_stage ) ) {
	$plan_stage = 'full';
}

$command = sprintf(
	'cd %s && %s eval-file %s %s %s 2>&1',
	escapeshellarg( $wp_root ),
	escapeshellarg( $wp_cli_bin ),
	escapeshellarg( $run_plan ),
	escapeshellarg( $plan_id ),
	escapeshellarg( $plan_stage )
);

echo "Executing: wp eval-file run-plan.php {$plan_id} {$plan_stage}\n";
echo str_repeat( '-', 40 ) . "\n";

$output      = array();
$exit_status = 0;
exec( $command, $output, $exit_status );

foreach ( $output as $line ) {
	echo $line . "\n";
}
echo str_repeat( '-', 40 ) . "\n";

if ( 0 !== $exit_status ) {
	echo "WARNING: WP-CLI exited with status {$exit_status}. Seeding may have partially failed.\n";
} else {
	echo "Seeding command completed successfully.\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 3. Verify minimum data counts
// ---------------------------------------------------------------------------

echo "Verifying seeded data...\n";

$checks = array(
	array(
		'label'     => "Products (post_type='product')",
		'post_type' => 'product',
		'min'       => 3,
	),
	array(
		'label'     => "bbPress Topics (post_type='topic')",
		'post_type' => 'topic',
		'min'       => 1,
	),
);

$all_ok = true;

foreach ( $checks as $check ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'auto-draft'",
			$check['post_type']
		)
	);

	if ( $count >= $check['min'] ) {
		echo "  OK    {$check['label']}: {$count} (min {$check['min']})\n";
	} elseif ( $count > 0 ) {
		echo "  WARN  {$check['label']}: {$count} (expected >= {$check['min']})\n";
		$all_ok = false;
	} else {
		echo "  WARNING: {$check['label']}: 0 rows found.\n";
		echo "           The required plugin (e.g. WooCommerce, bbPress) may not be active.\n";
		echo "           Subsequent tests that depend on this data may fail.\n";
		$all_ok = false;
	}
}

echo "\n";

// ---------------------------------------------------------------------------
// 4. Summary
// ---------------------------------------------------------------------------

if ( $all_ok ) {
	echo "Seeding verification PASSED.\n";
} else {
	echo "Seeding verification WARNINGS detected (see above). Tests may still run.\n";
}

echo "=== run-seeding.php DONE ===\n\n";
