<?php
/**
 * The Events Calendar 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  The Events Calendar 验证                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'tribe_events' ) ) {
    echo "❌ The Events Calendar 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_events'] = wptsall_verify_count_output( 'tribe_events', '活动 (tribe_events)', 3 );
$results['count_venues'] = wptsall_verify_count_output( 'tribe_venue', '场地 (tribe_venue)', 2 );
$results['count_organizers'] = wptsall_verify_count_output( 'tribe_organizer', '组织者 (tribe_organizer)', 1 );

// 分类
$term_count = wptsall_get_term_count( 'tribe_events_cat' );
wptsall_verify_output( '活动分类', $term_count >= 0, "$term_count 个" );
$results['count_category'] = array( 'success' => true, 'count' => $term_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 活动归档
$archive_url = wptsall_get_archive_url( 'tribe_events' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "活动归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个活动
$sample_url = wptsall_get_sample_url( 'tribe_events' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "活动单页 ($sample_path)", $result );
    $results['url_event_single'] = $result;
}

// 单个场地
$sample_url = wptsall_get_sample_url( 'tribe_venue' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "场地单页 ($sample_path)", $result );
    $results['url_venue_single'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=tribe_events'    => '活动列表',
    'post-new.php?post_type=tribe_events' => '新建活动',
    'edit.php?post_type=tribe_venue'     => '场地列表',
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
    'plugin'  => 'the-events-calendar',
    'results' => $results,
    'summary' => $summary,
);
