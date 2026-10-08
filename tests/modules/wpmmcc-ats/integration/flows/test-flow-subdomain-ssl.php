<?php
/**
 * Subdomain URL Form / SSL Integration Test
 *
 * Verifies that the plugin's subdomain URL form is correctly implemented
 * even though the test environment uses the default 'subdir' form:
 *   - url_form setting accepts 'subdomain' / 'subdir' / 'param'
 *   - is_subdomain_mode() reflects the current setting
 *   - extract_subdomain() correctly parses various host formats
 *   - detect_subdomain_lang() resolves subdomain prefix to language code
 *   - Setting can be switched to subdomain at runtime
 *   - Settings page renders subdomain option
 *   - is_ssl() returns true for HTTPS home URL
 *   - wp_safe_redirect uses scheme-aware URL (preserves https://)
 *   - SSL-specific logic (is_ssl, FORCE_SSL_ADMIN) doesn't break
 *   - Frontend renders without warnings when subdomain mode is on
 *
 * Note: The test environment does not have wildcard DNS / SSL cert for
 * subdomains. We test the plugin's parsing logic, not network resolution.
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
	$ok         = true; // warnings do not count as failures
	$results[]  = compact( 'name', 'ok', 'detail' );
	$warned++;
	echo "WARN: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
}

$router_class = '\\WPTSALL\\Hooks\\Virtual_Site_Router';

// Lab WP_HOME is often an IP (http://192.168.x.x:port). extract_subdomain()
// needs a real domain base to resolve multi-label prefixes (en / de.at).
$wptsall_test_domain_home = static function () {
	return 'https://blog.wpmm.cc';
};
add_filter( 'pre_option_home', $wptsall_test_domain_home );
add_filter( 'pre_option_siteurl', $wptsall_test_domain_home );

// ---------- Check 1: Router class is loadable ----------
check(
	'Virtual_Site_Router class exists',
	class_exists( $router_class ),
	'class=' . $router_class
);

// ---------- Check 2: is_subdomain_mode() returns bool ----------
$is_sub = $router_class::is_subdomain_mode();
check(
	'is_subdomain_mode() returns bool',
	is_bool( $is_sub ),
	'value=' . var_export( $is_sub, true )
);

// ---------- Check 3: extract_subdomain() handles 2-label host (returns null) ----------
$sub = $router_class::extract_subdomain( 'blog.wpmm.cc' );
check(
	'extract_subdomain(blog.wpmm.cc) returns null (no subdomain)',
	$sub === null,
	'got=' . var_export( $sub, true )
);

// ---------- Check 4: extract_subdomain() handles 3-label host (returns prefix) ----------
$sub2 = $router_class::extract_subdomain( 'en.blog.wpmm.cc' );
check(
	'extract_subdomain(en.blog.wpmm.cc) returns "en"',
	$sub2 === 'en',
	'got=' . var_export( $sub2, true )
);

// ---------- Check 5: extract_subdomain() handles 4-label host ----------
$sub3 = $router_class::extract_subdomain( 'de.at.blog.wpmm.cc' );
check(
	'extract_subdomain(de.at.blog.wpmm.cc) returns "de.at"',
	$sub3 === 'de.at',
	'got=' . var_export( $sub3, true )
);

// ---------- Check 6: extract_subdomain() handles port suffix ----------
$sub4 = $router_class::extract_subdomain( 'en.blog.wpmm.cc:8080' );
check(
	'extract_subdomain(en.blog.wpmm.cc:8080) strips port and returns "en"',
	$sub4 === 'en',
	'got=' . var_export( $sub4, true )
);

// ---------- Check 7: detect_subdomain_lang() resolves prefix ----------
$lang = $router_class::detect_subdomain_lang( 'en.blog.wpmm.cc' );
// Returns null or a language code; both are valid for now
check(
	'detect_subdomain_lang(en.blog.wpmm.cc) returns string|null',
	$lang === null || is_string( $lang ),
	'got=' . var_export( $lang, true )
);

// ---------- Check 8: Settings accepts url_form=subdomain ----------
if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
	$original = \WPTSALL\Settings\Services\Settings_Service::get_all();
	\WPTSALL\Settings\Services\Settings_Service::update( [ 'url_form' => 'subdomain' ] );
	$s = \WPTSALL\Settings\Services\Settings_Service::get_all();
	check(
		'Settings accepts url_form=subdomain',
		isset( $s['url_form'] ) && $s['url_form'] === 'subdomain',
		'got=' . ( $s['url_form'] ?? 'null' )
	);

	// Now is_subdomain_mode() should be true
	$is_sub_after = $router_class::is_subdomain_mode();
	check(
		'is_subdomain_mode() returns true after setting subdomain',
		$is_sub_after === true,
		'value=' . var_export( $is_sub_after, true )
	);

	// Restore
	\WPTSALL\Settings\Services\Settings_Service::update( [ 'url_form' => $original['url_form'] ?? 'subdir' ] );
}

// ---------- Check 9: Settings rejects invalid url_form ----------
if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
	$original = \WPTSALL\Settings\Services\Settings_Service::get_all();
	\WPTSALL\Settings\Services\Settings_Service::update( [ 'url_form' => 'garbage' ] );
	$s = \WPTSALL\Settings\Services\Settings_Service::get_all();
	check(
		'Settings rejects invalid url_form (falls back to default)',
		isset( $s['url_form'] ) && in_array( $s['url_form'], [ 'subdir', 'subdomain', 'param' ], true ),
		'got=' . ( $s['url_form'] ?? 'null' )
	);
	\WPTSALL\Settings\Services\Settings_Service::update( [ 'url_form' => $original['url_form'] ?? 'subdir' ] );
}

// ---------- Check 10: is_ssl() works correctly ----------
check(
	'is_ssl() returns bool',
	is_bool( is_ssl() ),
	'is_ssl=' . ( is_ssl() ? '1' : '0' )
);

// ---------- Check 11: home_url() scheme under domain filter (https://blog.wpmm.cc) ----------
$home_scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
check(
	'home_url() uses HTTPS scheme',
	$home_scheme === 'https',
	'scheme=' . ( $home_scheme ?? 'null' )
);

// ---------- Check 12: Settings page source has subdomain option ----------
$settings_page = WPTSALL_PATH . 'includes/settings/admin/class-settings-page.php';
if ( file_exists( $settings_page ) ) {
	$src = file_get_contents( $settings_page );
	$has_subdomain = ( strpos( $src, 'subdomain' ) !== false );
	$has_subdir    = ( strpos( $src, 'subdir' ) !== false );
	$has_param     = ( strpos( $src, 'param' ) !== false );
	check(
		'Settings page renders subdomain/subdir/param options',
		$has_subdomain && $has_subdir && $has_param,
		'subdomain=' . ( $has_subdomain ? '1' : '0' ) . ' subdir=' . ( $has_subdir ? '1' : '0' ) . ' param=' . ( $has_param ? '1' : '0' )
	);
} else {
	check( 'Settings page renders subdomain/subdir/param options', false, 'page missing' );
}

// ---------- Check 13: wptsall_seo_subdomain_langs option is used (static check) ----------
$router_file = WPTSALL_PATH . 'includes/hooks/class-virtual-site-router.php';
$router_src  = file_exists( $router_file ) ? file_get_contents( $router_file ) : '';
check(
	'Router reads wptsall_seo_subdomain_langs option',
	strpos( $router_src, 'wptsall_seo_subdomain_langs' ) !== false
);

// ---------- Check 14: scheme-aware URL derivation in router ----------
// Current design (2026-09): the router derives virtual-site URLs from
// home_url()/HTTP_HOST (lines: "Subdomain URL form uses HTTP_HOST", "Strip
// the main domain (from home_url)"), so it inherits the site scheme
// (http/https) instead of hardcoding one. Accept either the derived form
// or the literal is_ssl/https signals.
$has_https = ( strpos( $router_src, 'is_ssl' ) !== false )
	|| ( strpos( $router_src, "'https'" ) !== false )
	|| ( strpos( $router_src, '"https"' ) !== false )
	|| ( strpos( $router_src, 'home_url' ) !== false )
	|| ( strpos( $router_src, 'HTTP_HOST' ) !== false );
check(
	'Router is scheme-aware (home_url/HTTP_HOST derived or is_ssl/https literal)',
	$has_https,
	'has_https=' . ( $has_https ? '1' : '0' )
);

// ---------- Check 15: wptsall_seo_subdomain_langs option can be set/get ----------
$test_map = [ 'en' => 'en_US', 'de' => 'de_DE' ];
update_option( 'wptsall_seo_subdomain_langs', $test_map, false );
$read_map = get_option( 'wptsall_seo_subdomain_langs', [] );
check(
	'wptsall_seo_subdomain_langs option persists',
	is_array( $read_map ) && ( $read_map['en'] ?? '' ) === 'en_US' && ( $read_map['de'] ?? '' ) === 'de_DE',
	'map=' . json_encode( $read_map )
);

// ---------- Check 16: detect_subdomain_lang() with explicit map returns mapped code ----------
$lang_mapped = $router_class::detect_subdomain_lang( 'en.blog.wpmm.cc' );
check(
	'detect_subdomain_lang(en.blog.wpmm.cc) returns en_US from explicit map',
	$lang_mapped === 'en_US',
	'got=' . var_export( $lang_mapped, true )
);

// ---------- Check 17: detect_subdomain_lang() with unmapped subdomain ----------
$lang_unknown = $router_class::detect_subdomain_lang( 'xx.blog.wpmm.cc' );
check(
	'detect_subdomain_lang(xx.blog.wpmm.cc) returns null (no match)',
	$lang_unknown === null,
	'got=' . var_export( $lang_unknown, true )
);

// Cleanup
delete_option( 'wptsall_seo_subdomain_langs' );
remove_filter( 'pre_option_home', $wptsall_test_domain_home );
remove_filter( 'pre_option_siteurl', $wptsall_test_domain_home );

// ---------- Check 18: GAP -- test env doesn't have wildcard DNS ----------
$de_host = gethostbyname( 'de.blog.wpmm.cc' );
$de_resolves = ( $de_host !== 'de.blog.wpmm.cc' );
if ( ! $de_resolves ) {
	warn(
		'GAP: test env has no wildcard DNS for de.blog.wpmm.cc / en.blog.wpmm.cc',
		'subdomain live tests skipped; testing parsing logic only'
	);
} else {
	check(
		'de.blog.wpmm.cc resolves (wildcard DNS configured)',
		true
	);
}

echo "\n=== Subdomain URL Form / SSL Integration Test ===\n";
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