<?php
/**
 * Manual subsite translation writeback test (2026-09-09 audit gap ②).
 *
 * The manual matrix deliberately covers a single virtual site; manual
 * translation against a wp-type relation (real subsite target) had ZERO
 * coverage. This script closes that gap:
 *
 *   1. Preconditions: multisite, subsite blog > 1, active wp relation.
 *   2. Insert one unique source post on blog 1.
 *   3. Save a manual translation via internal REST
 *      POST /wptsall/v2/manual-translations with the wp relation.
 *   4. Assert the writeback landed ON THE SUBSITE (switch_to_blog):
 *      target exists, title/content saved, identity meta
 *      _wptsall_source_post_id + _wptsall_relation_id present.
 *   5. Assert a post_mappings row exists for (relation, source, subsite).
 *   6. Negative control: the manual path must NOT create tasks rows
 *      (manual translation is not automatic-translation work).
 *
 * Emits "MANUAL-SUBSITE-JSON: <json>" including the subsite permalink for
 * the gate script's front-end HTTP check.
 *
 * Env: WPTSALL_T1_BLOG_ID (optional; defaults to first blog_id > 1).
 *
 * Run: wp eval-file manual-subsite-writeback.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

/**
 * Emit a failure line and exit non-zero.
 *
 * @param string $code Machine-readable failure code.
 * @param string $detail Human context.
 */
$manual_subsite_fail = function ( string $code, string $detail = '' ): void {
	echo 'MANUAL-SUBSITE-FAIL: ' . $code . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
	exit( 1 );
};

if ( ! is_multisite() ) {
	$manual_subsite_fail( 'not_multisite', 'run scripts/lab-enable-multisite-t1.sh first' );
}

$blog_id = (int) ( getenv( 'WPTSALL_T1_BLOG_ID' ) ?: 0 );
if ( $blog_id <= 1 ) {
	foreach ( get_sites( array( 'number' => 20, 'orderby' => 'id', 'order' => 'ASC' ) ) as $site ) {
		$id = (int) $site->blog_id;
		if ( $id > 1 ) {
			$blog_id = $id;
			break;
		}
	}
}
if ( $blog_id <= 1 ) {
	$manual_subsite_fail( 'no_t1_blog', 'no subsite (blog_id > 1) found' );
}

$rel_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : e2e_table( 'site_relations' );

$relation = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$rel_table} WHERE target_site_type = %s AND target_site_id = %s AND status = %s ORDER BY id ASC LIMIT 1",
		'wp',
		(string) $blog_id,
		'active'
	),
	ARRAY_A
);
if ( ! $relation ) {
	$manual_subsite_fail( "no_active_wp_relation_for_blog_{$blog_id}", 'run php/setup-relations.php after multisite enable' );
}
$relation_id = (int) $relation['id'];

// Admin context for the internal REST save.
wp_set_current_user( 1 );

$stamp   = gmdate( 'Ymd-His' );
$title   = "Manual Subsite Writeback {$stamp}";
$content = "<p>Manual subsite writeback source at {$stamp}. Saved through the manual translation REST surface against a wp-type relation.</p>\n<p>Second paragraph keeps the payload realistic.</p>";

$source_post_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_author'  => 1,
		'post_title'   => "Manual Subsite Source {$stamp}",
		'post_name'    => "manual-subsite-source-{$stamp}",
		'post_content' => $content,
		'post_excerpt' => "Manual subsite source {$stamp}",
	),
	true
);
if ( is_wp_error( $source_post_id ) || (int) $source_post_id <= 0 ) {
	$msg = is_wp_error( $source_post_id ) ? $source_post_id->get_error_message() : 'insert failed';
	$manual_subsite_fail( 'source_post_insert_failed', $msg );
}
$source_post_id = (int) $source_post_id;

$request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-translations' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body_params(
	array(
		'source_post_id'  => $source_post_id,
		'relation_id'     => $relation_id,
		'translated_data' => array(
			'post_title'   => $title,
			'post_name'    => "manual-subsite-target-{$stamp}",
			'post_content' => $content,
			'post_excerpt' => "Manual subsite target {$stamp}",
		),
	)
);
$response  = rest_do_request( $request );
$status    = (int) $response->get_status();
$data      = $response->get_data();
$target_id = (int) ( is_array( $data ) ? ( $data['target_id'] ?? 0 ) : 0 );

