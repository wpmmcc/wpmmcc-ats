<?php
/**
 * wpmmcc REST middleware performance + boundary harness (doc 21 §6 G2).
 *
 * Hermetic php harness on top of the wpmmcc test bootstrap (in-memory
 * options/transients — no WP install). Drives the real middleware code:
 *
 *   W-P1  Response-signing throughput — N signed ping envelopes through
 *         maybe_sign_client_response(), every signature re-verified with
 *         the client-side derivation (HKDF-SHA256 + HMAC-SHA256,
 *         base64url-nopad). Also proves the unsigned passthroughs (no
 *         token presented / non-wpmmcc route stay unsigned).
 *         NOTE: this is the FIRST automated coverage of the response
 *         signer (validated live until now via T-ID-4a on slot-g).
 *   W-P2  Rate-limit boundary — fixed-window limiter exact counts at an
 *         explicit limit (50), bucket isolation, limit=0 disable, and the
 *         DEFAULT_RATE_LIMIT_PER_MINUTE (240) boundary.
 *   W-P3  Combined stack — 260 ping-shaped requests through
 *         enforce_rate_limit() + maybe_sign_client_response(): exactly 240
 *         allowed AND signed (the documented per-minute ceiling), 20
 *         rejected with wpmmcc_rate_limited and left unsigned.
 *
 * Budgets are smoke budgets (they catch O(n^2)/pathological regressions,
 * not fine latency): W-P1/W-P2 < 5s, W-P3 < 30s.
 *
 * Usage (via run-wpmmcc-middleware-perf-smoke.sh):
 *   php wpmmcc-middleware-perf-harness.php <output.json> [N]
 *
 * Requires the wpmmcc plugin tree in the working copy (WPMMCC_TREE env to
 * override). The plugin tree is the colleague's untracked work — this
 * harness loads it read-only and is versioned on the ATS side.
 *
 * @package WPTSALL\Tests\E2E
 */

$repo_root = dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
$wpmmcc    = getenv( 'WPMMCC_TREE' ) ?: ( $repo_root . '/wpmmcc' );

if ( ! file_exists( $wpmmcc . '/tests/bootstrap.php' ) ) {
	fwrite( STDERR, "wpmmcc test bootstrap missing at {$wpmmcc}/tests/bootstrap.php\n" );
	exit( 2 );
}
require $wpmmcc . '/tests/bootstrap.php';
if ( ! class_exists( 'WPMMCC\\REST\\Rest_Middleware' ) ) {
	require $wpmmcc . '/source/includes/rest/class-wpmmcc-rest-middleware.php';
}

use WPMMCC\REST\Rest_Middleware;

/**
 * Bootstrap's WP_REST_Response stub has no header() capture (real WP does);
 * maybe_sign_client_response() requires it via method_exists().
 */
class Perf_REST_Response extends WP_REST_Response {
	public $captured = array();
	public function header( $key, $value, $replace = true ) {
		$this->captured[ $key ] = $value;
	}
}

$results    = array();
$fail_flags = array();

function record( &$results, &$fail_flags, $case, $fields ) {
	$fields['pass'] = empty( $fields['fail_reason'] );
	if ( ! $fields['pass'] ) {
		$fail_flags[] = $case;
	}
	$results[ $case ] = $fields;
}

$n = isset( $argv[2] ) ? max( 10, min( 2000, (int) $argv[2] ) ) : 300;

// ---------------------------------------------------------------------------
// W-P1 — response-signing throughput + unsigned passthroughs.
// ---------------------------------------------------------------------------
$token   = 'perf-signing-token-p1';
$request = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
$request->set_header( 'x-wptsall-client-token', $token );
$GLOBALS['wp_mock_options']['wpmmcc_client_token'] = $token;

$t0     = microtime( true );
$signed = 0;
$ok_sig = 0;
for ( $i = 0; $i < $n; $i++ ) {
	$resp = new Perf_REST_Response( array(
		'success' => true,
		'data'    => array(
			'plugin_identity' => 'wpmmcc',
			'plugin_version'  => '1.0.0-perf',
			'seq'             => $i,
		),
	) );
	$out = Rest_Middleware::maybe_sign_client_response( $resp, null, $request );
	if ( $out instanceof Perf_REST_Response && isset( $out->captured['X-WPTSALL-Response-Signature'] ) ) {
		$signed++;
		$plaintext = wp_json_encode( $out->get_data() );
		$key       = hash_hkdf( 'sha256', $token, 32, 'wptsall-signing-v1', 'request-signing' );
		$expect    = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $plaintext, $key, true ) ), '+/', '-_' ), '=' );
		if ( hash_equals( $expect, $out->captured['X-WPTSALL-Response-Signature'] ) ) {
			$ok_sig++;
		}
		if ( 'plaintext' !== ( $out->captured['X-WPTSALL-Transport'] ?? '' ) ) {
			$signed = -1; // transport marker missing
			break;
		}
	}
}
$wall_p1 = microtime( true ) - $t0;

