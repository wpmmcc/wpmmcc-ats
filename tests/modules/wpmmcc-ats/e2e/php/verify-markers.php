<?php
/**
 * E2E v2 翻译标记精度检查
 *
 * 验证翻译标记只出现在展示字段（post_title, post_content, post_excerpt），
 * 不出现在功能字段（post_name, guid, _thumbnail_id）。
 *
 * 提取+增强自 testing/verify-client-e2e.php checks 5,7。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-markers.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Translation Marker Precision ===\n\n";

$passed  = 0;
$failed  = 0;
$checks  = array();

$relation_ids = e2e_load_relation_ids();

function e2e_marker_sql_for_column( string $column ): string {
	return "({$column} LIKE '%\xE3\x80\x90%' OR {$column} LIKE '%\xE3\x80\x91%' OR LOWER({$column}) LIKE '%e3%80%90%' OR LOWER({$column}) LIKE '%e3%80%91%')";
}

// ==================================================================
// Check 1: Virtual site — functional fields clean
// ==================================================================
echo "--- Check 1: Virtual site functional fields ---\n";

$vs_bad_postname = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wptsall_virtual_site_id'
	 WHERE " . e2e_marker_sql_for_column( 'p.post_name' )
);

$vs_bad_guid = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wptsall_virtual_site_id'
	 WHERE " . e2e_marker_sql_for_column( 'p.guid' )
);

e2e_check(
	'Virtual: post_name clean',
	$vs_bad_postname === 0,
	$vs_bad_postname === 0 ? 'clean' : "$vs_bad_postname bad",
	$checks, $passed, $failed
);

e2e_check(
	'Virtual: guid clean',
	$vs_bad_guid === 0,
	$vs_bad_guid === 0 ? 'clean' : "$vs_bad_guid bad",
	$checks, $passed, $failed
);

// ==================================================================
// Check 2: WP target blog — functional fields clean
// ==================================================================
echo "\n--- Check 2: WP target blog functional fields ---\n";

$wp_blog_id = e2e_get_wp_target_blog_id( $relation_ids );
if ( $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
	switch_to_blog( $wp_blog_id );

	$b3_bad_postname = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE " . e2e_marker_sql_for_column( 'post_name' ) . "
		 AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id'))"
	);

	$b3_bad_guid = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE " . e2e_marker_sql_for_column( 'guid' ) . "
		 AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id'))"
	);

	e2e_check( "Blog {$wp_blog_id}: post_name clean", $b3_bad_postname === 0, $b3_bad_postname === 0 ? 'clean' : "$b3_bad_postname bad", $checks, $passed, $failed );
	e2e_check( "Blog {$wp_blog_id}: guid clean", $b3_bad_guid === 0, $b3_bad_guid === 0 ? 'clean' : "$b3_bad_guid bad", $checks, $passed, $failed );

	// Check _thumbnail_id is numeric
	$bad_thumbnail = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_thumbnail_id'
		 AND (meta_value LIKE '%\xE3\x80\x90%' OR meta_value REGEXP '[^0-9]')
		 AND post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id'))"
	);

	e2e_check( "Blog {$wp_blog_id}: _thumbnail_id clean", $bad_thumbnail === 0, $bad_thumbnail === 0 ? 'clean' : "$bad_thumbnail bad", $checks, $passed, $failed );

	restore_current_blog();
} else {
	echo "  Skipped (not multisite or WP target blog not found).\n";
}

// ==================================================================
// Check 3: Taxonomy slugs — no markers
// ==================================================================
echo "\n--- Check 3: Taxonomy slugs ---\n";

$bad_term_slugs = 0;
$term_scope     = 'current mapped target terms';
$term_mappings  = e2e_table( 'term_mappings' );

if ( e2e_table_exists( $term_mappings ) ) {
	$virtual_target_site_id = '';
	$virtual_relation_id    = (int) ( $relation_ids['virtual'] ?? 0 );
	if ( $virtual_relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
		$virtual_relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $virtual_relation_id );
		if ( is_array( $virtual_relation ) ) {
			$virtual_target_site_id = (string) ( $virtual_relation['target_site_id'] ?? '' );
		}
	}

	$where = 'tm.target_term_id > 0';
	$args  = array();
	if ( '' !== $virtual_target_site_id ) {
		$where .= ' AND tm.target_site_id = %s';
		$args[] = $virtual_target_site_id;
		$term_scope = 'current virtual mapped target terms';
	}

	$sql = "SELECT COUNT(*)
	        FROM {$wpdb->terms} t
	        INNER JOIN {$term_mappings} tm ON tm.target_term_id = t.term_id
	        WHERE {$where} AND " . e2e_marker_sql_for_column( 't.slug' );
	$bad_term_slugs = (int) ( empty( $args ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) );
} else {
	$term_scope = 'term_mappings table unavailable';
}

e2e_check(
	'Taxonomy slugs clean',
	$bad_term_slugs === 0,
	$bad_term_slugs === 0 ? "clean ({$term_scope})" : "{$bad_term_slugs} bad ({$term_scope})",
	$checks, $passed, $failed
);

// ==================================================================
// Check 4: post_author integrity
// ==================================================================
echo "\n--- Check 4: post_author integrity ---\n";

if ( $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
	switch_to_blog( $wp_blog_id );

	$synced_posts = $wpdb->get_results(
		"SELECT p.ID, p.post_author FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
		 WHERE pm.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
		 AND p.post_type NOT IN ('revision', 'nav_menu_item')
		 LIMIT 50",
		ARRAY_A
	);

	$bad_authors = 0;
	foreach ( $synced_posts as $sp ) {
		$author_id = intval( $sp['post_author'] );
		if ( $author_id <= 0 || ! get_userdata( $author_id ) ) {
			++$bad_authors;
		}
	}

	e2e_check(
		"Blog {$wp_blog_id}: post_author valid",
		$bad_authors === 0,
		$bad_authors === 0 ? count( $synced_posts ) . ' checked' : "$bad_authors invalid",
		$checks, $passed, $failed
	);

	restore_current_blog();
}

// ==================================================================
// Check 5: Self translation — functional fields
// ==================================================================
echo "\n--- Check 5: Self translation functional fields ---\n";

$self_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_self_translation'"
);

if ( $self_count > 0 ) {
	$self_bad_slug = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wptsall_self_translation'
		 WHERE " . e2e_marker_sql_for_column( 'p.post_name' )
	);

	e2e_check( 'Self: post_name clean', $self_bad_slug === 0, $self_bad_slug === 0 ? 'clean' : "$self_bad_slug bad", $checks, $passed, $failed );
} else {
	echo "  No self-translation content, skipped.\n";
}

// ==================================================================
// 结果
// ==================================================================
e2e_print_results( $checks, $passed, $failed );

if ( $failed > 0 ) {
	exit( 1 );
}
