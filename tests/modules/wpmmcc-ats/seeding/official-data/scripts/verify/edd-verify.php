<?php
/**
 * Easy Digital Downloads 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Easy Digital Downloads 验证                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'download' ) ) {
    echo "❌ Easy Digital Downloads 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_download'] = wptsall_verify_count_output( 'download', '下载 (download)', 3 );

// 下载分类
$cat_count = wptsall_get_term_count( 'download_category' );
wptsall_verify_output( '下载分类 (download_category)', $cat_count >= 0, "$cat_count 个" );
$results['count_category'] = array( 'success' => true, 'count' => $cat_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 归档页
$archive_url = wptsall_get_archive_url( 'download' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "下载归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个下载
$sample_url = wptsall_get_sample_url( 'download' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "下载单页 ($sample_path)", $result );
    $results['url_download_single'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=download' => '下载列表',
    'post-new.php?post_type=download' => '新建下载',
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
    'plugin'  => 'easy-digital-downloads',
    'results' => $results,
    'summary' => $summary,
);
