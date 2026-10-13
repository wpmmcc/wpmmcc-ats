<?php
/**
 * Golden wire T-01 (opus5 doc 05 §9.10/§9.12): provision SELF-OWNED rows
 * for the four client REST capture endpoints, then emit the ids + a fresh
 * device token for the host-side lane (tests/infra/tools/check-golden-wire.py).
 *
 * Sweeps every p2fxgw row FIRST (self-healing: residue from a crashed run
 * never leaks into the next capture), then seeds:
 *   owned model p2fxgw-golden → rule (CPT p2fxgw_gwpost, translate fields
 *   with content_format/storage so rules discovery exercises the
 *   field_content_formats/field_storage_map contract axes)
 *   → relation en_US→zh_CN targeting virtual site v_p2fxgw
 *   → one published p2fxgw_gwpost.
 *
 * Emits one JSON line:
 *   {"token":"...","device_id":"...","secret":"...","relation_id":N,
 *    "model_id":N,"rule_id":N,"post_id":N,"vs_id":"v_p2fxgw","target_lang":"zh_CN"}
 *
 * Usage: docker exec <lab> wp eval-file <this file> --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

if ( ! defined( 'GOLDEN_WIRE_SWEEP_INCLUDED' ) ) {
	define( 'GOLDEN_WIRE_SWEEP_INCLUDED', true );
}
require_once __DIR__ . '/golden-wire-sweep.php';

$PREFIX = 'p2fxgw';

/**
 * Dedicated CPT so content discovery returns EXACTLY the owned post.
 * (Discovery lists every unmapped post of the rule subtype; scoping the
 * rule to a dedicated CPT keeps total=1 deterministic on a live lab.)
 */
register_post_type(
	'p2fxgw_gwpost',
	array(
		'label'        => 'P2FXGW Golden Post',
		'public'       => false,
		'show_ui'      => false,
		'supports'     => array( 'title', 'editor', 'excerpt' ),
		'rewrite'      => false,
		'query_var'    => false,
	)
);

golden_wire_sweep( $PREFIX );

// --- language: must already exist on the lab (baseline row, never owned). -----
$lang_table = $wpdb->prefix . 'wptsall_languages';
$has_zh     = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$lang_table} WHERE code = %s AND status = %s", 'zh_CN', 'active' )
);
if ( 1 > $has_zh ) {
	fwrite( STDERR, "golden-wire: active zh_CN language row missing; lab baseline changed\n" );
	exit( 1 );
}

// --- virtual target site ------------------------------------------------------
// virtual_sites.id is an AUTO-INCREMENT int; relations reference it as the
// string 'v_<id>'. Let the insert assign the id and read it back.
$vs_table = $wpdb->prefix . 'wptsall_virtual_sites';
$wpdb->insert(
	$vs_table,
	array(
		'site_name'        => 'P2FXGW Golden 站',
		'site_path'        => '/zh-gw/',
		'site_language'    => 'zh_CN',
		'source_blog_id'   => 1,
		'enable_blog_sync' => 1,
		'status'           => 'enabled',
	)
);
$vs_row_id = (int) $wpdb->insert_id;
$vs_id     = 'v_' . $vs_row_id;

// --- owned model --------------------------------------------------------------
// is_system=1: rule template validation only applies to non-system models
// (class-translation-rule-service.php constrain_rule_fields_to_template);
// the owned fixture model carries no model_object registry rows and is
// fully swept at the end of the run.
$models_table = $wpdb->prefix . 'wptsall_models';
$wpdb->insert(
	$models_table,
	array(
		'plugin_slug'    => 'p2fxgw-golden',
		'plugin_name'    => 'P2FXGW Golden Model',
		'text_domain'    => 'p2fxgw-golden',
		'description'    => 'opus5 T-01 golden wire fixture model',
		'post_types'     => wp_json_encode( array( 'p2fxgw_gwpost' ) ),
		'taxonomies'     => wp_json_encode( array( 'category' ) ),
		'status'         => 'active',
		'source_type'    => 'manual',
		'is_system'      => 1,
	)
);
$model_id = (int) $wpdb->insert_id;

// --- relation (en_US → zh_CN, virtual target) ----------------------------------
$rel_table = $wpdb->prefix . 'wptsall_site_relations';
$wpdb->insert(
	$rel_table,
	array(
		'source_site_id'    => 1,
		'source_site_type'  => 'wp',
		'source_lang'       => 'en_US',
		'source_theme_name' => 'Twenty Twenty-Five',
		'source_theme_path' => 'twentytwentyfive',
		'template'          => 'p2fxgw-golden',
		'target_site_id'    => $vs_id,
		'target_site_type'  => 'virtual',
		'target_lang'       => 'zh_CN',
		'target_theme_name' => 'P2FXGW Golden 站',
		'target_theme_path' => 'zh-gw',
		'media_handling'    => 'copy',
		'sync_mode'         => 'new_only',
		'direction'         => 'source_to_target',
		'conflict_strategy' => 'source_wins',
		'status'            => 'active',
	)
);
$relation_id = (int) $wpdb->insert_id;

