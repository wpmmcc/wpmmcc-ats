<?php
/**
 * Flow: WP-CLI Commands (wp wptsall)
 *
 * Executes the real `wp` binary (WP-CLI 2.12) against the lab WordPress
 * and verifies:
 *   - bare `wp wptsall` lists all 7 command groups
 *   - read-only subcommands (translate progress, tm counts, strings counts,
 *     identity scan, audit list) exit 0 with the expected output markers
 *   - negative cases (unknown subcommand, missing required positional)
 *     exit non-zero with an error marker
 *
 * Only read-only subcommands are executed. The audit list checks seed one
 * audit entry through the in-process service API (never through a writing
 * CLI subcommand), and the original audit option value is restored at exit.
 *
 * @package WPTSALL\Tests\Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	$_SERVER['HTTP_HOST']   = 'localhost';
	$_SERVER['REQUEST_URI'] = '/';
	define( 'WP_USE_THEMES', false );
	require_once dirname( __DIR__ ) . '/bootstrap/load-wordpress.php';
}

$results = [];
$failed   = 0;

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
 * Run a wp-cli command and return exit code + stdout/stderr separately.
 *
 * @param array $cli_args Arguments after the `wptsall` namespace token.
 * @return array{exit:int,out:string,err:string}
 */
function wptsall_flow_cli_run( array $cli_args ): array {
	// WP root is layout-dependent: /var/www/html in the lab containers,
	// $WPTSALL_WP_ROOT (e.g. /tmp/wp) in host mode on the module-ci runner.
	// The 2026-09-09 runner run failed every check because the lab path was
	// hardcoded. Derive from the harness-defined WP_SITE_PATH.
	$wp_bin  = getenv( 'WPTSALL_WP_CLI_BIN' ) ?: '/usr/local/bin/wp';
	$wp_path = defined( 'WP_SITE_PATH' )
		? rtrim( (string) WP_SITE_PATH, '/' )
		: '/var/www/html';
	$parts = array( $wp_bin, 'wptsall' );
	foreach ( $cli_args as $arg ) {
		$parts[] = escapeshellarg( (string) $arg );
	}
	$parts[] = escapeshellarg( '--path=' . $wp_path );
	$parts[] = escapeshellarg( '--allow-root' );
	$cmd     = implode( ' ', $parts );

	$proc = proc_open(
		$cmd,
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$wp_path
	);
	if ( ! is_resource( $proc ) ) {
		return array( 'exit' => -1, 'out' => '', 'err' => 'proc_open failed' );
	}
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $proc );
	return array( 'exit' => $exit, 'out' => (string) $out, 'err' => (string) $err );
}

// ---------------------------------------------------------------------------
// Snapshot the audit option so the seeded entry can be restored at the end.
// ---------------------------------------------------------------------------
$audit_option_key    = 'wptsall_audit_log';
$audit_before        = get_option( $audit_option_key, array() );
$audit_seed_action   = 'flow_cli_seed_' . substr( (string) time(), -6 );
$audit_restore_later = true;

// ---------------------------------------------------------------------------
// Check 1: bare `wp wptsall` must list all 7 command groups (exit 0).
// ---------------------------------------------------------------------------
$bare = wptsall_flow_cli_run( array() );
check(
	'bare wp wptsall exits 0',
	0 === $bare['exit'],
	"exit={$bare['exit']} err=" . trim( $bare['err'] )
);
$groups = array( 'audit', 'identity', 'security', 'strings', 'tasks', 'tm', 'translate' );
foreach ( $groups as $group ) {
	check(
		"bare wp wptsall lists command group '{$group}'",
		false !== strpos( $bare['out'], $group ),
		'out=' . trim( $bare['out'] )
	);
}

// ---------------------------------------------------------------------------
// Check 2: read-only subcommand `translate progress`.
// ---------------------------------------------------------------------------
$progress = wptsall_flow_cli_run( array( 'translate', 'progress' ) );
check(
	'wp wptsall translate progress exits 0',
	0 === $progress['exit'],
	"exit={$progress['exit']} err=" . trim( $progress['err'] )
);
check(
	'translate progress outputs table markers (lang/total)',
	false !== strpos( $progress['out'], 'lang' ) && false !== strpos( $progress['out'], 'total' ),
	'out=' . trim( $progress['out'] )
);

// ---------------------------------------------------------------------------
// Check 3: read-only subcommand `tm counts`.
// ---------------------------------------------------------------------------
$tm_counts = wptsall_flow_cli_run( array( 'tm', 'counts' ) );
check(
	'wp wptsall tm counts exits 0',
	0 === $tm_counts['exit'],
	"exit={$tm_counts['exit']} err=" . trim( $tm_counts['err'] )
);
check(
	'tm counts outputs key/total markers',
	false !== strpos( $tm_counts['out'], 'key' ) && false !== strpos( $tm_counts['out'], 'total' ),
	'out=' . trim( $tm_counts['out'] )
);

