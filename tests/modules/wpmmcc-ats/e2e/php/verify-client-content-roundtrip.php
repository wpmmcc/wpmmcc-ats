<?php
/**
 * Client content round-trip verifier.
 *
 * Asserts the WP-side outcome of the client ↔ WP translation round-trip:
 *   1. A post mapping exists for the source post under the relation.
 *   2. The mapped target post exists and is published.
 *   3. The target title and content carry the mock translation markers.
 *   4. (media leg, when attachment_id provided) A media mapping exists for
 *      the source image attachment, its target attachment exists, and the
 *      target post content references the mapped target media URL.
 *
 * Input (via --args or the CLIENT_RT_POST_ID / CLIENT_RT_RELATION_ID /
 * CLIENT_RT_ATTACHMENT_ID env):
 *   relation_id, post_id, attachment_id
 *
 * Emits a single JSON line:
 *   {"pass":true|false,"relation_id":N,"post_id":N,"target_post_id":N,"checks":[...]}
 *
 * Usage:
 *   docker exec <lab> wp eval-file <this file> --allow-root -- relation_id=327 post_id=32689
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$relation_id = 0;
$post_id     = 0;

$env_relation = getenv( 'CLIENT_RT_RELATION_ID' );
$env_post     = getenv( 'CLIENT_RT_POST_ID' );
if ( is_string( $env_relation ) && '' !== trim( $env_relation ) ) {
	$relation_id = (int) $env_relation;
}
if ( is_string( $env_post ) && '' !== trim( $env_post ) ) {
	$post_id = (int) $env_post;
}

if ( ! empty( $args ) && is_array( $args ) ) {
	foreach ( $args as $raw ) {
		$raw = trim( (string) $raw );
		if ( 0 === strpos( $raw, 'relation_id=' ) ) {
			$relation_id = (int) substr( $raw, strlen( 'relation_id=' ) );
		}
		if ( 0 === strpos( $raw, 'post_id=' ) ) {
			$post_id = (int) substr( $raw, strlen( 'post_id=' ) );
		}
	}
}

$checks  = array();
$pass    = true;
$fail_fn = static function ( string $label, string $detail ) use ( &$checks, &$pass ) {
	$pass     = false;
	$checks[] = array( 'check' => $label, 'ok' => false, 'detail' => $detail );
};
$ok_fn   = static function ( string $label, string $detail ) use ( &$checks ) {
	$checks[] = array( 'check' => $label, 'ok' => true, 'detail' => $detail );
};

if ( $relation_id <= 0 || $post_id <= 0 ) {
	$fail_fn( 'input', 'relation_id and post_id are required' );
	echo wp_json_encode( array( 'pass' => false, 'checks' => $checks ) ) . "\n";
	exit( 1 );
}

$pm_table  = $wpdb->prefix . 'wptsall_post_mappings';
$mapping   = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$pm_table} WHERE source_post_id = %d AND relation_id = %d LIMIT 1", $post_id, $relation_id ),
	ARRAY_A
);
if ( empty( $mapping ) ) {
	$fail_fn( 'post_mapping', "no mapping for source post {$post_id} under relation {$relation_id}" );
	echo wp_json_encode( array( 'pass' => false, 'relation_id' => $relation_id, 'post_id' => $post_id, 'checks' => $checks ) ) . "\n";
	exit( 1 );
}

$target_post_id = (int) $mapping['target_post_id'];
$ok_fn( 'post_mapping', "source {$post_id} -> target {$target_post_id}" );

$target = get_post( $target_post_id );
if ( ! $target ) {
	$fail_fn( 'target_post', "target post {$target_post_id} not found" );
	echo wp_json_encode( array( 'pass' => false, 'relation_id' => $relation_id, 'post_id' => $post_id, 'target_post_id' => $target_post_id, 'checks' => $checks ) ) . "\n";
	exit( 1 );
}
$ok_fn( 'target_post', "status={$target->post_status} type={$target->post_type}" );

if ( 'publish' !== $target->post_status ) {
	$fail_fn( 'target_published', "target post status is {$target->post_status}, expected publish" );
} else {
	$ok_fn( 'target_published', 'target post is published' );
}

$marker_ok_title   = false !== mb_strpos( $target->post_title, '【zh_CN】' );
$marker_ok_content = false !== mb_strpos( $target->post_content, '【zh_CN】' );

if ( $marker_ok_title ) {
	$ok_fn( 'target_title_marker', mb_substr( $target->post_title, 0, 60 ) );
} else {
	$fail_fn( 'target_title_marker', 'target title lacks 【zh_CN】 marker: ' . mb_substr( $target->post_title, 0, 60 ) );
}

if ( $marker_ok_content ) {
	$ok_fn( 'target_content_marker', mb_substr( $target->post_content, 0, 60 ) );
} else {
	$fail_fn( 'target_content_marker', 'target content lacks 【zh_CN】 marker' );
}

/**
 * MEDIA LEG (when the provisioned source post carried an image attachment):
 *   1. media_mappings row for the source attachment under the relation.
 *   2. The mapped target attachment exists.
 *   3. The target post content references the TARGET attachment URL (the
 *      sync executor rewrote the source media URL, not just the text).
 */
