<?php
/**
 * P0 — Tag one official/representative source post per content-plugin project.
 *
 * Prefers existing Lab seed (official CSV/XML/JSON) over synthetic matrix posts.
 * Writes meta `_wptsall_official_fixture=1` + `_wptsall_official_fixture_project=<project>`.
 *
 *   wp eval-file tests/modules/wpmmcc-ats/e2e/php/tag-official-manual-fixtures.php --allow-root
 *
 * @package WPTSALL\E2E
 */

require_once __DIR__ . '/helpers.php';

$started = gmdate( 'c' );
$specs   = e2e_plugin_project_specs();
$out     = array(
	'ok'        => true,
	'started_at'=> $started,
	'fixtures'  => array(),
	'warnings'  => array(),
);

/**
 * Whether a post looks like an e2e synthetic fixture (skip for official pick).
 *
 * @param string $name  post_name.
 * @param string $title post_title.
 * @return bool
 */
function wptsall_e2e_ofi_is_synthetic( string $name, string $title ): bool {
	$hay = strtolower( $name . ' ' . $title );
	foreach ( array( 'manual-matrix', 'manual-fr', 'tgt-corr', 'src-corr', 'src-sitemap', 'tgt-sitemap', 'e2e-', 'wptsall-e2e', 'correspondence', 'src-search', 'tgt-search', 'src-nav', 'tgt-nav' ) as $needle ) {
		if ( false !== strpos( $hay, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Score a candidate post for "official-like" richness.
 *
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type.
 * @return int
 */
function wptsall_e2e_ofi_score( int $post_id, string $post_type ): int {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return -1;
	}
	$score = strlen( wp_strip_all_tags( (string) $post->post_content ) );
	$score += strlen( (string) $post->post_excerpt ) * 2;
	if ( 'product' === $post_type ) {
		if ( get_post_meta( $post_id, '_product_attributes', true ) ) {
			$score += 5000;
		}
		if ( 'variable' === get_post_meta( $post_id, '_product_type', true ) || taxonomy_exists( 'product_type' ) ) {
			$terms = wp_get_object_terms( $post_id, 'product_type', array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $terms ) && in_array( 'variable', (array) $terms, true ) ) {
				$score += 8000;
			}
		}
	}
	if ( 'give_forms' === $post_type && get_post_meta( $post_id, '_give_form_content', true ) ) {
		$score += 3000;
	}
	if ( in_array( $post_type, array( 'lp_course', 'course', 'courses' ), true ) ) {
		$score += 1000;
	}
	if ( 'elementor_library' === $post_type && get_post_meta( $post_id, '_elementor_data', true ) ) {
		$score += 5000;
	}
	return $score;
}

/**
 * Write official-fixture meta via raw SQL.
 *
 * Give (and similar) can filter update_post_meta / get_post_meta for `_wptsall_*`
 * keys, so WP APIs alone leave the tag missing in postmeta and P0→matrix miss.
 *
 * @param int    $post_id Post ID.
 * @param string $project Project key.
 * @return void
 */
function wptsall_e2e_ofi_set_meta( int $post_id, string $project ): void {
	global $wpdb;
	$keys = array( '_wptsall_official_fixture', '_wptsall_official_fixture_project' );
	foreach ( $keys as $key ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$post_id,
				$key
			)
		);
	}
	$wpdb->insert(
		$wpdb->postmeta,
		array(
			'post_id'    => $post_id,
			'meta_key'   => '_wptsall_official_fixture',
			'meta_value' => '1',
		),
		array( '%d', '%s', '%s' )
	);
	$wpdb->insert(
		$wpdb->postmeta,
		array(
			'post_id'    => $post_id,
			'meta_key'   => '_wptsall_official_fixture_project',
			'meta_value' => $project,
		),
		array( '%d', '%s', '%s' )
	);
}

/**
 * Clear official-fixture tags for a project via raw SQL (WP meta APIs may miss Give).
 *
 * @param string $project Project key.
 * @return void
 */
