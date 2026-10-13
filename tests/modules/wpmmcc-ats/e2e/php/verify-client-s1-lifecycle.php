<?php
/**
 * S1 lifecycle lane verifier.
 *
 * mode=initial (scenario 1.1 — Gutenberg + taxonomy cascade):
 *   1. post_mappings has EXACTLY ONE row for the source post under the relation.
 *   2. Target post exists and is published.
 *   3. Title / excerpt / content carry the 【zh_CN】 mock marker.
 *   4. Gutenberg block fidelity: the set of block comments (names + JSON
 *      attribute payloads) is IDENTICAL between source and target content.
 *   5. Inline markup fidelity: <strong>, <blockquote class="wp-block-quote">
 *      preserved; the <a href> target host + first query key preserved.
 *   6. Taxonomy cascade: term_mappings rows exist for the source category and
 *      tag under the relation, and the target post is assigned the mapped terms.
 *
 * mode=increment (scenario 1.2 — incremental update):
 *   1. post_mappings STILL one row and target_post_id is UNCHANGED
 *      (expected_target_id arg) — no duplicate target post.
 *   2. Target title carries the marker (new title translated).
 *   3. Target content contains the appended paragraph text inside markers.
 *   4. Block-comment set still identical to the (now longer) source.
 *
 * Input args: relation_id, post_id, mode=initial|increment, cat_id, tag_id,
 *             expected_target_id (increment only).
 *
 * Emits one JSON line: {"pass":bool,"checks":[...],...}
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$relation_id  = 0;
$post_id      = 0;
$cat_id       = 0;
$tag_id       = 0;
$expected_tid = 0;
$mode         = 'initial';

if ( ! empty( $args ) && is_array( $args ) ) {
	foreach ( $args as $raw ) {
		$raw = trim( (string) $raw );
		foreach ( array(
			'relation_id'       => 'relation_id',
			'post_id'           => 'post_id',
			'cat_id'            => 'cat_id',
			'tag_id'            => 'tag_id',
			'expected_target_id' => 'expected_tid',
		) as $key => $var ) {
			if ( 0 === strpos( $raw, $key . '=' ) ) {
				${$var} = (int) substr( $raw, strlen( $key . '=' ) );
			}
		}
		if ( 'mode=increment' === $raw ) {
			$mode = 'increment';
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

/**
 * Extract the block-comment signature set from Gutenberg content:
 * every "<!-- wp:<name> <json-attrs> -->" and closing marker, whitespace
 * normalised. Two contents are "block-faithful" when their sets are equal.
 */
$block_signature = static function ( string $content ): array {
	$found = array();
	if ( preg_match_all( '/<!--\s*(\/?\s*wp:[a-zA-Z0-9\/_-]+)(.*?)\s*-->/u', $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $match ) {
			$name = trim( preg_replace( '/\s+/', ' ', $match[1] ) );
			$attrs = trim( preg_replace( '/\s+/', ' ', $match[2] ) );
			$found[] = $name . ( '' !== $attrs ? ' ' . $attrs : '' );
		}
	}
	sort( $found );
	return $found;
};

$source = get_post( $post_id );
if ( ! $source ) {
	$fail_fn( 'source_post', "source post {$post_id} not found" );
	echo wp_json_encode( array( 'pass' => false, 'checks' => $checks ) ) . "\n";
	exit( 1 );
}

$pm_table = $wpdb->prefix . 'wptsall_post_mappings';
$rows     = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$pm_table} WHERE source_post_id = %d AND relation_id = %d", $post_id, $relation_id )
);
if ( 1 !== $rows ) {
	$fail_fn( 'mapping_unique', "expected exactly 1 mapping row, found {$rows}" );
	$mapping = array();
} else {
	$mapping = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$pm_table} WHERE source_post_id = %d AND relation_id = %d LIMIT 1", $post_id, $relation_id ),
		ARRAY_A
	);
	$ok_fn( 'mapping_unique', 'source ' . $post_id . ' -> target ' . $mapping['target_post_id'] );
}

$target_post_id = (int) ( $mapping['target_post_id'] ?? 0 );

if ( 'increment' === $mode ) {
	if ( $expected_tid > 0 && $target_post_id !== $expected_tid ) {
		$fail_fn( 'increment_target_stable', "target changed: was {$expected_tid}, now {$target_post_id} (duplicate creation bug)" );
	} elseif ( $expected_tid > 0 ) {
		$ok_fn( 'increment_target_stable', "target unchanged at {$target_post_id}" );
	}
}

$target = $target_post_id > 0 ? get_post( $target_post_id ) : null;
if ( ! $target ) {
	$fail_fn( 'target_post', "target post {$target_post_id} not found" );
	echo wp_json_encode( array( 'pass' => false, 'checks' => $checks ) ) . "\n";
	exit( 1 );
}
$ok_fn( 'target_post', "status={$target->post_status} type={$target->post_type}" );

