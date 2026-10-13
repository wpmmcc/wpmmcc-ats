<?php
/**
 * Seriously Simple Podcasting 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Seriously Simple Podcasting 验证                            ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'podcast' ) ) {
    echo "❌ Seriously Simple Podcasting 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_episodes'] = wptsall_verify_count_output( 'podcast', '节目 (podcast)', 3 );

// 分类（系列）
$term_count = wptsall_get_term_count( 'series' );
wptsall_verify_output( '系列 (series)', $term_count >= 0, "$term_count 个" );
$results['count_series'] = array( 'success' => true, 'count' => $term_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 播客归档
$archive_url = wptsall_get_archive_url( 'podcast' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "播客归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个节目
$sample_url = wptsall_get_sample_url( 'podcast' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "节目单页 ($sample_path)", $result );
    $results['url_episode_single'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=podcast'     => '节目列表',
    'post-new.php?post_type=podcast' => '新建节目',
);

foreach ( $admin_pages as $path => $label ) {
    $result = wptsall_verify_admin_url( $path );
    wptsall_verify_url_output( $label, $result );
    $results[ 'admin_' . sanitize_key( $label ) ] = $result;
}

// =============================================================
// 汇总
// =============================================================
echo "\n";
$summary = wptsall_verify_summary( $results );
echo "╔══════════════════════════════════════════════════════════════╗\n";
printf( "║  验证完成: %d/%d 通过 (%.1f%%)                               ║\n",
    $summary['passed'], $summary['total'], $summary['rate'] );
echo "╚══════════════════════════════════════════════════════════════╝\n";

return array(
    'plugin'  => 'seriously-simple-podcasting',
    'results' => $results,
    'summary' => $summary,
);
