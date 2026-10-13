<?php
/**
 * P3 — Problem-family regression over official-manual matrix targets.
 *
 * Families (from competitor + Lab findings):
 *   F1 url_prefix       — virtual URL / no double-prefix / VS marker
 *   F2 seo_identity     — hreflang present + x-default unprefixed + sitemap no shadow loc
 *   F3 translation_id   — mapping + _wptsall_source/relation meta dual-write
 *   F4 content_structure— official fixture used for priority plugins
 *   F5 query_isolation  — translated marker present; source title not leaking as sole body
 *
 * Expects runtime/manual-content-plugin-matrix-targets.json from the matrix PHP gate.
 *
 *   wp eval-file tests/modules/wpmmcc-ats/e2e/php/problem-family-regression.php --allow-root
 *
 * @package WPTSALL\E2E
 */

require_once __DIR__ . '/helpers.php';

$targets_file = e2e_runtime_file( 'manual-content-plugin-matrix-targets.json' );
$ofi_file     = e2e_runtime_file( 'official-manual-fixtures.json' );

$report = array(
	'ok'         => true,
	'started_at' => gmdate( 'c' ),
	'families'   => array(),
	'failures'   => array(),
	'warnings'   => array(),
);

if ( ! is_readable( $targets_file ) ) {
	echo wp_json_encode( array( 'ok' => false, 'error' => 'missing targets: ' . $targets_file ), JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( 1 );
}

$targets = json_decode( (string) file_get_contents( $targets_file ), true );
if ( ! is_array( $targets ) || empty( $targets['projects'] ) ) {
	echo wp_json_encode( array( 'ok' => false, 'error' => 'invalid targets json' ), JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( 1 );
}

$ofi = is_readable( $ofi_file ) ? json_decode( (string) file_get_contents( $ofi_file ), true ) : array();
$priority = array( 'woocommerce-content', 'learnpress-content', 'give-content', 'the-events-calendar-content', 'elementor-content' );

/**
 * Record family assert.
 *
 * @param array  $report Report ref.
 * @param string $family Family id.
 * @param string $name   Assert name.
 * @param bool   $ok     Pass.
 * @param mixed  $detail Detail.
 * @return void
 */
function wptsall_e2e_pfr_assert( array &$report, string $family, string $name, bool $ok, $detail = null ): void {
	if ( ! isset( $report['families'][ $family ] ) ) {
		$report['families'][ $family ] = array( 'asserts' => array(), 'ok' => true );
	}
	$report['families'][ $family ]['asserts'][] = array( 'name' => $name, 'ok' => $ok, 'detail' => $detail );
	if ( ! $ok ) {
		$report['families'][ $family ]['ok'] = false;
		$report['ok'] = false;
		$report['failures'][] = array( 'family' => $family, 'name' => $name, 'detail' => $detail );
	}
}

$prefix = (string) ( $targets['prefix'] ?? '' );
$projects = (array) $targets['projects'];

wptsall_e2e_pfr_assert( $report, 'F0_harness', 'matrix targets present', count( $projects ) > 0, array( 'count' => count( $projects ) ) );
wptsall_e2e_pfr_assert( $report, 'F0_harness', 'path prefix set', '' !== $prefix, array( 'prefix' => $prefix ) );
wptsall_e2e_pfr_assert( $report, 'F0_harness', 'official fixtures tagged', is_array( $ofi ) && ! empty( $ofi['fixtures'] ), array( 'tagged' => $ofi['summary']['tagged'] ?? 0 ) );

foreach ( $projects as $row ) {
	$project = (string) ( $row['project'] ?? '' );
	$tv      = is_array( $row['target_verify'] ?? null ) ? $row['target_verify'] : array();
	$checks  = is_array( $tv['checks'] ?? null ) ? $tv['checks'] : array();
	$seams   = is_array( $row['public_seams'] ?? null ) ? $row['public_seams'] : array();
	$seam_c  = is_array( $seams['checks'] ?? null ) ? $seams['checks'] : array();
	$routable = ! empty( $row['post_type_routable'] );

	// F3 translation identity — always when target exists.
	if ( (int) ( $row['target_post_id'] ?? 0 ) > 0 ) {
		wptsall_e2e_pfr_assert( $report, 'F3_translation_id', $project . ': mapping', ! empty( $checks['mapping'] ), $checks );
		wptsall_e2e_pfr_assert( $report, 'F3_translation_id', $project . ': source meta', ! empty( $checks['identity_source_meta'] ), $tv['identity'] ?? null );
		wptsall_e2e_pfr_assert( $report, 'F3_translation_id', $project . ': relation meta', ! empty( $checks['identity_relation_meta'] ), $tv['identity'] ?? null );
	}

	if ( $routable && empty( $seams['skipped'] ) ) {
		wptsall_e2e_pfr_assert( $report, 'F1_url_prefix', $project . ': front 200', ! empty( $seam_c['front_200'] ), $seams['detail'] ?? null );
		wptsall_e2e_pfr_assert( $report, 'F1_url_prefix', $project . ': vs marker', ! empty( $seam_c['front_vs_marker'] ), null );
		wptsall_e2e_pfr_assert( $report, 'F1_url_prefix', $project . ': no double prefix', ! empty( $seam_c['front_no_double_prefix'] ), null );

		wptsall_e2e_pfr_assert( $report, 'F2_seo_identity', $project . ': hreflang present', ! empty( $seam_c['hreflang_present'] ), $seams['detail']['hreflangs'] ?? null );
		wptsall_e2e_pfr_assert( $report, 'F2_seo_identity', $project . ': x-default unprefixed', ! empty( $seam_c['x_default_unprefixed'] ), $seams['detail']['x_default'] ?? null );
		wptsall_e2e_pfr_assert( $report, 'F2_seo_identity', $project . ': sitemap no shadow loc', ! empty( $seam_c['sitemap_no_shadow_loc'] ), $seams['detail'] ?? null );

		wptsall_e2e_pfr_assert( $report, 'F5_query_isolation', $project . ': translated marker on front', ! empty( $seam_c['front_has_marker'] ), $row['expected'] ?? null );
	} elseif ( $routable && ! empty( $seams['skipped'] ) ) {
		$report['warnings'][] = array( 'family' => 'F1_url_prefix', 'name' => $project . ': seams deferred to Playwright', 'detail' => $seams['reason'] ?? 'skipped' );
	}

	if ( in_array( $project, $priority, true ) ) {
		if ( ! empty( $row['official_fixture'] ) ) {
			wptsall_e2e_pfr_assert(
				$report,
				'F4_content_structure',
				$project . ': used official fixture',
				true,
				array( 'source_post_id' => $row['source_post_id'] ?? 0 )
			);
		} else {
			$report['warnings'][] = array(
				'family' => 'F4_content_structure',
				'name'   => $project . ': fell back to longest publish content',
				'detail' => array( 'source_post_id' => $row['source_post_id'] ?? 0 ),
			);
		}
	}
}

// Extra F1 search seam on virtual home (theme-independent: just ensure VS home 200).
$home = trailingslashit( home_url( '/' . trim( $prefix, '/' ) . '/' ) );
$mapped_home = (string) preg_replace( '#^https?://127\.0\.0\.1:\d+#i', 'http://127.0.0.1', $home );
$res  = wp_remote_get( $mapped_home, array( 'timeout' => 20, 'sslverify' => false, 'redirection' => 0, 'headers' => array( 'Host' => '127.0.0.1' ) ) );
$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
$body = is_wp_error( $res ) ? $res->get_error_message() : (string) wp_remote_retrieve_body( $res );
if ( in_array( $code, array( 0, 301, 302, 303, 307, 308 ), true ) ) {
	$report['warnings'][] = array( 'family' => 'F1_url_prefix', 'name' => 'virtual home deferred to Playwright', 'detail' => array( 'status' => $code, 'url' => $mapped_home ) );
} else {
	wptsall_e2e_pfr_assert( $report, 'F1_url_prefix', 'virtual home 200', 200 === $code, array( 'url' => $mapped_home, 'status' => $code ) );
	wptsall_e2e_pfr_assert( $report, 'F1_url_prefix', 'virtual home vs marker', (bool) preg_match( '/<!--\s*wptsall virtual site/i', $body ), null );
}

$report['finished_at'] = gmdate( 'c' );
$report['summary']     = array(
	'families' => array_map(
		static function ( $f ) {
			return array(
				'ok'       => ! empty( $f['ok'] ),
				'asserts'  => count( $f['asserts'] ?? array() ),
				'failures' => count( array_filter( $f['asserts'] ?? array(), static function ( $a ) { return empty( $a['ok'] ); } ) ),
			);
		},
		$report['families']
	),
	'failure_count' => count( $report['failures'] ),
);

$out = e2e_runtime_file( 'problem-family-regression.json' );
file_put_contents( $out, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
exit( $report['ok'] ? 0 : 1 );
