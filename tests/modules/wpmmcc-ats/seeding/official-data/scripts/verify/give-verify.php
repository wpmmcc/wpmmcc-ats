<?php
/**
 * GiveWP 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  GiveWP 验证                                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'give_forms' ) || ! class_exists( 'Give' ) ) {
    echo "❌ GiveWP 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_forms'] = wptsall_verify_count_output( 'give_forms', '捐赠表单 (give_forms)', 1 );

// 分类
$category_count = wptsall_get_term_count( 'give_forms_category' );
wptsall_verify_output( '表单分类', $category_count >= 0, "$category_count 个" );
$results['count_categories'] = array( 'success' => true, 'count' => $category_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 表单归档
$archive_url = get_post_type_archive_link( 'give_forms' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "表单归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个表单
$sample_url = wptsall_get_sample_url( 'give_forms' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "表单单页 ($sample_path)", $result );
    $results['url_single'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=give_forms' => '表单列表',
    'admin.php?page=give-settings' => 'GiveWP 设置',
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
    'plugin'  => 'give',
    'results' => $results,
    'summary' => $summary,
);
