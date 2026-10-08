#!/usr/bin/env php
<?php
/**
 * SEO HTML + XML assertion matrix (ISS T4 / W3).
 *
 * Usage:
 *   php verify-seo-html-xml-matrix.php --base=http://127.0.0.1:9083 --path=/
 *
 * @package WPTSALL\Tests
 */

$opts = array(
	'base' => getenv( 'WP_BASE_URL' ) ?: 'http://127.0.0.1:9083',
	'path' => '/',
);
foreach ( array_slice( $argv ?? array(), 1 ) as $arg ) {
	if ( preg_match( '/^--base=(.+)$/', $arg, $m ) ) {
		$opts['base'] = rtrim( $m[1], '/' );
	}
	if ( preg_match( '/^--path=(.+)$/', $arg, $m ) ) {
		$opts['path'] = $m[1];
	}
}

$report = array(
	'base'      => $opts['base'],
	'checks'    => array(),
	'hard_fail' => 0,
	'soft_fail' => 0,
	'pass'      => 0,
	'generated' => gmdate( 'c' ),
);

$fatal_markers = array(
	'Fatal error:',
	'Parse error:',
	'There has been a critical error',
	'Uncaught Error',
);

/**
 * @param string $url URL.
 * @return array{status:int,body:string}
 */
function wptsall_seo_fetch( $url ) {
	if ( function_exists( 'wp_remote_get' ) ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => false,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'status' => 0,
				'body'   => $res->get_error_message(),
			);
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $res ),
			'body'   => (string) wp_remote_retrieve_body( $res ),
		);
	}
	$ctx  = stream_context_create(
		array(
			'http' => array(
				'timeout'         => 20,
				'follow_location' => 1,
				'ignore_errors'   => true,
			),
		)
	);
	$body = @file_get_contents( $url, false, $ctx ); // phpcs:ignore
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
 * @param array  $report Report by ref.
 * @param string $id     Id.
 * @param bool   $ok     Ok.
 * @param string $msg    Msg.
 * @param bool   $hard   Hard.
 * @return void
 */
function wptsall_seo_record( array &$report, $id, $ok, $msg, $hard = true ) {
	$report['checks'][] = array(
		'id'   => $id,
		'ok'   => $ok,
		'msg'  => $msg,
		'hard' => $hard,
	);
	if ( $ok ) {
		++$report['pass'];
	} elseif ( $hard ) {
		++$report['hard_fail'];
	} else {
		++$report['soft_fail'];
	}
}

$home = wptsall_seo_fetch( $opts['base'] . $opts['path'] );
wptsall_seo_record( $report, 'home_http', $home['status'] >= 200 && $home['status'] < 400, 'home status=' . $home['status'] );
wptsall_seo_record( $report, 'home_html', false !== stripos( $home['body'], '<html' ), 'home contains <html' );

$fatal_hit = false;
foreach ( $fatal_markers as $marker ) {
	if ( false !== strpos( $home['body'], $marker ) ) {
		$fatal_hit = true;
		break;
	}
}
wptsall_seo_record( $report, 'home_no_fatal', ! $fatal_hit, $fatal_hit ? 'fatal marker in home HTML' : 'no fatal markers' );

$has_hreflang = (bool) preg_match( '/rel=["\']alternate["\'][^>]*hreflang=/i', $home['body'] )
	|| (bool) preg_match( '/hreflang=["\'][^"\']+["\'][^>]*rel=["\']alternate["\']/i', $home['body'] );
wptsall_seo_record( $report, 'home_hreflang', $has_hreflang, $has_hreflang ? 'hreflang present' : 'hreflang missing', false );

$has_canonical = (bool) preg_match( '/rel=["\']canonical["\']/i', $home['body'] );
wptsall_seo_record( $report, 'home_canonical', $has_canonical, $has_canonical ? 'canonical present' : 'canonical missing', false );

$alts = array();
if ( preg_match_all( '/hreflang=["\']([^"\']+)["\'][^>]*href=["\']([^"\']+)["\']/i', $home['body'], $mm, PREG_SET_ORDER ) ) {
	$alts = $mm;
} elseif ( preg_match_all( '/href=["\']([^"\']+)["\'][^>]*hreflang=["\']([^"\']+)["\']/i', $home['body'], $mm2, PREG_SET_ORDER ) ) {
	foreach ( $mm2 as $row ) {
		$alts[] = array( 1 => $row[2], 2 => $row[1] );
	}
}
$checked = 0;
foreach ( $alts as $row ) {
	$lang = $row[1];
	$href = $row[2];
	if ( 'x-default' === strtolower( $lang ) ) {
		continue;
	}
	$alt = wptsall_seo_fetch( $href );
	$ok  = $alt['status'] >= 200 && $alt['status'] < 400 && false !== stripos( $alt['body'], 'hreflang' );
	wptsall_seo_record( $report, 'hreflang_reciprocal_' . $lang, $ok, "alt {$lang} status={$alt['status']}", false );
	++$checked;
	if ( $checked >= 2 ) {
		break;
	}
}

$robots = wptsall_seo_fetch( $opts['base'] . '/robots.txt' );
wptsall_seo_record( $report, 'robots_txt', $robots['status'] >= 200 && $robots['status'] < 500, 'robots status=' . $robots['status'] );

$sitemap_ok  = false;
$sitemap_msg = 'no sitemap endpoint responded 200';
foreach ( array( '/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml' ) as $sp ) {
	$sm = wptsall_seo_fetch( $opts['base'] . $sp );
	if ( $sm['status'] >= 200 && $sm['status'] < 400 && ( false !== stripos( $sm['body'], '<urlset' ) || false !== stripos( $sm['body'], '<sitemapindex' ) || false !== stripos( $sm['body'], '<?xml' ) ) ) {
		$sitemap_ok  = true;
		$sitemap_msg = $sp . ' ok';
		if ( preg_match_all( '#<loc>([^<]+)</loc>#i', $sm['body'], $locs ) ) {
			$bad = 0;
			$base_host = parse_url( $opts['base'], PHP_URL_HOST );
			foreach ( array_slice( $locs[1], 0, 20 ) as $loc ) {
				$host = parse_url( $loc, PHP_URL_HOST );
				if ( $host && $base_host && 0 !== strcasecmp( (string) $host, (string) $base_host ) ) {
					++$bad;
				}
			}
			wptsall_seo_record( $report, 'sitemap_host_consistency', $bad < 15, "off-host loc count={$bad}", false );
		}
		break;
	}
}
wptsall_seo_record( $report, 'sitemap_xml', $sitemap_ok, $sitemap_msg, false );

echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $report['hard_fail'] > 0 ? 1 : 0 );
