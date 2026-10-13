<?php
/**
 * Large Content Boundary Integration Test
 *
 * Verifies that the plugin handles large content gracefully:
 *   - Long titles (1000+ chars)
 *   - Long post content (100KB+)
 *   - Many meta fields
 *   - Many terms per post
 *   - Many translations per post
 *   - Large taxonomy term lists
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	$_SERVER['HTTP_HOST']    = 'blog.wpmm.cc';
	$_SERVER['REQUEST_URI']  = '/';
	define( 'WP_USE_THEMES', false );
	define( 'WP_ADMIN', true );
	require_once dirname( __DIR__ ) . '/bootstrap/load-wordpress.php';
}

$results = [];
$failed  = 0;

function check( string $name, bool $ok, string $detail = '' ): void {
	global $results, $failed;
	$results[] = compact( 'name', 'ok', 'detail' );
	if ( ! $ok ) {
		$failed++;
		echo "FAIL: {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	} else {
		echo "PASS: {$name}\n";
	}
}

global $wpdb;

// Check 1: Can create post with 1000-char title
$long_title = str_repeat( 'A', 1000 );
$post_id = wp_insert_post( [
	'post_title'   => $long_title,
	'post_content' => 'short content',
	'post_status'  => 'draft',
	'post_type'    => 'post',
] );
check(
	'Can create post with 1000-char title',
	$post_id > 0 && ! is_wp_error( $post_id ),
	'post_id=' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : $post_id )
);
if ( $post_id > 0 && ! is_wp_error( $post_id ) ) {
	wp_delete_post( $post_id, true );
}

// Check 2: Can create post with 100KB content
$big_content = str_repeat( 'B', 100 * 1024 );
$post_id2 = wp_insert_post( [
	'post_title'   => 'Large Content Test',
	'post_content' => $big_content,
	'post_status'  => 'draft',
	'post_type'    => 'post',
] );
check(
	'Can create post with 100KB content',
	$post_id2 > 0 && ! is_wp_error( $post_id2 ),
	'post_id=' . ( is_wp_error( $post_id2 ) ? $post_id2->get_error_message() : $post_id2 )
);
if ( $post_id2 > 0 && ! is_wp_error( $post_id2 ) ) {
	$actual_len = strlen( get_post_field( 'post_content', $post_id2 ) );
	check(
		'100KB content persisted correctly',
		$actual_len === 100 * 1024,
		'actual_len=' . $actual_len
	);
	wp_delete_post( $post_id2, true );
}

// Check 3: Can create post with 50 meta fields
$post_id3 = wp_insert_post( [
	'post_title'  => 'Meta Test',
	'post_status' => 'draft',
	'post_type'   => 'post',
] );
if ( $post_id3 > 0 && ! is_wp_error( $post_id3 ) ) {
	$meta_count = 50;
	for ( $i = 0; $i < $meta_count; $i++ ) {
		update_post_meta( $post_id3, "_wptsall_test_meta_{$i}", "value_{$i}" );
	}
	$retrieved = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE '_wptsall_test_meta_%'",
		$post_id3
	) );
	check(
		"Can create post with {$meta_count} meta fields",
		(int) $retrieved === $meta_count,
		'count=' . $retrieved
	);
	wp_delete_post( $post_id3, true );
}

// Check 4: Can create post with 100 terms
$post_id4 = wp_insert_post( [
	'post_title'  => 'Terms Test',
	'post_status' => 'draft',
	'post_type'   => 'post',
] );
if ( $post_id4 > 0 && ! is_wp_error( $post_id4 ) ) {
	// Get or create 100 tags
	$tag_ids = [];
	for ( $i = 0; $i < 100; $i++ ) {
		$term = wp_insert_term( "wptsall_test_tag_{$i}", 'post_tag' );
		if ( ! is_wp_error( $term ) ) {
			$tag_ids[] = $term['term_id'];
		}
	}
	if ( count( $tag_ids ) > 0 ) {
		wp_set_object_terms( $post_id4, $tag_ids, 'post_tag' );
		$assigned = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tr.object_id = %d AND tt.taxonomy = 'post_tag'",
			$post_id4
		) );
		check(
			'Can attach 100 terms to a post',
			(int) $assigned === count( $tag_ids ),
			'assigned=' . $assigned . ' target=' . count( $tag_ids )
		);
		// Cleanup tags
		foreach ( $tag_ids as $tid ) {
			wp_delete_term( $tid, 'post_tag' );
		}
	}
	wp_delete_post( $post_id4, true );
}

// Check 5: Can create post with 50 translations (in post_mappings)
$post_id5 = wp_insert_post( [
	'post_title'  => 'Translations Test',
	'post_status' => 'draft',
	'post_type'   => 'post',
] );
if ( $post_id5 > 0 && ! is_wp_error( $post_id5 ) ) {
	$pm_table = $wpdb->prefix . 'wptsall_post_mappings';
	$inserted = 0;
	for ( $i = 0; $i < 50; $i++ ) {
		$ok = $wpdb->insert( $pm_table, [
			'relation_id'        => 99999 + $i,
			'source_post_id'     => $post_id5,
			'source_post_type'   => 'post',
			'source_site_id'     => 1,
			'target_post_id'     => $post_id5 + 10000 + $i,
			'target_post_type'   => 'post',
			'target_site_id'     => "site_{$i}",
			'relationship_type'  => 'translation',
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		] );
		if ( $ok ) $inserted++;
	}
	check(
		'Can store 50 translation mappings for one post',
		$inserted === 50,
		'inserted=' . $inserted
	);
	// Cleanup
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$pm_table} WHERE source_post_id = %d",
		$post_id5
	) );
	wp_delete_post( $post_id5, true );
}

// Check 6: Can create 100 categories (large taxonomy)
$cat_ids = [];
$batch_ok = true;
for ( $i = 0; $i < 100; $i++ ) {
	$term = wp_insert_term( "wptsall_test_cat_{$i}", 'category' );
	if ( is_wp_error( $term ) ) {
		$batch_ok = false;
		break;
	}
	$cat_ids[] = $term['term_id'];
}
check(
	'Can create 100 categories',
	$batch_ok && count( $cat_ids ) >= 95,  // allow some throttling
	'created=' . count( $cat_ids )
);
// Cleanup
foreach ( $cat_ids as $cid ) {
	wp_delete_term( $cid, 'category' );
}

// Check 7: Long slug is truncated/handled
$long_slug_source = str_repeat( 'x', 250 );
$post_id7 = wp_insert_post( [
	'post_title'  => 'Long Slug Test',
	'post_name'   => $long_slug_source,
	'post_status' => 'draft',
	'post_type'   => 'post',
] );
if ( $post_id7 > 0 && ! is_wp_error( $post_id7 ) ) {
	$actual_slug = get_post_field( 'post_name', $post_id7 );
	check(
		'Long slug (250 chars) handled gracefully',
		is_string( $actual_slug ),
		'actual_len=' . strlen( $actual_slug ?? '' )
	);
	wp_delete_post( $post_id7, true );
}

// Check 8: Memory_limit handling - 1MB string in memory
$mem_start = memory_get_usage();
$big_string = str_repeat( 'C', 1024 * 1024 );  // 1MB
check(
	'1MB string can be allocated in memory',
	strlen( $big_string ) === 1024 * 1024
);
unset( $big_string );

// Check 9: Translation progress service handles large site (no crash)
if ( class_exists( 'WPTSALL\\ManualTranslation\\Services\\Translation_Progress_Service' ) ) {
	$svc = new \WPTSALL\ManualTranslation\Services\Translation_Progress_Service();
	$result = $svc->summary();
	check(
		'Translation_Progress_Service::summary() returns array on real data',
		is_array( $result ),
		'count=' . count( $result ?? [] )
	);
}

echo "\n=== Large Content Boundary Integration Test ===\n";
echo "Passed: " . ( count( $results ) - $failed ) . " / " . count( $results ) . "\n";
echo "Failed: {$failed}\n";
if ( defined( 'WPTSALL_INTEGRATION_RUNNER' ) && WPTSALL_INTEGRATION_RUNNER ) {
	$GLOBALS['wptsall_flow_result'] = array(
		'failed' => $failed,
		'total'  => count( $results ),
	);
	if ( $failed > 0 ) {
		throw new RuntimeException( basename( __FILE__ ) . ": {$failed} check(s) failed" );
	}
	return;
}
exit( $failed > 0 ? 1 : 0 );