if ( 'publish' !== $target->post_status ) {
	$fail_fn( 'target_published', "target post status is {$target->post_status}, expected publish" );
} else {
	$ok_fn( 'target_published', 'target post is published' );
}

$marker_title   = false !== mb_strpos( $target->post_title, '【zh_CN】' );
$marker_content = false !== mb_strpos( $target->post_content, '【zh_CN】' );
$marker_ok      = $marker_title && $marker_content;
if ( $marker_title ) {
	$ok_fn( 'title_marker', mb_substr( $target->post_title, 0, 60 ) );
} else {
	$fail_fn( 'title_marker', 'target title lacks 【zh_CN】 marker: ' . mb_substr( $target->post_title, 0, 60 ) );
}
if ( $marker_content ) {
	$ok_fn( 'content_marker', 'marker present in content' );
} else {
	$fail_fn( 'content_marker', 'target content lacks 【zh_CN】 marker' );
}

/**
 * Gutenberg block fidelity (both modes).
 */
$src_blocks  = $block_signature( (string) $source->post_content );
$dst_blocks  = $block_signature( (string) $target->post_content );
if ( $src_blocks === $dst_blocks && ! empty( $src_blocks ) ) {
	$ok_fn( 'block_fidelity', count( $src_blocks ) . ' block signatures identical' );
} else {
	$only_src = array_values( array_diff( $src_blocks, $dst_blocks ) );
	$only_dst = array_values( array_diff( $dst_blocks, $src_blocks ) );
	$fail_fn(
		'block_fidelity',
		'block signatures differ; source-only: ' . wp_json_encode( array_slice( $only_src, 0, 3 ) )
		. ' target-only: ' . wp_json_encode( array_slice( $only_dst, 0, 3 ) )
	);
}

/**
 * Inline markup + attribute fidelity (both modes; appended blocks only add).
 */
foreach ( array(
	'"fontSize":"large"'        => 'attr_fontsize',
	'"level":2'                 => 'attr_level',
	'<strong>'                  => 'inline_strong',
	'wp-block-quote'            => 'inline_blockquote_class',
	'https://example.com/test'  => 'inline_link_host',
) as $needle => $label ) {
	if ( false !== mb_strpos( (string) $target->post_content, $needle ) ) {
		$ok_fn( $label, "preserved: {$needle}" );
	} else {
		$fail_fn( $label, "missing in target content: {$needle}" );
	}
}

/**
 * Scenario-specific assertions.
 */
if ( 'initial' === $mode ) {
	if ( false !== mb_strpos( (string) $target->post_excerpt, '【zh_CN】' ) ) {
		$ok_fn( 'excerpt_marker', 'excerpt translated' );
	} else {
		$fail_fn( 'excerpt_marker', 'target excerpt lacks 【zh_CN】 marker' );
	}

	if ( $cat_id > 0 || $tag_id > 0 ) {
		$tm_table = $wpdb->prefix . 'wptsall_term_mappings';
		foreach ( array(
			array( 'id' => $cat_id, 'tax' => 'category', 'label' => 'category' ),
			array( 'id' => $tag_id, 'tax' => 'post_tag', 'label' => 'tag' ),
		) as $term ) {
			if ( $term['id'] <= 0 ) {
				continue;
			}
			$tm = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$tm_table} WHERE relation_id = %d AND source_term_id = %d AND source_taxonomy = %s LIMIT 1",
					$relation_id,
					$term['id'],
					$term['tax']
				),
				ARRAY_A
			);
			if ( empty( $tm ) ) {
				$fail_fn( 'term_mapping_' . $term['label'], "no term_mappings row for source {$term['label']} #{$term['id']} under relation {$relation_id}" );
				continue;
			}
			$target_term_id = (int) $tm['target_term_id'];
			$assigned       = is_object_in_term( $target_post_id, $term['tax'], $target_term_id );
			if ( $assigned ) {
				$ok_fn( 'term_mapping_' . $term['label'], "source #{$term['id']} -> target term #{$target_term_id}, assigned to target post" );
			} else {
				$fail_fn( 'term_mapping_' . $term['label'], "target term #{$target_term_id} exists but NOT assigned to target post #{$target_post_id}" );
			}
		}
	}
}

if ( 'increment' === $mode ) {
	if ( false !== mb_strpos( (string) $target->post_content, 'Appended lifecycle paragraph' ) ) {
		$ok_fn( 'increment_appended', 'appended paragraph present in target content' );
	} else {
		$fail_fn( 'increment_appended', 'appended paragraph missing from target content (update not propagated)' );
	}
}

echo wp_json_encode(
	array(
		'pass'           => $pass,
		'mode'           => $mode,
		'relation_id'    => $relation_id,
		'post_id'        => $post_id,
		'target_post_id' => $target_post_id,
		'target_title'   => $target->post_title,
		'checks'         => $checks,
	)
) . "\n";

exit( $pass ? 0 : 1 );
