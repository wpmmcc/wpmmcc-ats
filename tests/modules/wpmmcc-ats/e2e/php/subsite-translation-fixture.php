<?php
/**
 * T1 subsite translation gate fixture (2026-09-09 audit gap ①).
 *
 * Deterministic closed-loop setup for a wp-type site relation (real subsite
 * target). Replaces incidental coverage that previously relied on stale lab
 * tasks: this fixture creates exactly the tasks the lane verifies.
 *
 *   1. Preconditions: multisite, subsite blog > 1, active wp relation.
 *   2. Sweep stale pending/retry tasks to 'cancelled' (audit gap ④: a
 *      bounded run-once must only ever claim this lane's tasks; swept
 *      counts are recorded so backlog drift stays visible).
 *   3. Insert 2 unique source posts on blog 1.
 *   4. Create one translation task per post via internal REST create_task
 *      and verify the task row is pending on the wp relation with the
 *      subsite as target_blog.
 *
 * Emits machine-readable "SUBSITE-FIXTURE-JSON: <json>". The same JSON is
 * written to /tmp/wptsall-subsite-gate-fixture.json inside the WP container
 * for the verify stage; the gate script also parses stdout.
 *
 * Env: WPTSALL_T1_BLOG_ID (optional; defaults to first blog_id > 1).
 *
 * Run: wp eval-file subsite-translation-fixture.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

/**
 * Emit a failure line and exit non-zero.
 *
 * @param string $code Machine-readable failure code.
 * @param string $detail Human context.
 */
$subsite_fail = function ( string $code, string $detail = '' ): void {
	echo 'SUBSITE-FIXTURE-FAIL: ' . $code . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
	echo wp_json_encode( array( 'error' => $code, 'detail' => $detail ) ) . "\n";
	exit( 1 );
};

if ( ! is_multisite() ) {
	$subsite_fail( 'not_multisite', 'run scripts/lab-enable-multisite-t1.sh first' );
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
	$subsite_fail( 'no_t1_blog', 'no subsite (blog_id > 1) found' );
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
	$subsite_fail( "no_active_wp_relation_for_blog_{$blog_id}", 'run php/setup-relations.php after multisite enable' );
}

$relation_id = (int) $relation['id'];
$template    = (string) ( $relation['template'] ?? 'wordpress-blog' );
$target_lang = (string) ( $relation['target_lang'] ?? 'en_US' );

// Sweep stale pending/retry tasks so the owned worker's bounded run-once
// only ever claims this lane's tasks (the lab carries hundreds of stale
// rows; leaving them would make the closed loop non-deterministic).
$tasks_table  = function_exists( 'wptsall_table' ) ? wptsall_table( 'tasks' ) : e2e_table( 'tasks' );
$swept_pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_table} WHERE status = %s", 'pending' ) );
$swept_retry   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_table} WHERE status = %s", 'retry' ) );
if ( $swept_pending + $swept_retry > 0 ) {
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tasks_table} SET status = %s, updated_at = %s WHERE status IN ('pending','retry')",
			'cancelled',
			current_time( 'mysql' )
		)
	);
}

// Admin context for internal REST create_task.
wp_set_current_user( 1 );

$stamp       = gmdate( 'Ymd-His' );
$post_ids    = array();

foreach ( array( 'A', 'B' ) as $suffix ) {
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_author'  => 1,
			'post_title'   => "Subsite Translation Gate {$stamp} {$suffix}",
			'post_name'    => "subsite-gate-{$stamp}-" . strtolower( $suffix ),
			'post_content' => "<p>Subsite translation gate source {$suffix} at {$stamp}. This paragraph carries enough words for a realistic provider request payload in the automatic lane.</p>\n<p>Second paragraph keeps the request body realistic for provider round trips and subsite writeback verification.</p>",
			'post_excerpt' => "Subsite gate {$suffix} {$stamp}",
		),
		true
	);
	if ( is_wp_error( $post_id ) || (int) $post_id <= 0 ) {
		$msg = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insert failed';
		$subsite_fail( "source_post_insert_failed_{$suffix}", $msg );
	}
	$post_ids[ $suffix ] = (int) $post_id;
}

$posts = array();
foreach ( $post_ids as $suffix => $post_id ) {
	$request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body_params(
		array(
			'template'    => $template,
			'site_id'     => $relation_id,
			'object_type' => 'post',
			'subtype'     => 'post',
			'object_id'   => $post_id,
		)
	);
	$response = rest_do_request( $request );
	$status   = (int) $response->get_status();
	$data     = $response->get_data();
	if ( ! in_array( $status, array( 200, 201 ), true ) || empty( $data['created'] ) ) {
		// Self-diagnosing failure: REST error explains whether the mapping
		// slug, relation binding or post type config is missing.
		$subsite_fail(
			"create_task_failed_{$suffix}",
			'HTTP ' . $status . ' ' . wp_json_encode( $data ) . " (template={$template} relation=#{$relation_id})"
		);
	}

	$task_id = (int) ( is_array( $data ) ? ( $data['task_id'] ?? 0 ) : 0 );
	if ( $task_id <= 0 ) {
		$subsite_fail( "create_task_no_task_id_{$suffix}", wp_json_encode( $data ) );
	}
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $task_id ),
		ARRAY_A
	);
	if ( ! $row ) {
		$subsite_fail( "task_row_missing_{$suffix}", "task_id={$task_id}" );
	}
	if ( (int) $row['relation_id'] !== $relation_id ) {
		$subsite_fail( "task_relation_mismatch_{$suffix}", 'row relation_id=' . (int) $row['relation_id'] . " expected={$relation_id}" );
	}
	if ( 'pending' !== (string) $row['status'] ) {
		$subsite_fail( "task_not_pending_{$suffix}", 'status=' . (string) $row['status'] );
	}
	if ( (int) ( $row['target_blog'] ?? 0 ) !== $blog_id ) {
		$subsite_fail( "task_target_blog_mismatch_{$suffix}", 'row target_blog=' . (int) ( $row['target_blog'] ?? 0 ) . " expected subsite={$blog_id}" );
	}

	$posts[] = array(
		'suffix'  => $suffix,
		'post_id' => $post_id,
		'task_id' => $task_id,
	);
}

$payload = array(
	'stamp'       => $stamp,
	'relation_id' => $relation_id,
	'blog_id'     => $blog_id,
	'template'    => $template,
	'target_lang' => $target_lang,
	'swept'       => array(
		'pending' => $swept_pending,
		'retry'   => $swept_retry,
	),
	'posts'       => $posts,
);

$json = wp_json_encode( $payload );
if ( false === $json ) {
	$subsite_fail( 'fixture_json_encode_failed', '' );
}
if ( false === file_put_contents( '/tmp/wptsall-subsite-gate-fixture.json', $json ) ) {
	$subsite_fail( 'fixture_state_write_failed', '/tmp not writable in container' );
}

echo 'SUBSITE-FIXTURE-JSON: ' . $json . "\n";
exit( 0 );
