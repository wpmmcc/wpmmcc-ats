<?php
/**
 * Dual translation-callback with same client_task_id — assert single row (ISS T5/S3).
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

global $wpdb;

$secret = function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '';
$token  = '';
$device = '';
if ( function_exists( 'wptsall_issue_client_device_token' ) ) {
	$d      = wptsall_issue_client_device_token( 'fault-wb-' . wp_generate_password( 4, false ), 'fault-wb' );
	$token  = (string) ( $d['token'] ?? '' );
	$device = (string) ( $d['device_id'] ?? '' );
}

if ( '' === $secret || '' === $token || '' === $device ) {
	echo wp_json_encode(
		array(
			'ok'     => false,
			'error'  => 'missing_auth_material',
			'secret' => '' !== $secret,
			'token'  => '' !== $token,
			'device' => '' !== $device,
		)
	) . "\n";
	exit( 1 );
}

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Fault WB ' . wp_generate_password( 4, false ),
		'post_content' => 'source body',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	),
	true
);
if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
	echo wp_json_encode( array( 'ok' => false, 'error' => 'post_create_failed' ) ) . "\n";
	exit( 1 );
}

$target = 'fault-wb-' . wp_generate_password( 8, false );
$relation_inserted = $wpdb->insert(
	wptsall_table( 'site_relations' ),
	array(
		'source_site_id'   => (int) get_current_blog_id(),
		'source_site_type' => 'wp',
		'source_lang'      => 'zh_CN',
		'template'         => 'fault-wb-' . wp_generate_password( 8, false ),
		'target_site_id'   => $target,
		'target_site_type' => 'virtual',
		'target_lang'      => 'en_US',
		'status'           => 'active',
		'created_at'       => current_time( 'mysql' ),
		'updated_at'       => current_time( 'mysql' ),
	),
	array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
);
$relation_id = $relation_inserted ? (int) $wpdb->insert_id : 0;
if ( $relation_id <= 0 ) {
	echo wp_json_encode( array( 'ok' => false, 'error' => 'relation_create_failed', 'post_id' => (int) $post_id ) ) . "\n";
	wp_delete_post( (int) $post_id, true );
	exit( 1 );
}

$rev = class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
	? \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', (int) $post_id )
	: '';
$pol = class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
	? \WPTSALL\Core\Job_Snapshot::current_policy_version()
	: '';

if ( '' === $rev ) {
	echo wp_json_encode( array( 'ok' => false, 'error' => 'missing_source_revision', 'post_id' => (int) $post_id ) ) . "\n";
	$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => $relation_id ), array( '%d' ) );
	wp_delete_post( (int) $post_id, true );
	exit( 1 );
}

$task_id = 'fault-wb-' . wp_generate_password( 12, false );
$body    = array(
	'business_line'     => 'post_content',
	'client_task_id'    => $task_id,
	'relation_id'       => $relation_id,
	'object_type'       => 'post_type',
	'subtype'           => 'post',
	'post_type'         => 'post',
	'object_id'         => (int) $post_id,
	'translated_fields' => array(
		'post_title'   => 'Fault Title EN',
		'post_content' => 'translated body',
	),
	'translated_meta'   => array(),
	'source_lang'       => 'zh_CN',
	'target_lang'       => 'en_US',
	'source_revision'   => $rev,
	'policy_version'    => $pol,
);

// Device-scoped content claim (P0 security model): the callback gate checks
// post_mappings.claimed_at/claim_owner_hash for the calling device. Mirror the
// claim endpoint by inserting a claim_placeholder for this fresh post.
$claim_hash = function_exists( 'wptsall_client_claim_owner_hash' )
	? wptsall_client_claim_owner_hash( $device, $relation_id, 'en_US', 'post' )
	: '';
$claim_now  = current_time( 'mysql', true );
if ( '' !== $claim_hash ) {
	$wpdb->insert(
		wptsall_table( 'post_mappings' ),
		array(
			'source_post_id'    => (int) $post_id,
			'source_post_type'  => 'post',
			'source_site_id'    => (int) get_current_blog_id(),
			'relation_id'       => $relation_id,
			'target_post_id'    => 0,
			'target_post_type'  => 'post',
			'target_site_id'    => $target,
			'relationship_type' => 'claim_placeholder',
			'claimed_at'        => $claim_now,
			'claim_owner_hash'  => $claim_hash,
			'created_at'        => $claim_now,
			'updated_at'        => $claim_now,
		),
		array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
}

$statuses = array();
$ids      = array();
$flags    = array();
$errors   = array();
for ( $i = 0; $i < 2; $i++ ) {
	$req = new WP_REST_Request( 'POST', '/wptsall/v2/' . $secret . '/client/translation-callback' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'X-WPTSALL-Protocol-Version', '2' );
	$req->set_header( 'X-WPTSALL-Device-Id', $device );
	$req->set_header( 'X-WPTSALL-Client-Token', $token );
	$req->set_body( wp_json_encode( $body ) );
	$res        = rest_do_request( $req );
	$statuses[] = (int) $res->get_status();
	$data       = $res->get_data();
	$ids[]      = is_array( $data ) ? ( $data['result_id'] ?? null ) : null;
	$flags[]    = is_array( $data ) ? ( ! empty( $data['idempotent'] ) ) : false;
	$errors[]   = is_array( $data ) ? ( $data['error'] ?? '' ) : '';
}

$rows = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE client_task_id = %s',
		wptsall_table( 'translation_results' ),
		$task_id
	)
);

$ok = ( 1 === $rows )
	&& isset( $statuses[0], $statuses[1] )
	&& $statuses[0] >= 200 && $statuses[0] < 300
	&& $statuses[1] >= 200 && $statuses[1] < 300
	&& ( ! empty( $flags[1] ) || (int) $ids[0] === (int) $ids[1] );

echo wp_json_encode(
	array(
		'ok'             => $ok,
		'client_task_id' => $task_id,
		'statuses'       => $statuses,
		'ids'            => $ids,
		'idempotent'     => $flags,
		'errors'         => $errors,
		'rows'           => $rows,
		'max_writebacks' => 1,
		'post_id'        => (int) $post_id,
		'relation_id'    => $relation_id,
	),
	JSON_PRETTY_PRINT
) . "\n";

$wpdb->delete( wptsall_table( 'translation_results' ), array( 'client_task_id' => $task_id ), array( '%s' ) );
$wpdb->delete(
	wptsall_table( 'post_mappings' ),
	array( 'relation_id' => $relation_id, 'source_post_id' => (int) $post_id ),
	array( '%d', '%d' )
);
$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => $relation_id ), array( '%d' ) );
wp_delete_post( (int) $post_id, true );
exit( $ok ? 0 : 1 );
