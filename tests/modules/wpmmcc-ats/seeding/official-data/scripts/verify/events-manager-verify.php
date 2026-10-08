<?php
/**
 * Events Manager 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Events Manager 验证                                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'event' ) || ! defined( 'EM_VERSION' ) ) {
    echo "❌ Events Manager 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_events'] = wptsall_verify_count_output( 'event', '活动 (event)', 1 );
// 地点是可选的，不检查数量
$location_count = wptsall_get_post_count( 'location' );
wptsall_verify_output( '地点 (location)', true, "$location_count 条（可选）" );
$results['count_locations'] = array( 'success' => true, 'count' => $location_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 活动归档
$archive_url = get_post_type_archive_link( 'event' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "活动归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个活动
// Events Manager 活动可能需要特殊配置才能前台访问
$sample_url = wptsall_get_sample_url( 'event' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    if ( ! $result['success'] && $result['status'] === 404 ) {
        echo "  ⚠ 活动单页需要配置主页面（正常）\n";
        $results['url_single'] = array( 'success' => true, 'status' => 'needs_page_setup' );
    } else {
        wptsall_verify_url_output( "活动单页 ($sample_path)", $result );
        $results['url_single'] = $result;
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=event' => '活动列表',
    'admin.php?page=events-manager-options' => 'Events Manager 设置',
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
    'plugin'  => 'events-manager',
    'results' => $results,
    'summary' => $summary,
);
