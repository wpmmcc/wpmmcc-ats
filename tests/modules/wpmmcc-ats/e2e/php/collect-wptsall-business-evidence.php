<?php
/**
 * Collect lightweight WP evidence for WPTSALL business smoke.
 *
 * Usage:
 *   wp eval-file tests/modules/wpmmcc-ats/e2e/php/collect-wptsall-business-evidence.php [window_seconds] [run_id=...] [relation_id=...] [fixture_marker=...]
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$window_seconds = 900;
$run_id         = '';
$relation_id    = 0;
$fixture_marker = '';
$scenario_id    = 'wptsall-business-smoke';
$requirement_id = 'REQ-WPTSALL-BUSINESS-SMOKE';
if ( ! empty( $args ) && is_array( $args ) ) {
	foreach ( $args as $raw_arg ) {
		$arg = trim( (string) $raw_arg );
		if ( '' === $arg ) {
			continue;
		}
		if ( preg_match( '/^\d+$/', $arg ) ) {
			$candidate = (int) $arg;
			if ( $candidate > 0 ) {
				$window_seconds = $candidate;
			}
			continue;
		}
		if ( 0 === strpos( $arg, 'run_id=' ) ) {
			$run_id = sanitize_text_field( substr( $arg, strlen( 'run_id=' ) ) );
			continue;
		}
		if ( 0 === strpos( $arg, 'relation_id=' ) ) {
			$relation_id = max( 0, (int) substr( $arg, strlen( 'relation_id=' ) ) );
			continue;
		}
		if ( 0 === strpos( $arg, 'fixture_marker=' ) ) {
			$fixture_marker = sanitize_text_field( substr( $arg, strlen( 'fixture_marker=' ) ) );
			continue;
		}
		if ( 0 === strpos( $arg, 'scenario_id=' ) ) {
			$scenario_id = sanitize_text_field( substr( $arg, strlen( 'scenario_id=' ) ) );
			continue;
		}
		if ( 0 === strpos( $arg, 'requirement_id=' ) ) {
			$requirement_id = sanitize_text_field( substr( $arg, strlen( 'requirement_id=' ) ) );
		}
	}
}
if ( '' === $fixture_marker && '' !== $run_id ) {
	$fixture_marker = 'wptsall-business-' . $run_id;
}

$tasks_table     = e2e_table( 'tasks' );
$results_table   = e2e_table( 'translation_results' );
$relations_table = e2e_table( 'site_relations' );
$post_map_table  = e2e_table( 'post_mappings' );
$term_map_table  = e2e_table( 'term_mappings' );
$media_map_table = e2e_table( 'media_mappings' );

$translation_result_success_statuses = array( 'synced', 'completed' );

if ( $relation_id <= 0 ) {
	$relation_ids = e2e_load_relation_ids();
	$relation_id  = max( 0, (int) ( $relation_ids['virtual'] ?? 0 ) );
}

$target_site_id = '';
if ( $relation_id > 0 && e2e_table_exists( $relations_table ) ) {
	$target_site_id = (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT target_site_id FROM {$relations_table} WHERE id = %d", $relation_id )
	);
}

function wptsall_evidence_status_counts( $table, $where_sql = '1=1' ) {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT status, COUNT(*) AS cnt FROM {$table} WHERE {$where_sql} GROUP BY status ORDER BY cnt DESC",
		ARRAY_A
	);
	$out = array();
	foreach ( (array) $rows as $row ) {
		$key = sanitize_key( (string) ( $row['status'] ?? '' ) );
		if ( '' === $key ) {
			$key = 'empty';
		}
		$out[ $key ] = (int) ( $row['cnt'] ?? 0 );
	}
	return $out;
}

function wptsall_evidence_column_exists( $table, $column ) {
	global $wpdb;

	if ( ! e2e_table_exists( $table ) ) {
		return false;
	}

	return (bool) $wpdb->get_var(
		$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column )
	);
}

function wptsall_evidence_value_counts( $table, $column, $where_sql = '1=1' ) {
	global $wpdb;

	if ( ! wptsall_evidence_column_exists( $table, $column ) ) {
		return array();
	}

	$rows = $wpdb->get_results(
		"SELECT {$column} AS value_key, COUNT(*) AS cnt FROM {$table} WHERE {$where_sql} GROUP BY {$column} ORDER BY cnt DESC",
		ARRAY_A
	);
	$out = array();
	foreach ( (array) $rows as $row ) {
		$key = sanitize_key( (string) ( $row['value_key'] ?? '' ) );
		if ( '' === $key ) {
			$key = 'empty';
		}
		$out[ $key ] = (int) ( $row['cnt'] ?? 0 );
	}

	return $out;
}

function wptsall_evidence_int_list_sql( array $ids ) {
	$ids = array_values(
		array_unique(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		)
	);
	if ( empty( $ids ) ) {
		return '0';
	}
	return implode( ',', $ids );
}

$fixture_source_post_ids = array();
if ( '' !== $fixture_marker ) {
	$fixture_source_post_ids = array_map(
		'intval',
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC",
				'_wptsall_e2e_fixture_marker',
				$fixture_marker
			)
		)
	);
}
$fixture_source_ids_sql = wptsall_evidence_int_list_sql( $fixture_source_post_ids );

$tasks_total     = 0;
$tasks_pending   = 0;
$tasks_completed = 0;
$tasks_failed    = 0;
$tasks_scoped_total  = 0;
$tasks_marker_total  = 0;
$tasks_status_counts = array();
$tasks_scoped_status_counts = array();
$tasks_marker_status_counts = array();

if ( e2e_table_exists( $tasks_table ) ) {
	$tasks_total     = e2e_table_count( $tasks_table );
	$tasks_pending   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_table} WHERE status = 'pending'" );
	$tasks_completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_table} WHERE status = 'completed'" );
	$tasks_failed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_table} WHERE status IN ('failed', 'error')" );
	$tasks_status_counts = wptsall_evidence_status_counts( $tasks_table );
	if ( $relation_id > 0 ) {
		$tasks_scoped_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tasks_table} WHERE relation_id = %d OR site_id = %d",
				$relation_id,
				$relation_id
			)
		);
		$tasks_scoped_status_counts = wptsall_evidence_status_counts(
			$tasks_table,
			$wpdb->prepare( 'relation_id = %d OR site_id = %d', $relation_id, $relation_id )
		);
	}
	if ( '0' !== $fixture_source_ids_sql ) {
		$marker_where = 'object_id IN (' . $fixture_source_ids_sql . ')';
		if ( $relation_id > 0 ) {
			$marker_where .= $wpdb->prepare( ' AND (relation_id = %d OR site_id = %d)', $relation_id, $relation_id );
		}
		$tasks_marker_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_table} WHERE {$marker_where}" );
		$tasks_marker_status_counts = wptsall_evidence_status_counts( $tasks_table, $marker_where );
	}
}

$results_total            = 0;
$results_completed        = 0;
$results_synced           = 0;
$results_success          = 0;
$results_failed           = 0;
$results_recent_completed = 0;
$results_recent_success   = 0;
$results_scoped_total     = 0;
$results_scoped_success   = 0;
$results_marker_total     = 0;
$results_marker_success   = 0;
$results_status_counts    = array();
$results_scoped_status_counts = array();
$results_marker_status_counts = array();

if ( e2e_table_exists( $results_table ) ) {
	$results_total     = e2e_table_count( $results_table );
	$results_completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$results_table} WHERE status = 'completed'" );
	$results_synced    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$results_table} WHERE status = 'synced'" );
	$results_success   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$results_table} WHERE status IN ('synced', 'completed')" );
	$results_failed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$results_table} WHERE status = 'failed'" );
	$results_status_counts = wptsall_evidence_status_counts( $results_table );
	if ( $relation_id > 0 ) {
		$results_scoped_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$results_table} WHERE relation_id = %d", $relation_id )
		);
		$results_scoped_success = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$results_table} WHERE relation_id = %d AND status IN ('synced', 'completed')", $relation_id )
		);
		$results_scoped_status_counts = wptsall_evidence_status_counts(
			$results_table,
			$wpdb->prepare( 'relation_id = %d', $relation_id )
		);
	}
	if ( '0' !== $fixture_source_ids_sql ) {
		$marker_where = 'object_id IN (' . $fixture_source_ids_sql . ')';
		if ( $relation_id > 0 ) {
			$marker_where .= $wpdb->prepare( ' AND relation_id = %d', $relation_id );
		}
		$results_marker_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$results_table} WHERE {$marker_where}" );
		$results_marker_success = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$results_table} WHERE {$marker_where} AND status IN ('synced', 'completed')"
		);
		$results_marker_status_counts = wptsall_evidence_status_counts( $results_table, $marker_where );
	}

	$has_created_at = (bool) $wpdb->get_var(
		$wpdb->prepare( "SHOW COLUMNS FROM {$results_table} LIKE %s", 'created_at' )
	);
	if ( $has_created_at ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - $window_seconds );
		$results_recent_completed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$results_table} WHERE status = %s AND created_at >= %s",
				'completed',
				$since
			)
		);
		$results_recent_success = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$results_table} WHERE status IN ('synced', 'completed') AND created_at >= %s",
				$since
			)
		);
	}
}

$virtual_posts_total = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_virtual_site_id'"
);
$virtual_posts_with_markers = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wptsall_virtual_site_id'
	 WHERE p.post_title LIKE '%\xE3\x80\x90%\xE3\x80\x91%' OR p.post_content LIKE '%\xE3\x80\x90%\xE3\x80\x91%'"
);
$origin_mappings_total = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_origin_object_id'"
);
$scoped_virtual_posts_total = 0;
$scoped_virtual_posts_with_markers = 0;
if ( '' !== $target_site_id ) {
	$scoped_virtual_posts_total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_virtual_site_id' AND meta_value = %s",
			$target_site_id
		)
	);
	$scoped_virtual_posts_with_markers = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wptsall_virtual_site_id'
			 WHERE pm.meta_value = %s
			   AND (p.post_title LIKE '%%\xE3\x80\x90%%\xE3\x80\x91%%' OR p.post_content LIKE '%%\xE3\x80\x90%%\xE3\x80\x91%%')",
			$target_site_id
		)
	);
}

$post_mappings_scoped = 0;
$term_mappings_scoped = 0;
$post_mappings_marker_source = 0;
$marker_virtual_posts_total = 0;
$marker_virtual_posts_with_markers = 0;
$post_mapping_relationship_counts = array();
$post_mapping_scoped_relationship_counts = array();
$post_mapping_marker_relationship_counts = array();
$post_mapping_target_counts = array();
$term_mapping_method_counts = array();
$term_translation_method_counts = array();
$media_mapping_method_counts = array();
if ( $relation_id > 0 && e2e_table_exists( $post_map_table ) ) {
	$post_mappings_scoped = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$post_map_table} WHERE relation_id = %d", $relation_id )
	);
	$post_mapping_scoped_relationship_counts = wptsall_evidence_value_counts(
		$post_map_table,
		'relationship_type',
		$wpdb->prepare( 'relation_id = %d', $relation_id )
	);
}
if ( '0' !== $fixture_source_ids_sql && e2e_table_exists( $post_map_table ) ) {
	$marker_post_mapping_where = 'source_post_id IN (' . $fixture_source_ids_sql . ')';
	if ( $relation_id > 0 ) {
		$marker_post_mapping_where .= $wpdb->prepare( ' AND relation_id = %d', $relation_id );
	}
	$post_mappings_marker_source = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$post_map_table} WHERE {$marker_post_mapping_where}"
	);
	$post_mapping_marker_relationship_counts = wptsall_evidence_value_counts(
		$post_map_table,
		'relationship_type',
		$marker_post_mapping_where
	);
	$target_filter = '';
	if ( '' !== $target_site_id ) {
		$target_filter = $wpdb->prepare( ' AND pm_site.meta_value = %s', $target_site_id );
	}
	$marker_virtual_posts_total = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm_origin ON p.ID = pm_origin.post_id AND pm_origin.meta_key = '_wptsall_origin_object_id'
		 INNER JOIN {$wpdb->postmeta} pm_site ON p.ID = pm_site.post_id AND pm_site.meta_key = '_wptsall_virtual_site_id'
		 WHERE CAST(pm_origin.meta_value AS UNSIGNED) IN ({$fixture_source_ids_sql}){$target_filter}"
	);
	$marker_virtual_posts_with_markers = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm_origin ON p.ID = pm_origin.post_id AND pm_origin.meta_key = '_wptsall_origin_object_id'
		 INNER JOIN {$wpdb->postmeta} pm_site ON p.ID = pm_site.post_id AND pm_site.meta_key = '_wptsall_virtual_site_id'
		 WHERE CAST(pm_origin.meta_value AS UNSIGNED) IN ({$fixture_source_ids_sql}){$target_filter}
		   AND (p.post_title LIKE '%\xE3\x80\x90%\xE3\x80\x91%' OR p.post_content LIKE '%\xE3\x80\x90%\xE3\x80\x91%' OR p.post_title LIKE '%" . esc_sql( $fixture_marker ) . "%' OR p.post_content LIKE '%" . esc_sql( $fixture_marker ) . "%')"
	);
}
if ( $relation_id > 0 && e2e_table_exists( $term_map_table ) ) {
	$term_mappings_scoped = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$term_map_table} WHERE relation_id = %d", $relation_id )
	);
}
if ( e2e_table_exists( $post_map_table ) ) {
	$post_mapping_relationship_counts = wptsall_evidence_value_counts( $post_map_table, 'relationship_type' );
	$post_mapping_target_counts = array(
		'mapped'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$post_map_table} WHERE target_post_id > 0" ),
		'placeholder' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$post_map_table} WHERE target_post_id = 0" ),
		'needs_resync' => wptsall_evidence_column_exists( $post_map_table, 'needs_resync' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$post_map_table} WHERE needs_resync = 1" )
			: 0,
	);
}
if ( e2e_table_exists( $term_map_table ) ) {
	$term_mapping_method_counts = wptsall_evidence_value_counts( $term_map_table, 'mapping_method' );
	$term_translation_method_counts = wptsall_evidence_value_counts( $term_map_table, 'translation_method' );
}
if ( e2e_table_exists( $media_map_table ) ) {
	$media_mapping_method_counts = wptsall_evidence_value_counts( $media_map_table, 'mapping_method' );
}

$payload = array(
	'generated_at'   => current_time( 'mysql' ),
	'window_seconds' => $window_seconds,
	'semantics'      => array(
		'translation_result_success_statuses' => $translation_result_success_statuses,
		'translation_results_completed'       => 'legacy_result_status_only_not_current_business_success_gate',
		'translation_results_synced'          => 'current_successful_writeback_status',
		'business_gate_primary_scope'         => 'fixture_marker',
	),
	'run_scope'      => array(
		'run_id'                  => $run_id,
		'scenario_id'             => $scenario_id,
		'requirement_id'          => $requirement_id,
		'relation_id'             => $relation_id,
		'target_site_id'          => $target_site_id,
		'fixture_marker'          => $fixture_marker,
		'fixture_source_post_ids' => $fixture_source_post_ids,
	),
	'tables'         => array(
		'tasks'               => e2e_table_exists( $tasks_table ),
		'translation_results' => e2e_table_exists( $results_table ),
		'post_mappings'       => e2e_table_exists( $post_map_table ),
		'term_mappings'       => e2e_table_exists( $term_map_table ),
		'media_mappings'      => e2e_table_exists( $media_map_table ),
	),
	'tasks'          => array(
		'total'     => $tasks_total,
		'pending'   => $tasks_pending,
		'completed' => $tasks_completed,
		'failed'    => $tasks_failed,
		'status_counts' => $tasks_status_counts,
		'scoped_total' => $tasks_scoped_total,
		'scoped_status_counts' => $tasks_scoped_status_counts,
		'marker_total' => $tasks_marker_total,
		'marker_status_counts' => $tasks_marker_status_counts,
	),
	'translation_results' => array(
		'total'            => $results_total,
		'completed'        => $results_completed,
		'completed_legacy' => $results_completed,
		'synced'           => $results_synced,
		'success'          => $results_success,
		'success_statuses' => $translation_result_success_statuses,
		'failed'           => $results_failed,
		'recent_completed' => $results_recent_completed,
		'recent_completed_legacy' => $results_recent_completed,
		'recent_success'   => $results_recent_success,
		'status_counts'    => $results_status_counts,
		'scoped_total'     => $results_scoped_total,
		'scoped_success'   => $results_scoped_success,
		'scoped_status_counts' => $results_scoped_status_counts,
		'marker_total'     => $results_marker_total,
		'marker_success'   => $results_marker_success,
		'marker_status_counts' => $results_marker_status_counts,
	),
	'writeback' => array(
		'virtual_posts_total'        => $virtual_posts_total,
		'virtual_posts_with_markers' => $virtual_posts_with_markers,
		'origin_mappings_total'      => $origin_mappings_total,
		'scoped_virtual_posts_total' => $scoped_virtual_posts_total,
		'scoped_virtual_posts_with_markers' => $scoped_virtual_posts_with_markers,
		'post_mappings_scoped'       => $post_mappings_scoped,
		'term_mappings_scoped'       => $term_mappings_scoped,
		'post_mappings_marker_source' => $post_mappings_marker_source,
		'marker_virtual_posts_total'  => $marker_virtual_posts_total,
		'marker_virtual_posts_with_markers' => $marker_virtual_posts_with_markers,
	),
	'mappings' => array(
		'post_relationship_type_counts' => $post_mapping_relationship_counts,
		'post_scoped_relationship_type_counts' => $post_mapping_scoped_relationship_counts,
		'post_marker_relationship_type_counts' => $post_mapping_marker_relationship_counts,
		'post_target_counts' => $post_mapping_target_counts,
		'term_mapping_method_counts' => $term_mapping_method_counts,
		'term_translation_method_counts' => $term_translation_method_counts,
		'media_mapping_method_counts' => $media_mapping_method_counts,
	),
);

$runtime_dir = e2e_runtime_dir();
if ( ! is_dir( $runtime_dir ) ) {
	wp_mkdir_p( $runtime_dir );
}

$latest_file  = e2e_runtime_file( 'wptsall-business-evidence.json' );
$history_file = e2e_runtime_file( 'wptsall-business-evidence-' . gmdate( 'Ymd-His' ) . '.json' );
$json         = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

file_put_contents( $latest_file, $json . "\n" );
file_put_contents( $history_file, $json . "\n" );

echo $json . "\n";
