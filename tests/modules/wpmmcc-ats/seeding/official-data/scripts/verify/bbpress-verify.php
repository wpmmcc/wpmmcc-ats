<?php
/**
 * bbPress 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  bbPress 验证                                                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! class_exists( 'bbPress' ) && ! post_type_exists( 'forum' ) ) {
    echo "❌ bbPress 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_forum'] = wptsall_verify_count_output( 'forum', '论坛 (forum)', 1 );
$results['count_topic'] = wptsall_verify_count_output( 'topic', '话题 (topic)', 5 );
$results['count_reply'] = wptsall_verify_count_output( 'reply', '回复 (reply)', 5 );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 论坛归档
$archive_url = wptsall_get_archive_url( 'forum' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "论坛归档 ($archive_path)", $result );
    $results['url_forum_archive'] = $result;
}

// 单个论坛
$sample_url = wptsall_get_sample_url( 'forum' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "论坛单页 ($sample_path)", $result );
    $results['url_forum_single'] = $result;
}

// 单个话题
$sample_url = wptsall_get_sample_url( 'topic' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "话题单页 ($sample_path)", $result );
    $results['url_topic_single'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=forum' => '论坛列表',
    'edit.php?post_type=topic' => '话题列表',
    'edit.php?post_type=reply' => '回复列表',
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
    'plugin'  => 'bbpress',
    'results' => $results,
    'summary' => $summary,
);
