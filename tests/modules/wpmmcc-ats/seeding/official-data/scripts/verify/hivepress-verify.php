<?php
/**
 * HivePress 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  HivePress 验证                                              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
// HivePress 使用 HivePress\Core 类或 hivepress() 函数
if ( ! post_type_exists( 'hp_listing' ) || ( ! class_exists( 'HivePress\\Core' ) && ! function_exists( 'hivepress' ) ) ) {
    echo "❌ HivePress 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_listings'] = wptsall_verify_count_output( 'hp_listing', '列表 (hp_listing)', 1 );
$results['count_vendors'] = wptsall_verify_count_output( 'hp_vendor', '供应商 (hp_vendor)', 0 );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 列表归档
$archive_url = get_post_type_archive_link( 'hp_listing' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "列表归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个列表
$sample_url = wptsall_get_sample_url( 'hp_listing' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "列表单页 ($sample_path)", $result );
    $results['url_single'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=hp_listing' => '列表管理',
    'admin.php?page=hivepress_settings' => 'HivePress 设置',
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
    'plugin'  => 'hivepress',
    'results' => $results,
    'summary' => $summary,
);
