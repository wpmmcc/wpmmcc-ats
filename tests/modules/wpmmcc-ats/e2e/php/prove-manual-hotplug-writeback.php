<?php
/**
 * Prove manual JSON hot-plug meta roundtrip for a CPT (default: directorist / at_biz_dir).
 *
 * Env (optional):
 *   HOTPLUG_POST_TYPE   default at_biz_dir
 *   HOTPLUG_FORM_ID     specific post ID (else first published of type)
 *   HOTPLUG_META_PLAIN  default _manual_directorist_tagline
 *   HOTPLUG_META_HTML   default _manual_directorist_blurb
 *   E2E_SINGLE_RELATION_ID
 *
 * Run:
 *   WPTSALL_LAB=1 wp eval-file tests/modules/wpmmcc-ats/e2e/php/prove-manual-hotplug-writeback.php
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$post_type  = getenv( 'HOTPLUG_POST_TYPE' ) ?: 'at_biz_dir';
$meta_plain = getenv( 'HOTPLUG_META_PLAIN' ) ?: '_manual_directorist_tagline';
$meta_html  = getenv( 'HOTPLUG_META_HTML' ) ?: '_manual_directorist_blurb';
$post_id    = (int) ( getenv( 'HOTPLUG_POST_ID' ) ?: getenv( 'HOTPLUG_FORM_ID' ) ?: 0 );
$relation_id = (int) ( getenv( 'E2E_SINGLE_RELATION_ID' ) ?: 0 );

echo "=== Prove manual hot-plug writeback ===\n";
echo "post_type={$post_type} meta_plain={$meta_plain} meta_html={$meta_html}\n";

if ( $post_id <= 0 ) {
	$post_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status = 'publish'
			 ORDER BY ID ASC LIMIT 1",
			$post_type
		)
	);
}
$post = $post_id > 0 ? get_post( $post_id ) : null;
if ( ! $post || $post->post_type !== $post_type ) {
	echo "FAIL: no published {$post_type}\n";
	exit( 1 );
}
echo "using_post_id={$post_id} title=" . $post->post_title . "\n";

if ( $relation_id <= 0 ) {
	$ids         = e2e_load_relation_ids();
	$relation_id = (int) ( $ids['virtual'] ?? $ids['wp'] ?? 0 );
	if ( $relation_id <= 0 ) {
		$rel_table   = wptsall_table( 'site_relations' );
		$relation_id = (int) $wpdb->get_var(
			"SELECT id FROM {$rel_table} WHERE status='active' ORDER BY id ASC LIMIT 1"
		);
	}
}
if ( $relation_id <= 0 ) {
	echo "FAIL: no relation_id\n";
	exit( 1 );
}
echo "relation_id={$relation_id}\n";

$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
if ( ! is_array( $relation ) ) {
	echo "FAIL: relation not found\n";
	exit( 1 );
}
$source_lang = (string) ( $relation['source_lang'] ?? 'en_US' );
$target_lang = (string) ( $relation['target_lang'] ?? 'zh_CN' );

$seed_meta = array(
	$meta_plain => 'Manual JSON hot-plug tagline for Directorist listing.',
	$meta_html  => '<p>Manual JSON <strong>rich HTML</strong> listing blurb.</p>',
);

foreach ( $seed_meta as $meta_key => $meta_value ) {
	update_post_meta( $post_id, $meta_key, $meta_value );
}
wp_cache_delete( $post_id, 'post_meta' );
echo "seeded source meta\n";

foreach ( $seed_meta as $meta_key => $_ ) {
	if ( '' === (string) get_post_meta( $post_id, $meta_key, true ) ) {
		echo "FAIL: source meta missing after seed: {$meta_key}\n";
		exit( 1 );
	}
}
echo "source_meta_ok\n";

$__d = function_exists( 'wptsall_issue_client_device_token' ) ? wptsall_issue_client_device_token( 'e2e-' . wp_generate_password( 6, false ), 'e2e' ) : array();
$token  = (string) ( $__d['token'] ?? '' );
$device_id = (string) ( $__d['device_id'] ?? '' );
$secret = function_exists( 'wptsall_get_client_route_secret' ) ? (string) wptsall_get_client_route_secret() : '';
if ( '' === $token ) {
	echo "FAIL: client token unavailable\n";
	exit( 1 );
}

$visits_table = wptsall_table( 'origin_visits' );
$target_type  = (string) ( $relation['target_site_type'] ?? 'virtual' );
$target_id    = (string) ( $relation['target_site_id'] ?? '' );
$deleted      = (int) $wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$visits_table}
		 WHERE origin_object_id = %d
		   AND visited_site_type = %s AND visited_site_id = %s",
		$post_id,
		$target_type,
		$target_id
	)
);
echo "cleared_origin_visits={$deleted}\n";

$marker = static function ( $text, $lang ) {
	$lang = trim( (string) $lang );
	if ( '' === $lang ) {
		$lang = 'zh_CN';
	}
	return '【' . $lang . '】' . (string) $text . '【/' . $lang . '】';
};

$translated_meta = array(
	$meta_plain => $marker( $seed_meta[ $meta_plain ], $target_lang ),
	$meta_html  => $marker( $seed_meta[ $meta_html ], $target_lang ),
);

$client_task_id = 'e2e-hotplug-' . $post_type . '-' . time() . '-' . $post_id;
$route_parts    = array( '', 'wptsall', 'v2' );
if ( '' !== $secret ) {
	$route_parts[] = $secret;
}
$route_parts[] = 'client';
$route_parts[] = 'translation-callback';
$route         = implode( '/', $route_parts );

$source_revision = '';
if ( class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ) {
	$source_revision = (string) \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id );
}
if ( '' === $source_revision ) {
	$source_revision = 'e2e-hotplug-' . $post_type . '-' . $post_id . '-' . (string) $post->post_modified_gmt;
}

$body = array(
	'business_line'     => 'post_content',
	'client_task_id'    => $client_task_id,
	'relation_id'       => $relation_id,
	'object_type'       => 'post_type',
	'post_type'         => $post_type,
	'subtype'           => $post_type,
	'object_id'         => $post_id,
	'source_lang'       => $source_lang,
	'target_lang'       => $target_lang,
	'source_revision'   => $source_revision,
	'schema_version'    => 2,
	'attempt_id'        => 'hotplug-' . wp_generate_uuid4(),
	'translated_fields' => array(
		'post_title' => $marker( (string) $post->post_title, $target_lang ),
	),
	'translated_meta'   => $translated_meta,
	'field_results'     => array(
		array(
			'field'          => 'post_title',
			'status'         => 'success',
			'content_format' => 'plain_text',
			'storage'        => 'post',
		),
		array(
			'field'          => $meta_plain,
			'status'         => 'success',
			'content_format' => 'plain_text',
			'storage'        => 'meta',
		),
		array(
			'field'          => $meta_html,
			'status'         => 'success',
			'content_format' => 'rich_html',
			'storage'        => 'meta',
		),
	),
);

$request = new WP_REST_Request( 'POST', $route );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
$request->set_header( 'X-WPTSALL-Device-Id', $device_id );
$request->set_header( 'X-WPTSALL-Client-Token', $token );
$request->set_header( 'X-Client-Version', '2.1.0' );
$request->set_header( 'Idempotency-Key', $client_task_id );
$request->set_body( wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

// Device-scoped content claim (required before callback).
$claim_hash = function_exists( 'wptsall_client_claim_owner_hash' )
	? wptsall_client_claim_owner_hash( $device_id, $relation_id, $target_lang, $post_type )
	: '';
$claim_now  = current_time( 'mysql', true );
if ( '' !== $claim_hash ) {
	$pm_table       = wptsall_table( 'post_mappings' );
	$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
	if ( $source_site_id <= 0 ) {
		$source_site_id = (int) get_current_blog_id();
	}
	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$pm_table}
			 WHERE relation_id = %d AND source_post_id = %d AND source_post_type = %s
			   AND source_site_id = %d AND target_site_id = %s
			 LIMIT 1",
			$relation_id,
			$post_id,
			$post_type,
			$source_site_id,
			$target_id
		)
	);
	if ( $existing ) {
		$wpdb->update(
			$pm_table,
			array(
				'claimed_at'       => $claim_now,
				'claim_owner_hash' => $claim_hash,
				'updated_at'       => $claim_now,
			),
			array( 'id' => (int) $existing ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	} else {
		$wpdb->insert(
			$pm_table,
			array(
				'source_post_id'    => $post_id,
				'source_post_type'  => $post_type,
				'source_site_id'    => $source_site_id,
				'relation_id'       => $relation_id,
				'target_post_id'    => 0,
				'target_post_type'  => $post_type,
				'target_site_id'    => $target_id,
				'relationship_type' => 'claim_placeholder',
				'claimed_at'        => $claim_now,
				'claim_owner_hash'  => $claim_hash,
				'created_at'        => $claim_now,
				'updated_at'        => $claim_now,
			),
			array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
	echo "claimed_mapping ok\n";
}

$response = rest_do_request( $request );
$status   = (int) $response->get_status();
$data     = $response->get_data();
echo 'callback_status=' . $status . ' body=' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) . "\n";

if ( 200 !== $status || empty( $data['success'] ) ) {
	echo "FAIL: translation-callback rejected\n";
	exit( 1 );
}

$sync_task_id    = (int) ( $data['sync_task_id'] ?? 0 );
$callback_sync   = is_array( $data['sync_result'] ?? null ) ? $data['sync_result'] : array();
$callback_tgt    = (int) ( $callback_sync['target_id'] ?? 0 );
$callback_synced = ! empty( $callback_sync['success'] ) && empty( $callback_sync['skipped'] );
echo "callback_ok result_id=" . (int) ( $data['result_id'] ?? 0 )
	. " sync_task_id={$sync_task_id} callback_target={$callback_tgt} callback_synced="
	. ( $callback_synced ? '1' : '0' ) . "\n";

if ( ! $callback_synced && $sync_task_id > 0 ) {
	$class = '\\WPTSALL\\Tasks\\Sync\\Sync_Executor';
	if ( ! class_exists( $class ) ) {
		$includes = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wptsall/' ) . 'includes/';
		require_once $includes . 'tasks/sync/class-sync-executor.php';
	}
	$sync_result = $class::execute_translation_sync( $sync_task_id );
	if ( is_wp_error( $sync_result ) ) {
		echo 'FAIL: sync ' . $sync_result->get_error_code() . ': ' . $sync_result->get_error_message() . "\n";
		exit( 1 );
	}
	echo "sync_ok (executor)\n";
} elseif ( $callback_synced ) {
	echo "sync_ok (via callback)\n";
}

$pm_table = wptsall_table( 'post_mappings' );
$maps     = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$pm_table} WHERE source_post_id = %d", $post_id )
);
$tgt = $callback_tgt > 0 ? $callback_tgt : (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT target_post_id FROM {$pm_table} WHERE source_post_id = %d ORDER BY id DESC LIMIT 1",
		$post_id
	)
);
echo "mappings={$maps} target={$tgt}\n";

$ok = false;
if ( $tgt > 0 ) {
	$blurb = (string) get_post_meta( $tgt, $meta_plain, true );
	$html  = (string) get_post_meta( $tgt, $meta_html, true );
	if ( '' === $blurb ) {
		$blurb = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$tgt,
				$meta_plain
			)
		);
	}
	if ( '' === $html ) {
		$html = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$tgt,
				$meta_html
			)
		);
	}
	echo 'target_plain_len=' . strlen( $blurb ) . ' target_html_len=' . strlen( $html ) . "\n";
	$ok = ( '' !== $blurb && '' !== $html
		&& false !== strpos( $blurb, '【' )
		&& false !== strpos( $html, '【' ) );
	echo ( $ok ? 'TARGET_META_OK' : 'TARGET_META_MISSING' ) . "\n";
} else {
	$tr_table = wptsall_table( 'translation_results' );
	$row      = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, status, translated_meta FROM {$tr_table} WHERE client_task_id = %s LIMIT 1",
			$client_task_id
		),
		ARRAY_A
	);
	echo 'tr=' . wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) . "\n";
	$stored = json_decode( (string) ( $row['translated_meta'] ?? '' ), true );
	if ( ! is_array( $stored ) ) {
		$stored = is_array( $row['translated_meta'] ?? null ) ? $row['translated_meta'] : array();
	}
	$ok = ! empty( $stored[ $meta_plain ] ) && ! empty( $stored[ $meta_html ] );
	echo ( $ok ? 'RESULT_META_OK (no mapping yet)' : 'RESULT_META_MISSING' ) . "\n";
}

if ( ! $ok ) {
	echo "FAIL: hot-plug writeback not proven\n";
	exit( 1 );
}

echo "PASS: manual hot-plug writeback closed loop ({$post_type})\n";
exit( 0 );
