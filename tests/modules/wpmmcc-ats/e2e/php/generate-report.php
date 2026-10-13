<?php
/**
 * E2E v2 报告生成
 *
 * 汇总所有验证结果，输出 JSON 和文本报告到 reports/ 目录。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/generate-report.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Generate Report ===\n\n";

$prefix = $wpdb->prefix . 'wptsall_';

// ---------------------------------------------------------------------------
// 收集数据
// ---------------------------------------------------------------------------

$report = array(
	'timestamp'    => date( 'Y-m-d H:i:s' ),
	'version'      => 'e2e-v2',
	'environment'  => array(),
	'baseline'     => array(),
	'relations'    => array(),
	'seeding'      => array(),
	'translations' => array(),
	'targets'      => array(),
	'markers'      => array(),
	'iss15'        => array(),
	'iss16'        => array(),
	'iss16_flags'  => array(),
);

// 环境信息
$report['environment'] = array(
	'wp_version'     => get_bloginfo( 'version' ),
	'plugin_version' => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : 'unknown',
	'php_version'    => PHP_VERSION,
	'multisite'      => is_multisite(),
);

// Relation IDs (target types only) + resolved WP blog id context.
$relation_ids_raw = e2e_load_relation_ids();
$relation_ids     = e2e_relation_ids_only( $relation_ids_raw );
$report['relations'] = $relation_ids;
$wp_target_blog_id = e2e_get_wp_target_blog_id( $relation_ids_raw );
if ( $wp_target_blog_id > 0 ) {
	$report['relations_context'] = array(
		'wp_blog_id' => $wp_target_blog_id,
	);
}

// Baseline fixtures runtime metadata
$baseline_file = e2e_runtime_file( 'baseline-fixtures.json' );
if ( file_exists( $baseline_file ) ) {
	$baseline = json_decode( file_get_contents( $baseline_file ), true );
	if ( is_array( $baseline ) ) {
		$report['baseline'] = $baseline;
	}
}

// ISS-15 coverage runtime metadata
$iss15_file = e2e_runtime_file( 'iss15-coverage.json' );
if ( file_exists( $iss15_file ) ) {
	$iss15 = json_decode( file_get_contents( $iss15_file ), true );
	if ( is_array( $iss15 ) ) {
		$report['iss15'] = $iss15;
	}
}

// ISS-16 stability runtime metadata
$iss16_file = e2e_runtime_file( 'iss16-stability.json' );
if ( file_exists( $iss16_file ) ) {
	$iss16 = json_decode( file_get_contents( $iss16_file ), true );
	if ( is_array( $iss16 ) ) {
		$report['iss16'] = $iss16;
	}
}

// ISS-16 release flag snapshot metadata
$iss16_flags_file = e2e_runtime_file( 'iss16-release-flags.json' );
if ( file_exists( $iss16_flags_file ) ) {
	$iss16_flags = json_decode( file_get_contents( $iss16_flags_file ), true );
	if ( is_array( $iss16_flags ) ) {
		$report['iss16_flags'] = $iss16_flags;
	}
}

// Seeding 统计
$post_types = $wpdb->get_results(
	"SELECT post_type, COUNT(*) as cnt FROM {$wpdb->posts}
	 WHERE post_status = 'publish' AND post_type NOT IN ('revision', 'nav_menu_item', 'attachment', 'custom_css', 'customize_changeset', 'wp_global_styles')
	 GROUP BY post_type ORDER BY cnt DESC",
	ARRAY_A
);

foreach ( $post_types as $row ) {
	$report['seeding'][ $row['post_type'] ] = (int) $row['cnt'];
}

// 翻译统计
$tasks_table = $prefix . 'tasks';
if ( e2e_table_exists( $tasks_table ) ) {
	$report['translations']['tasks'] = array(
		'total'     => e2e_table_count( $tasks_table ),
		'completed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tasks_table WHERE status = 'completed'" ),
		'pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tasks_table WHERE status = 'pending'" ),
		'error'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tasks_table WHERE status IN ('error', 'failed')" ),
	);
}

$results_table = $prefix . 'translation_results';
if ( e2e_table_exists( $results_table ) ) {
	$report['translations']['results'] = array(
		'total'     => e2e_table_count( $results_table ),
		'completed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $results_table WHERE status = 'completed'" ),
		'failed'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $results_table WHERE status = 'failed'" ),
	);
}

$jobs_table = $prefix . 'task_jobs';
if ( e2e_table_exists( $jobs_table ) ) {
	$report['translations']['jobs'] = array(
		'total' => e2e_table_count( $jobs_table ),
	);
}

// 目标写回统计
// Virtual
$vs_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_virtual_site_id'"
);
$report['targets']['virtual'] = array( 'posts' => $vs_count );

// WP subsite target blog (dynamic)
if ( $wp_target_blog_id > 0 && is_multisite() && get_blog_details( $wp_target_blog_id ) ) {
	switch_to_blog( $wp_target_blog_id );
	$wp_blog_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')"
	);
	restore_current_blog();
	$report['targets']['wp_blog'] = array(
		'blog_id' => $wp_target_blog_id,
		'posts'   => $wp_blog_count,
	);
}

// Self
$self_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_self_translation'"
);
$report['targets']['self'] = array( 'posts' => $self_count );

// 标记精度
$bad_slugs = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->terms}
	 WHERE slug LIKE '%\xE3\x80\x90%' OR slug LIKE '%\xE3\x80\x91%'
	    OR LOWER(slug) LIKE '%e3%80%90%' OR LOWER(slug) LIKE '%e3%80%91%'"
);
$bad_postnames = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wptsall_virtual_site_id'
	 WHERE p.post_name LIKE '%\xE3\x80\x90%' OR p.post_name LIKE '%\xE3\x80\x91%'
	    OR LOWER(p.post_name) LIKE '%e3%80%90%' OR LOWER(p.post_name) LIKE '%e3%80%91%'"
);
$report['markers'] = array(
	'bad_term_slugs'  => $bad_slugs,
	'bad_postnames'   => $bad_postnames,
	'precision_clean' => ( $bad_slugs + $bad_postnames === 0 ),
);

// Mapping 统计
$mappings = array();
foreach ( array( 'post_mappings', 'media_mappings', 'term_mappings' ) as $mt ) {
	$table = $prefix . $mt;
	if ( e2e_table_exists( $table ) ) {
		$mappings[ $mt ] = e2e_table_count( $table );
	}
}
$report['mappings'] = $mappings;

// ---------------------------------------------------------------------------
// 输出报告
// ---------------------------------------------------------------------------

$report_dir = dirname( __DIR__ ) . '/reports';
if ( ! is_dir( $report_dir ) ) {
	mkdir( $report_dir, 0755, true );
}

$timestamp = date( 'Ymd-His' );

// JSON 报告
$json_file = $report_dir . "/e2e-v2-{$timestamp}.json";
file_put_contents( $json_file, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
echo "JSON report: $json_file\n";

// 文本摘要
$summary = "E2E v2 Report — {$report['timestamp']}\n";
$summary .= str_repeat( '=', 50 ) . "\n\n";

$summary .= "Environment:\n";
$summary .= "  WP: {$report['environment']['wp_version']}, Plugin: {$report['environment']['plugin_version']}, PHP: {$report['environment']['php_version']}\n";
$summary .= "  Multisite: " . ( $report['environment']['multisite'] ? 'yes' : 'no' ) . "\n\n";

$summary .= "Relations:\n";
foreach ( $relation_ids as $type => $id ) {
	$summary .= "  $type: " . ( $id > 0 ? "ID=$id" : "SKIPPED" ) . "\n";
}

$summary .= "\nBaseline Fixtures:\n";
if ( ! empty( $report['baseline'] ) ) {
	$summary .= "  fixture_post_id: " . intval( $report['baseline']['fixture_post_id'] ?? 0 ) . "\n";
	$summary .= "  fixture_media_id: " . intval( $report['baseline']['fixture_media_id'] ?? 0 ) . "\n";
	$summary .= "  fixture_model_id: " . intval( $report['baseline']['fixture_model_id'] ?? 0 ) . "\n";
	$summary .= "  fixture_rule_id: " . intval( $report['baseline']['fixture_rule_id'] ?? 0 ) . "\n";
	$summary .= "  manual_fields: " . implode( ', ', (array) ( $report['baseline']['manual_fields'] ?? array() ) ) . "\n";
	$summary .= "  language_pack_entries: " . intval( $report['baseline']['language_pack']['entries_total'] ?? 0 ) . "\n";
} else {
	$summary .= "  not available (baseline-fixtures.json missing)\n";
}

$summary .= "\nSeeding:\n";
foreach ( $report['seeding'] as $pt => $cnt ) {
	$summary .= "  $pt: $cnt\n";
}

$summary .= "\nTranslations:\n";
if ( isset( $report['translations']['tasks'] ) ) {
	$t = $report['translations']['tasks'];
	$summary .= "  Tasks: total={$t['total']}, completed={$t['completed']}, pending={$t['pending']}, error={$t['error']}\n";
}
if ( isset( $report['translations']['results'] ) ) {
	$r = $report['translations']['results'];
	$summary .= "  Results: total={$r['total']}, completed={$r['completed']}, failed={$r['failed']}\n";
}

$summary .= "\nTarget Write-Back:\n";
foreach ( $report['targets'] as $target => $data ) {
	$summary .= "  $target: {$data['posts']} posts\n";
}

$summary .= "\nMarker Precision: " . ( $report['markers']['precision_clean'] ? 'CLEAN' : 'ISSUES FOUND' ) . "\n";

$summary .= "\nISS-15 Coverage:\n";
if ( ! empty( $report['iss15'] ) ) {
	$summary .= '  passed=' . intval( $report['iss15']['passed'] ?? 0 )
		. ', failed=' . intval( $report['iss15']['failed'] ?? 0 )
		. ', skipped=' . intval( $report['iss15']['skipped'] ?? 0 ) . "\n";
} else {
	$summary .= "  not available (iss15-coverage.json missing)\n";
}

$summary .= "\nISS-16 Stabilization:\n";
if ( ! empty( $report['iss16'] ) ) {
	$summary .= '  passed=' . intval( $report['iss16']['passed'] ?? 0 )
		. ', failed=' . intval( $report['iss16']['failed'] ?? 0 )
		. ', skipped=' . intval( $report['iss16']['skipped'] ?? 0 ) . "\n";
	$summary .= '  ssot_read_source=' . (string) ( $report['iss16']['ssot_read_source'] ?? 'unknown' )
		. ', rollout_profile=' . (string) ( $report['iss16']['rollout_profile'] ?? 'unknown' ) . "\n";
} else {
	$summary .= "  not available (iss16-stability.json missing)\n";
}

if ( ! empty( $report['iss16_flags'] ) ) {
	$summary .= "  release_flags_snapshot: yes\n";
} else {
	$summary .= "  release_flags_snapshot: no\n";
}

$summary .= "\nMappings:\n";
foreach ( $report['mappings'] as $name => $cnt ) {
	$summary .= "  $name: $cnt\n";
}

$text_file = $report_dir . "/e2e-v2-{$timestamp}.txt";
file_put_contents( $text_file, $summary );
echo "Text report: $text_file\n";

// 也输出到 stdout
echo "\n" . $summary;