// Unsigned passthroughs (correctness, counted once each).
$plain_req = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' ); // no token presented
$other_req = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
$other_req->set_header( 'x-wptsall-client-token', $token );
$other_route = new WP_REST_Request( 'GET', '/wp/v2/posts' );
$other_route->set_header( 'x-wptsall-client-token', $token );
$pass_no_token   = Rest_Middleware::maybe_sign_client_response( new Perf_REST_Response( array( 'success' => true ) ), null, $plain_req );
$pass_wrong_tok  = Rest_Middleware::maybe_sign_client_response( new Perf_REST_Response( array( 'success' => true ) ), null, $other_route );
$pass_other_rte  = null; // assigned below with a fresh token-mismatch request
$bad_tok_req     = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
$bad_tok_req->set_header( 'x-wptsall-client-token', 'not-the-stored-token' );
$pass_other_rte  = Rest_Middleware::maybe_sign_client_response( new Perf_REST_Response( array( 'success' => true ) ), null, $bad_tok_req );
$unsigned_ok     = ( ! isset( $pass_no_token->captured['X-WPTSALL-Response-Signature'] ) )
	&& ( ! isset( $pass_wrong_tok->captured['X-WPTSALL-Response-Signature'] ) )
	&& ( ! isset( $pass_other_rte->captured['X-WPTSALL-Response-Signature'] ) );

$p1_reason = '';
if ( $signed !== $n ) {
	$p1_reason = "signed {$signed}/{$n}";
} elseif ( $ok_sig !== $n ) {
	$p1_reason = "signature verify {$ok_sig}/{$n}";
} elseif ( ! $unsigned_ok ) {
	$p1_reason = 'unsigned passthrough broken';
} elseif ( $wall_p1 > 5.0 ) {
	$p1_reason = sprintf( 'wall %.2fs exceeded 5.0s budget', $wall_p1 );
}
record( $results, $fail_flags, 'W-P1-signing-throughput', array(
	'n'          => $n,
	'signed'     => $signed,
	'verified'   => $ok_sig,
	'unsigned_passthrough_ok' => $unsigned_ok,
	'wall_ms'    => round( $wall_p1 * 1000, 1 ),
	'ops_per_sec' => $wall_p1 > 0 ? round( $n / $wall_p1, 1 ) : null,
	'budget_secs' => 5.0,
	'fail_reason' => $p1_reason,
) );

// ---------------------------------------------------------------------------
// W-P2 — rate-limit boundary (explicit limit, isolation, disable, default).
// ---------------------------------------------------------------------------
$t0 = microtime( true );
$allowed = 0;
$limited = 0;
$limited_meta_ok = 0;
for ( $i = 0; $i < 60; $i++ ) {
	$r = Rest_Middleware::enforce_rate_limit( 'perf|p2|a', 50 );
	if ( true === $r ) {
		$allowed++;
	} elseif ( is_wp_error( $r ) ) {
		$limited++;
		$code   = $r->get_error_code();
		$status = $r->get_error_data()['status'] ?? 0;
		$retry  = $r->get_error_data()['retry_after'] ?? 0;
		if ( 'wpmmcc_rate_limited' === $code && 429 === $status && $retry >= 1 && $retry <= 60 ) {
			$limited_meta_ok++;
		}
	}
}
$isolated   = ( true === Rest_Middleware::enforce_rate_limit( 'perf|p2|b', 50 ) );
$disabled   = ( true === Rest_Middleware::enforce_rate_limit( 'perf|p2|c', 0 ) )
	&& ( true === Rest_Middleware::enforce_rate_limit( 'perf|p2|c', 0 ) );
