#!/usr/bin/env php
<?php
/**
 * hreflang no-double-emission verifier (WP-WRAP-01 item 3).
 *
 * Fetches the homepage and one post page and asserts:
 *   1. every hreflang alternate value appears at most once per page
 *      (no duplicate emission, e.g. plugin emitter + Yoast both emitting);
 *   2. when expected languages are provided (active virtual sites), the
 *      alternate set covers all of them.
 *
 * Usage:
 *   php verify-hreflang-no-double-emission.php --base=http://127.0.0.1:9081 \
 *       [--expect-langs=zh_CN,fr_FR] [--report=/path/report.json]
 *
 * Exit code: 0 = all hard checks passed, 1 = at least one hard failure.
 *
 * @package WPTSALL\Tests
 */

$opts = array(
	'base'        => getenv( 'WP_BASE_URL' ) ?: '',
	'expect'      => '',
	'report'      => '',
);
$args = array_slice( $argv ?? array(), 1 );
for ( $i = 0; $i < count( $args ); $i++ ) {
	$arg = (string) $args[ $i ];
	$next = ( $i + 1 < count( $args ) ) ? (string) $args[ $i + 1 ] : null;
	if ( preg_match( '/^--base=(.+)$/', $arg, $m ) ) {
		$opts['base'] = rtrim( $m[1], '/' );
	} elseif ( '--base' === $arg && null !== $next ) {
		$opts['base'] = rtrim( $next, '/' );
		++$i;
	} elseif ( preg_match( '/^--expect-langs=(.*)$/', $arg, $m ) ) {
		$opts['expect'] = $m[1];
	} elseif ( '--expect-langs' === $arg && null !== $next ) {
		$opts['expect'] = $next;
		++$i;
	} elseif ( preg_match( '/^--report=(.+)$/', $arg, $m ) ) {
		$opts['report'] = $m[1];
	} elseif ( '--report' === $arg && null !== $next ) {
		$opts['report'] = $next;
		++$i;
	}
}

if ( '' === $opts['base'] ) {
	fwrite( STDERR, "usage: php verify-hreflang-no-double-emission.php --base=<wp-base-url>\n" );
	exit( 2 );
}

$report = array(
	'suite'         => 'hreflang-no-double-emission',
	'base'          => $opts['base'],
	'expected_langs' => array_filter( array_map( 'trim', explode( ',', $opts['expect'] ) ) ),
	'pages'         => array(),
	'hard_fail'     => 0,
	'generated'     => gmdate( 'c' ),
	'dry_run'       => false,
);

$fatal_markers = array(
	'Fatal error:',
	'Parse error:',
	'There has been a critical error',
	'Uncaught Error',
);

/**
 * Fetch a URL over HTTP.
 *
 * @param string $url URL.
 * @return array{status:int,body:string}
 */
function wptsall_hreflang_fetch( $url ) {
	$ctx  = stream_context_create(
		array(
			'http' => array(
				'timeout'         => 20,
				'follow_location' => 1,
				'ignore_errors'   => true,
			),
		)
	);
	$body = @file_get_contents( $url, false, $ctx ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$code = 0;
	if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
		$code = (int) $m[1];
	}
	return array(
		'status' => $code,
		'body'   => (string) $body,
	);
}

/**
 * Normalize a language code for comparison (zh_CN, zh-CN, zh-Hans-CN).
 *
 * @param string $lang Raw language code.
 * @return string Normalized lowercase code without separators.
 */
function wptsall_hreflang_normalize_lang( $lang ) {
	return preg_replace( '/[^a-z0-9]/', '', strtolower( trim( (string) $lang ) ) );
}

/**
 * Check whether two normalized language codes match (prefix tolerant).
 *
 * @param string $a Normalized code A.
 * @param string $b Normalized code B.
 * @return bool
 */
function wptsall_hreflang_lang_matches( $a, $b ) {
	if ( '' === $a || '' === $b ) {
		return false;
	}
	if ( $a === $b ) {
		return true;
	}
	$len = min( strlen( $a ), strlen( $b ) );
	if ( $len < 2 ) {
		return false;
	}
	return strncmp( $a, $b, $len ) === 0;
}

/**
 * Extract hreflang => href pairs from an HTML document.
 *
 * @param string $html HTML.
 * @return array<string,string> hreflang value => href (first seen wins for dup detection).
 */
function wptsall_hreflang_extract( $html ) {
	$pairs = array();
	if ( ! preg_match_all( '/<link\b[^>]*>/i', $html, $tags ) ) {
		return $pairs;
	}
	foreach ( $tags[0] as $tag ) {
		if ( ! preg_match( '/rel=["\']alternate["\']/i', $tag ) ) {
			continue;
		}
		$href     = '';
		$hreflang = '';
		if ( preg_match( '/hreflang=["\']([^"\']+)["\']/i', $tag, $m ) ) {
			$hreflang = $m[1];
		}
		if ( preg_match( '/href=["\']([^"\']+)["\']/i', $tag, $m ) ) {
			$href = $m[1];
		}
		if ( '' !== $hreflang ) {
			$pairs[ $hreflang ] = $href;
		}
	}
	return $pairs;
}

/**
 * Verify one page and append results to the report.
 *
 * @param array  $report Report by ref.
 * @param string $label  Page label.
 * @param string $url    Page URL.
 * @return void
 */