if ( ! in_array( $status, array( 200, 201 ), true ) || $target_id <= 0 ) {
	$manual_subsite_fail(
		'manual_save_failed',
		'HTTP ' . $status . ' ' . wp_json_encode( $data ) . " (relation #{$relation_id} source #{$source_post_id})"
	);
}

$checks = array();
$passed = 0;
$failed = 0;

// Target must live ON THE SUBSITE. A manual save that ignored the wp target
// and wrote to blog 1 fails here — exactly the product gap this lane hunts.
switch_to_blog( $blog_id );
$target = get_post( $target_id );
e2e_check(
	'target_on_subsite',
	(bool) $target,
	(bool) $target
		? "target #{$target_id} exists on blog {$blog_id}"
		: "target #{$target_id} NOT found on blog {$blog_id} (manual writeback missed the subsite?)",
	$checks,
	$passed,
	$failed
);

$permalink = '';
if ( $target ) {
	e2e_check( 'target_title', (string) $target->post_title === $title, 'title: ' . substr( (string) $target->post_title, 0, 80 ), $checks, $passed, $failed );
	e2e_check(
		'target_content',
		false !== strpos( (string) $target->post_content, 'Manual subsite writeback source' ),
		'content saved: ' . substr( (string) $target->post_content, 0, 60 ),
		$checks,
		$passed,
		$failed
	);
	e2e_check( 'target_published', 'publish' === (string) $target->post_status, 'status=' . (string) $target->post_status, $checks, $passed, $failed );

	// Identity meta via SQL (plugin meta filters bypassed).
	$meta_source = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
			$wpdb->postmeta,
			$target_id,
			'_wptsall_source_post_id'
		)
	);
	$meta_relation = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
			$wpdb->postmeta,
			$target_id,
			'_wptsall_relation_id'
		)
	);
	e2e_check( 'identity_source_meta', $meta_source === $source_post_id, "_wptsall_source_post_id={$meta_source} expected={$source_post_id}", $checks, $passed, $failed );
	e2e_check( 'identity_relation_meta', $meta_relation === $relation_id, "_wptsall_relation_id={$meta_relation} expected={$relation_id}", $checks, $passed, $failed );

	$permalink = (string) get_permalink( $target_id );
}
restore_current_blog();

// Mapping row for the wp relation.
$pm_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : e2e_table( 'post_mappings' );
$mapping  = $wpdb->get_row(
	$wpdb->prepare(
		'SELECT * FROM %i WHERE relation_id = %d AND source_post_id = %d AND target_site_id = %s ORDER BY id DESC LIMIT 1',
		$pm_table,
		$relation_id,
		$source_post_id,
		(string) $blog_id
	),
	ARRAY_A
);
e2e_check(
	'mapping_row',
	is_array( $mapping ) && (int) $mapping['target_post_id'] === $target_id,
	is_array( $mapping )
		? "mapping #{$mapping['id']} target={$mapping['target_post_id']} target_site_id={$mapping['target_site_id']}"
		: 'no post_mappings row',
	$checks,
	$passed,
	$failed
);

// Negative control: the manual path must not enqueue automatic tasks.
$tasks_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'tasks' ) : e2e_table( 'tasks' );
$task_count  = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$tasks_table} WHERE relation_id = %d AND object_id = %d",
		$relation_id,
		$source_post_id
	)
);
e2e_check(
	'no_task_created',
	0 === $task_count,
	"tasks for (relation #{$relation_id}, object #{$source_post_id}): {$task_count} (manual path must not create tasks)",
	$checks,
	$passed,
	$failed
);

$summary = array(
	'relation_id'    => $relation_id,
	'blog_id'        => $blog_id,
	'source_post_id' => $source_post_id,
	'target_post_id' => $target_id,
	'permalink'      => $permalink,
	'passed'         => $passed,
	'failed'         => $failed,
	'checks'         => $checks,
);

echo 'MANUAL-SUBSITE-JSON: ' . wp_json_encode( $summary ) . "\n";
if ( $failed > 0 ) {
	echo "FAIL: {$failed} manual subsite writeback check(s) failed\n";
	exit( 1 );
}
echo "PASS: {$passed} manual subsite writeback checks passed\n";
exit( 0 );