$def_allowed = 0;
$def_limited = 0;
for ( $i = 0; $i < 241; $i++ ) {
	$r = Rest_Middleware::enforce_rate_limit( 'perf|p2|default' );
	if ( true === $r ) {
		$def_allowed++;
	} else {
		$def_limited++;
	}
}
$wall_p2 = microtime( true ) - $t0;

$p2_reason = '';
if ( 50 !== $allowed || 10 !== $limited || 10 !== $limited_meta_ok ) {
	$p2_reason = "explicit-50: allowed={$allowed} limited={$limited} meta_ok={$limited_meta_ok}";
} elseif ( ! $isolated ) {
	$p2_reason = 'bucket isolation broken';
} elseif ( ! $disabled ) {
	$p2_reason = 'limit=0 disable broken';
} elseif ( 240 !== $def_allowed || 1 !== $def_limited ) {
	$p2_reason = "default-240: allowed={$def_allowed} limited={$def_limited}";
} elseif ( $wall_p2 > 5.0 ) {
	$p2_reason = sprintf( 'wall %.2fs exceeded 5.0s budget', $wall_p2 );
}
record( $results, $fail_flags, 'W-P2-rate-limit-boundary', array(
	'explicit_limit'     => 50,
	'allowed_at_50'      => $allowed,
	'limited_at_50'      => $limited,
	'limited_meta_ok'    => $limited_meta_ok,
	'bucket_isolated'    => $isolated,
	'zero_limit_disables' => $disabled,
	'default_allowed'    => $def_allowed,
	'default_limited'    => $def_limited,
	'wall_ms'            => round( $wall_p2 * 1000, 1 ),
	'budget_secs'        => 5.0,
	'fail_reason'        => $p2_reason,
) );

// ---------------------------------------------------------------------------
// W-P3 — combined stack: limiter + signer at the documented ceiling.
// ---------------------------------------------------------------------------
$t0 = microtime( true );
$combined_bucket = 'perf|p3|combined';
$allowed = 0;
$limited = 0;
$signed  = 0;
$limited_unsigned = 0;
for ( $i = 0; $i < 260; $i++ ) {
	$gate = Rest_Middleware::enforce_rate_limit( $combined_bucket );
	if ( true !== $gate ) {
		$limited++;
		continue;
	}
	$allowed++;
	$resp = new Perf_REST_Response( array(
		'success' => true,
		'data'    => array( 'plugin_identity' => 'wpmmcc', 'seq' => $i ),
	) );
	$out = Rest_Middleware::maybe_sign_client_response( $resp, null, $request );
	if ( $out instanceof Perf_REST_Response && isset( $out->captured['X-WPTSALL-Response-Signature'] ) ) {
		$signed++;
	}
}
$wall_p3 = microtime( true ) - $t0;

$p3_reason = '';
if ( 240 !== $allowed || 20 !== $limited ) {
	$p3_reason = "gate: allowed={$allowed} limited={$limited} (expected 240/20)";
} elseif ( 240 !== $signed ) {
	$p3_reason = "signed {$signed}/240 allowed";
} elseif ( $wall_p3 > 30.0 ) {
	$p3_reason = sprintf( 'wall %.2fs exceeded 30.0s budget', $wall_p3 );
}
record( $results, $fail_flags, 'W-P3-combined-stack', array(
	'n'           => 260,
	'allowed'     => $allowed,
	'limited'     => $limited,
	'signed'      => $signed,
	'wall_ms'     => round( $wall_p3 * 1000, 1 ),
	'req_per_sec' => $wall_p3 > 0 ? round( 260 / $wall_p3, 1 ) : null,
	'budget_secs' => 30.0,
	'fail_reason' => $p3_reason,
) );

// ---------------------------------------------------------------------------
$doc = array(
	'task'    => 'wpmmcc-middleware-perf-smoke',
	'wpmmcc_tree' => $wpmmcc,
	'n'       => $n,
	'cases'   => $results,
	'pass'    => empty( $fail_flags ),
	'failed'  => $fail_flags,
);
$payload = json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( isset( $argv[1] ) && $argv[1] ) {
	file_put_contents( $argv[1], $payload );
} else {
	echo $payload;
}
foreach ( $results as $case => $fields ) {
	$mark = $fields['pass'] ? 'PASS' : 'FAIL';
	echo "{$mark} {$case}" . ( $fields['pass'] ? '' : ' — ' . $fields['fail_reason'] ) . "\n";
}
exit( empty( $fail_flags ) ? 0 : 1 );