// ---------------------------------------------------------------------------
// Check 4: read-only subcommand `strings counts`.
// ---------------------------------------------------------------------------
$str_counts = wptsall_flow_cli_run( array( 'strings', 'counts' ) );
check(
	'wp wptsall strings counts exits 0',
	0 === $str_counts['exit'],
	"exit={$str_counts['exit']} err=" . trim( $str_counts['err'] )
);
check(
	'strings counts outputs total/translated markers',
	false !== strpos( $str_counts['out'], 'total' ) && false !== strpos( $str_counts['out'], 'translated' ),
	'out=' . trim( $str_counts['out'] )
);

// ---------------------------------------------------------------------------
// Check 5: read-only subcommand `identity scan` (dry-run by design).
// ---------------------------------------------------------------------------
$identity = wptsall_flow_cli_run( array( 'identity', 'scan', '--limit=5' ) );
check(
	'wp wptsall identity scan (dry-run) exits 0',
	0 === $identity['exit'],
	"exit={$identity['exit']} err=" . trim( $identity['err'] )
);
check(
	'identity scan outputs scanned metric',
	false !== strpos( $identity['out'], 'scanned' ),
	'out=' . trim( $identity['out'] )
);

// ---------------------------------------------------------------------------
// Check 6: `audit list` with seeded data (seed via in-process service API).
// ---------------------------------------------------------------------------
if ( class_exists( 'WPTSALL\CLI\Audit_Log' ) ) {
	WPTSALL\CLI\Audit_Log::record( $audit_seed_action, array( 'from' => 'test-flow-cli-commands' ) );

	$audit_json = wptsall_flow_cli_run( array( 'audit', 'list', '--format=json', '--limit=10' ) );
	check(
		'wp wptsall audit list --format=json exits 0',
		0 === $audit_json['exit'],
		"exit={$audit_json['exit']} err=" . trim( $audit_json['err'] )
	);
	$audit_rows = json_decode( $audit_json['out'], true );
	$seed_found = is_array( $audit_rows ) ? false : null;
	if ( is_array( $audit_rows ) ) {
		foreach ( $audit_rows as $row ) {
			if ( ( $row['action'] ?? '' ) === $audit_seed_action ) {
				$seed_found = true;
				break;
			}
		}
	}
	check(
		'audit list --format=json returns seeded action as valid JSON',
		true === $seed_found,
		'out=' . trim( $audit_json['out'] )
	);

	$audit_filtered = wptsall_flow_cli_run( array( 'audit', 'list', '--format=json', "--action={$audit_seed_action}" ) );
	$filtered_rows  = json_decode( $audit_filtered['out'], true );
	$all_match      = is_array( $filtered_rows ) && ! empty( $filtered_rows );
	if ( $all_match ) {
		foreach ( $filtered_rows as $row ) {
			if ( ( $row['action'] ?? '' ) !== $audit_seed_action ) {
				$all_match = false;
				break;
			}
		}
	}
	check(
		'audit list --action=<seed> filters to only the seeded action',
		0 === $audit_filtered['exit'] && $all_match,
		"exit={$audit_filtered['exit']} out=" . trim( $audit_filtered['out'] )
	);
} else {
	check( 'WPTSALL\\CLI\\Audit_Log class is available for seeding', false, 'class not found; is the plugin active?' );
	$audit_restore_later = false;
}

// ---------------------------------------------------------------------------
// Check 7 (negative): unknown subcommand must exit non-zero with an error.
// ---------------------------------------------------------------------------
$unknown = wptsall_flow_cli_run( array( 'definitely-not-a-subcommand' ) );
check(
	'unknown subcommand exits non-zero',
	0 !== $unknown['exit'],
	"exit={$unknown['exit']}"
);
check(
	'unknown subcommand reports not-registered error',
	false !== strpos( $unknown['out'] . $unknown['err'], 'not a registered subcommand' ),
	'out=' . trim( $unknown['out'] ) . ' err=' . trim( $unknown['err'] )
);

// ---------------------------------------------------------------------------
// Check 8 (negative): translate pending without required <target_lang>.
// WP-CLI enforces the required positional at the dispatcher level, so the
// output is either WP-CLI's "usage: ... <target_lang>" error or the product's
// own "target language" error; both must exit non-zero.
// ---------------------------------------------------------------------------
$missing_arg = wptsall_flow_cli_run( array( 'translate', 'pending' ) );
$combined    = $missing_arg['out'] . $missing_arg['err'];
check(
	'translate pending without target_lang exits non-zero with target_lang error',
	0 !== $missing_arg['exit'] && ( false !== stripos( $combined, 'target_lang' ) || false !== stripos( $combined, 'target language' ) ),
	"exit={$missing_arg['exit']} out=" . trim( $missing_arg['out'] )
);

// ---------------------------------------------------------------------------
// Restore the audit option to its pre-flow state.
// ---------------------------------------------------------------------------
if ( $audit_restore_later ) {
	update_option( $audit_option_key, $audit_before, false );
}

echo "\n=== WP-CLI Commands Flow Test ===\n";
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
