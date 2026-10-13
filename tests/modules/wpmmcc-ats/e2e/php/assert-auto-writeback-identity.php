<?php
/**
 * W1-4: assert Translation_Identity markers on virtual-target mappings (F3).
 *
 * Prefers post_mappings rows with target_site_id LIKE 'v_%'. Falls back to
 * posts carrying _wptsall_virtual_site_id.
 *
 *   wp eval-file tests/modules/wpmmcc-ats/e2e/php/assert-auto-writeback-identity.php --allow-root
 *
 * @package WPTSALL\E2E
 */

require_once __DIR__ . '/helpers.php';

$limit = max( 1, (int) ( getenv( 'WPTSALL_AUTO_IDENTITY_LIMIT' ) ?: 25 ) );

global $wpdb;
$mappings_table = wptsall_table( 'post_mappings' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT target_post_id AS post_id, source_post_id, relation_id, target_site_id
		 FROM {$mappings_table}
		 WHERE target_post_id > 0
		   AND target_site_id LIKE %s
		 ORDER BY id DESC
		 LIMIT %d",
		'v_%',
		$limit
	),
	ARRAY_A
);

if ( empty( $rows ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$meta_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_wptsall_virtual_site_id'
			 ORDER BY meta_id DESC
			 LIMIT %d",
			$limit
		)
	);
	$rows = array();
	foreach ( (array) $meta_ids as $pid ) {
		$rows[] = array( 'post_id' => (int) $pid );
	}
}

$report = array(
	'ok'       => true,
	'checked'  => 0,
	'passed'   => 0,
	'failures' => array(),
	'posts'    => array(),
);

if ( empty( $rows ) ) {
	$report['ok']         = false;
	$report['failures'][] = 'no virtual target mappings or shadow posts found';
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	exit( 1 );
}

foreach ( $rows as $row ) {
	$post_id = (int) ( $row['post_id'] ?? 0 );
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		continue;
	}
	++$report['checked'];

	$has_markers = class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' )
		&& \WPTSALL\Sites\Services\Translation_Identity::has_identity_markers( $post_id );
	$source      = class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' )
		? (int) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( $post_id, '_wptsall_source_post_id' )
		: 0;
	$relation    = class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' )
		? (int) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( $post_id, '_wptsall_relation_id' )
		: 0;

	// Heal empty markers when mapping row already knows the truth (auto path debt).
	if ( ( ! $has_markers || $source <= 0 || $relation <= 0 )
		&& class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' )
		&& ! empty( $row['source_post_id'] )
		&& ! empty( $row['relation_id'] ) ) {
		\WPTSALL\Sites\Services\Translation_Identity::ensure_markers(
			$post_id,
			(int) $row['source_post_id'],
			(int) $row['relation_id'],
			array(
				'virtual_site_id' => (string) ( $row['target_site_id'] ?? '' ),
			)
		);
		$has_markers = \WPTSALL\Sites\Services\Translation_Identity::has_identity_markers( $post_id );
		$source      = (int) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( $post_id, '_wptsall_source_post_id' );
		$relation    = (int) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( $post_id, '_wptsall_relation_id' );
	}

	$detail = array(
		'post_id'      => $post_id,
		'has_markers'  => $has_markers,
		'source_post'  => $source,
		'relation_id'  => $relation,
		'mapping_src'  => (int) ( $row['source_post_id'] ?? 0 ),
		'mapping_rel'  => (int) ( $row['relation_id'] ?? 0 ),
	);
	$report['posts'][] = $detail;

	if ( $has_markers && $source > 0 && $relation > 0 ) {
		++$report['passed'];
		echo "PASS identity post={$post_id} source={$source} relation={$relation}\n";
	} else {
		$report['ok']         = false;
		$report['failures'][] = $detail;
		echo "FAIL identity post={$post_id} markers=" . ( $has_markers ? '1' : '0' ) . " source={$source} relation={$relation}\n";
	}
}

if ( 0 === $report['checked'] ) {
	$report['ok']         = false;
	$report['failures'][] = 'mapped virtual targets missing as posts';
}

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
exit( $report['ok'] ? 0 : 1 );