function wptsall_e2e_ofi_clear_project( string $project ): void {
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			'_wptsall_official_fixture_project',
			$project
		)
	);
	foreach ( array_map( 'absint', (array) $ids ) as $oid ) {
		if ( $oid <= 0 ) {
			continue;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s)",
				$oid,
				'_wptsall_official_fixture',
				'_wptsall_official_fixture_project'
			)
		);
	}
}

/**
 * Pick best published non-shadow source for a post type.
 *
 * @param string[] $post_types Post types.
 * @param string   $project    Project key (exclude posts already claimed by others).
 * @return array{ID:int,post_type:string,post_name:string,post_title:string}|null
 */
function wptsall_e2e_ofi_pick( array $post_types, string $project = '' ): ?array {
	global $wpdb;
	$best = null;
	$best_score = -1;
	foreach ( $post_types as $post_type ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_name, p.post_title
				   FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = '_wptsall_virtual_site_id'
				   LEFT JOIN {$wpdb->postmeta} ofp ON ofp.post_id = p.ID AND ofp.meta_key = '_wptsall_official_fixture_project'
				  WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_name <> '' AND vm.meta_id IS NULL
				    AND ( ofp.meta_id IS NULL OR ofp.meta_value = %s )
				  ORDER BY p.ID DESC LIMIT 80",
				$post_type,
				$project
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			if ( wptsall_e2e_ofi_is_synthetic( (string) $row['post_name'], (string) $row['post_title'] ) ) {
				continue;
			}
			$score = wptsall_e2e_ofi_score( (int) $row['ID'], (string) $row['post_type'] );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $row;
			}
		}
	}
	return $best;
}

foreach ( $specs as $project => $spec ) {
	$verifier = is_array( $spec['verifier'] ?? null ) ? $spec['verifier'] : array();
	$post_types = array();
	if ( ! empty( $verifier['source_post_types'] ) && is_array( $verifier['source_post_types'] ) ) {
		$post_types = array_map( 'strval', $verifier['source_post_types'] );
	} elseif ( ! empty( $verifier['source_post_type'] ) ) {
		$post_types = array( (string) $verifier['source_post_type'] );
	} elseif ( ! empty( $spec['content_types'] ) && is_array( $spec['content_types'] ) ) {
		$post_types = array_map( 'strval', $spec['content_types'] );
	} else {
		$post_types = array( 'post' );
	}

	// Clear previous tags for this project so re-runs stay deterministic.
	// Do not clear other projects' tags (product CPT is shared by Woo + ACF).
	wptsall_e2e_ofi_clear_project( $project );

	$pick = wptsall_e2e_ofi_pick( $post_types, $project );
	if ( ! $pick ) {
		$out['warnings'][] = array( 'project' => $project, 'reason' => 'no_candidate', 'post_types' => $post_types );
		$out['fixtures'][ $project ] = array( 'ok' => false, 'post_types' => $post_types );
		continue;
	}
	$id = (int) $pick['ID'];
	wptsall_e2e_ofi_set_meta( $id, $project );
	$out['fixtures'][ $project ] = array(
		'ok'         => true,
		'post_id'    => $id,
		'post_type'  => (string) $pick['post_type'],
		'post_name'  => (string) $pick['post_name'],
		'post_title' => (string) $pick['post_title'],
		'score'      => wptsall_e2e_ofi_score( $id, (string) $pick['post_type'] ),
		'edit_url'   => admin_url( 'post.php?post=' . $id . '&action=edit' ),
	);
}

$out['finished_at'] = gmdate( 'c' );
$out['summary']     = array(
	'total'   => count( $specs ),
	'tagged'  => count( array_filter( $out['fixtures'], static function ( $r ) { return ! empty( $r['ok'] ); } ) ),
	'missing' => count( $out['warnings'] ),
);
$out['ok'] = $out['summary']['tagged'] > 0;

$file = e2e_runtime_file( 'official-manual-fixtures.json' );
if ( ! is_dir( dirname( $file ) ) ) {
	mkdir( dirname( $file ), 0755, true );
}
file_put_contents( $file, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
if ( ! $out['ok'] ) {
	exit( 1 );
}
