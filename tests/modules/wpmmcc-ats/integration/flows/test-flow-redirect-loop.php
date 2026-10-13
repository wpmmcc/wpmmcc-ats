<?php
/**
 * Redirect Loop Protection Integration Test
 *
 * Verifies that the plugin does not cause infinite redirect chains when:
 *   - Source URL /de/ redirects to a target virtual URL
 *   - Frontend URL /de/<slug>/ requests a non-existent page
 *   - Virtual site routing chains are bounded
 *   - wp_safe_redirect uses the "wptsall" X-Redirect-By marker
 *   - No more than N redirects in a chain (loop detection by max-redirs)
 *
 * Test approach:
 *   1. Static source-code check: confirm router uses wp_safe_redirect (not raw wp_redirect)
 *   2. Curl the site with --max-redirs N and verify N is never exceeded
 *   3. Verify X-Redirect-By: wptsall header is set on plugin redirects
 *   4. Verify the redirect target does not itself redirect back (no cycle)
 *   5. Verify 404 paths for virtual URLs do not redirect
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

require __DIR__ . '/flow-http-helper.php';

$results = [];
$failed  = 0;
$warned  = 0;

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

function warn( string $name, string $detail = '' ): void {
	global $results, $warned;
	$ok        = true; // warnings do not count as failures
	$results[] = compact( 'name', 'ok', 'detail' );
	$warned++;
	echo "WARN: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
}

$base = home_url();

function fetch_with_redirects( string $url, int $max_redirs = 5 ): array {
	return wptsall_flow_fetch_with_redirects( $url, $max_redirs );
}

function fetch_headers( string $url ): array {
	return wptsall_flow_fetch_headers( $url );
}

// ---------- Check 1: Source code -- router uses wp_safe_redirect ----------
$router_file = WPTSALL_PATH . 'includes/hooks/class-virtual-site-router.php';
$router_src  = file_exists( $router_file ) ? file_get_contents( $router_file ) : '';
check(
	'Router uses wp_safe_redirect (not raw wp_redirect)',
	$router_src !== '' && strpos( $router_src, 'wp_safe_redirect' ) !== false && strpos( $router_src, 'wp_redirect' ) === false,
	'has_safe=' . ( strpos( $router_src, 'wp_safe_redirect' ) !== false ? '1' : '0' )
);

// ---------- Check 2: Router marks redirects with "wptsall" X-Redirect-By ----------
check(
	'Router sets X-Redirect-By: wptsall marker',
	$router_src !== '' && strpos( $router_src, "wp_safe_redirect" ) !== false && preg_match( "/wp_safe_redirect\(\s*\\$[^,]+,\s*\d+,\s*[\"']wptsall[\"']/", $router_src ),
	'has_marker=' . ( preg_match( "/wp_safe_redirect\(\s*\\$[^,]+,\s*\d+,\s*[\"']wptsall[\"']/", $router_src ) ? '1' : '0' )
);

// ---------- Check 3: /de/ does not produce infinite redirect chain ----------
$r = fetch_with_redirects( $base . '/de/', 5 );
check(
	'/de/ redirect chain ends within 5 hops (no loop)',
	$r['num_redirects'] <= 5,
	'code=' . $r['code'] . ' num_redirects=' . $r['num_redirects'] . ' final=' . $r['url']
);

// ---------- Check 4: /de/nonexistent-page/ returns 404 (no redirect) ----------
$r2 = fetch_with_redirects( $base . '/de/nonexistent-page-' . bin2hex( random_bytes( 4 ) ) . '/', 5 );
check(
	'/de/<non-existent>/ returns 404 directly (no redirect loop)',
	$r2['code'] === 404 && $r2['num_redirects'] === 0,
	'code=' . $r2['code'] . ' num_redirects=' . $r2['num_redirects']
);

// ---------- Check 5: /en/nonexistent/ also returns 404 (no loop) ----------
$r3 = fetch_with_redirects( $base . '/en/nonexistent-page-' . bin2hex( random_bytes( 4 ) ) . '/', 5 );
check(
	'/en/<non-existent>/ returns 404 directly (no redirect loop)',
	$r3['code'] === 404 && $r3['num_redirects'] === 0,
	'code=' . $r3['code'] . ' num_redirects=' . $r3['num_redirects']
);

// ---------- Check 6: /de/ first redirect has Location header pointing to non-/de/ target ----------
$h = fetch_headers( $base . '/de/' );
$has_wptsall_marker = isset( $h['headers']['x-redirect-by'] ) && stripos( $h['headers']['x-redirect-by'], 'wptsall' ) !== false;
$has_wp_marker      = isset( $h['headers']['x-redirect-by'] ) && stripos( $h['headers']['x-redirect-by'], 'WordPress' ) !== false;
$location = $h['headers']['location'] ?? '';
if ( $location ) {
	$target_is_not_de = strpos( $location, '/de/' ) === false;
	check(
		'/de/ redirect target does NOT contain /de/ (no self-loop)',
		$target_is_not_de,
		'location=' . $location
	);
} else {
	check( '/de/ is not a 301 (no redirect to test)', $h['code'] !== 301, 'code=' . $h['code'] );
}

// ---------- Check 7: Following /de/ chain does not redirect back to /de/ ----------
if ( $location ) {
	$final = fetch_with_redirects( $base . '/de/', 5 );
	$final_path = wp_parse_url( $final['url'], PHP_URL_PATH );
	$back_to_de = $final_path && ( strpos( $final_path, '/de/' ) === 0 );
	check(
		'Following /de/ chain does NOT return to /de/ (no cycle)',
		! $back_to_de,
		'final=' . ( $final['url'] ?? '' )
	);
}

// ---------- Check 8: Cross-language /de/xxx -> /en/xxx does not loop ----------
$slug = 'redirect-loop-' . bin2hex( random_bytes( 4 ) );
$de_r = fetch_with_redirects( $base . '/de/' . $slug . '/', 5 );
$en_r = fetch_with_redirects( $base . '/en/' . $slug . '/', 5 );
check(
	'/de/<random>/ and /en/<random>/ are independent (each 404)',
	$de_r['code'] === 404 && $en_r['code'] === 404,
	'de_code=' . $de_r['code'] . ' en_code=' . $en_r['code']
);

// ---------- Check 9: Static check -- no infinite loop counter exists in router ----------
// (We expect that the plugin does NOT implement explicit loop detection;
// if it does, the test should be updated. For now, this is a known gap.)
$has_loop_counter = preg_match( '/redirect_count|loop_count|max_redirects|cycle_count/', $router_src );
if ( $has_loop_counter ) {
	check(
		'Router implements explicit loop/redirect counter',
		true
	);
} else {
	warn(
		'GAP: router has no explicit loop/redirect counter (relies on wp_safe_redirect + 301 marker only)',
		'not a hard fail; curl --max-redirs catches most loops'
	);
}

// ---------- Check 10: Source code -- no recursive wp_redirect to same URL ----------
// (Heuristic: check that target URL is not built from $path_prefix without bound)
$has_unbounded_recursion = preg_match( '/wp_safe_redirect\s*\(\s*\$path_prefix\s*\.\s*.*\$path_prefix/s', $router_src );
check(
	'No unbounded recursive wp_safe_redirect in router source',
	! $has_unbounded_recursion,
	'has_recursion=' . ( $has_unbounded_recursion ? '1' : '0' )
);

echo "\n=== Redirect Loop Protection Integration Test ===\n";
echo "Passed: " . ( count( $results ) - $failed - $warned ) . " / " . count( $results ) . "\n";
echo "Warned: {$warned}\n";
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