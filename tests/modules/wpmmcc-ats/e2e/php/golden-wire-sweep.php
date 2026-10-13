<?php
/**
 * Golden wire T-01 (opus5 doc 05 §9.10/§9.12): sweep EVERY p2fxgw row so the
 * lab carries zero golden-wire residue between runs (the machine runs
 * continuous testing; the capture lane must be pollution-free).
 *
 * Order matters: dependent rows first (results/tasks → mappings → posts →
 * link rows → rules/model → relation → virtual site). Deletes are
 * prefix/name-keyed, never by volatile ids, so a crashed run's residue is
 * healed by the next provision.
 *
 * Included by golden-wire-provision.php and runnable standalone:
 *   docker exec <lab> wp eval-file <this file> --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

/**
 * Delete every golden-wire fixture row.
 *
 * @param string $prefix Fixture tag prefix (p2fxgw).
 * @return int Approximate number of rows deleted.
 */
function golden_wire_sweep( $prefix = 'p2fxgw' ) {
	global $wpdb;

	if ( ! isset( $wpdb ) ) {
		return 0;
	}

	$deleted = 0;
	$prefix  = sanitize_key( (string) $prefix );

	// Resolve owned ids BEFORE deleting the rows they hang from.
	// Relations are matched by the STABLE target_theme_name (target_site_id is
	// 'v_<auto-inc>' — volatile across runs); virtual sites by the stable
	// unique site_path.
	$rel_table   = $wpdb->prefix . 'wptsall_site_relations';
	$model_table = $wpdb->prefix . 'wptsall_models';
	$vs_table    = $wpdb->prefix . 'wptsall_virtual_sites';
	$rel_rows    = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, target_site_id FROM {$rel_table} WHERE target_theme_name = %s", 'P2FXGW Golden 站' ),
		ARRAY_A
	);
	$rel_ids     = array_map( 'absint', array_column( (array) $rel_rows, 'id' ) );
	$vs_num_ids  = array();
	foreach ( (array) $rel_rows as $rr ) {
		$m = array();
		if ( preg_match( '/^v_(\d+)$/', (string) ( $rr['target_site_id'] ?? '' ), $m ) ) {
			$vs_num_ids[] = (int) $m[1];
		}
	}
	$vs_num_ids = array_merge(
		$vs_num_ids,
		array_map( 'absint', (array) $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$vs_table} WHERE site_path = %s", '/zh-gw/' )
		) )
	);
	$vs_num_ids = array_values( array_unique( array_filter( $vs_num_ids ) ) );
	$vs_tags    = array_map( function ( $n ) { return 'v_' . (int) $n; }, $vs_num_ids );
	$model_ids  = $wpdb->get_col(
		$wpdb->prepare( "SELECT id FROM {$model_table} WHERE plugin_slug = %s", $prefix . '-golden' )
	);
	$model_ids  = array_map( 'absint', (array) $model_ids );

	// 1. Translation results + tasks (by stable client_task_id / relation).
	$deleted += $wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->prefix}wptsall_translation_results WHERE client_task_id LIKE %s", $prefix . '-%' )
	);
	if ( ! empty( $rel_ids ) ) {
		$deleted += $wpdb->query(
			"DELETE FROM {$wpdb->prefix}wptsall_tasks WHERE relation_id IN (" . implode( ',', $rel_ids ) . ')'
		);
	}

	// 2. Mappings (relation-scoped, plus stale target_site_id tags).
	foreach ( array( 'post_mappings', 'term_mappings', 'media_mappings' ) as $mtable ) {
		if ( ! empty( $rel_ids ) ) {
			$deleted += $wpdb->query(
				"DELETE FROM {$wpdb->prefix}wptsall_{$mtable} WHERE relation_id IN (" . implode( ',', $rel_ids ) . ')'
			);
		}
		foreach ( $vs_tags as $tag ) {
			$deleted += $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}wptsall_{$mtable} WHERE target_site_id = %s", $tag )
			);
		}
	}

	// 3. Posts: the owned source post + any VS write-back copies (matched by
	//    origin meta first — the source post id is volatile across runs).
	$source_ids = $wpdb->get_col(
		$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s", $prefix . '_gwpost', 'P2FXGW Golden Post' )
	);
	$copy_ids   = array();
	if ( ! empty( $source_ids ) ) {
		$copy_ids = $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wptsall_source_post_id','_wptsall_origin_object_id') AND meta_value IN (" . implode( ',', array_map( 'absint', $source_ids ) ) . ')'
		);
	}
	$vs_copy_ids = $wpdb->get_col(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_virtual_site_id' AND meta_value IN ('" . implode( "','", array_map( 'esc_sql', $vs_tags ) ) . "')"
	);
	foreach ( array_unique( array_merge( array_map( 'absint', $source_ids ), array_map( 'absint', $copy_ids ), array_map( 'absint', $vs_copy_ids ) ) ) as $pid ) {
		if ( $pid > 0 ) {
			wp_delete_post( $pid, true );
			++$deleted;
		}
	}

	// 4. Relation-model links, rules, model, relation, virtual site.
	if ( ! empty( $rel_ids ) ) {
		$deleted += $wpdb->query(
			"DELETE FROM {$wpdb->prefix}wptsall_relation_models WHERE relation_id IN (" . implode( ',', $rel_ids ) . ')'
		);
		$deleted += $wpdb->query(
			"DELETE FROM {$wpdb->prefix}wptsall_relation_post_type_configs WHERE relation_id IN (" . implode( ',', $rel_ids ) . ')'
		);
		$deleted += $wpdb->query(
			"DELETE FROM {$wpdb->prefix}wptsall_site_relations WHERE id IN (" . implode( ',', $rel_ids ) . ')'
		);
	}
	if ( ! empty( $model_ids ) ) {
		$deleted += $wpdb->query(
			"DELETE FROM {$wpdb->prefix}wptsall_translation_rules WHERE model_id IN (" . implode( ',', $model_ids ) . ')'
		);
		$deleted += $wpdb->query(
			"DELETE FROM {$wpdb->prefix}wptsall_models WHERE id IN (" . implode( ',', $model_ids ) . ')'
		);
	}
	if ( ! empty( $vs_num_ids ) ) {
		$deleted += $wpdb->query(
			"DELETE FROM {$vs_table} WHERE id IN (" . implode( ',', $vs_num_ids ) . ')'
		);
	}

	return (int) $deleted;
}

// Standalone mode: sweep + report. When included by golden-wire-provision.php
// the marker constant is set BEFORE this require, so the top-level block is a
// no-op there and the probe drives the sweep explicitly.
if ( ! defined( 'GOLDEN_WIRE_SWEEP_INCLUDED' ) ) {
	$n = golden_wire_sweep();
	echo wp_json_encode( array( 'swept' => $n ) ) . "\n";
}
