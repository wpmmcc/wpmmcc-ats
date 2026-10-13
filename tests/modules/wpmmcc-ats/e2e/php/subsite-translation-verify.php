<?php
/**
 * T1 subsite translation gate verifier (2026-09-09 audit gaps ① + ④).
 *
 * Reads /tmp/wptsall-subsite-gate-fixture.json (written by
 * subsite-translation-fixture.php inside the WP container) and asserts the
 * full automatic closed loop for every fixture post:
 *
 *   - task row reached status 'completed'
 *   - translation_results row exists for (relation, object) with synced_at
 *   - post_mappings row exists (relation + source + target + target_site_id
 *     = subsite blog)
 *   - target post EXISTS ON THE SUBSITE (switch_to_blog; catches writebacks
 *     that wrongly landed on blog 1), is published, carries 【xx_XX】
 *     translation markers in title and content, and keeps identity meta
 *     _wptsall_source_post_id on the subsite
 *   - no stuck pending/retry task remains for a fixture object (audit gap
 *     ④); relation-wide backlog counts are reported as info because claim
 *     auto-discovery regenerates background tasks on the shared lab
 *
 * Emits "SUBSITE-VERIFY-JSON: <json>" including subsite permalinks (for the
 * gate script's front-end HTTP checks) and per-check results.
 *
 * Run: wp eval-file subsite-translation-verify.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

/**
 * Emit a failure line and exit non-zero.
 *
 * @param string $code Machine-readable failure code.
 * @param string $detail Human context.
 */
$subsite_verify_fail = function ( string $code, string $detail = '' ): void {
	echo 'SUBSITE-VERIFY-FAIL: ' . $code . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
	exit( 1 );
};

$raw = file_get_contents( '/tmp/wptsall-subsite-gate-fixture.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
if ( false === $raw || '' === trim( (string) $raw ) ) {
	$subsite_verify_fail( 'fixture_state_missing', 'run subsite-translation-fixture.php first' );
}
$fx = json_decode( (string) $raw, true );
if ( ! is_array( $fx ) || empty( $fx['posts'] ) || (int) ( $fx['relation_id'] ?? 0 ) <= 0 ) {
	$subsite_verify_fail( 'fixture_state_invalid', substr( (string) $raw, 0, 200 ) );
}

$relation_id = (int) $fx['relation_id'];
$blog_id     = (int) ( $fx['blog_id'] ?? 0 );
if ( $blog_id <= 1 ) {
	$subsite_verify_fail( 'fixture_blog_invalid', "blog_id={$blog_id}" );
}

$tasks_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'tasks' ) : e2e_table( 'tasks' );
$tr_table    = function_exists( 'wptsall_table' ) ? wptsall_table( 'translation_results' ) : e2e_table( 'translation_results' );
$pm_table    = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : e2e_table( 'post_mappings' );

$checks = array();
$passed = 0;
$failed = 0;
$permalinks = array();

