<?php
/**
 * E2E v2 深度清理脚本
 *
 * 清理三系统翻译产物，保留源数据和站点配置：
 * 1. TRUNCATE mapping/task 相关表
 * 2. DELETE WP 子站翻译内容（动态目标 Blog，ID > 30）
 * 4. DELETE 所有虚拟站点翻译内容（按 _wptsall_virtual_site_id meta）
 * 5. DELETE Blog 1 Self Translation 产出（按 _wptsall_self_translation meta）
 * 6. CLEAN stale _wptsall_target_id_* meta
 * 7. DELETE sync/translation 任务
 * 8. TRUNCATE manual_queue
 *
 * 适配自 testing/e2e-reset.php，扩展覆盖全部 virtual site 和 self translation。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/reset.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2 Deep Reset ===\n\n";

$prefix = $wpdb->prefix . 'wptsall_';

// ---------------------------------------------------------------------------
// Step 1: TRUNCATE mapping and task tables
// ---------------------------------------------------------------------------
echo "--- Step 1: Truncate mapping & task tables ---\n";

$truncate_tables = array(
	'post_mappings',
	'media_mappings',
	'term_mappings',
	'translation_results',
	'origin_visits',
	'task_logs',
	'task_items',
	'task_jobs',
	'manual_queue',
);

foreach ( $truncate_tables as $table_name ) {
	$full_name = $prefix . $table_name;
	$count     = e2e_truncate( $full_name );
	if ( $count > 0 ) {
		echo "  $table_name: truncated ($count rows)\n";
	} else {
		echo "  $table_name: empty or not found\n";
	}
}

// Callback idempotency transients survive table truncates; stale replays would
// return success without re-inserting translation_results (0 write-back posts).
if ( function_exists( 'wptsall_idempotency_clear_all' ) ) {
	$idem_deleted = wptsall_idempotency_clear_all();
	echo "  idempotency_transients: cleared ($idem_deleted option rows)\n";
}

// ---------------------------------------------------------------------------
// Step 2: Reset template entry translation state (for repeatable i18n E2E)
// ---------------------------------------------------------------------------
echo "\n--- Step 2: Reset template_entries state ---\n";

$templates_table        = $prefix . 'templates';
$template_entries_table = $prefix . 'template_entries';
$now                    = current_time( 'mysql' );

if ( e2e_table_exists( $template_entries_table ) ) {
	$reset_entries = (int) $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$template_entries_table}
			 SET status = %s,
			     msgstr = %s,
			     msgstr_plural = %s,
			     updated_at = %s",
			'pending',
			'',
			'',
			$now
		)
	);
	echo "  template_entries reset to pending: {$reset_entries} rows\n";
} else {
	echo "  template_entries table not found\n";
}

if ( e2e_table_exists( $templates_table ) ) {
	$reset_templates = (int) $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$templates_table}
			 SET translated_entries = %d,
			     updated_at = %s",
			0,
			$now
		)
	);
	echo "  templates translated_entries reset: {$reset_templates} rows\n";
} else {
	echo "  templates table not found\n";
}

// ---------------------------------------------------------------------------
// Step 3: Delete sync/translation tasks
// ---------------------------------------------------------------------------
echo "\n--- Step 3: Delete sync/translation tasks ---\n";

$tasks_table = $prefix . 'tasks';
if ( e2e_table_exists( $tasks_table ) ) {
	$deleted = $wpdb->query(
		"DELETE FROM $tasks_table WHERE type IN ('sync', 'translation')"
	);
	echo "  Deleted $deleted sync/translation tasks\n";
} else {
	echo "  Tasks table not found\n";
}

// ---------------------------------------------------------------------------
// Step 4: Clean WP target blogs (dynamic)
// ---------------------------------------------------------------------------
echo "\n--- Step 4: Clean WP target blogs ---\n";

$wp_target_blogs = array();

// 3.1 runtime relation ids context.
$runtime_relation_ids = e2e_load_relation_ids();
$runtime_wp_blog_id   = (int) ( $runtime_relation_ids['wp_blog_id'] ?? 0 );
if ( $runtime_wp_blog_id > 1 ) {
	$wp_target_blogs[] = $runtime_wp_blog_id;
}

// 3.2 relation table context.
$rel_table = e2e_table( 'site_relations' );
if ( e2e_table_exists( $rel_table ) ) {
	$target_site_ids = $wpdb->get_col(
		"SELECT DISTINCT target_site_id FROM {$rel_table}
		 WHERE target_site_type = 'wp'
		   AND target_site_id REGEXP '^[0-9]+$'"
	);
	foreach ( (array) $target_site_ids as $target_site_id ) {
		$blog_id = (int) $target_site_id;
		if ( $blog_id > 1 ) {
			$wp_target_blogs[] = $blog_id;
		}
	}
}

// 3.3 backward-compat fallback (multisite only).
if ( is_multisite() ) {
	foreach ( array( 3, 4 ) as $legacy_blog_id ) {
		if ( get_blog_details( $legacy_blog_id ) ) {
			$wp_target_blogs[] = $legacy_blog_id;
		}
	}
}

$wp_target_blogs = array_values( array_unique( array_map( 'intval', $wp_target_blogs ) ) );
sort( $wp_target_blogs );

if ( empty( $wp_target_blogs ) ) {
	echo "  No WP target blogs detected, skipped.\n";
} else {
	foreach ( $wp_target_blogs as $blog_id ) {
		$count = e2e_clean_blog( $blog_id );
		echo "  Blog {$blog_id}: deleted {$count} items\n";
	}
}

// ---------------------------------------------------------------------------
// Step 5: Delete ALL virtual site translated content
// ---------------------------------------------------------------------------
echo "\n--- Step 5: Clean virtual site content ---\n";

$virtual_post_ids = $wpdb->get_col(
	"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
	 WHERE pm.meta_key = '_wptsall_virtual_site_id'"
);

if ( ! empty( $virtual_post_ids ) ) {
	$count = e2e_delete_posts_by_ids( $virtual_post_ids );
	echo "  Virtual site content: deleted $count items\n";
} else {
	echo "  Virtual site content: nothing to clean\n";
}

// ---------------------------------------------------------------------------
// Step 6: Delete Blog 1 Self Translation products
// ---------------------------------------------------------------------------
echo "\n--- Step 6: Clean self-translation content ---\n";

$self_post_ids = $wpdb->get_col(
	"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
	 WHERE pm.meta_key = '_wptsall_self_translation'"
);

if ( ! empty( $self_post_ids ) ) {
	$count = e2e_delete_posts_by_ids( $self_post_ids );
	echo "  Self-translation content: deleted $count items\n";
} else {
	echo "  Self-translation content: nothing to clean\n";
}

// ---------------------------------------------------------------------------
// Step 7: Clean stale _wptsall_target_id_* meta on source Blog 1
// ---------------------------------------------------------------------------
echo "\n--- Step 7: Clean stale target_id meta ---\n";

$meta_deleted = (int) $wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wptsall_target_id_%'"
);
echo "  Deleted $meta_deleted stale target_id meta entries\n";

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n=== Reset Complete ===\n\n";

echo "Cleaned:\n";
echo "  - Mapping tables (post/media/term mappings, translation_results, origin_visits)\n";
echo "  - Template entries reset to pending (cleared msgstr)\n";
echo "  - Task tables (task_logs, task_items, task_jobs, manual_queue)\n";
echo "  - Sync/translation tasks deleted\n";
echo "  - WP target blog translated content (dynamic)\n";
echo "  - ALL virtual site translated content\n";
echo "  - Self-translation content on Blog 1\n";
echo "  - Stale _wptsall_target_id_* meta\n\n";

echo "Preserved:\n";
echo "  - Source content on Blog 1\n";
echo "  - Site relations and model bindings\n";
echo "  - Client API tokens and route_secret\n";
echo "  - Virtual site configuration\n\n";

echo "Ready for next E2E run.\n";
