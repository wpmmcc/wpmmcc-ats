<?php
/**
 * Discover VS pages surface fixtures (no HTTP — host curls :9083).
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$out = array(
	'ok'        => true,
	'home_path' => '/',
	'vs'        => null,
	'paths'     => array( 'home' => '/' ),
	'timestamp' => gmdate( 'c' ),
);

$vs_list = array();
if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
	$all = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
	if ( is_array( $all ) ) {
		$vs_list = $all;
	}
}

$picked   = null;
$fallback = null;
foreach ( $vs_list as $vs ) {
	if ( ! is_array( $vs ) ) {
		continue;
	}
	$prefix = (string) ( $vs['path_prefix'] ?? '' );
	if ( '' === $prefix ) {
		continue;
	}
	if ( null === $fallback ) {
		$fallback = $vs;
	}
	if ( 0 === strpos( $prefix, 'mac_' ) || 0 === strpos( $prefix, 'dbg' ) ) {
		continue;
	}
	$picked = $vs;
	break;
}
if ( null === $picked ) {
	$picked = $fallback;
}

if ( null === $picked ) {
	$out['ok'] = false;
	$out['error'] = 'no_active_virtual_site';
	echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 1 );
}

$prefix = trim( (string) $picked['path_prefix'], '/' );
$vs_id  = (string) ( $picked['id'] ?? $picked['site_id'] ?? '' );
$out['vs'] = array(
	'id'          => $vs_id,
	'path_prefix' => $prefix,
	'lang'        => $picked['lang'] ?? null,
	'count'       => count( $vs_list ),
);
$out['paths']['vs_home'] = '/' . $prefix . '/';

global $wpdb;
$shadow_id = 0;
if ( '' !== $vs_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$shadow_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wptsall_virtual_site_id'
			WHERE pm.meta_value = %s AND p.post_status = 'publish' AND p.post_type IN ('post','page')
			ORDER BY p.ID DESC LIMIT 1",
			$vs_id
		)
	);
}
if ( $shadow_id > 0 ) {
	$permalink = (string) get_permalink( $shadow_id );
	$path      = (string) ( wp_parse_url( $permalink, PHP_URL_PATH ) ?: '' );
	$query     = (string) ( wp_parse_url( $permalink, PHP_URL_QUERY ) ?: '' );
	$out['paths']['shadow_singular'] = $path . ( '' !== $query ? '?' . $query : '' );
	$out['shadow_post_id']           = $shadow_id;
}

// S6: when a static front page exists and has a shadow for this VS, VS home
// must serve that shadow title (host curl asserts). Missing shadow = soft skip.
$front_id = (int) get_option( 'page_on_front' );
$s6       = array(
	'mode'   => 'blog_index',
	'source' => $front_id,
);
if ( $front_id > 0 && '' !== $vs_id && class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Query_Switch' ) ) {
	$shadow_front = (int) \WPTSALL\Hooks\Virtual_Site_Query_Switch::find_shadow_post_id( $front_id, $picked );
	if ( $shadow_front <= 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
		$shadow_front = (int) \WPTSALL\Sites\Services\Virtual_Site_Service::find_shadow_post_id_by_source( array( $vs_id ), $front_id );
	}
	if ( $shadow_front > 0 ) {
		$s6 = array(
			'mode'         => 'mapped',
			'source'       => $front_id,
			'shadow'       => $shadow_front,
			'expect_title' => (string) get_the_title( $shadow_front ),
		);
	} else {
		$s6 = array(
			'mode'   => 'missing_shadow',
			'source' => $front_id,
		);
	}
}
$out['s6'] = $s6;

echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 0 );
