<?php
/**
 * Podlove Podcast Publisher 数据填充验证脚本
 *
 * Podlove 使用自定义表存储播客数据
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Podlove 验证                                                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
// Podlove 使用 post type 'podcast' 和自定义表
if ( ! post_type_exists( 'podcast' ) && ! class_exists( '\Podlove\Model\Episode' ) ) {
    echo "❌ Podlove 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

global $wpdb;
$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

// 检查 podcast post type 或 episode 表
if ( post_type_exists( 'podcast' ) ) {
    $results['count_episodes'] = wptsall_verify_count_output( 'podcast', '播客 (podcast)', 1 );
} else {
    // Podlove 使用自定义表
    $episode_table = $wpdb->prefix . 'podlove_episode';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$episode_table'" ) === $episode_table ) {
        $episode_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $episode_table" );
        $pass = $episode_count >= 1;
        $icon = $pass ? '✓' : '✗';
        echo "  $icon 播客剧集: $episode_count 条" . ( $pass ? '' : ' (期望 >= 1)' ) . "\n";
        $results['count_episodes'] = array( 'success' => $pass, 'count' => $episode_count );
    } else {
        echo "  ⚠ 播客剧集表不存在\n";
        $results['count_episodes'] = array( 'success' => false, 'count' => 0 );
    }
}

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 播客归档
if ( post_type_exists( 'podcast' ) ) {
    $archive_url = get_post_type_archive_link( 'podcast' );
    if ( $archive_url ) {
        $archive_path = str_replace( home_url(), '', $archive_url );
        $result = wptsall_verify_url( $archive_path );
        wptsall_verify_url_output( "播客归档 ($archive_path)", $result );
        $results['url_archive'] = $result;
    }

    // 单个播客
    $sample_url = wptsall_get_sample_url( 'podcast' );
    if ( $sample_url ) {
        $sample_path = str_replace( home_url(), '', $sample_url );
        $result = wptsall_verify_url( $sample_path );
        wptsall_verify_url_output( "播客单页 ($sample_path)", $result );
        $results['url_single'] = $result;
    }
} else {
    echo "  ⚠ Podlove 使用自定义数据结构，无标准归档页\n";
    $results['url_note'] = array( 'success' => true, 'status' => 'custom_structure' );
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'admin.php?page=podlove_episodes_settings_handle' => 'Podlove 剧集',
    'admin.php?page=podlove_settings_handle' => 'Podlove 设置',
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
    'plugin'  => 'podlove',
    'results' => $results,
    'summary' => $summary,
);
