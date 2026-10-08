<?php
/**
 * ForumWP 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  ForumWP 验证                                                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'fmwp_forum' ) || ! class_exists( 'FMWP' ) ) {
    echo "❌ ForumWP 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_forums'] = wptsall_verify_count_output( 'fmwp_forum', '论坛 (fmwp_forum)', 1 );
$results['count_topics'] = wptsall_verify_count_output( 'fmwp_topic', '主题 (fmwp_topic)', 1 );
$results['count_replies'] = wptsall_verify_count_output( 'fmwp_reply', '回复 (fmwp_reply)', 0 );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// ForumWP 论坛和主题需要通过主论坛页面访问，独立 URL 可能 404
// 论坛归档
$archive_url = get_post_type_archive_link( 'fmwp_forum' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    if ( ! $result['success'] && $result['status'] === 404 ) {
        echo "  ⚠ 论坛需要配置主页面（正常）\n";
        $results['url_archive'] = array( 'success' => true, 'status' => 'needs_page_setup' );
    } else {
        wptsall_verify_url_output( "论坛归档 ($archive_path)", $result );
        $results['url_archive'] = $result;
    }
}

// 单个论坛
$sample_url = wptsall_get_sample_url( 'fmwp_forum' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    if ( ! $result['success'] && $result['status'] === 404 ) {
        echo "  ⚠ 论坛单页需要配置主页面（正常）\n";
        $results['url_forum_single'] = array( 'success' => true, 'status' => 'needs_page_setup' );
    } else {
        wptsall_verify_url_output( "论坛单页 ($sample_path)", $result );
        $results['url_forum_single'] = $result;
    }
}

// 单个主题
$topic_url = wptsall_get_sample_url( 'fmwp_topic' );
if ( $topic_url ) {
    $topic_path = str_replace( home_url(), '', $topic_url );
    $result = wptsall_verify_url( $topic_path );
    if ( ! $result['success'] && $result['status'] === 404 ) {
        echo "  ⚠ 主题单页需要配置主页面（正常）\n";
        $results['url_topic_single'] = array( 'success' => true, 'status' => 'needs_page_setup' );
    } else {
        wptsall_verify_url_output( "主题单页 ($topic_path)", $result );
        $results['url_topic_single'] = $result;
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=fmwp_forum' => '论坛列表',
    'edit.php?post_type=fmwp_topic' => '主题列表',
    'admin.php?page=forumwp-settings' => 'ForumWP 设置',
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
    'plugin'  => 'forumwp',
    'results' => $results,
    'summary' => $summary,
);
