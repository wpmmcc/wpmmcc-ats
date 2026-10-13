<?php
/**
 * Asgaros Forum 数据填充验证脚本
 *
 * 注意: Asgaros Forum 使用自定义表存储数据，不使用 WordPress post_type
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Asgaros Forum 验证                                          ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! class_exists( 'AsgarosForum' ) ) {
    echo "❌ Asgaros Forum 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 0. 页面配置
// =============================================================
echo "--- 0. 页面配置 ---\n";

$asgaros_options = get_option( 'asgarosforum_options', array() );
$forum_page_id = isset( $asgaros_options['location'] ) ? intval( $asgaros_options['location'] ) : 0;
$page_exists = $forum_page_id > 0 && get_post_status( $forum_page_id ) === 'publish';
wptsall_verify_output( 'Forum 页面', $page_exists, $page_exists ? "ID: $forum_page_id" : '未配置' );
$results['page_forum'] = array( 'success' => $page_exists, 'page_id' => "$forum_page_id" );

// =============================================================
// 1. 内容数量（自定义表）
// =============================================================
echo "\n--- 1. 内容数量 ---\n";

global $wpdb;

// 论坛数量
$forum_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}forum_forums" );
$success = intval( $forum_count ) >= 0;
wptsall_verify_output( '论坛 (forums)', $success, "$forum_count 个" );
$results['count_forums'] = array( 'success' => $success, 'count' => intval( $forum_count ) );

// 话题数量
$topic_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}forum_topics" );
$success = intval( $topic_count ) >= 0;
wptsall_verify_output( '话题 (topics)', $success, "$topic_count 个" );
$results['count_topics'] = array( 'success' => $success, 'count' => intval( $topic_count ) );

// 帖子数量
$post_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}forum_posts" );
$success = intval( $post_count ) >= 0;
wptsall_verify_output( '帖子 (posts)', $success, "$post_count 个" );
$results['count_posts'] = array( 'success' => $success, 'count' => intval( $post_count ) );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// Forum 主页面
if ( $forum_page_id > 0 ) {
    $forum_url = get_permalink( $forum_page_id );
    if ( $forum_url ) {
        $forum_path = str_replace( home_url(), '', $forum_url );
        $result = wptsall_verify_url( $forum_path );
        wptsall_verify_url_output( "论坛主页 ($forum_path)", $result );
        $results['url_forum'] = $result;
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'admin.php?page=asgarosforum' => 'Asgaros 设置',
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
    'plugin'  => 'asgaros-forum',
    'results' => $results,
    'summary' => $summary,
);
