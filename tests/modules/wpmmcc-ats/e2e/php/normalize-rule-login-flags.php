<?php
/**
 * Normalize translation rule requires_login flags from URL audit report.
 *
 * Heuristic goals:
 * - Keep truly gated/internal objects as requires_login=1.
 * - Mark clearly public-facing URLs as requires_login=0.
 * - Avoid plugin-specific hardcoding by relying on audit classification,
 *   runtime accessibility, and object visibility flags.
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/normalize-rule-login-flags.php
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$rules_table = e2e_table( 'translation_rules' );
if ( ! e2e_table_exists( $rules_table ) ) {
	echo "ERROR: table not found: {$rules_table}\n";
	exit( 1 );
}

$audit_file = e2e_runtime_file( 'url-audit.json' );
if ( ! file_exists( $audit_file ) ) {
	echo "ERROR: url-audit.json not found. Run audit-rule-urls.php first.\n";
	exit( 1 );
}

$payload = json_decode( file_get_contents( $audit_file ), true );
if ( ! is_array( $payload ) || empty( $payload['rules'] ) || ! is_array( $payload['rules'] ) ) {
	echo "ERROR: invalid url-audit.json payload.\n";
	exit( 1 );
}

/**
 * Check HTTP success (2xx/3xx).
 */
function e2e_norm_http_ok( int $code ): bool {
	return $code >= 200 && $code < 400;
}

/**
 * Pretty URL likely user-facing path (not query-only URL).
 */
function e2e_norm_is_front_path_url( string $url ): bool {
	if ( '' === $url ) {
		return false;
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return false;
	}

	$path  = (string) ( $parts['path'] ?? '' );
	$query = (string) ( $parts['query'] ?? '' );

	// Path-based URL (e.g. /post/slug/) is front-facing.
	if ( '' !== $path && '/' !== $path ) {
		return true;
	}

	// Query-only URLs are usually internal/query var entrypoints.
	if ( '' !== $query ) {
		return false;
	}

	return false;
}

/**
 * Compute recommended requires_login.
 */
function e2e_norm_recommend_requires_login( array $entry ): int {
	$current        = (int) ( $entry['requires_login'] ?? 0 );
	$classification = (string) ( $entry['classification'] ?? '' );
	$pretty_url     = (string) ( $entry['urls']['pretty'] ?? '' );
	$pretty_code    = (int) ( $entry['http']['pretty']['code'] ?? 0 );
	$canonical_code = (int) ( $entry['http']['canonical']['code'] ?? 0 );

	$object_flags       = is_array( $entry['object_flags'] ?? null ) ? $entry['object_flags'] : array();
	$public             = (int) ( $object_flags['public'] ?? 0 );
	$publicly_queryable = (int) ( $object_flags['publicly_queryable'] ?? 0 );

	$pretty_ok       = e2e_norm_http_ok( $pretty_code );
	$canonical_ok    = e2e_norm_http_ok( $canonical_code );
	$front_path_url  = e2e_norm_is_front_path_url( $pretty_url );
	$front_public_ok = $pretty_ok && $front_path_url;

	if ( in_array( $classification, array( 'expected_gated', 'likely_gated_needs_rule_flag' ), true ) ) {
		return 1;
	}

	// If a path-based public URL is accessible anonymously, treat as public-facing.
	if ( $front_public_ok ) {
		return 0;
	}

	// Non-queryable non-public objects are internal by default.
	if ( 0 === $public && 0 === $publicly_queryable ) {
		return 1;
	}

	// If canonical and pretty both fail, keep current to avoid blind flips.
	if ( ! $pretty_ok && ! $canonical_ok ) {
		return $current;
	}

	// For explicit public classifications, default to public.
	if ( in_array( $classification, array( 'public_ok', 'public_ok_pretty_only' ), true ) ) {
		return 0;
	}

	return $current;
}

echo "=== E2E v2: Normalize Rule Login Flags ===\n\n";

$updated  = 0;
$checked  = 0;
$changes  = array();
$now      = current_time( 'mysql' );

foreach ( (array) $payload['rules'] as $entry ) {
	$rule_id = (int) ( $entry['id'] ?? 0 );
	if ( $rule_id <= 0 ) {
		continue;
	}
	$checked++;

	$current = (int) ( $entry['requires_login'] ?? 0 );
	$target  = e2e_norm_recommend_requires_login( (array) $entry );

	if ( $target === $current ) {
		continue;
	}

	$ok = $wpdb->update(
		$rules_table,
		array(
			'requires_login' => $target,
			'updated_at'     => $now,
		),
		array( 'id' => $rule_id ),
		array( '%d', '%s' ),
		array( '%d' )
	);

	if ( false === $ok ) {
		$changes[] = array(
			'id'            => $rule_id,
			'object_name'   => (string) ( $entry['object_name'] ?? '' ),
			'from'          => $current,
			'to'            => $target,
			'classification'=> (string) ( $entry['classification'] ?? '' ),
			'status'        => 'failed',
		);
		continue;
	}

	$updated++;
	$changes[] = array(
		'id'            => $rule_id,
		'object_name'   => (string) ( $entry['object_name'] ?? '' ),
		'from'          => $current,
		'to'            => $target,
		'classification'=> (string) ( $entry['classification'] ?? '' ),
		'status'        => 'updated',
	);
}

$summary = array(
	'checked' => $checked,
	'updated' => $updated,
	'changes' => $changes,
);

echo wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";