$media_attachment_id = 0;
$env_media           = getenv( 'CLIENT_RT_ATTACHMENT_ID' );
if ( is_string( $env_media ) && '' !== trim( $env_media ) ) {
	$media_attachment_id = (int) $env_media;
}
if ( ! empty( $args ) && is_array( $args ) ) {
	foreach ( $args as $raw ) {
		$raw = trim( (string) $raw );
		if ( 0 === strpos( $raw, 'attachment_id=' ) ) {
			$media_attachment_id = (int) substr( $raw, strlen( 'attachment_id=' ) );
		}
	}
}

if ( $media_attachment_id > 0 ) {
	$mm_table = $wpdb->prefix . 'wptsall_media_mappings';
	$media_row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$mm_table} WHERE relation_id = %d AND source_media_id = %d LIMIT 1", $relation_id, $media_attachment_id ),
		ARRAY_A
	);
	if ( empty( $media_row ) ) {
		$fail_fn( 'media_mapping', "no media mapping for source attachment {$media_attachment_id} under relation {$relation_id}" );
		echo wp_json_encode(
			array(
				'pass'           => $pass,
				'relation_id'    => $relation_id,
				'post_id'        => $post_id,
				'target_post_id' => $target_post_id,
				'target_title'   => $target->post_title,
				'checks'         => $checks,
			)
		) . "\n";
		exit( 1 );
	}
	$target_media_id = (int) $media_row['target_media_id'];
	if ( $target_media_id > 0 ) {
		$ok_fn( 'media_mapping', "source media {$media_attachment_id} -> target {$target_media_id}" );
	} else {
		$fail_fn( 'media_mapping', 'mapping row exists but target_media_id is empty' );
	}

	$target_media = get_post( $target_media_id );
	if ( $target_media && 'attachment' === $target_media->post_type ) {
		$ok_fn( 'target_attachment', "attachment #{$target_media_id} ({$target_media->post_mime_type})" );
	} else {
		$fail_fn( 'target_attachment', "target attachment {$target_media_id} not found" );
	}

	$target_media_url = $target_media ? (string) wp_get_attachment_url( $target_media_id ) : '';
	if ( '' !== $target_media_url && false !== strpos( (string) $target->post_content, $target_media_url ) ) {
		$ok_fn( 'target_content_media_url', "target content references mapped media {$target_media_url}" );
	} else {
		$fail_fn( 'target_content_media_url', "target content does not reference the mapped target media URL ({$target_media_url})" );
	}
} elseif ( isset( $env_media ) && '' !== trim( (string) $env_media ) ) {
	$fail_fn( 'media_input', 'CLIENT_RT_ATTACHMENT_ID must be a positive integer' );
}

echo wp_json_encode(
	array(
		'pass'           => $pass,
		'relation_id'    => $relation_id,
		'post_id'        => $post_id,
		'target_post_id' => $target_post_id,
		'target_title'   => $target->post_title,
		'checks'         => $checks,
	)
) . "\n";

exit( $pass ? 0 : 1 );
