<?php
/**
 * Multi-page delivery matrix for virtual sites.
 *
 * Exercises every frontend page shape the user asked for — virtual home,
 * home pagination, category / tag archives, static pages, CPT singles,
 * second-language virtual site, native subsite (blog 2) — and asserts
 * HTTP 200, translated titles, translated content markers, SEO output
 * (hreflang / canonical / robots / body class) and that every absolute
 * URL keeps the lab port (:9081) after rewrite.
 *
 * Usage:
 *   wp eval-file run-pages.php
 *
 * Exit/json: single JSON object on stdout.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$home      = rtrim( (string) get_option( 'home' ), '/' );
$slug      = 'pages-' . substr( md5( (string) microtime( true ) ), 0, 8 );
$out       = array(
	'slug'    => $slug,
	'started' => gmdate( 'c' ),
	'pass'    => true,
	'status'  => 'ran',
	'steps'   => array(),
	'ids'     => array(),
	'urls'    => array(),
	'notes'   => array(),
);

function mp_ok( &$out, $name, $ok, $detail = null ) {
	$out['steps'][] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
	if ( ! $ok ) {
		$out['pass'] = false;
	}
}

function mp_http( $url ) {
	$r = wp_remote_get(
		$url,
		array(
			'timeout'     => 25,
			'redirection' => 3,
			'sslverify'   => false,
		)
	);
	if ( is_wp_error( $r ) ) {
		return array( 'url' => $url, 'code' => 0, 'error' => $r->get_error_message(), 'title' => '', 'body' => '' );
	}
	$body  = (string) wp_remote_retrieve_body( $r );
	$title = preg_match( '/<title>(.*?)<\/title>/is', $body, $m ) ? html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) : '';
	return array(
		'url'   => $url,
		'code'  => (int) wp_remote_retrieve_response_code( $r ),
		'title' => $title,
		'body'  => $body,
	);
}

function mp_virtual_url( $home, $permalink ) {
	$path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
	if ( ! $path || '/' === $path ) {
		return $home . '/en-us/';
	}
	return $home . '/en-us' . $path;
}

function mp_has_portless_absolute_url( $body, $home ) {
	$host = (string) wp_parse_url( $home, PHP_URL_HOST );
	$port = (string) wp_parse_url( $home, PHP_URL_PORT );
	if ( preg_match_all( '#https?://' . preg_quote( $host, '#' ) . '(?::\d+)?/#i', $body, $m ) ) {
		foreach ( $m[0] as $u ) {
			if ( ! preg_match( '#^https?://' . preg_quote( $host, '#' ) . ':' . preg_quote( $port, '#' ) . '/#i', $u ) ) {
				return $u;
			}
		}
	}
	return null;
}

function mp_seo( &$out, $name, $res, $home, array $opts = array() ) {
	$body = (string) $res['body'];
	$ok   = 200 === (int) $res['code']
		&& false === strpos( (string) $res['title'], 'Page not found' )
		&& false === strpos( (string) $res['title'], 'Untitled' );
	$deets = array( 'code' => $res['code'], 'title' => $res['title'] );
	if ( ! $ok ) {
		mp_ok( $out, $name, false, $deets );
		return;
	}
	$deets['virtual_class'] = false !== strpos( $body, 'wptsall-virtual-site' );
	if ( ! empty( $opts['expect_hreflang'] ) ) {
		$deets['hreflang'] = false !== strpos( $body, 'rel="alternate"' ) && false !== strpos( $body, 'hreflang="' );
		$ok                 = $ok && $deets['hreflang'];
	}
	if ( ! empty( $opts['expect_canonical'] ) ) {
		$deets['canonical']    = (bool) preg_match( '/rel="canonical"\s+href="([^"]+)"/i', $body, $cm );
		$deets['canonical_url'] = $cm[1] ?? '';
		if ( $deets['canonical'] ) {
			$deets['canonical_has_port'] = false !== strpos( $deets['canonical_url'], (string) wp_parse_url( $home, PHP_URL_PORT ) . '/' ) || false !== strpos( $deets['canonical_url'], ':' . (string) wp_parse_url( $home, PHP_URL_PORT ) );
			$ok                            = $ok && $deets['canonical_has_port'];
		}
		$ok = $ok && $deets['canonical'];
	}
	if ( ! empty( $opts['expect_content'] ) ) {
		$deets['content'] = false !== strpos( $body, $opts['expect_content'] );
		$ok                = $ok && $deets['content'];
	}
	if ( ! empty( $opts['expect_title'] ) ) {
		$deets['title_ok'] = false !== strpos( (string) $res['title'], $opts['expect_title'] );
		$ok                 = $ok && $deets['title_ok'];
	}
	$deets['robots_noindex'] = (bool) preg_match( '/name="robots"[^>]*content="[^"]*noindex/i', $body );
	if ( empty( $opts['allow_noindex'] ) && $deets['robots_noindex'] ) {
		$ok = false;
	}
	$portless          = mp_has_portless_absolute_url( $body, $home );
	$deets['portless'] = $portless;
	if ( $portless ) {
		$ok = false;
	}
	mp_ok( $out, $name, $ok, $deets );
}

// ---------------------------------------------------------------- fixture
// Reuse existing translated shadow posts for category/tag archives so home
// pagination volume already exists; create fresh posts for page/zh/CPT.
$manual = class_exists( '\\WPTSALL\\Sites\\Services\\Manual_Content_Service' ) ? '\\WPTSALL\\Sites\\Services\\Manual_Content_Service' : '';

$cat = wp_insert_term( 'COV Cat ' . $slug, 'category', array( 'slug' => 'cov-cat-' . $slug ) );
$tag = wp_insert_term( 'COV Tag ' . $slug, 'post_tag', array( 'slug' => 'cov-tag-' . $slug ) );
$cat_id = is_wp_error( $cat ) && term_exists( 'category-default', 'category' ) ? (int) get_term_by( 'slug', 'category-default', 'category' )->term_id : (int) ( is_wp_error( $cat ) ? 0 : $cat['term_id'] );
$tag_id = is_wp_error( $tag ) ? 0 : (int) $tag['term_id'];
$out['ids']['category'] = $cat_id;
$out['ids']['tag']      = $tag_id;
mp_ok( $out, 'fixture_terms', $cat_id > 0 && $tag_id > 0, array( 'cat' => $cat_id, 'tag' => $tag_id, 'cat_err' => is_wp_error( $cat ) ? $cat->get_error_message() : null, 'tag_err' => is_wp_error( $tag ) ? $tag->get_error_message() : null ) );

require_once __DIR__ . '/cov-relations.php';
$cov_rels = cov_resolve_relations();
$REL_V    = (int) $cov_rels['virtual'];
$REL_ZH   = (int) ( getenv( 'WPTSALL_COV_ZH_RELATION' ) ?: 0 );
if ( $REL_ZH <= 0 ) {
	// Second virtual relation if present (for zh path); else reuse virtual.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$REL_ZH = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}wptsall_site_relations
			 WHERE target_site_type = %s AND status = 'active' AND id <> %d
			 ORDER BY id DESC LIMIT 1",
			'virtual',
			$REL_V
		)
	);
	if ( $REL_ZH <= 0 ) {
		$REL_ZH = $REL_V;
	}
}
foreach ( $cov_rels['notes'] as $note ) {
	$out['notes'][] = $note;
}
mp_ok( $out, 'relations_resolved', $REL_V > 0, array( 'virtual' => $REL_V, 'zh' => $REL_ZH ) );

// Pick existing virtual shadows for the resolved relation and attach to the new
// category; tag 5 of them. The category archive then paginates (10 + 2).
$shadow_ids = array();
global $wpdb;
$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT m.target_post_id, m.source_post_id FROM {$wpdb->prefix}wptsall_post_mappings m
		 JOIN {$wpdb->posts} p ON p.ID = m.target_post_id AND p.post_type = 'post'
		 WHERE m.relation_id = %d AND m.target_post_id > 0 AND p.post_status = 'publish' ORDER BY m.id DESC LIMIT 12",
		$REL_V
	),
	ARRAY_A
);
$picked = array();
foreach ( (array) $rows as $r ) {
	$tid = (int) $r['target_post_id'];
	if ( $tid > 0 && get_post_meta( $tid, '_wptsall_virtual_site_id', true ) !== '' ) {
		$picked[] = $tid;
	}
}
foreach ( array_slice( $picked, 0, 12 ) as $tid ) {
	wp_set_post_terms( $tid, array( $cat_id ), 'category', false );
}
foreach ( array_slice( $picked, 0, 5 ) as $tid ) {
	wp_set_post_terms( $tid, array( $tag_id ), 'post_tag', false );
	wp_set_post_terms( $tid, array( $cat_id ), 'category', true ); // keep both
}
mp_ok( $out, 'fixture_shadows_tagged', count( $picked ) >= 12, array( 'count' => count( $picked ) ) );
$out['ids']['shadow_posts'] = $picked;

// Fresh static page + translation (resolved virtual relation).
$src_page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'COV源 Page ' . $slug,
		'post_content' => '<p>Source page body ' . $slug . '</p>',
		'post_name'    => 'cov-page-' . $slug,
	),
	true
);
$pg_v   = ( ! is_wp_error( $src_page_id ) && $manual && $REL_V > 0 ) ? $manual::save_translation( (int) $src_page_id, $REL_V, array(
	'post_title'   => 'COV EN Page ' . $slug,
	'post_content' => '<p>EN page body ' . $slug . '</p>',
	'post_name'    => 'cov-page-' . $slug . '-en',
) ) : new WP_Error( 'x', 'no manual service or relation' );
$page_tid = ! is_wp_error( $pg_v ) ? (int) $pg_v['target_id'] : 0;
mp_ok( $out, 'fixture_page_translation', $page_tid > 0, is_wp_error( $pg_v ) ? $pg_v->get_error_message() : $pg_v );

// Fresh post + translation for a second virtual site (or same if only one).
$zh_src_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'COV源 zh ' . $slug,
		'post_content' => '<p>zh source body ' . $slug . '</p>',
		'post_name'    => 'cov-zh-' . $slug,
	),
	true
);
if ( $manual && ! is_wp_error( $zh_src_id ) && $REL_ZH > 0 ) {
	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) ) {
		$blog_model = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
		if ( $blog_model ) {
			\WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $REL_ZH, (int) $blog_model['id'] );
		}
	}
	$zh_v = $manual::save_translation( (int) $zh_src_id, $REL_ZH, array(
		'post_title'   => 'COV ZH ' . $slug,
		'post_content' => '<p>zh target body ' . $slug . '</p>',
		'post_name'    => 'cov-zh-' . $slug . '-zh',
	) );
} else {
	$zh_v = new WP_Error( 'x', 'no manual service or zh relation' );
}
$zh_tid = ! is_wp_error( $zh_v ) ? (int) $zh_v['target_id'] : 0;
mp_ok( $out, 'fixture_zh_translation', $zh_tid > 0, is_wp_error( $zh_v ) ? $zh_v->get_error_message() : $zh_v );

// CPT single (portfolio-post-type plugin) — activate temporarily.
$cpt_slug = '';
if ( post_type_exists( 'portfolio' ) ) {
	$cpt_slug = 'portfolio';
} else {
	$compat = apply_filters( 'wptsall_extra_cpt_for_pages_matrix', '' );
	if ( $compat && post_type_exists( $compat ) ) {
		$cpt_slug = $compat;
	}
}
$cpt_tid = 0;
if ( '' !== $cpt_slug ) {
	$cpt_src = wp_insert_post(
		array(
			'post_type'    => $cpt_slug,
			'post_status'  => 'publish',
			'post_title'   => 'COV源 CPT ' . $slug,
			'post_content' => '<p>cpt source ' . $slug . '</p>',
			'post_name'    => 'cov-cpt-' . $slug,
		),
		true
	);
	if ( $manual && ! is_wp_error( $cpt_src ) ) {
		$cpt_v = $manual::save_translation( (int) $cpt_src, $REL_V, array(
			'post_title'   => 'COV EN CPT ' . $slug,
			'post_content' => '<p>cpt en body ' . $slug . '</p>',
			'post_name'    => 'cov-cpt-' . $slug . '-en',
		) );
		$cpt_tid = ! is_wp_error( $cpt_v ) ? (int) $cpt_v['target_id'] : 0;
	}
}
mp_ok( $out, 'fixture_cpt', '' === $cpt_slug || $cpt_tid > 0, array( 'cpt' => $cpt_slug, 'tid' => $cpt_tid ) );

// ---------------------------------------------------------------- URLs
$v_urls   = array();
$p_post   = $picked[0] ?? 0;
$v_posts  = array();
$post_tid = 0;
if ( $p_post > 0 ) {
	$post_tid        = $p_post;
	$v_urls['post']  = mp_virtual_url( $home, (string) get_permalink( $post_tid ) );
}
$v_urls['home']          = $home . '/en-us/';
$v_urls['home_p2']       = $home . '/en-us/page/2/';
$v_urls['category']      = $home . '/en-us/category/cov-cat-' . $slug . '/';
$v_urls['category_p2']   = $home . '/en-us/category/cov-cat-' . $slug . '/page/2/';
$v_urls['tag']           = $home . '/en-us/tag/cov-tag-' . $slug . '/';
$v_urls['not_found']     = $home . '/en-us/cov-definitely-missing-' . $slug . '/';
$v_urls['page']      = $page_tid ? mp_virtual_url( $home, (string) get_permalink( $page_tid ) ) : '';
$v_urls['zh_home']   = $home . '/test-vs-5b4e80ca84355043/';
$v_urls['zh_post']   = $zh_tid ? $home . '/test-vs-5b4e80ca84355043' . (string) wp_parse_url( (string) get_permalink( $zh_tid ), PHP_URL_PATH ) : '';
$v_urls['cpt']       = $cpt_tid ? mp_virtual_url( $home, (string) get_permalink( $cpt_tid ) ) : '';
$out['urls']         = $v_urls;
$out['ids']['page_tid'] = $page_tid;
$out['ids']['zh_tid']   = $zh_tid;
$out['ids']['cpt_tid']  = $cpt_tid;
$out['ids']['post_tid'] = $post_tid;

// ---------------------------------------------------------------- virtual checks
$h = mp_http( $v_urls['home'] );
mp_seo( $out, 'virtual_home', $h, $home, array(
	'expect_title' => '', // blog home title = site name; just require 200 + not 404
	'expect_content' => 'wptsall-virtual-site',
) );

$h = mp_http( $v_urls['home_p2'] );
mp_seo( $out, 'virtual_home_paged2', $h, $home, array( 'expect_content' => 'wptsall-virtual-site' ) );

$h = mp_http( $v_urls['category'] );
$first_en = count( $picked ) ? (string) get_the_title( $picked[0] ) : '';
mp_seo( $out, 'virtual_category', $h, $home, array( 'expect_content' => $first_en ? $first_en : '' ) );

$h = mp_http( $v_urls['category_p2'] );
mp_seo( $out, 'virtual_category_paged2', $h, $home, array( 'expect_content' => 'wptsall-virtual-site' ) );

$h = mp_http( $v_urls['tag'] );
mp_seo( $out, 'virtual_tag', $h, $home, array( 'expect_content' => $first_en ? $first_en : '' ) );

$h = mp_http( $v_urls['not_found'] );
mp_ok( $out, 'virtual_404_negative', 404 === (int) $h['code'], array( 'code' => $h['code'], 'title' => $h['title'] ) );

if ( $v_urls['page'] ) {
	$h = mp_http( $v_urls['page'] );
	mp_seo( $out, 'virtual_page', $h, $home, array(
		'expect_hreflang'  => true,
		'expect_canonical' => true,
		'expect_content'   => 'EN page body ' . $slug,
		'expect_title'     => 'COV EN Page ' . $slug,
	) );
}
if ( $post_tid > 0 ) {
	$h = mp_http( $v_urls['post'] );
	mp_seo( $out, 'virtual_post', $h, $home, array(
		'expect_hreflang'  => true,
		'expect_canonical' => true,
		'expect_content'   => (string) get_the_title( $post_tid ),
		'expect_title'     => (string) get_the_title( $post_tid ),
	) );
}
if ( $cpt_tid > 0 && $v_urls['cpt'] ) {
	$h = mp_http( $v_urls['cpt'] );
	mp_seo( $out, 'virtual_cpt', $h, $home, array(
		'expect_hreflang'  => true,
		'expect_canonical' => true,
		'expect_content'   => 'cpt en body ' . $slug,
		'expect_title'     => 'COV EN CPT ' . $slug,
	) );
}
$h = mp_http( $v_urls['zh_home'] );
mp_seo( $out, 'virtual_zh_home', $h, $home, array( 'expect_content' => 'wptsall-virtual-site' ) );
if ( $v_urls['zh_post'] ) {
	$h = mp_http( $v_urls['zh_post'] );
	mp_seo( $out, 'virtual_zh_post', $h, $home, array(
		'expect_hreflang'  => true,
		'expect_canonical' => true,
		'expect_content'   => 'zh target body ' . $slug,
		'expect_title'     => 'COV ZH ' . $slug,
	) );
}

// ---------------------------------------------------------------- native subsite (blog 2)
if ( is_multisite() ) {
	switch_to_blog( 2 );
	$sub_cat = wp_insert_term( 'SUB Cat ' . $slug, 'category', array( 'slug' => 'sub-cat-' . $slug ) );
	$sub_cat_id = is_wp_error( $sub_cat ) ? 0 : (int) $sub_cat['term_id'];
	$sub_page  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SUB Page ' . $slug, 'post_content' => '<p>sub page body</p>', 'post_name' => 'sub-page-' . $slug ), true );
	$sub_post  = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'SUB Post ' . $slug, 'post_content' => '<p>sub post body ' . $slug . '</p>', 'post_name' => 'sub-post-' . $slug ), true );
	if ( $sub_cat_id && $sub_post && ! is_wp_error( $sub_post ) ) {
		wp_set_post_terms( (int) $sub_post, array( $sub_cat_id ), 'category', false );
	}
	$sub_home   = rtrim( (string) get_option( 'home' ), '/' );
	// Build subsite URLs manually: in CLI the global $wp_rewrite still belongs
	// to blog 1, so get_term_link()/get_permalink() would inject blog 1's
	// /blog/ front into every link (a CLI-only artifact, not a web bug).
	$sub_urls   = array(
		'home'     => $sub_home . '/',
		'category' => $sub_cat_id ? $sub_home . '/category/' . ( is_wp_error( $sub_cat ) ? '' : 'sub-cat-' . $slug ) . '/' : '',
		'page'     => $sub_page && ! is_wp_error( $sub_page ) ? $sub_home . '/' . get_post_field( 'post_name', $sub_page ) . '/' : '',
		'post'     => $sub_post && ! is_wp_error( $sub_post ) ? $sub_home . '/' . gmdate( 'Y/m/d', (int) strtotime( get_post_field( 'post_date', $sub_post ) ) ) . '/' . get_post_field( 'post_name', $sub_post ) . '/' : '',
	);
	restore_current_blog();
	$out['urls']['subsite'] = $sub_urls;

	$h = mp_http( $sub_urls['home'] );
	mp_seo( $out, 'subsite_home', $h, $sub_home, array() );
	if ( $sub_urls['category'] ) {
		$h = mp_http( $sub_urls['category'] );
		mp_seo( $out, 'subsite_category', $h, $sub_home, array( 'expect_content' => 'SUB Post ' . $slug, 'expect_title' => 'SUB Cat ' . $slug ) );
	}
	if ( $sub_urls['page'] ) {
		$h = mp_http( $sub_urls['page'] );
		mp_seo( $out, 'subsite_page', $h, $sub_home, array( 'expect_content' => 'sub page body', 'expect_title' => 'SUB Page ' . $slug ) );
	}
	if ( $sub_urls['post'] ) {
		$h = mp_http( $sub_urls['post'] );
		mp_seo( $out, 'subsite_post', $h, $sub_home, array( 'expect_content' => 'sub post body ' . $slug, 'expect_title' => 'SUB Post ' . $slug ) );
	}
}

$out['finished'] = gmdate( 'c' );
$out['status']   = $out['pass'] ? 'passed' : 'failed';
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) . "\n";