$rm_table = $wpdb->prefix . 'wptsall_relation_models';
$wpdb->insert(
	$rm_table,
	array(
		'relation_id' => $relation_id,
		'model_id'    => $model_id,
	)
);

// --- owned rule (service call, same as the admin UI contract) ------------------
$rule_id = 0;
if ( ! class_exists( 'WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
	fwrite( STDERR, "golden-wire: Translation_Rule_Service unavailable\n" );
	golden_wire_sweep( $PREFIX );
	exit( 1 );
}
$created = WPTSALL\Models\Services\Translation_Rule_Service::create_rule(
	$model_id,
	array(
		'name'        => 'P2FXGW Golden Rule',
		'url_pattern' => '?post_type=p2fxgw_gwpost&p={id}',
		'url_type'    => 'single',
		'data_type'   => 'post',
		'object_name' => 'p2fxgw_gwpost',
		'priority'    => 10,
		'is_active'   => true,
		'field_capabilities' => array(
			'post_title'   => array(
				'type'           => 'translate',
				'enabled'        => true,
				'content_format' => 'plain_text',
				'storage'        => 'post_column',
			),
			'post_content' => array(
				'type'           => 'translate',
				'enabled'        => true,
				'content_format' => 'rich_html',
				'storage'        => 'post_column',
			),
			'post_excerpt' => array(
				'type'           => 'translate',
				'enabled'        => true,
				'content_format' => 'plain_text',
				'storage'        => 'post_column',
			),
		),
	)
);
if ( is_wp_error( $created ) ) {
	fwrite( STDERR, "golden-wire: create_rule failed: " . $created->get_error_message() . "\n" );
	golden_wire_sweep( $PREFIX );
	exit( 1 );
}
$rule_id = is_array( $created ) ? (int) ( $created['id'] ?? 0 ) : (int) $created;

// --- owned source post ---------------------------------------------------------
$post_id = wp_insert_post(
	array(
		'post_title'   => 'P2FXGW Golden Post',
		'post_content' => "<!-- wp:paragraph -->\n<p>P2FXGW golden source content for the golden wire capture.</p>\n<!-- /wp:paragraph -->\n",
		'post_excerpt' => 'P2FXGW golden source excerpt.',
		'post_status'  => 'publish',
		'post_type'    => 'p2fxgw_gwpost',
		'post_author'  => 1,
		// Seed the frozen fixture's opaque meta explicitly. An ATS-only owned
		// site must not depend on a second plugin's save_post UUID hook.
		'meta_input'   => array( '_wpmmcc_canonical_uuid' => wp_generate_uuid4() ),
	),
	true
);
if ( is_wp_error( $post_id ) ) {
	fwrite( STDERR, "golden-wire: wp_insert_post failed: " . $post_id->get_error_message() . "\n" );
	golden_wire_sweep( $PREFIX );
	exit( 1 );
}

// --- fresh device-scoped token (swept by the token service TTL, never a row) ---
if ( ! function_exists( 'wptsall_issue_client_device_token' ) ) {
	fwrite( STDERR, "golden-wire: token service unavailable\n" );
	golden_wire_sweep( $PREFIX );
	exit( 1 );
}
$issued    = wptsall_issue_client_device_token( 'p2fxgw-device', 'p2fxgw golden wire' );
$token     = (string) ( $issued['token'] ?? '' );
$device_id = (string) ( $issued['device_id'] ?? '' );
$secret    = function_exists( 'wptsall_get_client_route_secret' ) ? (string) wptsall_get_client_route_secret() : '';

if ( '' === $token || '' === $device_id || '' === $secret ) {
	fwrite( STDERR, "golden-wire: client API runtime incomplete (token/device/secret)\n" );
	golden_wire_sweep( $PREFIX );
	exit( 1 );
}

echo wp_json_encode(
	array(
		'token'       => $token,
		'device_id'   => $device_id,
		'secret'      => $secret,
		'relation_id' => $relation_id,
		'model_id'    => $model_id,
		'rule_id'     => $rule_id,
		'post_id'     => (int) $post_id,
		'vs_id'       => $vs_id,
		'target_lang' => 'zh_CN',
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
