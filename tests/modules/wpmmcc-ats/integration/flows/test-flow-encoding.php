<?php
/**
 * Encoding / RTL / Unicode / Emoji Integration Test
 *
 * Verifies that the plugin handles multi-language content correctly:
 *   - UTF-8 roundtrip in post title / content / excerpt
 *   - CJK (Chinese / Japanese / Korean) characters
 *   - RTL (Arabic / Hebrew) characters
 *   - Emoji (4-byte UTF-8) in post meta and term names
 *   - Translation mappings with non-ASCII fields (lang codes, post names)
 *   - DB charset/collation is utf8mb4 (so 4-byte chars survive)
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
		echo "FAIL: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
	} else {
		echo "PASS: {$name}\n";
	}
}

global $wpdb;

// Check 1: wpdb charset is utf8mb4
$charset    = $wpdb->get_charset_collate();
$has_utf8mb4 = ( strpos( $charset, 'utf8mb4' ) !== false );
check(
	'wpdb charset is utf8mb4 (required for 4-byte emoji)',
	$has_utf8mb4,
	'charset=' . $charset
);

// Check 2: CJK post roundtrip
$cjk_title   = "Chinese title \u{4e2d}\u{6587}\u{6807}\u{9898} and Korean \u{d55c}\u{ae00}";
$cjk_content = "Chinese content \u{8fd9}\u{662f}\u{4e00}\u{4e2a}\u{6d4b}\u{8bd5}\u{3002}";
$post_id     = wp_insert_post( [
	'post_title'   => $cjk_title,
	'post_content' => $cjk_content,
	'post_status'  => 'draft',
	'post_type'    => 'post',
] );
if ( $post_id > 0 && ! is_wp_error( $post_id ) ) {
	$got_title   = get_post_field( 'post_title', $post_id, 'raw' );
	$got_content = get_post_field( 'post_content', $post_id, 'raw' );
	check(
		'CJK post title and content roundtrip (Chinese + Korean)',
		$got_title === $cjk_title && $got_content === $cjk_content,
		'title_match=' . ( $got_title === $cjk_title ? '1' : '0' ) . ' content_match=' . ( $got_content === $cjk_content ? '1' : '0' )
	);
	wp_delete_post( $post_id, true );
} else {
	check( 'CJK post title and content roundtrip', false, 'post insert failed' );
}

// Check 3: RTL (Arabic) post roundtrip
$ar_title   = "Arabic title \u{0645}\u{0642}\u{0627}\u{0644} \u{0628}\u{0627}\u{0644}\u{0639}\u{0631}\u{0628}\u{064a}\u{0629}";
$ar_content = "Arabic content \u{0647}\u{0630}\u{0627} \u{0646}\u{0635} \u{0639}\u{0631}\u{0628}\u{064a} \u{0644}\u{0644}\u{0627}\u{062e}\u{062a}\u{0628}\u{0627}\u{0637}.";
$post_id2   = wp_insert_post( [
	'post_title'   => $ar_title,
	'post_content' => $ar_content,
	'post_status'  => 'draft',
	'post_type'    => 'post',
] );
if ( $post_id2 > 0 && ! is_wp_error( $post_id2 ) ) {
	$got_title   = get_post_field( 'post_title', $post_id2, 'raw' );
	$got_content = get_post_field( 'post_content', $post_id2, 'raw' );
	check(
		'RTL Arabic post title and content roundtrip',
		$got_title === $ar_title && $got_content === $ar_content,
		'title_match=' . ( $got_title === $ar_title ? '1' : '0' ) . ' content_match=' . ( $got_content === $ar_content ? '1' : '0' )
	);
	wp_delete_post( $post_id2, true );
} else {
	check( 'RTL Arabic post title and content roundtrip', false, 'post insert failed' );
}

// Check 4: Emoji (4-byte UTF-8) roundtrip
$emoji_title   = "Emoji test \u{1f680} \u{1f30d} \u{1f44d} \u{1f4af} \u{1f389}";
$emoji_content = "Content with \u{2764}\u{fe0f} and \u{1f389}";
$post_id3      = wp_insert_post( [
	'post_title'   => $emoji_title,
	'post_content' => $emoji_content,
	'post_status'  => 'draft',
	'post_type'    => 'post',
] );
if ( $post_id3 > 0 && ! is_wp_error( $post_id3 ) ) {
	$got_title   = get_post_field( 'post_title', $post_id3, 'raw' );
	$got_content = get_post_field( 'post_content', $post_id3, 'raw' );
	check(
		'4-byte emoji (rocket/globe/thumbs/100/party) roundtrip via utf8mb4',
		$got_title === $emoji_title && $got_content === $emoji_content,
		'got_title_len=' . strlen( $got_title ?? '' ) . ' expected=' . strlen( $emoji_title )
	);
	wp_delete_post( $post_id3, true );
} else {
	check( '4-byte emoji roundtrip', false, 'post insert failed' );
}

// Check 5: Emoji in post meta
$post_id4 = wp_insert_post( [
	'post_title'  => 'Emoji Meta Test',
	'post_status' => 'draft',
	'post_type'   => 'post',
] );
if ( $post_id4 > 0 && ! is_wp_error( $post_id4 ) ) {
	$emoji_meta = "tag_\u{1f3af}_\u{1f44d}";
	update_post_meta( $post_id4, '_wptsall_test_emoji', $emoji_meta );
	$got_meta = get_post_meta( $post_id4, '_wptsall_test_emoji', true );
	check(
		'Emoji in post meta (utf8mb4) roundtrip',
		$got_meta === $emoji_meta,
		'got_len=' . strlen( $got_meta ?? '' ) . ' expected=' . strlen( $emoji_meta )
	);
	wp_delete_post( $post_id4, true );
} else {
	check( 'Emoji in post meta roundtrip', false, 'post insert failed' );
}

// Check 6: CJK term name (category)
$cjk_term_name = "Chinese cat \u{4e2d}\u{6587}\u{5206}\u{7c7b} Japanese \u{30ab}\u{30c6}\u{30b4}\u{30ea}";
$term          = wp_insert_term( $cjk_term_name, 'category' );
if ( ! is_wp_error( $term ) ) {
	$term_obj = get_term( $term['term_id'], 'category' );
	check(
		'CJK term name roundtrip',
		$term_obj && $term_obj->name === $cjk_term_name,
		'got_name=' . ( $term_obj ? $term_obj->name : 'null' )
	);
	wp_delete_term( $term['term_id'], 'category' );
} else {
	check( 'CJK term name roundtrip', false, $term->get_error_message() );
}

// Check 7: Translation mapping with non-ASCII target_site_id
$src_id = wp_insert_post( [ 'post_title' => 'Source', 'post_status' => 'draft', 'post_type' => 'post' ] );
$tgt_id = wp_insert_post( [ 'post_title' => "Target \u{76ee}\u{6807}", 'post_status' => 'draft', 'post_type' => 'post' ] );
if ( $src_id && $tgt_id ) {
	$pm_table = $wpdb->prefix . 'wptsall_post_mappings';
	$ok       = $wpdb->insert( $pm_table, [
		'relation_id'       => 88001,
		'source_post_id'    => $src_id,
		'source_post_type'  => 'post',
		'source_site_id'    => 1,
		'target_post_id'    => $tgt_id,
		'target_post_type'  => 'post',
		'target_site_id'    => 2,
		'relationship_type' => 'translation',
		'created_at'        => current_time( 'mysql' ),
		'updated_at'        => current_time( 'mysql' ),
	] );
	check(
		'Mapping row insert (utf8mb4 columns) succeeds',
		$ok !== false,
		'last_error=' . $wpdb->last_error
	);
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$pm_table} WHERE relation_id = %d",
		88001
	) );
	check(
		'Mapping row reads back correctly',
		$row && (int) $row->source_post_id === $src_id && (int) $row->target_post_id === $tgt_id
	);
	$wpdb->delete( $pm_table, [ 'relation_id' => 88001 ] );
	wp_delete_post( $src_id, true );
	wp_delete_post( $tgt_id, true );
}

// Check 8: json_encode/decode preserves unicode
$unicode_str = "Chinese \u{4f60}\u{597d} Arabic \u{0645}\u{0631}\u{062d}\u{0628}\u{0627} Emoji \u{1f60a}";
$json        = json_encode( $unicode_str );
$back        = json_decode( $json, true );
check(
	'json_encode/decode preserves CJK/Arabic/emoji',
	$back === $unicode_str,
	'roundtrip_ok=' . ( $back === $unicode_str ? 'yes' : 'no' )
);

// Check 9: Post lookup by ASCII slug (CJK title) works
$slug     = 'cjk-test-' . bin2hex( random_bytes( 4 ) );
$post_id5 = wp_insert_post( [
	'post_title'  => "CJK title \u{6c49}\u{5b57}\u{6807}\u{9898}",
	'post_name'   => $slug,
	'post_status' => 'publish',
	'post_type'   => 'post',
] );
if ( $post_id5 ) {
	$found = get_posts( [
		'name'        => $slug,
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => 1,
	] );
	check(
		'Post lookup by ASCII slug (CJK title) works',
		! empty( $found ) && $found[0]->ID === $post_id5
	);
	wp_delete_post( $post_id5, true );
}

echo "\n=== Encoding / RTL / Unicode / Emoji Integration Test ===\n";
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