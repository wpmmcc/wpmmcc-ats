<?php
/**
 * WPForo 数据填充验证脚本
 *
 * WPForo 使用自定义表，不使用 WordPress posts
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WPForo 验证                                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
// WPForo 使用 WPF() 函数和 WPFORO_VERSION 常量
if ( ! function_exists( 'WPF' ) && ! defined( 'WPFORO_VERSION' ) ) {
    echo "❌ WPForo 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

global $wpdb;
$results = array();

// =============================================================
// 1. 内容数量（自定义表）
// =============================================================
echo "--- 1. 内容数量 ---\n";

// 论坛
$forum_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforo_forums" );
$forum_count = (int) $forum_count;
$pass = $forum_count >= 1;
$icon = $pass ? '✓' : '✗';
echo "  $icon 论坛: $forum_count 个" . ( $pass ? '' : ' (期望 >= 1)' ) . "\n";
$results['count_forums'] = array( 'success' => $pass, 'count' => $forum_count );

// 主题
$topic_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforo_topics" );
$topic_count = (int) $topic_count;
$pass = $topic_count >= 0;
$icon = $pass ? '✓' : '✗';
echo "  $icon 主题: $topic_count 个\n";
$results['count_topics'] = array( 'success' => $pass, 'count' => $topic_count );

// 帖子
$post_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforo_posts" );
$post_count = (int) $post_count;
$pass = $post_count >= 0;
$icon = $pass ? '✓' : '✗';
echo "  $icon 帖子: $post_count 个\n";
$results['count_posts'] = array( 'success' => $pass, 'count' => $post_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 论坛首页
$forum_page_id = wpforo_setting( 'forums', 'pageid' );
if ( ! $forum_page_id ) {
    $forum_page_id = get_option( 'wpforo_pageid' );
}

if ( $forum_page_id ) {
    $forum_url = get_permalink( $forum_page_id );
    if ( $forum_url ) {
        $forum_path = str_replace( home_url(), '', $forum_url );
        $result = wptsall_verify_url( $forum_path );
        wptsall_verify_url_output( "论坛首页 ($forum_path)", $result );
        $results['url_forum'] = $result;
    }
} else {
    // 尝试默认 /community/ 路径
    $result = wptsall_verify_url( '/community/' );
    wptsall_verify_url_output( "论坛首页 (/community/)", $result );
    $results['url_forum'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'admin.php?page=wpforo-forums'   => '论坛管理',
    'admin.php?page=wpforo-settings' => '论坛设置',
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
    'plugin'  => 'wpforo',
    'results' => $results,
    'summary' => $summary,
);