foreach ( (array) $fx['posts'] as $post ) {
	$suffix   = (string) ( $post['suffix'] ?? '?' );
	$post_id  = (int) ( $post['post_id'] ?? 0 );
	$task_id  = (int) ( $post['task_id'] ?? 0 );
	$prefix   = "post_{$suffix}";

	if ( $post_id <= 0 || $task_id <= 0 ) {
		e2e_check( "{$prefix}_fixture_ids", false, 'missing post/task id in fixture state', $checks, $passed, $failed );
		continue;
	}

	// Task terminal state (per fixture object; background auto-discovered
	// tasks for other objects are reported relation-wide below).
	$task_status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tasks_table} WHERE id = %d", $task_id ) );
	e2e_check(
		"{$prefix}_task_completed",
		'completed' === $task_status,
		"task #{$task_id} status={$task_status}",
		$checks,
		$passed,
		$failed
	);

	// Audit gap ④: no stuck pending/retry task may remain for a fixture
	// object (WP claim-time auto-discovery regenerates background tasks for
	// OTHER objects; those are surfaced as backlog info below, not failed).
	$stuck_object = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tasks_table} WHERE relation_id = %d AND object_id = %d AND status IN ('pending','retry')",
			$relation_id,
			$post_id
		)
	);
	e2e_check(
		"{$prefix}_no_stuck_task",
		0 === $stuck_object,
		"pending/retry tasks for object #{$post_id}: {$stuck_object}",
		$checks,
		$passed,
		$failed
	);

	// Provider result recorded + synced back.
	$tr_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tr_table} WHERE relation_id = %d AND object_id = %d AND synced_at IS NOT NULL",
			$relation_id,
			$post_id
		)
	);
	e2e_check(
		"{$prefix}_translation_result_synced",
		$tr_count > 0,
		"translation_results rows synced for object #{$post_id}: {$tr_count}",
		$checks,
		$passed,
		$failed
	);

	// Mapping row points at the subsite.
	$mapping = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$pm_table} WHERE relation_id = %d AND source_post_id = %d AND target_site_id = %s ORDER BY id DESC LIMIT 1",
			$relation_id,
			$post_id,
			(string) $blog_id
		),
		ARRAY_A
	);
	$target_id = is_array( $mapping ) ? (int) $mapping['target_post_id'] : 0;
	e2e_check(
		"{$prefix}_mapping_row",
		$target_id > 0,
		is_array( $mapping ) ? "mapping #{$mapping['id']} target={$target_id} target_site_id={$mapping['target_site_id']}" : 'no post_mappings row',
		$checks,
		$passed,
		$failed
	);

	if ( $target_id <= 0 ) {
		continue;
	}

	// Target content lives ON the subsite. This is the core T1 assertion:
	// a writeback that landed on blog 1 instead of the subsite fails here.
	switch_to_blog( $blog_id );
	$target = get_post( $target_id );
	e2e_check(
		"{$prefix}_target_on_subsite",
		(bool) $target,
		(bool) $target
			? "target #{$target_id} exists on blog {$blog_id}"
			: "target #{$target_id} NOT found on blog {$blog_id} (wrong-site writeback?)",
		$checks,
		$passed,
		$failed
	);

	if ( $target ) {
		$title    = (string) $target->post_title;
		$content  = (string) $target->post_content;
		$title_ok = 1 === preg_match( E2E_MARKER_PATTERN, $title );
		$content_ok = 1 === preg_match( E2E_MARKER_PATTERN, $content );
		e2e_check( "{$prefix}_target_published", 'publish' === (string) $target->post_status, 'status=' . (string) $target->post_status, $checks, $passed, $failed );
		e2e_check( "{$prefix}_title_marker", $title_ok, 'title: ' . substr( $title, 0, 80 ), $checks, $passed, $failed );
		e2e_check( "{$prefix}_content_marker", $content_ok, 'content marker ' . ( $content_ok ? 'present' : 'MISSING' ), $checks, $passed, $failed );

		// Identity meta is read via SQL (some plugins filter get_post_meta).
		$meta_source = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
				$wpdb->postmeta,
				$target_id,
				'_wptsall_source_post_id'
			)
		);
		e2e_check(
			"{$prefix}_identity_source_meta",
			$meta_source === $post_id,
			"_wptsall_source_post_id={$meta_source} expected={$post_id}",
			$checks,
			$passed,
			$failed
		);

		$permalinks[] = array(
			'suffix'    => $suffix,
			'post_id'   => $post_id,
			'target_id' => $target_id,
			'permalink' => (string) get_permalink( $target_id ),
		);
	}
	restore_current_blog();
}

// Relation-wide backlog visibility (audit gap ④): auto-discovery on claim
// regenerates pending tasks for the relation's other objects on the shared
// lab; report the counts (deep-smoke WARNs on them) but do not fail the
// closed loop on pre-existing lab inventory.
$backlog_pending = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_table} WHERE relation_id = %d AND status = %s", $relation_id, 'pending' )
);
$backlog_retry = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_table} WHERE relation_id = %d AND status = %s", $relation_id, 'retry' )
);

$summary = array(
	'relation_id'      => $relation_id,
	'blog_id'          => $blog_id,
	'passed'           => $passed,
	'failed'           => $failed,
	'backlog'          => array(
		'pending' => $backlog_pending,
		'retry'   => $backlog_retry,
	),
	'permalinks'       => $permalinks,
	'checks'           => $checks,
);

echo 'SUBSITE-VERIFY-JSON: ' . wp_json_encode( $summary ) . "\n";
if ( $failed > 0 ) {
	echo "FAIL: {$failed} subsite closed-loop check(s) failed\n";
	exit( 1 );
}
echo "PASS: {$passed} subsite closed-loop checks passed\n";
exit( 0 );
