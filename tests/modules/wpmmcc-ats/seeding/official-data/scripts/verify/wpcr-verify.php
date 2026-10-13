<?php
/**
 * WP Customer Reviews 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WP Customer Reviews 验证                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'wpcr3_review' ) ) {
    echo "❌ WP Customer Reviews 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_reviews'] = wptsall_verify_count_output( 'wpcr3_review', '评论 (wpcr3_review)', 1 );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 单个评论（WP Customer Reviews 通常嵌入在其他页面）
$sample_url = wptsall_get_sample_url( 'wpcr3_review' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "评论单页 ($sample_path)", $result );
    $results['url_single'] = $result;
} else {
    echo "  ⚠ 评论通常嵌入其他页面，无独立单页\n";
    $results['url_single'] = array( 'success' => true, 'status' => 'embedded' );
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=wpcr3_review' => '评论列表',
    'admin.php?page=wpcr3_settings'   => '评论设置',
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
    'plugin'  => 'wp-customer-reviews',
    'results' => $results,
    'summary' => $summary,
);