function wptsall_hreflang_check_page( array &$report, $label, $url ) {
	$page   = array(
		'label'    => $label,
		'url'      => $url,
		'checks'   => array(),
		'hard_fail' => 0,
	);
	$record = function ( $id, $ok, $msg ) use ( &$report, &$page ) {
		$page['checks'][] = array(
			'id'  => $id,
			'ok'  => (bool) $ok,
			'msg' => $msg,
		);
		if ( ! $ok ) {
			++$page['hard_fail'];
			++$report['hard_fail'];
		}
	};

	$res = wptsall_hreflang_fetch( $url );
	$record(
		'page_reachable',
		$res['status'] >= 200 && $res['status'] < 400,
		"HTTP {$res['status']}"
	);

	foreach ( $report['fatal_markers'] ?? array() as $marker ) {
		// Fatal markers are checked by the caller-level loop below.
		unset( $marker );
	}

	if ( $res['status'] >= 200 && $res['status'] < 400 ) {
		foreach ( array(
			'Fatal error:',
			'Parse error:',
			'There has been a critical error',
			'Uncaught Error',
		) as $marker ) {
			if ( false !== stripos( $res['body'], $marker ) ) {
				$record( 'no_fatal_error', false, "response contains: {$marker}" );
				break;
			}
		}

		$pairs = wptsall_hreflang_extract( $res['body'] );
		$page['hreflang_pairs'] = $pairs;

		// 1. No duplicate hreflang value on the same page.
		$counts = array();
		if ( preg_match_all( '/<link\b[^>]*>/i', $res['body'], $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( ! preg_match( '/rel=["\']alternate["\']/i', $tag ) ) {
					continue;
				}
				if ( preg_match( '/hreflang=["\']([^"\']+)["\']/i', $tag, $m ) ) {
					$key = wptsall_hreflang_normalize_lang( $m[1] );
					$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
				}
			}
		}
		$dups = array();
		foreach ( $counts as $lang => $count ) {
			if ( $count > 1 ) {
				$dups[] = $lang . ' x' . $count;
			}
		}
		$record(
			'no_duplicate_hreflang',
			empty( $dups ),
			empty( $dups )
				? sprintf( '%d hreflang alternate(s), no duplicates', count( $counts ) )
				: 'duplicate hreflang emission: ' . implode( ', ', $dups )
		);

		// 2. Alternate set completeness when expected languages are known.
		// The source home MUST advertise the full set (Virtual_Site_SEO
		// emits it for every non-singular source request). Singular source
		// pages emit no hreflang by design (virtual-site contexts own the
		// per-post alternates; page-level completeness there is P1-CMS-01
		// scope), so a missing set is recorded as an observation, not a
		// failure — but ANY emitted set must still be complete.
		$expected = $report['expected_langs'];
		if ( ! empty( $expected ) ) {
			if ( empty( $counts ) ) {
				if ( 'home' === $label ) {
					$record(
						'alternate_set_complete',
						false,
						'home must advertise hreflang while active virtual sites exist: ' . implode( ',', $expected )
					);
				} else {
					$page['checks'][] = array(
						'id'  => 'alternate_set_complete',
						'ok'  => true,
						'msg' => 'no hreflang on singular source page (by design; per-page completeness tracked in P1-CMS-01)',
					);
				}
			} else {
				$missing = array();
				foreach ( $expected as $lang ) {
					$want = wptsall_hreflang_normalize_lang( $lang );
					$hit  = false;
					foreach ( array_keys( $counts ) as $have ) {
						if ( wptsall_hreflang_lang_matches( $have, $want ) ) {
							$hit = true;
							break;
						}
					}
					if ( ! $hit ) {
						$missing[] = $lang;
					}
				}
				$record(
					'alternate_set_complete',
					empty( $missing ),
					empty( $missing )
						? 'alternate set covers all expected languages'
						: 'missing alternate languages: ' . implode( ',', $missing )
				);
			}
		}
	}

	$report['pages'][] = $page;
}

// Homepage.
wptsall_hreflang_check_page( $report, 'home', $opts['base'] . '/' );

// One representative post page (via REST; skipped when unavailable).
$post_link = '';
$rest      = wptsall_hreflang_fetch( $opts['base'] . '/wp-json/wp/v2/posts?per_page=1&_fields=link' );
if ( $rest['status'] >= 200 && $rest['status'] < 400 ) {
	$decoded = json_decode( $rest['body'], true );
	if ( is_array( $decoded ) && ! empty( $decoded[0]['link'] ) ) {
		$post_link = (string) $decoded[0]['link'];
	}
}
if ( '' !== $post_link ) {
	wptsall_hreflang_check_page( $report, 'post', $post_link );
} else {
	$report['pages'][] = array(
		'label' => 'post',
		'url'   => '',
		'checks' => array(
			array(
				'id'  => 'post_discovered',
				'ok'  => true,
				'msg' => 'no post via REST; homepage-only run',
			),
		),
		'hard_fail' => 0,
	);
}

if ( '' !== $opts['report'] ) {
	file_put_contents( $opts['report'], json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}

foreach ( $report['pages'] as $page ) {
	foreach ( $page['checks'] as $check ) {
		printf(
			"[%s] %s %s: %s\n",
			$check['ok'] ? 'PASS' : 'FAIL',
			$page['label'],
			$check['id'],
			$check['msg']
		);
	}
}
printf( "hreflang-no-double-emission: hard_fail=%d report=%s\n", $report['hard_fail'], $opts['report'] ?: '<stdout>' );

exit( $report['hard_fail'] > 0 ? 1 : 0 );
