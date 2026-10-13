<?php
/**
 * FooGallery 数据填充验证脚本
 *
 * 注意: FooGallery 图库通过 shortcode 显示，没有前台归档页
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  FooGallery 验证                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'foogallery' ) ) {
    echo "❌ FooGallery 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_galleries'] = wptsall_verify_count_output( 'foogallery', '图库 (foogallery)', 1 );

// =============================================================
// 2. 前台 URL 说明
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

echo "  ℹ FooGallery 通过 shortcode 嵌入页面，无独立前台 URL\n";
$results['url_note'] = array( 'success' => true, 'message' => 'shortcode_only' );

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=foogallery'     => '图库列表',
    'post-new.php?post_type=foogallery' => '新建图库',
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
    'plugin'  => 'foogallery',
    'results' => $results,
    'summary' => $summary,
);
